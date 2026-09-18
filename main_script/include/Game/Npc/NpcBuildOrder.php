<?php

namespace Game\Npc;

/**
 * The order in which a neighbour's village gets built, per archetype.
 *
 * A list of goals - "fields to 5", "main building to 10", "wall to 8" - walked
 * from the top: the first goal not yet met decides the next level to raise.
 * That is how a person plays: fields early, storage when it overflows, the
 * military line once the economy carries it, and everything to 20 only at the
 * very end. The old pass raised "whatever is lowest", which is how a village
 * ends up with a level 12 cranny and a level 4 main building.
 *
 * Goals may name a building the blueprint never had; the first empty slot is
 * opened for it. Levels are capped by what the ruleset actually defines for
 * that building (a cranny stops at 10), so nothing here can produce a level the
 * engine has no row for.
 *
 * Every level and population figure is asked of the NpcGameData handed in, and
 * nothing else: no globals, no Formulas, no database, so the whole order is
 * unit-tested against a stub.
 */
class NpcBuildOrder
{
    /** Goal key meaning "every resource field to this level". */
    const FIELDS = 'fields';

    /** Resource fields never grow past this in an ordinary NPC village. */
    const MAX_FIELD_LEVEL = 10;

    /**
     * Resource fields of a CAPITAL may go this far.
     *
     * The engine has levels up to 20 for every field, and the
     * capital is the one village a real player pushes there - that is the whole
     * point of hunting a cropper and settling it as the capital. Satellites
     * stop at MAX_FIELD_LEVEL because past that the resources per hour bought
     * per hour spent stop making sense outside the capital.
     */
    const CAPITAL_FIELD_LEVEL = 20;

    /** Building slots a village has: f19..f39 are free-form, f40 is the wall. */
    const FIRST_SLOT = 19;
    const LAST_SLOT  = 39;
    const WALL_SLOT  = 40;

    /**
     * Per-archetype goals: [what, level], walked in order.
     *
     * A capital gets the tail from capitalGoals() appended, which is the only
     * thing that can ever raise a field past MAX_FIELD_LEVEL.
     */
    private static function goals($archetype, $isCapital = false)
    {
        $goals = self::baseGoals($archetype);

        return $isCapital ? array_merge($goals, self::capitalGoals()) : $goals;
    }

    /**
     * What a capital builds once its ordinary goals are met: fields past 10,
     * with the storage to hold what they produce.
     *
     * Interleaved rather than "fields to 20, then storage": a level 18 field
     * feeding a level 16 warehouse just overflows, which is exactly the mistake
     * the ordered goal list exists to prevent.
     */
    private static function capitalGoals()
    {
        $A = NpcArchetypes::class;

        return [
            [self::FIELDS, 13], [$A::GID_WAREHOUSE, 16], [$A::GID_GRANARY, 16],
            [self::FIELDS, 16], [$A::GID_WAREHOUSE, 20], [$A::GID_GRANARY, 20],
            [self::FIELDS, self::CAPITAL_FIELD_LEVEL],
        ];
    }

