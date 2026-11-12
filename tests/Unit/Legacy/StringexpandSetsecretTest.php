<?php

declare(strict_types=1);

/**
 * {setsecret} was a debugging command that printed a new 2FA secret and its code. It is kept empty:
 * an unknown command would be printed back as text, with its parameters - a user name and a password.
 * The Central security lint still names it, as old packages may carry it.
 */

namespace Tests\Unit\Legacy;

use AA;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandSetsecretTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_expand_givesAndPrintsNothing(): void
    {
        ob_start();
        try {
            $out = AA::Un('{setsecret:user:pwd}');
        } finally {
            $printed = ob_get_clean();
        }
        self::assertSame('', $out);
        self::assertSame('', $printed);
    }
}
