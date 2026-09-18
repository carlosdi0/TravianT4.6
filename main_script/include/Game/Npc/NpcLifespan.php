<?php

namespace Game\Npc;

/**
 * When an INACTIVE neighbour stops playing.
 *
 * Nobody quits on day zero. A world now starts the way a real one does - every
 * neighbour with one empty village, growing along its own curve - and the
 * inactive tier is no longer "an account that was born dead". It is an account
 * that plays like a casual for its first few days and then, on its own quit
 * day, freezes exactly where it stood. That is what a real server looks like
 * three weeks in: a scatter of abandoned three-village accounts, still holding
 * whatever they had built, worth raiding and worth conquering.
 *
 * Seeding an inactive as frozen-at-day-zero instead would leave 30 % of the
 * world as single villages of 2 population: not conquerable (the engine refuses
 * to take anyone's last village, and never a capital), not worth raiding, and
 * permanently in the way.
 *
 * `quit_at` is a unix timestamp on the registry row, 0 meaning "never quits",
 * which is every active tier. Everything else in the subsystem keeps reading
 * the tier it always read: effectiveTier() is what turns a not-yet-quit
 * inactive into the casual it is currently behaving as.
 *
 * Pure arithmetic, unit-tested without a database.
 */
class NpcLifespan
{
    /** Earliest and latest quit, in GAME days since the account was created. */
    const QUIT_MIN_DAYS = 3;
    const QUIT_MAX_DAYS = 21;

    /** The tier a not-yet-quit inactive behaves as while it is still playing. */
    const PLAYING_AS = NpcTiers::CASUAL;

    /**
     * The timestamp an account of this tier stops playing at.
     *
     * @param string   $tier    Only INACTIVE ever gets a date.
     * @param int      $created Account creation, unix time.
     * @param int      $speed   Server SPEED: game days pass that much faster.
     * @param int|null $days    Injectable roll, so the range can be tested.
     * @return int Unix timestamp, or 0 for an account that never quits.
     */
    public static function quitAt($tier, $created, $speed, $days = null)
    {
        if ((string) $tier !== NpcTiers::INACTIVE) {
            return 0;
        }

        $speed = max(1, (int) $speed);
        $days  = ($days === null)
               ? random_int(self::QUIT_MIN_DAYS, self::QUIT_MAX_DAYS)
               : max(0, (int) $days);

        // At least one second into the future: a quit_at equal to `created`
        // would read as "already quit" and reproduce the frozen-at-birth
        // account this whole class exists to avoid.
        return (int) $created + max(1, (int) round($days * 86400 / $speed));
    }

    /** Has this account stopped playing by $now? */
    public static function hasQuit($tier, $quitAt, $now)
    {
        if ((string) $tier !== NpcTiers::INACTIVE) {
            return false;
        }
        // A row written before quit_at existed has 0, which has to keep meaning
        // "inactive, full stop" or a migrated world would come back to life.
        return (int) $quitAt <= 0 || (int) $quitAt <= (int) $now;
    }

    /**
     * The tier an account is BEHAVING as right now.
     *
     * Everything that grows, expands or raids reads this instead of the raw
     * column; the column itself keeps saying `inactive` so the world's tier
     * split, the admin panel and the seeding quotas all stay honest.
     */
    public static function effectiveTier($tier, $quitAt, $now)
    {
        $tier = (string) $tier;
        if ($tier !== NpcTiers::INACTIVE) {
            return $tier;
        }

        return self::hasQuit($tier, $quitAt, $now) ? NpcTiers::INACTIVE : self::PLAYING_AS;
    }

    /** Is this account allowed to act at all right now? */
    public static function isPlaying($tier, $quitAt, $now)
    {
        return !self::hasQuit($tier, $quitAt, $now);
    }

    /**
     * How much of a seed age an account of this tier actually lived through,
     * and as what.
     *
     * Seeding into a world that has been running for months has to age each
     * account by that much - except an inactive one, which stopped playing on
     * its own quit day and has been frozen ever since. Handing the raw age to
     * the growth curve with the raw tier would produce the thing this whole
     * class exists to avoid: a cow that never built anything, in a world where
     * it supposedly lived for months.
     *
     * @param string $tier     Registry tier.
     * @param float  $seedDays World age the account is being seeded at.
     * @param int    $created  Back-dated creation stamp.
     * @param int    $quitAt   From quitAt(), 0 for an active tier.
     * @param int    $speed    Server SPEED.
     * @return array{days:float,tier:string} Age lived, and the tier that lived it.
     */
    public static function seedAge($tier, $seedDays, $created, $quitAt, $speed)
    {
        $seedDays = max(0.0, (float) $seedDays);
        if ((string) $tier !== NpcTiers::INACTIVE) {
            return ['days' => $seedDays, 'tier' => (string) $tier];
        }

        $quitDays = ((int) $quitAt > 0)
                  ? max(0.0, ((int) $quitAt - (int) $created) * max(1, (int) $speed) / 86400.0)
                  : 0.0;

        return ['days' => min($seedDays, $quitDays), 'tier' => self::PLAYING_AS];
    }
}
