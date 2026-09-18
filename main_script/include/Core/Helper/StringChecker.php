<?php

namespace Core\Helper;

use Core\Caching\GlobalCaching;
use Core\Database\DB;
use Core\Session;
use Model\InfoBoxModel;

class StringChecker
{
    private static $max_tries = 6;
    private static $max_tries2 = 12;

    public static function isValidName($text)
    {
        $string = self::clearString($text);
        if (empty($string)) return true;
        $badWords = (new BadWordsFilter())->containsBadWords($string, $text);
        $urls = self::checkUrls($string, $text);
        self::checkForBan($badWords, $urls);
        return $badWords && $urls;
    }

    private static function checkForBan($badWords, $urls)
    {
        if ($badWords && $urls) return;
        if (!Session::getInstance()->banned()) {
            $spam_count = SessionVar::getVariable("spamCount", 0, 600);
            SessionVar::setVariable('spamCount', ++$spam_count);
            if ($spam_count >= floor((!$urls ? self::$max_tries : self::$max_tries2) / 2)) {
                Session::getInstance()->setValidationStatus(FALSE);
            }
            if ($spam_count >= (!$urls ? self::$max_tries : self::$max_tries2)) {
                $uid = Session::getInstance()->getPlayerId();
                $reason = !$urls ? 'Spam' : 'Bad name';
                $time = 15 * 60;
                $db = DB::getInstance();
                $exists = $db->fetchScalar("SELECT COUNT(id) FROM banQueue WHERE uid=$uid");
                if (!$exists) {
                    $db->query("UPDATE users SET access=0 WHERE id=$uid");
                    if ($db->affectedRows()) {
                        $db->query("INSERT INTO `banQueue`(`uid`, `reason`, `time`, `end`) VALUES ($uid, '$reason', " . time() . ", " . ($time == 0 ? $time : $time + time()) . ")");
                        $db->query("INSERT INTO `banHistory`(`uid`, `reason`, `time`, `end`) VALUES ($uid, '$reason', " . time() . ", " . ($time == 0 ? $time : $time + time()) . ")");
                        $db->query("DELETE FROM multiaccount_users WHERE uid=$uid");
                        (new InfoBoxModel())->addInfo($uid, 0, 14, '', time(), time() + 365 * 86400);
                        Notification::notify("Spam blocked",
                            "A player was banned because he reached maximum spam limit.");
                    }
                }
            }
        }
    }

    public static function isValidMessage($text)
    {
        $string = self::clearString($text);
        if (empty($string)) return true;
        $badWords = (new BadWordsFilter())->containsBadWords($string, $text);
        $urls = self::checkUrls($string, $text);
        self::checkForBan($badWords, $urls);
        return $badWords && $urls;
    }

    public static function clearString($string)
    {
        $string = str_replace([
            PHP_EOL,
            '!',
            '@',
            '#',
            '$',
            '%',
            '^',
            '&',
            '*',
            '(',
            ')',
            '_',
            '+',
            '*',
            '-',
            '|',
            '[',
            ']',
            ':',
            ';',
            '<',
            '>',
            '\"',
            ',',
            ' ',
            '€',
            '\\',
            '/'
        ],
            '',
            $string);
        $string = str_replace(str_split('¹½⅓¼⅛1¹²⅔³⅜¾⁴⅝4532⅝56⅞789ⁿ∅0@#¢£¥€₱$‰%&_—–·±+{<[]}>†‡★*”“„«»’‘‚‹›:;¡!¿?…/._,~`|♣️♠️♪♥️♦️√•Ππ÷×§¶∆←↑↓→′^″°∞≠≈©®™℅›≥»>≤«‹,'),
            '',
            $string);
        $string = clean_string_from_white($string);
        $string = preg_replace('/\s+/', '', $string);
        $string = str_replace('..', '.', $string);
        $string = str_replace('..', '.', $string);
        $string = strtolower($string);
        return $string;
    }

    public static function checkUrls($string, $mainString)
    {
        $cache = GlobalCaching::getInstance();
        if (!($invalidPages = $cache->get("filteredUrlsCache"))) {
            $invalidPages = explode(",", file_get_contents(FILTERING_PATH . "filteredUrls.txt"));
            $cache->set("filteredUrlsCache", $invalidPages, 2 * 86400);
        }
        $match = self::matchFilteredUrl($string, $invalidPages);
        if ($match === NULL) {
            return true;
        }
        Notification::notify("Spam detected!",
            "Player " . Session::getInstance()->getName() . " is trying to spam domain $match");
        return false;
    }

    /**
     * A domain is matched either whole, or split in two so that "spam" plus
     * "com" is caught as well as "spam.com". Empty entries are skipped: an
     * empty or comma-padded filter file explodes into "" entries, and both
     * strpos($string, "") and a missing TLD half would otherwise match every
     * string and reject every name and message on the server.
     */
    public static function matchFilteredUrl($string, array $invalidPages)
    {
        if ((string)$string === '') {
            return NULL;
        }
        foreach ($invalidPages as $link) {
            $link = trim((string)$link);
            if ($link === '') {
                continue;
            }
            if (strpos($string, $link) !== FALSE) {
                return $link;
            }
            $split = explode(".", $link);
            if (count($split) < 2) {
                continue;
            }
            $domain = $split[0];
            $tld = $split[1];
            if ($domain === '' || $tld === '') {
                continue;
            }
            if (strpos($string, $domain) !== FALSE && strpos($string, $tld) !== FALSE) {
                return $link;
            }
        }
        return NULL;
    }
}
