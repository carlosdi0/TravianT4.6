<?php

// Blocs only work if two things hold: a neighbour's tag never changes, and an
// allied village is genuinely unreachable from every code path that picks a
// target. Get the first wrong and the map is noise; get the second wrong and
// alliance members raid each other, which reads as a bug to anyone watching
// the rankings.

require_once __DIR__ . '/../main_script/include/Game/Npc/NpcAlliances.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcSeedPlan.php';
require_once __DIR__ . '/../main_script/include/Game/Npc/NpcTargeting.php';

use Game\Npc\NpcAlliances;
use Game\Npc\NpcTargeting;

function npc_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// A neighbourhood too small to divide gets no blocs at all: two alliances of
// one member each is worse than none.
if (NpcAlliances::blocCount(3) !== 0) {
    npc_fail('Three neighbours must not be split into alliances.');
}
if (NpcAlliances::blocCount(24) < 2) {
    npc_fail('Two dozen neighbours must yield at least two alliances.');
}
if (NpcAlliances::blocCount(5000) > count(NpcAlliances::blocs())) {
    npc_fail('The layout must never ask for more blocs than there are tags.');
}

// Tags must be unique, or allianceByTag() would match the wrong bloc and two
// of them would silently merge.
$tags = array_column(NpcAlliances::blocs(), 'tag');
if (count($tags) !== count(array_unique($tags))) {
    npc_fail('Bloc tags must be unique.');
}

// Joining is permanent: the same uid must always get the same answer, or a
// neighbour would drift in and out of its alliance on every upkeep pass.
foreach ([1, 7, 42, 999, 100000] as $uid) {
    if (NpcAlliances::isLoner($uid) !== NpcAlliances::isLoner($uid)) {
        npc_fail("isLoner is not stable for uid $uid.");
    }
}

// Roughly a quarter unaligned. The exact share is a hash, so this is a band,
// not an equality: what matters is that both groups genuinely exist.
$loners = 0;
for ($uid = 1; $uid <= 2000; $uid++) {
    if (NpcAlliances::isLoner($uid)) {
        $loners++;
    }
}
$share = $loners * 100 / 2000;
if ($share < 15 || $share > 35) {
    npc_fail("Unaligned share drifted out of band: $share%");
}

// assign() fills the smallest bloc, so seeding in several runs spreads out
// instead of piling everyone into the first tag.
$sizes = [0 => 6, 1 => 2, 2 => 4];
if (NpcAlliances::assign(1234, $sizes) !== 1) {
    npc_fail('A joiner must go to the smallest bloc.');
}

// Equal blocs are broken by the uid, and the same uid always breaks them the
// same way.
$even  = [0 => 3, 1 => 3, 2 => 3];
$first = NpcAlliances::assign(77, $even);
if (NpcAlliances::assign(77, $even) !== $first) {
    npc_fail('Tie-breaking must be deterministic.');
}

// Confederations: never a chain. Every bloc appears in at most one pact, or a
// single pact would put the whole neighbourhood at peace.
foreach ([3, 4, 6, 8] as $count) {
    $seen = [];
    foreach (NpcAlliances::confederations($count) as $pair) {
        foreach ($pair as $index) {
            if (isset($seen[$index])) {
                npc_fail("Bloc $index is in two pacts at $count blocs.");
            }
            $seen[$index] = true;
            if ($index >= $count) {
                npc_fail("Pact refers to bloc $index, outside $count blocs.");
            }
        }
    }
}
if (NpcAlliances::confederations(2) !== []) {
    npc_fail('Two blocs must stay at odds, with no pact between them.');
}

// friendly(): own tag plus confederates, and nothing for the unaligned.
$confeds = [10 => [20], 20 => [10]];
$friends = NpcAlliances::friendly(10, $confeds);
sort($friends);
if ($friends !== [10, 20]) {
    npc_fail('An aligned attacker must spare its own tag and its confederate.');
}
if (NpcAlliances::friendly(0, $confeds) !== []) {
    npc_fail('An unaligned attacker must spare nobody.');
}
if (!NpcAlliances::mayRaid(10, 30, $confeds)) {
    npc_fail('An unrelated alliance must remain a legal target.');
}
if (NpcAlliances::mayRaid(10, 20, $confeds)) {
    npc_fail('A confederate must not be a legal target.');
}
if (!NpcAlliances::mayRaid(10, 0, $confeds)) {
    npc_fail('An unaligned village must remain a legal target.');
}