    /** The goals every village of an archetype walks, capital or not. */
    private static function baseGoals($archetype)
    {
        $A = NpcArchetypes::class;
        switch ($archetype) {
            case NpcArchetypes::WARLORD:
                return [
                    [self::FIELDS, 3], [$A::GID_MAIN, 5], [$A::GID_WAREHOUSE, 3], [$A::GID_GRANARY, 3],
                    [self::FIELDS, 5], [$A::GID_RALLY, 1], [$A::GID_BARRACKS, 3], [$A::GID_CRANNY, 3], [$A::GID_MARKET, 3],
                    [self::FIELDS, 6], [$A::GID_MAIN, 10], [$A::GID_WAREHOUSE, 8], [$A::GID_GRANARY, 8],
                    [$A::GID_ACADEMY, 5], [$A::GID_BARRACKS, 10], [$A::GID_STABLE, 5],
                    [self::FIELDS, 8], [$A::GID_WAREHOUSE, 12], [$A::GID_GRANARY, 12], ['wall', 5], [$A::GID_WORKSHOP, 3], [$A::GID_STABLE, 10],
                    [self::FIELDS, 10], [$A::GID_MAIN, 15], [$A::GID_MARKET, 10], [$A::GID_ACADEMY, 10],
                    [$A::GID_WAREHOUSE, 16], [$A::GID_GRANARY, 16], ['wall', 10], [$A::GID_BARRACKS, 15], [$A::GID_STABLE, 15],
                    [$A::GID_WORKSHOP, 10], [$A::GID_RESIDENCE, 10], [$A::GID_MAIN, 20], [$A::GID_WAREHOUSE, 20], [$A::GID_GRANARY, 20],
                    ['wall', 15], [$A::GID_BARRACKS, 20], [$A::GID_STABLE, 20], [$A::GID_MARKET, 20], [$A::GID_ACADEMY, 15],
                    [$A::GID_CRANNY, 10], ['wall', 20],
                    // The late game: the buildings a finished offensive village
                    // has and this list used to leave out, which is why a
                    // "complete" village only ever filled eleven of its
                    // twenty-one slots and stopped a fifth short of what the
                    // engine can hold.
                    [$A::GID_SMITHY, 20], [$A::GID_TOWN_HALL, 20], [$A::GID_EMBASSY, 10],
                    [$A::GID_TOURNAMENT, 20], [$A::GID_ARMOURY, 20], [$A::GID_TRADE, 20],
                ];
            case NpcArchetypes::GARRISON:
                return [
                    [self::FIELDS, 3], [$A::GID_MAIN, 5], [$A::GID_WAREHOUSE, 3], [$A::GID_GRANARY, 3],
                    [self::FIELDS, 5], [$A::GID_RALLY, 1], [$A::GID_CRANNY, 3], [$A::GID_BARRACKS, 3], ['wall', 3],
                    [self::FIELDS, 6], [$A::GID_MAIN, 10], [$A::GID_WAREHOUSE, 8], [$A::GID_GRANARY, 8],
                    [$A::GID_RESIDENCE, 5], ['wall', 8], [$A::GID_BARRACKS, 8], [$A::GID_MARKET, 3],
                    [self::FIELDS, 8], [$A::GID_WAREHOUSE, 12], [$A::GID_GRANARY, 12], [$A::GID_RESIDENCE, 10], ['wall', 12],
                    [$A::GID_ACADEMY, 5], [$A::GID_STABLE, 3],
                    [self::FIELDS, 10], [$A::GID_MAIN, 15], [$A::GID_BARRACKS, 15], ['wall', 15], [$A::GID_MARKET, 8],
                    [$A::GID_WAREHOUSE, 16], [$A::GID_GRANARY, 16], [$A::GID_RESIDENCE, 15], [$A::GID_CRANNY, 10], [$A::GID_STABLE, 8],
                    [$A::GID_MAIN, 20], [$A::GID_WAREHOUSE, 20], [$A::GID_GRANARY, 20], ['wall', 20], [$A::GID_BARRACKS, 20],
                    [$A::GID_RESIDENCE, 20], [$A::GID_ACADEMY, 10], [$A::GID_MARKET, 12],
                    // A defensive village finishes on armour and the civic
                    // buildings, not on offence.
                    [$A::GID_ARMOURY, 20], [$A::GID_TOWN_HALL, 20], [$A::GID_EMBASSY, 20],
                    [$A::GID_SMITHY, 10], [$A::GID_TRADE, 20], [$A::GID_ACADEMY, 20],
                ];
            default: // FARM and anything unknown: economy first, barely any military
                return [
                    [self::FIELDS, 4], [$A::GID_MAIN, 3], [$A::GID_WAREHOUSE, 5], [$A::GID_GRANARY, 5],
                    [self::FIELDS, 6], [$A::GID_MARKET, 3], [$A::GID_CRANNY, 3],
                    [self::FIELDS, 8], [$A::GID_WAREHOUSE, 12], [$A::GID_GRANARY, 12], [$A::GID_MAIN, 8], [$A::GID_MARKET, 8],
                    [self::FIELDS, 10], [$A::GID_WAREHOUSE, 16], [$A::GID_GRANARY, 16], [$A::GID_CRANNY, 6], [$A::GID_MAIN, 12],
                    [$A::GID_RALLY, 1], [$A::GID_BARRACKS, 3], [$A::GID_RESIDENCE, 5],
                    [$A::GID_WAREHOUSE, 20], [$A::GID_GRANARY, 20], [$A::GID_MARKET, 15], [$A::GID_CRANNY, 10],
                    [$A::GID_MAIN, 15], [$A::GID_RESIDENCE, 10],
                    // An economy village finishes on trade and civics.
                    [$A::GID_TRADE, 20], [$A::GID_TOWN_HALL, 20], [$A::GID_EMBASSY, 20],
                    [$A::GID_SMITHY, 10], [$A::GID_MAIN, 20],
                ];
        }
    }

