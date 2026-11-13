<?php

declare(strict_types=1);

/**
 * RedefineSlicReaderTemplates - a Reader Management slice made from the template gives every new reader a
 * long access code: the code alone confirms the e-mail and manages the reader's alerts.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'AA_BASE_DIR'     => 'aa/',
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

namespace Tests\Unit\Optimize {

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\RedefineSlicReaderTemplates;
    use PHPUnit\Framework\TestCase;

    final class RedefineSlicReaderTemplatesTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__) . '/Legacy/fixtures/store_fixtures.php';
        }

        protected function tearDown(): void
        {
            AbstractOptimize::justPrint(false);
        }

        public function test_repair_accessCode_24Characters(): void
        {
            AbstractOptimize::justPrint(true);   // only lists the SQL
            $optimize = new RedefineSlicReaderTemplates();

            self::assertTrue($optimize->repair());
            $access_code = array_values(array_filter($optimize->reportRows(), static fn(array $row): bool => str_contains((string) $row['txt'], 'text...........3')));
            self::assertCount(1, $access_code);
            self::assertStringContainsString('rnd:24:text...........3:0', (string) $access_code[0]['txt']);
        }
    }
}
