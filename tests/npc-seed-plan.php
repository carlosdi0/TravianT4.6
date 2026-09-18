<?php

// Neighbour placement: candidates must stay on the map, inside the requested
// ring, and be ordered from the player outwards.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTraits.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGrowthCurve.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcExpansion.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcSeedPlan.php';

use Game\Npc\NpcSeedPlan;
use Game\Npc\NpcTiers;
use Game\Npc\NpcTraits;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$worldMax = 25;

// Bounding box is clamped to the map.
list($minX, $maxX, $minY, $maxY) = NpcSeedPlan::boundingBox(20, -20, 15, $worldMax);
if ($maxX !== 25 || $minY !== -25) {
    npc_fail("boundingBox left the map: $minX..$maxX / $minY..$maxY");
}

// Chebyshev distance: five squares diagonally is five away, not ten.
if (NpcSeedPlan::distance(0, 0, 5, 5) !== 5) {
    npc_fail('distance must be Chebyshev.');
}

$ring = NpcSeedPlan::ring(0, 0, 5, 10, $worldMax);
if (!$ring) {
    npc_fail('ring returned nothing.');
}

$seen = [];
$previous = 0;
foreach ($ring as $square) {
    list($x, $y) = $square;

    $distance = NpcSeedPlan::distance(0, 0, $x, $y);
    if ($distance < 5 || $distance > 10) {
        npc_fail("ring returned ($x|$y) at distance $distance, outside 5..10.");
    }
    if (abs($x) > $worldMax || abs($y) > $worldMax) {
        npc_fail("ring returned ($x|$y), off the map.");
    }

    $key = $x . '|' . $y;
    if (isset($seen[$key])) {
        npc_fail("ring returned ($x|$y) twice.");
    }
    $seen[$key] = true;

    // Closest first, so the neighbourhood fills from the player outwards.
    if ($distance < $previous) {
        npc_fail('ring is not ordered by distance.');
    }
    $previous = $distance;
}

// A ring near the edge must not wrap around to the other side of the map.
$edge = NpcSeedPlan::ring(24, 24, 1, 5, $worldMax);
foreach ($edge as $square) {
    if ($square[0] < 19 || $square[1] < 19) {
        npc_fail('ring wrapped around the map edge: ' . json_encode($square));
    }
}

// A world now starts the way a real one does, so a single village is a legal
// answer. Anything outside the requested band is not.
for ($i = 0; $i < 20; $i++) {
    $count = NpcSeedPlan::villagesFor(1, 3);
    if ($count < 1 || $count > 3) {
        npc_fail("villagesFor returned $count.");
    }
}
if (NpcSeedPlan::villagesFor(0, 0) !== 1) {
    npc_fail('villagesFor must floor at one village, never zero.');
}

// The layout roll is gone on purpose: a village is built from the layout of the
// wdata tile it stands on (NpcTerrain), so seeding cannot invent one any more.
if (method_exists(NpcSeedPlan::class, 'villageType')) {
    npc_fail('villageType is back; the map tile decides the layout now.');
}

// Seed state: at day zero every account is one empty village, exactly like the
// player's, whatever its tier.
foreach ([NpcTiers::TOP, NpcTiers::BUILDER, NpcTiers::CASUAL, NpcTiers::INACTIVE] as $tier) {
    $state = NpcSeedPlan::seedState(0, $tier, NpcTraits::STEADY, 50, 700, 1100, 9);
    if ($state['villages'] !== 1) {
        npc_fail("A day-zero $tier was seeded with {$state['villages']} villages.");
    }
    if ($state['pop'] > 2) {
        npc_fail("A day-zero $tier was seeded with {$state['pop']} population.");
    }
}

// And it grows from there: more game days never means fewer villages or less
// population, and a top account outgrows a casual at the same age.
$previousVillages = 0;
$previousPop      = 0;
foreach ([0, 7, 21, 60, 120] as $days) {
    $state = NpcSeedPlan::seedState($days, NpcTiers::TOP, NpcTraits::STEADY, 50, 700, 1100, 9);
    if ($state['villages'] < $previousVillages || $state['pop'] < $previousPop) {
        npc_fail("seedState went backwards at day $days.");
    }
    if ($state['villages'] > 9) {
        npc_fail("seedState passed the hard cap at day $days: {$state['villages']}.");
    }
    $previousVillages = $state['villages'];
    $previousPop      = $state['pop'];
}
if ($previousVillages < 2) {
    npc_fail('A top account 120 game days old should own more than one village.');
}

$top    = NpcSeedPlan::seedState(60, NpcTiers::TOP, NpcTraits::STEADY, 50, 700, 1100, 9);
$casual = NpcSeedPlan::seedState(60, NpcTiers::CASUAL, NpcTraits::STEADY, 50, 700, 1100, 9);
if ($casual['pop'] >= $top['pop']) {
    npc_fail('A casual must not be seeded as big as a top account of the same age.');
}

// The population split hands the capital the most, loses nothing to rounding,
// and degenerates cleanly to a single village.
foreach ([[0, 1], [2, 1], [1000, 1], [1000, 3], [4321, 7]] as $case) {
    list($pop, $villages) = $case;
    $shares = NpcSeedPlan::popShares($pop, $villages);
    if (count($shares) !== $villages) {
        npc_fail("popShares($pop, $villages) returned " . count($shares) . ' shares.');
    }
    if (array_sum($shares) !== $pop) {
        npc_fail("popShares($pop, $villages) lost population to rounding.");
    }
    foreach ($shares as $share) {
        if ($share < 0) {
            npc_fail("popShares($pop, $villages) produced a negative share.");
        }
    }
    if ($villages > 1 && $pop > 0 && $shares[0] <= $shares[1]) {
        npc_fail("popShares($pop, $villages) did not give the capital the largest share.");
    }
}

fwrite(STDOUT, "NpcSeedPlan regression test passed.\n");
