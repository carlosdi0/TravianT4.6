<?php

// Army ceilings follow the account's own population and its tier. The floor
// keeps a young world alive, the cow gets nothing, loot lifts but never removes
// the ceiling, and the step converges without overshooting.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcBalance.php';

use Game\Npc\NpcBalance;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Monotonic in the account's own population.
$previous = 0;
foreach ([0, 100, 500, 1500, 5000] as $ownPop) {
    $target = NpcBalance::targetArmy($ownPop, NpcTiers::BUILDER, 50);
    if ($target < $previous) {
        npc_fail("targetArmy went down at ownPop $ownPop.");
    }
    // The floor is itself capped by the account's population, so a day-zero
    // world does not open with every two-population village holding fifty men.
    if ($target < min(NpcBalance::MIN_ARMY, $ownPop)) {
        npc_fail("targetArmy fell below the floor at ownPop $ownPop.");
    }
    $previous = $target;
}

// An account with a population behind it still leaves something to raid...
if (NpcBalance::targetArmy(NpcBalance::MIN_ARMY, NpcTiers::CASUAL, 0) !== NpcBalance::MIN_ARMY) {
    npc_fail('An active account must still garrison the minimum.');
}
// ...but a cow gets nothing, floor or no floor: whatever it was seeded with is
// all it ever has, or the floor would quietly regrow every cow's garrison.
if (NpcBalance::targetArmy(5000, NpcTiers::INACTIVE, 100) !== 0) {
    npc_fail('A cow must have an army target of zero.');
}

// The floor has to clear the "stay home" threshold, or an early world is
// completely static: every neighbour sits below the minimum to raid.
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcSeedPlan.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTargeting.php';
if (NpcBalance::MIN_ARMY <= \Game\Npc\NpcTargeting::MIN_GARRISON) {
    npc_fail('MIN_ARMY must exceed NpcTargeting::MIN_GARRISON or nobody ever attacks.');
}

// Power shifts a neighbour by half, it does not decide the fight.
$weak   = NpcBalance::targetArmy(1000, NpcTiers::TOP, 0);
$strong = NpcBalance::targetArmy(1000, NpcTiers::TOP, 100);
if ($weak >= $strong) {
    npc_fail('Power must make a difference.');
}
if ($strong > $weak * 2.1) {
    npc_fail("Power swings too hard: $weak vs $strong.");
}

// Tier ordering: the builder is the densest, the casual the thinnest, and a
// 2000-pop top has an army in the same league as its population.
$casual  = NpcBalance::targetArmy(2000, NpcTiers::CASUAL, 50);
$top     = NpcBalance::targetArmy(2000, NpcTiers::TOP, 50);
$builder = NpcBalance::targetArmy(2000, NpcTiers::BUILDER, 50);
if (!($casual < $top && $top < $builder)) {
    npc_fail("Tiers out of order: $casual / $top / $builder");
}
if ($top < 1500 || $top > 3000) {
    npc_fail("A 2000-pop top should field an army in the thousands, got $top");
}
if (NpcBalance::ratio('made-up') !== NpcBalance::ratio(NpcTiers::CASUAL)) {
    npc_fail('An unknown tier must be treated as CASUAL.');
}

// The ceiling belongs to the ACCOUNT: owning more villages spreads the same
// army thinner, it does not multiply it.
$oneVillage   = NpcBalance::targetArmy(1000, NpcTiers::BUILDER, 50, 1);
$threeVillage = NpcBalance::targetArmy(1000, NpcTiers::BUILDER, 50, 3);
if ($threeVillage >= $oneVillage) {
    npc_fail("More villages must not mean more troops per village: $oneVillage vs $threeVillage");
}
if ($threeVillage * 3 > $oneVillage + 3) {
    npc_fail('Total army across villages must not exceed the single-village ceiling.');
}

// The granary caps what a village can feed.
if (NpcBalance::cropCap(5000, 800) !== 960) {
    npc_fail('cropCap must cap at ARMY_PER_MAXCROP times the granary.');
}
if (NpcBalance::cropCap(500, 800) !== 500) {
    npc_fail('cropCap must not raise a target.');
}
if (NpcBalance::cropCap(500, 0) !== 500) {
    npc_fail('An unknown granary must leave the target alone.');
}

