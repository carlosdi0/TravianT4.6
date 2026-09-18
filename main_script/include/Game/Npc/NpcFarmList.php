<?php

namespace Game\Npc;

/**
 * What a RAIDER remembers about the villages it hits.
 *
 * Every other neighbour picks the nearest legal target, which is fine but
 * inert: it never learns that one village is empty and pays every time, or
 * that another one killed half its army last night.
 *
 * A raider keeps a list instead and sorts it the way a player sorts a raid
 * list - by what it yields per trip, minus what it costs, minus the travel -
 * so it converges on soft, close, rich targets, drops the ones that fight
 * back, and occasionally tries something new. That behaviour, repeated for a
 * few days, is what turns one neighbour into the farm king at the top of the
 * attackers' ranking.
 *
 * Pure scoring, no database: NpcWorld owns the table, this owns the decision.
 */
class NpcFarmList
{
    /** Resource value charged per unit lost, so a costly farm scores badly. */
    const LOSS_VALUE = 250;

    /** A farm is left alone for this long after a raid, so it can refill. */
    const REVISIT = 1800;

    /** How long a burnt farm is off the list. */
    const BURN = 21600;

    /** Score given to a village never tried: optimistic, so it gets tried. */
    const UNTESTED = 900;

    /** Below this many known farms, the raider always looks for more. */
    const MIN_FARMS = 3;

    /** Chance in percent of prospecting anyway when the list is healthy. */
    const EXPLORE_PCT = 20;

    /** Share of the wave that may die before the farm is written off. */
    const BURN_LOSS_PCT = 25;

    /**
     * Value of one trip to this farm.
     *
     * Loot earned minus troops spent, per visit, decayed by distance: a fat
     * village twenty squares away is worth less per hour than a thin one next
     * door, which is exactly the trade a raiding player makes.
     *
     * @param array $farm     hits, loot, losses.
     * @param int   $distance Squares from the raider's village.
     */
    public static function score(array $farm, $distance)
    {
        $hits     = isset($farm['hits']) ? max(0, (int) $farm['hits']) : 0;
        $distance = max(1, (int) $distance);
        $decay    = 1 + ($distance / 10);

        if ($hits === 0) {
            return self::UNTESTED / $decay;
        }

        $loot   = isset($farm['loot']) ? max(0, (int) $farm['loot']) : 0;
        $losses = isset($farm['losses']) ? max(0, (int) $farm['losses']) : 0;
        $net    = ($loot - ($losses * self::LOSS_VALUE)) / $hits;

        return $net / $decay;
    }

    /**
     * True while the raider is still waiting for its scouts to reach a farm
     * it has never hit. `scouted_at` holds the ARRIVAL time of the party.
     */
    public static function awaitingScout(array $farm, $now)
    {
        $hits    = isset($farm['hits']) ? (int) $farm['hits'] : 0;
        $scouted = isset($farm['scouted_at']) ? (int) $farm['scouted_at'] : 0;

        return $hits === 0 && $scouted > (int) $now;
    }

    /** True while a farm is resting, written off, or still being scouted. */
    public static function isAvailable(array $farm, $now)
    {
        $now = (int) $now;

        $burnt = isset($farm['burnt_until']) ? (int) $farm['burnt_until'] : 0;
        if ($burnt > $now) {
            return false;
        }
        if (self::awaitingScout($farm, $now)) {
            return false;
        }

        $last = isset($farm['last_hit']) ? (int) $farm['last_hit'] : 0;

        return ($now - $last) >= self::REVISIT;
    }

    /**
     * Best farm to hit right now.
     *
     * @param array $farms Rows with wref plus a 'distance' key.
     * @return array|null
     */
    public static function pick(array $farms, $now)
    {
        $best      = null;
        $bestScore = null;

        foreach ($farms as $farm) {
            if (!self::isAvailable($farm, $now)) {
                continue;
            }
            $distance = isset($farm['distance']) ? (int) $farm['distance'] : 1;
            $score    = self::score($farm, $distance);

            // A farm that is actively costing troops is not worth a trip even
            // when it is the only one left: better to prospect.
            if ($score <= 0) {
                continue;
            }
            if ($bestScore === null || $score > $bestScore) {
                $best      = $farm;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Whether the raider should look for a new village instead of working the
     * list it has. A short list always prospects; a healthy one does it now
     * and then, which is how the list grows and how the player's new villages
     * get noticed.
     *
     * @param int      $known Farms currently on the list.
     * @param int|null $roll  1..100, injected by the tests.
     */
    public static function shouldExplore($known, $roll = null)
    {
        if ((int) $known < self::MIN_FARMS) {
            return true;
        }
        $roll = $roll === null ? random_int(1, 100) : (int) $roll;

        return $roll <= self::EXPLORE_PCT;
    }

    /**
     * Whether the raid that just came back writes the farm off.
     *
     * Two ways to burn a farm: it killed a real share of the wave, or it was
     * empty of anything worth carrying. The first is a defended village, the
     * second is a village already drained by someone else - a player does not
     * keep sending troops to either.
     */
    public static function shouldBurn($loot, $lost, $sent)
    {
        $loot = max(0, (int) $loot);
        $lost = max(0, (int) $lost);
        $sent = max(1, (int) $sent);

        if ($lost * 100 >= $sent * self::BURN_LOSS_PCT) {
            return true;
        }

        return $loot === 0 && $lost > 0;
    }

    /** Timestamp a burnt farm comes back onto the list. */
    public static function burnUntil($now)
    {
        return (int) $now + self::BURN;
    }
}
