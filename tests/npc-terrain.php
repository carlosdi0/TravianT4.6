<?php

// The map tile decides the village, and only a top account goes out of its way
// for a cropper.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTerrain.php';

use Game\Npc\NpcTerrain;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// An oasis is never a valley; every layout Database::addResourceFields() can
// build is.
if (NpcTerrain::isValley(NpcTerrain::OASIS)) {
    npc_fail('An oasis must never be settled.');
}
for ($fieldtype = 1; $fieldtype <= 12; $fieldtype++) {
    if (!NpcTerrain::isValley($fieldtype)) {
        npc_fail("fieldtype $fieldtype must be settleable; addResourceFields builds it.");
    }
}

// Only the nine and the fifteen are croppers.
foreach ([1, 6] as $fieldtype) {
    if (!NpcTerrain::isCropper($fieldtype)) {
        npc_fail("fieldtype $fieldtype is a cropper.");
    }
}
foreach ([0, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12] as $fieldtype) {
    if (NpcTerrain::isCropper($fieldtype)) {
        npc_fail("fieldtype $fieldtype is not a cropper.");
    }
}

// Only the top tier hunts.
if (!NpcTerrain::hunts(NpcTiers::TOP)) {
    npc_fail('The top tier must hunt croppers.');
}
foreach ([NpcTiers::BUILDER, NpcTiers::CASUAL, NpcTiers::INACTIVE, ''] as $tier) {
    if (NpcTerrain::hunts($tier)) {
        npc_fail("Tier '$tier' must take whatever valley is closest.");
    }
}

// A fifteen beats a nine beats anything else, and only for a hunter.
if (NpcTerrain::score(6, NpcTiers::TOP) <= NpcTerrain::score(1, NpcTiers::TOP)) {
    npc_fail('A fifteen-cropper must outrank a nine.');
}
if (NpcTerrain::score(1, NpcTiers::TOP) <= NpcTerrain::score(3, NpcTiers::TOP)) {
    npc_fail('A nine-cropper must outrank an ordinary valley.');
}
if (NpcTerrain::score(6, NpcTiers::CASUAL) !== 0) {
    npc_fail('A casual must not score a cropper above anything else.');
}

// rank(): the hunter's fifteen comes first, the nine second, and everything
// else keeps the order the caller chose. A non-hunter's list never moves.
$pool = [
    ['id' => 1, 'fieldtype' => 3],
    ['id' => 2, 'fieldtype' => 1],
    ['id' => 3, 'fieldtype' => 5],
    ['id' => 4, 'fieldtype' => 6],
    ['id' => 5, 'fieldtype' => 3],
];

$hunted = NpcTerrain::rank($pool, NpcTiers::TOP);
if (array_column($hunted, 'id') !== [4, 2, 1, 3, 5]) {
    npc_fail('rank did not put the croppers first, stably: ' . json_encode(array_column($hunted, 'id')));
}

if (NpcTerrain::rank($pool, NpcTiers::CASUAL) !== $pool) {
    npc_fail('rank must leave a non-hunter\'s order completely alone.');
}
if (NpcTerrain::rank([], NpcTiers::TOP) !== []) {
    npc_fail('rank must survive an empty pool.');
}

// A hunter with no cropper in range keeps the distance order it was given.
$plain = [['id' => 7, 'fieldtype' => 3], ['id' => 8, 'fieldtype' => 4], ['id' => 9, 'fieldtype' => 2]];
if (array_column(NpcTerrain::rank($plain, NpcTiers::TOP), 'id') !== [7, 8, 9]) {
    npc_fail('With no cropper in range the closest valley must still win.');
}

// A row with no layout at all must not be treated as a cropper.
$missing = NpcTerrain::rank([['id' => 1], ['id' => 2, 'fieldtype' => 6]], NpcTiers::TOP);
if ((int) $missing[0]['id'] !== 2) {
    npc_fail('A row without a fieldtype must rank below a real cropper.');
}

fwrite(STDOUT, "NpcTerrain regression test passed.\n");
