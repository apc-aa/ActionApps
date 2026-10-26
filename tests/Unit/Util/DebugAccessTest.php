<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Util;

use AA\Util\DebugAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebugAccessTest extends TestCase
{
    private const KEY = '0123456789abcdef0123';

    protected function tearDown(): void
    {
        DebugAccess::fromRequest(null, null, '', '');   // nothing waits for the next test
    }

    /** @return array<string, array{mixed, mixed, string, string, int}> */
    public static function requests(): array
    {
        return [
            'key matches'                 => ['150', null, self::KEY, self::KEY, 150],
            'key matches, cookie switch'  => [null, '1', self::KEY, self::KEY, 1],
            'key matches, ?debug first'   => ['2', '1', self::KEY, self::KEY, 2],
            'wrong key'                   => ['150', null, self::KEY . 'x', self::KEY, 0],
            'empty key given'             => ['150', null, '', self::KEY, 0],
            'no key configured'           => ['150', null, '', '', 0],
            'no key configured, any key'  => ['150', null, 'anything', '', 0],
            'configured key too short'    => ['150', null, 'short', 'short', 0],
            'debug cookie, no key'        => ['2', null, '', self::KEY, 0],
            'aa_debug cookie, no key'     => [null, '1', '', self::KEY, 0],
            'key, nothing asked'          => [null, null, self::KEY, self::KEY, 0],
            'key, array debug'            => [['2'], null, self::KEY, self::KEY, 0],
        ];
    }

    #[DataProvider('requests')]
    public function test_fromRequest_grantsOnlyWithTheKey(mixed $debug, mixed $aa_debug, string $given, string $configured, int $expected): void
    {
        $this->assertSame($expected, DebugAccess::fromRequest($debug, $aa_debug, $given, $configured));
    }

    public function test_pending_levelWaitsWithoutTheKey(): void
    {
        DebugAccess::fromRequest('150', null, '', self::KEY);

        $this->assertSame(150, DebugAccess::pending());
    }

    public function test_pending_nothingWaitsWhenTheKeyGranted(): void
    {
        DebugAccess::fromRequest('150', null, self::KEY, self::KEY);

        $this->assertSame(0, DebugAccess::pending());
    }

    public function test_granted_levelOfTheKey(): void
    {
        DebugAccess::fromRequest('6', null, self::KEY, self::KEY);

        $this->assertSame(6, DebugAccess::granted());
    }

    public function test_granted_zeroWithoutTheKey(): void
    {
        DebugAccess::fromRequest('6', null, '', self::KEY);

        $this->assertSame(0, DebugAccess::granted());
    }

    public function test_granted_levelGrantedToSuperadmin(): void
    {
        DebugAccess::fromRequest('6', null, '', '');
        DebugAccess::grant(0, static fn(): bool => true);

        $this->assertSame(6, DebugAccess::granted());
    }

    public function test_grant_superadminGetsTheLevelAskedFor(): void
    {
        DebugAccess::fromRequest('150', null, '', '');

        $this->assertSame(150, DebugAccess::grant(0, static fn(): bool => true));
    }

    public function test_grant_superadminViaTheCookieSwitch(): void
    {
        DebugAccess::fromRequest(null, '1', '', '');

        $this->assertSame(1, DebugAccess::grant(0, static fn(): bool => true));
    }

    public function test_grant_otherUserGetsNothing(): void
    {
        DebugAccess::fromRequest('150', '1', 'wrong-key-wrong-key', self::KEY);

        $this->assertSame(0, DebugAccess::grant(0, static fn(): bool => false));
    }

    public function test_grant_permissionsNotAskedWithoutDebug(): void
    {
        DebugAccess::fromRequest(null, null, '', '');

        $this->assertSame(0, DebugAccess::grant(0, function (): bool {
            $this->fail('no ?debug - the permissions must not be read');
        }));
    }

    public function test_grant_levelGrantedByKeyStays(): void
    {
        $level = DebugAccess::fromRequest('6', null, self::KEY, self::KEY);

        $this->assertSame(6, DebugAccess::grant($level, function (): bool {
            $this->fail('granted already - the permissions must not be read');
        }));
    }

    /** pageOpen() runs twice on some pages - a visitor first, the login later */
    public function test_grant_waitsForALaterLogin(): void
    {
        DebugAccess::fromRequest('2', null, '', '');
        DebugAccess::grant(0, static fn(): bool => false);

        $this->assertSame(2, DebugAccess::grant(0, static fn(): bool => true));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function servers(): array
    {
        return [
            'header sent'   => [['HTTP_X_AA_DEBUG_KEY' => self::KEY], self::KEY],
            'no header'     => [['QUERY_STRING' => 'debugkey=' . self::KEY], ''],
            'not a string'  => [['HTTP_X_AA_DEBUG_KEY' => ['x']], ''],
        ];
    }

    /** @param array<string, mixed> $server */
    #[DataProvider('servers')]
    public function test_givenKey_readsOnlyTheHeader(array $server, string $expected): void
    {
        $this->assertSame($expected, DebugAccess::givenKey($server));
    }

    /** @return array<string, array{mixed, int, ?int}> */
    public static function dryRuns(): array
    {
        return [
            'not asked for'          => [null, 0, 0],
            'not asked, debug on'    => [null, 1, 0],
            'asked for and granted'  => ['2', 2, 2],
            'asked for, not granted' => ['1', 0, null],
            'zero runs for real'     => ['0', 0, 0],
            'empty runs for real'    => ['', 0, 0],
            'text, not granted'      => ['abc', 0, null],
            'array, not granted'     => [['1'], 0, null],
            'text, granted'          => ['abc', 1, 1],
        ];
    }

    #[DataProvider('dryRuns')]
    public function test_dryRunLevel_refusedWhenNotGranted(mixed $debug, int $granted, ?int $expected): void
    {
        $this->assertSame($expected, DebugAccess::dryRunLevel($debug, $granted));
    }
}
