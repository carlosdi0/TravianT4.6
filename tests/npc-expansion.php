<?php

/**
 * Founding is the one NPC action that permanently changes the map, so the rules
 * that bound it are the ones worth pinning down.
 *
 * Two of them are load-bearing:
 *
 *   - A ceiling below the account's seeded village count would describe a world
 *     the engine can never reach, because nothing deletes an NPC village.
 *   - The cost of the next village must keep rising. If it ever flattened, an
 *     account that can pay once can pay forever and the neighbourhood turns
 *     into one empire within a day.
 */

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTraits.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcExpansion.php';

use Game\Npc\NpcExpansion;
use Game\Npc\NpcTiers;
use Game\Npc\NpcTraits;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$active = [NpcTiers::TOP, NpcTiers::BUILDER, NpcTiers::CASUAL];
$traits = NpcTraits::keys();

/* ---- Ceiling stays inside its own bounds ---------------------------- */

foreach ([3, 6, 9, 20] as $hardCap) {
    foreach ($active as $tier) {
        $cap = NpcExpansion::ceiling($hardCap, $tier, NpcExpansion::FLOOR);

        if ($cap > max($hardCap, NpcExpansion::FLOOR)) {
            npc_fail("ceiling($hardCap, $tier) = $cap exceeds the hard cap");
        }
        if ($cap < NpcExpansion::FLOOR) {
            npc_fail("ceiling($hardCap, $tier) = $cap is below the seeded floor");
        }
    }
}

// A world seeded with more villages than the configured cap must not produce a
// ceiling that orders villages to disappear.
if (NpcExpansion::ceiling(3, NpcTiers::TOP, 5) < 5) {
    npc_fail('ceiling ignored a floor above the hard cap');
}

/* ---- Tiers decide how far anyone gets ------------------------------- */

foreach ([6, 9, 12, 20] as $hardCap) {
    $casual  = NpcExpansion::ceiling($hardCap, NpcTiers::CASUAL);
    $builder = NpcExpansion::ceiling($hardCap, NpcTiers::BUILDER);
    $top     = NpcExpansion::ceiling($hardCap, NpcTiers::TOP);

    if (!($casual <= $builder && $builder <= $top)) {
        npc_fail("ceilings out of order at hardCap $hardCap: $casual / $builder / $top");
    }
    // Only while the hard cap leaves room above the casual's own: an admin who
    // clamps the whole world to six villages is asking for the tiers to meet
    // there, and they should.
    if ($hardCap > NpcExpansion::tierCap(NpcTiers::CASUAL) && $top <= $casual) {
        npc_fail("top ceiling at hardCap $hardCap does not beat the casual");
    }
}
// At the shipped cap: top 15, builder 11, casual 6. A cow never founds.
if (NpcExpansion::ceiling(15, NpcTiers::TOP) !== 15 || NpcExpansion::ceiling(15, NpcTiers::BUILDER) !== 11 || NpcExpansion::ceiling(15, NpcTiers::CASUAL) !== 6) {
    npc_fail('default ceilings should be 15 / 11 / 6');
}

// The caps have to be REACHABLE by an ordinary member of the tier, or the cap
// is a number nothing in the world ever gets to. An account can only pay for
// its next village out of what its current ones hold, so the cost of the last
// one has to sit below the ceiling of the ones before it.
//
// Only for the middling trait: a TURTLE is meant to stop short of its tier's
// cap, that is what the trait is for.
$satCap = 926;   // NpcBuildOrder::villageCap(warlord, 4-4-4-6 satellite)
$capCap = 1336;  // ...and its capital
foreach ([NpcTiers::TOP, NpcTiers::BUILDER, NpcTiers::CASUAL] as $tier) {
    $cap     = NpcExpansion::tierCap($tier);
    $ceiling = $capCap + ($cap - 2) * $satCap;   // what it holds BEFORE the last one
    $needed  = NpcExpansion::requiredPop($cap, $tier, NpcTraits::STEADY);
    if ($needed >= $ceiling) {
        npc_fail("A steady $tier can never afford village $cap: needs $needed, holds $ceiling.");
    }
}
if (NpcExpansion::ceiling(9, NpcTiers::INACTIVE) !== 0) {
    npc_fail('a cow must have a ceiling of zero');
}

