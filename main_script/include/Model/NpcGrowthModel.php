<?php

namespace Model;

use Controller\Build\TroopBuilding;
use Core\Database\DB;
use Game\Buildings\BuildingAction;
use Game\Formulas;
use Game\Npc\NpcArchetypes;
use Game\Npc\NpcBalance;
use Game\Npc\NpcBuildOrder;
use Game\Npc\NpcFormulasData;
use Game\Npc\NpcGrowthCurve;
use Game\Npc\NpcLifespan;
use Game\Npc\NpcTiers;
use Game\Npc\NpcTrainingPlan;
use Game\Npc\NpcTroopMapping;
use Game\ResourcesHelper;
use function getGameSpeed;
use function getNpc;
use function logError;
use function nrToUnitId;
use function unitIdToNr;

/**
 * The growth pass: what a neighbour does with the time that has passed.
 *
 * A neighbour does not run an economy. Its population comes from its own curve
 * (NpcGrowthCurve), and the pass turns that number into levels that really
 * stand in `fdata` and troops that really queue in `training`. That is the
 * whole trick: from the outside it is an account that built things overnight,
 * and every table the map, the rankings and combat read is the real one.
 *
 * Buildings are applied straight through `Game\Buildings\BuildingAction`
 * rather than queued in `building_upgrade`, because the queue is a spend of
 * resources a neighbour does not have. Troops go the other way - they are
 * queued through `Model\TrainingModel`, so they take real time to appear and a
 * raid that kills a garrison buys the player actual hours.
 *
 * Nothing here reads `Core\Session` or `$_REQUEST`, and nothing calls into
 * `Core\Village`, whose failure path calls `exit()`. It runs in a worker.
 */
class NpcGrowthModel
{
    /**
     * Levels one village may gain in a single GROWTH pass, whatever the budget.
     *
     * It exists to spread growth over passes, not to bound the work: seeding
     * builds a village in one go and passes its own, far higher, cap. Leaving
     * this one in place there is what made a seeded account come out at a
     * quarter of the population its own curve had just asked for.
     */
    const MAX_STEPS_PER_VILLAGE = 25;

    /**
     * Levels one village may gain when it is built in a single call.
     *
     * High enough to reach saturation on the longest build order (the warlord
     * capital runs past 300 levels), and still a bound rather than a while(true).
     */
    const MAX_STEPS_AT_ONCE = 600;

    /** Hero experience is worth this fraction on a half-pace tier. */
    const HERO_TIER_FACTOR = [
        NpcTiers::TOP      => 1.0,
        NpcTiers::BUILDER  => 0.7,
        NpcTiers::CASUAL   => 0.35,
        NpcTiers::INACTIVE => 0.0,
    ];

    /** @var NpcFormulasData */
    private $data;

    /** @var NpcModel */
    private $npc;

    public function __construct(NpcModel $npc = null, NpcFormulasData $data = null)
    {
        $this->npc  = $npc ?: new NpcModel();
        $this->data = $data ?: new NpcFormulasData();
    }

    /**
     * Run the growth pass over the accounts that are due.
     *
     * @return int Accounts touched.
     */
    public function run($limit = null, $interval = null)
    {
        if (!getNpc('enabled', true)) {
            return 0;
        }

        $rows = $this->npc->dueForGrowth(
            $interval === null ? getNpc('growthInterval', 900) : $interval,
            $limit === null ? getNpc('growthBatch', 10) : $limit
        );

        $touched = 0;
        foreach ($rows as $row) {
            // The stamp is written first and unconditionally. A row that
            // throws halfway must not come back at the head of the very next
            // batch and starve every account behind it.
            $this->npc->touch((int) $row['uid'], ['last_growth' => time()]);
            try {
                if ($this->grow($row)) {
                    $touched++;
                }
            } catch (\Throwable $e) {
                logError('NPC growth failed for uid ' . (int) $row['uid'] . ': ' . $e->getMessage());
            }
        }

        return $touched;
    }

