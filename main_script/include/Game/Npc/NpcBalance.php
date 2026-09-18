<?php

namespace Game\Npc;

/**
 * Ceilings for a neighbour's army, measured against ITS OWN population.
 *
 * The first version measured everything against the human player's
 * population. That made the world unable to run away from the player, and
 * also unable to exist without them: with a 300-population player every
 * neighbour was frozen at fifty units. Growth now follows the account's own
 * curve (see NpcGrowthCurve) and the army follows the account: a 2000-pop
 * TOP has a 2000-pop army, a BUILDER slightly more, a CASUAL a token
 * garrison, a cow whatever it quit with and not one unit more.
 *
 * Growth is still gradual - one step is a fraction of the ceiling - so a
 * neighbour drained by raids takes real time to become a threat again.
 */
class NpcBalance
{
    /** Fraction of the ceiling added by one growth pass. */
    const ARMY_STEP_PCT = 8;

    /**
     * Floor for every ACTIVE garrison.
     *
     * It has to sit above NpcTargeting::MIN_GARRISON, or a world is one where
     * no neighbour ever has enough troops to leave home: no raids between
     * neighbours, nothing happening anywhere.
     *
     * The floor is itself capped by the account's population (see
     * targetArmy). A world now opens at day zero with two-population villages,
     * and handing each of those a garrison of fifty would mean the player
     * spends their first day surrounded by armies they cannot match and did
     * not earn. An account grows into this floor within its first day instead.
     */
    const MIN_ARMY = 50;

    /** A raider may outgrow its tier by this much, and no more. */
    const MAX_LOOT_BONUS = 2.0;

    /** Loot per point of OWN population needed for the full bonus. */
    const LOOT_PER_POP = 600;

    /**
     * Army upkeep a village can carry, as a multiple of its granary. Past
     * this the crop floor maintain() keeps would not survive the elapsed
     * upkeep a battle applies in one go, and starvation() eats the garrison.
     */
    const ARMY_PER_MAXCROP = 1.2;

    /** Units per point of population, by tier. */
    private static $ratio = [
        NpcTiers::TOP      => 1.5,
        NpcTiers::BUILDER  => 1.8,
        NpcTiers::CASUAL   => 0.6,
        NpcTiers::INACTIVE => 0.0,
    ];

    /** Army ratio of a tier; unknown tiers behave like CASUAL. */
    public static function ratio($tier)
    {
        return isset(self::$ratio[$tier]) ? self::$ratio[$tier] : self::$ratio[NpcTiers::CASUAL];
    }

    /**
     * How much a neighbour's own raiding lets it exceed its tier.
     *
     * Loot is allowed to lift the ceiling, never to remove it: a farm king
     * that has been eating the neighbourhood for days grows towards double
     * its normal size and stops there. Measured against its own population,
     * so a big account needs proportionally more loot for the same bonus.
     */
    public static function lootBonus($loot, $ownPop)
    {
        $loot   = max(0, (int) $loot);
        $ownPop = max(1, (int) $ownPop);
        $earned = $loot / ($ownPop * self::LOOT_PER_POP);

        return min(self::MAX_LOOT_BONUS, 1.0 + ($earned * (self::MAX_LOOT_BONUS - 1.0)));
    }

