<?php

declare(strict_types=1);

/**
 * {qrpay:<account>:<amount>[:<currency>...]} draws a QR payment code. The library knows upper-case
 * currency codes only, so a lower-case one is read as upper case. An account that is neither an IBAN
 * nor a Czech [prefix-]number/bankcode, a currency the library refuses, or more data than a QR code
 * holds gives the empty picture instead of stopping the page - a wrong payment code is worse than none.
 * The library itself turns any "x/y" into an IBAN unchecked, so a typo in the bank code would pay
 * to another bank.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string}> */
    public static function validAccounts(): array
    {
        return [
            'number/bank'      => [self::ACCOUNT],
            'without prefix'   => ['3456789012/0100'],
            'IBAN'             => ['CZ6508000000192000145399'],
            'IBAN with spaces' => ['CZ65 0800 0000 1920 0014 5399'],
        ];
    }

    #[DataProvider('validAccounts')]
    public function test_expand_validAccount_givesPngDataUri(string $account): void
    {
        self::assertStringStartsWith('data:image/png;base64,', self::expand($account, '100', 'CZK'));
    }

    public function test_expand_lowerCaseCurrency_isReadAsUpperCase(): void
    {
        self::assertSame(self::expand(self::ACCOUNT, '100', 'CZK'), self::expand(self::ACCOUNT, '100', 'czk'));
    }

    public function test_expand_unsupportedCurrency_givesEmptyPicture(): void
    {
        self::assertSame(\NO_PICTURE_DATA, self::expand(self::ACCOUNT, '100', 'XYZ'));
    }

    /** @return array<string, array{string}> */
    public static function invalidAccounts(): array
    {
        return [
            'no bank code'         => ['abc'],
            'letters'              => ['abc/0100'],
            'upper-case letters'   => ['ABC/0100'],
            'five-digit bank code' => ['12-3456789012/01000'],
            'two prefixes'         => ['1-2-3/0100'],
            'eleven-digit number'  => ['12345678901/0100'],
            'twenty digits'        => ['12345678901234567890'],
        ];
    }

    #[DataProvider('invalidAccounts')]
    public function test_expand_invalidAccount_givesEmptyPicture(string $account): void
    {
        self::assertSame(\NO_PICTURE_DATA, self::expand($account, '100'));
    }

    public function test_expand_moreDataThanQrHolds_givesEmptyPicture(): void
    {
        self::assertSame(\NO_PICTURE_DATA, self::expand(self::ACCOUNT, '100', 'CZK', str_repeat('1', 5000)));
    }

    public function test_expand_noAccountOrAmount_givesNothing(): void
    {
        self::assertSame('', self::expand('', '100'));
        self::assertSame('', self::expand(self::ACCOUNT, ''));
    }

    private static function expand(string $account, string $amount, string $currency = '', string $reference_number = ''): string
    {
        return (new \AA_Stringexpand_Qrpay([]))->expand($account, $amount, $currency, $reference_number);
    }
}
