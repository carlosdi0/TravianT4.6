<?php

namespace Model;

use Core\Database\DB;
use Game\Formulas;
use Game\Npc\NpcArchetypes;
use Game\Npc\NpcBalance;
use Game\Npc\NpcBuildOrder;
use Game\Npc\NpcExpansion;
use Game\Npc\NpcFormulasData;
use Game\Npc\NpcGrowthCurve;
use Game\Npc\NpcLifespan;
use Game\Npc\NpcNames;
use Game\Npc\NpcSeedPlan;
use Game\Npc\NpcTerrain;
use Game\Npc\NpcTiers;
use Game\Npc\NpcTraits;
use Game\ResourcesHelper;
use function getGameElapsedSeconds;
use function getGameSpeed;
use function getNpc;
use function get_random_string;

/**
 * Creating neighbours. Always by hand, never by a worker.
 *
 * A world starts empty and an administrator seeds it deliberately. Automatic
 * seeding would mean every fresh install silently populates itself, which is
 * impossible to undo cleanly once the accounts have grown - and the accounts do
 * grow, which is the whole point.
 *
 * An account seeded into a world that has been running for weeks is born as big
 * as its own curve says it would be by now: same curve, same expansion rule,
 * same build order the growth pass walks every quarter of an hour. There is no
 * separate "seeded" shape, which is what used to make a freshly seeded
 * neighbour look nothing like a grown one.
 */
class NpcSeedModel
{
    /** Valleys fetched per account looked at; enough to fall back a few times. */
    const VALLEY_OVERSAMPLE = 6;

    /** @var NpcModel */
    private $npc;

    /** @var NpcGrowthModel */
    private $growth;

    /** @var NpcFormulasData */
    private $data;

    public function __construct(NpcModel $npc = null, NpcGrowthModel $growth = null, NpcFormulasData $data = null)
    {
        $this->npc    = $npc ?: new NpcModel();
        $this->data   = $data ?: new NpcFormulasData();
        $this->growth = $growth ?: new NpcGrowthModel($this->npc, $this->data);
    }

    /**
     * Seed $count neighbours in a ring around a map square.
     *
     * @param int   $count   Accounts to create.
     * @param array $options centerX, centerY, radiusMin, radiusMax; anything
     *                       left out comes from the config, and a centre left
     *                       out is the biggest human player's capital.
     * @return array{created:int,failed:int,accounts:array,reason:string}
     */
    public function seed($count, array $options = [])
    {
        $count = max(0, (int) $count);
        if ($count === 0) {
            return $this->result(0, 0, [], 'Nothing asked for.');
        }

        $centre = $this->centre($options);
        if ($centre === null) {
            return $this->result(0, $count, [], 'No centre to seed around: pass explicit coordinates.');
        }

        $radiusMin = (int) (isset($options['radiusMin']) ? $options['radiusMin'] : getNpc('seedRadiusMin', 4));
        $radiusMax = (int) (isset($options['radiusMax']) ? $options['radiusMax'] : getNpc('seedRadiusMax', 20));
        $worldMax  = (int) (defined('MAP_SIZE') ? MAP_SIZE : 100);

        $valleys = $this->npc->freeValleysInRing(
            $centre['x'],
            $centre['y'],
            $radiusMin,
            $radiusMax,
            $worldMax
        );
        if (!$valleys) {
            return $this->result(0, $count, [], 'No free valleys in the ring; widen it or free some map.');
        }

        $tiers   = NpcTiers::planSeed($this->npc->countByTier(), $count, NpcModel::configuredSplit());
        $seedBase = $this->npc->total();

        $created  = 0;
        $failed   = 0;
        $accounts = [];
        foreach ($tiers as $index => $tier) {
            $account = $this->seedOne($tier, $seedBase + $index, $valleys);
            if ($account === null) {
                $failed++;
                continue;
            }
            $created++;
            $accounts[] = $account;
        }

        return $this->result($created, $failed, $accounts, '');
    }

