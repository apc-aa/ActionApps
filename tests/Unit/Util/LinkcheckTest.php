<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Linkcheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The valid_codes string handling of AA\Util\Linkcheck. check_url()/checking() are
 * not covered here: they need cURL and the DB.
 *
 * A never-checked link has links_links.valid_codes NULL, which used to kill the
 * hourly AA\Later\LinkcheckRun task with a TypeError (remove_old(): Return value
 * must be of type string, null returned).
 */
final class LinkcheckTest extends TestCase
{
    // --- remove_old(): keeps the 10 newest checks ---

    public function test_removeOld_null_returns_empty_string(): void
    {
        self::assertSame('', (new Linkcheck())->remove_old(null));
    }

    public function test_removeOld_empty_string_returns_empty_string(): void
    {
        self::assertSame('', (new Linkcheck())->remove_old(''));
    }

    public function test_removeOld_keeps_short_list_unchanged(): void
    {
        $codes = '1789900000,200:1789800000,301:1789700000,404';
        self::assertSame($codes, (new Linkcheck())->remove_old($codes));
    }

    public function test_removeOld_drops_all_but_the_ten_newest(): void
    {
        // 12 checks, an hour apart, newest first - the two oldest must go
        $codes = [];
        for ($i = 12; $i >= 1; --$i) {
            $codes[] = (1789900000 + $i * 3600) . ',200';
        }
        $kept = (new Linkcheck())->remove_old(implode(':', $codes));

        self::assertSame(implode(':', array_slice($codes, 0, 10)), $kept);
    }

    // --- add_check(): prepends the new check ---

    public function test_addCheck_null_valid_codes_yields_the_first_check(): void
    {
        $check = ['timestamp' => 1789900000, 'code' => '200'];
        self::assertSame('1789900000,200', (new Linkcheck())->add_check($check, null));
    }

    public function test_addCheck_prepends_to_existing_checks(): void
    {
        $check = ['timestamp' => 1789900000, 'code' => '404'];
        self::assertSame(
            '1789900000,404:1789800000,200',
            (new Linkcheck())->add_check($check, '1789800000,200'),
        );
    }

    // --- count_weight(): rank from the check history ---

    /**
     * @return array<string, array{0:string, 1:float}>
     */
    public static function countWeightProvider(): array
    {
        return [
            // 0 weight for 200 -> the best possible rank
            'single OK check'          => ['1789900000,200', 0.0],
            // 5 (Not Found) * 1000 / 14.644841269841, rounded
            'single 404 check'         => ['1789900000,404', 341.0],
            // second check counts a half: (5 + 5/2) * 1000 / 14.644841269841
            'two 404 checks'           => ['1789900000,404:1789800000,404', 512.0],
            // an unknown code must not warn - it is weighted as a plain error
            'code outside the table'   => ['1789900000,599', 341.0],
            // older releases stored a trailing ":" - the empty entry must not count
            'legacy trailing colon'    => ['1789900000,404:', 341.0],
            // junk in the column must not be weighted as an error either
            'entry without a code'     => ['1789900000,404: ', 341.0],
            'entry without a comma'    => ['1789900000,404:garbage', 341.0],
            'no check yet'             => ['', 0.0],
        ];
    }

    // --- the round trip checking() runs: add_check() feeds count_weight() ---

    public function test_history_never_grows_past_ten_checks(): void
    {
        $linkcheck = new Linkcheck();

        $valid_codes = null;
        for ($i = 0; $i < 12; ++$i) {
            $valid_codes = $linkcheck->add_check(['timestamp' => 1789900000 + $i * 3600, 'code' => '200'], $valid_codes);
        }

        $checks = explode(':', $valid_codes);
        self::assertCount(10, $checks);
        self::assertSame('1789939600,200', $checks[0], 'the newest check comes first');
        self::assertSame('1789907200,200', $checks[9], 'the two oldest checks are gone');
        self::assertSame(0.0, $linkcheck->count_weight($valid_codes));
    }

    #[DataProvider('countWeightProvider')]
    public function test_countWeight(string $valid_codes, float $expected): void
    {
        self::assertSame($expected, (new Linkcheck())->count_weight($valid_codes));
    }
}
