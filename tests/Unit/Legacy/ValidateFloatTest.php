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

    use AA_Validate_Decimal;
    use AA_Validate_Float;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * The float and decimal validators with a limit left empty in the field settings: an empty
     * limit is no limit ('5' > '' is true in PHP, so every value was "too big").
     */
    final class ValidateFloatTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/validate.php';
        }

        public static function validators(): array
        {
            return ['float' => [AA_Validate_Float::class], 'decimal' => [AA_Validate_Decimal::class]];
        }

        #[DataProvider('validators')]
        public function test_empty_limits_accept_a_number(string $class): void
        {
            $value = '3.5';
            $this->assertTrue((new $class(['f_min' => '', 'f_max' => '']))->validate($value));
        }

        #[DataProvider('validators')]
        public function test_set_limits_still_apply(string $class): void
        {
            $validator = new $class(['f_min' => '1', 'f_max' => '10']);
            $big       = '11';
            $small     = '0.5';
            $ok        = '10';

            $this->assertFalse($validator->validate($big));
            $this->assertFalse($validator->validate($small));
            $this->assertTrue($validator->validate($ok));
        }

        #[DataProvider('validators')]
        public function test_sanitize_with_empty_limits_keeps_the_number(string $class): void
        {
            $this->assertEquals(3.5, (new $class(['f_min' => '', 'f_max' => '']))->sanitize('3.5'));
        }
    }
}
