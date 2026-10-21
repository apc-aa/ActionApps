<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CodePoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodePointsTest extends TestCase
{
    private const SITE  = '0123456789abcdef0123456789abcdef';
    private const SLICE = 'fedcba9876543210fedcba9876543210';

    /** @return array<string, array{0: array{table: string, iid: string, sid?: string}, 1: string}> */
    public static function moduleProvider(): array
    {
        return [
            'site spot'      => [['table' => 'site_spot', 'iid' => '4', 'sid' => self::SITE], self::SITE],
            'view'           => [['table' => 'view', 'iid' => '259', 'sid' => self::SLICE], self::SLICE],
            'Code item'      => [['table' => 'content', 'iid' => 'aaaabbbbccccddddaaaabbbbccccdddd', 'sid' => self::SLICE], self::SLICE],
            'slice settings' => [['table' => 'slice', 'iid' => self::SLICE], self::SLICE],
            'poll'           => [['table' => 'polls', 'iid' => self::SITE], self::SITE],
            'object'         => [['table' => 'object_text', 'iid' => 'abcdabcdabcdabcdabcdabcdabcdabcd'], self::SITE],
            'ownerless'      => [['table' => 'object_text', 'iid' => 'ffffffffffffffffffffffffffffffff'], ''],
        ];
    }

    /** @param array{table: string, iid: string, sid?: string} $row */
    #[DataProvider('moduleProvider')]
    public function test_moduleOf_finds_the_module_of_a_code_point(array $row, string $expected): void
    {
        $this->assertSame($expected, CodePoints::moduleOf($row, ['abcdabcdabcdabcdabcdabcdabcdabcd' => [self::SITE]]));
    }
}
