<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckPasskeys;
use AA\Optimize\Finding;
use AA\Passkey\Refused;
use PHPUnit\Framework\TestCase;

final class CheckPasskeysTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    private static function row(string $selector): array
    {
        return ['selector' => $selector, 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => ''];
    }

    public function test_no_refusals_is_ok(): void
    {
        [$finding] = CheckPasskeys::findingsFor([]);
        $this->assertSame(Finding::OK, $finding->severity);
    }

    /** the password did not let anybody in, but someone knows it */
    public function test_a_right_password_is_a_warning_naming_the_login(): void
    {
        [$finding] = CheckPasskeys::findingsFor([self::row(ParamImplode([CheckPasskeys::PASSWORD, 'honza']))]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('honza', $finding->title);
        $this->assertSame(2, $finding->count);
    }

    /** one leaked password tried under several spellings is one kind of problem */
    public function test_rightPasswords_oneKind(): void
    {
        [$a, $b] = CheckPasskeys::findingsFor([self::row(ParamImplode([CheckPasskeys::PASSWORD, 'honza'])), self::row(ParamImplode([CheckPasskeys::PASSWORD, 'Honza']))]);

        $this->assertSame($a->kind, $b->kind);
        $this->assertNotSame($a->title, $b->title);
    }

    public function test_reasons_have_their_severity(): void
    {
        $findings = CheckPasskeys::findingsFor([
            self::row(ParamImplode([Refused::UNKNOWN, ''])),
            self::row(ParamImplode([Refused::ORIGIN, 'host'])),
            self::row(ParamImplode([Refused::CHALLENGE, ''])),
        ]);
        $by_key = array_column(array_map(static fn(Finding $f): array => [$f->key, $f->severity], $findings), 1, 0);

        $this->assertEquals([
            ParamImplode([Refused::UNKNOWN, ''])    => Finding::INFO,
            ParamImplode([Refused::ORIGIN, 'host']) => Finding::WARNING,
            ParamImplode([Refused::CHALLENGE, ''])  => Finding::INFO,
        ], $by_key);
        $this->assertSame(Finding::WARNING, $findings[0]->severity, 'the warning comes first');
    }
}
