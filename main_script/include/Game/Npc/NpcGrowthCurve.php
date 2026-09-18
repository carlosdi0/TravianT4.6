<?php

namespace Game\Npc;

/**
 * The population a neighbour's account should have reached, from how long it
 * has been "playing".
 *
 * Time is measured in game days: real seconds since the account was created,
 * scaled by SPEED, so a x10 server runs through the same curve ten times as
 * fast. The curve is a top account's on a x1 server, roughly:
 *
 *   day 1 ≈ 90   day 7 ≈ 645   day 14 ≈ 1320   day 30 ≈ 2970   day 60 ≈ 6480
 *
 * and each tier scales it (TOP and BUILDER play it in full, CASUAL at half,
 * INACTIVE not at all). Two things keep it honest:
 *
 *   - a village saturates at a few hundred population, so the target is also
 *     capped at villages x villageCap; the part of the curve above that is the
 *     account's pressure to found another village, not free population;
 *   - the pass never adds the whole gap at once. Each growth pass adds a
 *     fraction of the deficit, so a world that was frozen for days catches up
 *     over hours and a village that was raided flat takes real time to
 *     recover, on x1 and x10 alike.
 *
 * Pure arithmetic, unit-tested without a database.
 */
class NpcGrowthCurve
{
    /** Population per game day at the start of the curve. */
    const BASE_PER_DAY = 90.0;

    /** Quadratic term: the curve accelerates as villages multiply. */
    const QUAD = 0.3;

    /** Never add less than this per pass while below target. */
    const MIN_STEP = 6;

    /** Share of the deficit closed per pass, in percent. */
    const CATCHUP_PCT = 15;

    /** How fast each tier walks the curve. */
    private static $tierFactor = [
        NpcTiers::TOP      => 1.0,
        NpcTiers::BUILDER  => 1.0,
        NpcTiers::CASUAL   => 0.5,
        NpcTiers::INACTIVE => 0.0,
    ];

    /** Game days lived: real age scaled by the server speed. */
    public static function gameDays($ageSeconds, $speed)
    {
        $ageSeconds = max(0, (int) $ageSeconds);
        $speed      = max(1, (int) $speed);

        return $ageSeconds * $speed / 86400.0;
    }

    /** The reference curve: a top account on a x1 server, in population. */
    public static function curve($gameDays)
    {
        $t = max(0.0, (float) $gameDays);

        return self::BASE_PER_DAY * $t + self::QUAD * $t * $t;
    }

    /** Inverse of curve(): the game day at which the curve reaches $pop. */
    public static function ageForPop($pop)
    {
        $pop = max(0.0, (float) $pop);
        if ($pop <= 0) {
            return 0.0;
        }
        // 0.3 t^2 + 90 t - pop = 0
        return (-self::BASE_PER_DAY + sqrt(self::BASE_PER_DAY * self::BASE_PER_DAY + 4 * self::QUAD * $pop)) / (2 * self::QUAD);
    }

    /** 1.0 for a full-pace tier, 0 for a cow, 1.0 for anything unknown. */
    public static function tierFactor($tier)
    {
        return isset(self::$tierFactor[$tier]) ? self::$tierFactor[$tier] : 1.0;
    }

    /** 0.75 at power 0, 1.25 at power 100: a quarter either way. */
    public static function powerFactor($power)
    {
        $power = max(0, min(100, (int) $power));

        return 0.75 + $power / 200.0;
    }

    /**
     * The population the ACCOUNT should have by now.
     *
     * @param float  $gameDays   From gameDays().
     * @param string $tier       NpcTiers constant.
     * @param int    $power      0..100 per-account modifier.
     * @param int    $villages   Villages the account owns.
     * @param int    $villageCap Population one ORDINARY village saturates at.
     * @param int    $capitalCap Saturation of the capital, which builds its
     *                           fields past level 10 and so saturates higher.
     *                           0 means "same as the others", which is what
     *                           every caller wanted before capitals differed.
     */
    public static function targetPop($gameDays, $tier, $power, $villages, $villageCap, $capitalCap = 0)
    {
        $factor = self::tierFactor($tier);
        if ($factor <= 0) {
            return 0;
        }
        $villages   = max(1, (int) $villages);
        $villageCap = max(0, (int) $villageCap);
        $capitalCap = max(0, (int) $capitalCap);

        // An account owns exactly one capital, so its ceiling is that one plus
        // the satellites. Multiplying by the satellite cap - what this did
        // before capitals could outgrow them - would freeze a cropper capital
        // the moment it passed the ordinary saturation point.
        $cap = $capitalCap > 0
             ? $capitalCap + ($villages - 1) * $villageCap
             : $villages * $villageCap;

        return self::targetPopCapped($gameDays, $tier, $power, $cap);
    }

    /**
     * targetPop() against a ceiling the caller worked out itself.
     *
     * The growth pass has every village's fdata row in hand, so it can add up
     * what each one ACTUALLY saturates at - the layout of the tile it stands on
     * decides that, because cropland barely holds any population. Summing the
     * real ceilings is the difference between a pass that finishes and one that
     * plans nothing on every run forever, convinced the account is still short.
     *
     * @param int $capTotal Population the account's villages hold between them.
     */
    public static function targetPopCapped($gameDays, $tier, $power, $capTotal)
    {
        $factor = self::tierFactor($tier);
        if ($factor <= 0) {
            return 0;
        }
        $raw = self::curve($gameDays) * $factor * self::powerFactor($power);

        return (int) floor(min($raw, max(0, (int) $capTotal)));
    }

    /**
     * The game day at which this account's own curve (tier and power applied)
     * reaches $pop. Used to back-date `created` for a freshly seeded account,
     * which is born with a few hundred population and would otherwise sit
     * "over target" and frozen until the curve caught up with it.
     */
    public static function ageForTarget($pop, $tier, $power)
    {
        $factor = self::tierFactor($tier) * self::powerFactor($power);
        if ($factor <= 0) {
            return 0.0;
        }
        return self::ageForPop($pop / $factor);
    }

    /**
     * Optional leash back to the human player. 0 = off. Above 0, no account
     * may target more than $lead times the player's population.
     */
    public static function softCap($target, $playerPop, $lead)
    {
        $target = max(0, (int) $target);
        $lead   = (float) $lead;
        if ($lead <= 0) {
            return $target;
        }
        return (int) min($target, floor(max(0, (int) $playerPop) * $lead));
    }

    /**
     * Population to add this pass. A share of the deficit, floored so growth
     * never stalls, zero once at or above target. Speed-independent: on x10 the
     * deficit is ten times bigger, so the step is too.
     */
    public static function stepPop($deficit, $pct = self::CATCHUP_PCT, $min = self::MIN_STEP)
    {
        $deficit = (int) $deficit;
        if ($deficit <= 0) {
            return 0;
        }
        $pct = max(1, min(100, (int) $pct));
        $min = max(1, (int) $min);

        return min($deficit, max($min, (int) ceil($deficit * $pct / 100)));
    }
}
