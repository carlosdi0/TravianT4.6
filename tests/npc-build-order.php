<?php

// The build order decides what an NPC village looks like as it grows. It must
// never produce a level the engine has no data for, must open new buildings in
// free slots, and must saturate at a population the growth curve can rely on.
// The ruleset it reads is a stub here, so none of this needs a live game.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGameData.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcBuildOrder.php';

use Game\Npc\NpcArchetypes;
use Game\Npc\NpcBuildOrder;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function npc_empty_row($fieldGid = 1)
{
    $row = [];
    for ($slot = 1; $slot <= 40; $slot++) {
        $row['f' . $slot]       = 0;
        $row['f' . $slot . 't'] = $slot <= 18 ? $fieldGid : 0;
    }
    return $row;
}

/**
 * The slice of the ruleset the build order reads, with the engine's own
 * figures: crop upkeep per building and the level each one stops at. The
 * population formula is the engine's (Formulas::buildingCropConsumption), so
 * the saturation figures asserted below are the real ones.
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
        return 0.0; // the build order never asks about troops
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

// Level maxima come from the ruleset: cranny 10, fields 20 but we stop at 10.
if (NpcBuildOrder::maxLevel($data, NpcArchetypes::GID_CRANNY) !== 10) {
    npc_fail('The cranny must max out at 10.');
}
if (NpcBuildOrder::maxLevel($data, NpcArchetypes::GID_MAIN) !== 20) {
    npc_fail('The main building must max out at 20.');
}
if (NpcBuildOrder::maxLevel($data, 0) !== 0 || NpcBuildOrder::maxLevel($data, 999) !== 0) {
    npc_fail('Unknown buildings have no levels.');
}

foreach (NpcArchetypes::keys() as $archetype) {
    foreach ([1, 2, 3] as $tribe) {
        $row   = npc_empty_row();
        $steps = 0;
        $seenGid = [];

        // Walk the whole order one level at a time.
        while (($step = NpcBuildOrder::nextStep($data, $row, $archetype, $tribe)) !== null && $steps < 2000) {
            list($slot, $gid, $level) = $step;
            $steps++;

            if ($slot < 1 || $slot > 40) {
                npc_fail("$archetype: slot $slot out of range");
            }
            if ($level > NpcBuildOrder::maxLevel($data, $gid)) {
                npc_fail("$archetype: $gid raised to $level, above its table");
            }
            if ($slot <= 18 && $level > NpcBuildOrder::MAX_FIELD_LEVEL) {
                npc_fail("$archetype: a field went past " . NpcBuildOrder::MAX_FIELD_LEVEL);
            }
            $current = (int) $row['f' . $slot];
            if ($level !== $current + 1) {
                npc_fail("$archetype: slot $slot jumped from $current to $level");
            }
            $there = (int) $row['f' . $slot . 't'];
            if ($there !== 0 && $there !== $gid) {
                npc_fail("$archetype: slot $slot holding $there was overwritten with $gid");
            }
            if ($slot === 40 && $gid !== $data->wallGid($tribe)) {
                npc_fail("$archetype: something other than the wall went into slot 40");
            }
            if ($slot >= 19 && $slot <= 39 && $gid === $data->wallGid($tribe)) {
                npc_fail("$archetype: the wall was built in an ordinary slot");
            }
            $row['f' . $slot]       = $level;
            $row['f' . $slot . 't'] = $gid;
            $seenGid[$gid] = true;
        }
        if ($steps === 0 || $steps >= 2000) {
            npc_fail("$archetype/$tribe: the order did not terminate sensibly ($steps steps)");
        }
        // Fields ended at 10, storage at 20, and a wall stands for tribes with one.
        for ($i = 1; $i <= 18; $i++) {
            if ((int) $row['f' . $i] !== NpcBuildOrder::MAX_FIELD_LEVEL) {
                npc_fail("$archetype: field $i finished at {$row['f' . $i]}");
            }
        }
        if (!isset($seenGid[NpcArchetypes::GID_WAREHOUSE]) || !isset($seenGid[NpcArchetypes::GID_GRANARY])) {
            npc_fail("$archetype: no storage in the order");
        }
        if ($data->wallGid($tribe) > 0 && $archetype !== NpcArchetypes::FARM && (int) $row['f40'] === 0) {
            npc_fail("$archetype/$tribe: no wall was built");
        }
        // The saturation used by the curve equals the population actually
        // built, FOR THIS VILLAGE'S OWN FIELD LAYOUT. If the two ever drift the
        // growth pass spends every run planning nothing, convinced the account
        // is still short of a target it can never reach.
        $built = NpcBuildOrder::popOfColumns($data, $row);
        $cap   = NpcBuildOrder::villageCapForRow($data, $row, $archetype, $tribe);
        if ($built !== $cap) {
            npc_fail("$archetype/$tribe: villageCapForRow $cap but the finished village has $built");
        }
        if ($cap < 400 || $cap > 1600) {
            npc_fail("$archetype/$tribe: villageCap $cap is not a plausible Travian village");
        }
    }
}

// Fields before military: on an empty village the first steps are fields.
$first = NpcBuildOrder::nextStep($data, npc_empty_row(), NpcArchetypes::WARLORD, 1);
if ($first[0] > 18) {
    npc_fail('A fresh village must start with its fields.');
}

// The lowest field is raised first, whatever its type.
$row = npc_empty_row();
for ($i = 1; $i <= 18; $i++) {
    $row['f' . $i] = 3;
}
$row['f7'] = 1;
$step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::FARM, 3);
if ($step[0] !== 7 || $step[2] !== 2) {
    npc_fail('The lowest field must be raised first: ' . json_encode($step));
}

// A building the blueprint never had is opened in a free slot: the warlord
// order includes a workshop, which the shipped blueprint does not.
$row = npc_empty_row();
$opened = false;
for ($i = 0; $i < 2000 && !$opened; $i++) {
    $step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::WARLORD, 1);
    if ($step === null) {
        break;
    }
    if ($step[1] === NpcArchetypes::GID_WORKSHOP) {
        if ($step[2] !== 1 || (int) $row['f' . $step[0] . 't'] !== 0) {
            npc_fail('The workshop must be opened at level 1 in an empty slot.');
        }
        $opened = true;
    }
    $row['f' . $step[0]]       = $step[2];
    $row['f' . $step[0] . 't'] = $step[1];
}
if (!$opened) {
    npc_fail('The warlord order never opened a workshop.');
}

// A full village of unrelated buildings leaves nowhere to open a new one.
$row = npc_empty_row();
for ($i = 1; $i <= 18; $i++) {
    $row['f' . $i] = 10;
}
for ($slot = 19; $slot <= 39; $slot++) {
    $row['f' . $slot]       = 20;
    $row['f' . $slot . 't'] = 5; // sawmill everywhere: no goal names it
}
$row['f40'] = 20;
$row['f40t'] = $data->wallGid(1);
if (NpcBuildOrder::nextStep($data, $row, NpcArchetypes::WARLORD, 1) !== null) {
    npc_fail('A full village has nothing left to build.');
}
// ...and another tribe's wall in slot 40 is never overwritten.
$row = npc_empty_row();
for ($i = 1; $i <= 18; $i++) {
    $row['f' . $i] = 10;
}
$row['f40'] = 5;
$row['f40t'] = $data->wallGid(2);
for ($i = 0; $i < 2000; $i++) {
    $step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::GARRISON, 1);
    if ($step === null) {
        break;
    }
    if ($step[0] === 40) {
        npc_fail('A Roman must not build over a Teuton wall.');
    }
    $row['f' . $step[0]]       = $step[2];
    $row['f' . $step[0] . 't'] = $step[1];
}

// plan() respects the population budget and the step cap, and its returned row
// reflects every step it took.
$plan = NpcBuildOrder::plan($data, npc_empty_row(), NpcArchetypes::WARLORD, 1, 20, 25);
if (count($plan['steps']) > 25) {
    npc_fail('plan() exceeded its step cap.');
}
if ($plan['pop'] < 20 && count($plan['steps']) < 25) {
    npc_fail('plan() stopped short of both its budget and its cap.');
}
$sum = 0;
foreach ($plan['steps'] as $step) {
    $sum += $step[3];
    if ((int) $plan['row']['f' . $step[0]] < $step[2]) {
        npc_fail('plan() returned a row that does not reflect its own steps.');
    }
}
if ($sum !== $plan['pop']) {
    npc_fail('plan() pop total does not match its steps.');
}
if (NpcBuildOrder::plan($data, npc_empty_row(), NpcArchetypes::WARLORD, 1, 0, 25)['steps'] !== []) {
    npc_fail('A zero budget must plan nothing.');
}
$limited = NpcBuildOrder::plan($data, npc_empty_row(), NpcArchetypes::WARLORD, 1, 100000, 3);
if (count($limited['steps']) !== 3) {
    npc_fail('The step cap must be honoured before the budget.');
}

// The capital, and only the capital, may push its resource fields past 10.
$capitalCap = NpcBuildOrder::villageCap($data, NpcArchetypes::WARLORD, 1, true);
$plainCap   = NpcBuildOrder::villageCap($data, NpcArchetypes::WARLORD, 1, false);
if ($capitalCap <= $plainCap) {
    npc_fail("A capital must saturate higher than a satellite: $capitalCap vs $plainCap");
}
if (NpcBuildOrder::fieldCeiling(false) !== NpcBuildOrder::MAX_FIELD_LEVEL) {
    npc_fail('A satellite must stop at MAX_FIELD_LEVEL.');
}
if (NpcBuildOrder::fieldCeiling(true) !== NpcBuildOrder::CAPITAL_FIELD_LEVEL) {
    npc_fail('A capital must be allowed up to CAPITAL_FIELD_LEVEL.');
}

// Walked to completion, a satellite's fields stop at 10 and a capital's reach
// the capital ceiling - and neither ever asks for a level the ruleset has no
// row for, which would make popOf() silently return zero forever.
foreach ([false, true] as $isCapital) {
    $row   = npc_empty_row();
    $steps = 0;
    while (($step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::WARLORD, 1, $isCapital)) !== null && $steps < 5000) {
        list($slot, $gid, $level) = $step;
        if ($level > NpcBuildOrder::maxLevel($data, $gid, $isCapital)) {
            npc_fail("Asked for level $level of gid $gid, which the ruleset does not define.");
        }
        $row['f' . $slot]       = $level;
        $row['f' . $slot . 't'] = $gid;
        $steps++;
    }
    if ($steps >= 5000) {
        npc_fail('The build order never completed.');
    }

    $ceiling = NpcBuildOrder::fieldCeiling($isCapital);
    for ($field = 1; $field <= 18; $field++) {
        if ((int) $row['f' . $field] !== $ceiling) {
            $what = $isCapital ? 'capital' : 'satellite';
            npc_fail("A finished $what left field $field at " . $row['f' . $field] . ", not $ceiling.");
        }
    }
}

// Every blueprint gains from being the capital, not just the warlord one.
foreach ([NpcArchetypes::FARM, NpcArchetypes::GARRISON, NpcArchetypes::WARLORD] as $archetype) {
    if (NpcBuildOrder::villageCap($data, $archetype, 3, true) <= NpcBuildOrder::villageCap($data, $archetype, 3, false)) {
        npc_fail("The $archetype blueprint gains nothing from being the capital.");
    }
}

// A capital reaches its last field level only after the storage that holds what
// those fields produce, or the extra levels just overflow the warehouse.
$row       = npc_empty_row();
$steps     = 0;
$storeDone = false;
while (($step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::WARLORD, 1, true)) !== null && $steps < 5000) {
    list($slot, $gid, $level) = $step;

    if ($gid === NpcArchetypes::GID_WAREHOUSE && $level >= 20) {
        $storeDone = true;
    }
    if ($slot <= 18 && $level >= NpcBuildOrder::CAPITAL_FIELD_LEVEL && !$storeDone) {
        npc_fail('A capital reached its top field level before its warehouse was done.');
    }

    $row['f' . $slot]       = $level;
    $row['f' . $slot . 't'] = $gid;
    $steps++;
}

// The same invariant across real tile layouts, capital and satellite alike.
// Cropland barely holds any population, so a cropper must come out SMALLER
// than an ordinary valley - that is the whole trade a cropper makes.
$layouts = [
    '4-4-4-6'  => [1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,4,4],
    '3-3-3-9'  => [1,1,1,2,2,2,3,3,3,4,4,4,4,4,4,4,4,4],
    '1-1-1-15' => [1,2,3,4,4,4,4,4,4,4,4,4,4,4,4,4,4,4],
];
$caps = [];
foreach ($layouts as $label => $gids) {
    foreach ([false, true] as $isCapital) {
        $row = npc_empty_row();
        for ($slot = 1; $slot <= 18; $slot++) { $row['f' . $slot . 't'] = $gids[$slot - 1]; }

        $steps = 0;
        while (($step = NpcBuildOrder::nextStep($data, $row, NpcArchetypes::WARLORD, 1, $isCapital)) !== null && $steps < 6000) {
            list($slot, $gid, $level) = $step;
            $row['f' . $slot] = $level;
            $row['f' . $slot . 't'] = $gid;
            $steps++;
        }

        $built = NpcBuildOrder::popOfColumns($data, $row);
        $cap   = NpcBuildOrder::villageCapForRow($data, $row, NpcArchetypes::WARLORD, 1, $isCapital);
        if ($built !== $cap) {
            npc_fail("$label " . ($isCapital ? 'capital' : 'satellite') . ": cap $cap but built $built");
        }
        $caps[$label][$isCapital ? 'c' : 's'] = $cap;
    }
}
foreach (['s', 'c'] as $kind) {
    if (!($caps['1-1-1-15'][$kind] < $caps['3-3-3-9'][$kind] && $caps['3-3-3-9'][$kind] < $caps['4-4-4-6'][$kind])) {
        npc_fail('Cropland holds less population, so a cropper must be the smaller village: ' . json_encode($caps));
    }
}

// The default layout is the ordinary valley, not eighteen fields of one type:
// assuming one type overstated every ceiling in the subsystem.
if (NpcBuildOrder::villageCap($data, NpcArchetypes::WARLORD, 1) !== $caps['4-4-4-6']['s']) {
    npc_fail('villageCap must default to the 4-4-4-6 valley.');
}

// A field slot with no type at all contributes nothing and must not warn.
if (NpcBuildOrder::fieldPop($data, [0, 0, 0], 10) !== 0) {
    npc_fail('A field with no type holds no population.');
}

fwrite(STDOUT, "NpcBuildOrder regression test passed.\n");
