<?php

declare(strict_types=1);

/**
 * {qrpay:<account>:<amount>[:<currency>...]} draws a QR payment code. The library knows upper-case
 * currency codes only, so a lower-case one is read as upper case. An account or currency it refuses
 * gives no code at all (a wrong payment code is worse than none) instead of stopping the page.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandQrpayTest extends TestCase
{
    private const ACCOUNT = '12-3456789012/0100';

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        } finally {
            restore_error_handler();
        }
    }

    public function test_expand_validPayment_givesPngDataUri(): void
    {
        self::assertStringStartsWith('data:image/png;base64,', self::expand(self::ACCOUNT, '100', 'CZK'));
    }

    public function test_expand_lowerCaseCurrency_isReadAsUpperCase(): void
    {
        self::assertSame(self::expand(self::ACCOUNT, '100', 'CZK'), self::expand(self::ACCOUNT, '100', 'czk'));
    }

    public function test_expand_unsupportedCurrency_givesNothing(): void
    {
        self::assertSame('', self::expand(self::ACCOUNT, '100', 'XYZ'));
    }

    public function test_expand_notABankAccount_givesNothing(): void
    {
        self::assertSame('', self::expand('abc', '100'));
    }

    private static function expand(string $account, string $amount, string $currency = ''): string
    {
        return (new \AA_Stringexpand_Qrpay([]))->expand($account, $amount, $currency);
    }
}
