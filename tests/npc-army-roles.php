<?php

// Roles decide which units leave home. Get one wrong and a builder sends its
// praetorians raiding, or a raider walks scouts into a wall.
//
// The stats come from a stub NpcGameData rather than the engine: that is the
// point of the interface, and it means this case pins the roles even when no
// world is loaded. The numbers are the ruleset's own.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGameData.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArmyRoles.php';

use Game\Npc\NpcArmyRoles;
use Game\Npc\NpcGameData;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

class ArmyRolesStubData implements NpcGameData
{
    /** Tribe => relative slot => stats, straight from the ruleset. */
    private static $units = [
        1 => [
            1  => ['off' => 40,  'def_i' => 35,  'def_c' => 50,  'speed' => 6,  'cap' => 50,   'cu' => 1],
            2  => ['off' => 30,  'def_i' => 65,  'def_c' => 35,  'speed' => 5,  'cap' => 20,   'cu' => 1],
            3  => ['off' => 70,  'def_i' => 40,  'def_c' => 25,  'speed' => 7,  'cap' => 50,   'cu' => 1],
            4  => ['off' => 0,   'def_i' => 20,  'def_c' => 10,  'speed' => 16, 'cap' => 0,    'cu' => 2],
            5  => ['off' => 120, 'def_i' => 65,  'def_c' => 50,  'speed' => 14, 'cap' => 100,  'cu' => 3],
            6  => ['off' => 180, 'def_i' => 80,  'def_c' => 105, 'speed' => 10, 'cap' => 70,   'cu' => 4],
            7  => ['off' => 60,  'def_i' => 30,  'def_c' => 75,  'speed' => 4,  'cap' => 0,    'cu' => 3],
            8  => ['off' => 75,  'def_i' => 60,  'def_c' => 10,  'speed' => 3,  'cap' => 0,    'cu' => 6],
            9  => ['off' => 50,  'def_i' => 40,  'def_c' => 30,  'speed' => 4,  'cap' => 0,    'cu' => 5],
            10 => ['off' => 0,   'def_i' => 80,  'def_c' => 80,  'speed' => 5,  'cap' => 3000, 'cu' => 1],
        ],
        2 => [
            1  => ['off' => 40,  'def_i' => 20,  'def_c' => 5,   'speed' => 7,  'cap' => 60,   'cu' => 1],
            2  => ['off' => 10,  'def_i' => 35,  'def_c' => 60,  'speed' => 7,  'cap' => 40,   'cu' => 1],
            3  => ['off' => 60,  'def_i' => 30,  'def_c' => 30,  'speed' => 6,  'cap' => 50,   'cu' => 1],
            4  => ['off' => 0,   'def_i' => 10,  'def_c' => 5,   'speed' => 9,  'cap' => 0,    'cu' => 1],
            5  => ['off' => 55,  'def_i' => 100, 'def_c' => 40,  'speed' => 10, 'cap' => 110,  'cu' => 2],
            6  => ['off' => 150, 'def_i' => 50,  'def_c' => 75,  'speed' => 9,  'cap' => 80,   'cu' => 3],
            7  => ['off' => 65,  'def_i' => 30,  'def_c' => 80,  'speed' => 4,  'cap' => 0,    'cu' => 3],
            8  => ['off' => 50,  'def_i' => 60,  'def_c' => 10,  'speed' => 3,  'cap' => 0,    'cu' => 6],
            9  => ['off' => 40,  'def_i' => 60,  'def_c' => 40,  'speed' => 4,  'cap' => 0,    'cu' => 4],
            10 => ['off' => 10,  'def_i' => 80,  'def_c' => 80,  'speed' => 5,  'cap' => 3000, 'cu' => 1],
        ],
        3 => [
            1  => ['off' => 15,  'def_i' => 40,  'def_c' => 50,  'speed' => 7,  'cap' => 35,   'cu' => 1],
            2  => ['off' => 65,  'def_i' => 35,  'def_c' => 20,  'speed' => 6,  'cap' => 45,   'cu' => 1],
            3  => ['off' => 0,   'def_i' => 20,  'def_c' => 10,  'speed' => 17, 'cap' => 0,    'cu' => 2],
            4  => ['off' => 90,  'def_i' => 25,  'def_c' => 40,  'speed' => 19, 'cap' => 75,   'cu' => 2],
            5  => ['off' => 45,  'def_i' => 115, 'def_c' => 55,  'speed' => 16, 'cap' => 35,   'cu' => 2],
            6  => ['off' => 140, 'def_i' => 60,  'def_c' => 165, 'speed' => 13, 'cap' => 65,   'cu' => 3],
            7  => ['off' => 50,  'def_i' => 30,  'def_c' => 105, 'speed' => 4,  'cap' => 0,    'cu' => 3],
            8  => ['off' => 70,  'def_i' => 45,  'def_c' => 10,  'speed' => 3,  'cap' => 0,    'cu' => 6],
            9  => ['off' => 40,  'def_i' => 50,  'def_c' => 50,  'speed' => 5,  'cap' => 0,    'cu' => 4],
            10 => ['off' => 0,   'def_i' => 80,  'def_c' => 80,  'speed' => 5,  'cap' => 3000, 'cu' => 1],
        ],
    ];

