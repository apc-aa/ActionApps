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

    use AA\IO\DB\DB_AA;
    use AA\Isds\Registry\Config;
    use AA\Isds\Registry\RegisterRecord;
    use AA\Optimize\CheckObjectFormRefusals;
    use AA\Security\Settings;
    use AA\Util\LogOnce;
    use AA_Modulesettings_Site;
    use AA_Modulesettings_Slice;
    use AA_Object;
    use AA_Property;
    use PHPUnit\Framework\Attributes\DataProvider;
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

    /** an object whose id is derived from its owner, as the module settings are */
    final class SelfOwnedObject extends AA_Object
    {
        public static function idOf(string $owner): string
        {
            return md5('self-owned:' . $owner);
        }

        protected function ownsItsId(): bool
        {
            return $this->getId() === self::idOf((string) $this->aa_owner);
        }
    }

    /**
     * What AA_Object reports as stored under an id, and the guard of save() and update() built on it:
     * the id may come from a form, so a write must not replace an object of another module or class.
     */
    final class ObjectSaveGuardTest extends TestCase
    {
        private const OWNER = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const ID    = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const OTHER_ID = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';

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
            LogOnce::reset();
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
                // far from the numbers the INSERTs of the test get
                TableDb::$tables['object_text'][] = ['id' => 100000 + count(TableDb::$tables['object_text'] ?? []), 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
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

        /** @return string[] the DELETEs of object rows */
        private static function deletes(): array
        {
            return array_values(array_filter(TableDb::$log, static fn(string $s): bool => str_starts_with($s, 'DELETE')));
        }

        private static function guarded(string $id, string $owner, string $title = 'new'): GuardedObject
        {
            $object = new GuardedObject();
            $object->setNew($id, $owner);
            $object->title = $title;
            return $object;
        }

        /** $save is refused, and nothing stored is touched */
        private function assertRefused(callable $save, string $exception = \UnexpectedValueException::class): void
        {
            $before = TableDb::rows('object_text');
            try {
                $save();
                self::fail('Expected ' . $exception);
            } catch (\Throwable $e) {
                self::assertInstanceOf($exception, $e);
            }
            self::assertSame([], self::deletes(), 'nothing deleted');
            self::assertSame($before, TableDb::rows('object_text'), 'the stored object unchanged');
        }

        /** @return array<string, string> property => value stored under $id */
        private static function stored(string $id): array
        {
            $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id);
            return array_map('strval', array_column($rows, 'value', 'property'));
        }

        public function test_save_newObject_isStored(): void
        {
            self::assertSame(self::ID, self::guarded(self::ID, self::OWNER)->save());

            self::assertSame(['title' => 'new', 'aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER], array_intersect_key(self::stored(self::ID), ['title' => 1, 'aa_type' => 1, 'aa_owner' => 1]));
        }

        public function test_save_ownObject_isReplaced(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER, 'title' => 'old']);

            self::guarded(self::ID, self::OWNER)->save();

            self::assertSame('new', self::stored(self::ID)['title']);
        }

        public function test_save_overAnObjectOfAnotherOwner_isRefused(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OTHER, 'title' => 'theirs']);

            $this->assertRefused(static fn() => self::guarded(self::ID, self::OWNER)->save());
        }

        public function test_save_overAnObjectOfAnotherClass_isRefused(): void
        {
            self::store(self::ID, ['aa_type' => OtherGuardedObject::class, 'aa_owner' => self::OWNER, 'title' => 'other']);

            $this->assertRefused(static fn() => self::guarded(self::ID, self::OWNER)->save());
        }

        /** a save cut short leaves the properties before aa_type and aa_owner - the next save completes it */
        public function test_save_overRowsWithoutOwner_isAllowed(): void
        {
            self::store(self::ID, ['title' => 'half']);
            self::store(self::OTHER_ID, ['title' => 'half', 'aa_type' => GuardedObject::class]);

            self::guarded(self::ID, self::OWNER)->save();
            self::guarded(self::OTHER_ID, self::OWNER)->save();

            self::assertSame(self::OWNER, self::stored(self::ID)['aa_owner']);
            self::assertSame(self::OWNER, self::stored(self::OTHER_ID)['aa_owner']);
        }

        public function test_save_storedClassInAnotherLetterCase_isAllowed(): void
        {
            self::store(self::ID, ['aa_type' => strtolower(GuardedObject::class), 'aa_owner' => strtoupper(self::OWNER)]);

            self::guarded(self::ID, self::OWNER)->save();

            self::assertSame(GuardedObject::class, self::stored(self::ID)['aa_type']);
        }

        public function test_save_storedUnderAnAliasOfTheClass_isAllowed(): void
        {
            self::store(self::ID, ['aa_type' => 'Tests_Unit_Legacy_GuardedAlias', 'aa_owner' => self::OWNER]);

            self::guarded(self::ID, self::OWNER)->save();

            self::assertSame(GuardedObject::class, self::stored(self::ID)['aa_type']);
        }

        public function test_save_atTheIdDerivedFromItsOwner_replacesWhatIsThere(): void
        {
            $own = SelfOwnedObject::idOf(self::OWNER);
            self::store($own, ['aa_type' => OtherGuardedObject::class, 'aa_owner' => self::OTHER, 'title' => 'planted']);
            self::store(self::ID, ['aa_type' => OtherGuardedObject::class, 'aa_owner' => self::OTHER, 'title' => 'theirs']);

            $object = new SelfOwnedObject();
            $object->setNew($own, self::OWNER);
            $object->save();
            self::assertSame(['aa_type' => SelfOwnedObject::class, 'aa_owner' => self::OWNER], AA_Object::storedIdentity($own));

            TableDb::$log = [];
            $elsewhere = new SelfOwnedObject();
            $elsewhere->setNew(self::ID, self::OWNER);
            $this->assertRefused(static fn() => $elsewhere->save());
        }

        public function test_save_refusal_isLogged(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OTHER]);

            $this->assertRefused(static fn() => self::guarded(self::ID, self::OWNER)->save());

            $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === CheckObjectFormRefusals::EVENT));
            self::assertCount(1, $logged);
            self::assertSame([self::OWNER, self::ID], array_slice(ParamExplode((string) $logged[0]['selector']), 0, 2));
            self::assertSame([GuardedObject::class, GuardedObject::class, self::OTHER], ParamExplode((string) $logged[0]['params']));
        }

        /** a lock on an id not stored yet locks the gap of the index - two new objects saved at once would deadlock */
        public function test_save_inATransaction_locksNothing(): void
        {
            DB_AA::transaction(static function (): void {
                self::guarded(self::ID, self::OWNER)->save();
                self::guarded(self::ID, self::OWNER)->update(['title']);
            });

            self::assertSame([], array_filter(TableDb::$log, static fn(string $s): bool => str_contains($s, 'FOR UPDATE')));
        }

        public function test_update_ofAnObjectOfAnotherClass_isRefused(): void
        {
            self::store(self::ID, ['aa_type' => OtherGuardedObject::class, 'aa_owner' => self::OWNER, 'title' => 'other']);

            $this->assertRefused(static fn() => self::guarded(self::ID, self::OWNER)->update(['title']));
        }

        public function test_update_withNoProperties_isRefused(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER, 'title' => 'mine']);

            $this->assertRefused(static fn() => self::guarded(self::ID, self::OWNER)->update([]), \InvalidArgumentException::class);
        }

        /** a task moved to another slice keeps its id - only the class is checked */
        public function test_update_ofTheOwner_isAllowed(): void
        {
            self::store(self::ID, ['aa_type' => GuardedObject::class, 'aa_owner' => self::OTHER, 'title' => 'moved']);

            self::guarded(self::ID, self::OWNER)->update(['aa_owner']);

            self::assertSame(['aa_type' => GuardedObject::class, 'aa_owner' => self::OWNER], AA_Object::storedIdentity(self::ID));
            self::assertSame('moved', self::stored(self::ID)['title']);
        }

        /** the module settings live at an id derived from their class and module - whatever lies there is replaced */
        public static function moduleSettings(): array
        {
            return ['slice' => [AA_Modulesettings_Slice::class], 'site' => [AA_Modulesettings_Site::class]];
        }

        /** @param class-string<\AA_AbstractModulesettings> $class */
        #[DataProvider('moduleSettings')]
        public function test_moduleSettings_ownTheirId(string $class): void
        {
            $id = string2id($class . self::OWNER);
            self::store($id, ['aa_type' => OtherGuardedObject::class, 'aa_owner' => self::OTHER]);

            $settings = new $class();
            $settings->setNew($id, self::OWNER);
            $settings->save();

            self::assertSame(['aa_type' => $class, 'aa_owner' => self::OWNER], AA_Object::storedIdentity($id));
        }

        /** the classes whose id is derived from their class and owner (or the install) - each knows its id */
        public static function derivedIds(): array
        {
            $private = static fn(string $class): string => (string) (new \ReflectionMethod($class, 'id'))->invoke(null);
            return [
                'slice settings' => [static fn(): AA_Object => (new AA_Modulesettings_Slice())->setId(string2id(AA_Modulesettings_Slice::class . self::OWNER))->setOwnerId(self::OWNER)],
                'site settings'  => [static fn(): AA_Object => (new AA_Modulesettings_Site())->setId(string2id(AA_Modulesettings_Site::class . self::OWNER))->setOwnerId(self::OWNER)],
                'register config' => [static fn(): AA_Object => (new Config())->setId(Config::idFor(self::OWNER))->setOwnerId(self::OWNER)],
                'security'       => [static fn(): AA_Object => (new Settings())->setId($private(Settings::class))->setOwnerId(AA_ID)],
                'register'       => [static fn(): AA_Object => (new RegisterRecord())->setId($private(RegisterRecord::class))->setOwnerId(AA_ID)],
            ];
        }

        #[DataProvider('derivedIds')]
        public function test_ownsItsId_onlyAtTheDerivedId(\Closure $make): void
        {
            $owns = static fn(AA_Object $o): bool => (bool) (new \ReflectionMethod($o, 'ownsItsId'))->invoke($o);

            self::assertTrue($owns($make()));
            self::assertFalse($owns($make()->setId(self::ID)));
        }
    }
}
