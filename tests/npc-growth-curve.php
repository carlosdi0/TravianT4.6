<?php

// The growth curve is what replaced "everything scales with the player". It
// has to be monotonic, speed-aware, capped by what villages can hold, and the
// per-pass step has to converge without ever stalling or overshooting.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGrowthCurve.php';

use Game\Npc\NpcGrowthCurve;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Reference points of a top account on a x1 server, within 10 %.
foreach ([1 => 90, 7 => 645, 14 => 1320, 30 => 2970, 60 => 6480] as $day => $expected) {
    $pop = NpcGrowthCurve::curve($day);
    if (abs($pop - $expected) > $expected * 0.10) {
        npc_fail("curve($day) = $pop, expected about $expected");
    }
}

// Monotonic, and zero at zero.
if (NpcGrowthCurve::curve(0) != 0) {
    npc_fail('The curve must start at zero.');
}
$previous = -1;
for ($t = 0; $t <= 120; $t++) {
    $pop = NpcGrowthCurve::curve($t);
    if ($pop <= $previous) {
        npc_fail("curve is not increasing at day $t");
    }
    $previous = $pop;
}

// Game days scale with SPEED: two real days on x10 are twenty game days.
if (abs(NpcGrowthCurve::gameDays(2 * 86400, 10) - 20.0) > 0.001) {
    npc_fail('gameDays must scale by SPEED.');
}
if (NpcGrowthCurve::gameDays(-5, 1) != 0) {
    npc_fail('A negative age must read as zero.');
}

// The inverse really inverts, so back-dating a seeded account lands it exactly
// on its own population.
foreach ([1, 50, 600, 1920, 6480, 50000] as $pop) {
    $t = NpcGrowthCurve::ageForPop($pop);
    if (abs(NpcGrowthCurve::curve($t) - $pop) > 0.01) {
        npc_fail("ageForPop($pop) = $t does not invert the curve.");
    }
}
foreach (NpcTiers::keys() as $tier) {
    if ($tier === NpcTiers::INACTIVE) {
        continue;
    }
    $t   = NpcGrowthCurve::ageForTarget(600, $tier, 70);
    $got = NpcGrowthCurve::targetPop($t, $tier, 70, 3, 100000);
    if (abs($got - 600) > 1) {
        npc_fail("ageForTarget for $tier landed on $got instead of 600");
    }
}

// Tier factors: top and builder full, casual half, inactive nothing.
if (NpcGrowthCurve::tierFactor(NpcTiers::TOP) != 1.0 || NpcGrowthCurve::tierFactor(NpcTiers::BUILDER) != 1.0) {
    npc_fail('TOP and BUILDER must walk the curve in full.');
}
if (NpcGrowthCurve::tierFactor(NpcTiers::CASUAL) >= 1.0 || NpcGrowthCurve::tierFactor(NpcTiers::CASUAL) <= 0) {
    npc_fail('CASUAL must walk the curve at a fraction.');
}
if (NpcGrowthCurve::tierFactor(NpcTiers::INACTIVE) != 0.0) {
    npc_fail('INACTIVE must not grow at all.');
}
if (NpcGrowthCurve::targetPop(30, NpcTiers::INACTIVE, 100, 3, 1000) !== 0) {
    npc_fail('A cow has no target population.');
}

// Power moves the target a quarter either way and no more.
$low  = NpcGrowthCurve::targetPop(14, NpcTiers::TOP, 0, 9, 100000);
$mid  = NpcGrowthCurve::targetPop(14, NpcTiers::TOP, 50, 9, 100000);
$high = NpcGrowthCurve::targetPop(14, NpcTiers::TOP, 100, 9, 100000);
if (!($low < $mid && $mid < $high)) {
    npc_fail("Power must order the targets: $low / $mid / $high");
}
if ($high > $low * 1.7 || $high < $low * 1.6) {
    npc_fail("Power swing out of range: $low vs $high");
}

// A village can only hold so much: the target is capped by villages x cap.
if (NpcGrowthCurve::targetPop(60, NpcTiers::TOP, 100, 2, 700) !== 1400) {
    npc_fail('targetPop must be capped at villages x villageCap.');
}
if (NpcGrowthCurve::targetPop(1, NpcTiers::TOP, 50, 9, 700) > 200) {
    npc_fail('A young account must not be handed the full cap.');
}

// The soft cap only bites when asked.
if (NpcGrowthCurve::softCap(5000, 300, 0) !== 5000) {
    npc_fail('softCap with lead 0 must be a no-op.');
}
if (NpcGrowthCurve::softCap(5000, 300, 2) !== 600) {
    npc_fail('softCap with lead 2 must cap at twice the player.');
}
if (NpcGrowthCurve::softCap(500, 300, 2) !== 500) {
    npc_fail('softCap must not raise a target.');
}

// The step: zero at or above target, never below the minimum, never past the
// deficit, and it closes a 1300 backlog to under 5 % within 25 passes.
if (NpcGrowthCurve::stepPop(0) !== 0 || NpcGrowthCurve::stepPop(-50) !== 0) {
    npc_fail('No deficit, no step.');
}
if (NpcGrowthCurve::stepPop(3) !== 3) {
    npc_fail('A step must never exceed the deficit.');
}
if (NpcGrowthCurve::stepPop(20) !== NpcGrowthCurve::MIN_STEP) {
    npc_fail('A small deficit must still move by the minimum step.');
}
$deficit = 1300;
for ($pass = 0; $pass < 25; $pass++) {
    $deficit -= NpcGrowthCurve::stepPop($deficit);
}
if ($deficit > 65) {
    npc_fail("Backlog did not close: $deficit left after 25 passes");
}
if ($deficit < 0) {
    npc_fail('The step overshot the target.');
}
// Custom pace from config is honoured and clamped.
if (NpcGrowthCurve::stepPop(1000, 50, 1) !== 500) {
    npc_fail('stepPop must honour the configured percentage.');
}
if (NpcGrowthCurve::stepPop(1000, 500, 1) !== 1000) {
    npc_fail('stepPop must clamp a silly percentage to the deficit.');
}

// The account's ceiling is ONE capital plus the satellites, not the satellite
// cap multiplied out: a cropper capital that saturates higher than its
// satellites must not be frozen at the satellite figure.
$plain   = NpcGrowthCurve::targetPop(365, NpcTiers::TOP, 100, 4, 700);
$withCap = NpcGrowthCurve::targetPop(365, NpcTiers::TOP, 100, 4, 700, 1200);
if ($withCap !== $plain + 500) {
    npc_fail("A bigger capital must lift the account ceiling by exactly its excess: $plain vs $withCap");
}
if (NpcGrowthCurve::targetPop(365, NpcTiers::TOP, 100, 1, 700, 1200) !== 1200) {
    npc_fail('A single-village account is all capital.');
}
// 0 keeps the old meaning for every caller that does not care.
if (NpcGrowthCurve::targetPop(365, NpcTiers::TOP, 100, 4, 700, 0) !== $plain) {
    npc_fail('capitalCap 0 must leave the old ceiling untouched.');
}
// And the curve, not the ceiling, is still what binds early on.
if (NpcGrowthCurve::targetPop(1, NpcTiers::TOP, 50, 1, 700, 1200) > 200) {
    npc_fail('A one-day-old account must be held by its curve, not by its capital.');
}

fwrite(STDOUT, "NpcGrowthCurve regression test passed.\n");
