<?php

// A real attack is the only NPC action that can cost the player buildings.
// Every guard must refuse on its own, and the one compliant case must pass.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGameData.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTroopMapping.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArmyRoles.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcDefenceEstimate.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcRealAttackPolicy.php';

use Game\Npc\NpcRealAttackPolicy as P;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$now = 1000000;
$npc = ['tier' => NpcTiers::TOP, 'grudge_hits' => 3, 'grudge_since' => $now - 3600, 'last_raider' => 7, 'last_real_attack' => 0];
$target = ['wref' => 500, 'owner' => 7, 'capital' => 0, 'pop' => 400, 'f99' => 0, 'last_real_hit' => 0];
$fdata = ['f21t' => 10, 'f21' => 8, 'f22t' => 11, 'f22' => 8];
$scouted = $now - 600;

if (P::refusal(true, $npc, $target, $fdata, $scouted, 3.0, $now) !== '') {
    npc_fail('The compliant case must be allowed: ' . P::refusal(true, $npc, $target, $fdata, $scouted, 3.0, $now));
}

$cases = [
    'disabled'           => [false, $npc, $target, $fdata, $scouted, 3.0],
    'tier'               => [true, array_merge($npc, ['tier' => NpcTiers::BUILDER]), $target, $fdata, $scouted, 3.0],
    'grudge'             => [true, array_merge($npc, ['grudge_hits' => 2]), $target, $fdata, $scouted, 3.0],
    'grudge-stale'       => [true, array_merge($npc, ['grudge_since' => $now - 90000]), $target, $fdata, $scouted, 3.0],
    'not-the-culprit'    => [true, array_merge($npc, ['last_raider' => 8]), $target, $fdata, $scouted, 3.0],
    'system-account'     => [true, array_merge($npc, ['last_raider' => 3]), array_merge($target, ['owner' => 3]), $fdata, $scouted, 3.0],
    'capital'            => [true, $npc, array_merge($target, ['capital' => 1]), $fdata, $scouted, 3.0],
    'wonder'             => [true, $npc, array_merge($target, ['f99' => 1]), $fdata, $scouted, 3.0],
    'too-small'          => [true, $npc, array_merge($target, ['pop' => 100]), $fdata, $scouted, 3.0],
    'unscouted'          => [true, $npc, $target, $fdata, 0, 3.0],
    'unscouted-stale'    => [true, $npc, $target, $fdata, $now - 90000, 3.0],
    'unscouted-pending'  => [true, $npc, $target, $fdata, $now + 60, 3.0],
    'ratio'              => [true, $npc, $target, $fdata, $scouted, 2.0],
    'rate-npc'           => [true, array_merge($npc, ['last_real_attack' => $now - 3600]), $target, $fdata, $scouted, 3.0],
    'rate-target'        => [true, $npc, array_merge($target, ['last_real_hit' => $now - 3600]), $fdata, $scouted, 3.0],
    'no-target-building' => [true, $npc, $target, ['f21t' => 15, 'f21' => 10], $scouted, 3.0],
];
foreach ($cases as $name => $args) {
    list($enabled, $n, $t, $f, $s, $r) = $args;
    $why = P::refusal($enabled, $n, $t, $f, $s, $r, $now);
    if ($why === '') {
        npc_fail("Guard '$name' did not refuse.");
    }
    if (P::allows($enabled, $n, $t, $f, $s, $r, $now)) {
        npc_fail("allows() disagreed with refusal() for '$name'.");
    }
}

// Catapults aim at the warehouse, then the granary, never anything else, and
// never at random: a target without either gets none.
if (P::catapultTarget($fdata) !== 10) {
    npc_fail('The warehouse must be the first target.');
}
if (P::catapultTarget(['f22t' => 11, 'f22' => 3]) !== 11) {
    npc_fail('Without a warehouse the granary is the target.');
}
if (P::catapultTarget(['f21t' => 10, 'f21' => 0, 'f25t' => 25, 'f25' => 10]) !== 0) {
    npc_fail('A razed warehouse and a residence must yield no target at all.');
}
if (P::catapultTarget(['f40t' => 10, 'f40' => 5]) !== 0) {
    npc_fail('Only ordinary slots count as buildings.');
}

// Siege is capped, and only siege slots are taken.
$siege = P::siege([1 => 500, 7 => 60, 8 => 90]);
if ($siege !== [7 => P::MAX_RAMS, 8 => P::MAX_CATAPULTS]) {
    npc_fail('Siege must be capped at the maxima: ' . json_encode($siege));
}
if (P::siege([1 => 500]) !== []) {
    npc_fail('No siege units, no siege.');
}
if (P::siege([8 => 5]) !== [8 => 5]) {
    npc_fail('Fewer catapults than the cap go as they are.');
}
if (P::MAX_CATAPULTS > 20) {
    npc_fail('Twenty catapults is the most that can never raze a village above the floor.');
}
if (P::razeFloor() < 50) {
    npc_fail('The raze floor must never drop below 50.');
}

fwrite(STDOUT, "NpcRealAttackPolicy regression test passed.\n");
