<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\RegisterRecord;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;

// the in-memory object_text, the config constants and the stored() helpers of the batch run tests
require_once __DIR__ . '/../../Later/Fixtures/BatchRunTables.php';

/** RegisterRecord over TableDb's object_text */
final class RegisterRecordTest extends TestCase
{
    use BatchRunTables;

    public function test_read_noRecordIsEmpty(): void
    {
        self::assertSame(RegisterIndex::RECORD, RegisterRecord::read());
    }

    public function test_write_firstSavesTheRecordThenOnlyTheFields(): void
    {
        RegisterRecord::write(['xml' => 'aa11', 'size' => 17000000, 'time' => 1760000000]);
        $stored = self::stored(self::id());
        self::assertSame([RegisterRecord::class, AA_ID], [$stored['aa_type'] ?? null, $stored['aa_owner'] ?? null], 'owned by the install');
        $typeRow = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] === self::id()) && ($r['property'] === 'aa_type')))[0]['id'];

        RegisterRecord::write(['index' => 'bb22', 'nonsense' => 'x']);

        self::assertSame(['xml' => 'aa11', 'size' => '17000000', 'time' => '1760000000', 'index' => 'bb22'] + RegisterIndex::RECORD, RegisterRecord::read());
        $again = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] === self::id()) && ($r['property'] === 'aa_type')))[0]['id'];
        self::assertSame($typeRow, $again, 'a later write changes only its fields');
        self::assertArrayNotHasKey('nonsense', self::stored(self::id()));
    }

    // update([]) would delete the whole object, aa_type and aa_owner too
    public function test_write_nothingToWriteKeepsTheRecord(): void
    {
        RegisterRecord::write(['xml' => 'aa11']);
        TableDb::$log = [];

        RegisterRecord::write(['nonsense' => 'x']);
        RegisterRecord::write([]);

        self::assertSame([], TableDb::$log, 'not a statement');
        self::assertSame(['xml' => 'aa11'] + RegisterIndex::RECORD, RegisterRecord::read());
        self::assertSame(RegisterRecord::class, self::stored(self::id())['aa_type'] ?? null);
    }

    // a reader sees all of a change or none of it
    public function test_write_inOneTransaction(): void
    {
        RegisterRecord::write(['xml' => 'aa11']);
        TableDb::$log  = [];
        TableDb::$fail = static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, "'failure'");
        try {
            RegisterRecord::write(['failed' => 5, 'failure' => 'no']);
            self::fail('the write of failure fails');
        } catch (\Throwable) {
            TableDb::$fail = null;
        }

        self::assertSame('BEGIN', TableDb::$log[0]);
        self::assertSame(['xml' => 'aa11'] + RegisterIndex::RECORD, RegisterRecord::read(), 'the half-written change rolled back');
    }

    private static function id(): string
    {
        return md5('aa-isds-register:' . AA_ID);
    }
}
