<?php

// A raider's farm list only converges on good targets if the scoring actually
// rewards profit and punishes losses, and if burnt/resting farms genuinely
// drop out of consideration. Get any of this wrong and the "farm king" trait
// degrades back into hitting whatever is nearest, which is the one thing this
// class exists to avoid.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcFarmList.php';

use Game\Npc\NpcFarmList;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// An untested farm gets the optimistic score, decayed by distance: a raider
// must prefer trying a close unknown over a far one.
$nearUntested = NpcFarmList::score(['hits' => 0], 5);
$farUntested  = NpcFarmList::score(['hits' => 0], 50);
if ($nearUntested !== NpcFarmList::UNTESTED / 1.5) {
    npc_fail("Untested score formula drifted: $nearUntested");
}
if ($nearUntested <= $farUntested) {
    npc_fail('A closer untested farm must score higher than a distant one.');
}

// A farm that pays for itself must beat one that merely exists.
$profitable = NpcFarmList::score(['hits' => 5, 'loot' => 5000, 'losses' => 0], 10);
$ruinous    = NpcFarmList::score(['hits' => 5, 'loot' => 500, 'losses' => 4], 10);
if ($profitable <= $ruinous) {
    npc_fail("A profitable farm must outscore a ruinous one: $profitable vs $ruinous");
}

// A farm that is bleeding the raider dry must score negative, not just low,
// so pick() actively refuses it instead of treating it as a last resort.
$bleeding = NpcFarmList::score(['hits' => 2, 'loot' => 0, 'losses' => 3], 5);
if ($bleeding >= 0) {
    npc_fail("A farm costing more in troops than it pays must score negative: $bleeding");
}

// Availability: resting after a hit, or written off after a burn, both mean
// "leave it alone", each for its own reason.
$now = 100000;
if (NpcFarmList::isAvailable(['burnt_until' => $now + 500, 'last_hit' => 0], $now)) {
    npc_fail('A farm burnt until the future must not be available.');
}
if (NpcFarmList::isAvailable(['burnt_until' => 0, 'last_hit' => $now - 100], $now)) {
    npc_fail('A farm hit moments ago must still be resting.');
}
if (!NpcFarmList::isAvailable(['burnt_until' => 0, 'last_hit' => $now - 2000], $now)) {
    npc_fail('A farm last hit well past REVISIT must be available again.');
}
if (!NpcFarmList::isAvailable(['burnt_until' => 0], $now)) {
    npc_fail('A farm never hit before must be available.');
}

// pick(): the best score among the available farms wins, burnt farms are
// skipped no matter how good they would otherwise look, and a list with
// nothing worth a trip returns null rather than the least-bad option.
$farms = [
    ['id' => 1, 'hits' => 0, 'distance' => 5, 'burnt_until' => 0, 'last_hit' => 0], // untested, decent
    ['id' => 2, 'hits' => 5, 'loot' => 5000, 'losses' => 0, 'distance' => 10, 'burnt_until' => 0, 'last_hit' => 0],
    ['id' => 3, 'hits' => 5, 'loot' => 50000, 'losses' => 0, 'distance' => 1, 'burnt_until' => 0, 'last_hit' => 0], // best
    ['id' => 4, 'hits' => 5, 'loot' => 90000, 'losses' => 0, 'distance' => 1, 'burnt_until' => $now + 9999, 'last_hit' => 0], // burnt: would win but must be skipped
];
$best = NpcFarmList::pick($farms, $now);
if (!$best || (int) $best['id'] !== 3) {
    npc_fail('pick() did not choose the best available farm: ' . json_encode($best));
}

$allBad = [
    ['id' => 5, 'hits' => 2, 'loot' => 0, 'losses' => 3, 'distance' => 1, 'burnt_until' => 0, 'last_hit' => 0],
];
if (NpcFarmList::pick($allBad, $now) !== null) {
    npc_fail('pick() must return null when every farm scores at or below zero.');
}
if (NpcFarmList::pick([], $now) !== null) {
    npc_fail('pick() must return null for an empty list.');
}

// shouldExplore(): a short list always prospects, whatever the roll; a
// healthy list only does so within EXPLORE_PCT.
foreach ([0, 1, 2] as $known) {
    if (!NpcFarmList::shouldExplore($known, 100)) {
        npc_fail("A list of only $known farms must always explore, even on a bad roll.");
    }
}
if (NpcFarmList::shouldExplore(3, 100)) {
    npc_fail('A healthy list must not explore on a roll above EXPLORE_PCT.');
}
if (NpcFarmList::shouldExplore(10, 100)) {
    npc_fail('A large list must not explore on a roll above EXPLORE_PCT.');
}

// shouldBurn(): a defended village (real troop losses) and a drained one
// (nothing to loot) both get written off; a clean, profitable raid does not.
if (!NpcFarmList::shouldBurn(1000, 3, 10)) {
    npc_fail('Losing a quarter of the wave or more must burn the farm.');
}
if (!NpcFarmList::shouldBurn(0, 1, 100)) {
    npc_fail('An empty village that still costs a unit must burn the farm.');
}
if (NpcFarmList::shouldBurn(1000, 0, 50)) {
    npc_fail('A clean, profitable raid must not burn the farm.');
}

// A farm the scouts have not reached yet is neither available nor tested; once
// they have arrived (or it was ever hit) it is.
$scouting = ['hits' => 0, 'loot' => 0, 'losses' => 0, 'last_hit' => 0, 'burnt_until' => 0, 'scouted_at' => 5000];
if (!NpcFarmList::awaitingScout($scouting, 4000)) {
    npc_fail('A farm whose scouts are still flying must be awaiting them.');
}
if (NpcFarmList::isAvailable($scouting, 4000)) {
    npc_fail('A farm still being scouted must not be raided.');
}
if (NpcFarmList::awaitingScout($scouting, 5000) || !NpcFarmList::isAvailable($scouting, 5000)) {
    npc_fail('Once the scouts have arrived the farm is fair game.');
}
$hit = $scouting;
$hit['hits'] = 1;
if (NpcFarmList::awaitingScout($hit, 4000)) {
    npc_fail('A farm that has already been hit is never "awaiting scouts".');
}
if (NpcFarmList::score($scouting, 3) !== NpcFarmList::score(['hits' => 0], 3)) {
    npc_fail('A scouted-only farm scores as untested.');
}

fwrite(STDOUT, "NpcFarmList regression test passed.\n");
