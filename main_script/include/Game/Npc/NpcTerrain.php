<?php

namespace Game\Npc;

/**
 * The map tile an NPC village stands on, and who gets to be picky about it.
 *
 * wdata.fieldtype is the resource layout of a tile: 3 is the ordinary 4-4-4-6,
 * 1 is a nine-cropper, 6 is a fifteen-cropper, 0 is an oasis and the rest are
 * six- and seven-crop variants. The full table lives in
 * Database::addResourceFields(), which turns the same number into the f1t..f18t
 * gids of the village built there.
 *
 * Two rules:
 *
 *   - THE TILE DECIDES THE VILLAGE. Seeding used to roll a layout of its own
 *     and write it into fdata while the tile it stood on stayed 4-4-4-6, so the
 *     world map lied about every NPC village and a player could conquer a
 *     "4-4-4-6" that turned out to be a fifteen-cropper. Whatever settles here,
 *     settles on what the map says is here.
 *
 *   - ONLY THE TOP TIER HUNTS CROPPERS. A cropper is the one piece of terrain
 *     worth planning around, and a top account plans. Everyone else takes what
 *     is near, which is what keeps the croppers in the neighbourhood available
 *     for the player for the first weeks.
 *
 * Pure data, so it is unit-tested without a database.
 */
class NpcTerrain
{
    /** fieldtype of an oasis: never a village. */
    const OASIS = 0;

    /** The ordinary valley, and what the whole world used to be seeded on. */
    const NORMAL = 3;

    /** Nine-cropper (3-3-3-9) and fifteen-cropper (1-1-1-15). */
    const CROPPER_9  = 1;
    const CROPPER_15 = 6;

    /** Croppers, best first: a fifteen beats a nine every time. */
    private static $croppers = [self::CROPPER_15, self::CROPPER_9];

    /** Tiers that will go out of their way for a cropper. */
    private static $hunters = [NpcTiers::TOP];

    /** Is this fieldtype something a village can be built on at all? */
    public static function isValley($fieldtype)
    {
        return (int) $fieldtype > self::OASIS;
    }

    /** Is this fieldtype a nine- or fifteen-cropper? */
    public static function isCropper($fieldtype)
    {
        return in_array((int) $fieldtype, self::$croppers, true);
    }

    /** Does this tier hunt croppers, or just settle whatever is close? */
    public static function hunts($tier)
    {
        return in_array((string) $tier, self::$hunters, true);
    }

    /**
     * How much a tier wants a given tile, higher is better.
     *
     * A hunter ranks a fifteen-cropper over a nine over anything else; everyone
     * else scores every valley the same, so the caller's own ordering (distance
     * for seeding, shuffled for expansion) survives untouched.
     */
    public static function score($fieldtype, $tier)
    {
        if (!self::hunts($tier) || !self::isCropper($fieldtype)) {
            return 0;
        }

        return (int) $fieldtype === self::CROPPER_15 ? 2 : 1;
    }

    /**
     * Reorder candidate tiles so a hunter sees its croppers first.
     *
     * A stable sort on the score alone: for a non-hunter nothing moves, and for
     * a hunter with no cropper in range nothing moves either. usort() is NOT
     * stable before PHP 8.0 and this must not reshuffle the caller's order, so
     * the original index is the tie-break.
     *
     * @param array $valleys Rows carrying at least a 'fieldtype' key.
     * @return array The same rows, best first.
     */
    public static function rank(array $valleys, $tier)
    {
        if (!self::hunts($tier)) {
            return $valleys;
        }

        $ordered = array_values($valleys);
        $keyed   = [];
        foreach ($ordered as $index => $valley) {
            $field    = isset($valley['fieldtype']) ? (int) $valley['fieldtype'] : self::NORMAL;
            $keyed[]  = [self::score($field, $tier), -$index, $valley];
        }

        usort($keyed, function ($a, $b) {
            return $b[0] <=> $a[0] ?: $b[1] <=> $a[1];
        });

        $result = [];
        foreach ($keyed as $entry) {
            $result[] = $entry[2];
        }

        return $result;
    }
}
