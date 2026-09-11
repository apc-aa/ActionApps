<?php

/**
 * Stand-in for the getDB()/freeDB() pair of include/locsess.php - the test bootstrap
 * never loads that file, and the guard below steps aside if anything ever does.
 * Behaviour is identical - same $spareDBs pool, same DB_AA transaction hooks - so the
 * tests exercise the real contract. The only difference: an empty pool is an error
 * here instead of opening a database connection.
 */

declare(strict_types=1);

if (!function_exists('getDB')) {
    function getDB()
    {
        global $spareDBs;
        if ($tx_db = \AA\IO\DB\DB_AA::transactionDb()) {
            return $tx_db;
        }
        if (!($db = array_pop($spareDBs))) {
            throw new RuntimeException('test pool exhausted - prefill $GLOBALS[\'spareDBs\']');
        }
        return $db;
    }

    function freeDB(&$db): void
    {
        if (!\AA\IO\DB\DB_AA::releaseTransactionDb($db)) {
            $GLOBALS['spareDBs'][] = $db;
        }
        $db = null;
    }
}