    /**
     * One account's pass: build, train, and age its hero.
     *
     * @param array $registry Registry row joined with the user row.
     */
    public function grow(array $registry, $now = null)
    {
        $now   = $now === null ? time() : (int) $now;
        $uid   = (int) $registry['uid'];
        $tier  = NpcModel::effectiveTier($registry, $now);

        if (!NpcLifespan::isPlaying((string) $registry['tier'], (int) $registry['quit_at'], $now)) {
            return false;
        }

        $villages = $this->npc->villagesOf($uid);
        if (!$villages) {
            return false; // conquered down to nothing; the registry row is harmless
        }

        $built = $this->growBuildings($registry, $villages, $tier, $now);
        // Re-read when anything was built: the army ceiling is measured
        // against the account's population, and reusing the rows from before
        // the levels went up leaves the army permanently one pass behind.
        if ($built > 0) {
            $villages = $this->npc->villagesOf($uid);
        }
        $trained = $this->growArmy($registry, $villages, $tier);
        $this->growHero($uid, $tier, (int) $registry['power'], $registry, $now);

        // A neighbour that acted was "online". A cow never is, which is what
        // makes it read as inactive on the map - the same signal a real player
        // uses to pick their farms.
        if (NpcTiers::refreshesTimestamp($tier)) {
            DB::getInstance()->query("UPDATE users SET last_login_time=$now WHERE id=$uid");
        }

        return $built > 0 || $trained > 0;
    }

    /**
     * Raise levels until this pass's population budget is spent.
     *
     * The budget is a share of the gap to the account's curve, so a world that
     * was frozen for a week catches up over hours rather than in one tick.
     *
     * @return int Population actually added.
     */
    public function growBuildings(array $registry, array $villages, $tier, $now)
    {
        $archetype = (string) $registry['archetype'];
        $tribe     = (int) $registry['race'];
        $power     = (int) $registry['power'];

        $rows      = [];
        $capTotal  = 0;
        $popTotal  = 0;
        foreach ($villages as $village) {
            $kid = (int) $village['kid'];
            $row = $this->npc->fdata($kid);
            if (!$row) {
                continue;
            }
            $isCapital = (int) $village['capital'] === 1;
            $cap       = NpcBuildOrder::villageCapForRow($this->data, $row, $archetype, $tribe, $isCapital);
            // A frozen per-account ceiling never raises the real saturation of
            // a village, it only ever lowers it. See docs/NPC.md.
            $ceiling   = (int) $registry['max_pop_per_village'];
            if ($ceiling > 0) {
                $cap = min($cap, $ceiling);
            }
            $rows[] = [
                'kid'       => $kid,
                'row'       => $row,
                'isCapital' => $isCapital,
                'cap'       => $cap,
                'pop'       => (int) $village['pop'],
            ];
            $capTotal += $cap;
            $popTotal += (int) $village['pop'];
        }
        if (!$rows) {
            return 0;
        }

        $days   = NpcGrowthCurve::gameDays($now - (int) $registry['created'], getGameSpeed());
        $target = NpcGrowthCurve::targetPopCapped($days, $tier, $power, $capTotal);
        $target = NpcGrowthCurve::softCap($target, $this->playerPop(), (float) getNpc('popLead', 0));

        $budget = NpcGrowthCurve::stepPop($target - $popTotal);
        if ($budget <= 0) {
            return 0;
        }

        // Whichever village is furthest from its own ceiling gets the budget
        // first: that is the one a person would be working on.
        usort($rows, function ($a, $b) {
            return ($b['cap'] - $b['pop']) <=> ($a['cap'] - $a['pop']);
        });

        $gained = 0;
        foreach ($rows as $village) {
            if ($budget <= 0) {
                break;
            }
            $room = $village['cap'] - $village['pop'];
            if ($room <= 0) {
                continue;
            }
            $spend  = min($budget, $room);
            $added  = $this->buildVillage(
                $village['kid'],
                $village['row'],
                $spend,
                (string) $registry['archetype'],
                (int) $registry['race'],
                $village['isCapital']
            );
            $budget -= $added;
            $gained += $added;
        }

        return $gained;
    }

