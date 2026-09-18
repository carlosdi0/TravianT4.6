<?php

/**
 * Administering server-run neighbours from the command line.
 *
 * Seeding is deliberately manual: a world starts empty and an administrator
 * populates it on purpose. Nothing here runs from a worker, and no installation
 * step calls it. See docs/NPC.md.
 *
 *   php npc.php status
 *   php npc.php seed <count> [--x=N --y=N] [--radius-min=N] [--radius-max=N]
 *   php npc.php grow [count]
 *   php npc.php expand [count]
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI Only!');
}

require __DIR__ . '/env.php';
require dirname(__DIR__, 2) . '/include/bootstrap.php';

use Model\NpcExpandModel;
use Model\NpcGrowthModel;
use Model\NpcModel;
use Model\NpcSeedModel;

mt_srand(make_seed());

$argv    = isset($argv) ? $argv : [];
$command = isset($argv[1]) ? (string) $argv[1] : 'status';

/** Long options in the --name=value form; positionals are read by index. */
function npc_options(array $argv)
{
    $options = [];
    foreach ($argv as $argument) {
        if (strpos($argument, '--') !== 0) {
            continue;
        }
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
    }

    return $options;
}

function npc_positional(array $argv, $index, $default = null)
{
    $found = 0;
    foreach (array_slice($argv, 2) as $argument) {
        if (strpos($argument, '--') === 0) {
            continue;
        }
        if ($found++ === $index) {
            return $argument;
        }
    }

    return $default;
}

$options = npc_options($argv);

switch ($command) {
    case 'seed':
        $count = (int) npc_positional($argv, 0, 0);
        if ($count <= 0) {
            fwrite(STDERR, "Usage: php npc.php seed <count> [--x=N --y=N] [--radius-min=N] [--radius-max=N]\n");
            exit(2);
        }

        $seedOptions = [];
        if (isset($options['x']) && isset($options['y'])) {
            $seedOptions['centerX'] = (int) $options['x'];
            $seedOptions['centerY'] = (int) $options['y'];
        }
        if (isset($options['radius-min'])) {
            $seedOptions['radiusMin'] = (int) $options['radius-min'];
        }
        if (isset($options['radius-max'])) {
            $seedOptions['radiusMax'] = (int) $options['radius-max'];
        }

        $result = (new NpcSeedModel())->seed($count, $seedOptions);
        foreach ($result['accounts'] as $account) {
            printf(
                "  %-16s uid=%-5d tribe=%d %-8s/%-9s/%-11s power=%d villages=%d pop=%d at (%d|%d)\n",
                $account['name'],
                $account['uid'],
                $account['tribe'],
                $account['tier'],
                $account['archetype'],
                $account['trait'],
                $account['power'],
                $account['villages'],
                $account['pop'],
                $account['x'],
                $account['y']
            );
        }
        printf("Seeded %d neighbour(s), %d failed.%s\n",
            $result['created'],
            $result['failed'],
            $result['reason'] !== '' ? ' ' . $result['reason'] : '');
        exit($result['created'] > 0 || $count === 0 ? 0 : 1);

    case 'grow':
        // An interval of 0 means "whatever their last pass was", which is what
        // an administrator running this by hand wants; the worker keeps its own.
        $touched = (new NpcGrowthModel())->run((int) npc_positional($argv, 0, 50), 60);
        printf("Grew %d neighbour(s).\n", $touched);
        exit(0);

    case 'expand':
        $founded = (new NpcExpandModel())->run((int) npc_positional($argv, 0, 20), 60);
        printf("Founded %d village(s).\n", $founded);
        exit(0);

    case 'status':
        $npc    = new NpcModel();
        $counts = $npc->countByTier();
        $total  = $npc->total();
        printf("Neighbours: %d\n", $total);
        foreach ($counts as $tier => $count) {
            printf("  %-9s %4d  %5.1f%%\n", $tier, $count, $total > 0 ? $count * 100 / $total : 0);
        }
        $benchmark = $npc->playerBenchmark();
        printf("Biggest human player: %d pop across %d village(s)\n", $benchmark['pop'], $benchmark['villages']);
        exit(0);

    default:
        fwrite(STDERR, "Unknown command '$command'. Try: status, seed, grow, expand\n");
        exit(2);
}