    public function unitStat($tribe, $slot, $key)
    {
        return isset(self::$units[(int) $tribe][(int) $slot][$key])
            ? (float) self::$units[(int) $tribe][(int) $slot][$key]
            : 0.0;
    }

    public function buildingMaxLevel($gid, $isCapital)
    {
        return 20;
    }

    public function buildingPop($gid, $level)
    {
        return 1;
    }

    public function wallGid($tribe)
    {
        $walls = [1 => 31, 2 => 32, 3 => 33];
        return isset($walls[(int) $tribe]) ? $walls[(int) $tribe] : 0;
    }
}

$data = new ArmyRolesStubData();

$never = [NpcArmyRoles::SCOUT, NpcArmyRoles::RAM, NpcArmyRoles::CATAPULT, NpcArmyRoles::CHIEF, NpcArmyRoles::SETTLER];

foreach ([1, 2, 3] as $tribe) {
    for ($slot = 1; $slot <= 10; $slot++) {
        $role = NpcArmyRoles::role($tribe, $slot);
        if ($role === '') {
            npc_fail("tribe $tribe slot $slot has no role");
        }
        $atk = NpcArmyRoles::stat($data, $tribe, $slot, 'off');
        $di  = NpcArmyRoles::stat($data, $tribe, $slot, 'def_i');
        $dc  = NpcArmyRoles::stat($data, $tribe, $slot, 'def_c');
        // Anything marked offensive really is: attack at least the mean defence.
        if ($role === NpcArmyRoles::OFFENSIVE && $atk < ($di + $dc) / 2) {
            npc_fail("tribe $tribe slot $slot is marked offensive with off $atk vs $di/$dc");
        }
        // Anything marked defensive really is: defence clearly above attack.
        if ($role === NpcArmyRoles::DEFENSIVE && $atk >= ($di + $dc) / 2) {
            npc_fail("tribe $tribe slot $slot is marked defensive with off $atk vs $di/$dc");
        }
    }
    // The fixed slots.
    if (NpcArmyRoles::role($tribe, 7) !== NpcArmyRoles::RAM || NpcArmyRoles::role($tribe, 8) !== NpcArmyRoles::CATAPULT
        || NpcArmyRoles::role($tribe, 9) !== NpcArmyRoles::CHIEF || NpcArmyRoles::role($tribe, 10) !== NpcArmyRoles::SETTLER) {
        npc_fail("tribe $tribe: siege/chief/settler slots misassigned");
    }
    if (NpcArmyRoles::stat($data, $tribe, NpcArmyRoles::scoutSlot($tribe), 'off') != 0) {
        npc_fail("tribe $tribe: the scout slot has attack");
    }
}
if (NpcArmyRoles::scoutSlot(3) !== 3 || NpcArmyRoles::scoutSlot(1) !== 4) {
    npc_fail('Gaul scouts are slot 3, Roman scouts slot 4.');
}
if (NpcArmyRoles::role(9, 1) !== '' || NpcArmyRoles::scoutSlot(9) !== 0) {
    npc_fail('Unknown tribes have no roles.');
}
// A stat nobody defined is harmless, never a fatal.
if (NpcArmyRoles::stat($data, 9, 1, 'off') !== 0.0 || NpcArmyRoles::stat($data, 1, 1, 'made-up') !== 0.0) {
    npc_fail('An unknown tribe or key must read as zero.');
}

// A mixed Roman garrison: legionnaires, praetorians, imperians, scouts, EI.
$garrison = [1 => 100, 2 => 200, 3 => 150, 4 => 20, 5 => 40];

