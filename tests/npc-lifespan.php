<?php

// Inactive accounts are not born dead: they play as casuals until their own
// quit day and freeze there.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcLifespan.php';

use Game\Npc\NpcLifespan;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$now = 1700000000;

// Only an inactive account ever gets a quit day.
foreach ([NpcTiers::TOP, NpcTiers::BUILDER, NpcTiers::CASUAL] as $tier) {
    if (NpcLifespan::quitAt($tier, $now, 1) !== 0) {
        npc_fail("An active tier ($tier) must never be handed a quit day.");
    }
    if (NpcLifespan::hasQuit($tier, 0, $now)) {
        npc_fail("An active tier ($tier) must never read as quit.");
    }
    if (NpcLifespan::effectiveTier($tier, 0, $now) !== $tier) {
        npc_fail("An active tier ($tier) must be left alone.");
    }
}

// The quit day lands inside the documented band, and speed pulls it closer in
// real time: a x10 server burns through the same game days ten times as fast.
for ($i = 0; $i < 50; $i++) {
    $quitAt = NpcLifespan::quitAt(NpcTiers::INACTIVE, $now, 1);
    $days   = ($quitAt - $now) / 86400;
    if ($days < NpcLifespan::QUIT_MIN_DAYS || $days > NpcLifespan::QUIT_MAX_DAYS) {
        npc_fail("A quit day landed at $days game days, outside the band.");
    }
}
$slow = NpcLifespan::quitAt(NpcTiers::INACTIVE, $now, 1, 10);
$fast = NpcLifespan::quitAt(NpcTiers::INACTIVE, $now, 10, 10);
if ($fast - $now >= $slow - $now) {
    npc_fail('A faster server must reach the same quit day in less real time.');
}

// Never in the past, however the roll or the speed comes out: a quit day at or
// before `created` is the frozen-at-birth account this class exists to avoid.
if (NpcLifespan::quitAt(NpcTiers::INACTIVE, $now, 100, 0) <= $now) {
    npc_fail('A quit day must always be at least one second into the future.');
}

// Before the day: behaving as a casual, and allowed to act.
$quitAt = $now + 86400;
if (NpcLifespan::hasQuit(NpcTiers::INACTIVE, $quitAt, $now)) {
    npc_fail('An account that has not reached its quit day has not quit.');
}
if (NpcLifespan::effectiveTier(NpcTiers::INACTIVE, $quitAt, $now) !== NpcTiers::CASUAL) {
    npc_fail('A still-playing inactive must behave as a casual.');
}
if (!NpcLifespan::isPlaying(NpcTiers::INACTIVE, $quitAt, $now)) {
    npc_fail('A still-playing inactive must be allowed to act.');
}

// On and after the day: frozen for good.
foreach ([$now, $now - 1, $now - 999999] as $past) {
    if (!NpcLifespan::hasQuit(NpcTiers::INACTIVE, $past, $now)) {
        npc_fail("quit_at $past must read as quit at $now.");
    }
    if (NpcLifespan::effectiveTier(NpcTiers::INACTIVE, $past, $now) !== NpcTiers::INACTIVE) {
        npc_fail('A quit account must read as inactive.');
    }
}

// A row written before quit_at existed carries 0, which has to keep meaning
// "inactive, full stop" or migrating a world would bring every cow back to life.
if (!NpcLifespan::hasQuit(NpcTiers::INACTIVE, 0, $now)) {
    npc_fail('quit_at 0 must keep meaning "already inactive" for migrated worlds.');
}

// seedAge(): an active tier lives the whole world age...
foreach ([NpcTiers::TOP, NpcTiers::BUILDER, NpcTiers::CASUAL] as $tier) {
    $age = NpcLifespan::seedAge($tier, 120, $now - 1000, 0, 1);
    if ($age['days'] !== 120.0 || $age['tier'] !== $tier) {
        npc_fail("An active tier ($tier) must live the whole seed age: " . json_encode($age));
    }
}

// ...an inactive one lives up to its quit day, and lives it as a casual.
$created = $now - 120 * 86400;
$quitAt  = $created + 10 * 86400;          // ten game days on a x1 server
$age     = NpcLifespan::seedAge(NpcTiers::INACTIVE, 120, $created, $quitAt, 1);
if (abs($age['days'] - 10.0) > 0.001) {
    npc_fail('A quit account must stop ageing on its quit day: ' . json_encode($age));
}
if ($age['tier'] !== NpcTiers::CASUAL) {
    npc_fail('The days an inactive did live, it lived as a casual.');
}

// A world younger than the quit day: the account has not quit yet, so it lived
// all of it.
$age = NpcLifespan::seedAge(NpcTiers::INACTIVE, 4, $created, $quitAt, 1);
if (abs($age['days'] - 4.0) > 0.001) {
    npc_fail('An inactive seeded before its quit day must live the whole age.');
}

// A migrated row with no quit day at all has not lived a single day.
if (NpcLifespan::seedAge(NpcTiers::INACTIVE, 120, $created, 0, 1)['days'] !== 0.0) {
    npc_fail('quit_at 0 means the account was already frozen.');
}

// Speed scales the quit day in real time but not in game days.
$fastQuit = $created + 10 * 86400 / 10;
$age      = NpcLifespan::seedAge(NpcTiers::INACTIVE, 120, $created, $fastQuit, 10);
if (abs($age['days'] - 10.0) > 0.001) {
    npc_fail('The quit day is ten GAME days whatever the server speed: ' . json_encode($age));
}

fwrite(STDOUT, "NpcLifespan regression test passed.\n");
