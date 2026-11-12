<?php

declare(strict_types=1);

// AA_Object lives in include/statestore.php, whose require chain reaches include/locsess.php
// and needs the constants include/config.php normally provides.

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
        'AA_ID'           => '0123456789abcdef0123456789abcdef',
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
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    class GuardedObject extends AA_Object
    {
        public $title;

        public static function getClassProperties(): array
        {
            return ['title' => new AA_Property('title', 'Title', 'string', false)];
        }
    }

    final class OtherGuardedObject extends AA_Object
    {
        public $title;

        public static function getClassProperties(): array
        {
            return ['title' => new AA_Property('title', 'Title', 'string', false)];
        }
    }

    /**
     * What AA_Object reports as stored under an id - the store's guards decide by it.
     */
    final class ObjectSaveGuardTest extends TestCase
    {
        private const OWNER = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const ID    = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../Central/Package/fixtures/TableDb.php';
            if (!class_exists('Tests_Unit_Legacy_GuardedAlias', false)) {
                class_alias(GuardedObject::class, 'Tests_Unit_Legacy_GuardedAlias');
            }
        }

        protected function setUp(): void
        {
            TableDb::reset();
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
        }

        /** rows of an object as a save (or a half-done one) leaves them: property => value */
        private static function store(string $id, array $rows): void
        {
            foreach ($rows as $property => $value) {
                TableDb::$tables['object_text'][] = ['id' => count(TableDb::$tables['object_text'] ?? []) + 1, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
            }
        }

        public function test_storedIdentity_nothingStored_isEmpty(): void
        {
            self::assertSame(['aa_type' => '', 'aa_owner' => ''], AA_Object::storedIdentity(self::ID));
        }

        public function test_storedIdentity_readsTypeAndOwner(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER, 'title' => 'x']);

            self::assertSame(['aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER], AA_Object::storedIdentity(self::ID));
        }

        public function test_storedIdentity_lock_readsForUpdate(): void
        {
            AA_Object::storedIdentity(self::ID, true);

            self::assertStringEndsWith(' FOR UPDATE', (string) end(TableDb::$log));
        }

        public function test_isClass_letterCase(): void
        {
            self::assertTrue(AA_Object::isClass(strtoupper(GuardedObject::class), GuardedObject::class));
        }

        public function test_isClass_alias(): void
        {
            self::assertTrue(AA_Object::isClass('Tests_Unit_Legacy_GuardedAlias', GuardedObject::class));
            self::assertTrue(AA_Object::isClass(GuardedObject::class, 'tests_unit_legacy_guardedalias'));
        }

        public function test_isClass_otherClass_isFalse(): void
        {
            self::assertFalse(AA_Object::isClass(OtherGuardedObject::class, GuardedObject::class));
            self::assertFalse(AA_Object::isClass(GuardedObject::class, AA_Object::class), 'a parent is another class');
        }

        public function test_isClass_nameOfNoClass_isFalse(): void
        {
            self::assertFalse(AA_Object::isClass('No_Such_Class_' . md5(__FUNCTION__), GuardedObject::class));
            self::assertFalse(AA_Object::isClass('', GuardedObject::class));
        }
    }
}
