<?php

// Tiers decide who plays how much. Whatever the count of neighbours, the world
// has to come out close to the configured split, migration has to be
// deterministic, and a cow has to be recognisable as one from the tier alone.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';

use Game\Npc\NpcArchetypes;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// The split always normalises to 100 and never comes back empty.
$split = NpcTiers::split(null);
if (abs(array_sum($split) - 100.0) > 0.001) {
    npc_fail('Default split does not sum to 100: ' . json_encode($split));
}
$split = NpcTiers::split([NpcTiers::TOP => 0, NpcTiers::BUILDER => 0, NpcTiers::CASUAL => 0, NpcTiers::INACTIVE => 0]);
if ($split !== NpcTiers::split(null)) {
    npc_fail('An all-zero split must fall back to the default.');
}
$split = NpcTiers::split([NpcTiers::TOP => 1, NpcTiers::BUILDER => 1, NpcTiers::CASUAL => 1, NpcTiers::INACTIVE => 1]);
foreach ($split as $tier => $share) {
    if (abs($share - 25.0) > 0.001) {
        npc_fail("Equal weights should give 25% each, got $share for $tier");
    }
}

// Quotas add up exactly and stay within one account of the ideal share.
for ($n = 1; $n <= 60; $n++) {
    $quotas = NpcTiers::quotas($n);
    if (array_sum($quotas) !== $n) {
        npc_fail("quotas($n) sums to " . array_sum($quotas));
    }
    foreach (NpcTiers::DEFAULT_SPLIT as $tier => $pct) {
        $ideal = $n * $pct / 100;
        if (abs($quotas[$tier] - $ideal) > 1.0) {
            npc_fail("quotas($n)[$tier] = {$quotas[$tier]}, ideal $ideal");
        }
    }
    if ($n >= 10 && $quotas[NpcTiers::TOP] < 1) {
        npc_fail("A world of $n must have at least one TOP account.");
    }
}
$quotas = NpcTiers::quotas(50);
if ($quotas !== [NpcTiers::TOP => 5, NpcTiers::BUILDER => 10, NpcTiers::CASUAL => 20, NpcTiers::INACTIVE => 15]) {
    npc_fail('quotas(50) should be 5/10/20/15, got ' . json_encode($quotas));
}

// A fresh world of ten comes out as the canonical 1/2/4/3 and is interleaved,
// not sorted: no three cows in a row at the start.
$fresh = NpcTiers::planSeed([], 10);
if (count($fresh) !== 10) {
    npc_fail('planSeed([], 10) must return ten tiers.');
}
if (array_count_values($fresh) != [NpcTiers::TOP => 1, NpcTiers::BUILDER => 2, NpcTiers::CASUAL => 4, NpcTiers::INACTIVE => 3]) {
    npc_fail('planSeed([], 10) has the wrong mix: ' . json_encode($fresh));
}
if ($fresh[0] === NpcTiers::INACTIVE && $fresh[1] === NpcTiers::INACTIVE) {
    npc_fail('planSeed must interleave the tiers: ' . json_encode($fresh));
}

// Topping up a skewed world: 50 accounts already migrated as 5/10/20/15, add
// 50 more, and the hundred lands exactly on 10/20/40/30.
$existing = [NpcTiers::TOP => 5, NpcTiers::BUILDER => 10, NpcTiers::CASUAL => 20, NpcTiers::INACTIVE => 15];
$batch    = NpcTiers::planSeed($existing, 50);
if (count($batch) !== 50) {
    npc_fail('planSeed for 50 more must return 50 tiers.');
}
$final = $existing;
foreach ($batch as $tier) {
    $final[$tier]++;
}
if ($final != [NpcTiers::TOP => 10, NpcTiers::BUILDER => 20, NpcTiers::CASUAL => 40, NpcTiers::INACTIVE => 30]) {
    npc_fail('Seeding 50 on top of 50 should land on 10/20/40/30, got ' . json_encode($final));
}

// A world already over quota on cows gets no more cows from the batch, and the
// batch is still exactly the requested size.
$batch = NpcTiers::planSeed([NpcTiers::INACTIVE => 40], 10);
if (count($batch) !== 10) {
    npc_fail('planSeed must always return the requested count.');
}
if (in_array(NpcTiers::INACTIVE, $batch, true)) {
    npc_fail('A world drowning in cows must not be given more: ' . json_encode($batch));
}

// Every planned tier is a real tier.
foreach (NpcTiers::planSeed([], 37) as $tier) {
    if (!NpcTiers::exists($tier)) {
        npc_fail("planSeed produced an unknown tier: $tier");
    }
}

// Only INACTIVE sits out of the passes and never refreshes its timestamp.
foreach (NpcTiers::keys() as $tier) {
    $expected = ($tier !== NpcTiers::INACTIVE);
    if (NpcTiers::isActive($tier) !== $expected) {
        npc_fail("isActive('$tier') should be " . ($expected ? 'true' : 'false'));
    }
    if (NpcTiers::refreshesTimestamp($tier) !== $expected) {
        npc_fail("refreshesTimestamp('$tier') should be " . ($expected ? 'true' : 'false'));
    }
}
if (NpcTiers::isActive('made-up') || NpcTiers::exists('made-up')) {
    npc_fail('An unknown tier must be neither active nor known.');
}

