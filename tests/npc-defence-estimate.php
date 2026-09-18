<?php

// The estimate is how an NPC "looks" at a target before attacking. It has to
// agree with the battle engine about walls and residence, and its margins have
// to be the ones a person accepts.
//
// Unit stats arrive through a stub NpcGameData and the two per-level tables
// (cranny capacity, field production) are handed in, so nothing here needs a
// database or a loaded ruleset.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcGameData.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTroopMapping.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcArchetypes.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTiers.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcDefenceEstimate.php';

use Game\Npc\NpcDefenceEstimate;
use Game\Npc\NpcGameData;
use Game\Npc\NpcTiers;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

class DefenceStubData implements NpcGameData
{
    /** Only the defensive stats this case needs, tribe => relative slot. */
    private static $units = [
        1 => [
            1 => ['def_i' => 35,  'def_c' => 50],
            2 => ['def_i' => 65,  'def_c' => 35],
        ],
        3 => [
            1 => ['def_i' => 40,  'def_c' => 50],
            5 => ['def_i' => 115, 'def_c' => 55],
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

$data = new DefenceStubData();

// Wall multipliers as BattleCalculator has them, all seven tribes.
if (abs(NpcDefenceEstimate::wallMultiplier(1, 10) - round(pow(1.030, 10), 3)) > 0.0001) {
    npc_fail('Roman wall 10 must be 1.030^10.');
}
if (abs(NpcDefenceEstimate::wallMultiplier(7, 10) - round(pow(1.015, 10), 3)) > 0.0001) {
    npc_fail('Egyptian wall 10 must be 1.015^10.');
}
if (NpcDefenceEstimate::wallMultiplier(4, 10) != 1.0) {
    npc_fail('Nature has no wall, so no multiplier.');
}
if (NpcDefenceEstimate::wallMultiplier(2, 0) != 1.0) {
    npc_fail('No wall, no multiplier.');
}

// No troops, no wall: only the residence bonus (10 with no residence).
$empty = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u1' => 0]], 0, 0, 0.0);
if (abs($empty - 10) > 0.001) {
    npc_fail("An empty, unwalled village should estimate 10, got $empty");
}
if (!NpcDefenceEstimate::isEmpty($empty)) {
    npc_fail('An empty village must read as empty.');
}
// Residence 10 alone: 2*100+10 = 210.
if (abs(NpcDefenceEstimate::defence($data, 1, [], 0, 10, 0.0) - 210) > 0.001) {
    npc_fail('Residence 10 must add 210.');
}
// 100 praetorians (def_i 65) against infantry: 6500 + 10.
$inf = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u2' => 100]], 0, 0, 0.0);
if (abs($inf - 6510) > 0.001) {
    npc_fail("100 praetorians vs infantry should be 6510, got $inf");
}
// ...and against pure cavalry they are worth their def_c (35): 3510.
$cav = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u2' => 100]], 0, 0, 1.0);
if (abs($cav - 3510) > 0.001) {
    npc_fail("100 praetorians vs cavalry should be 3510, got $cav");
}
// The wall multiplies everything, reinforcements count too - and a foreign
// reinforcement defends with ITS OWN stats, which is why the row carries a
// race. Fifty Gaul phalanxes are 50 x 40, not 50 x the Roman legionnaire.
$walled = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u2' => 100], ['race' => 3, 'u1' => 50]], 10, 0, 0.0);
$expect = (6500 + 50 * 40 + 10) * round(pow(1.030, 10), 3);
if (abs($walled - $expect) > 0.01) {
    npc_fail("Walled, reinforced estimate off: $walled vs $expect");
}
if (NpcDefenceEstimate::isEmpty($walled)) {
    npc_fail('A garrisoned village must not read as empty.');
}
// Reading a Gaul row as if it were Roman would have given 50 x 35 instead.
$asRoman = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u1' => 50]], 0, 0, 0.0);
$asGaul  = NpcDefenceEstimate::defence($data, 1, [['race' => 3, 'u1' => 50]], 0, 0, 0.0);
if ($asRoman >= $asGaul) {
    npc_fail("The row's race must decide the stats: $asRoman vs $asGaul");
}
// A row with no race of its own belongs to the defender.
$noRace = NpcDefenceEstimate::defence($data, 1, [['u1' => 50]], 0, 0, 0.0);
if (abs($noRace - $asRoman) > 0.001) {
    npc_fail('A row without a race must be read as the defender own tribe.');
}
// Slots outside 1..10 are not units and must never be counted.
$junk = NpcDefenceEstimate::defence($data, 1, [['race' => 1, 'u2' => 100, 'u11' => 1, 'u99' => 5000]], 0, 0, 0.0);
if (abs($junk - 6510) > 0.001) {
    npc_fail("The hero flag and the trapped-units column are not defenders: $junk");
}

// Cranny: the capacity table is the defender's own, so the server multiplier
// and the Gaul bonus are already inside it; only the attacking Teuton's
// discount is applied here.
$fdata     = ['f23t' => 23, 'f23' => 10, 'f24t' => 23, 'f24' => 1];
$romanCaps = [1 => 100, 10 => 1000];
$gaulCaps  = [1 => 150, 10 => 1500];   // Formulas::crannyCAP doubles by 3/2 for Gauls

$base = NpcDefenceEstimate::cranny($fdata, $romanCaps, 1);
if (abs($base - 1100) > 0.001) {
    npc_fail("Two crannies (10 and 1) should hide 1100 per resource, got $base");
}
if (abs(NpcDefenceEstimate::cranny($fdata, $gaulCaps, 1) - 1650) > 0.001) {
    npc_fail('A Gaul defender hides more, through its own capacity table.');
}
if (abs(NpcDefenceEstimate::cranny($fdata, $romanCaps, 2) - 880) > 0.001) {
    npc_fail('A Teuton attacker sees 80 % of the cranny.');
}
if (NpcDefenceEstimate::cranny(['f40t' => 23, 'f40' => 10], $romanCaps, 1) != 0) {
    npc_fail('Only ordinary building slots count as crannies, as in the loot code.');
}
if (NpcDefenceEstimate::cranny(['f23t' => 23, 'f23' => 0], $romanCaps, 1) != 0) {
    npc_fail('A cranny that is not built hides nothing.');
}

// Loot: stock plus production since lastmupdate, capped by storage, minus cranny.
$now   = 1000000;
$vdata = ['wood' => 1000, 'clay' => 1000, 'iron' => 1000, 'crop' => 1000, 'maxstore' => 8000, 'maxcrop' => 8000, 'lastmupdate' => $now - 3600];
$fields = [];
for ($i = 1; $i <= 18; $i++) {
    $fields['f' . $i . 't'] = ($i <= 4) ? 1 : (($i <= 8) ? 2 : (($i <= 12) ? 3 : 4));
    $fields['f' . $i]       = 5;
}
$slow = [5 => 33];    // 33 an hour per field
$fast = [5 => 330];   // the same fields on a x10 server

$loot = NpcDefenceEstimate::availableLoot($vdata, $fields, $now, 0, $slow);
// wood/clay/iron: 4 fields x 33 = 132 each; crop: 6 fields x 33 = 198.
if ($loot !== (1000 + 132) * 3 + (1000 + 198)) {
    npc_fail("Loot with one hour of x1 production is off: $loot");
}
if (NpcDefenceEstimate::availableLoot($vdata, $fields, $now, 0, $fast) <= $loot) {
    npc_fail('A faster server must produce more in the same hour.');
}
$capped = NpcDefenceEstimate::availableLoot($vdata, $fields, $now + 86400 * 30, 0, $fast);
if ($capped !== 8000 * 4) {
    npc_fail("A month of production must be capped by storage: $capped");
}
$hidden = NpcDefenceEstimate::availableLoot($vdata, $fields, $now, 1132, $slow);
if ($hidden !== 0 + (1198 - 1132)) {
    npc_fail("The cranny must be taken off each resource: $hidden");
}
if (NpcDefenceEstimate::availableLoot($vdata, $fields, $now, 999999, $slow) !== 0) {
    npc_fail('A cranny bigger than the stock hides everything, never goes negative.');
}
// A village whose stock was just written has produced nothing since.
$fresh = array_merge($vdata, ['lastmupdate' => $now]);
if (NpcDefenceEstimate::availableLoot($fresh, $fields, $now, 0, $slow) !== 4000) {
    npc_fail('A freshly updated village holds exactly its stock.');
}

// Losses of a won raid, as the engine computes them.
foreach ([[1.5, 0.35], [2.0, 0.26], [3.0, 0.16], [5.0, 0.08]] as $pair) {
    list($ratio, $loss) = $pair;
    $got = NpcDefenceEstimate::raidLoss($ratio);
    if (abs($got - $loss) > 0.015) {
        npc_fail("raidLoss($ratio) = $got, expected about $loss");
    }
}

// Margins: a top accepts 3x, a builder 2.5x, a casual only what is empty.
if (NpcDefenceEstimate::hasSuperiority(1.5, NpcTiers::TOP)) {
    npc_fail('1.5x is a bot losing a third of its wave; a top must refuse.');
}
if (!NpcDefenceEstimate::hasSuperiority(3.0, NpcTiers::TOP)) {
    npc_fail('3x must satisfy a top.');
}
if (NpcDefenceEstimate::hasSuperiority(3.0, NpcTiers::CASUAL) || !NpcDefenceEstimate::hasSuperiority(5.0, NpcTiers::CASUAL)) {
    npc_fail('A casual needs 5x.');
}
if (!NpcDefenceEstimate::hasSuperiority(2.5, NpcTiers::BUILDER)) {
    npc_fail('A builder taking revenge accepts 2.5x.');
}
if (NpcDefenceEstimate::hasSuperiority(50.0, NpcTiers::INACTIVE)) {
    npc_fail('A cow never has superiority.');
}
if (NpcDefenceEstimate::margin('made-up') !== NpcDefenceEstimate::margin(NpcTiers::TOP)) {
    npc_fail('An unknown tier must use the strict default margin.');
}

fwrite(STDOUT, "NpcDefenceEstimate regression test passed.\n");
