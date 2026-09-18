<?php

// NPC raids are the only part of this subsystem that can ruin someone's game,
// so the guards are pinned here: protection is honoured, villages are never
// emptied, and nobody is attacked from the other side of the world.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcSeedPlan.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTargeting.php';

use Game\Npc\NpcTargeting;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$now = 1000000;

// Beginners' protection is enforced HERE: nothing in the battle pipeline
// checks the defender's protect when a wave lands.
$protected = ['access' => 2, 'protect' => $now + 3600, 'vac_mode' => 0];
if (NpcTargeting::isRaidable($protected, $now)) {
    npc_fail('A protected player must never be raided.');
}

$expired = ['access' => 2, 'protect' => $now - 1, 'vac_mode' => 0];
if (!NpcTargeting::isRaidable($expired, $now)) {
    npc_fail('Expired protection must not shield forever.');
}

if (NpcTargeting::isRaidable(['access' => 2, 'protect' => 0, 'vac_mode' => 1], $now)) {
    npc_fail('Holiday mode must be respected.');
}
foreach ([0, 8, 9] as $access) {
    if (NpcTargeting::isRaidable(['access' => $access, 'protect' => 0, 'vac_mode' => 0], $now)) {
        npc_fail("Access level $access must be left alone.");
    }
}

// Cooldown.
if (NpcTargeting::isReady(['last_attack' => $now - 100], $now, 7200)) {
    npc_fail('An NPC on cooldown must not attack.');
}
if (!NpcTargeting::isReady(['last_attack' => $now - 8000], $now, 7200)) {
    npc_fail('An NPC off cooldown must be able to attack.');
}

// Targeting: nearest raidable village, never beyond reach, never itself.
$villages = [
    ['wref' => 1, 'x' => 40, 'y' => 0,  'access' => 2, 'protect' => 0, 'vac_mode' => 0], // too far
    ['wref' => 2, 'x' => 3,  'y' => 0,  'access' => 2, 'protect' => $now + 60, 'vac_mode' => 0], // protected
    ['wref' => 3, 'x' => 6,  'y' => 0,  'access' => 2, 'protect' => 0, 'vac_mode' => 0], // the answer
    ['wref' => 4, 'x' => 9,  'y' => 0,  'access' => 2, 'protect' => 0, 'vac_mode' => 0],
];
$target = NpcTargeting::pickTarget($villages, 0, 0, 20, $now);
if (!$target || (int) $target['wref'] !== 3) {
    npc_fail('pickTarget chose the wrong village: ' . json_encode($target));
}

$none = NpcTargeting::pickTarget($villages, 0, 0, 2, $now);
if ($none !== null) {
    npc_fail('Nothing within reach must mean no attack.');
}

$self = NpcTargeting::pickTarget([['wref' => 9, 'x' => 5, 'y' => 5, 'access' => 2, 'protect' => 0, 'vac_mode' => 0]], 5, 5, 20, $now);
if ($self !== null) {
    npc_fail('An NPC must not target its own square.');
}

// Neighbours raid each other, but never themselves.
$mixed = [
    ['wref' => 10, 'owner' => 7, 'x' => 1, 'y' => 0, 'access' => 2, 'protect' => 0, 'vac_mode' => 0],
    ['wref' => 11, 'owner' => 9, 'x' => 4, 'y' => 0, 'access' => 2, 'protect' => 0, 'vac_mode' => 0],
];
$own = NpcTargeting::pickTarget($mixed, 0, 0, 20, $now, 7);
if (!$own || (int) $own['wref'] !== 11) {
    npc_fail('An NPC must skip its own villages: ' . json_encode($own));
}

// Revenge goes to whoever hit us, even when someone else is closer.
$revenge = NpcTargeting::pickTarget($mixed, 0, 0, 20, $now, 0, 9);
if (!$revenge || (int) $revenge['owner'] !== 9) {
    npc_fail('The last raider must be preferred: ' . json_encode($revenge));
}

// ... but only while they are within reach; otherwise hit the nearest.
$far = [
    ['wref' => 12, 'owner' => 3, 'x' => 2, 'y' => 0, 'access' => 2, 'protect' => 0, 'vac_mode' => 0],
    ['wref' => 13, 'owner' => 9, 'x' => 40, 'y' => 0, 'access' => 2, 'protect' => 0, 'vac_mode' => 0],
];
$fallback = NpcTargeting::pickTarget($far, 0, 0, 10, $now, 0, 9);
if (!$fallback || (int) $fallback['wref'] !== 12) {
    npc_fail('An out-of-reach raider must not freeze the attack: ' . json_encode($fallback));
}

// A wave is a share of the garrison, never the whole village.
$garrison = [1 => 100, 2 => 200];
$wave = NpcTargeting::wave($garrison, 50);
if (array_sum($wave) >= array_sum($garrison)) {
    npc_fail('A wave must never empty the village: ' . json_encode($wave));
}
if ($wave[1] !== 50 || $wave[2] !== 100) {
    npc_fail('Wave did not keep the army shape: ' . json_encode($wave));
}

// Too few troops to matter: stay home rather than feed the player.
if (NpcTargeting::wave([1 => 5], 60) !== []) {
    npc_fail('A token garrison must stay home.');
}
if (NpcTargeting::wave([1 => 30], 1) !== []) {
    npc_fail('A wave that rounds down to nothing must not be sent.');
}

// Wave size stays inside the configured band, even when avenging.
foreach ([0, 50, 100] as $power) {
    foreach ([false, true] as $avenging) {
        $pct = NpcTargeting::wavePercent(30, 60, $power, $avenging);
        if ($pct < 30 || $pct > 60) {
            npc_fail("wavePercent left the band: $pct (power $power, avenging " . (int) $avenging . ')');
        }
    }
}
if (NpcTargeting::wavePercent(30, 60, 50, true) <= NpcTargeting::wavePercent(30, 60, 50, false)) {
    npc_fail('A recently raided neighbour must hit back harder.');
}

// Inactivity: a cow is inactive whatever its timestamp, a human is inactive
// once away for the configured hours, and an unknown timestamp is not.
$now = 1000000;
if (!NpcTargeting::isInactive(['target_tier' => \Game\Npc\NpcTiers::INACTIVE, 'timestamp' => $now], $now, 48)) {
    npc_fail('A cow is always inactive.');
}
if (NpcTargeting::isInactive(['target_tier' => '', 'timestamp' => $now - 3600], $now, 48)) {
    npc_fail('A player seen an hour ago is active.');
}
if (!NpcTargeting::isInactive(['target_tier' => '', 'timestamp' => $now - 49 * 3600], $now, 48)) {
    npc_fail('A player away for 49 hours is inactive at a 48 hour threshold.');
}
if (NpcTargeting::isInactive(['target_tier' => '', 'timestamp' => 0], $now, 48)) {
    npc_fail('An unknown last-seen must not count as inactive.');
}

fwrite(STDOUT, "NpcTargeting regression test passed.\n");