    /**
     * One account, from an empty name to a registry row.
     *
     * @param array $valleys Free valleys, closest first; the ones taken here
     *                       are removed so the next account does not pick them.
     * @return array|null Summary of what was created, null when it could not be.
     */
    private function seedOne($tier, $seed, array &$valleys)
    {
        $tribes    = NpcArchetypes::allowedTribes();
        $tribe     = $tribes[random_int(0, count($tribes) - 1)];
        $archetype = NpcTiers::archetypeFor($tier, $seed);
        $trait     = NpcTraits::forTier($tier, $seed);
        $power     = random_int((int) getNpc('powerMin', 35), max((int) getNpc('powerMin', 35), (int) getNpc('powerMax', 85)));

        $name = $this->freeName($tribe, $seed);
        if ($name === null) {
            return null; // every name in the tribe's pool is taken
        }

        $speed = getGameSpeed();
        $age   = max(0, (int) getGameElapsedSeconds());
        $now   = time();
        // The account was "created" when the world was, so its curve has had
        // the same time to run as the world has been open.
        $created  = $now - $age;
        $quitAt   = NpcLifespan::quitAt($tier, $created, $speed);
        $seedDays = NpcGrowthCurve::gameDays($age, $speed);
        $lived    = NpcLifespan::seedAge($tier, $seedDays, $created, $quitAt, $speed);

        $villageCap = NpcBuildOrder::villageCap($this->data, $archetype, $tribe, false);
        $capitalCap = NpcBuildOrder::villageCap($this->data, $archetype, $tribe, true);
        $hardCap    = (int) getNpc('expandMaxVillages', 9);

        $state = NpcSeedPlan::seedState(
            $lived['days'],
            $lived['tier'],
            $trait,
            $power,
            $villageCap,
            $capitalCap,
            $hardCap
        );

        $tiles = $this->takeValleys($valleys, $state['villages'], $tier);
        if (!$tiles) {
            return null; // the ring ran out of room
        }

        $register = new RegisterModel();
        $capital  = $tiles[0];
        $uid      = (int) $register->addUser($name, sha1(get_random_string(random_int(8, 16))), '', $tribe, $capital['kid'], 1);
        if ($uid <= 0) {
            $this->releaseValleys($tiles);
            return null;
        }

        if (!$register->createBaseVillage($uid, $name, $tribe, $capital['kid'])) {
            $this->releaseValleys($tiles);
            return null;
        }
        // Village names are seeded from the capital, not from the batch
        // index, so the ones this account founds later carry on the series.
        $nameSeed = (int) $capital['kid'];
        foreach (array_slice($tiles, 1) as $index => $tile) {
            $register->createNewVillage($uid, $tribe, $tile['kid'], $capital['kid']);
            $this->renameVillage($tile['kid'], NpcNames::village($nameSeed, $index + 1));
        }
        $this->renameVillage($capital['kid'], NpcNames::village($nameSeed, 0));

        $db = DB::getInstance();
        // Back-date the account so the growth pass reads the same age this
        // seed was computed from, and so its beginner protection has already
        // run out on a world that is past it.
        $protection = $created + Formulas::getProtectionBasicTime($created);
        $db->query("UPDATE users SET signupTime=$created, protection=$protection, "
                 . 'last_login_time=' . ($tier === NpcTiers::INACTIVE && $quitAt > 0 ? (int) $quitAt : $now)
                 . ", lastVillageExpand=$now, lastHeroExpCheck=$now WHERE id=$uid");
        $db->query("UPDATE vdata SET created=$created WHERE owner=$uid");

        $this->npc->register($uid, [
            'archetype'           => $archetype,
            'tier'                => $tier,
            'trait'               => $trait,
            'power'               => $power,
            // Frozen here, on purpose: raising a global ceiling in a live world
            // wakes up every account parked at the old limit at once.
            //
            // Computed from the tier the account actually LIVES as, not the one
            // stored. An inactive's own ceiling is zero, and freezing that would
            // leave it unable to found anything during the days before it quits,
            // when it is behaving as a casual in every other respect.
            'max_villages'        => NpcExpansion::ceiling($hardCap, $lived['tier'], max(1, count($tiles))),
            'max_pop_per_village' => 0,
            'home_kid'            => (int) $capital['kid'],
            'created'             => $created,
            'quit_at'             => (int) $quitAt,
            // Due for its first growth pass immediately, so a seeded world
            // starts moving rather than waiting out a full interval.
            'last_growth'         => 0,
            'last_expand'         => $now,
            'next_attack'         => $now,
        ]);

        $this->buildToState($uid, $state, count($tiles), $archetype, $tribe, $lived, $power);

        // The hero is born as old as the account. addHero() stamps lastupdate
        // with the wall clock, so without this an account seeded into a
        // three-month-old world comes with a level 0 hero and gives itself
        // away the first time the player scouts it. A cow's hero stops the day
        // the account does, which is the same date its last login says.
        $db->query("UPDATE hero SET lastupdate=$created WHERE uid=$uid");
        $this->growth->growHero(
            $uid,
            $lived['tier'],
            $power,
            ['created' => $created],
            ($tier === NpcTiers::INACTIVE && $quitAt > 0) ? min($now, (int) $quitAt) : $now
        );

        return [
            'uid'       => $uid,
            'name'      => $name,
            'tribe'     => $tribe,
            'tier'      => $tier,
            'archetype' => $archetype,
            'trait'     => $trait,
            'power'     => $power,
            'villages'  => count($tiles),
            'kid'       => (int) $capital['kid'],
            'x'         => (int) $capital['x'],
            'y'         => (int) $capital['y'],
            'pop'       => (int) $state['pop'],
        ];
    }

