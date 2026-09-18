<?php

namespace Game\Npc;

/**
 * A neighbour's estimate of a target: how hard it hits back, how much it holds,
 * and whether the odds are the kind a person accepts.
 *
 * Mirrors the engine's own arithmetic closely enough to be right about who
 * wins (the wall and residence bonuses of BattleCalculator, the loss ratio of
 * a won raid being h/(1+h) with h = (def/att)^1.5), and the loot code's
 * treatment of the cranny (per resource, with the attacking Teuton's 80 %).
 * NPCs get no reports, so this is how they "look" at a village; the scouts
 * they send first are the part the player sees.
 *
 * Margins are the point. At 1.5x attack a won raid still loses a third of the
 * wave; at 3x a sixth; at 5x under a tenth. A top account accepts 3x, a
 * builder taking revenge 2.5x, a casual only what is effectively empty.
 *
 * Nothing here reads the engine. Unit stats arrive through NpcGameData, and
 * the two per-level tables this needs - cranny capacity and field production -
 * are handed in by the caller, which is the only side that knows the server's
 * multipliers and speed.
 */
class NpcDefenceEstimate
{
    /**
     * Wall factors per tribe, as BattleCalculator::$wall_base has them.
     * Tribe 4 (Nature) has no wall, hence the flat 1.
     */
    private static $wallFactor = [1 => 1.030, 2 => 1.020, 3 => 1.025, 4 => 1.000, 5 => 1.030, 6 => 1.025, 7 => 1.015];

    /** Minimum attack / defence ratio a tier accepts for a raid. */
    private static $margin = [
        NpcTiers::TOP      => 3.0,
        NpcTiers::BUILDER  => 2.5,
        NpcTiers::CASUAL   => 5.0,
        NpcTiers::INACTIVE => 99.0,
    ];

    /** Ratio a real (type 3) attack needs, whoever sends it. */
    const REAL_ATTACK_MARGIN = 2.5;

    /** Below this many effective defence points a village counts as empty. */
    const EMPTY_BELOW = 60.0;

    /** Exponent the engine applies to the strength ratio. */
    const M_FACTOR = 1.5;

    /** Highest fdata slot that can hold an ordinary building. */
    const LAST_BUILDING_SLOT = 38;

    public static function wallMultiplier($defTribe, $wallLevel)
    {
        $level = max(0, (int) $wallLevel);
        if ($level === 0) {
            return 1.0;
        }
        $factor = isset(self::$wallFactor[(int) $defTribe]) ? self::$wallFactor[(int) $defTribe] : 1.025;
        return round(pow($factor, $level), 3);
    }

    /**
     * Effective defence of a village against a given wave mix.
     *
     * Rows are `units` / `enforcement` rows exactly as the tables store them:
     * tribe-relative u1..u10 with the owning tribe in `race`. A reinforcement
     * from another tribe therefore defends with ITS OWN stats, which is the
     * whole reason the race travels with the row.
     *
     * @param NpcGameData $data
     * @param int   $defTribe   Defender's tribe (for the wall factor, and the
     *                          fallback race of a row that carries none).
     * @param array $rows       One or more rows (race plus u1..u10).
     * @param int   $wallLevel  fdata f40.
     * @param int   $residence  Level of the residence/palace, 0 if none.
     * @param float $cavShare   Share of OUR attack that is mounted, 0..1.
     */
    public static function defence(NpcGameData $data, $defTribe, array $rows, $wallLevel, $residence, $cavShare)
    {
        $cav = max(0.0, min(1.0, (float) $cavShare));
        $dp  = 0.0;
        foreach ($rows as $row) {
            $race = isset($row['race']) && (int) $row['race'] > 0 ? (int) $row['race'] : (int) $defTribe;
            for ($slot = 1; $slot <= NpcTroopMapping::SLOTS_PER_TRIBE; $slot++) {
                $count = isset($row['u' . $slot]) ? (int) $row['u' . $slot] : 0;
                if ($count <= 0) {
                    continue;
                }
                $di = (float) $data->unitStat($race, $slot, 'def_i');
                $dc = (float) $data->unitStat($race, $slot, 'def_c');
                $dp += $count * ($di * (1 - $cav) + $dc * $cav);
            }
        }

        $wall     = self::wallMultiplier($defTribe, $wallLevel);
        $resBonus = (2 * ((int) $residence * (int) $residence)) + 10;

        if ($dp > 0) {
            return $dp * $wall + $resBonus * $wall;
        }
        // Nobody home: the wall alone still fights a little.
        return 10 * $wall * max(0, (int) $wallLevel) + $resBonus * $wall;
    }

