<?php

namespace Game\Npc;

/**
 * The slice of the ruleset an NPC brain needs in order to decide anything.
 *
 * Every other class under Game\Npc takes one of these and nothing else: no
 * database handle, no globals, no Formulas. That is what keeps the decision
 * layer testable without a world behind it — a test passes a stub with the
 * three numbers the case needs instead of standing up a server.
 *
 * Unit slots here are always TRIBE-RELATIVE (1..10), never the absolute unit
 * ids the engine formulas use. Slot 9 is the conqueror and slot 10 the settler
 * in every tribe. The implementation does the translation; callers never do.
 */
interface NpcGameData
{
    /**
     * One stat of one unit.
     *
     * @param int    $tribe 1..7.
     * @param int    $slot  Tribe-relative, 1..10.
     * @param string $key   off | def_i | def_c | speed | cap | cu
     * @return float 0.0 when the tribe, slot or key is unknown, so a brain that
     *               asks about a unit that does not exist gets a harmless
     *               answer instead of a fatal.
     */
    public function unitStat($tribe, $slot, $key);

    /**
     * Highest level this building can reach.
     *
     * @param bool $isCapital Some buildings cap lower outside the capital, and
     *                        resource fields cap much higher inside it.
     * @return int 0 for an unknown building.
     */
    public function buildingMaxLevel($gid, $isCapital);

    /**
     * Population one level of a building adds, going from $level-1 to $level.
     *
     * In this ruleset a building's population IS its crop upkeep, so this is
     * also what the neighbour has to feed.
     *
     * @return int 0 for an unknown building or a level below 1.
     */
    public function buildingPop($gid, $level);

    /** Wall building id for a tribe, 0 when the tribe has no wall. */
    public function wallGid($tribe);
}