    /** Population one level of a building adds, from the ruleset. */
    public static function popOf(NpcGameData $data, $gid, $level)
    {
        return (int) $data->buildingPop((int) $gid, (int) $level);
    }

    /**
     * Highest level the ruleset defines for a building, 0 for an unknown gid.
     *
     * Unlike the table this used to read, the cap can depend on whether the
     * village is the capital, so every caller passes what it knows. A caller
     * that does not know says false, which is the lower of the two.
     */
    public static function maxLevel(NpcGameData $data, $gid, $isCapital = false)
    {
        if ((int) $gid <= 0) {
            return 0;
        }

        return (int) $data->buildingMaxLevel((int) $gid, (bool) $isCapital);
    }

    /**
     * The next single level to raise, or null when the village is complete.
     *
     * @param NpcGameData $data      The ruleset: level caps and populations.
     * @param array       $row       fdata row: f1..f40 levels, f1t..f40t gids.
     * @param string      $archetype Blueprint.
     * @param int         $tribe     For the wall gid.
     * @param bool        $isCapital Capitals may push their fields past level 10.
     * @return array|null [slot, gid, newLevel]
     */
    public static function nextStep(NpcGameData $data, array $row, $archetype, $tribe, $isCapital = false)
    {
        $fieldCeiling = self::fieldCeiling($isCapital);

        foreach (self::goals($archetype, $isCapital) as $goal) {
            list($what, $level) = $goal;

            if ($what === self::FIELDS) {
                $step = self::nextField($row, min((int) $level, $fieldCeiling));
            } else {
                $gid = ($what === 'wall') ? (int) $data->wallGid($tribe) : (int) $what;
                if ($gid <= 0) {
                    continue; // tribe without a modelled wall
                }
                $step = self::nextBuilding($data, $row, $gid, (int) $level, $isCapital);
            }
            if ($step !== null) {
                return $step;
            }
        }
        return null;
    }

    /**
     * Plan as many single-level steps as the population budget allows,
     * simulating each on a copy of the row so later goals see earlier ones.
     *
     * @param int  $popBudget Population to add (stops once reached or exceeded).
     * @param int  $maxSteps  Hard cap on levels per call, whatever the budget.
     * @param bool $isCapital Passed straight to nextStep().
     * @return array{steps:array,row:array,pop:int} steps = [slot, gid, level, pop]
     */
    public static function plan(NpcGameData $data, array $row, $archetype, $tribe, $popBudget, $maxSteps = 25, $isCapital = false)
    {
        $steps  = [];
        $gained = 0;
        $limit  = max(0, (int) $maxSteps);

        while (count($steps) < $limit && $gained < (int) $popBudget) {
            $next = self::nextStep($data, $row, $archetype, $tribe, $isCapital);
            if ($next === null) {
                break;
            }
            list($slot, $gid, $level) = $next;
            $pop = self::popOf($data, $gid, $level);

            $row['f' . $slot]       = $level;
            $row['f' . $slot . 't'] = $gid;
            $gained += $pop;
            $steps[] = [$slot, $gid, $level, $pop];
        }

        return ['steps' => $steps, 'row' => $row, 'pop' => $gained];
    }

