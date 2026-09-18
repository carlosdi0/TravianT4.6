<?php

namespace Model;

use Core\Database\DB;
use Game\Npc\NpcLifespan;
use Game\Npc\NpcSeedPlan;
use Game\Npc\NpcTiers;
use function getNpc;

/**
 * Everything the NPC subsystem asks the database.
 *
 * The decision layer under `Game\Npc` is pure by design: it is handed rows and
 * answers with a decision. This is the other half - the one that knows what a
 * table is - and the seeding, growth and expansion passes go through it rather
 * than writing their own SQL, so there is exactly one place that knows an NPC's
 * villages are derived from `vdata.owner` and never stored.
 *
 * Nothing here touches `Core\Session` or `$_REQUEST`. It runs in a worker.
 */
class NpcModel
{
    /**
     * Columns of the registry row. `trait` is a reserved word in MariaDB, so
     * every mention of it is backquoted; building the lists from one array is
     * what keeps that from being forgotten in a query written later.
     */
    const REGISTRY_COLUMNS = [
        'uid', 'archetype', 'tier', 'trait', 'power', 'max_villages', 'max_pop_per_village',
        'home_kid', 'created', 'quit_at', 'last_growth', 'last_attack', 'next_attack', 'burst',
        'last_expand', 'expansions', 'grudge_hits', 'grudge_since', 'last_raided', 'last_raider',
        'raid_loot', 'raid_hits', 'last_real_attack',
    ];

    /** The registry columns as a select list, optionally table-qualified. */
    private static function selectList($prefix = '')
    {
        $columns = [];
        foreach (self::REGISTRY_COLUMNS as $column) {
            $columns[] = $prefix . '`' . $column . '`';
        }

        return implode(', ', $columns);
    }

