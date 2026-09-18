<?php

namespace Game\Npc;

/**
 * Decides whether a neighbour raids the player, who it hits and how hard.
 *
 * The rules exist to keep NPC pressure a threat rather than a punishment:
 *
 *   - Beginners' protection is honoured HERE. The battle pipeline does not
 *     check the defender's `protect` when an attack lands, so nothing else in
 *     the engine would stop a wave from flattening a new player.
 *   - A neighbour never empties its village. It sends a share of the garrison,
 *     so the player can always survive a wave, and a counter-raid against the
 *     weakened attacker is a real option.
 *   - Raids, never full attacks: no rams, no catapults, no conquest. Losing to
 *     an NPC costs troops and resources, never buildings.
 *   - Alliances are honoured here too: a neighbour never raids its own tag or
 *     a confederated one (see NpcAlliances), which is what turns the blocs on
 *     the map into something the player can read and exploit.
 */
class NpcTargeting
{
    /** Below this the village stays home; a token raid only feeds the player. */
    const MIN_GARRISON = 25;

    /**
     * Whether a player's village may be raided at all.
     *
     * @param array $player   Row with access, protect, vac_mode and alliance.
     * @param int   $now      Current timestamp.
     * @param array $friendly Alliance ids the attacker will not touch: its own
     *                        and its confederates. Empty = raids anybody.
     */
    public static function isRaidable(array $player, $now, array $friendly = [])
    {
        $access = isset($player['access']) ? (int) $player['access'] : 0;
        if ($access < 1 || $access > 7) {
            return false; // banned, multihunter or admin accounts are left alone
        }
        if (isset($player['protect']) && (int) $player['protect'] > (int) $now) {
            return false; // beginners' protection
        }
        if (isset($player['vac_mode']) && (int) $player['vac_mode'] > 0) {
            return false; // holiday mode
        }
        if ($friendly && isset($player['alliance'])) {
            $alliance = (int) $player['alliance'];
            if ($alliance > 0 && in_array($alliance, $friendly, true)) {
                return false; // same tag, or a confederate of it
            }
        }
        return true;
    }

    /**
     * Whether a village belongs to someone who has stopped playing: a cow, or
     * a human who has not logged in for $hours. That is what a casual farms,
     * and what a top prospects first - population is on the map, and "last
     * online" is on the profile, so a person uses both.
     *
     * @param array $village Row with target_tier and timestamp (owner's).
     */
    public static function isInactive(array $village, $now, $hours)
    {
        if (isset($village['target_tier']) && $village['target_tier'] === NpcTiers::INACTIVE) {
            return true;
        }
        $seen = isset($village['timestamp']) ? (int) $village['timestamp'] : 0;

        return $seen > 0 && ((int) $now - $seen) >= max(1, (int) $hours) * 3600;
    }

    /** Whether this neighbour is off cooldown. */
    public static function isReady(array $npc, $now, $cooldown)
    {
        $last = isset($npc['last_attack']) ? (int) $npc['last_attack'] : 0;
        return ($now - $last) >= max(60, (int) $cooldown);
    }

    /**
     * Nearest raidable village of a player, inside the neighbour's reach.
     *
     * @param array $villages Rows with wref, x, y and the owner's account flags.
     * @return array|null
     */
    public static function pickTarget(array $villages, $fromX, $fromY, $maxDistance, $now, $ownerUid = 0, $preferOwner = 0, array $friendly = [])
    {
        $best         = null;
        $bestDistance = PHP_INT_MAX;
        $revenge      = null;
        $revengeDist  = PHP_INT_MAX;

        foreach ($villages as $village) {
            if (!self::isRaidable($village, $now, $friendly)) {
                continue;
            }
            if ($ownerUid > 0 && (int) $village['owner'] === (int) $ownerUid) {
                continue; // never raid your own villages
            }
            $distance = NpcSeedPlan::distance($fromX, $fromY, $village['x'], $village['y']);
            if ($distance > (int) $maxDistance || $distance === 0) {
                continue;
            }

            // Whoever hit us last gets hit back, as long as they are in reach:
            // otherwise a neighbour "avenges" itself on whoever happens to be
            // closest, which reads as random aggression.
            if ($preferOwner > 0 && (int) $village['owner'] === (int) $preferOwner && $distance < $revengeDist) {
                $revenge     = $village;
                $revengeDist = $distance;
            }

            if ($distance < $bestDistance) {
                $best         = $village;
                $bestDistance = $distance;
            }
        }

        return $revenge !== null ? $revenge : $best;
    }

