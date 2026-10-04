<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Object;
use AA_Property;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;

// the in-memory object_text and the config constants of the batch run tests
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/** opens the protected reader for the test */
final class TextRowsObject extends AA_Object
{
    public $title;

    public static function getClassProperties(): array
    {
        return ['title' => new AA_Property('title', 'Title', 'string', false)];
    }

    /** @param string[] $properties */
    public static function rows(string $id, bool $lock = false, array $properties = []): array
    {
        return self::textRows($id, $lock, $properties);
    }
}

/** AA_Object::textRows() - the one reader of an object's object_text rows past the load() cache */
final class ObjectTextRowsTest extends TestCase
{
    use BatchRunTables;

    private const ID = '0123456789abcdef0123456789abcdef';

    public function test_textRows_allPropertiesAsStrings(): void
    {
        $object = (new TextRowsObject())->setId(self::ID)->setOwnerId(self::SUBJECT);
        $object->title = 'A';
        $object->save();
        self::storedSet('fedcba9876543210fedcba9876543210', 'title', 'other object');

        $rows = TextRowsObject::rows(self::ID);

        self::assertSame(['A', TextRowsObject::class, self::SUBJECT], [$rows['title'] ?? null, $rows['aa_type'] ?? null, $rows['aa_owner'] ?? null]);
        self::assertContainsOnly('string', $rows);
        self::assertStringNotContainsString('FOR UPDATE', (string) end(TableDb::$log));
    }

    public function test_textRows_onlyGivenProperties(): void
    {
        $object = (new TextRowsObject())->setId(self::ID)->setOwnerId(self::SUBJECT);
        $object->title = 'A';
        $object->save();

        self::assertSame(['aa_owner' => self::SUBJECT], TextRowsObject::rows(self::ID, false, ['aa_owner']));
        self::assertSame([], TextRowsObject::rows('fedcba9876543210fedcba9876543210'), 'no object');
    }

    public function test_textRows_lockReadsTheRowsOfTheIdForUpdate(): void
    {
        TableDb::$log = [];

        TextRowsObject::rows(self::ID, true);

        self::assertCount(1, TableDb::$log, 'one statement');
        self::assertStringContainsString(self::ID, TableDb::$log[0]);
        self::assertStringEndsWith(' FOR UPDATE', TableDb::$log[0]);
    }
}
