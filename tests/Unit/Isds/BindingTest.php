<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Account;
use AA\Isds\Binding;
use PHPUnit\Framework\TestCase;

final class BindingTest extends TestCase
{
    private const SITE = '51de0000000000000000000000000001';

    // Accounts never asks for an empty pair; a broken binding must not match one anyway
    public function test_binds_brokenSlicesNothing(): void
    {
        $binding = new Binding('NSZM', Account::TEST, [self::SITE], '', '', ['Set AA_APIKEY_ISDSTEST_NSZM_SLICES'], true);

        self::assertFalse($binding->binds(self::SITE, '', ''));
        self::assertFalse($binding->usable());
    }
}