// Against a defended target: offensive only, no praetorians, no scouts.
$wave = NpcArmyRoles::raidWave($data, 1, $garrison, 3000, 5000, false);
if (!$wave) {
    npc_fail('A garrison that can beat the defence must send something.');
}
foreach ($wave as $slot => $n) {
    if (in_array(NpcArmyRoles::role(1, $slot), $never, true) || NpcArmyRoles::role(1, $slot) === NpcArmyRoles::DEFENSIVE) {
        npc_fail("defended raid took a slot that must stay home: $slot");
    }
    if ($n > $garrison[$slot]) {
        npc_fail("wave took more units than the village has in slot $slot");
    }
}
if (NpcArmyRoles::attackPower($data, 1, $wave) < 5000) {
    npc_fail('The wave did not reach the attack it was asked for.');
}
if (NpcArmyRoles::carry($data, 1, $wave) < 3000 * 1.2) {
    npc_fail('The wave cannot carry the loot with its margin.');
}
// The legionnaire is a hybrid: it does not lead a defended raid.
if (isset($wave[1]) && !isset($wave[3])) {
    npc_fail('Legionnaires must not be the backbone of a defended raid.');
}

// Not strong enough: nobody leaves, rather than a wave that dies.
if (NpcArmyRoles::raidWave($data, 1, $garrison, 500, 1000000, false) !== []) {
    npc_fail('A wave that cannot meet the required attack must stay home.');
}

// Against an empty target: carriers first, defensive infantry welcome.
$wave = NpcArmyRoles::raidWave($data, 3, [1 => 80, 2 => 10, 3 => 5], 1500, 0, true);
if (!isset($wave[1])) {
    npc_fail('Phalanxes must be allowed to farm an empty village: ' . json_encode($wave));
}
if (isset($wave[3])) {
    npc_fail('Scouts never raid, empty target or not.');
}
if (NpcArmyRoles::carry($data, 3, $wave) < 1500 * 1.2 && array_sum($wave) < 90) {
    npc_fail('An empty-target wave should carry the loot or take everything: ' . json_encode($wave));
}

// Tiny waves never go: one dead unit teaches nobody anything.
if (NpcArmyRoles::raidWave($data, 2, [1 => 3], 100, 0, true) !== []) {
    npc_fail('A wave below MIN_WAVE must not leave.');
}
if (NpcArmyRoles::raidWave($data, 2, [4 => 50, 7 => 10, 8 => 10, 9 => 1, 10 => 3], 100, 0, true) !== []) {
    npc_fail('Scouts, siege, chiefs and settlers never make a raid.');
}

// Far targets prefer speed: a Teuton with clubs and knights sends knights first.
$far  = NpcArmyRoles::raidWave($data, 2, [1 => 500, 6 => 50], 500, 3000, false, true);
$near = NpcArmyRoles::raidWave($data, 2, [1 => 500, 6 => 50], 500, 3000, false, false);
if (!isset($far[6])) {
    npc_fail('A far raid should lead with cavalry: ' . json_encode($far));
}
if (!$near) {
    npc_fail('A near raid must still be composed.');
}

// Cavalry share and helpers.
if (NpcArmyRoles::cavalryShare($data, 1, [3 => 10]) != 0.0 || NpcArmyRoles::cavalryShare($data, 1, [5 => 10]) != 1.0) {
    npc_fail('cavalryShare must be 0 for infantry and 1 for cavalry.');
}
if (NpcArmyRoles::cavalryShare($data, 1, []) != 0.0) {
    npc_fail('An empty army has no cavalry share.');
}

// Scouting party and reinforcement composition.
if (NpcArmyRoles::scoutParty(1, [4 => 20]) !== [4 => 5]) {
    npc_fail('scoutParty should send five scouts by default.');
}
if (NpcArmyRoles::scoutParty(1, [4 => 2]) !== [4 => 2]) {
    npc_fail('scoutParty must not send scouts the village lacks.');
}
if (NpcArmyRoles::scoutParty(1, [3 => 50]) !== []) {
    npc_fail('No scouts, no party.');
}
$reinf = NpcArmyRoles::reinforcement(1, [1 => 100, 2 => 200, 3 => 300], 50, 20);
if (isset($reinf[3]) || !isset($reinf[2]) || $reinf[2] !== 100) {
    npc_fail('reinforcement must lend half the defence and none of the offence: ' . json_encode($reinf));
}
if (NpcArmyRoles::reinforcement(1, [2 => 10], 50, 20) !== []) {
    npc_fail('A token reinforcement below the minimum must not be sent.');
}

fwrite(STDOUT, "NpcArmyRoles regression test passed.\n");