/* ---- The lead knob puts the player back in charge -------------------- */

foreach ([1, 2] as $lead) {
    foreach ([1, 2, 3, 5, 8] as $playerVillages) {
        foreach ($active as $tier) {
            $cap = NpcExpansion::ceiling(20, $tier, NpcExpansion::FLOOR, $lead, $playerVillages);
            $max = max(NpcExpansion::FLOOR, $playerVillages + $lead);

            if ($cap > $max) {
                npc_fail("with lead $lead a $tier reached $cap against $playerVillages player villages");
            }
        }
    }
}

/* ---- Each village costs more than the last --------------------------- */

foreach ($active as $tier) {
    foreach ($traits as $trait) {
        $previous = -1;
        for ($target = 2; $target <= 12; $target++) {
            $needed = NpcExpansion::requiredPop($target, $tier, $trait);

            if ($needed <= $previous) {
                npc_fail("requiredPop($target, $tier, $trait) = $needed did not rise above $previous");
            }
            $previous = $needed;
        }
    }
}
if (NpcExpansion::requiredPop(2, NpcTiers::INACTIVE, NpcTraits::STEADY) !== PHP_INT_MAX) {
    npc_fail('a cow must never be able to afford a village');
}

/* ---- Reachable: a top pays for its villages at ~3/4 of saturation ---- */

// A village saturates around 600-700 pop (NpcBuildOrder::villageCap), so the
// cost of village n must be payable with (n-1) villages at 80 % of that.
foreach ([2, 3, 5, 9, 12, 15] as $target) {
    $needed = NpcExpansion::requiredPop($target, NpcTiers::TOP, NpcTraits::RAIDER);
    $canPay = ($target - 1) * 0.8 * 650;
    if ($needed > $canPay) {
        npc_fail("village $target costs $needed, more than $canPay a top can have by then");
    }
}
// ...and not so cheap that it founds with hamlets.
if (NpcExpansion::requiredPop(2, NpcTiers::TOP, NpcTraits::RAIDER) < 250) {
    npc_fail('the second village is too cheap');
}

/* ---- Character decides how expensive that is ------------------------- */

foreach ([3, 5, 8] as $target) {
    $casual = NpcExpansion::requiredPop($target, NpcTiers::CASUAL, NpcTraits::TURTLE);
    $top    = NpcExpansion::requiredPop($target, NpcTiers::TOP, NpcTraits::RAIDER);

    if ($casual <= $top) {
        npc_fail("a casual-turtle pays $casual for village $target, no more than a top-raider's $top");
    }

    $turtle = NpcExpansion::requiredPop($target, NpcTiers::TOP, NpcTraits::TURTLE);
    $steady = NpcExpansion::requiredPop($target, NpcTiers::TOP, NpcTraits::STEADY);
    $raider = NpcExpansion::requiredPop($target, NpcTiers::TOP, NpcTraits::RAIDER);

    if (!($turtle > $steady && $steady > $raider)) {
        npc_fail("trait order broken at village $target: $turtle / $steady / $raider");
    }
}

/* ---- The ceiling cannot be bought with population -------------------- */

$limits = ['hardCap' => 9, 'chance' => 100, 'lead' => 0, 'floor' => 3];
$capped = [
    'tier'     => NpcTiers::TOP,
    'trait'    => NpcTraits::RAIDER,
    'power'    => 100,
    'villages' => 9,
    'pop'      => 500000,
];

