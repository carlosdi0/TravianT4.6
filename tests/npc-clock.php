<?php

// The clock is what stops the NPC world from reading as a cron job: sleeping
// hours, jittered cooldowns and burst/rest cycles all live here. Anything
// wrong in this file either makes a neighbour attack in its sleep or makes
// every wave arrive on the same suspiciously round schedule.

date_default_timezone_set('UTC');

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTraits.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcClock.php';

use Game\Npc\NpcClock;
use Game\Npc\NpcTraits;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// sleepStart is derived from the uid, never stored: it must be stable across
// calls and always a real hour of day, for a good spread of neighbours.
for ($uid = 1; $uid <= 50; $uid++) {
    $first  = NpcClock::sleepStart($uid);
    $second = NpcClock::sleepStart($uid);
    if ($first !== $second) {
        npc_fail("sleepStart is not stable for uid $uid.");
    }
    if ($first < 0 || $first > 23) {
        npc_fail("sleepStart($uid) out of range: $first");
    }
}

// sleepHours must stay inside the band the engine promises regardless of trait.
foreach (NpcTraits::keys() as $trait) {
    for ($uid = 1; $uid <= 20; $uid++) {
        $hours = NpcClock::sleepHours($uid, $trait);
        if ($hours < 3 || $hours > 12) {
            npc_fail("sleepHours($uid, '$trait') out of band: $hours");
        }
    }
}

// The window itself: asleep the instant it opens, awake the instant it is
// due to close. Both edges matter because the window wraps midnight for
// most neighbours (21:00-05:00 territory).
$uid   = 7;
$trait = NpcTraits::STEADY;
$start = NpcClock::sleepStart($uid);
$hours = NpcClock::sleepHours($uid, $trait);

$base        = mktime(0, 0, 0, 6, 15, 2026); // arbitrary fixed date; only the hour matters
$sleepBegins = $base + ($start * NpcClock::HOUR);
if (!NpcClock::isAsleep($uid, $sleepBegins, $trait)) {
    npc_fail('A neighbour must be asleep the instant its window opens.');
}
$sleepEnds = $sleepBegins + ($hours * NpcClock::HOUR);
if (NpcClock::isAsleep($uid, $sleepEnds, $trait)) {
    npc_fail('A neighbour must be awake the instant its window is due to end.');
}

// wakeAt(): untouched while already awake; pushed strictly into the future
// while asleep, and never still inside the window on arrival.
if (NpcClock::wakeAt($uid, $sleepEnds, $trait) !== $sleepEnds) {
    npc_fail('wakeAt must return the timestamp untouched when already awake.');
}
$woken = NpcClock::wakeAt($uid, $sleepBegins, $trait);
if ($woken <= $sleepBegins) {
    npc_fail('wakeAt must push a sleeping neighbour strictly into the future.');
}
if (NpcClock::isAsleep($uid, $woken, $trait)) {
    npc_fail('wakeAt must never land back inside its own sleeping window.');
}

// jitter(): roll 50 is the untouched midpoint; 0 and 100 are the -/+ JITTER_PCT
// ends, and it must never invent a negative delay.
if (NpcClock::jitter(1000, 50) !== 1000) {
    npc_fail('jitter(1000, 50) must leave the delay unchanged.');
}
if (NpcClock::jitter(1000, 0) >= 1000) {
    npc_fail('jitter(1000, 0) must reduce the delay.');
}
if (NpcClock::jitter(1000, 100) <= 1000) {
    npc_fail('jitter(1000, 100) must increase the delay.');
}
foreach ([0, 10, 50, 90, 100] as $roll) {
    if (NpcClock::jitter(1000, $roll) < 0) {
        npc_fail("jitter must never go negative (roll $roll).");
    }
}

// nextAttack(): must never fire in the past, and a burst that is spent (0
// left) really does rest at least as long as one still owed a wave - the
// whole reason bursts read as "several waves, then quiet" rather than a
// single fixed interval.
$raiderHours = NpcClock::sleepHours($uid, NpcTraits::RAIDER);
$now         = $base + ((($start + $raiderHours + 2) % 24) * NpcClock::HOUR); // solidly inside the awake stretch
if (NpcClock::isAsleep($uid, $now, NpcTraits::RAIDER)) {
    npc_fail('Test setup error: the chosen "now" must fall inside the awake window.');
}
$next = NpcClock::nextAttack($now, 3600, NpcTraits::RAIDER, 1, $uid, 50);
if ($next <= $now) {
    npc_fail('nextAttack must never fire in the past.');
}
$spentBurst  = NpcClock::nextAttack($now, 3600, NpcTraits::RAIDER, 0, $uid, 50);
$withinBurst = NpcClock::nextAttack($now, 3600, NpcTraits::RAIDER, 1, $uid, 50);
if ($spentBurst < $withinBurst) {
    npc_fail("A spent burst must rest at least as long as a wave still owed: $spentBurst vs $withinBurst");
}

// isChaotic(): a trait with no chaos chance can never roll badly; erratic can.
if (NpcClock::isChaotic(NpcTraits::STEADY, 1)) {
    npc_fail('A trait with chaos=0 must never be chaotic.');
}
if (!NpcClock::isChaotic(NpcTraits::ERRATIC, 1)) {
    npc_fail('erratic must be able to roll chaotic on a roll of 1.');
}

fwrite(STDOUT, "NpcClock regression test passed.\n");