    /**
     * Population a village of this archetype holds once every goal is met.
     * This is the per-village saturation the growth curve is capped by.
     */
    public static function villageCap(NpcGameData $data, $archetype, $tribe, $isCapital = false, array $fieldGids = null)
    {
        static $cache = [];

        $fieldGids = $fieldGids ? array_slice(array_map('intval', $fieldGids), 0, 18) : self::ORDINARY_VALLEY;
        // The ruleset is part of the key: two NpcGameData instances - the live
        // one and a test stub - do not have to agree on a single figure, and a
        // cache shared between them would hand one the other's answer.
        $key = spl_object_hash($data) . '/' . $archetype . '/' . (int) $tribe . '/' . ($isCapital ? 'c' : 's') . '/' . implode('', $fieldGids);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $final = [];
        foreach (self::goals($archetype, $isCapital) as $goal) {
            list($what, $level) = $goal;
            $gid = ($what === self::FIELDS) ? self::FIELDS : (($what === 'wall') ? (int) $data->wallGid($tribe) : (int) $what);
            if ($gid === 0) {
                continue;
            }
            $final[$gid] = max(isset($final[$gid]) ? $final[$gid] : 0, (int) $level);
        }

        $pop = 0;
        foreach ($final as $gid => $level) {
            if ($gid === self::FIELDS) {
                $pop += self::fieldPop($data, $fieldGids, min($level, self::fieldCeiling($isCapital)));
                continue;
            }
            for ($l = 1; $l <= min($level, self::maxLevel($data, $gid, $isCapital)); $l++) {
                $pop += self::popOf($data, $gid, $l);
            }
        }

        return $cache[$key] = $pop;
    }

    /** Population of a village as described by a column map (f1.., f1t..). */
    public static function popOfColumns(NpcGameData $data, array $columns)
    {
        $pop = 0;
        for ($slot = 1; $slot <= self::WALL_SLOT; $slot++) {
            $level = isset($columns['f' . $slot]) ? (int) $columns['f' . $slot] : 0;
            $gid   = isset($columns['f' . $slot . 't']) ? (int) $columns['f' . $slot . 't'] : ($slot <= 18 ? 1 : 0);
            for ($l = 1; $l <= $level; $l++) {
                $pop += self::popOf($data, $gid, $l);
            }
        }
        return $pop;
    }

    /**
     * The layout of the common valley (4-4-4-6), as f1t..f18t gids.
     *
     * The default when a caller has no real tile to hand. It is NOT "eighteen
     * of the same": the four field types do not weigh the same in population,
     * and cropland barely weighs anything at all (see fieldPop), so assuming
     * one type overstated every ceiling in the subsystem by up to a fifth.
     */
    const ORDINARY_VALLEY = [1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 4, 4];

    /**
     * Population 18 resource fields hold once every one is at $ceiling.
     *
     * Cropland (gid 4) costs almost nothing in population - nothing at all
     * below level 6 - because in Travian cropland FEEDS population rather than
     * housing it. So a cropper is worth markedly LESS population than an
     * ordinary valley: eighteen fields at level 20 are 652 on a 4-4-4-6 and 433
     * on a 1-1-1-15. Anything that treats the eighteen fields as interchangeable
     * gets the village's ceiling wrong, and a ceiling the village can never
     * reach means the growth pass plans nothing, forever, on every pass.
     *
     * @param int[] $fieldGids The f1t..f18t gids of the village.
     */
    public static function fieldPop(NpcGameData $data, array $fieldGids, $ceiling)
    {
        $ceiling = max(0, (int) $ceiling);
        $perGid  = [];
        $pop     = 0;

        foreach ($fieldGids as $gid) {
            $gid = (int) $gid;
            if ($gid < 1 || $gid > 4) {
                continue; // a field slot with no type is not something to grow
            }
            if (!isset($perGid[$gid])) {
                $perGid[$gid] = 0;
                for ($l = 1; $l <= $ceiling; $l++) {
                    $perGid[$gid] += self::popOf($data, $gid, $l);
                }
            }
            $pop += $perGid[$gid];
        }

        return $pop;
    }