    /**
     * Apply as many single levels to one village as $popBudget pays for.
     *
     * The plan is computed against a copy of the row, so later goals see the
     * earlier ones, and then written level by level. A building the village
     * does not have yet gets its gid written into the slot first: upgrade()
     * reads `f{slot}t` to know WHAT it is raising and silently does nothing
     * when the slot is empty.
     *
     * @return int Population added.
     */
    public function buildVillage($kid, array $row, $popBudget, $archetype, $tribe, $isCapital, $maxSteps = null)
    {
        $plan = NpcBuildOrder::plan(
            $this->data,
            $row,
            $archetype,
            $tribe,
            (int) $popBudget,
            $maxSteps === null ? self::MAX_STEPS_PER_VILLAGE : (int) $maxSteps,
            (bool) $isCapital
        );
        if (!$plan['steps']) {
            return 0;
        }

        $db  = DB::getInstance();
        $kid = (int) $kid;
        foreach ($plan['steps'] as $step) {
            list($slot, $gid, $level) = $step;
            $slot = (int) $slot;
            $gid  = (int) $gid;
            if ($level === 1) {
                $db->query("UPDATE fdata SET f{$slot}t=$gid WHERE kid=$kid AND (f{$slot}t=0 OR f{$slot}=0)");
            }
            BuildingAction::upgrade($kid, $slot, 1, false, true);
        }

        ResourcesHelper::updateVillageResources($kid, false);

        return (int) $plan['pop'];
    }

    /**
     * Queue troops towards the account's ceiling.
     *
     * The ceiling is per ACCOUNT and then split across its villages: the
     * yardstick is the account's population, which is also an account total,
     * so applying it per village would multiply every neighbour by however
     * many villages it happens to own.
     *
     * @return int Units ordered.
     */
    public function growArmy(array $registry, array $villages, $tier)
    {
        if (!NpcTiers::isActive($tier)) {
            return 0;
        }

        $tribe     = (int) $registry['race'];
        $archetype = (string) $registry['archetype'];
        $ownPop    = 0;
        foreach ($villages as $village) {
            $ownPop += (int) $village['pop'];
        }

        $bonus  = NpcBalance::lootBonus((int) $registry['raid_loot'], $ownPop);
        $target = NpcBalance::targetArmy($ownPop, $tier, (int) $registry['power'], count($villages), $bonus);
        if ($target <= 0) {
            return 0;
        }

        $batchMax = max(1, (int) getNpc('trainBatchMax', 50));
        $ordered  = 0;
        foreach ($villages as $village) {
            $kid       = (int) $village['kid'];
            $unitsRow  = $this->npc->units($kid);
            if (!$unitsRow) {
                continue;
            }
            $garrison  = NpcTroopMapping::fightingArmyFromRow($unitsRow);
            // Troops already in the queue count as owned, or every pass would
            // order the same units again while the first batch is still cooking.
            $current   = array_sum($garrison) + $this->npc->queuedUnits($kid);
            $perVillage = NpcBalance::cropCap($target, (int) $village['maxcrop']);

            $step = NpcBalance::armyStep($current, $perVillage);
            if ($step <= 0) {
                continue;
            }

            $wanted = NpcBalance::distribute($garrison, NpcArchetypes::army($archetype), $step);
            if (!$wanted) {
                continue;
            }

            $orders = NpcTrainingPlan::plan(
                $wanted,
                $this->trainableSlots($tribe),
                $this->npc->trainingBuildings($kid)
            );
            foreach ($orders as $order) {
                list($gid, $slot, $count) = $order;
                $ordered += $this->queueTraining($kid, $tribe, $gid, $slot, $count, $batchMax);
            }
        }

        return $ordered;
    }

    /**
     * Hero experience, the way the fake-user pass does it: the only other hero
     * in the engine that grows without ever going on an adventure.
     *
     * The difference is that this one grows at the account's own pace. A top
     * neighbour's hero pulls ahead, a casual's trails, and a cow's stops the
     * day the account does - so a player who scouts one can read the account's
     * seriousness off its hero, exactly as they would a real neighbour's.
     */
    public function growHero($uid, $tier, $power, array $registry, $now)
    {
        $factor = isset(self::HERO_TIER_FACTOR[$tier]) ? self::HERO_TIER_FACTOR[$tier] : 0.35;
        if ($factor <= 0) {
            return 0;
        }

        $db   = DB::getInstance();
        $uid  = (int) $uid;
        $hero = $db->query("SELECT uid, exp, health, lastupdate FROM hero WHERE uid=$uid");
        if (!$hero->num_rows) {
            return 0;
        }
        $hero = $hero->fetch_assoc();

        $since = (int) $hero['lastupdate'];
        if ($since <= 0) {
            $since = (int) $registry['created'];
        }
        $elapsed = max(0, $now - $since);
        if ($elapsed <= 0) {
            return 0;
        }

        $days = NpcGrowthCurve::gameDays($elapsed, getGameSpeed());
        $exp  = (int) floor(
            $days * (float) getNpc('heroExpPerDay', 120) * $factor * NpcGrowthCurve::powerFactor($power)
        );
        // Health comes back on its own, the way a player's does between
        // adventures; a hero stuck at 1 % would never be worth scouting.
        $health = min(100.0, (float) $hero['health'] + ($days * 10.0));

        $db->query("UPDATE hero SET exp=exp+$exp, health=$health, lastupdate=$now WHERE uid=$uid");

        return $exp;
    }

