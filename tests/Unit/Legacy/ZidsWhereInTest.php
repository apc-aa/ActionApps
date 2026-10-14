<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use zids;

/**
 * zids::whereIn() hands DB_AA the condition zids::sqlin() writes into SQL by hand.
 * Both must select the same rows, so every case compares the two WHERE clauses.
 */
final class ZidsWhereInTest extends TestCase
{
    private const L1 = '0123456789abcdef0123456789abcdef';
    private const L2 = 'fedcba9876543210fedcba9876543210';

    /** sqlin() as a whole WHERE clause - an empty list selects nothing */
    private static function sqlinWhere(zids $zids, string $column = 'short_or_long', bool $asis = false): string
    {
        $sql = trim($zids->sqlin($column, $asis));
        return ($sql === '0') ? 'WHERE 2=1' : "WHERE $sql";
    }

    public static function zidsProvider(): array
    {
        return [
            'long one'          => [new zids([self::L1], 'l')],
            'long two'          => [new zids([self::L1, self::L2], 'l')],
            'packed two'        => [new zids([pack_id(self::L1), pack_id(self::L2)], 'p')],
            'tagged two'        => [new zids(['x' . self::L1, 'y' . self::L2], 't')],
            'short one'         => [new zids(['12'], 's')],
            'short two'         => [new zids(['12', '34'], 's')],
            'long empty'        => [new zids([], 'l')],
            'invalid long'      => [new zids(["x'; --"], 'l')],
        ];
    }

    #[DataProvider('zidsProvider')]
    public function test_whereIn_matches_sqlin_for_default_column(zids $zids): void
    {
        $this->assertSame(self::sqlinWhere($zids), DB_AA::makeWhere([$zids->whereIn()]));
    }

    #[DataProvider('zidsProvider')]
    public function test_whereIn_matches_sqlin_for_given_column(zids $zids): void
    {
        $this->assertSame(self::sqlinWhere($zids, 'item_id'), DB_AA::makeWhere([$zids->whereIn('item_id')]));
    }

    #[DataProvider('zidsProvider')]
    public function test_whereIn_matches_sqlin_asis(zids $zids): void
    {
        $this->assertSame(self::sqlinWhere($zids, 'object_id', true), DB_AA::makeWhere([$zids->whereIn('object_id', true)]));
    }

    public function test_whereIn_picks_short_id_column_for_short_ids(): void
    {
        $this->assertSame("WHERE item.short_id IN ('12','34')", DB_AA::makeWhere([(new zids(['12', '34'], 's'))->whereIn()]));
        $this->assertSame('WHERE item.id = 0x' . self::L1, DB_AA::makeWhere([(new zids([self::L1], 'l'))->whereIn()]));
    }
}
