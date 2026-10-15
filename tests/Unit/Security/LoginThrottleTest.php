<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\LoginThrottle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How long a login waits, counted from the LOGIN rows of the log (AA_Log::context() selectors). */
final class LoginThrottleTest extends TestCase
{
    private const NOW = 1790000000;
    private const IP  = '192.0.2.1';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // ParamImplode(), ParamExplode(), _m()
    }

    /** a LOGIN row as AA_Auth and AA_Perm write it; $uid '' = failed */
    private static function row(int $ago, string $name, string $ip = self::IP, string $uid = '', string $type = 'LOGIN'): array
    {
        return [
            'time'     => (string) (self::NOW - $ago),
            'type'     => $type,
            'selector' => ParamImplode([$uid, '0123456789abcdef0123456789abcdef', str_replace(':', '_', $ip), 'example.org/admin/index.php?x=a:b', 'Qa']),
            'params'   => $name,
        ];
    }

    /** $count failures of $name, one a minute, the last $last_ago seconds ago */
    private static function failures(int $count, string $name, int $last_ago = 0, string $ip = self::IP): array
    {
        $rows = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $rows[] = self::row($last_ago + 60 * $i, $name, $ip);
        }
        return $rows;
    }

    public function test_a_few_failures_do_not_wait(): void
    {
        $this->assertSame(0, LoginThrottle::wait(self::failures(4, 'admin'), 'admin', self::IP, self::NOW));
    }

    /** @return array<string, array{int, int}> failures of one name => seconds to wait right after the last */
    public static function nameWaits(): array
    {
        return [
            '5th'  => [5, 30],
            '6th'  => [6, 60],
            '9th'  => [9, 480],
            '11th' => [11, 900],
            '19th' => [19, 900],
        ];
    }

    #[DataProvider('nameWaits')]
    public function test_failures_of_one_name_double_the_wait_up_to_15_minutes(int $failures, int $wait): void
    {
        $this->assertSame($wait, LoginThrottle::wait(self::failures($failures, 'admin'), 'admin', self::IP, self::NOW));
    }

    public function test_the_wait_counts_from_the_last_failure(): void
    {
        $rows = self::failures(6, 'admin', 45);
        $this->assertSame(15, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW));
        $this->assertSame(0, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW + 15));
    }

    public function test_the_name_counts_however_it_is_typed(): void
    {
        $rows = array_merge(self::failures(3, 'Admin '), self::failures(2, 'admin'));
        $this->assertSame(30, LoginThrottle::wait($rows, ' ADMIN', self::IP, self::NOW));
    }

    public function test_rows_without_a_name_are_no_guesses(): void
    {
        $rows = array_merge(self::failures(30, ''), [['time' => (string) self::NOW, 'selector' => self::row(0, '')['selector'], 'params' => null]]);
        $this->assertSame(0, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW));
    }

    public function test_a_failure_of_template_credentials_counts_only_for_its_name(): void
    {
        $rows = [];
        for ($i = 24; $i >= 0; --$i) {
            $rows[] = self::row($i, "example$i", self::IP, '', LoginThrottle::TEMPLATE);
        }
        $this->assertSame(0, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW), 'the visitor of a page with wrong example credentials');
        for ($i = 4; $i >= 0; --$i) {
            $rows[] = self::row($i, 'admin', self::IP, '', LoginThrottle::TEMPLATE);
        }
        $this->assertSame(30, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW), 'one name guessed through a template');
    }

    public function test_another_name_from_the_address_waits_only_by_the_address_counter(): void
    {
        $this->assertSame(0, LoginThrottle::wait(self::failures(8, 'admin'), 'editor', self::IP, self::NOW));
    }

    public function test_the_right_password_forgets_the_failures_of_its_name_not_of_the_address(): void
    {
        $rows   = array_merge(self::failures(8, 'admin', 120), [self::row(60, 'admin', self::IP, '7')], self::failures(2, 'admin'));
        $this->assertSame(0, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW));
        $rows[] = self::row(0, 'admin', self::IP, '7');
        $rows   = array_merge($rows, self::failures(10, 'guest'));
        $this->assertSame(60, LoginThrottle::wait($rows, 'admin', self::IP, self::NOW), 'still 20 failures from the address');
    }

    /** @return array<string, array{int, int}> failures from the address (each of another name) => seconds to wait */
    public static function addressWaits(): array
    {
        return [
            '19th' => [19, 0],
            '20th' => [20, 60],
            '22nd' => [22, 240],
            '26th' => [26, 3600],
        ];
    }

    #[DataProvider('addressWaits')]
    public function test_failures_from_one_address_double_the_wait_up_to_an_hour(int $failures, int $wait): void
    {
        $rows = [];
        for ($i = $failures - 1; $i >= 0; --$i) {
            $rows[] = self::row($i, "user$i");
        }
        $this->assertSame($wait, LoginThrottle::wait($rows, 'someone', self::IP, self::NOW));
    }

    /** @return array<string, array{string, string, bool}> address of the rows, address of the login, counted together;
     *  as the server writes them - IPv6 compressed, lowercase */
    public static function addresses(): array
    {
        return [
            'another IPv4 ending alike'     => ['11.2.3.4', '1.2.3.4', false],
            'IPv4 mapped to IPv6'           => ['::ffff:192.0.2.1', '192.0.2.1', true],
            'the same IPv6 /64'             => ['2001:db8:0:ab00::1', '2001:db8:0:ab00:1:2:3:4', true],
            'another IPv6 /64 of a /56'     => ['2001:db8:0:ab01::1', '2001:db8:0:ab00::1', false],
            'IPv6 starting with zeros'      => ['::2', '::1', true],
        ];
    }

    #[DataProvider('addresses')]
    public function test_the_rows_of_the_address_count(string $logged, string $login, bool $together): void
    {
        $this->assertSame($together ? 30 : 0, LoginThrottle::wait(self::failures(5, 'admin', 0, $logged), 'admin', $login, self::NOW));
    }

    #[DataProvider('addresses')]
    public function test_the_sql_needle_finds_every_row_of_the_address(string $logged, string $login, bool $together): void
    {
        $needle = (new \ReflectionMethod(LoginThrottle::class, 'needle'))->invoke(null, $login);
        if ($together) {
            $this->assertStringContainsString($needle, self::row(0, 'admin', $logged)['selector']);
        }
        $this->assertStringContainsString($needle, self::row(0, 'admin', $login)['selector']);
    }

    public function test_no_address_is_not_counted(): void
    {
        $this->assertNull((new \ReflectionMethod(LoginThrottle::class, 'needle'))->invoke(null, ''));
    }

    public function test_the_stored_name_is_cut_to_the_column_without_splitting_a_character(): void
    {
        $this->assertSame(str_repeat('ž', 64), LoginThrottle::stored(str_repeat('ž', 100)));
        $rows = self::failures(5, LoginThrottle::stored(str_repeat('ž', 100)));
        $this->assertSame(30, LoginThrottle::wait($rows, str_repeat('ž', 100), self::IP, self::NOW), 'the name typed again matches the stored one');
    }
}
