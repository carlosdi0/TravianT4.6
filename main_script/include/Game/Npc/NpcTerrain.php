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
 *     worth planning around, and a top account plans.
 *
 *   - AND EVERYONE ELSE STAYS OFF THEM. Not hunting is not enough: a small map
 *     has a handful of croppers, and seeding walks the free valleys closest
 *     first, so "take whatever is near" handed fifteen-croppers to the cows.
 *     A non-hunter now ranks a cropper BELOW an ordinary valley and only ever
 *     settles one when the neighbourhood has nothing else left, which is what
 *     actually keeps them available for the player through the first weeks.
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
     * A hunter ranks a fifteen-cropper over a nine over anything else. A
     * non-hunter scores every ordinary valley the same, so the caller's own
     * ordering (distance for seeding, shuffled for expansion) survives
     * untouched - and scores a cropper BELOW them, so it is the last thing it
     * settles rather than whatever happened to be closest.
     */
    public static function score($fieldtype, $tier)
    {
        if (!self::isCropper($fieldtype)) {
            return 0;
        }
        if (!self::hunts($tier)) {
            return -1;
        }

        return (int) $fieldtype === self::CROPPER_15 ? 2 : 1;
    }

    /**
     * Reorder candidate tiles: a hunter's croppers first, a non-hunter's last.
     *
     * A stable sort on the score alone, so a list with no cropper in it comes
     * back exactly as it was given for either tier. usort() is NOT stable
     * before PHP 8.0 and this must not reshuffle the caller's distance order,
     * so the original index is the tie-break.
     *
     * @param array $valleys Rows carrying at least a 'fieldtype' key.
     * @return array The same rows, best first.
     */
    public static function rank(array $valleys, $tier)
    {
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
