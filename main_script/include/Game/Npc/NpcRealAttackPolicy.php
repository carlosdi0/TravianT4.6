<?php

namespace Game\Npc;

/**
 * The one NPC action that can cost the player buildings: a real attack
 * (attack_type 3) with rams and catapults. A person does this to someone who
 * keeps hitting them, after scouting, when clearly stronger. Everything here
 * exists to make sure an NPC never does it in any other circumstance, and
 * never in a way the engine could turn into a razed village.
 *
 * The engine facts these guards rest on:
 *   - catapults pick their target by building GID (ctar1); if the target has
 *     no such building the engine falls back to RANDOM, which can hit the
 *     residence or the Wonder. So the gid must be checked against the
 *     target's fdata first, and without it no catapults go.
 *   - a non-capital village whose population reaches 0 is razed when its
 *     owner has more than one. Twenty catapults cannot do that to a village
 *     above NPC_RAZE_FLOOR; more are never sent.
 *   - players are limited by rally point level (RP 3-4: warehouse/granary
 *     only). NPCs bypass that path, so they impose the strictest version on
 *     themselves.
 *
 * Pure data, unit-tested without a database.
 */
class NpcRealAttackPolicy
{
    /** Building gids catapults may aim at: warehouse and granary. */
    const TARGET_GIDS = [10, 11];

    /** Most catapults / rams in one wave. */
    const MAX_CATAPULTS = 20;
    const MAX_RAMS      = 20;

    /** Hits received from the target's owner inside the grudge window. */
    const MIN_GRUDGE = 3;

    /** Scouting older than this is stale. */
    const SCOUT_MAX_AGE = 86400;

    /** One real attack per NPC, and per target village, in this window. */
    const RATE_WINDOW = 86400;

    /** Attack / defence ratio a real attack needs. */
    const MIN_RATIO = NpcDefenceEstimate::REAL_ATTACK_MARGIN;

    /**
     * Whether this neighbour may bring siege against this village right now.
     *
     * @param bool  $enabled   NPC_REAL_ATTACKS.
     * @param array $npc       tier, grudge_hits, grudge_since, last_raider, last_real_attack
     * @param array $target    wref, owner, capital, pop, f99 (wonder level), last_real_hit
     * @param array $fdata     The target's fdata row.
     * @param int   $scoutedAt Arrival time of the last scouting party (0 = never).
     * @param float $ratio     Attack / defence estimate for the offensive army.
     * @param int   $now
     * @return string Empty when allowed, else the reason it is not.
     */
    public static function refusal($enabled, array $npc, array $target, array $fdata, $scoutedAt, $ratio, $now)
    {
        if (!$enabled) {
            return 'disabled';
        }
        if (!isset($npc['tier']) || $npc['tier'] !== NpcTiers::TOP) {
            return 'tier';
        }
        $grudge = isset($npc['grudge_hits']) ? (int) $npc['grudge_hits'] : 0;
        $since  = isset($npc['grudge_since']) ? (int) $npc['grudge_since'] : 0;
        if ($grudge < self::MIN_GRUDGE || $since <= 0 || ($now - $since) > self::GRUDGE_WINDOW()) {
            return 'grudge';
        }
        if (!isset($npc['last_raider']) || (int) $npc['last_raider'] !== (int) $target['owner']) {
            return 'not-the-culprit';
        }
        if ((int) $target['owner'] <= 5) {
            return 'system-account';
        }
        if (!empty($target['capital'])) {
            return 'capital';
        }
        if (isset($target['f99']) && (int) $target['f99'] > 0) {
            return 'wonder';
        }
        if ((int) $target['pop'] < self::razeFloor()) {
            return 'too-small';
        }
        if ($scoutedAt <= 0 || $scoutedAt > $now || ($now - $scoutedAt) > self::SCOUT_MAX_AGE) {
            return 'unscouted';
        }
        if ((float) $ratio < self::MIN_RATIO) {
            return 'ratio';
        }
        $last = isset($npc['last_real_attack']) ? (int) $npc['last_real_attack'] : 0;
        if ($last > 0 && ($now - $last) < self::RATE_WINDOW) {
            return 'rate-npc';
        }
        $hit = isset($target['last_real_hit']) ? (int) $target['last_real_hit'] : 0;
        if ($hit > 0 && ($now - $hit) < self::RATE_WINDOW) {
            return 'rate-target';
        }
        if (self::catapultTarget($fdata) === 0) {
            return 'no-target-building';
        }
        return '';
    }

    public static function allows($enabled, array $npc, array $target, array $fdata, $scoutedAt, $ratio, $now)
    {
        return self::refusal($enabled, $npc, $target, $fdata, $scoutedAt, $ratio, $now) === '';
    }

    /**
     * The gid to aim at: warehouse if the target has one standing, else the
     * granary, else 0 (no catapults at all, never random).
     */
    public static function catapultTarget(array $fdata)
    {
        foreach (self::TARGET_GIDS as $gid) {
            for ($slot = 19; $slot <= 39; $slot++) {
                $there = isset($fdata['f' . $slot . 't']) ? (int) $fdata['f' . $slot . 't'] : 0;
                $level = isset($fdata['f' . $slot]) ? (int) $fdata['f' . $slot] : 0;
                if ($there === $gid && $level > 0) {
                    return $gid;
                }
            }
        }
        return 0;
    }

    /**
     * Siege to add to a wave: capped rams and catapults out of the garrison.
     *
     * @return array Relative slot => units (7 and/or 8), possibly empty.
     */
    public static function siege(array $garrison)
    {
        $out  = [];
        $rams = isset($garrison[NpcArmyRoles::ramSlot()]) ? (int) $garrison[NpcArmyRoles::ramSlot()] : 0;
        $cats = isset($garrison[NpcArmyRoles::catapultSlot()]) ? (int) $garrison[NpcArmyRoles::catapultSlot()] : 0;
        if ($rams > 0) {
            $out[NpcArmyRoles::ramSlot()] = min($rams, self::MAX_RAMS);
        }
        if ($cats > 0) {
            $out[NpcArmyRoles::catapultSlot()] = min($cats, self::MAX_CATAPULTS);
        }
        return $out;
    }

    /** Population below which a village is never brought catapults. */
    public static function razeFloor()
    {
        return defined('NPC_RAZE_FLOOR') ? max(50, (int) NPC_RAZE_FLOOR) : 200;
    }

    /** The grudge window, shared with NpcWorld::noteRaid(). */
    public static function GRUDGE_WINDOW()
    {
        return 86400;
    }
}
