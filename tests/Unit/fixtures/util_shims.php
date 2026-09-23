<?php

declare(strict_types=1);

/**
 * Loads the real include/util.php (safe(), _m(), ParamImplode(), get_class_name(), warn(), …)
 * for tests that call legacy global helpers.
 *
 * It used to declare look-alike shims instead. util.php declares its functions unguarded, so a
 * shim loaded first made every later test that pulls util.php in (the item store, CopyTableRows)
 * die with "Cannot redeclare function" - the suite passed only by alphabetical luck.
 * The real file loads without a database or session (locsess.php only declares things), given
 * the constants include/config.php normally provides - safe test values, unless a test set its own.
 */
foreach ([
    'AA_INSTAL_URL'   => 'https://example.org/aa/',
    'AA_INSTAL_PATH'  => '/aa/',
    'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
    'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    'PERM_LIB'        => 'sql',
    'DB_HOST'         => 'localhost',
    'DB_NAME'         => 'aa_test',
    'DB_USER'         => 'aa',
    'DB_PASSWORD'     => '',
] as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}
require_once __DIR__ . '/../../../include/util.php';