    /** True when there is effectively nothing to fight. */
    public static function isEmpty($defence)
    {
        return (float) $defence < self::EMPTY_BELOW;
    }

    /**
     * Effective cranny per resource, as the loot code computes it.
     *
     * The capacity table is the DEFENDER's own, level => resources hidden, so
     * the server's cranny multiplier and the Gaul bonus are already inside it
     * (that is exactly what Formulas::crannyCAP($level, $defRace) returns).
     * Only the attacker-side discount belongs here, because it depends on who
     * is knocking rather than on the village.
     *
     * @param array $fdataRow         The target's fdata row.
     * @param array $capacityByLevel  level => resources this cranny hides.
     * @param int   $attTribe         Attacker's tribe; a Teuton sees 80 %.
     */
    public static function cranny(array $fdataRow, array $capacityByLevel, $attTribe)
    {
        $total = 0.0;
        for ($slot = 19; $slot <= self::LAST_BUILDING_SLOT; $slot++) {
            if (!isset($fdataRow['f' . $slot . 't']) || (int) $fdataRow['f' . $slot . 't'] !== NpcArchetypes::GID_CRANNY) {
                continue;
            }
            $level = isset($fdataRow['f' . $slot]) ? (int) $fdataRow['f' . $slot] : 0;
            if ($level > 0 && isset($capacityByLevel[$level])) {
                $total += (float) $capacityByLevel[$level];
            }
        }
        if ((int) $attTribe === 2) {
            $total *= 0.8;
        }
        return $total;
    }

    /**
     * Resources a raid could carry off right now, all four added up.
     *
     * Stocks in vdata are only brought up to date when the village is opened
     * or fought over, so production since `lastmupdate` is added here from the
     * field levels, capped by storage, then the cranny is taken off each
     * resource.
     *
     * The production table is hourly output of ONE field at a given level,
     * already scaled by the server's speed - Formulas::fieldProduction() has
     * the speed inside it, so applying one here as well would count it twice.
     *
     * @param array $productionByLevel level => resources an hour, per field.
     */
    public static function availableLoot(array $vdata, array $fdata, $now, $cranny, array $productionByLevel)
    {
        $lastUpdate = isset($vdata['lastmupdate']) ? (int) $vdata['lastmupdate'] : (int) $now;
        $elapsed    = max(0, (int) $now - $lastUpdate) / 3600.0;

        $prod = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
        for ($slot = 1; $slot <= 18; $slot++) {
            $gid   = isset($fdata['f' . $slot . 't']) ? (int) $fdata['f' . $slot . 't'] : 0;
            $level = isset($fdata['f' . $slot]) ? (int) $fdata['f' . $slot] : 0;
            if ($gid >= 1 && $gid <= 4 && $level > 0 && isset($productionByLevel[$level])) {
                $prod[$gid] += (float) $productionByLevel[$level];
            }
        }

        $maxStore = isset($vdata['maxstore']) ? (int) $vdata['maxstore'] : 0;
        $maxCrop  = isset($vdata['maxcrop']) ? (int) $vdata['maxcrop'] : 0;
        $stock    = [
            1 => [isset($vdata['wood']) ? (float) $vdata['wood'] : 0.0, $maxStore],
            2 => [isset($vdata['clay']) ? (float) $vdata['clay'] : 0.0, $maxStore],
            3 => [isset($vdata['iron']) ? (float) $vdata['iron'] : 0.0, $maxStore],
            4 => [isset($vdata['crop']) ? (float) $vdata['crop'] : 0.0, $maxCrop],
        ];

        $total = 0;
        foreach ($stock as $res => $pair) {
            list($have, $cap) = $pair;
            $have += $prod[$res] * $elapsed;
            if ($cap > 0) {
                $have = min($have, $cap);
            }
            $total += (int) max(0, floor($have - (float) $cranny));
        }
        return $total;
    }

    /** Share of a WON raid's wave that dies, for an attack/defence ratio. */
    public static function raidLoss($ratio)
    {
        $ratio = max(0.0001, (float) $ratio);
        $h     = pow(1 / $ratio, self::M_FACTOR);
        return $h / (1 + $h);
    }

    /** The odds a tier accepts for a raid. */
    public static function margin($tier)
    {
        return isset(self::$margin[$tier]) ? self::$margin[$tier] : self::$margin[NpcTiers::TOP];
    }

    public static function hasSuperiority($ratio, $tier)
    {
        return (float) $ratio >= self::margin($tier);
    }
}
