<?php

namespace Game\Npc;

/**
 * The rhythm of a neighbour: when it sleeps, and when its next wave leaves.
 *
 * Nothing gives a bot away faster than punctuality. A raid pass that fires
 * every NPC_ATTACK_INTERVAL and a cooldown that is exactly NPC_ATTACK_COOLDOWN
 * produce attacks the player can set a watch by, and a world that never has a
 * quiet hour or a busy one.
 *
 * So each neighbour gets its own `next_attack` instead: jittered, pushed past
 * its sleeping hours, and spent in bursts - several waves back to back, then a
 * long silence, which is what a human raid list actually looks like.
 *
 * The sleeping window is derived from the uid, never stored and never random,
 * so it survives restarts and a neighbour keeps the same habits all server.
 */
class NpcClock
{
    const DAY  = 86400;
    const HOUR = 3600;

    /** Never let a jittered cooldown drop below this. */
    const MIN_COOLDOWN = 300;

    /** Spread applied to every cooldown, in percent. */
    const JITTER_PCT = 50;

    /** Stable per-neighbour hash; same uid always gives the same habits. */
    private static function seed($uid)
    {
        return crc32('npc-clock-' . (int) $uid);
    }

    /** Hour of day (0..23) for a timestamp, in the server's own timezone. */
    public static function hourOf($timestamp)
    {
        return (int) date('G', (int) $timestamp);
    }

    /**
     * Hour a neighbour goes offline.
     *
     * Most sleep at night, between 21:00 and 05:00, staggered so no two
     * neighbours wake up together. Roughly one in seven is a night owl that
     * sleeps through the middle of the day instead, which is what keeps the
     * small hours from being a guaranteed safe window for the player.
     */
    public static function sleepStart($uid)
    {
        $seed = self::seed($uid);

        if ($seed % 7 === 0) {
            return 9 + ($seed % 5); // 9..13, the one who plays all night
        }

        return (21 + ($seed % 9)) % 24; // 21..23 and 0..5
    }

    /** Hours a neighbour stays offline, from its trait, varied by uid. */
    public static function sleepHours($uid, $trait = NpcTraits::STEADY)
    {
        $base  = (int) NpcTraits::value($trait, 'sleep', 7);
        $shift = (self::seed($uid) % 3) - 1; // -1, 0 or +1

        return max(3, min(12, $base + $shift));
    }

    /** True while the neighbour is inside its own sleeping window. */
    public static function isAsleep($uid, $timestamp, $trait = NpcTraits::STEADY)
    {
        $hours = self::sleepHours($uid, $trait);
        $start = self::sleepStart($uid);
        $hour  = self::hourOf($timestamp);

        // The window wraps midnight, so compare in "hours since falling asleep".
        $elapsed = ($hour - $start + 24) % 24;

        return $elapsed < $hours;
    }

    /**
     * When the neighbour is next awake. Returns the timestamp untouched when
     * it is already up, so callers can apply it unconditionally.
     */
    public static function wakeAt($uid, $timestamp, $trait = NpcTraits::STEADY)
    {
        $timestamp = (int) $timestamp;
        if (!self::isAsleep($uid, $timestamp, $trait)) {
            return $timestamp;
        }

        $hours   = self::sleepHours($uid, $trait);
        $start   = self::sleepStart($uid);
        $hour    = self::hourOf($timestamp);
        $elapsed = ($hour - $start + 24) % 24;
        $left    = $hours - $elapsed;

        // Wake on the hour, minus the minutes already served this hour, plus a
        // few minutes so a whole neighbourhood does not log in at once.
        $minutes = (int) date('i', $timestamp);
        $seconds = (int) date('s', $timestamp);

        return $timestamp
             + ($left * self::HOUR)
             - ($minutes * 60) - $seconds
             + (self::seed($uid) % 1800);
    }

    /**
     * Spread a delay by +/- JITTER_PCT.
     *
     * @param int      $seconds Base delay.
     * @param int|null $roll    0..100; null draws one. Injected by the tests.
     */
    public static function jitter($seconds, $roll = null)
    {
        $seconds = max(0, (int) $seconds);
        $roll    = $roll === null ? random_int(0, 100) : max(0, min(100, (int) $roll));

        // roll 0 => -JITTER_PCT, roll 50 => unchanged, roll 100 => +JITTER_PCT.
        $factor = 1.0 + ((($roll - 50) / 50) * (self::JITTER_PCT / 100));

        return (int) round($seconds * $factor);
    }

    /** Waves a trait fires back to back before its long rest. */
    public static function burstSize($trait)
    {
        return max(1, (int) NpcTraits::value($trait, 'burst', 1));
    }

    /**
     * Timestamp of a neighbour's next wave.
     *
     * @param int      $now         Current time.
     * @param int      $baseCooldown NPC_ATTACK_COOLDOWN.
     * @param string   $trait       Personality.
     * @param int      $burstLeft   Waves still owed in the current burst; 0 or
     *                              less means the burst is spent and the long
     *                              rest applies.
     * @param int      $uid         Neighbour, for its sleeping window.
     * @param int|null $roll        0..100, injected by the tests.
     */
    public static function nextAttack($now, $baseCooldown, $trait, $burstLeft, $uid, $roll = null)
    {
        $now      = (int) $now;
        $cooldown = max(60, (int) $baseCooldown) * (float) NpcTraits::value($trait, 'cooldown', 1.0);

        if ((int) $burstLeft <= 0) {
            $cooldown *= (float) NpcTraits::value($trait, 'rest', 1.0);
        }

        $cooldown = max(self::MIN_COOLDOWN, self::jitter((int) round($cooldown), $roll));

        return self::wakeAt($uid, $now + $cooldown, $trait);
    }

    /**
     * Whether this wave is one of the neighbour's bad ideas.
     *
     * @param int|null $roll 1..100, injected by the tests.
     */
    public static function isChaotic($trait, $roll = null)
    {
        $chance = (int) NpcTraits::value($trait, 'chaos', 0);
        if ($chance <= 0) {
            return false;
        }
        $roll = $roll === null ? random_int(1, 100) : (int) $roll;

        return $roll <= $chance;
    }
}
