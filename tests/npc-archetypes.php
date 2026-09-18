<?php

// The archetypes are written straight into fdata, so a wrong column name or a
// gid with no data table would produce a village the engine cannot read. This
// validates them against the ruleset, which the test supplies as a stub so the
// blueprints can be checked without standing up the game.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGameData.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';

use Game\Npc\NpcArchetypes;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * The slice of the ruleset these blueprints touch, with the engine's own
 * figures: crop upkeep per building and the level each one stops at. The
 * population formula is the engine's (Formulas::buildingCropConsumption), so a
 * blueprint that passes here passes against the real tables too.
 */
class NpcStubGameData implements \Game\Npc\NpcGameData
{
    /** gid => crop upkeep of level 1, which is what drives population. */
    private static $cu = [
        1 => 2, 2 => 2, 3 => 3, 4 => 0,            // resource fields
        5 => 4, 6 => 3, 7 => 6, 8 => 3, 9 => 4,    // boosters
        10 => 1, 11 => 1, 12 => 4, 13 => 4, 14 => 1,
        15 => 2, 16 => 1, 17 => 4, 18 => 3, 19 => 4,
        20 => 5, 21 => 3, 22 => 4, 23 => 0, 24 => 4,
        25 => 1, 26 => 1, 27 => 4, 28 => 3,
        31 => 0, 32 => 0, 33 => 0,                 // walls
    ];

    /** gid => highest level, for everything that does not stop at 20. */
    private static $maxLevel = [
        5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5,    // boosters
        23 => 10,                                  // cranny
    ];

    public function unitStat($tribe, $slot, $key)
    {
        return 0.0; // no blueprint here asks about troops
    }

    public function buildingMaxLevel($gid, $isCapital)
    {
        $gid = (int) $gid;
        if (!isset(self::$cu[$gid])) {
            return 0;
        }
        if ($gid <= 4) {
            return $isCapital ? 20 : 10;
        }

        return isset(self::$maxLevel[$gid]) ? self::$maxLevel[$gid] : 20;
    }

    public function buildingPop($gid, $level)
    {
        $gid   = (int) $gid;
        $level = (int) $level;
        if ($level < 1 || !isset(self::$cu[$gid])) {
            return 0;
        }
        $cu = self::$cu[$gid];

        return (int) ($level != 1 ? round((5 * $cu + ($level - 1)) / 10) : $cu);
    }

    public function wallGid($tribe)
    {
        $walls = [1 => 31, 2 => 32, 3 => 33];
        $tribe = (int) $tribe;

        return isset($walls[$tribe]) ? $walls[$tribe] : 0;
    }
}

$data = new NpcStubGameData();

$keys = NpcArchetypes::keys();
if (count($keys) < 3) {
    npc_fail('Expected at least three archetypes.');
}

foreach ($keys as $key) {
    foreach (NpcArchetypes::allowedTribes() as $tribe) {
        $columns = NpcArchetypes::toFieldColumns($key, $tribe, $data);

        if (!$columns) {
            npc_fail("Archetype $key produced no columns for tribe $tribe.");
        }

        foreach ($columns as $column => $value) {
            if (!preg_match('/^f(\d{1,2})(t?)$/', $column, $match)) {
                npc_fail("Archetype $key: '$column' is not an fdata column.");
            }

            $slot  = (int) $match[1];
            $isGid = ($match[2] === 't');

            if ($slot < 1 || $slot > 40) {
                npc_fail("Archetype $key: slot $slot is outside f1..f40.");
            }
            if ((int) $value < 0) {
                npc_fail("Archetype $key: $column is negative.");
            }

            // Every gid written must be a building the ruleset knows, and the
            // level asked for must be one it defines.
            if ($isGid) {
                $max = $data->buildingMaxLevel((int) $value, false);
                if ($max <= 0) {
                    npc_fail("Archetype $key: gid $value is not a building the ruleset defines.");
                }
                $level = isset($columns['f' . $slot]) ? (int) $columns['f' . $slot] : 0;
                if ($level > $max) {
                    npc_fail("Archetype $key: gid $value has no level $level.");
                }
            }

            // Resource fields carry levels only; their type comes from the
            // village type passed to village creation.
            if ($slot <= 18 && $isGid) {
                npc_fail("Archetype $key: resource field $column must not set a type.");
            }
        }

        // The wall lives in slot 40 and its gid is tribe-specific.
        if (isset($columns['f40t'])) {
            $wall = (int) $columns['f40t'];
            if (!in_array($wall, [31, 32, 33], true)) {
                npc_fail("Archetype $key: unexpected wall gid $wall for tribe $tribe.");
            }
        }
    }

    // An army that cannot be garrisoned is a bug, and the ratio drives the
    // whole balance: both must be sane.
    if (!NpcArchetypes::army($key)) {
        npc_fail("Archetype $key has no starting army.");
    }
    $ratio = NpcArchetypes::ratio($key);
    if ($ratio <= 0 || $ratio > 3) {
        npc_fail("Archetype $key has an implausible ratio: $ratio");
    }
}

// The engine refuses to conquer while a Residence/Palace/Command Centre stands,
// so at least one archetype must be takeable with senators alone - otherwise
// the player could never conquer anything without catapults.
$conquerable = array_filter($keys, function ($key) {
    return NpcArchetypes::isConquerable($key);
});
if (!$conquerable) {
    npc_fail('No archetype can be conquered without flattening a residence first.');
}

// ... and at least one must NOT be, or the progression is flat.
if (count($conquerable) === count($keys)) {
    npc_fail('Every archetype is senator-only; nothing ever needs catapults.');
}

fwrite(STDOUT, "NpcArchetypes regression test passed.\n");
