<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckSecret;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class CheckSecretTest extends TestCase
{
    private const SECRET = 'a-long-random-string-of-at-least-32-bytes';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    public function test_aa_secret_set_is_ok(): void
    {
        $this->assertSame(Finding::OK, CheckSecret::assess(self::SECRET, '')->severity, 'with AA_SECRET the database password does not matter');
    }

    public function test_short_aa_secret_is_an_error(): void
    {
        $this->assertSame(Finding::ERROR, CheckSecret::assess(substr(self::SECRET, 0, 31), 'a-long-db-password')->severity, 'a short AA_SECRET is ignored');
    }

    public function test_missing_aa_secret_with_a_strong_db_password_is_a_warning_with_the_fix(): void
    {
        $finding = CheckSecret::assess('', 'a-long-db-password');
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString("define('AA_SECRET'", $finding->advice);
    }

    public function test_missing_aa_secret_with_a_weak_db_password_is_an_error(): void
    {
        $this->assertSame(Finding::ERROR, CheckSecret::assess('', '')->severity);
        $this->assertSame(Finding::ERROR, CheckSecret::assess('', '12345678901')->severity);
        $this->assertSame(Finding::WARNING, CheckSecret::assess('', '123456789012')->severity);
    }
}
