<?php

declare(strict_types=1);

// Regression cover for the NPC EXECUTION layer: the half that has a database
// behind it. The decision layer is covered by tests/npc-*.php, which are pure
// and run in seconds; this one seeds real accounts into the running world,
// grows them, and deletes everything it made on the way out.
//
// What it is actually guarding:
//
//   - a seeded neighbour is indistinguishable from a player account in every
//     table the map, the rankings and combat read;
//   - population accounting stays consistent between vdata, users and fdata,
//     which is what breaks first when a pass writes levels by hand;
//   - a cow that has quit really is frozen, and the growth pass does not
//     quietly bring it back;
//   - a seed never settles a square something already stands on.

use Core\Database\DB;
use Game\Npc\NpcBuildOrder;
use Game\Npc\NpcFormulasData;
use Game\Npc\NpcLifespan;
use Game\Npc\NpcTiers;
use Model\NpcExpandModel;
use Model\NpcGrowthModel;
use Model\NpcModel;
use Model\NpcSeedModel;
use Model\VillageModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

function npc_expect_same(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, received %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function npc_expect_true(bool $actual, string $label): void
{
    npc_expect_same(true, $actual, $label);
}

/**
 * Remove every account this test created, and hand its map squares back.
 *
 * Deliberately not AccountDeleter: that one is the engine's own path for a
 * player quitting and drags in mail, alliances and auctions. Here the point is
 * to leave the world exactly as it was found.
 */
function npc_cleanup(array $uids): void
{
    if (!$uids) {
        return;
    }
    $db   = DB::getInstance();
    $list = implode(',', array_map('intval', $uids));

    $kids = [];
    $rows = $db->query("SELECT kid FROM vdata WHERE owner IN ($list)");
    while ($row = $rows->fetch_assoc()) {
        $kids[] = (int) $row['kid'];
    }
    if ($kids) {
        $kidList = implode(',', $kids);
        foreach (['fdata', 'units', 'tdata', 'smithy', 'training', 'building_upgrade', 'vdata'] as $table) {
            $db->query("DELETE FROM $table WHERE kid IN ($kidList)");
        }
        $db->query("UPDATE available_villages SET occupied=0 WHERE kid IN ($kidList)");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($kidList)");
    }
    foreach (['hero', 'inventory', 'face', 'adventure', 'npc_player', 'npc_farm'] as $table) {
        $db->query("DELETE FROM $table WHERE uid IN ($list)");
    }
    $db->query("DELETE FROM users WHERE id IN ($list)");
}

$db      = DB::getInstance();
$npc     = new NpcModel();
$created = [];

try {
    $before = $npc->total();

    // A corner of the map the local world leaves empty, so the test never
    // competes with the player's own neighbourhood for valleys.
    $seeded = (new NpcSeedModel())->seed(3, [
        'centerX'   => 18,
        'centerY'   => -18,
        'radiusMin' => 2,
        'radiusMax' => 8,
    ]);
    foreach ($seeded['accounts'] as $account) {
        $created[] = (int) $account['uid'];
    }

    npc_expect_same(3, $seeded['created'], 'accounts seeded');
    npc_expect_same(0, $seeded['failed'], 'accounts that failed to seed');
    npc_expect_same($before + 3, $npc->total(), 'registry rows after seeding');

    // The split is applied to the world AFTER seeding, not to the batch, so a
    // second run tops up whatever the world is short of.
    $counts = $npc->countByTier();
    npc_expect_same($before + 3, array_sum($counts), 'tier counts add up to the registry');

    $data = new NpcFormulasData();
    foreach ($created as $uid) {
        $registry = $npc->get($uid);
        npc_expect_true($registry !== null, "registry row exists for uid $uid");
        npc_expect_true(NpcTiers::exists((string) $registry['tier']), "uid $uid has a known tier");

        $user = $db->query("SELECT access, race, total_pop, total_villages, protection FROM users WHERE id=$uid")->fetch_assoc();
        npc_expect_true($user !== null, "uid $uid has a user row");
        // An NPC is an ordinary account. Anything else and the fake-user pass
        // (access=3) would start driving it as well.
        npc_expect_same(1, (int) $user['access'], "uid $uid is an ordinary account");
        npc_expect_true(in_array((int) $user['race'], [1, 2, 3], true), "uid $uid has a classic tribe");

        $villages = $npc->villagesOf($uid);
        npc_expect_true($villages !== [], "uid $uid owns at least one village");
        npc_expect_same(count($villages), (int) $user['total_villages'], "uid $uid village count matches users");

        $popTotal = 0;
        $capitals = 0;
        foreach ($villages as $village) {
            $kid = (int) $village['kid'];
            $popTotal += (int) $village['pop'];
            $capitals += (int) $village['capital'];

            // The map square really is taken. A seed that claims a valley and
            // leaves the flags disagreeing is how two villages end up on one
            // square, which nothing downstream recovers from.
            npc_expect_same(1, (int) $db->fetchScalar("SELECT occupied FROM wdata WHERE id=$kid"), "wdata occupied for kid $kid");
            npc_expect_same(1, (int) $db->fetchScalar("SELECT occupied FROM available_villages WHERE kid=$kid"), "available_villages occupied for kid $kid");
            npc_expect_same(1, (int) $db->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE kid=$kid"), "exactly one village on kid $kid");

            // The engine's own reading of the village has to agree with what
            // the pass wrote, or the pop column drifts a little on every pass
            // until the growth curve is chasing a number nobody recognises.
            $calculated = VillageModel::calculateVillageCulturePointsAndPopulation($kid, false);
            npc_expect_same((int) $calculated['pop'], (int) $village['pop'], "vdata.pop matches the engine for kid $kid");

            $row = $npc->fdata($kid);
            npc_expect_true($row !== null, "fdata row exists for kid $kid");
            $cap = NpcBuildOrder::villageCapForRow(
                $data,
                $row,
                (string) $registry['archetype'],
                (int) $user['race'],
                (int) $village['capital'] === 1
            );
            npc_expect_true((int) $village['pop'] <= $cap, "kid $kid is within its own saturation ($cap)");
        }
        npc_expect_same(1, $capitals, "uid $uid has exactly one capital");
        npc_expect_same($popTotal, (int) $user['total_pop'], "uid $uid total_pop matches its villages");

        // The hero is born as old as the account, not as old as the seed run.
        $hero = $db->query("SELECT exp, health, lastupdate FROM hero WHERE uid=$uid")->fetch_assoc();
        npc_expect_true($hero !== null, "uid $uid has a hero");
        npc_expect_true((float) $hero['health'] > 0.0, "uid $uid hero is alive");
        npc_expect_true((int) $hero['lastupdate'] >= (int) $registry['created'], "uid $uid hero was aged from its creation");
    }

    // A growth pass never loses population, and never makes a village exceed
    // the ceiling its own tile allows.
    $popBefore = [];
    foreach ($created as $uid) {
        $popBefore[$uid] = (int) $db->fetchScalar("SELECT total_pop FROM users WHERE id=$uid");
    }
    $db->query('UPDATE npc_player SET last_growth=0 WHERE uid IN (' . implode(',', $created) . ')');
    (new NpcGrowthModel())->run(count($created), 60);
    foreach ($created as $uid) {
        $after = (int) $db->fetchScalar("SELECT total_pop FROM users WHERE id=$uid");
        npc_expect_true($after >= $popBefore[$uid], "uid $uid did not lose population to a growth pass");

        $sum = (int) $db->fetchScalar("SELECT COALESCE(SUM(pop), 0) FROM vdata WHERE owner=$uid");
        npc_expect_same($sum, $after, "uid $uid total_pop still matches its villages after growing");
    }

    // A cow that has quit is frozen. This is the one behaviour a worker could
    // silently undo, because every other pass reads the EFFECTIVE tier and an
    // inactive reads as a casual right up until its quit day.
    $frozen = $created[0];
    $db->query("UPDATE npc_player SET tier='" . NpcTiers::INACTIVE . "', quit_at=" . (time() - 3600) . ", last_growth=0 WHERE uid=$frozen");
    $registry = $npc->get($frozen);
    npc_expect_true(
        NpcLifespan::hasQuit((string) $registry['tier'], (int) $registry['quit_at'], time()),
        'the frozen account reads as quit'
    );
    $handedOut = 0;
    foreach ($npc->dueForGrowth(0, 100, time()) as $row) {
        if ((int) $row['uid'] === $frozen) {
            $handedOut++;
        }
    }
    npc_expect_same(0, $handedOut, 'a quit account is never handed to the growth pass');

    $popFrozen = (int) $db->fetchScalar("SELECT total_pop FROM users WHERE id=$frozen");
    (new NpcGrowthModel())->run(50, 60);
    npc_expect_same($popFrozen, (int) $db->fetchScalar("SELECT total_pop FROM users WHERE id=$frozen"), 'a quit account does not grow');

    // And the expansion pass agrees with it.
    $villagesFrozen = (int) $db->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE owner=$frozen");
    $db->query("UPDATE npc_player SET last_expand=0 WHERE uid=$frozen");
    (new NpcExpandModel())->run(50, 60);
    npc_expect_same($villagesFrozen, (int) $db->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE owner=$frozen"), 'a quit account does not expand');

    // Seeding twice never puts two accounts on one square: the second run has
    // to see the first run's claims.
    $secondBefore = (int) $db->fetchScalar('SELECT COUNT(kid) FROM vdata');
    $again = (new NpcSeedModel())->seed(2, [
        'centerX'   => 18,
        'centerY'   => -18,
        'radiusMin' => 2,
        'radiusMax' => 8,
    ]);
    foreach ($again['accounts'] as $account) {
        $created[] = (int) $account['uid'];
    }
    $duplicates = (int) $db->fetchScalar('SELECT COUNT(*) FROM (SELECT kid FROM vdata GROUP BY kid HAVING COUNT(*) > 1) AS d');
    npc_expect_same(0, $duplicates, 'no map square carries two villages');
    npc_expect_true(
        (int) $db->fetchScalar('SELECT COUNT(kid) FROM vdata') > $secondBefore,
        'the second seed run created villages'
    );

    fwrite(STDOUT, "NPC execution regression test passed.\n");
} catch (Throwable $e) {
    npc_cleanup($created);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

npc_cleanup($created);