    /**
     * villageCap() for a village we actually have the fdata row of, so the real
     * layout of the tile it stands on is used instead of the common valley.
     */
    public static function villageCapForRow(NpcGameData $data, array $row, $archetype, $tribe, $isCapital = false)
    {
        $gids = [];
        for ($slot = 1; $slot <= 18; $slot++) {
            $gids[] = isset($row['f' . $slot . 't']) ? (int) $row['f' . $slot . 't'] : 0;
        }

        return self::villageCap($data, $archetype, $tribe, $isCapital, $gids);
    }

    /** How high this village's resource fields may ever go. */
    public static function fieldCeiling($isCapital = false)
    {
        return $isCapital ? self::CAPITAL_FIELD_LEVEL : self::MAX_FIELD_LEVEL;
    }

    /** The lowest field below $target, as a step, or null. */
    private static function nextField(array $row, $target)
    {
        $slot  = 0;
        $level = PHP_INT_MAX;
        for ($i = 1; $i <= 18; $i++) {
            $current = isset($row['f' . $i]) ? (int) $row['f' . $i] : 0;
            if ($current < $target && $current < $level) {
                $slot  = $i;
                $level = $current;
            }
        }
        if ($slot === 0) {
            return null;
        }
        $gid = isset($row['f' . $slot . 't']) ? (int) $row['f' . $slot . 't'] : 0;
        if ($gid <= 0) {
            return null; // a field without a type is not something to grow
        }
        return [$slot, $gid, $level + 1];
    }

    /** The building's next level, opening a slot for it if it has none. */
    private static function nextBuilding(NpcGameData $data, array $row, $gid, $target, $isCapital = false)
    {
        $target = min($target, self::maxLevel($data, $gid, $isCapital));
        if ($target <= 0) {
            return null;
        }

        $walls  = [(int) $data->wallGid(1), (int) $data->wallGid(2), (int) $data->wallGid(3)];
        $isWall = in_array((int) $gid, $walls, true);
        if ($isWall) {
            $current = isset($row['f' . self::WALL_SLOT]) ? (int) $row['f' . self::WALL_SLOT] : 0;
            $there   = isset($row['f' . self::WALL_SLOT . 't']) ? (int) $row['f' . self::WALL_SLOT . 't'] : 0;
            if ($there > 0 && $there !== $gid) {
                return null; // another wall stands there; never overwrite it
            }
            return $current < $target ? [self::WALL_SLOT, $gid, $current + 1] : null;
        }

        // Already standing somewhere?
        for ($slot = self::FIRST_SLOT; $slot <= self::LAST_SLOT; $slot++) {
            $there = isset($row['f' . $slot . 't']) ? (int) $row['f' . $slot . 't'] : 0;
            if ($there === $gid) {
                $current = isset($row['f' . $slot]) ? (int) $row['f' . $slot] : 0;
                return $current < $target ? [$slot, $gid, $current + 1] : null;
            }
        }

        // Not built yet: prefer the blueprint's slot for it, else the first hole.
        $layout    = NpcArchetypes::layout();
        $preferred = array_search($gid, $layout, true);
        $candidates = [];
        if ($preferred !== false) {
            $candidates[] = (int) $preferred;
        }
        for ($slot = self::FIRST_SLOT; $slot <= self::LAST_SLOT; $slot++) {
            $candidates[] = $slot;
        }
        foreach ($candidates as $slot) {
            $there = isset($row['f' . $slot . 't']) ? (int) $row['f' . $slot . 't'] : 0;
            $level = isset($row['f' . $slot]) ? (int) $row['f' . $slot] : 0;
            if ($there === 0 || $level === 0) {
                return [$slot, $gid, 1];
            }
        }
        return null; // village full
    }
}