// Steps approach the ceiling without ever passing it, and always advance.
$current = 0;
$target  = NpcBalance::targetArmy(1000, NpcTiers::BUILDER, 50);
for ($pass = 0; $pass < 200 && $current < $target; $pass++) {
    $step = NpcBalance::armyStep($current, $target);
    if ($step <= 0) {
        npc_fail("armyStep stalled at $current of $target.");
    }
    $current += $step;
}
if ($current !== $target) {
    npc_fail("Growth never converged: $current of $target.");
}
if (NpcBalance::armyStep($target + 100, $target) !== 0) {
    npc_fail('An over-strength village must not grow further.');
}

// Distribution keeps the army's shape and loses nothing to rounding.
$added = NpcBalance::distribute([1 => 100, 2 => 300], [], 40);
if (array_sum($added) !== 40) {
    npc_fail('distribute lost units: ' . json_encode($added));
}
if (!isset($added[2]) || $added[2] <= $added[1]) {
    npc_fail('distribute did not keep the army shape: ' . json_encode($added));
}

// A village wiped out by raids rebuilds from its archetype, not from nothing.
$added = NpcBalance::distribute([], [3 => 10], 7);
if (!isset($added[3]) || array_sum($added) !== 7) {
    npc_fail('distribute must fall back to the archetype: ' . json_encode($added));
}
if (NpcBalance::distribute([], [], 10) !== []) {
    npc_fail('distribute must not invent slots out of thin air.');
}

// Loot lifts a raider's ceiling, but the ceiling never disappears.
if (NpcBalance::lootBonus(0, 500) !== 1.0) {
    npc_fail('A neighbour that has looted nothing must get no bonus.');
}
if (NpcBalance::lootBonus(-100, 500) !== 1.0) {
    npc_fail('Negative loot must not shrink a neighbour.');
}
if (NpcBalance::lootBonus(999999999, 500) !== NpcBalance::MAX_LOOT_BONUS) {
    npc_fail('lootBonus must be capped at MAX_LOOT_BONUS.');
}
$small = NpcBalance::lootBonus(300000, 500);
$big   = NpcBalance::lootBonus(300000, 5000);
if ($small <= $big) {
    npc_fail('The same loot must count for less for a bigger account.');
}
if ($small < 1.5) {
    npc_fail("300k of loot on a 500-pop account should be a real bonus, got $small");
}
if (NpcBalance::lootBonus(1000, 0) > NpcBalance::MAX_LOOT_BONUS) {
    npc_fail('An empty account must not produce an infinite bonus.');
}

// And the bonus has to actually reach the army ceiling, capped the same way.
$plain   = NpcBalance::targetArmy(1000, NpcTiers::TOP, 50, 1);
$boosted = NpcBalance::targetArmy(1000, NpcTiers::TOP, 50, 1, 2.0);
if ($boosted !== $plain * 2) {
    npc_fail("A doubled bonus must double the ceiling: $plain vs $boosted");
}
if (NpcBalance::targetArmy(1000, NpcTiers::TOP, 50, 1, 9.0) !== $boosted) {
    npc_fail('targetArmy must clamp the bonus, not trust its caller.');
}

// A newborn account is not handed an army it could not have trained, and any
// account past the floor's worth of population still gets the full floor.
if (NpcBalance::targetArmy(2, NpcTiers::TOP, 50) > 2) {
    npc_fail('A two-population account was handed more units than population.');
}
if (NpcBalance::targetArmy(0, NpcTiers::TOP, 50) !== 0) {
    npc_fail('An empty account must be handed no troops at all.');
}
if (NpcBalance::targetArmy(NpcBalance::MIN_ARMY * 4, NpcTiers::BUILDER, 50) < NpcBalance::MIN_ARMY) {
    npc_fail('A grown account must still get the full garrison floor.');
}

fwrite(STDOUT, "NpcBalance regression test passed.\n");
