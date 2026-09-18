<?php

namespace Game\Npc;

/**
 * A trait is HOW a neighbour plays; the archetype is only what it built.
 *
 * Two warlords with the same buildings and the same army behave nothing alike
 * once one of them is a raider and the other is erratic, and that difference
 * is the whole point: an NPC world that fires one identical wave every
 * NPC_ATTACK_COOLDOWN seconds reads as a cron job, because it is one.
 *
 *   RAIDER       The farm king. Short cooldown, small waves, many of them, and
 *                the only trait that keeps a farm list (see NpcFarmList): it
 *                re-hits what pays and drops what costs it troops, exactly
 *                like a human working a raid list.
 *   VENGEFUL     Long memory. Hits back harder and keeps chasing whoever
 *                raided it well past the day everyone else forgets.
 *   OPPORTUNIST  Only moves against something already weakened, so it shows up
 *                right after the player loses an army somewhere else.
 *   ERRATIC      Mostly normal, occasionally insane: empties the village at a
 *                target on the far edge of its reach, or vanishes for days.
 *   TURTLE       Sits on its resources. Rarely leaves home, which makes it the
 *                fat, quiet target worth scouting.
 *   STEADY       The baseline, so the neighbourhood is not all characters.
 *
 * Pure data, no database, so the whole personality table is unit-testable.
 */
class NpcTraits
{
    const STEADY      = 'steady';
    const RAIDER      = 'raider';
    const VENGEFUL    = 'vengeful';
    const OPPORTUNIST = 'opportunist';
    const ERRATIC     = 'erratic';
    const TURTLE      = 'turtle';

    /**
     * Per-trait modifiers.
     *
     * 'cooldown' multiplies NPC_ATTACK_COOLDOWN between waves
     * 'rest'     multiplies the cooldown after a whole burst is spent
     * 'burst'    waves allowed back to back before that long rest
     * 'wave'     multiplies the share of the garrison sent
     * 'reach'    multiplies NPC_MAX_ATTACK_DISTANCE
     * 'sleep'    hours a day the neighbour is offline
     * 'chaos'    percent chance per wave of doing something stupid
     * 'grudge'   multiplies the 24h revenge window
     * 'farms'    keeps a farm list instead of hitting whatever is nearest
     * 'prey'     only attacks targets that recently lost troops
     */
    private static function table()
    {
        return [
            self::STEADY => [
                'cooldown' => 1.00, 'rest' => 1.00, 'burst' => 1, 'wave' => 1.00,
                'reach' => 1.00, 'sleep' => 7, 'chaos' => 0, 'grudge' => 1.0,
                'farms' => false, 'prey' => false,
            ],
            self::RAIDER => [
                // 'wave' stays below 1.0 - many small trips, not one big one -
                // but not so low that a probing wave dies to any garrison it
                // meets and teaches the raider nothing but its own funeral.
                'cooldown' => 0.30, 'rest' => 2.50, 'burst' => 4, 'wave' => 0.75,
                'reach' => 1.30, 'sleep' => 6, 'chaos' => 0, 'grudge' => 0.5,
                'farms' => true, 'prey' => false,
            ],
            self::VENGEFUL => [
                'cooldown' => 0.80, 'rest' => 1.20, 'burst' => 2, 'wave' => 1.30,
                'reach' => 1.10, 'sleep' => 7, 'chaos' => 0, 'grudge' => 4.0,
                'farms' => false, 'prey' => false,
            ],
            self::OPPORTUNIST => [
                'cooldown' => 1.20, 'rest' => 1.00, 'burst' => 1, 'wave' => 1.25,
                'reach' => 1.00, 'sleep' => 8, 'chaos' => 0, 'grudge' => 1.5,
                'farms' => false, 'prey' => true,
            ],
            self::ERRATIC => [
                'cooldown' => 0.90, 'rest' => 3.00, 'burst' => 3, 'wave' => 1.00,
                'reach' => 1.00, 'sleep' => 5, 'chaos' => 10, 'grudge' => 1.0,
                'farms' => false, 'prey' => false,
            ],
            self::TURTLE => [
                'cooldown' => 3.00, 'rest' => 1.50, 'burst' => 1, 'wave' => 0.70,
                'reach' => 0.60, 'sleep' => 9, 'chaos' => 0, 'grudge' => 1.0,
                'farms' => false, 'prey' => false,
            ],
        ];
    }

    /** @return string[] */
    public static function keys()
    {
        return array_keys(self::table());
    }

    public static function exists($trait)
    {
        $table = self::table();
        return is_string($trait) && isset($table[$trait]);
    }

    /** @return array Empty for an unknown trait. */
    public static function definition($trait)
    {
        $table = self::table();
        return isset($table[$trait]) ? $table[$trait] : [];
    }

    /**
     * One modifier, falling back to STEADY for anything unknown: an account
     * seeded before traits existed must still behave like a neighbour.
     */
    public static function value($trait, $field, $default = null)
    {
        $definition = self::definition($trait);
        if (isset($definition[$field])) {
            return $definition[$field];
        }
        $steady = self::definition(self::STEADY);
        if (isset($steady[$field])) {
            return $steady[$field];
        }
        return $default;
    }

    public static function keepsFarmList($trait)
    {
        return (bool) self::value($trait, 'farms', false);
    }

    public static function huntsWeakened($trait)
    {
        return (bool) self::value($trait, 'prey', false);
    }

    /**
     * Trait for a newly seeded account.
     *
     * Cycled by seed rather than drawn at random, for the same reason the
     * archetypes are: two dozen random draws regularly produce a
     * neighbourhood where nobody raids, or where everyone does.
     *
     * The pools are per archetype because the traits have to match what the
     * village actually is - a FARM has no offensive troops and is excluded
     * from the raid pass, so making it a raider would be a lie the player
     * never gets to see.
     */
    public static function forTier($tier, $seed)
    {
        $seed = (int) $seed;

        switch ($tier) {
            case NpcTiers::TOP:
                // The farm kings: mostly raiders, a grudge-holder, one loose cannon.
                $pool = [self::RAIDER, self::RAIDER, self::VENGEFUL, self::ERRATIC];
                break;
            case NpcTiers::BUILDER:
                // Sits at home, answers when hit, pounces on an open door.
                $pool = [self::STEADY, self::TURTLE, self::VENGEFUL, self::OPPORTUNIST];
                break;
            case NpcTiers::CASUAL:
                // Plays now and then; one in four keeps a little farm list.
                $pool = [self::STEADY, self::TURTLE, self::OPPORTUNIST, self::RAIDER];
                break;
            default:
                // Cows never leave home; the trait only colours their upkeep.
                $pool = [self::TURTLE];
                break;
        }

        return $pool[abs($seed) % count($pool)];
    }

    /** Legacy pick by archetype, for rows written before tiers existed. */
    public static function forArchetype($archetype, $seed)
    {
        $seed = (int) $seed;

        switch ($archetype) {
            case NpcArchetypes::WARLORD:
                // The aggressive pool, weighted towards raiders: the farm king
                // is the character this world is missing, not another turtle.
                $pool = [self::RAIDER, self::ERRATIC, self::RAIDER, self::VENGEFUL];
                break;
            case NpcArchetypes::GARRISON:
                $pool = [self::OPPORTUNIST, self::VENGEFUL, self::STEADY, self::TURTLE];
                break;
            default:
                // Farms never leave home; the trait only colours their upkeep.
                $pool = [self::TURTLE, self::STEADY];
                break;
        }

        return $pool[abs($seed) % count($pool)];
    }
}
