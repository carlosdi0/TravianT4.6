<?php

namespace Game\Npc;

/**
 * Tribe-relative unit slots, which is the only numbering the tables use here.
 *
 * `units`, `enforcement` and `movement` all store u1..u10 plus a `race`
 * column, so a slot means nothing without the row's race beside it. The
 * absolute unit id the formulas want - unit N of tribe T is (T - 1) * 10 + N -
 * is produced in exactly one place, NpcFormulasData::absoluteUnitId(), and
 * nothing outside that class is allowed to compute it again.
 *
 * What is left here is the part that is genuinely about slots: the three
 * numbers every tribe shares, and two helpers that move armies between the
 * [slot => amount] shape the brains use and the uN columns the tables use.
 *
 * Slot 9 is always the conqueror (senator/chief/chieftain) and slot 10 the
 * settler, in every tribe; u11 is the hero and NPCs never send one.
 */
class NpcTroopMapping
{
    /** Units per tribe. */
    const SLOTS_PER_TRIBE = 10;

    /** Relative slot of the conquering unit (senator, chief, chieftain, ...). */
    const SLOT_CONQUEROR = 9;

    /** Relative slot of the settler. */
    const SLOT_SETTLER = 10;

    /**
     * Convert a relative army into the 11 ordered values a wave is written
     * with (u1..u10 plus the hero flag u11, always 0 for NPCs). Missing slots
     * become 0, so the result is always a full, correctly ordered wave.
     *
     * @return array<int,int> Zero-indexed list of 11 values.
     */
    public static function toAttackSlots(array $relativeArmy)
    {
        $slots = array_fill(0, self::SLOTS_PER_TRIBE + 1, 0);
        foreach ($relativeArmy as $slot => $amount) {
            $slot   = (int) $slot;
            $amount = (int) $amount;
            if ($slot >= 1 && $slot <= self::SLOTS_PER_TRIBE && $amount > 0) {
                $slots[$slot - 1] = $amount;
            }
        }
        return $slots;
    }

    /**
     * Read a village's `units` row into a relative army, keeping only the
     * fighting units. The conqueror and settler slots are left out: an NPC
     * must never raid with its settlers, and losing a senator to a raid would
     * be silently wasteful.
     */
    public static function fightingArmyFromRow(array $unitsRow)
    {
        $army = [];
        for ($slot = 1; $slot < self::SLOT_CONQUEROR; $slot++) {
            $key = 'u' . $slot;
            if (isset($unitsRow[$key]) && (int) $unitsRow[$key] > 0) {
                $army[$slot] = (int) $unitsRow[$key];
            }
        }
        return $army;
    }
}