// Blueprints per tier: a cow is always a farm, a top is always a warlord, and
// whatever the seed the answer is a known archetype.
for ($seed = 0; $seed < 12; $seed++) {
    if (NpcTiers::archetypeFor(NpcTiers::INACTIVE, $seed) !== NpcArchetypes::FARM) {
        npc_fail('INACTIVE must always be a farm.');
    }
    if (NpcTiers::archetypeFor(NpcTiers::TOP, $seed) !== NpcArchetypes::WARLORD) {
        npc_fail('TOP must always be a warlord.');
    }
    foreach (NpcTiers::keys() as $tier) {
        if (!NpcArchetypes::exists(NpcTiers::archetypeFor($tier, $seed))) {
            npc_fail("archetypeFor('$tier', $seed) is not a known archetype.");
        }
    }
}
if (NpcTiers::archetypeFor('made-up', 3) !== NpcTiers::archetypeFor(NpcTiers::CASUAL, 3)) {
    npc_fail('An unknown tier must be treated as CASUAL for its blueprint.');
}

// Migration of the production shape: 17 farms, 17 garrisons, 16 warlords.
$rows = [];
$uid  = 10;
foreach ([NpcArchetypes::FARM => 17, NpcArchetypes::GARRISON => 17, NpcArchetypes::WARLORD => 16] as $archetype => $count) {
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['uid' => $uid, 'archetype' => $archetype, 'power' => 35 + (($uid * 7) % 51)];
        $uid++;
    }
}
$assigned = NpcTiers::assign($rows);
if (count($assigned) !== 50) {
    npc_fail('assign() must give every row a tier.');
}
$counts = array_count_values($assigned);
if ($counts != [NpcTiers::TOP => 5, NpcTiers::BUILDER => 10, NpcTiers::CASUAL => 20, NpcTiers::INACTIVE => 15]) {
    npc_fail('assign() on the production shape should give 5/10/20/15, got ' . json_encode($counts));
}
$byUid = [];
foreach ($rows as $row) {
    $byUid[$row['uid']] = $row;
}
$topPowers = [];
$warlordPowers = [];
foreach ($assigned as $id => $tier) {
    $row = $byUid[$id];
    if ($tier === NpcTiers::TOP) {
        if ($row['archetype'] !== NpcArchetypes::WARLORD) {
            npc_fail("TOP must come from the warlords when there are enough, uid $id is a {$row['archetype']}");
        }
        $topPowers[] = $row['power'];
    }
    if ($tier === NpcTiers::INACTIVE && $row['archetype'] !== NpcArchetypes::FARM) {
        npc_fail("INACTIVE must come from the farms when there are enough, uid $id is a {$row['archetype']}");
    }
    if ($tier === NpcTiers::BUILDER && $row['archetype'] !== NpcArchetypes::GARRISON) {
        npc_fail("BUILDER must come from the garrisons when there are enough, uid $id is a {$row['archetype']}");
    }
    if ($row['archetype'] === NpcArchetypes::WARLORD) {
        $warlordPowers[] = $row['power'];
    }
}
// The five strongest warlords are the tops.
rsort($warlordPowers);
sort($topPowers);
$strongest = array_slice($warlordPowers, 0, 5);
sort($strongest);
if ($topPowers !== $strongest) {
    npc_fail('TOP should be the strongest warlords: ' . json_encode($topPowers) . ' vs ' . json_encode($strongest));
}
if (NpcTiers::assign($rows) !== $assigned) {
    npc_fail('assign() must be deterministic.');
}
// Order of the result follows the input, so the SQL built from it is stable.
if (array_keys($assigned) !== array_map(function ($row) { return $row['uid']; }, $rows)) {
    npc_fail('assign() must keep the input order.');
}

// Short on a preferred archetype: a world of only farms still gets its tops.
$farms = [];
for ($i = 1; $i <= 20; $i++) {
    $farms[] = ['uid' => $i, 'archetype' => NpcArchetypes::FARM, 'power' => $i * 3];
}
$assigned = NpcTiers::assign($farms);
$counts   = array_count_values($assigned);
if ($counts != [NpcTiers::TOP => 2, NpcTiers::BUILDER => 4, NpcTiers::CASUAL => 8, NpcTiers::INACTIVE => 6]) {
    npc_fail('assign() on 20 farms should still be 2/4/8/6, got ' . json_encode($counts));
}
// ...and the tops are the strongest farms, the cows the weakest.
if ($assigned[20] !== NpcTiers::TOP || $assigned[1] !== NpcTiers::INACTIVE) {
    npc_fail('Leftover filling should give TOP to the strongest and INACTIVE to the weakest.');
}

// Unknown archetypes never fatal and never become TOP.
$odd = [['uid' => 1, 'archetype' => 'mystery', 'power' => 99], ['uid' => 2, 'archetype' => '', 'power' => 1]];
$assigned = NpcTiers::assign($odd);
if (count($assigned) !== 2) {
    npc_fail('assign() must cope with unknown archetypes.');
}
foreach ($assigned as $tier) {
    if (!NpcTiers::exists($tier)) {
        npc_fail("assign() produced an unknown tier: $tier");
    }
}

fwrite(STDOUT, "NpcTiers regression test passed.\n");
