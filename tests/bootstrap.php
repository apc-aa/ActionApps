<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — loads autoloader only.
 *
 * Does NOT include locsess.php (which starts sessions and DB connections).
 * Tests that need DB should set up their own mock connections.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define constants that would normally come from config.php
// with safe test values (no real credentials)
if (!defined('AA_ID')) {
    define('AA_ID', 'test00000000000000000000000000AA');
}
if (!defined('DB_TYPE')) {
    define('DB_TYPE', 'mysql');
}
if (!defined('ENABLE_PAGE_CACHE')) {
    define('ENABLE_PAGE_CACHE', false);
}

// --- Test polyfills: pure UTF-aware string helpers from include/util.php ---
// Several src/ classes (e.g. AA\Util\Txt::shorten) call these as global
// functions. They are not autoloadable (plain functions), and loading
// include/util.php would drag in the whole legacy boot sequence. Faithful
// copies (verbatim from include/util.php) keep src/ units testable in
// isolation. Extracting them into a src/ class is deferred modernization.
if (!function_exists('aa_strlen')) {
    function aa_strlen($text)
    {
        return grapheme_strlen((string) $text);
    }
}
if (!function_exists('aa_substr')) {
    function aa_substr($text, $start, $len = null): string
    {
        return mb_substr((string) $text, (int) $start, $len);
    }
}