    /**
     * Build and garrison the account up to the state its age calls for.
     *
     * Troops are written straight into `units` here rather than queued: an
     * account born three weeks old has to come with the garrison those weeks
     * would have produced, and queueing it would leave the whole neighbourhood
     * undefended for hours after every seed.
     */
    private function buildToState($uid, array $state, $villageCount, $archetype, $tribe, array $lived, $power)
    {
        $shares  = NpcSeedPlan::popShares((int) $state['pop'], (int) $villageCount);
        $villages = $this->npc->villagesOf($uid);
        if (!$villages) {
            return;
        }

        foreach ($villages as $index => $village) {
            $kid    = (int) $village['kid'];
            $row    = $this->npc->fdata($kid);
            $budget = isset($shares[$index]) ? (int) $shares[$index] : 0;
            if (!$row || $budget <= 0) {
                continue;
            }
            // A seeded village is built in one go, so the step cap the growth
            // pass uses does not apply: it exists to spread growth over
            // passes, and there is only ever one seeding pass.
            $this->growth->buildVillage(
                $kid,
                $row,
                $budget,
                $archetype,
                $tribe,
                (int) $village['capital'] === 1,
                NpcGrowthModel::MAX_STEPS_AT_ONCE
            );
        }

        // Re-read: the levels that were just applied are what decides the crop
        // ceiling the garrison is capped by.
        $villages = $this->npc->villagesOf($uid);
        $ownPop   = 0;
        foreach ($villages as $village) {
            $ownPop += (int) $village['pop'];
        }
        $target = NpcBalance::targetArmy($ownPop, $lived['tier'], $power, count($villages));
        if ($target <= 0) {
            return;
        }

        $blueprint = NpcArchetypes::army($archetype);
        foreach ($villages as $village) {
            $kid   = (int) $village['kid'];
            $size  = NpcBalance::cropCap($target, (int) $village['maxcrop']);
            $army  = NpcBalance::distribute([], $blueprint, $size);
            if ($army) {
                $this->growth->garrison($kid, $army);
            }
            ResourcesHelper::updateVillageResources($kid, false);
        }
    }

    /**
     * Take the valleys this account settles, best first.
     *
     * Only the top tier goes out of its way for a cropper, and every other
     * tier actively avoids one (NpcTerrain), which is what keeps the croppers
     * in the neighbourhood available for the player through the first weeks.
     */
    private function takeValleys(array &$valleys, $wanted, $tier)
    {
        $wanted = max(1, (int) $wanted);
        if (!$valleys) {
            return [];
        }

        $pool  = array_slice($valleys, 0, max($wanted * self::VALLEY_OVERSAMPLE, $wanted));
        $pool  = NpcTerrain::rank($pool, $tier);
        $taken = [];
        foreach ($pool as $tile) {
            if (count($taken) >= $wanted) {
                break;
            }
            // Satellites cluster around the capital the way a real account's
            // do, instead of being scattered across the whole ring.
            if ($taken && NpcSeedPlan::distance($tile['x'], $tile['y'], $taken[0]['x'], $taken[0]['y']) > 12) {
                continue;
            }
            $taken[] = $tile;
        }
        if (!$taken) {
            return [];
        }

        $takenKids = [];
        foreach ($taken as $tile) {
            $takenKids[(int) $tile['kid']] = true;
        }
        $valleys = array_values(array_filter($valleys, function ($tile) use ($takenKids) {
            return !isset($takenKids[(int) $tile['kid']]);
        }));

        $this->claimValleys($taken);

        return $taken;
    }

