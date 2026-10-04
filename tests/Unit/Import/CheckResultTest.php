<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CheckResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    #[DataProvider('badResults')]
    public function test_construct_rejectsBadCode(bool $ok, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CheckResult($ok, $code, 'The key was refused');
    }

    public static function badResults(): array
    {
        return [
            'not a code'          => [false, 'bad code'],
            'failure without one' => [false, ''],
            'ok with a bad one'   => [true, 'ok'],
        ];
    }

    public function test_construct_okNeedsNoCode(): void
    {
        self::assertTrue((new CheckResult(true, '', ''))->ok);
        self::assertSame('AUTH', (new CheckResult(false, 'AUTH', 'Refused'))->code);
    }
}
