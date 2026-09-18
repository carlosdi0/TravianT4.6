<?php

// The profanity and URL filters read comma separated files that ship empty in
// this repository. explode(",", "") yields a single "" entry, strpos() finds ""
// at offset 0, and every name and message on the server was rejected as spam:
// renaming a village silently failed and repeated tries fed the ban counter.
// These invariants pin the empty list as "nothing is filtered".

require_once __DIR__ . '/../main_script/include/Core/Helper/BadWordsFilter.php';
require_once __DIR__ . '/../main_script/include/Core/Helper/StringChecker.php';

use Core\Helper\BadWordsFilter;
use Core\Helper\StringChecker;

function filter_fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$emptyFile = explode(",", "");
$paddedFile = explode(",", "spam.com,,another.net,");

// An empty or comma padded filter file filters nothing.
foreach (['Dio`s village', 'PruebaClaude', 'a'] as $name) {
    if (BadWordsFilter::matchBadWord($name, $emptyFile) !== NULL) {
        filter_fail("An empty bad words file rejected \"$name\".");
    }
    if (StringChecker::matchFilteredUrl($name, $emptyFile) !== NULL) {
        filter_fail("An empty URL file rejected \"$name\".");
    }
    if (BadWordsFilter::matchBadWord($name, $paddedFile) !== NULL) {
        filter_fail("A trailing comma in the bad words file rejected \"$name\".");
    }
    if (StringChecker::matchFilteredUrl($name, $paddedFile) !== NULL) {
        filter_fail("A trailing comma in the URL file rejected \"$name\".");
    }
}

// A populated list still catches what it used to.
if (BadWordsFilter::matchBadWord('somethingspammy', ['spammy', 'other']) !== 'spammy') {
    filter_fail('A listed bad word was not caught.');
}
if (StringChecker::matchFilteredUrl('visit spam.com now', $paddedFile) !== 'spam.com') {
    filter_fail('A listed domain was not caught whole.');
}

// The split halves still catch a domain that was broken up to evade the filter.
if (StringChecker::matchFilteredUrl('spam dot com', ['spam.com']) !== 'spam.com') {
    filter_fail('A domain split across the string was not caught.');
}

// One half alone is not a match: that is what made every string look like spam.
if (StringChecker::matchFilteredUrl('spam', ['spam.com']) !== NULL) {
    filter_fail('The domain half alone was treated as a match.');
}

// An entry with no TLD half no longer matches everything.
if (StringChecker::matchFilteredUrl('harmless', ['nodot']) !== NULL) {
    filter_fail('An entry without a dot matched an unrelated string.');
}

// An empty subject is never spam, whatever the list says.
if (BadWordsFilter::matchBadWord('', ['spammy']) !== NULL || StringChecker::matchFilteredUrl('', ['spam.com']) !== NULL) {
    filter_fail('An empty string was reported as filtered.');
}

echo "Content filter regressions passed.\n";
