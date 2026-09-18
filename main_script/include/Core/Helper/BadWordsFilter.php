<?php

namespace Core\Helper;

use Core\Caching\GlobalCaching;
use Core\Session;

class BadWordsFilter
{
    private $badWords;

    public function __construct()
    {
        $cache = GlobalCaching::getInstance();
        if (!($this->badWords = $cache->get("badWordsFilterCache"))) {
            $this->badWords = explode(",", file_get_contents(FILTERING_PATH . "badWordsFilter.txt"));
            $cache->set("badWordsFilterCache", $this->badWords, 2 * 86400);
        }
    }

    public function containsBadWords($string, $mainString)
    {
        return $this->censorString($string, $mainString);
    }

    public function censorString($string, $mainString)
    {
        $match = self::matchBadWord($string, $this->badWords);
        if ($match === NULL) {
            return true;
        }
        Notification::notify("bad words detected!",
            "Player " . Session::getInstance()->getName() . " is trying to use bad words $match.");
        return false;
    }

    /**
     * An empty list entry is not a wildcard. explode() on an empty or
     * comma-padded filter file yields "" entries, and strpos($string, "")
     * returns 0, so without this guard every name and message on the server
     * would be rejected as profanity.
     */
    public static function matchBadWord($string, array $badWords)
    {
        if ((string)$string === '') {
            return NULL;
        }
        foreach ($badWords as $badWord) {
            $badWord = trim((string)$badWord);
            if ($badWord === '') {
                continue;
            }
            if (strpos($string, $badWord) !== FALSE) {
                return $badWord;
            }
        }
        return NULL;
    }
}
