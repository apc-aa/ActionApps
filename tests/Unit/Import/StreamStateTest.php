<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CheckResult;
use AA\Import\StreamState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Throwable;
use UnexpectedValueException;

// the in-memory object_text, the config constants and the stored() helpers of the batch run tests
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

final class StreamStateTest extends TestCase
{
    use BatchRunTables;

    private const DEF = '0123456789abcdef0123456789abcdef';
    private const RUN = 'fedcba9876543210fedcba9876543210';

    public function test_store_firstSaveThenUpdate_noOrphanRows(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '2024']);
        $state->store();

        $stored = self::stored($state->getId());
        self::assertSame(StreamState::class, $stored['aa_type']);
        self::assertSame(self::DEF, $stored['aa_owner'], 'owned by its definition');
        $typeRow = self::rowId($state->getId(), 'aa_type');

        $again = StreamState::forDefinition(self::DEF);
        self::assertNotNull($again);
        $again->record('b', ['v' => '2025']);
        $again->store();
        self::assertSame($typeRow, self::rowId($state->getId(), 'aa_type'), 'a later store only updates');
        $read = StreamState::forDefinition(self::DEF);
        self::assertSame(['2024', '2025'], [$read?->versionOf('a'), $read?->versionOf('b')]);

        // deleted meanwhile (the settings changed): the next store saves the whole record again
        StreamState::deleteFor(self::DEF);
        $again->record('c', ['v' => '1']);
        $again->store();
        $stored = self::stored($state->getId());
        self::assertSame([StreamState::class, self::DEF], [$stored['aa_type'] ?? null, $stored['aa_owner'] ?? null]);
    }

    public function test_store_inOneTransaction(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '1']);
        $state->store();

        $state->record('a', ['v' => '2']);
        $state->recordCheck(new CheckResult(true, '', ''), 5);
        TableDb::$log  = [];
        TableDb::$fail = static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, "'check'");
        try {
            $state->store();
            self::fail('The write of check fails');
        } catch (Throwable) {
            TableDb::$fail = null;
        }

        self::assertSame('BEGIN', TableDb::$log[0]);
        self::assertSame('1', StreamState::forDefinition(self::DEF)?->versionOf('a'), 'the half-written state rolled back');
    }

    public function test_create_oneRecordPerDefinition(): void
    {
        $first = StreamState::create(self::DEF);
        $first->record('a', ['v' => '1']);
        $first->store();
        $second = StreamState::create(strtoupper(self::DEF));
        $second->record('b', ['v' => '2']);
        $second->store();

        self::assertSame($first->getId(), $second->getId(), 'the id does not depend on the case of the definition id');
        self::assertSame(self::DEF, $second->getOwnerId());
        $state = StreamState::forDefinition(self::DEF);
        self::assertNull($state?->versionOf('a'), 'a new state replaces the stored one');
        self::assertSame('2', $state?->versionOf('b'));
        self::assertCount(1, array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'aa_type'));
    }

    public function test_versionOf_unknownStreamNull(): void
    {
        $state = StreamState::create(self::DEF);
        self::assertNull($state->versionOf('a'));
        $state->record('a', ['imported' => 5]);
        self::assertNull($state->versionOf('a'), 'imported, but no version known');
        $state->record('a', ['v' => '']);
        self::assertSame('', $state->versionOf('a'), 'a source without versions');
    }

    public function test_record_mergesFields(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('123', ['v' => '2024', 'run' => self::RUN]);
        $state->record('123', ['probed' => 7, 'imported' => 8]);
        $state->store();

        self::assertSame(
            ['123' => ['v' => '2024', 'run' => self::RUN, 'probed' => 7, 'imported' => 8]],
            StreamState::forDefinition(self::DEF)?->streams(),
        );
    }

    public function test_store_numericKeysStayAnObject(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('0', ['v' => 'a']);
        $state->record('1', ['v' => 'b']);
        $state->store();

        self::assertSame('{"0":{"v":"a"},"1":{"v":"b"}}', self::stored($state->getId())['streams']);
    }

    public function test_record_versionFromSourceIsCleanAndShort(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => "20\xff\x01\u{202E}24" . str_repeat('x', 1000)]);

        $version = (string) $state->versionOf('a');
        self::assertTrue(mb_check_encoding($version, 'UTF-8'));
        self::assertStringStartsWith(mb_scrub("20\xff", 'UTF-8') . ' 24', $version, 'control character to a space, bidi override gone');
        self::assertLessThanOrEqual(255, strlen($version));
    }

    public function test_record_cutKeepsWholeCharacters(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => str_repeat('a', 254) . '€']);

        self::assertSame(str_repeat('a', 254), $state->versionOf('a'));
    }

    public function test_store_unicodeRoundTripsAsAscii(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => 'Příliš 😀']);
        $state->recordCheck(new CheckResult(false, 'AUTH', 'Klíč 😀'), 5);
        $state->store();

        $stored = self::stored($state->getId());
        self::assertMatchesRegularExpression('/^[\x20-\x7e]*$/', $stored['streams'] . $stored['check'], 'object_text may be cp1250 or utf8mb3');
        $read = StreamState::forDefinition(self::DEF);
        self::assertSame('Příliš 😀', $read?->versionOf('a'));
        self::assertSame('Klíč 😀', $read?->lastCheck()['msg'] ?? null);
    }

    #[DataProvider('badRecords')]
    public function test_record_refusesBadInput(string $key, array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        StreamState::create(self::DEF)->record($key, $values);
    }

    public static function badRecords(): array
    {
        return [
            'stream key with a colon' => ['a:b', ['v' => '1']],
            'unknown field'           => ['a', ['secret' => 'x']],
            'version not a string'    => ['a', ['v' => 2024]],
            'negative time'           => ['a', ['probed' => -1]],
            'time not an int'         => ['a', ['imported' => '7']],
            'run not an id'           => ['a', ['run' => 'run-1']],
        ];
    }

    public function test_recordCheck(): void
    {
        $state = StreamState::create(self::DEF);
        self::assertNull($state->lastCheck());
        $state->recordCheck(new CheckResult(false, 'AUTH', "Key \xff refused\n" . str_repeat('!', 2000)), 1_900_000_000);
        $state->store();

        $check = StreamState::forDefinition(self::DEF)?->lastCheck();
        self::assertNotNull($check);
        self::assertSame([1_900_000_000, false, 'AUTH'], [$check['time'], $check['ok'], $check['code']]);
        self::assertStringStartsWith(mb_scrub("Key \xff refused !", 'UTF-8'), $check['msg']);
        self::assertTrue(mb_check_encoding($check['msg'], 'UTF-8'));
        self::assertLessThanOrEqual(1000, strlen($check['msg']));

        $state->recordCheck(new CheckResult(true, '', ''), 1_900_000_100);
        self::assertSame(['time' => 1_900_000_100, 'ok' => true, 'code' => '', 'msg' => ''], $state->lastCheck());
    }

    public function test_forDefinition_readsOutsideCache(): void
    {
        self::assertNull(StreamState::forDefinition(self::DEF), 'nothing stored yet');
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '1']);
        $state->store();
        self::assertSame('1', StreamState::forDefinition(self::DEF)?->versionOf('a'));

        self::storedSet($state->getId(), 'streams', '{"a":{"v":"2"}}');
        self::assertSame('2', StreamState::forDefinition(self::DEF)?->versionOf('a'), 'another process changed it');
    }

    public function test_damagedJson_actsEmpty(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '1']);
        $state->recordCheck(new CheckResult(true, '', ''), 5);
        $state->store();
        self::storedSet($state->getId(), 'streams', '{"a":{"v":');
        self::storedSet($state->getId(), 'check', '[[[[1]]]]');

        $read = StreamState::forDefinition(self::DEF);
        self::assertNotNull($read);
        self::assertFalse($read->damaged(), 'nothing read yet');
        self::assertNull($read->versionOf('a'), 'a full import');
        self::assertTrue($read->damaged());
        self::assertNull($read->lastCheck(), 'too deep is damaged too');

        $read->record('a', ['v' => '3']);
        $read->store();
        self::assertSame('3', StreamState::forDefinition(self::DEF)?->versionOf('a'), 'the next store heals it');
        self::assertFalse(StreamState::create(self::DEF)->damaged());
    }

    public function test_foreignRecordAtTheId(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '1']);
        $state->store();
        self::storedSet($state->getId(), 'aa_type', 'AA\Import\Catalog');

        self::assertNull(StreamState::forDefinition(self::DEF), 'the right owner, another type');
        StreamState::deleteFor(self::DEF);
        self::assertNotSame([], self::stored($state->getId()), 'a foreign record is not deleted');
        $this->expectException(UnexpectedValueException::class);
        $state->store();
    }

    public function test_foreignOwnerAtTheId(): void
    {
        $state = StreamState::create(self::DEF);
        $state->store();
        self::storedSet($state->getId(), 'aa_owner', self::RUN);

        self::assertNull(StreamState::forDefinition(self::DEF));
        $this->expectException(UnexpectedValueException::class);
        $state->store();
    }

    public function test_store_onlyAtTheIdOfItsDefinition(): void
    {
        $moved = StreamState::create(self::DEF);
        $moved->setId(self::RUN);
        try {
            $moved->store();
            self::fail('Stored at another id');
        } catch (UnexpectedValueException) {
        }
        self::assertSame([], TableDb::rows('object_text'));

        $this->expectException(InvalidArgumentException::class);
        (new StreamState())->store();
    }

    public function test_halfWrittenOwnRecord_counts(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('a', ['v' => '1']);
        $state->store();
        self::dropRow($state->getId(), 'aa_owner');

        $state->record('a', ['v' => '2']);
        $state->store();
        $stored = self::stored($state->getId());
        self::assertSame(self::DEF, $stored['aa_owner'] ?? null, 'saved whole again');
        self::assertSame('2', StreamState::forDefinition(self::DEF)?->versionOf('a'));

        self::dropRow($state->getId(), 'aa_owner');
        StreamState::deleteFor(self::DEF);
        self::assertSame([], self::stored($state->getId()));
    }

    public function test_streams_skipsAnEntryThatIsNoObject(): void
    {
        $state = StreamState::create(self::DEF);
        $state->store();
        self::storedSet($state->getId(), 'streams', '{"a":"x","b":{"v":"1"}}');

        $read = StreamState::forDefinition(self::DEF);
        self::assertNotNull($read);
        self::assertSame(['b' => ['v' => '1']], $read->streams());
        $read->record('a', ['v' => '2']);
        self::assertSame(['v' => '2'], $read->streams()['a']);
    }

    public function test_deleteFor_removesOnlyItsRecord(): void
    {
        $state = StreamState::create(self::DEF);
        $state->store();
        $other = StreamState::create(self::RUN);
        $other->store();

        StreamState::deleteFor(strtoupper(self::DEF));

        self::assertSame([], self::stored($state->getId()));
        self::assertNotNull(StreamState::forDefinition(self::RUN));
        StreamState::deleteFor(self::DEF);   // nothing there: no error
    }

    /** @return array<string, array{\Closure(): mixed}> */
    public static function badIds(): array
    {
        return [
            'create'        => [static fn() => StreamState::create('DEF')],
            'forDefinition' => [static fn() => StreamState::forDefinition('not-an-id')],
            'deleteFor'     => [static fn() => StreamState::deleteFor(self::DEF . '0')],
            'idOf'          => [static fn() => StreamState::idOf("0123456789abcdef0123456789abcde\n")],
        ];
    }

    #[DataProvider('badIds')]
    public function test_definitionId_mustBeLongId(\Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $call();
    }

    private static function dropRow(string $objectId, string $property): void
    {
        TableDb::$tables['object_text'] = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] !== $objectId) || ($r['property'] !== $property)));
    }

    /** the auto-increment id of the row - it changes when the row is deleted and inserted again */
    private static function rowId(string $objectId, string $property): int
    {
        foreach (TableDb::rows('object_text') as $row) {
            if (($row['object_id'] === $objectId) && ($row['property'] === $property)) {
                return (int) $row['id'];
            }
        }
        self::fail("No $property row");
    }
}
