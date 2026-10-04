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

    use AA_Content;
    use AA_Value;
    use PHPUnit\Framework\TestCase;

    /**
     * AA_Content::isSet() - whether the content has the field. The expander asks it for every {...}
     * of a template unaliased over an AA_Content (the rows of the object Managers), mostly for names
     * the content has not, so it must answer false without a warning.
     */
    final class ContentIsSetTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        public function test_isSet_trueForAField(): void
        {
            $content = new AA_Content();
            $content->setAaValue('aa_name', new AA_Value('prices'));
            $content->setAaValue('empty', new AA_Value(''));

            $this->assertTrue($content->isSet('aa_name'));
            $this->assertTrue($content->isSet('empty'), 'a field with an empty value is set');
        }

        public function test_isSet_falseWithoutWarningForAMissingField(): void
        {
            $content  = new AA_Content();
            $warnings = [];
            set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
                $warnings[] = $msg;
                return true;
            });
            try {
                $set = $content->isSet('internal:AA_Log:print');
            } finally {
                restore_error_handler();
            }

            $this->assertFalse($set);
            $this->assertSame([], $warnings);
        }
    }
}
