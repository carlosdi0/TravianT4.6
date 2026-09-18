<?php

namespace Game\Npc;

/**
 * What each unit of the three classic tribes is FOR, and how a neighbour
 * composes a raid out of what it has.
 *
 * The old pass sent a flat share of every slot, praetorians and phalanxes
 * included, which is how twenty-five defensive infantry ended up walking into
 * a wall to steal six hundred resources. A person raids with offensive units,
 * keeps the defence at home, and only takes "whatever is around" against a
 * village known to be empty - an inactive, a cow - the way everyone farms in
 * their first week.
 *
 * Roles are a fixed table, not derived from attack > defence: the legionnaire
 * (40 vs 35/50) and the haeduan (140 vs 60/165) both read wrong that way.
 * Unit stats come from the injected NpcGameData, never from the engine, so a
 * test can compose a wave without a world behind it.
 */
class NpcArmyRoles
{
    const OFFENSIVE = 'off';
    const DEFENSIVE = 'def';
    const HYBRID    = 'hyb';   // raids fine, defends fine; never the wave's backbone
    const SCOUT     = 'scout';
    const RAM       = 'ram';
    const CATAPULT  = 'cat';
    const CHIEF     = 'chief';
    const SETTLER   = 'settler';

    /** Slots per tribe (relative 1..10). Everything else is unknown. */
    private static $roles = [
        1 => [1 => self::HYBRID, 2 => self::DEFENSIVE, 3 => self::OFFENSIVE, 4 => self::SCOUT, 5 => self::OFFENSIVE,
              6 => self::OFFENSIVE, 7 => self::RAM, 8 => self::CATAPULT, 9 => self::CHIEF, 10 => self::SETTLER],
        2 => [1 => self::OFFENSIVE, 2 => self::DEFENSIVE, 3 => self::OFFENSIVE, 4 => self::SCOUT, 5 => self::HYBRID,
              6 => self::OFFENSIVE, 7 => self::RAM, 8 => self::CATAPULT, 9 => self::CHIEF, 10 => self::SETTLER],
        3 => [1 => self::DEFENSIVE, 2 => self::OFFENSIVE, 3 => self::SCOUT, 4 => self::OFFENSIVE, 5 => self::DEFENSIVE,
              6 => self::OFFENSIVE, 7 => self::RAM, 8 => self::CATAPULT, 9 => self::CHIEF, 10 => self::SETTLER],
    ];

    /** Mounted slots, for the infantry/cavalry split of a defence estimate. */
    private static $cavalry = [
        1 => [4, 5, 6],
        2 => [4, 5, 6],
        3 => [3, 4, 5, 6],
    ];

    /** Fewer units than this never leave: one dead unit teaches nobody anything. */
    const MIN_WAVE = 10;

    /** Carry margin over the loot estimate, in percent. */
    const CARRY_MARGIN_PCT = 120;

    public static function role($tribe, $slot)
    {
        $tribe = (int) $tribe;
        $slot  = (int) $slot;
        return isset(self::$roles[$tribe][$slot]) ? self::$roles[$tribe][$slot] : '';
    }

    /** @return int[] Relative slots holding this role, in slot order. */
    public static function slotsByRole($tribe, $role)
    {
        $found = [];
        foreach (isset(self::$roles[(int) $tribe]) ? self::$roles[(int) $tribe] : [] as $slot => $r) {
            if ($r === $role) {
                $found[] = $slot;
            }
        }
        return $found;
    }

    public static function scoutSlot($tribe)
    {
        $slots = self::slotsByRole($tribe, self::SCOUT);
        return $slots ? $slots[0] : 0;
    }

    public static function ramSlot()
    {
        return 7;
    }

    public static function catapultSlot()
    {
        return 8;
    }

    public static function isCavalry($tribe, $slot)
    {
        return isset(self::$cavalry[(int) $tribe]) && in_array((int) $slot, self::$cavalry[(int) $tribe], true);
    }

    /**
     * One stat of one tribe-relative slot, straight from the ruleset.
     *
     * @param NpcGameData $data The only way into the ruleset from here.
     * @param string      $key  off | def_i | def_c | speed | cap | cu
     */
    public static function stat(NpcGameData $data, $tribe, $slot, $key)
    {
        return (float) $data->unitStat($tribe, $slot, $key);
    }

    /** Total attack of a relative army. */
    public static function attackPower(NpcGameData $data, $tribe, array $army)
    {
        $total = 0.0;
        foreach ($army as $slot => $count) {
            $total += max(0, (int) $count) * self::stat($data, $tribe, $slot, 'off');
        }
        return $total;
    }

    /** Total carry capacity of a relative army. */
    public static function carry(NpcGameData $data, $tribe, array $army)
    {
        $total = 0;
        foreach ($army as $slot => $count) {
            $total += max(0, (int) $count) * (int) self::stat($data, $tribe, $slot, 'cap');
        }
        return $total;
    }

