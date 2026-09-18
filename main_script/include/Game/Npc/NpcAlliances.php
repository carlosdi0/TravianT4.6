<?php

namespace Game\Npc;

/**
 * Blocs: the neighbourhood divided into alliances that do not raid their own.
 *
 * Two things come out of this, and neither is cosmetic:
 *
 *   - The alliance rankings stop being an empty page. NPC alliances gain and
 *     lose population on their own, so there is a table to climb.
 *   - The map acquires shape. A player who can read which tag sits where knows
 *     that hitting one warlord answers to three more, and that the loners in
 *     between are the safe farms. Without blocs every neighbour is equally
 *     dangerous and equally worth hitting, which is no decision at all.
 *
 * Not everyone joins. A quarter of the world stays unaligned on purpose: they
 * are the ones who will raid anybody, and the ones anybody can raid, so the
 * blocs read as a choice rather than as the default state of the map.
 *
 * Each bloc also carries a written profile. An alliance page with an empty
 * description is the tell that gives the whole thing away, and the engine's
 * own [ally] and [war] shortcodes mean the diplomacy section fills itself in
 * from the `diplomacy` table - so the page stays true even after a pact is
 * cancelled or a bloc is conquered.
 *
 * Pure data and arithmetic, no database, so the whole layout is testable.
 */
class NpcAlliances
{
    /** diplomacy.type values, matching the alliance screen. */
    const PACT_CONFED = 1;
    const PACT_WAR    = 3;

    /** Share of neighbours that never join anything, in percent. */
    const LONER_PCT = 25;

    /** Neighbours per alliance the layout aims for. */
    const TARGET_SIZE = 6;

    /** alidata.max for an NPC alliance; the engine never enforces it here. */
    const MAX_MEMBERS = 30;

    /**
     * Tag and name for each bloc. The tags are what the player actually reads
     * on the map and in the rankings, so they are short and distinct rather
     * than pretty.
     */
    private static $blocs = [
        ['tag' => 'RVN',  'name' => 'The Raven Banner',
         'notice' => "Recruitment: closed.\nFounded by %LEADER%.",
         'desc'   => "We were here before the roads were.\n\n"
                   . "Ride out at dusk, be home by dawn, and do not come back empty.\n"
                   . "We do not hold ground we cannot feed. We do not avenge losses "
                   . "we earned. Everything else is negotiable.\n\n"
                   . "Members answer to their own villages first and to the banner "
                   . "second. That is not weakness, it is why we are still here.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'IRON', 'name' => 'Iron Compact',
         'notice' => "Recruitment: by invitation.\nSteward: %LEADER%.",
         'desc'   => "An agreement, not a friendship.\n\n"
                   . "Three terms, and they have not changed since %LEADER% wrote "
                   . "them down:\n"
                   . "1. No member raids another. Ever.\n"
                   . "2. Grain moves to whoever is under siege, at cost.\n"
                   . "3. Anyone who breaks the first two is outside the walls by "
                   . "morning, and stays there.\n\n"
                   . "We are not interested in glory. We are interested in the "
                   . "harvest arriving.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'ASH',  'name' => 'Sons of the Ash',
         'notice' => "Recruitment: open to those who have lost something.",
         'desc'   => "The valley burned. We did not leave.\n\n"
                   . "%LEADER% pulled the first stone out of the ash and set it "
                   . "back where it had been. That is the whole creed.\n\n"
                   . "We keep no standing army we cannot justify to the people "
                   . "feeding it, and we remember every wall that was thrown down "
                   . "on us. Both of those are long lists.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'WLF',  'name' => 'Winter Wolves',
         'notice' => "Recruitment: closed until the thaw.",
         'desc'   => "Wolves hunt when the herd is thin.\n\n"
                   . "We do not besiege, we do not garrison, and we do not hold "
                   . "grudges past the season. We arrive when your troops are out "
                   . "and we are gone before they are back.\n\n"
                   . "%LEADER% says a pack that fights its own eats its own. "
                   . "Nobody has tested it.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'SALT', 'name' => 'The Salt Road',
         'notice' => "Recruitment: open.\nTrade requests to %LEADER%.",
         'desc'   => "Every road here was ours first, and we still take our cut.\n\n"
                   . "We would rather sell you grain than burn it. That offer is "
                   . "open to anyone, and it closes the moment a caravan of ours "
                   . "fails to arrive.\n\n"
                   . "Members trade at cost between themselves. Outsiders pay what "
                   . "the road is worth that week.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'EMB',  'name' => 'The Ember Pact',
         'notice' => "Recruitment: closed.\nSworn under %LEADER%.",
         'desc'   => "Sworn over a fire that was not allowed to go out.\n\n"
                   . "Four villages made the oath. What is left of it is here.\n\n"
                   . "We answer a call from a member within the day, whatever it "
                   . "costs, and we ask the same back. It has ruined us twice and "
                   . "saved us more often than that.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'HOLL', 'name' => 'Hollow Kings',
         'notice' => "Recruitment: we will find you.",
         'desc'   => "Crowns without a kingdom, which is the honest kind.\n\n"
                   . "Every one of us ruled something once and lost it. We are not "
                   . "sentimental about how.\n\n"
                   . "%LEADER% keeps the seat warm and nobody has wanted it badly "
                   . "enough to take it. Ask again in a month.\n\n"
                   . "[ally]\n[war]"],

        ['tag' => 'TIDE', 'name' => 'The Long Tide',
         'notice' => "Recruitment: open to landless accounts.",
         'desc'   => "Patience is a weapon nobody watches for.\n\n"
                   . "We do not race to the top of the rankings. We take the "
                   . "village next to us, then the one next to that, and we do it "
                   . "for as long as it takes.\n\n"
                   . "%LEADER% has never lost a village. That is not luck, it is "
                   . "the point.\n\n"
                   . "[ally]\n[war]"],
    ];