    /**
     * Write the registry row for a freshly created account.
     *
     * @param array $row Column => value; unknown columns are refused rather
     *                   than silently dropped, because a typo in a ceiling is
     *                   exactly the kind of thing that only shows up weeks later.
     */
    public function register($uid, array $row)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return false;
        }

        $columns = ['uid' => $uid];
        foreach ($row as $column => $value) {
            if (!in_array($column, self::writableColumns(), true)) {
                throw new \InvalidArgumentException("Unknown npc_player column: $column");
            }
            $columns[$column] = $value;
        }

        $db     = DB::getInstance();
        $names  = [];
        $values = [];
        foreach ($columns as $column => $value) {
            $names[]  = '`' . $column . '`';
            $values[] = is_string($value) ? "'" . $db->real_escape_string($value) . "'" : (int) $value;
        }

        return (bool) $db->query('INSERT INTO npc_player (' . implode(',', $names) . ') VALUES (' . implode(',', $values) . ')');
    }

    /** @return array|null The registry row, or null when the uid is not an NPC. */
    public function get($uid)
    {
        $uid    = (int) $uid;
        $result = DB::getInstance()->query('SELECT ' . self::selectList() . " FROM npc_player WHERE uid=$uid");

        return $result->num_rows ? $result->fetch_assoc() : null;
    }

    /** Update named registry columns of one account. */
    public function touch($uid, array $fields)
    {
        $uid = (int) $uid;
        if ($uid <= 0 || !$fields) {
            return false;
        }

        $db  = DB::getInstance();
        $set = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::writableColumns(), true)) {
                throw new \InvalidArgumentException("Unknown npc_player column: $column");
            }
            $set[] = '`' . $column . '`=' . (is_string($value) ? "'" . $db->real_escape_string($value) . "'" : (int) $value);
        }

        return (bool) $db->query('UPDATE npc_player SET ' . implode(',', $set) . " WHERE uid=$uid");
    }

    /** tier => how many accounts the world currently holds. */
    public function countByTier()
    {
        $counts = array_fill_keys(NpcTiers::keys(), 0);
        $result = DB::getInstance()->query('SELECT tier, COUNT(uid) AS total FROM npc_player GROUP BY tier');
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['tier']] = (int) $row['total'];
        }

        return $counts;
    }

    public function total()
    {
        return (int) DB::getInstance()->fetchScalar('SELECT COUNT(uid) FROM npc_player');
    }

    /**
     * Accounts whose growth pass is due, oldest first.
     *
     * Cows are filtered out in SQL rather than in PHP: they are the largest
     * tier by design, and a batch that fetches thirty of them to discard
     * twenty-eight is a batch that never reaches the accounts that do grow.
     * A not-yet-quit inactive is still eligible, because it is playing as a
     * casual until its quit day (NpcLifespan).
     */
    public function dueForGrowth($interval, $limit, $now = null)
    {
        return $this->dueBatch('last_growth', $interval, $limit, $now);
    }

    /** Accounts whose expansion pass is due, oldest first. */
    public function dueForExpand($interval, $limit, $now = null)
    {
        return $this->dueBatch('last_expand', $interval, $limit, $now);
    }

    private function dueBatch($column, $interval, $limit, $now = null)
    {
        $now      = $now === null ? time() : (int) $now;
        $interval = max(60, (int) $interval);
        $limit    = max(1, (int) $limit);
        $cutoff   = $now - $interval;
        $inactive = DB::getInstance()->real_escape_string(NpcTiers::INACTIVE);

        $result = DB::getInstance()->query(
            'SELECT ' . self::selectList('n.') . ', '
            . 'u.race, u.total_pop, u.total_villages, u.aid '
            . 'FROM npc_player n JOIN users u ON u.id=n.uid '
            . "WHERE n.$column <= $cutoff "
            // A cow that has already quit is skipped; one still in its first
            // days is not, and its quit_at is what tells the two apart.
            . "AND (n.tier <> '$inactive' OR (n.quit_at > 0 AND n.quit_at > $now)) "
            . "ORDER BY n.$column ASC LIMIT $limit"
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The villages an account owns right now, capital first.
     *
     * Derived from `vdata.owner`, never stored: a village the player conquers
     * stops counting as NPC-owned with no extra code anywhere.
     */
    public function villagesOf($uid)
    {
        $uid    = (int) $uid;
        $result = DB::getInstance()->query(
            'SELECT kid, name, capital, pop, cp, fieldtype, maxstore, maxcrop, isWW, isFarm, isArtifact, created '
            . "FROM vdata WHERE owner=$uid AND isWW=0 ORDER BY capital DESC, created ASC"
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** The fdata row of a village: f1..f40 levels and f1t..f40t gids. */
    public function fdata($kid)
    {
        $kid    = (int) $kid;
        $result = DB::getInstance()->query("SELECT * FROM fdata WHERE kid=$kid");

        return $result->num_rows ? $result->fetch_assoc() : null;
    }

    /** The units row of a village: u1..u11 plus race. */
    public function units($kid)
    {
        $kid    = (int) $kid;
        $result = DB::getInstance()->query("SELECT * FROM units WHERE kid=$kid");

        return $result->num_rows ? $result->fetch_assoc() : null;
    }

    /** Units already queued for a village, so a pass does not order twice. */
    public function queuedUnits($kid)
    {
        $kid = (int) $kid;

        return (int) DB::getInstance()->fetchScalar("SELECT COALESCE(SUM(num), 0) FROM training WHERE kid=$kid");
    }

    /** Levels of the training buildings standing in a village: gid => level. */
    public function trainingBuildings($kid)
    {
        $row = $this->fdata($kid);
        if (!$row) {
            return [];
        }

        $levels = [];
        for ($slot = 19; $slot <= 40; $slot++) {
            $gid   = isset($row['f' . $slot . 't']) ? (int) $row['f' . $slot . 't'] : 0;
            $level = isset($row['f' . $slot]) ? (int) $row['f' . $slot] : 0;
            if ($gid > 0 && $level > 0 && !isset($levels[$gid])) {
                $levels[$gid] = $level;
            }
        }

        return $levels;
    }

    /**
     * Free valleys inside a ring around a map square, closest first.
     *
     * The ring is computed in PHP (NpcSeedPlan) but selected with a BETWEEN on
     * the bounding box, because a ring of radius 20 is 1681 squares and an
     * `IN (...)` that long is not a query any database enjoys.
     *
     * Both occupancy flags are checked. `available_villages.occupied` is what
     * village creation writes and `wdata.occupied` is what the map reads; a
     * valley that disagrees with itself is one the seeder must not touch.
     *
     * @return array<int,array{kid:int,x:int,y:int,fieldtype:int,distance:int}>
     */
    public function freeValleysInRing($centerX, $centerY, $radiusMin, $radiusMax, $worldMax, $limit = 0)
    {
        list($minX, $maxX, $minY, $maxY) = NpcSeedPlan::boundingBox($centerX, $centerY, $radiusMax, $worldMax);

        $result = DB::getInstance()->query(
            'SELECT w.id AS kid, w.x, w.y, w.fieldtype FROM wdata w '
            . 'JOIN available_villages a ON a.kid=w.id '
            . "WHERE w.occupied=0 AND a.occupied=0 AND w.fieldtype>0 "
            . "AND w.x BETWEEN $minX AND $maxX AND w.y BETWEEN $minY AND $maxY"
        );

        $valleys = [];
        while ($row = $result->fetch_assoc()) {
            if (!NpcSeedPlan::inRing($row['x'], $row['y'], $centerX, $centerY, $radiusMin, $radiusMax, $worldMax)) {
                continue;
            }
            $valleys[] = [
                'kid'       => (int) $row['kid'],
                'x'         => (int) $row['x'],
                'y'         => (int) $row['y'],
                'fieldtype' => (int) $row['fieldtype'],
                'distance'  => NpcSeedPlan::distance($row['x'], $row['y'], $centerX, $centerY),
            ];
        }

        usort($valleys, function ($a, $b) {
            return $a['distance'] <=> $b['distance'] ?: $a['kid'] <=> $b['kid'];
        });

        $limit = (int) $limit;

        return $limit > 0 ? array_slice($valleys, 0, $limit) : $valleys;
    }

    /** Is this name already taken by any account, NPC or human? */
    public function nameTaken($name)
    {
        $db = DB::getInstance();

        return (int) $db->fetchScalar("SELECT COUNT(id) FROM users WHERE name='" . $db->real_escape_string($name) . "'") > 0;
    }

    /**
     * The human player the world is being measured against: the biggest real
     * account. Only read when a lead leash is configured; with the default of
     * 0 the neighbours grow on their own curve and never look at the player.
     *
     * Access 3 (fake users) and the Natars/Support/Multihunter ids are not
     * players, and neither is anything in npc_player.
     *
     * @return array{pop:int,villages:int}
     */
    public function playerBenchmark()
    {
        $row = DB::getInstance()->query(
            'SELECT total_pop, total_villages FROM users '
            . 'WHERE id > 2 AND access < 3 AND id NOT IN (SELECT uid FROM npc_player) '
            . 'ORDER BY total_pop DESC LIMIT 1'
        );

        if (!$row->num_rows) {
            return ['pop' => 0, 'villages' => 0];
        }
        $row = $row->fetch_assoc();

        return ['pop' => (int) $row['total_pop'], 'villages' => (int) $row['total_villages']];
    }

    /**
     * The tier an account is BEHAVING as, which is not always the one stored.
     * Convenience so every pass reads the same thing the same way.
     */
    public static function effectiveTier(array $registry, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        return NpcLifespan::effectiveTier(
            isset($registry['tier']) ? (string) $registry['tier'] : NpcTiers::CASUAL,
            isset($registry['quit_at']) ? (int) $registry['quit_at'] : 0,
            $now
        );
    }

    /** The world's configured tier split, as NpcTiers::split() wants it. */
    public static function configuredSplit()
    {
        return [
            NpcTiers::TOP      => (float) getNpc('tierTopPct', 10),
            NpcTiers::BUILDER  => (float) getNpc('tierBuilderPct', 20),
            NpcTiers::CASUAL   => (float) getNpc('tierCasualPct', 40),
            NpcTiers::INACTIVE => (float) getNpc('tierInactivePct', 30),
        ];
    }

    /** Columns a caller may write; `uid` is set by register() alone. */
    private static function writableColumns()
    {
        static $columns = null;
        if ($columns === null) {
            $columns = array_values(array_diff(self::REGISTRY_COLUMNS, ['uid']));
        }

        return $columns;
    }
}
