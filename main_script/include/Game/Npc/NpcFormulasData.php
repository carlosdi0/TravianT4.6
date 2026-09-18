<?php

namespace Game\Npc;

use Game\Formulas;

/**
 * The real ruleset, read through Formulas.
 *
 * This is the only class under Game\Npc that knows the engine exists. It also
 * owns the one conversion that is easiest to get wrong: the `units` table
 * stores tribe-relative slots (u1..u10 plus a race column), while the formulas
 * want absolute unit ids, where unit N of tribe T is id (T-1)*10+N. Mixing the
 * two hands an NPC somebody else's army.
 */
class NpcFormulasData implements NpcGameData
{
    /** Units per tribe in both numbering schemes. */
    const SLOTS_PER_TRIBE = 10;

    /** Absolute unit id for a tribe-relative slot, 0 when out of range. */
    public static function absoluteUnitId($tribe, $slot)
    {
        $tribe = (int)$tribe;
        $slot = (int)$slot;
        if ($tribe < 1 || $slot < 1 || $slot > self::SLOTS_PER_TRIBE) {
            return 0;
        }

        return ($tribe - 1) * self::SLOTS_PER_TRIBE + $slot;
    }

    public function unitStat($tribe, $slot, $key)
    {
        if (self::absoluteUnitId($tribe, $slot) === 0) {
            return 0.0;
        }
        // Formulas::$data is only populated by load(), which nothing guarantees
        // has run in a worker that never rendered a page.
        if (!is_array(Formulas::$data)) {
            Formulas::load();
        }
        // The table is 0-indexed on both axes.
        $race = (int)$tribe - 1;
        $unit = (int)$slot - 1;

        return isset(Formulas::$data['units'][$race][$unit][$key])
            ? (float)Formulas::$data['units'][$race][$unit][$key]
            : 0.0;
    }

    public function buildingMaxLevel($gid, $isCapital)
    {
        $gid = (int)$gid;
        if ($gid <= 0) {
            return 0;
        }

        // $real=false keeps resource fields at their normal 20 instead of the
        // unbounded value the capital uses when the server allows it: an NPC
        // that chased 1e9 would never consider a field finished.
        return (int)Formulas::buildingMaxLvl($gid, (bool)$isCapital, false);
    }

    public function buildingPop($gid, $level)
    {
        $gid = (int)$gid;
        $level = (int)$level;
        if ($gid <= 0 || $level < 1) {
            return 0;
        }
        list($pop) = Formulas::buildingCpPop($gid, $level - 1, $level, true);

        return (int)$pop;
    }

    public function wallGid($tribe)
    {
        $tribe = (int)$tribe;
        if ($tribe < 1 || $tribe > 7) {
            return 0;
        }

        return (int)Formulas::getWallID($tribe);
    }
}
