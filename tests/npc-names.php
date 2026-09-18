<?php

// Names are what make a seeded world read as populated instead of as test data,
// so the invariants worth pinning are: a name always comes back, it belongs to
// the tribe that asked, an unknown tribe degrades instead of crashing, and the
// first village of an account keeps the plain name the way the engine does it.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcNames.php';

use Game\Npc\NpcNames;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Every supported tribe has a pool, and it is big enough to seed a
// neighbourhood without immediately falling back to numbered names.
foreach ([1, 2, 3] as $tribe) {
    $size = NpcNames::poolSize($tribe);
    if ($size < 24) {
        npc_fail("Tribe $tribe has only $size names; a seeded world would run out.");
    }
}

// The seed walks the tribe's own list and never decorates the name. This is the
// bug the class documents: deriving a suffix from a world-wide seed numbered
// most of the map while plenty of names were still free.
foreach ([1, 2, 3] as $tribe) {
    $size = NpcNames::poolSize($tribe);
    $seen = [];
    for ($seed = 0; $seed < $size; $seed++) {
        $name = NpcNames::player($tribe, $seed);
        if ($name === '') {
            npc_fail("Tribe $tribe returned an empty name for seed $seed.");
        }
        if (preg_match('/\d/', $name)) {
            npc_fail("Tribe $tribe decorated a name with a number: $name");
        }
        $seen[$name] = TRUE;
    }
    if (count($seen) !== $size) {
        npc_fail("Tribe $tribe repeated a name before exhausting its pool: got " . count($seen) . " of $size.");
    }
}

// Walking past the end of the pool wraps instead of failing.
if (NpcNames::player(1, 0) !== NpcNames::player(1, NpcNames::poolSize(1))) {
    npc_fail('Seeds past the end of the pool must wrap around.');
}

// Tribes are culturally distinct; sharing a pool would defeat the point.
if (NpcNames::player(1, 0) === NpcNames::player(2, 0) || NpcNames::player(2, 0) === NpcNames::player(3, 0)) {
    npc_fail('Different tribes must draw from different name pools.');
}

// An unknown tribe falls back rather than blowing up mid-seed.
$fallback = NpcNames::player(99, 0);
if ($fallback !== NpcNames::player(1, 0)) {
    npc_fail("An unknown tribe must fall back to the first pool, got: $fallback");
}
if (NpcNames::poolSize(99) !== NpcNames::poolSize(1)) {
    npc_fail('poolSize must fall back for an unknown tribe too.');
}

// Negative seeds are clamped, never used as a negative array index.
if (NpcNames::player(1, -5) !== NpcNames::player(1, 0)) {
    npc_fail('A negative seed must clamp to the first name.');
}
if (NpcNames::village(-5, 0) !== NpcNames::village(0, 0)) {
    npc_fail('A negative seed must clamp for villages too.');
}

// The capital keeps the plain name; later villages are numbered from 2, which
// is how the engine names a player's own villages.
$capital = NpcNames::village(7, 0);
if (preg_match('/\d/', $capital)) {
    npc_fail("The first village must keep the plain name, got: $capital");
}
if (NpcNames::village(7, 1) !== $capital . ' 2') {
    npc_fail('The second village must be suffixed with 2, got: ' . NpcNames::village(7, 1));
}
if (NpcNames::village(7, 2) !== $capital . ' 3') {
    npc_fail('The third village must be suffixed with 3, got: ' . NpcNames::village(7, 2));
}

// Same inputs, same output: seeding twice must not rename anybody.
if (NpcNames::player(2, 11) !== NpcNames::player(2, 11) || NpcNames::village(3, 1) !== NpcNames::village(3, 1)) {
    npc_fail('Name selection must be deterministic.');
}

echo "NpcNames regressions passed.\n";
