<?php

namespace Game\Npc;

/**
 * The tier of a neighbour: how seriously the "person" behind it plays.
 *
 * A real server is not a hundred equally busy accounts. A few play hard and
 * raid everything, a larger group builds and defends, most log in now and then
 * and only ever farm the obviously dead, and a big chunk stops playing in the
 * first week and sits there as free food. That mix is what this class encodes:
 *
 *   TOP       10 %  raids and grows like a top account
 *   BUILDER   20 %  grows as fast as TOP, trains defence, rarely leaves home
 *   CASUAL    40 %  grows at half pace, only ever hits inactives
 *   INACTIVE  30 %  never grows, never trains, never attacks: a cow
 *
 * The tier is orthogonal to the archetype: the archetype is the village
 * blueprint (what stands there), the tier is the behaviour (what happens to it
 * over time). Everything here is pure data so it can be unit-tested without a
 * database.
 */
class NpcTiers
{
    const TOP      = 'top';
    const BUILDER  = 'builder';
    const CASUAL   = 'casual';
    const INACTIVE = 'inactive';

    /** Default share of each tier, in percent. Overridable via NPC_TIER_*_PCT. */
    const DEFAULT_SPLIT = [
        self::TOP      => 10,
        self::BUILDER  => 20,
        self::CASUAL   => 40,
        self::INACTIVE => 30,
    ];

    /** Fixed order used everywhere a deterministic walk over the tiers is needed. */
    private static $order = [self::TOP, self::BUILDER, self::CASUAL, self::INACTIVE];

    /**
     * Which archetype a freshly seeded account of each tier gets. CASUAL cycles
     * so the middle of the world is not one blueprint repeated forty times.
     */
    private static $archetypes = [
        self::TOP      => [NpcArchetypes::WARLORD],
        self::BUILDER  => [NpcArchetypes::GARRISON],
        self::CASUAL   => [NpcArchetypes::GARRISON, NpcArchetypes::WARLORD, NpcArchetypes::FARM],
        self::INACTIVE => [NpcArchetypes::FARM],
    ];

    /**
     * Which existing archetype each tier prefers to be built from when a world
     * seeded before tiers existed is migrated. Anything not claimed here ends up
     * CASUAL, which is the tier that can carry any blueprint.
     */
    private static $migrationSource = [
        self::TOP      => NpcArchetypes::WARLORD,
        self::BUILDER  => NpcArchetypes::GARRISON,
        self::INACTIVE => NpcArchetypes::FARM,
    ];

    /** @return string[] Every tier, in the canonical order. */
    public static function keys()
    {
        return self::$order;
    }

    public static function exists($tier)
    {
        return is_string($tier) && in_array($tier, self::$order, true);
    }

    /**
     * Whether the tier takes part in the growth, expansion and raid passes at
     * all. INACTIVE is skipped by every one of them; that is the whole point of
     * a cow, and also what makes it nearly free for the cron.
     */
    public static function isActive($tier)
    {
        return self::exists($tier) && $tier !== self::INACTIVE;
    }

    /**
     * Whether acting should refresh users.timestamp. A cow never "logs in", so
     * it shows up as inactive on the heatmap and the alliance page, exactly the
     * signal a real player uses to pick farms.
     */
    public static function refreshesTimestamp($tier)
    {
        return self::isActive($tier);
    }

    /**
     * A usable split: every tier present, negatives dropped, normalised to sum
     * 100. Garbage in (all zeros, missing keys) falls back to the default so
     * a typo in config.php cannot produce a world of nothing.
     *
     * @param array|null $pct tier => percent, as read from config.
     * @return array<string,float> tier => share in percent, summing to 100.
     */
    public static function split(array $pct = null)
    {
        $clean = [];
        $total = 0.0;
        foreach (self::$order as $tier) {
            $value = ($pct !== null && isset($pct[$tier])) ? max(0.0, (float) $pct[$tier]) : 0.0;
            $clean[$tier] = $value;
            $total += $value;
        }
        if ($total <= 0) {
            $clean = self::DEFAULT_SPLIT;
            $total = (float) array_sum($clean);
        }
        foreach ($clean as $tier => $value) {
            $clean[$tier] = $value * 100.0 / $total;
        }
        return $clean;
    }

