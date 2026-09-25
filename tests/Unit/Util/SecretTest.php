<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Each test defines the config constants itself, so it runs in its own process. */
final class SecretTest extends TestCase
{
    private const SECRET = 'a-long-random-string-of-at-least-32-bytes';

    private static function config(?string $aa_secret): void
    {
        define('DB_PASSWORD', 'db-pw');
        define('DB_NAME', 'aadb');
        if ($aa_secret !== null) {
            define('AA_SECRET', $aa_secret);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_aa_secret_is_the_master_and_every_purpose_has_its_own_key(): void
    {
        self::config(self::SECRET);
        $this->assertSame(hash_hkdf('sha256', self::SECRET, 32, 'aa:jwt'), Secret::key('jwt'));
        $this->assertSame(Secret::key('jwt'), Secret::key('jwt'));
        $this->assertNotSame(Secret::key('jwt'), Secret::key('mail-form'));
    }

    /** @return array<string, array{0: ?string}> */
    public static function unusableSecrets(): array
    {
        return ['not defined' => [null], 'empty' => [''], 'shorter than 32 bytes' => [substr(self::SECRET, 0, 31)]];
    }

    /** the fallback must stay the same across updates - a change would log out every Central */
    #[DataProvider('unusableSecrets')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_a_usable_aa_secret_the_master_comes_from_the_db_password(?string $aa_secret): void
    {
        self::config($aa_secret);
        $master = hash_hkdf('sha256', "db-pw\0" . AA_ID . "\0aadb", 32, 'aa:master');
        $this->assertSame(hash_hkdf('sha256', $master, 32, 'aa:jwt'), Secret::key('jwt'));
    }
}