// Every bloc must carry a written profile with the diplomacy shortcodes in it.
// An alliance page with an empty description is the single clearest tell that
// the account is server-run.
foreach (array_keys(NpcAlliances::blocs()) as $index) {
    $profile = NpcAlliances::profile($index, 'Brennus');

    if (trim($profile['desc']) === '' || trim($profile['notice']) === '') {
        npc_fail("Bloc $index has an empty profile.");
    }
    if (strpos($profile['desc'], '[ally]') === false) {
        npc_fail("Bloc $index never lists its confederations.");
    }
    if (strpos($profile['desc'], '[war]') === false) {
        npc_fail("Bloc $index never lists its wars.");
    }
    if (strpos($profile['desc'], '%LEADER%') !== false
        || strpos($profile['notice'], '%LEADER%') !== false) {
        npc_fail("Bloc $index leaked the %LEADER% placeholder.");
    }
    // The profile is escaped before the engine expands the shortcodes, so any
    // markup written here would reach the page as visible junk.
    if (strip_tags($profile['desc']) !== $profile['desc']) {
        npc_fail("Bloc $index profile contains markup; plain text only.");
    }
}

// A bloc whose leader is unknown still reads as prose rather than as a gap.
$anonymous = NpcAlliances::profile(1, '');
if (strpos($anonymous['desc'], '  ') !== false) {
    npc_fail('An unnamed leader must not leave a hole in the text.');
}

// Wars: never between confederates, and there is always at least one, or the
// diplomacy section of every page would sit empty while the tags raid away.
foreach ([2, 3, 4, 6, 8] as $count) {
    $allied = [];
    foreach (NpcAlliances::confederations($count) as $pair) {
        $allied[$pair[0] . ':' . $pair[1]] = true;
        $allied[$pair[1] . ':' . $pair[0]] = true;
    }
    $wars = NpcAlliances::wars($count);
    if (!$wars) {
        npc_fail("No war declared at $count blocs.");
    }
    foreach ($wars as $pair) {
        if (isset($allied[$pair[0] . ':' . $pair[1]])) {
            npc_fail("Blocs {$pair[0]} and {$pair[1]} are both allied and at war.");
        }
        if ($pair[0] === $pair[1]) {
            npc_fail("Bloc {$pair[0]} declared war on itself.");
        }
        if ($pair[0] >= $count || $pair[1] >= $count) {
            npc_fail("War refers to a bloc outside the $count in play.");
        }
    }
}

// The filter has to bite inside the targeting itself, not just in the helper:
// this is the part that actually stops a wave leaving.
$now      = 1000000;
$villages = [
    ['wref' => 1, 'owner' => 11, 'x' => 1,  'y' => 0, 'pop' => 50, 'access' => 1,
     'protect' => 0, 'vac_mode' => 0, 'alliance' => 10, 'away' => 1],
    ['wref' => 2, 'owner' => 12, 'x' => 5,  'y' => 0, 'pop' => 90, 'access' => 1,
     'protect' => 0, 'vac_mode' => 0, 'alliance' => 20, 'away' => 1],
    ['wref' => 3, 'owner' => 13, 'x' => 9,  'y' => 0, 'pop' => 70, 'access' => 1,
     'protect' => 0, 'vac_mode' => 0, 'alliance' => 30, 'away' => 1],
];
$friendly = NpcAlliances::friendly(10, $confeds);

// The two nearest villages are an ally and a confederate; the pick must skip
// both and travel to the third rather than settle for the closest.
$picked = NpcTargeting::pickTarget($villages, 0, 0, 20, $now, 0, 0, $friendly);
if ($picked === null || (int) $picked['wref'] !== 3) {
    npc_fail('pickTarget must skip allied and confederated villages.');
}

$open = NpcTargeting::candidates($villages, 0, 0, 20, $now, 0, [], false, $friendly);
if (count($open) !== 1 || (int) $open[0]['wref'] !== 3) {
    npc_fail('candidates must only offer villages outside the bloc.');
}

// The opportunist path shares the same filter: an exposed ally is still an ally.
$exposed = NpcTargeting::candidates($villages, 0, 0, 20, $now, 0, [], true, $friendly);
if (count($exposed) !== 1 || (int) $exposed[0]['wref'] !== 3) {
    npc_fail('An exposed ally must not become a target.');
}

// With no alliance of its own the same neighbour hits the nearest village,
// bloc or no bloc.
$loner = NpcTargeting::pickTarget($villages, 0, 0, 20, $now, 0, 0, []);
if ($loner === null || (int) $loner['wref'] !== 1) {
    npc_fail('An unaligned neighbour must still hit the nearest village.');
}

// A village with no alliance column at all (an old row, or the player before
// joining anything) must stay raidable rather than fail closed.
if (!NpcTargeting::isRaidable(['access' => 1, 'protect' => 0, 'vac_mode' => 0], $now, $friendly)) {
    npc_fail('A row without an alliance field must remain raidable.');
}

echo "NpcAlliancesTest: OK\n";
exit(0);
