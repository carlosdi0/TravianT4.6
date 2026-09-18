<?php

// Unit slots are the easiest thing to get wrong in the NPC subsystem. Here the
// tables are tribe-relative (u1..u10 plus a race column), so what has to be
// pinned is that a wave keeps its slots in order and that a units row is never
// read as more than the tribe's own fighting units.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTroopMapping.php';

use Game\Npc\NpcTroopMapping;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// The slots every tribe shares.
if (NpcTroopMapping::SLOTS_PER_TRIBE !== 10) {
    npc_fail('A tribe has ten unit slots.');
}
if (NpcTroopMapping::SLOT_CONQUEROR !== 9 || NpcTroopMapping::SLOT_SETTLER !== 10) {
    npc_fail('The conqueror is slot 9 and the settler slot 10 in every tribe.');
}

// toAttackSlots() always returns 11 ordered values, hero slot included.
$slots = NpcTroopMapping::toAttackSlots([1 => 60, 3 => 120]);
if (count($slots) !== 11) {
    npc_fail('toAttackSlots must return u1..u10 plus the hero flag.');
}
if ($slots[0] !== 60 || $slots[2] !== 120 || $slots[1] !== 0) {
    npc_fail('toAttackSlots put the units in the wrong positions: ' . json_encode($slots));
}
if ($slots[10] !== 0) {
    npc_fail('NPCs never send a hero, u11 must stay 0.');
}

// Out-of-range slots are ignored rather than shifting the wave.
$slots = NpcTroopMapping::toAttackSlots([0 => 10, 11 => 10, 4 => 7]);
if ($slots[3] !== 7 || array_sum($slots) !== 7) {
    npc_fail('toAttackSlots must ignore slots outside 1..10: ' . json_encode($slots));
}

// A units row is read as fighting units only: no senators, no settlers, and
// nothing from the columns that are not unit slots.
$row = ['race' => 3, 'u1' => 100, 'u5' => 5, 'u9' => 3, 'u10' => 2, 'u11' => 1, 'u99' => 999];
$army = NpcTroopMapping::fightingArmyFromRow($row);
if ($army !== [1 => 100, 5 => 5]) {
    npc_fail('fightingArmyFromRow leaked units: ' . json_encode($army));
}

// An empty garrison is an empty army, not a row of zeroes.
if (NpcTroopMapping::fightingArmyFromRow(['race' => 1, 'u1' => 0, 'u2' => 0]) !== []) {
    npc_fail('An empty village must yield an empty army.');
}

fwrite(STDOUT, "NpcTroopMapping regression test passed.\n");