    /** Every bloc in the pool, in order. */
    public static function blocs()
    {
        return self::$blocs;
    }

    /** One bloc's tag and name; the pool wraps, so any index is valid. */
    public static function bloc($index)
    {
        $index = abs((int) $index) % count(self::$blocs);

        return self::$blocs[$index];
    }

    /**
     * How many alliances a neighbourhood of this size should carry.
     *
     * Two is the floor worth having: with a single bloc there is nobody for it
     * to be at odds with, and the whole map becomes one truce. The ceiling is
     * the pool, so tags are never reused.
     */
    public static function blocCount($npcCount)
    {
        $npcCount = max(0, (int) $npcCount);
        if ($npcCount < 4) {
            return 0; // too few neighbours to divide into anything meaningful
        }

        $aligned = (int) round($npcCount * (100 - self::LONER_PCT) / 100);
        $wanted  = (int) round($aligned / self::TARGET_SIZE);

        return max(2, min(count(self::$blocs), $wanted));
    }

    /**
     * Whether this neighbour stays unaligned, decided once from its uid.
     *
     * Deterministic so a neighbour does not drift in and out of a bloc every
     * upkeep pass: joining is permanent, which is what makes the tag on the
     * map mean something.
     */
    public static function isLoner($uid)
    {
        return (crc32('npc-bloc-' . (int) $uid) % 100) < self::LONER_PCT;
    }

    /**
     * Which bloc a joining neighbour goes to.
     *
     * Smallest bloc first, so seeding in several runs does not pile everyone
     * into the first tag; the uid only breaks ties, and it breaks them the
     * same way every time.
     *
     * @param array $sizes Current member count per bloc index.
     * @return int Bloc index.
     */
    public static function assign($uid, array $sizes)
    {
        if (!$sizes) {
            return 0;
        }

        $best     = null;
        $bestSize = PHP_INT_MAX;
        $offset   = abs(crc32('npc-join-' . (int) $uid));

        // Start the walk at a uid-derived offset so equal-sized blocs are not
        // always resolved in favour of index 0.
        $indexes = array_keys($sizes);
        $count   = count($indexes);

        for ($step = 0; $step < $count; $step++) {
            $index = $indexes[($offset + $step) % $count];
            $size  = (int) $sizes[$index];
            if ($size < $bestSize) {
                $best     = $index;
                $bestSize = $size;
            }
        }

        return $best === null ? $indexes[0] : $best;
    }

