<?php

namespace Model;

use Core\Database\DB;
use Game\Formulas;
use Game\Npc\NpcArchetypes;
use Game\Npc\NpcBalance;
use Game\Npc\NpcBuildOrder;
use Game\Npc\NpcExpansion;
use Game\Npc\NpcFormulasData;
use Game\Npc\NpcLifespan;
use Game\Npc\NpcNames;
use Game\Npc\NpcSeedPlan;
use Game\Npc\NpcTerrain;
use Game\ResourcesHelper;
use function getNpc;
use function logError;

/**
 * The expansion pass: when a neighbour founds another village.
 *
 * ACCOUNT POPULATION IS THE NPC'S CULTURE POINTS. NPCs do accumulate real
 * `users.cp`, but at a rate that would take months per village, so the real
 * gate is useless here; population is the honest substitute and
 * `Game\Npc\NpcExpansion` owns that rule. This class only asks it, finds a
 * valley and writes the village.
 *
 * The new village is deliberately born near empty. targetArmy() divides the
 * account's ceiling by its village count, so founding already lowers the cap of
 * every village it owns; handing the new one a full garrison on top of that
 * would turn expansion into a free troop printer.
 */
class NpcExpandModel
{
    /** How far from home a neighbour will settle, in map squares. */
    const RADIUS_MIN = 2;

    /** @var NpcModel */
    private $npc;

    /** @var NpcGrowthModel */
    private $growth;

    /** @var NpcFormulasData */
    private $data;

    public function __construct(NpcModel $npc = null, NpcGrowthModel $growth = null, NpcFormulasData $data = null)
    {
        $this->npc    = $npc ?: new NpcModel();
        $this->data   = $data ?: new NpcFormulasData();
        $this->growth = $growth ?: new NpcGrowthModel($this->npc, $this->data);
    }

    /**
     * Run the expansion pass over the accounts that are due.
     *
     * @return int Villages founded.
     */
    public function run($limit = null, $interval = null)
    {
        if (!getNpc('enabled', true)) {
            return 0;
        }

        $rows = $this->npc->dueForExpand(
            $interval === null ? getNpc('expandInterval', 7200) : $interval,
            $limit === null ? getNpc('expandBatch', 5) : $limit
        );

        $founded = 0;
        foreach ($rows as $row) {
            // Stamped before the attempt, like the growth pass: a row that
            // throws must not head the next batch forever.
            $this->npc->touch((int) $row['uid'], ['last_expand' => time()]);
            try {
                if ($this->expand($row)) {
                    $founded++;
                }
            } catch (\Throwable $e) {
                logError('NPC expansion failed for uid ' . (int) $row['uid'] . ': ' . $e->getMessage());
            }
        }

        return $founded;
    }

    /**
     * One account's expansion decision, and the village if it says yes.
     *
     * @return bool Whether a village was actually founded.
     */
    public function expand(array $registry, $now = null)
    {
        $now  = $now === null ? time() : (int) $now;
        $uid  = (int) $registry['uid'];
        $tier = NpcModel::effectiveTier($registry, $now);

        if (!NpcLifespan::isPlaying((string) $registry['tier'], (int) $registry['quit_at'], $now)) {
            return false;
        }

        $villages = $this->npc->villagesOf($uid);
        if (!$villages) {
            return false;
        }

        $pop = 0;
        foreach ($villages as $village) {
            $pop += (int) $village['pop'];
        }

        $benchmark = $this->npc->playerBenchmark();
        $decision  = NpcExpansion::shouldExpand(
            [
                'tier'     => $tier,
                'trait'    => (string) $registry['trait'],
                'power'    => (int) $registry['power'],
                'villages' => count($villages),
                'pop'      => $pop,
            ],
            $benchmark['pop'],
            $benchmark['villages'],
            [
                // The frozen per-account ceiling wins over the configured one,
                // which is the entire reason it is a column. The floor is left
                // at its default of 1: max_villages already has the seeded
                // count folded into it, and passing the CURRENT count would
                // raise the ceiling to meet it and refuse every expansion.
                'hardCap' => $this->hardCap($registry),
                'chance'  => (int) getNpc('expandChance', 60),
                'lead'    => (int) getNpc('villageLead', 0),
            ]
        );
        if (!$decision) {
            return false;
        }

        $tile = $this->findValley($registry, $villages, $tier);
        if ($tile === null) {
            return false;
        }

        $tribe    = (int) $registry['race'];
        $register = new RegisterModel();
        $home     = (int) $registry['home_kid'] ?: (int) $villages[0]['kid'];

        $db = DB::getInstance();
        $db->query('UPDATE available_villages SET occupied=1 WHERE kid=' . (int) $tile['kid']);
        $db->query('UPDATE wdata SET occupied=1 WHERE id=' . (int) $tile['kid']);

        if (!$register->createNewVillage($uid, $tribe, (int) $tile['kid'], $home)) {
            $db->query('UPDATE available_villages SET occupied=0 WHERE kid=' . (int) $tile['kid']);
            $db->query('UPDATE wdata SET occupied=0 WHERE id=' . (int) $tile['kid']);

            return false;
        }

        // Seeded from the capital rather than the batch index, so the names
        // an account founds later follow the same series its seeded ones did.
        $name = NpcNames::village($home, count($villages));
        $db->query("UPDATE vdata SET name='" . $db->real_escape_string($name) . "' WHERE kid=" . (int) $tile['kid']);

        $this->npc->touch($uid, [
            'last_expand' => $now,
            'expansions'  => (int) $registry['expansions'] + 1,
        ]);

        $this->settle($registry, (int) $tile['kid'], $tier, count($villages) + 1);

        return true;
    }

