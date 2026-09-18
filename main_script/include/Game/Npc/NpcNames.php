<?php

namespace Game\Npc;

/**
 * Names for NPC accounts and their villages.
 *
 * Neighbours called "Npc1".."Npc24" read as test data; named ones read as a
 * populated world, which is the whole point of the feature. Names are picked
 * per tribe so the map keeps some cultural flavour.
 */
class NpcNames
{
    private static $players = [
        1 => ['Aurelius', 'Cassia', 'Drusus', 'Flavia', 'Gaius', 'Livia', 'Marcellus',
              'Octavia', 'Quintus', 'Severus', 'Tiberius', 'Valeria', 'Aemilia', 'Brutus',
              'Claudia', 'Decimus', 'Fabius', 'Gallus', 'Horatia', 'Julia', 'Lucius',
              'Manius', 'Nerva', 'Portia', 'Rufus', 'Sabina', 'Titus', 'Urbana',
              'Varro', 'Vitellia', 'Cornelius', 'Domitia', 'Priscus', 'Tullia'],
        2 => ['Alaric', 'Brunhild', 'Eckhart', 'Gudrun', 'Hartmut', 'Ingrid', 'Ludolf',
              'Odalric', 'Rangar', 'Sigrun', 'Volkmar', 'Wulfstan', 'Adelheid', 'Baldur',
              'Dietmar', 'Erika', 'Frida', 'Gerhild', 'Hildegard', 'Irmin', 'Konrad',
              'Leudwin', 'Meinhard', 'Norbert', 'Ortrun', 'Reinhild', 'Sieghard', 'Thora',
              'Ulfrid', 'Waldemar', 'Gunther', 'Helm', 'Ragna', 'Sturmhild'],
        3 => ['Brennus', 'Cadwal', 'Deirdre', 'Eponia', 'Fergal', 'Maeve', 'Nuada',
              'Oisin', 'Rhiannon', 'Sionnach', 'Talorc', 'Vercinia', 'Aengus', 'Bran',
              'Caoimhe', 'Dagda', 'Eithne', 'Fionn', 'Grainne', 'Idris', 'Kelwin',
              'Lugh', 'Morrigan', 'Niall', 'Orla', 'Ronan', 'Saoirse', 'Tadhg',
              'Uaine', 'Ygraine', 'Cathal', 'Eirwen', 'Ferdia', 'Rowanna'],
    ];

    private static $villages = [
        'Ashford', 'Blackmere', 'Coldharbour', 'Duskmoor', 'Eastwatch', 'Fairstead',
        'Greyfell', 'Hollowbrook', 'Ironhold', 'Larkfield', 'Mosshaven', 'Northgate',
        'Oakenshade', 'Pinecross', 'Ravenstead', 'Stonewell', 'Thornbury', 'Westmarch',
        'Amberfen', 'Briarhold', 'Cinderford', 'Dunmarch', 'Elmsgate', 'Foxmere',
        'Glasswood', 'Havenrock', 'Ilfracove', 'Junipers Rest', 'Kestrelford', 'Longbarrow',
        'Marshlight', 'Norwood', 'Otterbank', 'Quarryhill', 'Redhollow', 'Silverbeck',
    ];

    /**
     * A player name for the given tribe. $seed only picks a starting point in
     * the tribe's own list; it never decorates the name with a number.
     *
     * The seed counts ALL neighbours, while each list is per tribe, so deriving
     * a suffix from it numbered most of the world ("Deirdre9") even when
     * plenty of names were still free. Uniqueness is the caller's job: it walks
     * the list with poolSize() and only falls back to a suffix when every name
     * is genuinely taken.
     */
    public static function player($tribe, $seed)
    {
        $tribe = (int) $tribe;
        $pool  = isset(self::$players[$tribe]) ? self::$players[$tribe] : self::$players[1];

        return $pool[max(0, (int) $seed) % count($pool)];
    }

    /** How many distinct names a tribe has, so callers know when to give up. */
    public static function poolSize($tribe)
    {
        $tribe = (int) $tribe;
        $pool  = isset(self::$players[$tribe]) ? self::$players[$tribe] : self::$players[1];

        return count($pool);
    }

    /**
     * Village name. The first village of an account is its capital and keeps
     * the plain name; the others get a numbered suffix, matching how the
     * engine names a player's own villages.
     */
    public static function village($seed, $index)
    {
        $seed = max(0, (int) $seed);
        $name = self::$villages[$seed % count(self::$villages)];

        return $index > 0 ? $name . ' ' . ($index + 1) : $name;
    }
}
