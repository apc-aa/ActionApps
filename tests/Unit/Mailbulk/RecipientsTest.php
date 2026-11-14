<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mailbulk\Recipients;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;

// the in-memory object_text and the config constants AA_Object needs
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/** The readers a sending goes to, frozen over TableDb's object_text: they come back as they went in. */
final class RecipientsTest extends TestCase
{
    use BatchRunTables;

    private const ITEM    = 'cafe0000000000000000000000000001';
    private const READERS = [
        'b0000000000000000000000000000002',
        'a0000000000000000000000000000001',
        'c0000000000000000000000000000003',
    ];

    public function test_freeze_thenIds_sameOrder(): void
    {
        $id = Recipients::freeze(self::ITEM, self::READERS);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertSame(self::READERS, Recipients::ids($id));
        self::assertSame(self::ITEM, self::stored($id)['aa_owner']);
    }

    public function test_ids_unknown_isEmpty(): void
    {
        self::assertSame([], Recipients::ids('d0000000000000000000000000000004'));
        self::assertSame([], Recipients::ids(''));
        TableDb::$tables['object_text'][] = ['id' => 998, 'object_id' => self::ITEM, 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set', 'flag' => null];
        TableDb::$tables['object_text'][] = ['id' => 999, 'object_id' => self::ITEM, 'property' => 'ids', 'priority' => 0, 'value' => self::READERS[0], 'flag' => null];
        self::assertSame([], Recipients::ids(self::ITEM), 'an object of another type');
    }

    public function test_freeze_rejectsWhatIsNotALongId(): void
    {
        foreach ([[strtoupper(self::READERS[0])], [substr(self::READERS[0], 1)], [''], [self::READERS[0] . ','], [1]] as $readers) {
            try {
                Recipients::freeze(self::ITEM, $readers);
                self::fail('frozen: ' . var_export($readers, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Recipients::freeze('item', self::READERS);
    }

    // object_text.value is text: a long list goes over rows of 512 KiB, in order whatever order the database returns
    public function test_freeze_longListOverRowsOfAtMost512KiB(): void
    {
        $readers = array_map(static fn(int $i): string => sprintf('%032x', $i), range(1, Recipients::PER_ROW + 1));
        $id      = Recipients::freeze(self::ITEM, $readers);
        $rows    = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'ids');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(512 * 1024, strlen((string) $row['value']));
        }
        TableDb::$tables['object_text'] = array_reverse(TableDb::rows('object_text'));
        self::assertSame($readers, Recipients::ids($id));
    }

    public function test_freeze_emptyList(): void
    {
        self::assertSame([], Recipients::ids(Recipients::freeze(self::ITEM, [])));
    }
}