    /** Share of an army's attack that is mounted, 0..1. */
    public static function cavalryShare(NpcGameData $data, $tribe, array $army)
    {
        $all = self::attackPower($data, $tribe, $army);
        if ($all <= 0) {
            return 0.0;
        }
        $mounted = 0.0;
        foreach ($army as $slot => $count) {
            if (self::isCavalry($tribe, $slot)) {
                $mounted += max(0, (int) $count) * self::stat($data, $tribe, $slot, 'off');
            }
        }
        return $mounted / $all;
    }

    /**
     * Compose a raid out of a garrison.
     *
     * Against a defended target only OFFENSIVE units go, strongest first
     * (fastest first when the target is far); against an empty one anything
     * that can carry goes, biggest carriers first, which is how a young
     * account farms with phalanxes. Units are taken until the wave carries
     * the loot with a margin AND out-hits the required attack; if the whole
     * garrison cannot reach the required attack, nobody leaves.
     *
     * Scouts, siege, chiefs and settlers never raid.
     *
     * @param NpcGameData $data
     * @param int   $tribe
     * @param array $garrison   Relative slot => units at home.
     * @param int   $lootWanted Resources the wave should be able to carry.
     * @param float $minAttack  Attack the wave must reach (0 = no defence).
     * @param bool  $targetEmpty True when the defence estimate is nil.
     * @param bool  $far        Prefer speed over raw attack.
     * @return array Relative slot => units to send; empty = stay home.
     */
    public static function raidWave(NpcGameData $data, $tribe, array $garrison, $lootWanted, $minAttack, $targetEmpty, $far = false)
    {
        $roles = $targetEmpty
               ? [self::OFFENSIVE, self::HYBRID, self::DEFENSIVE]
               : [self::OFFENSIVE];

        $eligible = [];
        foreach ($garrison as $slot => $count) {
            $count = (int) $count;
            if ($count <= 0 || !in_array(self::role($tribe, $slot), $roles, true)) {
                continue;
            }
            if (self::stat($data, $tribe, $slot, 'cap') <= 0) {
                continue;
            }
            $eligible[$slot] = $count;
        }
        if (!$eligible) {
            return [];
        }

        // Order of preference.
        $keys = array_keys($eligible);
        usort($keys, function ($a, $b) use ($data, $tribe, $targetEmpty, $far) {
            if ($targetEmpty) {
                return self::stat($data, $tribe, $b, 'cap') <=> self::stat($data, $tribe, $a, 'cap');
            }
            $ka = $far ? self::stat($data, $tribe, $a, 'off') * self::stat($data, $tribe, $a, 'speed') : self::stat($data, $tribe, $a, 'off');
            $kb = $far ? self::stat($data, $tribe, $b, 'off') * self::stat($data, $tribe, $b, 'speed') : self::stat($data, $tribe, $b, 'off');
            return $kb <=> $ka;
        });

        $needCarry = (int) ceil(max(0, (int) $lootWanted) * self::CARRY_MARGIN_PCT / 100);
        $wave      = [];
        $carry     = 0;
        $attack    = 0.0;

        foreach ($keys as $slot) {
            if ($carry >= $needCarry && $attack >= $minAttack && array_sum($wave) >= self::MIN_WAVE) {
                break;
            }
            $cap = (int) self::stat($data, $tribe, $slot, 'cap');
            $atk = self::stat($data, $tribe, $slot, 'off');

            // How many of this slot are still needed for either goal.
            $forCarry  = $cap > 0 ? (int) ceil(max(0, $needCarry - $carry) / $cap) : 0;
            $forAttack = $atk > 0 ? (int) ceil(max(0, $minAttack - $attack) / $atk) : 0;
            $take      = min($eligible[$slot], max($forCarry, $forAttack, self::MIN_WAVE - (int) array_sum($wave)));
            if ($take <= 0) {
                continue;
            }
            $wave[$slot] = $take;
            $carry  += $take * $cap;
            $attack += $take * $atk;
        }

        if (!$wave || array_sum($wave) < self::MIN_WAVE) {
            return [];
        }
        if ($minAttack > 0 && $attack < $minAttack) {
            return []; // the whole garrison could not do it: stay home
        }
        return $wave;
    }

    /**
     * A scouting party: a handful of scouts, never the whole stable.
     *
     * @return array Relative slot => units; empty when the village has no scouts.
     */
    public static function scoutParty($tribe, array $garrison, $size = 5)
    {
        $slot = self::scoutSlot($tribe);
        $have = ($slot > 0 && isset($garrison[$slot])) ? (int) $garrison[$slot] : 0;
        if ($have <= 0) {
            return [];
        }
        return [$slot => min($have, max(1, (int) $size))];
    }

    /**
     * Defensive units to lend an ally: a share of the DEFENSIVE (and HYBRID)
     * slots, never the offensive line, never below a token size.
     */
    public static function reinforcement($tribe, array $garrison, $pct = 50, $min = 20)
    {
        $wave = [];
        foreach ($garrison as $slot => $count) {
            $role = self::role($tribe, $slot);
            if ($role !== self::DEFENSIVE && $role !== self::HYBRID) {
                continue;
            }
            $send = (int) floor(max(0, (int) $count) * max(0, min(100, (int) $pct)) / 100);
            if ($send > 0) {
                $wave[$slot] = $send;
            }
        }
        return array_sum($wave) >= max(1, (int) $min) ? $wave : [];
    }
}
