<?php

declare(strict_types=1);

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'AA_BASE_DIR'     => 'aa/',
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

namespace Tests\Unit\Optimize {

    use AA\Optimize\RedefineViewDiscusTemplates;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /** The template new discussion views start from: the links it makes from the comment's fields are quoted. */
    final class RedefineViewDiscusTemplatesTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        public function test_viewDefinition_quotesTheLinksOfTheComment(): void
        {
            $sql = (new ReflectionClass(RedefineViewDiscusTemplates::class))->newInstanceWithoutConstructor()->_viewDefinition();

            self::assertStringContainsString('href="_#WWW_URL#"', $sql);
            self::assertStringContainsString('href="mailto:_#EMAIL###"', $sql);
            self::assertStringContainsString('href="_#URLREPLY"', $sql);
            self::assertDoesNotMatchRegularExpression('/href=[^"]/i', $sql);
        }
    }
}