    /**
     * Units ONE VILLAGE of this account may hold.
     *
     * The ceiling is per account and then split across its villages, because
     * the yardstick - the account's population - is also an account total.
     * Applying it per village would silently multiply every neighbour by the
     * number of villages it happens to own.
     *
     * A cow gets 0, below the floor on purpose: whatever it was seeded with is
     * all it will ever have.
     *
     * @param int    $ownPop   Population of the whole account.
     * @param string $tier     NpcTiers constant.
     * @param int    $power    Per-account 0..100 modifier, so neighbours differ.
     * @param int    $villages Villages the account owns.
     * @param float  $bonus    Loot multiplier from lootBonus(), 1.0 .. 2.0.
     */
    public static function targetArmy($ownPop, $tier, $power, $villages = 1, $bonus = 1.0)
    {
        if ($tier === NpcTiers::INACTIVE) {
            return 0;
        }
        $ownPop   = max(0, (int) $ownPop);
        $power    = max(0, min(100, (int) $power));
        $villages = max(1, (int) $villages);
        $bonus    = max(1.0, min(self::MAX_LOOT_BONUS, (float) $bonus));

        // 0.5 at power 0, 1.0 at power 100: power shifts a neighbour by half,
        // it does not decide the fight on its own.
        $powerFactor = 0.5 + ($power / 200);

        $target = (int) round($ownPop * self::ratio($tier) * $powerFactor * $bonus / $villages);

        // One unit per point of population is already far more than any real
        // account fields, so this only ever bites the first hours of a world.
        return max(min(self::MIN_ARMY, $ownPop), $target);
    }

    /**
     * Cap a per-village army at what its granary can feed. maxcrop is the
     * granary capacity; upkeep per unit is roughly one crop an hour, and the
     * crop floor maintain() enforces is a quarter of the granary every ten
     * minutes, which carries about this many units through a long absence.
     */
    public static function cropCap($perVillageTarget, $maxcrop)
    {
        $maxcrop = max(0, (int) $maxcrop);
        if ($maxcrop <= 0) {
            return (int) $perVillageTarget;
        }
        return (int) min((int) $perVillageTarget, floor($maxcrop * self::ARMY_PER_MAXCROP));
    }

    /**
     * How many units to add in one pass. Never overshoots the ceiling and
     * never adds nothing while below it, so growth is visible but slow.
     */
    public static function armyStep($current, $target)
    {
        $current = max(0, (int) $current);
        $target  = max(0, (int) $target);

        if ($current >= $target) {
            return 0;
        }

        $step = (int) ceil($target * self::ARMY_STEP_PCT / 100);

        return min(max(1, $step), $target - $current);
    }

    /**
     * Spread a number of new units over the slots a village already uses, so
     * a defensive garrison stays defensive instead of drifting into a mixed
     * army. Falls back to the archetype's own slots when the village is empty
     * (everything was killed or raided away).
     *
     * @param array $current  Relative slot => amount currently in the village.
     * @param array $fallback Archetype starting army, used when $current is empty.
     * @param int   $step     Units to distribute.
     * @return array Relative slot => units to ADD.
     */
    public static function distribute(array $current, array $fallback, $step)
    {
        $step = max(0, (int) $step);
        if ($step === 0) {
            return [];
        }

        $shape = array_filter($current, function ($amount) {
            return (int) $amount > 0;
        });
        $blueprint = array_filter($fallback, function ($amount) {
            return (int) $amount > 0;
        });
        if (!$shape) {
            $shape = $blueprint;
        } elseif ($blueprint) {
            // Slots the blueprint has and the village lacks (scouts, siege in
            // a warlord) are added at the blueprint's proportion, so a village
            // seeded before they existed grows them instead of never having any.
            $have  = array_sum($shape);
            $total = array_sum($blueprint);
            foreach ($blueprint as $slot => $amount) {
                if (!isset($shape[$slot]) && $total > 0) {
                    $shape[$slot] = max(1, (int) round($have * $amount / $total));
                }
            }
        }
        if (!$shape) {
            return [];
        }

        $total = array_sum($shape);
        $added = [];
        $left  = $step;

        foreach ($shape as $slot => $amount) {
            $share = (int) floor($step * $amount / $total);
            if ($share > 0) {
                $added[$slot] = $share;
                $left -= $share;
            }
        }

        // Rounding leftovers go to the biggest slot, so nothing is lost.
        if ($left > 0) {
            $biggest = array_search(max($shape), $shape, true);
            $added[$biggest] = (isset($added[$biggest]) ? $added[$biggest] : 0) + $left;
        }

        return $added;
    }
}