    /**
     * Every village this neighbour could legally hit, nearest first.
     *
     * Where pickTarget() answers "the closest one", this hands back the whole
     * shortlist, which is what a raider working a farm list and an
     * opportunist waiting for an opening both need.
     *
     * @param array $exclude     wrefs to skip (already on the farm list).
     * @param bool  $onlyExposed Keep only villages whose troops are out, the
     *                           opportunist's whole reason to exist.
     * @param array $friendly    Alliance ids the attacker will not touch.
     */
    public static function candidates(array $villages, $fromX, $fromY, $maxDistance, $now, $ownerUid = 0, array $exclude = [], $onlyExposed = false, array $friendly = [])
    {
        $found = [];
        $skip  = array_flip(array_map('intval', $exclude));

        foreach ($villages as $village) {
            if (!self::isRaidable($village, $now, $friendly)) {
                continue;
            }
            if ($ownerUid > 0 && (int) $village['owner'] === (int) $ownerUid) {
                continue;
            }
            if (isset($skip[(int) $village['wref']])) {
                continue;
            }
            if ($onlyExposed && empty($village['away'])) {
                continue;
            }
            $distance = NpcSeedPlan::distance($fromX, $fromY, $village['x'], $village['y']);
            if ($distance > (int) $maxDistance || $distance === 0) {
                continue;
            }
            $village['distance'] = $distance;
            $found[] = $village;
        }

        usort($found, function ($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });

        return $found;
    }

    /**
     * The smallest villages among the nearest few.
     *
     * Population is on the map for everyone to see, so choosing by it is fair
     * game and it is what a raider actually does: small village, few troops,
     * cheap trip. Without it a raider prospects into the first walled
     * neighbour in range and loses the wave.
     *
     * @param array $candidates Already sorted by distance.
     * @param int   $near       How many of the nearest to consider.
     */
    public static function softest(array $candidates, $near = 12)
    {
        $pool = array_slice($candidates, 0, max(1, (int) $near));

        usort($pool, function ($a, $b) {
            $popA = isset($a['pop']) ? (int) $a['pop'] : 0;
            $popB = isset($b['pop']) ? (int) $b['pop'] : 0;

            return $popA === $popB ? ($a['distance'] <=> $b['distance']) : ($popA <=> $popB);
        });

        return $pool;
    }

    /**
     * One of the nearest few, not always the nearest.
     *
     * Prospecting for new farms is the one place randomness belongs: a
     * neighbour that always picks the single closest village works the same
     * two targets for ever and the rest of the map never sees it.
     *
     * @param int|null $roll Injected by the tests.
     */
    public static function pickNearRandom(array $candidates, $sample = 5, $roll = null)
    {
        if (!$candidates) {
            return null;
        }
        $pool = array_slice($candidates, 0, max(1, (int) $sample));
        $roll = $roll === null ? random_int(0, count($pool) - 1) : (int) $roll;

        return $pool[abs($roll) % count($pool)];
    }

    /**
     * Troops to send: a share of the garrison, per slot, so the village keeps
     * a defence and the wave keeps the army's composition.
     *
     * @param array $garrison Relative slot => amount present.
     * @param int   $pct      Share to send, 1..100.
     * @return array Relative slot => amount to send (empty = stay home).
     */
    public static function wave(array $garrison, $pct)
    {
        $pct   = max(1, min(100, (int) $pct));
        $total = array_sum($garrison);

        if ($total < self::MIN_GARRISON) {
            return [];
        }

        $wave = [];
        foreach ($garrison as $slot => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }
            $send = (int) floor($amount * $pct / 100);
            if ($send > 0) {
                $wave[$slot] = $send;
            }
        }

        // All slots rounded down to nothing: send nobody rather than a single
        // unit that just dies and teaches the player nothing.
        return array_sum($wave) > 0 ? $wave : [];
    }

    /**
     * Raid share for a neighbour. A freshly raided one hits back harder, which
     * is the only "memory" the NPCs have and what makes farming them a choice
     * rather than free food.
     *
     * @param int $minPct   Floor from config.
     * @param int $maxPct   Ceiling from config.
     * @param int $power    Account power, 0..100.
     * @param bool $avenging True when the player raided this neighbour recently.
     */
    public static function wavePercent($minPct, $maxPct, $power, $avenging)
    {
        $minPct = max(1, min(100, (int) $minPct));
        $maxPct = max($minPct, min(100, (int) $maxPct));
        $power  = max(0, min(100, (int) $power));

        $span = $maxPct - $minPct;
        $pct  = $minPct + (int) round($span * $power / 100);

        if ($avenging) {
            $pct = (int) round($pct * 1.25);
        }

        return max($minPct, min($maxPct, $pct));
    }
}