    /**
     * How many accounts of each tier a world of $total should hold. Largest
     * remainder rounding, so the counts always add up to $total exactly and a
     * world of ten really does get one TOP rather than zero.
     *
     * @return array<string,int> tier => count.
     */
    public static function quotas($total, array $split = null)
    {
        $total = max(0, (int) $total);
        $split = self::split($split);

        $counts    = [];
        $remainder = [];
        $assigned  = 0;
        foreach (self::$order as $tier) {
            $exact = $total * $split[$tier] / 100.0;
            $counts[$tier]    = (int) floor($exact);
            $remainder[$tier] = $exact - $counts[$tier];
            $assigned += $counts[$tier];
        }
        // Hand the leftovers to the tiers that were rounded down the most;
        // ties go in canonical order, so TOP wins a coin toss over INACTIVE.
        $left = $total - $assigned;
        while ($left > 0) {
            $best = null;
            foreach (self::$order as $tier) {
                if ($best === null || $remainder[$tier] > $remainder[$best]) {
                    $best = $tier;
                }
            }
            $counts[$best]++;
            $remainder[$best] = -1.0;
            $left--;
        }
        return $counts;
    }

    /**
     * Tiers for a batch of new accounts, given how the world already looks.
     *
     * The split is applied to the world AFTER seeding, not to the batch on its
     * own: a second seed run tops up whatever the current population is short
     * of, so seeding 50 into a world of 50 lands the full hundred on the split
     * even if the first fifty were migrated rather than planned.
     *
     * @param array $existing tier => count already in the world.
     * @param int   $newCount Accounts about to be created.
     * @return string[] One tier per new account, interleaved, length $newCount.
     */
    public static function planSeed(array $existing, $newCount, array $split = null)
    {
        $newCount = max(0, (int) $newCount);
        if ($newCount === 0) {
            return [];
        }

        $have = [];
        foreach (self::$order as $tier) {
            $have[$tier] = isset($existing[$tier]) ? max(0, (int) $existing[$tier]) : 0;
        }
        $target = self::quotas(array_sum($have) + $newCount, $split);

        $need = [];
        foreach (self::$order as $tier) {
            $need[$tier] = max(0, $target[$tier] - $have[$tier]);
        }

        // The world may already be over quota somewhere (an admin seeded 40
        // cows by hand): the shortfalls then add up to more than the batch, or
        // to less. Trim the biggest first, or pad by the split's own weights.
        $sum = array_sum($need);
        while ($sum > $newCount) {
            $biggest = array_search(max($need), $need, true);
            $need[$biggest]--;
            $sum--;
        }
        if ($sum < $newCount) {
            $pad = self::quotas($newCount - $sum, $split);
            foreach ($pad as $tier => $count) {
                $need[$tier] += $count;
            }
        }

        return self::interleave($need);
    }

    /**
     * Spread tier counts evenly over a sequence, so consecutive accounts do
     * not come out as ten cows in a row followed by all the raiders.
     *
     * @param array<string,int> $counts tier => how many.
     * @return string[]
     */
    public static function interleave(array $counts)
    {
        $total = 0;
        foreach (self::$order as $tier) {
            $counts[$tier] = isset($counts[$tier]) ? max(0, (int) $counts[$tier]) : 0;
            $total += $counts[$tier];
        }

        $sequence = [];
        $placed   = array_fill_keys(self::$order, 0);
        for ($i = 1; $i <= $total; $i++) {
            // Whoever is furthest behind its fair share at this point goes next.
            $best    = null;
            $deficit = null;
            foreach (self::$order as $tier) {
                if ($placed[$tier] >= $counts[$tier]) {
                    continue;
                }
                $gap = ($counts[$tier] * $i / $total) - $placed[$tier];
                if ($deficit === null || $gap > $deficit) {
                    $best    = $tier;
                    $deficit = $gap;
                }
            }
            $sequence[] = $best;
            $placed[$best]++;
        }
        return $sequence;
    }

