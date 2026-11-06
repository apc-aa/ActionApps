<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php, which needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
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
}

namespace Tests\Unit\Legacy {

    use AA_Alias;
    use AA_Aliases;
    use AA_Item;
    use PHPUnit\Framework\TestCase;

    /** AA_Item::get_alias_subst() (include/item.php) reports an alias whose function does not exist;
     *  the alias definition is slice-admin text and the message lands in the public page */
    final class ItemWrongAliasTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        public function test_wrongAliasFunction_isReportedEscaped(): void
        {
            $aliases = (new AA_Aliases())->addAlias(new AA_Alias('_#BADFUNC_', 'f_<img src=x>', 'headline........'));
            $item    = new AA_Item(['headline........' => [['value' => 'x']]], $aliases);

            $out = $item->get_alias_subst('_#BADFUNC_');

            self::assertStringContainsString('f_&lt;img src=x&gt;', $out);
            self::assertStringNotContainsString('<img', $out);
        }
    }
}
