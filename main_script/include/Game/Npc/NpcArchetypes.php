<?php

namespace Game\Npc;

/**
 * Blueprints for NPC villages: what they build, what they garrison and how
 * strong they are allowed to get relative to the human player.
 *
 * Three archetypes, each a different kind of neighbour to meet:
 *
 *   FARM      Fat storage, high fields, no wall, no residence. The cash cow -
 *             worth raiding, easy to take with senators alone.
 *   GARRISON  Walls and defensive troops, and a Residence: catapults must
 *             flatten it before a senator can do anything (the engine refuses
 *             to conquer while gid 25/26/44 stands - see handleConquest()).
 *   WARLORD   Barracks and stable, offensive troops, no residence. This is the
 *             one that raids the player back.
 *
 * Everything here is pure data, so it can be unit-tested without a database.
 */
class NpcArchetypes
{
    const FARM     = 'farm';
    const GARRISON = 'garrison';
    const WARLORD  = 'warlord';

    /** Building gids used below, named for readability. */
    const GID_WAREHOUSE  = 10;
    const GID_GRANARY    = 11;
    const GID_MAIN       = 15;
    const GID_RALLY      = 16;
    const GID_MARKET     = 17;
    const GID_BARRACKS   = 19;
    const GID_STABLE     = 20;
    const GID_ACADEMY    = 22;
    const GID_WORKSHOP   = 21;
    const GID_CRANNY     = 23;
    const GID_RESIDENCE  = 25;
    const GID_SMITHY     = 12;
    const GID_ARMOURY    = 13;
    const GID_TOURNAMENT = 14;
    const GID_EMBASSY    = 18;
    const GID_TOWN_HALL  = 24;
    const GID_TRADE      = 28;

    /**
     * The `fdata` slot layout shared by every NPC village. Slot => gid.
     * Slots are the f19..f40 building spots; f1..f18 are resource fields and
     * their types come from the village `type` passed to generateVillages().
     */
    private static $layout = [
        19 => self::GID_MAIN,
        20 => self::GID_RALLY,
        21 => self::GID_WAREHOUSE,
        22 => self::GID_GRANARY,
        23 => self::GID_CRANNY,
        24 => self::GID_MARKET,
        25 => self::GID_BARRACKS,
        26 => self::GID_STABLE,
        27 => self::GID_ACADEMY,
        28 => self::GID_RESIDENCE,
    ];

    /**
     * Per-archetype levels and garrison.
     *
     * 'fields'  level of every resource field f1..f18
     * 'levels'  slot => level; a slot left out is not built at all
     * 'wall'    wall level (0 = no wall)
     * 'army'    tribe-RELATIVE slot => amount (see NpcTroopMapping)
     * 'ratio'   army ceiling as a fraction of the player's population
     */
    private static function table()
    {
        return [
            self::FARM => [
                'fields' => 6,
                'levels' => [19 => 5, 20 => 1, 21 => 12, 22 => 12, 23 => 4, 24 => 6],
                'wall'   => 0,
                'army'   => [1 => 30, 2 => 20],
                'ratio'  => 0.35,
            ],
            self::GARRISON => [
                'fields' => 5,
                'levels' => [19 => 8, 20 => 3, 21 => 8, 22 => 8, 23 => 6, 24 => 4, 25 => 5, 28 => 10],
                'wall'   => 8,
                'army'   => [1 => 120, 2 => 160, 4 => 20],
                'ratio'  => 0.90,
            ],
            self::WARLORD => [
                'fields' => 5,
                'levels' => [19 => 8, 20 => 5, 21 => 8, 22 => 8, 23 => 3, 24 => 4, 25 => 10, 26 => 8, 27 => 5],
                'wall'   => 4,
                // Scouts and a little siege from the start: a raider that
                // cannot scout goes in blind, and one without catapults can
                // never escalate (see NpcRealAttackPolicy).
                'army'   => [1 => 60, 3 => 120, 4 => 8, 5 => 40, 7 => 4, 8 => 6],
                'ratio'  => 1.30,
            ],
        ];
    }

    /** @return string[] Every archetype key. */
    public static function keys()
    {
        return array_keys(self::table());
    }

    public static function exists($key)
    {
        $table = self::table();
        return isset($table[$key]);
    }

    /** @return array The raw definition; empty array for an unknown key. */
    public static function definition($key)
    {
        $table = self::table();
        return isset($table[$key]) ? $table[$key] : [];
    }

    /**
     * True when a senator alone can take this village, i.e. it has no
     * Residence/Palace/Command Centre standing.
     */
    public static function isConquerable($key)
    {
        $definition = self::definition($key);
        if (!$definition) {
            return false;
        }
        foreach ($definition['levels'] as $slot => $level) {
            if (self::$layout[$slot] === self::GID_RESIDENCE && $level > 0) {
                return false;
            }
        }
        return true;
    }

    /** Army ceiling as a fraction of the human player's population. */
    public static function ratio($key)
    {
        $definition = self::definition($key);
        return $definition ? (float) $definition['ratio'] : 0.0;
    }

    /** Starting garrison, as tribe-relative slots. */
    public static function army($key)
    {
        $definition = self::definition($key);
        return $definition ? $definition['army'] : [];
    }

    /**
     * Render the blueprint as the `fdata` column => value map that village
     * creation expects ("f19t" = gid, "f19" = level).
     *
     * @param string      $key   Archetype.
     * @param int         $tribe Tribe id, used to pick the right wall gid.
     * @param NpcGameData $data  Where the tribe's wall gid comes from; the
     *                           ruleset owns that mapping, not this table.
     * @return array<string,int>
     */
    public static function toFieldColumns($key, $tribe, NpcGameData $data)
    {
        $definition = self::definition($key);
        if (!$definition) {
            return [];
        }

        $columns = [];

        // Resource fields: only levels, the types come from the village type.
        for ($field = 1; $field <= 18; $field++) {
            $columns['f' . $field] = (int) $definition['fields'];
        }

        foreach ($definition['levels'] as $slot => $level) {
            if (!isset(self::$layout[$slot]) || $level <= 0) {
                continue;
            }
            $columns['f' . $slot . 't'] = (int) self::$layout[$slot];
            $columns['f' . $slot]       = (int) $level;
        }

        $wall = (int) $data->wallGid($tribe);
        if ($definition['wall'] > 0 && $wall > 0) {
            $columns['f40t'] = $wall;
            $columns['f40']  = (int) $definition['wall'];
        }

        return $columns;
    }

    /** Tribes an NPC may be given. Limited to the three classic tribes, whose
     *  unit and wall data is complete in the ruleset. */
    public static function allowedTribes()
    {
        return [1, 2, 3];
    }

    /** The shared slot => gid layout, for code that grows a village later. */
    public static function layout()
    {
        return self::$layout;
    }
}
