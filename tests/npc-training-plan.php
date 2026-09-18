<?php

// Turning a balance decision into training orders is where the tribe-relative
// slots meet the buildings that make them. The rules under test: never order
// from a building that is not standing, never lose units to a missing one, and
// never hand the engine a single order it will take days to drain.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTroopMapping.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTrainingPlan.php';

use Game\Npc\NpcTrainingPlan;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Roman-shaped tables: barracks 1-3, stable 4-6, workshop 7-8.
$roman = [19 => [1, 2, 3], 20 => [4, 5, 6], 21 => [7, 8]];

// A village with everything standing trains exactly what it was asked for.
$orders = NpcTrainingPlan::plan([1 => 30, 5 => 10, 8 => 2], $roman, [19 => 5, 20 => 3, 21 => 1]);
$total = 0;
foreach ($orders as $order) {
    list($gid, $slot, $count) = $order;
    $total += $count;
    if ($slot <= 3 && $gid !== 19) {
        npc_fail("Slot $slot must come from the barracks, got gid $gid");
    }
    if ($slot >= 4 && $slot <= 6 && $gid !== 20) {
        npc_fail("Slot $slot must come from the stable, got gid $gid");
    }
    if ($slot >= 7 && $gid !== 21) {
        npc_fail("Slot $slot must come from the workshop, got gid $gid");
    }
}
if ($total !== 42) {
    npc_fail("A fully built village should train all 42 units, got $total");
}

// Orders come back in slot order, so a batch is reproducible across runs.
$slots = array_map(function ($order) { return $order[1]; }, $orders);
$sorted = $slots;
sort($sorted);
if ($slots !== $sorted) {
    npc_fail('Orders must be in slot order: ' . json_encode($slots));
}

// A building at level 0 is not a building. The units it would have made are
// not lost, they go to the slots that can actually be trained.
$orders = NpcTrainingPlan::plan([1 => 30, 5 => 10], $roman, [19 => 5, 20 => 0]);
if (count($orders) !== 1 || $orders[0][0] !== 19 || $orders[0][1] !== 1) {
    npc_fail('A missing stable must leave only barracks orders: ' . json_encode($orders));
}
if ($orders[0][2] !== 40) {
    npc_fail('The stable share must be reassigned, expected 40 got ' . $orders[0][2]);
}

// Nothing standing at all means nothing ordered, rather than an order the
// engine would accept and never finish.
if (NpcTrainingPlan::plan([1 => 30], $roman, []) !== []) {
    npc_fail('A village with no training buildings must order nothing.');
}
if (NpcTrainingPlan::plan([], $roman, [19 => 5]) !== []) {
    npc_fail('Wanting nothing must order nothing.');
}

// Teutons make four infantry from the barracks; the map is the caller's, not
// an assumption baked in here.
$teuton = [19 => [1, 2, 3, 4], 20 => [5, 6], 21 => [7, 8]];
$map = NpcTrainingPlan::buildingBySlot($teuton, [19 => 3, 20 => 1, 21 => 1]);
if ($map[4] !== 19) {
    npc_fail('Teuton slot 4 belongs to the barracks, got ' . $map[4]);
}

// The great barracks and the barracks both make slot 1; the caller's order
// decides, and the slot is never claimed twice.
$map = NpcTrainingPlan::buildingBySlot([19 => [1, 2, 3], 29 => [1, 2, 3]], [19 => 5, 29 => 3]);
if ($map[1] !== 19) {
    npc_fail('The first building given must win the slot, got ' . $map[1]);
}

// Reassignment keeps the total exactly, whatever the proportions.
foreach ([[7 => 3, 1 => 100], [7 => 1, 1 => 1], [7 => 999, 1 => 1]] as $wanted) {
    $orders = NpcTrainingPlan::plan($wanted, $roman, [19 => 1]);
    $total = 0;
    foreach ($orders as $order) {
        $total += $order[2];
    }
    if ($total !== array_sum($wanted)) {
        npc_fail('Reassignment lost units: ' . json_encode($wanted) . ' => ' . $total);
    }
}

// Batching never loses or invents a unit, and never exceeds the cap.
foreach ([[1, 50], [50, 50], [51, 50], [999, 50], [0, 50]] as $case) {
    list($count, $max) = $case;
    $batches = NpcTrainingPlan::batches($count, $max);
    if (array_sum($batches) !== $count) {
        npc_fail("batches($count, $max) sums to " . array_sum($batches));
    }
    foreach ($batches as $batch) {
        if ($batch > $max || $batch < 1) {
            npc_fail("batches($count, $max) produced $batch");
        }
    }
}

echo "npc-training-plan: ok\n";
