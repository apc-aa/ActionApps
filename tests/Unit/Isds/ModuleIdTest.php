<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\ModuleId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleIdTest extends TestCase
{
    #[DataProvider('ids')]
    public function test_normalize(string $id, ?string $expected): void
    {
        self::assertSame($expected, ModuleId::normalize($id));
    }

    public static function ids(): array
    {
        $id = 'cafe0000000000000000000000000001';
        return [
            'small letters' => [$id, $id],
            'capitals'      => [strtoupper($id), $id],
            'empty'         => ['', null],
            'short'         => [substr($id, 1), null],
            'long'          => [$id . '0', null],
            'not hex'       => ['g' . substr($id, 1), null],
            'space around'  => [" $id", null],
            'packed'        => [(string) hex2bin($id), null],
        ];
    }
}