if (NpcExpansion::shouldExpand($capped, 100000, 50, $limits, 1)) {
    npc_fail('an account at its ceiling expanded anyway');
}

/* ---- The player no longer gates anyone ------------------------------- */

$ready = [
    'tier'     => NpcTiers::TOP,
    'trait'    => NpcTraits::RAIDER,
    'power'    => 100,
    'villages' => 3,
    'pop'      => 5000,
];

if (!NpcExpansion::shouldExpand($ready, 0, 0, $limits, 1)) {
    npc_fail('a fully eligible neighbour must expand whatever the player looks like');
}

/* ---- Cows never found ------------------------------------------------ */

$cow = $ready;
$cow['tier'] = NpcTiers::INACTIVE;
if (NpcExpansion::shouldExpand($cow, 100000, 50, $limits, 1)) {
    npc_fail('a cow founded a village');
}

/* ---- Hamlets do not found empires ------------------------------------ */

$hamlets = [
    'tier'     => NpcTiers::TOP,
    'trait'    => NpcTraits::RAIDER,
    'power'    => 100,
    'villages' => 4,
    'pop'      => 4 * (NpcExpansion::MIN_POP_PER_VILLAGE - 1),
];

if (NpcExpansion::shouldExpand($hamlets, 100000, 50, $limits, 1)) {
    npc_fail('an account of hamlets founded another one');
}

/* ---- The roll is the only randomness --------------------------------- */

if (NpcExpansion::shouldExpand($ready, 5000, 1, $limits, 101)) {
    npc_fail('an impossible roll still expanded');
}
for ($i = 0; $i < 20; $i++) {
    if (!NpcExpansion::shouldExpand($ready, 5000, 1, $limits, 1)) {
        npc_fail('the same inputs and roll gave different answers');
    }
}

/* ---- Saturation: growth converges on the ceiling --------------------- */

foreach ($active as $tier) {
    $villages = 3;
    $pop      = 0;

    for ($pass = 0; $pass < 500; $pass++) {
        $pop += 200;
        $npc  = [
            'tier'     => $tier,
            'trait'    => NpcTraits::STEADY,
            'power'    => 100,
            'villages' => $villages,
            'pop'      => $pop,
        ];
        if (NpcExpansion::shouldExpand($npc, 100000, 50, $limits, 1)) {
            $villages++;
        }
    }

    $cap = NpcExpansion::ceiling($limits['hardCap'], $tier, $limits['floor']);
    if ($villages > $cap) {
        npc_fail("$tier grew to $villages villages, past its ceiling of $cap");
    }
    if ($villages < $cap) {
        npc_fail("$tier stalled at $villages villages with unlimited population, ceiling was $cap");
    }
}

/* ---- Founding does not print troops ---------------------------------- */

foreach ([0, 1, 50, 500, 100000] as $perVillage) {
    $start = NpcExpansion::startArmy($perVillage);

    if ($start < 0) {
        npc_fail("startArmy($perVillage) went negative");
    }
    if ($start > $perVillage) {
        npc_fail("startArmy($perVillage) = $start exceeds the per-village cap");
    }
}

/* ---- Rows written before a rename must not fatal --------------------- */

$junk = [
    'tier'     => '',
    'trait'    => null,
    'villages' => 0,
    'pop'      => -5,
    'power'    => 999,
];

if (NpcExpansion::shouldExpand($junk, 10000, 10, $limits, 1)) {
    npc_fail('an unreadable registry row was allowed to expand');
}
if (NpcExpansion::ceiling(9, '') < NpcExpansion::FLOOR) {
    npc_fail('an unknown tier produced a ceiling below the floor');
}
if (NpcExpansion::appetite('', '') <= 0) {
    npc_fail('an unknown tier produced a non-positive appetite');
}
if (NpcExpansion::startArmy(-10) !== 0) {
    npc_fail('a negative target produced a garrison');
}

fwrite(STDOUT, "NpcExpansion regression test passed.\n");