    /**
     * Blueprint for a new account of this tier. Derived from the seed, not
     * drawn at random, for the same reason the traits are; hashed rather than
     * cycled because the tier sequence itself repeats every few accounts and a
     * plain modulo lined up with it, handing every CASUAL the same blueprint.
     */
    public static function archetypeFor($tier, $seed)
    {
        $pool = isset(self::$archetypes[$tier]) ? self::$archetypes[$tier] : self::$archetypes[self::CASUAL];

        return $pool[crc32('npc-tier-' . (int) $seed) % count($pool)];
    }

    /**
     * Give tiers to accounts that were seeded before tiers existed.
     *
     * Quotas come from the split; each tier is filled from the archetype that
     * already behaves most like it (warlords make the best raiders, garrisons
     * the best builders, the weakest farms the most convincing cows), and
     * whatever is left becomes CASUAL. Deterministic for a given input, so two
     * concurrent runs would compute the same answer even before the single
     * UPDATE that writes it makes the race harmless.
     *
     * @param array $rows  Each with uid, archetype, power.
     * @return array<int,string> uid => tier, in the order given.
     */
    public static function assign(array $rows, array $split = null)
    {
        $pending = [];
        foreach ($rows as $row) {
            if (!isset($row['uid'])) {
                continue;
            }
            $pending[(int) $row['uid']] = [
                'archetype' => isset($row['archetype']) ? (string) $row['archetype'] : '',
                'power'     => isset($row['power']) ? (int) $row['power'] : 50,
            ];
        }
        $quota  = self::quotas(count($pending), $split);
        $result = [];

        // First pass: each tier takes its preferred archetype, strongest first
        // for the ones that fight, weakest first for the ones that quit.
        foreach (self::$migrationSource as $tier => $archetype) {
            $candidates = [];
            foreach ($pending as $uid => $row) {
                if ($row['archetype'] === $archetype) {
                    $candidates[$uid] = $row['power'];
                }
            }
            $weakestFirst = ($tier === self::INACTIVE);
            uksort($candidates, function ($a, $b) use ($candidates, $weakestFirst) {
                if ($candidates[$a] === $candidates[$b]) {
                    return $a <=> $b;
                }
                return $weakestFirst
                    ? $candidates[$a] <=> $candidates[$b]
                    : $candidates[$b] <=> $candidates[$a];
            });
            foreach (array_keys($candidates) as $uid) {
                if ($quota[$tier] <= 0) {
                    break;
                }
                $result[$uid] = $tier;
                $quota[$tier]--;
                unset($pending[$uid]);
            }
        }

        // Second pass: whatever is left fills the remaining quotas, TOP and
        // BUILDER from the strongest, INACTIVE from the weakest, CASUAL the rest.
        $byPower = array_keys($pending);
        usort($byPower, function ($a, $b) use ($pending) {
            if ($pending[$a]['power'] === $pending[$b]['power']) {
                return $a <=> $b;
            }
            return $pending[$b]['power'] <=> $pending[$a]['power'];
        });
        foreach ([self::TOP, self::BUILDER] as $tier) {
            while ($quota[$tier] > 0 && $byPower) {
                $uid = array_shift($byPower);
                $result[$uid] = $tier;
                $quota[$tier]--;
            }
        }
        while ($quota[self::INACTIVE] > 0 && $byPower) {
            $uid = array_pop($byPower);
            $result[$uid] = self::INACTIVE;
            $quota[self::INACTIVE]--;
        }
        foreach ($byPower as $uid) {
            $result[$uid] = self::CASUAL;
        }

        // Back to the caller's order, so the CASE statement built from this is
        // stable across runs.
        $ordered = [];
        foreach ($rows as $row) {
            if (isset($row['uid']) && isset($result[(int) $row['uid']])) {
                $ordered[(int) $row['uid']] = $result[(int) $row['uid']];
            }
        }
        return $ordered;
    }
}
