<?php

declare(strict_types=1);

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

    use AA_Validate;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * A pattern of the site settings ({xcat}, {xflag}) is saved only when it compiles on its own -
     * an unbalanced ")" would otherwise slip past the anchors the router adds.
     */
    final class ValidatePatternTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/validate.php';
        }

        /** @return array<string, array{string, bool}> */
        public static function patterns(): array
        {
            return [
                'empty'               => ['', true],
                'class'               => ['[a-z0-9-]*', true],
                'alternatives'        => ['t|p', true],
                'slash and tilde'     => ['a/b~c', true],
                'unbalanced bracket'  => ['[a-', false],
                'escapes the anchors' => ['a)|(.*', false],
            ];
        }

        #[DataProvider('patterns')]
        public function test_pattern_validator(string $pattern, bool $valid): void
        {
            $this->assertSame($valid, AA_Validate::doValidate($pattern, ['pattern', ['maxlength' => 255]]));
        }
    }
}
