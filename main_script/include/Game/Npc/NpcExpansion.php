<?php

namespace Game\Npc;

/**
 * Whether a neighbour has earned another village, and how many it may ever own.
 *
 * ACCOUNT POPULATION IS THE NPC'S CULTURE POINTS.
 *
 * NPCs do accumulate real `users.cp`, but at a rate that would take months per
 * village (cp0[4] is 6700), so the real gate is useless here. Population is the
 * honest substitute: it is already an accumulated stock, it already grows on
 * its own in grow() along the account's curve, and it is already LOST when the
 * player conquers one of the account's villages.
 *
 * The cost of each village rises with the count, roughly linearly in what one
 * village is worth: a TOP founds its second at a few hundred population, its
 * fifth near two thousand, its ninth near four and its fifteenth near eight. A
 * village saturates around 700 (NpcBuildOrder::villageCap), so an account has to
 * be about three quarters built before it spreads, the way a person's culture
 * points come in.
 *
 * The tier, not the archetype, decides appetite and ceiling: a cow never
 * founds anything, a casual stops at a handful, a top goes to the hard cap.
 *
 * Pure data and arithmetic, so it is unit-tested without a database.
 */
class NpcExpansion
{
    /**
     * No ACTIVE account's ceiling ever falls below this.
     *
     * One, because a world now starts the way a real one does: every neighbour
     * is seeded with a single village and founds the rest along its own curve.
     * The floor only exists so an account seeded with more villages than its
     * tier's cap allows is not born over its own ceiling - a state the admin
     * panel would render as "5 / 3" and nothing in the engine would resolve.
     */
    const FLOOR = 1;

    /** Population per village step, before the appetite divisor. */
    const POP_PER_STEP = 450;

    /** Each further village costs this much more than the last, per step. */
    const STEP_GROWTH = 0.05;

    /** Nobody founds a fourth village while owning three hamlets. */
    const MIN_POP_PER_VILLAGE = 300;

    /** Garrison a founded village starts with, as a % of its per-village cap. */
    const START_ARMY_PCT = 25;

    /**
     * Tier appetite and ceiling share a table so the two always move
     * together: something that barely wants to expand should not be handed a
     * high ceiling it will never reach, and vice versa. 'cap' is the most
     * villages the tier may own, before NPC_EXPAND_MAX_VILLAGES clamps it.
     *
     * These caps are what decide how big an ACCOUNT can ever get, because a
     * village saturates at a fixed population: the ceiling is the capital
     * (NpcBuildOrder::villageCap with the capital field levels) plus one
     * satellite cap per other village, and nothing else. On the warlord
     * blueprint that is 1134 + 684 each, so a top tops out near 10700.
     *
     * Raising the per-village figure instead would be the wrong knob: the
     * blueprint already takes nearly everything the engine defines to level 20,
     * and a satellite's fields are capped at 10 by the game's own rules.
     */
    private static $tiers = [
        NpcTiers::TOP      => ['appetite' => 1.3, 'cap' => 15],
        NpcTiers::BUILDER  => ['appetite' => 1.0, 'cap' => 11],
        NpcTiers::CASUAL   => ['appetite' => 0.8, 'cap' => 6],
        NpcTiers::INACTIVE => ['appetite' => 0.0, 'cap' => 0],
    ];

    /**
     * Trait appetite.
     *
     * Kept here rather than as a new key in NpcTraits::table(): that table
     * describes how a neighbour FIGHTS, and mixing in how it GROWS would force
     * every trait test to know about expansion. A trait missing from this list
     * simply expands at the ordinary rate.
     */
    private static $traits = [
        NpcTraits::TURTLE      => 0.6,
        NpcTraits::VENGEFUL    => 0.9,
        NpcTraits::STEADY      => 1.0,
        NpcTraits::OPPORTUNIST => 1.0,
        NpcTraits::ERRATIC     => 1.1,
        NpcTraits::RAIDER      => 1.3,
    ];

    /** Combined tier x trait appetite, clamped to a sane band; 0 for a cow. */
    public static function appetite($tier, $trait)
    {
        $tier  = (string) $tier;
        $trait = (string) $trait;

        if ($tier === NpcTiers::INACTIVE) {
            return 0.0;
        }
        // Unknown values come from rows written before a rename; the safe
        // reading is the least eager active one, never a fatal.
        $a = isset(self::$tiers[$tier]) ? self::$tiers[$tier]['appetite'] : self::$tiers[NpcTiers::CASUAL]['appetite'];
        $t = isset(self::$traits[$trait]) ? self::$traits[$trait] : 1.0;

        return max(0.2, min(2.0, $a * $t));
    }

    /** Most villages a tier may own, before the configured hard cap. */
    public static function tierCap($tier)
    {
        $tier = (string) $tier;
        return isset(self::$tiers[$tier]) ? self::$tiers[$tier]['cap'] : self::$tiers[NpcTiers::CASUAL]['cap'];
    }