    /**
     * Give the new village its first levels and its token garrison.
     *
     * The starting army is a fraction of the PER-VILLAGE cap and deliberately
     * skips NpcBalance::MIN_ARMY: the account's total stays flat and the growth
     * pass fills the village in over the following hours, which is also what a
     * real player's new village looks like.
     */
    private function settle(array $registry, $kid, $tier, $villageCount)
    {
        $archetype = (string) $registry['archetype'];
        $tribe     = (int) $registry['race'];
        $row       = $this->npc->fdata($kid);
        if (!$row) {
            return;
        }

        // Enough to stop being a bare two-population hamlet the moment it is
        // founded; the rest arrives on the ordinary growth passes.
        $budget = (int) floor(NpcBuildOrder::villageCapForRow($this->data, $row, $archetype, $tribe, false) * 0.1);
        if ($budget > 0) {
            // Founded in one go, like a seeded village: the growth pass's step
            // cap exists to spread growth over passes, and there is only one
            // founding.
            $this->growth->buildVillage(
                $kid,
                $row,
                $budget,
                $archetype,
                $tribe,
                false,
                NpcGrowthModel::MAX_STEPS_AT_ONCE
            );
        }

        $villages = $this->npc->villagesOf((int) $registry['uid']);
        $pop      = 0;
        $maxcrop  = 0;
        foreach ($villages as $village) {
            $pop += (int) $village['pop'];
            if ((int) $village['kid'] === (int) $kid) {
                $maxcrop = (int) $village['maxcrop'];
            }
        }

        $perVillage = NpcBalance::cropCap(
            NpcBalance::targetArmy($pop, $tier, (int) $registry['power'], max(1, $villageCount)),
            $maxcrop
        );
        $army = NpcBalance::distribute([], NpcArchetypes::army($archetype), NpcExpansion::startArmy($perVillage));
        if ($army) {
            $this->growth->garrison($kid, $army);
        }

        ResourcesHelper::updateVillageResources($kid, false);
    }

    /**
     * A free valley near the account's home, best first for the tier that
     * cares. Returns null rather than settling across the map when the
     * neighbourhood is full: a village nine hundred squares away is not one the
     * account would ever defend, and not one the player reads as the same
     * neighbour.
     */
    private function findValley(array $registry, array $villages, $tier)
    {
        $home = (int) $registry['home_kid'] ?: (int) $villages[0]['kid'];
        $xy   = Formulas::kid2xy($home);
        $max  = max(self::RADIUS_MIN + 1, (int) getNpc('expandRadius', 12));

        $valleys = $this->npc->freeValleysInRing(
            (int) $xy['x'],
            (int) $xy['y'],
            self::RADIUS_MIN,
            $max,
            (int) (defined('MAP_SIZE') ? MAP_SIZE : 100),
            NpcSeedModel::VALLEY_OVERSAMPLE
        );
        if (!$valleys) {
            return null;
        }

        $valleys = NpcTerrain::rank($valleys, $tier);

        return $valleys[0];
    }

    /**
     * The village ceiling this account is actually held to: the lower of the
     * frozen column and the world's current setting.
     *
     * A frozen 0 means zero, not "unset". Reading it as unset is what let an
     * account seeded before the ceiling was computed from its living tier -
     * where a cow's own ceiling really is 0 - fall back to the global cap and
     * expand anyway.
     */
    private function hardCap(array $registry)
    {
        return min((int) $registry['max_villages'], (int) getNpc('expandMaxVillages', 9));
    }
}
