<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — loads autoloader only.
 *
 * Does NOT include locsess.php (which starts sessions and DB connections).
 * Tests that need DB should set up their own mock connections.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load legacy global id/SQL helpers from include/zids.php (is_long_id, pack_id,
// unpack_id, xpack_id, quote, qquote, …). zids.php has no require/include of its
// own (only `use` imports resolved by the autoloader above), so it loads without
// triggering the heavy legacy boot (locsess/DB). Makes these helpers available to
// tests (DB_AA::makeWhere, InputVars id-type, zids characterization).
require_once __DIR__ . '/../include/zids.php';

// Define constants that would normally come from config.php
// with safe test values (no real credentials)
if (!defined('AA_ID')) {
    // a long id, as a real one: batch runs (Central) belong to it
    define('AA_ID', '7e5700000000000000000000000000aa');
}
if (!defined('DB_TYPE')) {
    define('DB_TYPE', 'mysql');
}
if (!defined('ENABLE_PAGE_CACHE')) {
    define('ENABLE_PAGE_CACHE', false);
}