    /**
     * How many villages this account may ever own.
     *
     * A cow's ceiling is 0: it never founds, whatever it was seeded with. For
     * the rest, the tier's own cap clamped by NPC_EXPAND_MAX_VILLAGES and never
     * below the seeded floor.
     *
     * $lead is the optional leash back to the player: at 0 (the default) the
     * player's village count is ignored; above 0 the ceiling is also capped at
     * the player's villages plus $lead.
     *
     * @param int    $hardCap        NPC_EXPAND_MAX_VILLAGES.
     * @param string $tier           NpcTiers constant.
     * @param int    $floor          Villages the account was seeded with.
     * @param int    $lead           0 = ignore the player entirely.
     * @param int    $playerVillages Only read when $lead > 0.
     */
    public static function ceiling($hardCap, $tier, $floor = self::FLOOR, $lead = 0, $playerVillages = 0)
    {
        if ((string) $tier === NpcTiers::INACTIVE) {
            return 0;
        }
        $hardCap = max(1, (int) $hardCap);
        $floor   = max(1, (int) $floor);
        $lead    = max(0, (int) $lead);

        $base = min($hardCap, self::tierCap($tier));
        if ($lead > 0) {
            $base = min($base, max($floor, (int) $playerVillages + $lead));
        }

        return max($floor, $base);
    }

    /**
     * Accumulated population needed before village number $targetVillages.
     *
     * Near-linear in the village count with a gentle ramp, then divided by
     * appetite, so a casual-turtle needs roughly three times what a top-raider
     * needs for the same step. A cow can never afford anything.
     */
    public static function requiredPop($targetVillages, $tier, $trait)
    {
        $appetite = self::appetite($tier, $trait);
        if ($appetite <= 0) {
            return PHP_INT_MAX;
        }
        $step = max(2, (int) $targetVillages);
        $base = self::POP_PER_STEP * ($step - 1) * (1 + self::STEP_GROWTH * ($step - 2));

        return (int) ceil($base / max(0.25, $appetite));
    }

    /**
     * Chance this account founds something on a pass it is eligible for.
     * Power shifts a neighbour by half, exactly as it does in NpcBalance.
     */
    public static function chance($baseChance, $tier, $trait, $power)
    {
        $power  = max(0, min(100, (int) $power));
        $chance = (int) round(((int) $baseChance) * self::appetite($tier, $trait) * (0.5 + $power / 200));

        return max(5, min(95, $chance));
    }

    /**
     * Does this neighbour found a village this pass?
     *
     * Ordered cheapest test first so the common answer - no - costs almost
     * nothing across a whole batch.
     *
     * @param array    $npc            ['tier','trait','power','villages','pop']
     * @param int      $playerPop      Unused since growth left the player; kept
     *                                 so callers and tests keep one signature.
     * @param int      $playerVillages Only used when 'lead' is set.
     * @param array    $limits         ['hardCap','chance','lead','floor']
     * @param int|null $roll           1..100, injected by the tests.
     */
    public static function shouldExpand(array $npc, $playerPop, $playerVillages, array $limits = [], $roll = null)
    {
        $tier     = isset($npc['tier']) ? (string) $npc['tier'] : '';
        $trait    = isset($npc['trait']) ? (string) $npc['trait'] : '';
        $power    = isset($npc['power']) ? (int) $npc['power'] : 0;
        $villages = isset($npc['villages']) ? (int) $npc['villages'] : 0;
        $pop      = isset($npc['pop']) ? (int) $npc['pop'] : 0;

        $hardCap    = isset($limits['hardCap']) ? (int) $limits['hardCap'] : 9;
        $baseChance = isset($limits['chance']) ? (int) $limits['chance'] : 60;
        $lead       = isset($limits['lead']) ? (int) $limits['lead'] : 0;
        $floor      = isset($limits['floor']) ? (int) $limits['floor'] : self::FLOOR;

        if ($tier === NpcTiers::INACTIVE || $villages < 1 || $pop < 1) {
            return false;
        }
        if ($villages >= self::ceiling($hardCap, $tier, $floor, $lead, $playerVillages)) {
            return false;
        }
        if ($pop / $villages < self::MIN_POP_PER_VILLAGE) {
            return false;
        }
        if ($pop < self::requiredPop($villages + 1, $tier, $trait)) {
            return false;
        }

        $roll = ($roll === null) ? random_int(1, 100) : (int) $roll;

        return $roll <= self::chance($baseChance, $tier, $trait, $power);
    }

    /**
     * Garrison the new village is born with.
     *
     * Deliberately a fraction of the PER-VILLAGE cap, and deliberately without
     * NpcBalance::MIN_ARMY applied. targetArmy() divides the account's ceiling
     * by its village count, so founding already lowers the cap of every
     * existing village; handing the new one a full garrison on top of that
     * would turn expansion into a free troop printer. Starting it near empty
     * keeps the account's total flat and lets growArmy() fill it in over the
     * following passes, which is also what a real player's new village looks
     * like.
     */
    public static function startArmy($perVillageTarget)
    {
        $target = max(0, (int) $perVillageTarget);

        return (int) floor($target * self::START_ARMY_PCT / 100);
    }
}