    /**
     * Which blocs are confederated, as index pairs.
     *
     * Roughly one pact per three blocs, and every bloc in at most one: a
     * confederation must never chain into a third party, or a single pact
     * quietly puts the whole neighbourhood at peace and the raiding stops.
     * With two blocs there is no pact at all, so the map stays split.
     *
     * @return array List of [indexA, indexB], indexA < indexB.
     */
    public static function confederations($blocCount)
    {
        $blocCount = max(0, (int) $blocCount);
        if ($blocCount < 3) {
            return [];
        }

        $wanted = max(1, (int) floor($blocCount / 3));
        $pairs  = [];

        for ($i = 0; $i + 1 < $blocCount && count($pairs) < $wanted; $i += 2) {
            $pairs[] = [$i, $i + 1];
        }

        return $pairs;
    }

    /**
     * The written profile for a bloc, with %LEADER% already filled in.
     *
     * `notice` is the narrow column under the member list, `desc` the wide one
     * beside it. Both go through htmlspecialchars() before the engine expands
     * the shortcodes, so plain text is all that belongs here - no markup.
     *
     * @param string $leader Username of the founding member.
     * @return array ['notice' => string, 'desc' => string]
     */
    public static function profile($index, $leader = '')
    {
        $bloc   = self::bloc($index);
        $leader = trim((string) $leader);
        if ($leader === '') {
            $leader = 'the founder';
        }

        return [
            'notice' => str_replace('%LEADER%', $leader, $bloc['notice']),
            'desc'   => str_replace('%LEADER%', $leader, $bloc['desc']),
        ];
    }

    /**
     * Which blocs have declared war on each other, as index pairs.
     *
     * Purely descriptive: the raid pass never reads these, because blocs
     * already raid everyone they are not allied with. Declaring it anyway is
     * what makes an alliance page explain the reports the player is getting,
     * instead of showing a diplomacy section that is blank while the two tags
     * are visibly burning each other's farms.
     *
     * Blocs are walked as a ring and every neighbouring pair that is not
     * confederated is at war, so there is always at least one live conflict.
     *
     * @return array List of [indexA, indexB].
     */
    public static function wars($blocCount)
    {
        $blocCount = max(0, (int) $blocCount);
        if ($blocCount < 2) {
            return [];
        }

        $allied = [];
        foreach (self::confederations($blocCount) as $pair) {
            $allied[$pair[0] . ':' . $pair[1]] = true;
            $allied[$pair[1] . ':' . $pair[0]] = true;
        }

        $wars = [];
        for ($i = 0; $i < $blocCount; $i++) {
            $next = ($i + 1) % $blocCount;
            if ($i === $next) {
                continue;
            }
            if (isset($allied[$i . ':' . $next])) {
                continue;
            }
            // The ring closes on itself, so record each pair once.
            $a = min($i, $next);
            $b = max($i, $next);
            $wars[$a . ':' . $b] = [$a, $b];
        }

        return array_values($wars);
    }

    /**
     * Alliance ids this attacker will not raid: its own, plus its confederates.
     *
     * Unaligned attackers (aid 0) get an empty set, which is the whole point of
     * being unaligned - they raid anybody, including bloc members.
     *
     * @param int   $aid     Attacker's alliance id, 0 when unaligned.
     * @param array $confeds aid => list of confederated aids.
     * @return array Alliance ids that are off limits.
     */
    public static function friendly($aid, array $confeds)
    {
        $aid = (int) $aid;
        if ($aid <= 0) {
            return [];
        }

        $friends = [$aid];
        if (isset($confeds[$aid])) {
            foreach ($confeds[$aid] as $other) {
                $other = (int) $other;
                if ($other > 0 && !in_array($other, $friends, true)) {
                    $friends[] = $other;
                }
            }
        }

        return $friends;
    }

    /**
     * Whether an attacker in $aid may hit a village owned by someone in
     * $targetAid.
     *
     * A village with no alliance is always fair game, and so is any attacker
     * with none of its own.
     */
    public static function mayRaid($aid, $targetAid, array $confeds)
    {
        $targetAid = (int) $targetAid;
        if ($targetAid <= 0) {
            return true;
        }

        return !in_array($targetAid, self::friendly($aid, $confeds), true);
    }
}
