<?php

namespace Game\Npc;

/**
 * Turns "this village should gain N units of these slots" into training orders
 * the engine can actually accept.
 *
 * NpcBalance::distribute() answers in tribe-relative slots and knows nothing
 * about buildings. The engine trains a unit only from the building that makes
 * it, and which slots a building makes DIFFERS BY TRIBE: a Roman barracks
 * makes slots 1-3, a Teuton barracks makes 1-4. Deriving that here would be a
 * second copy of a ruleset table, so the caller passes it in - the same rule
 * the rest of this package follows.
 *
 * Two things this has to get right, both of which were bugs in the engine this
 * design came from:
 *
 *   - A slot whose building is not standing yet is NOT silently dropped. Its
 *     share is handed to the slots that can be trained, so a warlord with a
 *     barracks and no stable still grows its infantry instead of stalling at
 *     zero until the stable exists.
 *   - Nothing is ever ordered from a building at level 0. The engine will
 *     happily insert the training row and then never finish it.
 *
 * Pure data, so it is unit-tested without a world.
 */
class NpcTrainingPlan
{
    /** Never order fewer than this of one unit: a queue of 1 is noise. */
    const MIN_BATCH = 1;

    /**
     * Training orders for one village.
     *
     * @param array $wanted        Relative slot => units to add.
     * @param array $slotsByBuilding gid => relative slots that building trains.
     * @param array $levels        gid => level standing in this village (0 = none).
     * @return array<int,array{0:int,1:int,2:int}> [gid, slot, count], slot order.
     */
    public static function plan(array $wanted, array $slotsByBuilding, array $levels)
    {
        $buildingOf = self::buildingBySlot($slotsByBuilding, $levels);
        if (!$buildingOf) {
            return [];
        }

        $trainable = [];
        $orphaned  = 0;
        foreach ($wanted as $slot => $count) {
            $slot  = (int) $slot;
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            if (isset($buildingOf[$slot])) {
                $trainable[$slot] = (isset($trainable[$slot]) ? $trainable[$slot] : 0) + $count;
                continue;
            }
            $orphaned += $count;
        }
        if (!$trainable) {
            return [];
        }
        if ($orphaned > 0) {
            $trainable = self::reassign($trainable, $orphaned);
        }

        ksort($trainable);
        $orders = [];
        foreach ($trainable as $slot => $count) {
            if ($count < self::MIN_BATCH) {
                continue;
            }
            $orders[] = [$buildingOf[$slot], $slot, $count];
        }

        return $orders;
    }

    /**
     * Relative slot => the gid that trains it, for the buildings this village
     * actually has standing. A slot listed by two buildings (a barracks and a
     * great barracks) resolves to the first one given, which is the caller's
     * own preference order.
     */
    public static function buildingBySlot(array $slotsByBuilding, array $levels)
    {
        $map = [];
        foreach ($slotsByBuilding as $gid => $slots) {
            $gid = (int) $gid;
            if ($gid <= 0 || !isset($levels[$gid]) || (int) $levels[$gid] <= 0) {
                continue;
            }
            foreach ((array) $slots as $slot) {
                $slot = (int) $slot;
                if ($slot >= 1 && $slot <= NpcTroopMapping::SLOTS_PER_TRIBE && !isset($map[$slot])) {
                    $map[$slot] = $gid;
                }
            }
        }

        return $map;
    }

    /**
     * Spread units that had nowhere to go over the slots that do, keeping the
     * existing proportions. The remainder lands on the biggest slot so the
     * total is exactly what the balance asked for.
     */
    private static function reassign(array $trainable, $orphaned)
    {
        $total = array_sum($trainable);
        if ($total <= 0) {
            return $trainable;
        }

        $left = (int) $orphaned;
        foreach ($trainable as $slot => $count) {
            $share = (int) floor($orphaned * $count / $total);
            if ($share > 0) {
                $trainable[$slot] += $share;
                $left -= $share;
            }
        }
        if ($left > 0) {
            $biggest = array_search(max($trainable), $trainable, true);
            $trainable[$biggest] += $left;
        }

        return $trainable;
    }

    /**
     * Split one order into batches no bigger than $max.
     *
     * The training table stores a row per order and the worker walks it unit by
     * unit, so a single order of twenty thousand is a row that takes days to
     * drain and cannot be interrupted. Batching also means a raid that kills
     * the garrison is replaced gradually rather than in one lump.
     *
     * @return int[] Batch sizes, largest first, summing to $count.
     */
    public static function batches($count, $max)
    {
        $count = max(0, (int) $count);
        $max   = max(1, (int) $max);
        if ($count === 0) {
            return [];
        }

        $batches = [];
        while ($count > 0) {
            $take = min($count, $max);
            $batches[] = $take;
            $count -= $take;
        }

        return $batches;
    }
}
