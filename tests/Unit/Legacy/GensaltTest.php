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

    use PHPUnit\Framework\TestCase;

    /**
     * gensalt() makes the access codes of readers (rnd inserter) and element ids. All 62 letters
     * and digits must be possible - '7' never was, '5' came twice as often.
     */
    final class GensaltTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/util.php';
        }

        public function test_length_and_alphabet(): void
        {
            $this->assertSame('', gensalt(0));
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{12}$/D', gensalt(12));

            // 62 * (61/62)^5000 ~ 1e-33: a missing character means it can never come
            $used = count_chars(gensalt(5000), 3);
            $this->assertSame(62, strlen($used), 'every letter and digit appears');
        }
    }
}
