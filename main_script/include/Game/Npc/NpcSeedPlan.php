<?php

namespace Game\Npc;

/**
 * Picks where NPC villages go.
 *
 * Database::generateBase() can only place villages in rings around the CENTRE
 * OF THE MAP, which is useless for "give the player some neighbours", so this
 * computes a ring around an arbitrary point instead. It only produces
 * candidate coordinates; whether a valley there is free is a database question
 * answered by NpcWorld.
 *
 * Candidates outside [-worldMax, worldMax] are dropped rather than wrapped.
 * The engine's own travel maths DOES wrap around the map edges
 * (MyGenerator::procDistanceTime), but a "neighbour" that is only close by
 * wrapping is not one the player will ever read as a neighbour, so a ring near
 * the edge is simply clipped.
 */
class NpcSeedPlan
{
    /**
     * Square bounding box for a ring, ready to feed a BETWEEN in SQL.
     *
     * @return array{0:int,1:int,2:int,3:int} [minX, maxX, minY, maxY]
     */
    public static function boundingBox($centerX, $centerY, $radiusMax, $worldMax)
    {
        $centerX   = (int) $centerX;
        $centerY   = (int) $centerY;
        $radiusMax = max(1, (int) $radiusMax);
        $worldMax  = max(1, (int) $worldMax);

        return [
            max(-$worldMax, $centerX - $radiusMax),
            min($worldMax, $centerX + $radiusMax),
            max(-$worldMax, $centerY - $radiusMax),
            min($worldMax, $centerY + $radiusMax),
        ];
    }

    /**
     * Chebyshev distance, which is what "N squares away" means on this map:
     * a village 5 east and 5 north is 5 away, not 7.
     */
    public static function distance($ax, $ay, $bx, $by)
    {
        return max(abs((int) $ax - (int) $bx), abs((int) $ay - (int) $by));
    }

    /**
     * True when (x, y) sits inside the ring and on the map.
     */
    public static function inRing($x, $y, $centerX, $centerY, $radiusMin, $radiusMax, $worldMax)
    {
        $x = (int) $x;
        $y = (int) $y;
        if (abs($x) > $worldMax || abs($y) > $worldMax) {
            return false;
        }
        $distance = self::distance($x, $y, $centerX, $centerY);
        return $distance >= (int) $radiusMin && $distance <= (int) $radiusMax;
    }

    /**
     * Every map square in the ring, closest first. Callers walk the list and
     * keep the free valleys until they have placed enough villages, so the
     * neighbourhood fills from the inside out and the player always gets a
     * target within reach even on a crowded map.
     *
     * @return array<int,array{0:int,1:int}> List of [x, y].
     */
    public static function ring($centerX, $centerY, $radiusMin, $radiusMax, $worldMax)
    {
        list($minX, $maxX, $minY, $maxY) = self::boundingBox($centerX, $centerY, $radiusMax, $worldMax);

        $candidates = [];
        for ($x = $minX; $x <= $maxX; $x++) {
            for ($y = $minY; $y <= $maxY; $y++) {
                if (!self::inRing($x, $y, $centerX, $centerY, $radiusMin, $radiusMax, $worldMax)) {
                    continue;
                }
                $candidates[] = [$x, $y, self::distance($x, $y, $centerX, $centerY)];
            }
        }

        usort($candidates, function ($a, $b) {
            return $a[2] <=> $b[2];
        });

        return array_map(function ($candidate) {
            return [$candidate[0], $candidate[1]];
        }, $candidates);
    }

    /**
     * The state an account seeded at a given age should be in.
     *
     * A world created today seeds every neighbour at day zero: one village,
     * nothing built, population two, exactly like the player. Seeding INTO a
     * running world instead passes the world's own age, and the account is
     * born as big as its curve says it would be by now - same curve, same
     * expansion rule, same build order the growth pass uses every hour. There
     * is no separate "seeded" shape any more, which is what used to make a
     * freshly seeded neighbour look nothing like a grown one.
     *
     * The village count is walked up rather than solved, because the target
     * population itself depends on how many villages the account owns: each
     * village it can afford raises its own ceiling, which may pay for the next.
     *
     * @param float  $gameDays   Account age, in game days (see NpcGrowthCurve).
     * @param string $tier       NpcTiers constant.
     * @param string $trait      NpcTraits constant, for expansion appetite.
     * @param int    $power      0..100 per-account modifier.
     * @param int    $villageCap NpcBuildOrder::villageCap() for a satellite.
     * @param int    $capitalCap NpcBuildOrder::villageCap() for the capital.
     * @param int    $hardCap    NPC_EXPAND_MAX_VILLAGES.
     * @return array{villages:int,pop:int}
     */
    public static function seedState($gameDays, $tier, $trait, $power, $villageCap, $capitalCap, $hardCap)
    {
        $villages = 1;
        $pop      = NpcGrowthCurve::targetPop($gameDays, $tier, $power, 1, $villageCap, $capitalCap);
        $ceiling  = NpcExpansion::ceiling($hardCap, $tier, 1);

        while ($villages < $ceiling) {
            if ($pop / $villages < NpcExpansion::MIN_POP_PER_VILLAGE) {
                break;
            }
            if ($pop < NpcExpansion::requiredPop($villages + 1, $tier, $trait)) {
                break;
            }
            $villages++;
            $pop = NpcGrowthCurve::targetPop($gameDays, $tier, $power, $villages, $villageCap, $capitalCap);
        }

        return ['villages' => $villages, 'pop' => (int) $pop];
    }

    /**
     * How the account's population is split across its villages.
     *
     * The capital carries more, the way a real account's does, and the
     * remainder lands on it too rather than being lost to integer division.
     *
     * @return int[] One budget per village, capital first.
     */
    public static function popShares($pop, $villages)
    {
        $villages = max(1, (int) $villages);
        $pop      = max(0, (int) $pop);

        if ($villages === 1) {
            return [$pop];
        }

        // The capital is worth about a village and a half of the rest: enough
        // to read as the account's centre without starving the satellites.
        $units    = $villages + 0.5;
        $ordinary = (int) floor($pop / $units);
        $shares   = array_fill(0, $villages, $ordinary);
        $shares[0] = $pop - $ordinary * ($villages - 1);

        return $shares;
    }

    /**
     * How many villages each NPC gets, when an admin overrides the age-derived
     * count through NPC_VILLAGES_MIN/MAX.
     *
     * One is now allowed, because that is how a world starts. Note what it
     * costs: the engine refuses to conquer a player's LAST village and never
     * lets a capital fall (handleConquest), so a single-village neighbour is
     * untouchable until it founds its second. Active tiers do that within
     * days; inactive ones get there through their quit day (NpcLifespan)
     * rather than by being seeded with spare villages.
     */
    public static function villagesFor($min, $max)
    {
        $min = max(1, (int) $min);
        $max = max($min, (int) $max);
        return random_int($min, $max);
    }
}
