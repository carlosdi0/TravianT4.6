<?php

// Traits are the personality layer on top of an archetype. If the table for
// one trait is missing a field the others have, every caller that reads that
// field silently falls back to a different default per trait - a bug nobody
// would notice until one neighbour behaves inexplicably differently.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTraits.php';

use Game\Npc\NpcArchetypes;
use Game\Npc\NpcTiers;
use Game\Npc\NpcTraits;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Every trait must define exactly the same fields as STEADY: a partial
// definition means value() silently mixes in STEADY's number for whatever is
// missing, which is a footgun for the next trait added to the table.
$steadyFields = array_keys(NpcTraits::definition(NpcTraits::STEADY));
foreach (NpcTraits::keys() as $trait) {
    $fields = array_keys(NpcTraits::definition($trait));
    sort($fields);
    $expected = $steadyFields;
    sort($expected);
    if ($fields !== $expected) {
        npc_fail("Trait '$trait' does not match STEADY's field set: " . json_encode($fields));
    }
}

// An account seeded before traits existed, or any other garbage input, must
// behave like a neighbour rather than crash or silently return nothing.
if (NpcTraits::value('made-up-trait', 'cooldown') !== NpcTraits::value(NpcTraits::STEADY, 'cooldown')) {
    npc_fail('An unknown trait must fall back to STEADY.');
}
if (NpcTraits::exists('made-up-trait')) {
    npc_fail('exists() must reject an unknown trait.');
}
if (!NpcTraits::exists(NpcTraits::STEADY)) {
    npc_fail('exists() must accept a real trait.');
}

// Only the raider works a farm list; giving that behaviour to any other
// trait would mean NpcWorld starts consulting a table that trait never fills.
foreach (NpcTraits::keys() as $trait) {
    $expected = ($trait === NpcTraits::RAIDER);
    if (NpcTraits::keepsFarmList($trait) !== $expected) {
        npc_fail("keepsFarmList('$trait') should be " . ($expected ? 'true' : 'false'));
    }
}

// Only the opportunist waits for a target to already be weakened.
foreach (NpcTraits::keys() as $trait) {
    $expected = ($trait === NpcTraits::OPPORTUNIST);
    if (NpcTraits::huntsWeakened($trait) !== $expected) {
        npc_fail("huntsWeakened('$trait') should be " . ($expected ? 'true' : 'false'));
    }
}

// forArchetype() must always hand back something the table recognises,
// whatever the archetype - including one that does not exist yet.
foreach ([NpcArchetypes::FARM, NpcArchetypes::GARRISON, NpcArchetypes::WARLORD, 'unknown-archetype'] as $archetype) {
    $trait = NpcTraits::forArchetype($archetype, 42);
    if (!NpcTraits::exists($trait)) {
        npc_fail("forArchetype('$archetype', 42) returned an unknown trait: $trait");
    }
}

// The pick is cycled by seed, not drawn at random: the same seed must always
// produce the same trait, or a restart would reshuffle every neighbour's
// personality out from under it.
for ($seed = 0; $seed < 20; $seed++) {
    $first  = NpcTraits::forArchetype(NpcArchetypes::WARLORD, $seed);
    $second = NpcTraits::forArchetype(NpcArchetypes::WARLORD, $seed);
    if ($first !== $second) {
        npc_fail("forArchetype is not stable for seed $seed: $first vs $second");
    }
}

// The warlord pool is explicitly weighted towards raiders - it must contain
// at least one, or the farm king this world is built around never appears.
$warlordPool = [];
for ($seed = 0; $seed < 10; $seed++) {
    $warlordPool[] = NpcTraits::forArchetype(NpcArchetypes::WARLORD, $seed);
}
if (!in_array(NpcTraits::RAIDER, $warlordPool, true)) {
    npc_fail('The warlord pool never produced a raider: ' . json_encode($warlordPool));
}

// Pools by tier: a cow is always a turtle, a top pool is mostly raiders, and
// every pick is a known trait for every tier and seed.
foreach (NpcTiers::keys() as $tier) {
    for ($seed = 0; $seed < 12; $seed++) {
        if (!NpcTraits::exists(NpcTraits::forTier($tier, $seed))) {
            npc_fail("forTier('$tier', $seed) returned an unknown trait");
        }
    }
}
for ($seed = 0; $seed < 8; $seed++) {
    if (NpcTraits::forTier(NpcTiers::INACTIVE, $seed) !== NpcTraits::TURTLE) {
        npc_fail('A cow must always be a turtle.');
    }
}
$topPool = [];
for ($seed = 0; $seed < 8; $seed++) {
    $topPool[] = NpcTraits::forTier(NpcTiers::TOP, $seed);
}
if (count(array_keys($topPool, NpcTraits::RAIDER, true)) < 4) {
    npc_fail('Half the top pool must be raiders: ' . json_encode($topPool));
}
if (in_array(NpcTraits::RAIDER, [NpcTraits::forTier(NpcTiers::BUILDER, 0), NpcTraits::forTier(NpcTiers::BUILDER, 1), NpcTraits::forTier(NpcTiers::BUILDER, 2), NpcTraits::forTier(NpcTiers::BUILDER, 3)], true)) {
    npc_fail('A builder never keeps a farm list.');
}
if (!NpcTraits::exists(NpcTraits::forTier('made-up', 3))) {
    npc_fail('An unknown tier must still get a trait.');
}
// The loose cannon is loose, not suicidal.
if (NpcTraits::value(NpcTraits::ERRATIC, 'chaos') > 10) {
    npc_fail('erratic chaos must stay at or below 10 %.');
}

fwrite(STDOUT, "NpcTraits regression test passed.\n");