    /**
     * Put units into a village directly, without the queue.
     *
     * Only seeding uses this: an account born three weeks old has to come with
     * the garrison those three weeks would have produced, and queueing it
     * would leave the whole neighbourhood defenceless for hours after a seed.
     * The growth pass never calls it.
     *
     * @param array $army Relative slot => units to add.
     */
    public function garrison($kid, array $army)
    {
        $kid   = (int) $kid;
        $set   = [];
        $added = 0;
        foreach ($army as $slot => $count) {
            $slot  = (int) $slot;
            $count = (int) $count;
            if ($slot < 1 || $slot > NpcTroopMapping::SLOTS_PER_TRIBE || $count <= 0) {
                continue;
            }
            $set[] = "u$slot=u$slot+$count";
            $added += $count;
        }
        if (!$set) {
            return 0;
        }

        $db = DB::getInstance();
        $db->query('UPDATE units SET ' . implode(',', $set) . " WHERE kid=$kid");
        // The crop these units eat is not optional: without this the village
        // looks free to feed until the next upkeep pass starves the garrison.
        $owner = (int) $db->fetchScalar("SELECT owner FROM vdata WHERE kid=$kid");
        if ($owner > 0) {
            ResourcesHelper::updateVillageUpkeep($owner, $kid);
        }

        return $added;
    }

    /** gid => tribe-relative slots that building trains, for this tribe. */
    public function trainableSlots($tribe)
    {
        $tribe = (int) $tribe;
        $map   = [];
        // Ordinary buildings before their great counterparts: a village with
        // both should fill the cheap one, and the plan takes the first match.
        foreach ([19, 20, 21, 29, 30] as $gid) {
            $slots = TroopBuilding::_getTroopBuildingTroopsStatic($tribe, $gid);
            if (!$slots) {
                continue;
            }
            $map[$gid] = array_map('unitIdToNr', $slots);
        }

        return $map;
    }

    /** One training order, split into batches the worker can drain. */
    private function queueTraining($kid, $tribe, $gid, $slot, $count, $batchMax)
    {
        $unitId = nrToUnitId((int) $slot, (int) $tribe);
        $levels = $this->npc->trainingBuildings((int) $kid);
        $level  = isset($levels[(int) $gid]) ? (int) $levels[(int) $gid] : 0;
        if ($level <= 0) {
            return 0;
        }

        // Formulas::$data is only populated by load(), which nothing
        // guarantees has run in a worker that never rendered a page.
        if (!is_array(Formulas::$data)) {
            Formulas::load();
        }
        $horse = isset($levels[41]) ? (int) $levels[41] : 0;
        $time  = max(1, (int) Formulas::uTrainingTime($unitId, $level, $horse, [0, 0], 1, 0));

        $training = new TrainingModel();
        $ordered  = 0;
        foreach (NpcTrainingPlan::batches((int) $count, (int) $batchMax) as $batch) {
            $training->addTraining((int) $kid, (int) $gid, (int) $slot, $batch, $time);
            $ordered += $batch;
        }

        return $ordered;
    }

    /** Only read when a lead leash is configured; 0 keeps it out of the query. */
    private function playerPop()
    {
        if ((float) getNpc('popLead', 0) <= 0) {
            return 0;
        }
        $benchmark = $this->npc->playerBenchmark();

        return (int) $benchmark['pop'];
    }
}