    /**
     * Mark valleys as taken before anything is created in them.
     *
     * Village creation marks them too, but only once it succeeds. Claiming
     * first is what stops a half-failed account from leaving a kid that the
     * next account in the same batch happily settles on top of.
     */
    private function claimValleys(array $tiles)
    {
        $kids = [];
        foreach ($tiles as $tile) {
            $kids[] = (int) $tile['kid'];
        }
        if (!$kids) {
            return;
        }
        $list = implode(',', $kids);
        $db   = DB::getInstance();
        $db->query("UPDATE available_villages SET occupied=1 WHERE kid IN ($list)");
        $db->query("UPDATE wdata SET occupied=1 WHERE id IN ($list)");
    }

    /** Hand valleys back when the account they were claimed for never appeared. */
    private function releaseValleys(array $tiles)
    {
        $kids = [];
        foreach ($tiles as $tile) {
            $kid = (int) $tile['kid'];
            // Never free a square something actually stands on.
            if (!(int) DB::getInstance()->fetchScalar("SELECT COUNT(kid) FROM vdata WHERE kid=$kid")) {
                $kids[] = $kid;
            }
        }
        if (!$kids) {
            return;
        }
        $list = implode(',', $kids);
        $db   = DB::getInstance();
        $db->query("UPDATE available_villages SET occupied=0 WHERE kid IN ($list)");
        $db->query("UPDATE wdata SET occupied=0 WHERE id IN ($list)");
    }

    /**
     * A name from the tribe's pool that nobody has yet.
     *
     * The pool is walked from the seed's own starting point rather than
     * decorated with a number: the seed counts ALL neighbours while each list
     * is per tribe, so a suffix derived from it numbered most of the world even
     * when plenty of names were still free.
     */
    private function freeName($tribe, $seed)
    {
        $size = NpcNames::poolSize($tribe);
        for ($offset = 0; $offset < $size; $offset++) {
            $name = NpcNames::player($tribe, $seed + $offset);
            if (!$this->npc->nameTaken($name)) {
                return $name;
            }
        }
        // Every name is taken: only now is a suffix worth the ugliness.
        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $name = substr(NpcNames::player($tribe, $seed), 0, 12) . $suffix;
            if (!$this->npc->nameTaken($name)) {
                return $name;
            }
        }

        return null;
    }

    private function renameVillage($kid, $name)
    {
        $db = DB::getInstance();
        $db->query("UPDATE vdata SET name='" . $db->real_escape_string($name) . "' WHERE kid=" . (int) $kid);
    }

    /**
     * The map square the ring is drawn around.
     *
     * Explicit coordinates win. Otherwise the biggest human player's capital,
     * because "give the player some neighbours" is what this feature is for;
     * the centre of the map is a poor second and is not used, since a world
     * whose only player lives in a corner would get its neighbours nowhere
     * near them.
     */
    private function centre(array $options)
    {
        if (isset($options['centerX']) && isset($options['centerY'])) {
            return ['x' => (int) $options['centerX'], 'y' => (int) $options['centerY']];
        }

        $benchmark = $this->npc->playerBenchmark();
        if ($benchmark['pop'] > 0 || $benchmark['villages'] > 0) {
            $kid = (int) DB::getInstance()->fetchScalar(
                'SELECT v.kid FROM vdata v JOIN users u ON u.id=v.owner '
                . 'WHERE u.id > 2 AND u.access < 3 AND u.id NOT IN (SELECT uid FROM npc_player) '
                . 'ORDER BY u.total_pop DESC, v.capital DESC LIMIT 1'
            );
            if ($kid > 0) {
                $xy = Formulas::kid2xy($kid);

                return ['x' => (int) $xy['x'], 'y' => (int) $xy['y']];
            }
        }

        return null;
    }

    private function result($created, $failed, array $accounts, $reason)
    {
        return ['created' => $created, 'failed' => $failed, 'accounts' => $accounts, 'reason' => $reason];
    }
}
