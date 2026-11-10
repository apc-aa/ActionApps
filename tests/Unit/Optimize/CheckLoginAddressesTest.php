<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckLoginAddresses;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/** Failed logins are counted per address - many people under one address wait for each other's failures. */
final class CheckLoginAddressesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    public function test_logins_from_many_addresses_are_ok(): void
    {
        [$finding] = CheckLoginAddresses::assess(40, 12, '192.0.2.1', ['198.51.100.7' => 0]);
        $this->assertSame(Finding::OK, $finding->severity);
    }

    public function test_every_login_from_one_address_warns_of_a_proxy(): void
    {
        [$finding] = CheckLoginAddresses::assess(1, 5, '203.0.113.9', []);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('203.0.113.9', $finding->title);
        $this->assertStringContainsString("'203.0.113.9'", $finding->advice, 'the line to put in config.php');
        $this->assertStringContainsString('AA_TRUSTED_PROXIES', $finding->advice);
    }

    /** @return array<string, mixed> the query of the "Log rows" link */
    private static function logQuery(Finding $finding): array
    {
        parse_str((string) parse_url($finding->links['Log rows'], PHP_URL_QUERY), $query);
        return $query;
    }

    /** the Log page holds tens of thousands of LOGIN rows - the link shows those of the address */
    public function test_log_link_shows_the_rows_of_the_address(): void
    {
        [$one] = CheckLoginAddresses::assess(1, 5, '203.0.113.9', []);
        [$shared] = CheckLoginAddresses::assess(30, 20, '192.0.2.1', ['2001:db8::7' => 3]);

        $query = self::logQuery($one);
        $this->assertSame(['selector', 'type'], $query['srchbr_field']);
        $this->assertSame(['LIKE', '='], $query['srchbr_oper']);
        $this->assertSame([':203.0.113.9:', 'LOGIN'], $query['srchbr_value']);
        $this->assertSame(':2001_db8__7:', self::logQuery($shared)['srchbr_value'][0], 'the selector holds "_" for ":"');
    }

    public function test_one_user_from_one_address_is_no_sign(): void
    {
        [$finding] = CheckLoginAddresses::assess(1, 2, '203.0.113.9', []);
        $this->assertSame(Finding::OK, $finding->severity);
    }

    public function test_a_slowed_down_address_of_several_users_warns(): void
    {
        $findings = CheckLoginAddresses::assess(30, 20, '192.0.2.1', ['2001:db8::7' => 3, '198.51.100.7' => 1]);
        $this->assertCount(1, $findings, 'a scanner or one user waiting alone is no sign');
        $this->assertSame(Finding::WARNING, $findings[0]->severity);
        $this->assertStringContainsString('2001:db8::7', $findings[0]->title);
        $this->assertStringContainsString("'2001:db8::7'", $findings[0]->advice);
    }
}
