<?php

declare(strict_types=1);

// AA_Object lives in include/statestore.php, whose require chain reaches include/locsess.php
// and needs the constants include/config.php normally provides. Safe test values, defined
// before the autoloader resolves the first legacy class (same block as ItemStoreFieldsTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Object;
    use AA_Property;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** An object with a property in each of the three storage tables */
    final class LoadedObject extends AA_Object
    {
        public $title;
        public $count;
        public $tags;
        public $ratio;

        public static function getClassProperties(): array
        {
            return [
                'title' => new AA_Property('title', 'Title', 'string', false),
                'count' => new AA_Property('count', 'Count', 'int', false),
                'tags'  => new AA_Property('tags', 'Tags', 'string', true),
                'ratio' => new AA_Property('ratio', 'Ratio', 'float', false),
            ];
        }
    }

    /** An object that owns a LoadedObject stored as a sub-object (by id) */
    final class ParentObject extends AA_Object
    {
        public $child;

        public static function getClassProperties(): array
        {
            return ['child' => new AA_Property('child', 'Child', LoadedObject::class, false)];
        }
    }

    /**
     * Characterization of AA_Object::load() (include/statestore.php): what an object looks
     * like after loading from the object_text / object_integer / object_float rows, and how
     * many statements that takes.
     *
     * Written against the code that probes aa_type with its own query and reads each table
     * separately; kept as the contract for the one-statement load (T5).
     */
    final class ObjectLoadTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        /** object id => table => rows; '*' answers for any id not listed */
        private static array $objects = [];

        protected function setUp(): void
        {
            RecordingDb::reset();
            self::$objects = [];
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        /** the load() cache is static per process - every test gets its own object id */
        private static function id(string $seed): string
        {
            return md5($seed);
        }

        /**
         * Scripts the fake DB with the object's rows: table => [[property, value, priority], ...].
         * Answers the aa_type probe, a single-table read and a UNION read alike - rows of every
         * table the statement names, ordered by (property, priority) as ORDER BY would.
         */
        private static function rows(array $tables, string $object_id = '*'): void
        {
            self::$objects[$object_id] = $tables;
            RecordingDb::$answer = static function (string $sql): array {
                preg_match("/object_id = '([^']*)'/", $sql, $m);
                $tables = self::$objects[$m[1] ?? ''] ?? self::$objects['*'] ?? [];
                if (str_contains($sql, "property = 'aa_type'")) {
                    foreach ($tables['object_text'] ?? [] as [$property, $value]) {
                        if ($property === 'aa_type') {
                            return [['value' => $value]];
                        }
                    }
                    return [];
                }
                $out = [];
                foreach (['object_text', 'object_integer', 'object_float'] as $table) {
                    if (!str_contains($sql, "`$table`")) {
                        continue;
                    }
                    foreach ($tables[$table] ?? [] as [$property, $value, $priority]) {
                        $out[] = ['tbl' => $table, 'property' => $property, 'value' => $value, 'priority' => $priority];
                    }
                }
                usort($out, static fn($a, $b) => [$a['property'], $a['priority']] <=> [$b['property'], $b['priority']]);
                return $out;
            };
        }

        private static function fullObject(): array
        {
            return [
                'object_text'    => [
                    ['aa_type', LoadedObject::class, 0],
                    ['aa_name', 'my object', 0],
                    ['aa_owner', 'owner-id', 0],
                    ['title', 'Hello', 0],
                    ['tags', 'second', 1],
                    ['tags', 'first', 0],
                ],
                'object_integer' => [['count', '3', 0]],
                'object_float'   => [['ratio', '1.5', 0]],
            ];
        }

        public function testLoadsEveryStorageTableIntoTheObject(): void
        {
            self::rows(self::fullObject());

            $obj = AA_Object::load(self::id(__FUNCTION__));

            self::assertInstanceOf(LoadedObject::class, $obj);
            self::assertSame('Hello', $obj->title);
            self::assertSame('3', $obj->count);
            self::assertSame(['first', 'second'], $obj->tags, 'multi values in priority order');
            self::assertSame('1.5', $obj->ratio);
            self::assertSame('my object', $obj->getName());
            self::assertSame('owner-id', $obj->getOwnerId());
            self::assertSame(self::id(__FUNCTION__), $obj->getId());
        }

        public function testTypelessLoadIsOneStatement(): void
        {
            self::rows(self::fullObject());

            AA_Object::load(self::id(__FUNCTION__));

            self::assertCount(1, RecordingDb::$log, implode("\n", RecordingDb::$log));
        }

        public function testTypedLoadIsOneStatement(): void
        {
            self::rows(self::fullObject());

            $obj = AA_Object::load(self::id(__FUNCTION__), LoadedObject::class);

            self::assertInstanceOf(LoadedObject::class, $obj);
            self::assertSame('Hello', $obj->title);
            self::assertSame('3', $obj->count);
            self::assertCount(1, RecordingDb::$log, implode("\n", RecordingDb::$log));
        }

        public function testTypedLoadOfAMissingObjectIsNullAndNotCached(): void
        {
            self::rows([]);

            self::assertNull(AA_Object::load(self::id(__FUNCTION__), LoadedObject::class));
            self::assertNull(AA_Object::load(self::id(__FUNCTION__), LoadedObject::class));
            self::assertCount(2, RecordingDb::$log, 'nothing to cache - asked again');
        }

        public function testNamelessObjectLoadsWithoutAName(): void
        {
            self::rows(['object_text' => [
                ['aa_type', LoadedObject::class, 0],
                ['aa_owner', 'owner-id', 0],
                ['title', 'untitled', 0],
            ]]);

            $obj = AA_Object::load(self::id(__FUNCTION__));

            self::assertSame('untitled', $obj->title);
            self::assertNull($obj->getName());
            self::assertSame('owner-id', $obj->getOwnerId());
        }

        public function testMissingSubObjectHydratesAsAnEmptyObjectOfItsClass(): void
        {
            $child_id = md5(__FUNCTION__ . 'child');
            self::rows(['object_text' => [
                ['aa_type', ParentObject::class, 0],
                ['aa_name', 'parent', 0],
                ['aa_owner', 'owner-id', 0],
                ['child', $child_id, 0],
            ]], self::id(__FUNCTION__));

            $parent = AA_Object::load(self::id(__FUNCTION__));

            self::assertInstanceOf(ParentObject::class, $parent);
            self::assertInstanceOf(LoadedObject::class, $parent->child, 'the parent stays usable');
            self::assertSame($child_id, $parent->child->getId());
            self::assertNull(AA_Object::load($child_id, LoadedObject::class), 'the child itself is gone');
        }

        public function testTypedLoadIsCachedForTheRequest(): void
        {
            self::rows(self::fullObject());
            $first = AA_Object::load(self::id(__FUNCTION__), LoadedObject::class);
            RecordingDb::$log = [];

            $second = AA_Object::load(self::id(__FUNCTION__), LoadedObject::class);

            self::assertSame($first, $second);
            self::assertSame([], RecordingDb::$log);
        }

        public function testMissingObjectIsNull(): void
        {
            self::rows([]);

            self::assertNull(AA_Object::load(self::id(__FUNCTION__)));
            self::assertCount(1, RecordingDb::$log, 'one look-up is enough to tell the object is not there');
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the legacy autoloader warns about an unknown class name
        public function testUnknownClassIsNull(): void
        {
            self::rows(['object_text' => [['aa_type', 'No_Such_Class_' . md5(__FUNCTION__), 0]]]);

            self::assertNull(AA_Object::load(self::id(__FUNCTION__)));
        }

        public function testEmptyIdIsNullWithoutAQuery(): void
        {
            self::assertNull(AA_Object::load(''));
            self::assertSame([], RecordingDb::$log);
        }

        public function testPropertiesWithoutRowsAreEmptyStrings(): void
        {
            self::rows(['object_text' => [
                ['aa_type', LoadedObject::class, 0],
                ['aa_name', 'bare', 0],
                ['aa_owner', 'owner-id', 0],
            ]]);

            $obj = AA_Object::load(self::id(__FUNCTION__));

            self::assertSame('', $obj->title);
            self::assertSame('', $obj->count);
            self::assertSame('', $obj->tags, 'even a multi property - no rows, no array');
            self::assertSame('', $obj->ratio);
        }

        public function testTheLaterTableWinsWhenAPropertyHasRowsInTwo(): void
        {
            $rows = self::fullObject();
            $rows['object_text'][] = ['count', 'stale text row', 0];
            self::rows($rows);

            $obj = AA_Object::load(self::id(__FUNCTION__));

            self::assertSame('3', $obj->count, 'object_integer replaces object_text for the same property');
        }

        public function testTypedLoadReadsOnlyTheTablesTheClassUses(): void
        {
            self::rows(['object_text' => [
                ['aa_type', 'AA_Modulesettings_Site', 0],
                ['aa_name', 'site', 0],
                ['aa_owner', 'owner-id', 0],
            ]]);

            $obj = AA_Object::load(self::id(__FUNCTION__), 'AA_Modulesettings_Site');

            self::assertInstanceOf(\AA_Modulesettings_Site::class, $obj);
            self::assertCount(1, RecordingDb::$log);
            self::assertStringNotContainsString('object_integer', RecordingDb::$log[0], 'a text-only class does not touch object_integer');
            self::assertStringNotContainsString('object_float', RecordingDb::$log[0]);
        }

        public function testUntypedLoadReadsAllThreeTablesInTheOneStatement(): void
        {
            self::rows(self::fullObject());

            AA_Object::load(self::id(__FUNCTION__));

            self::assertCount(1, RecordingDb::$log);
            foreach (['object_text', 'object_integer', 'object_float'] as $table) {
                self::assertStringContainsString("`$table`", RecordingDb::$log[0], 'the class is not known before the read, so every table is in it');
            }
        }
    }
}
