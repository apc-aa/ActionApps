<?php

declare(strict_types=1);

/**
 * A module's settings class may live in src/ under a namespace - it does not have to be an
 * AA_* class in include/slice.class.php. AA\Util\Note and AA\Widget\Widget already extend
 * AA_Object that way; what was never pinned is the module route to it: the object id derived
 * from the class name, the load, and the Managed router that a save goes through. A regression
 * here would be silent - the settings form would simply come back empty.
 */

namespace {
    // Legacy classes pull include/constants.php, which needs these config.php constants.
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

    use AA\Managed;
    use AA\Managed_Object;
    use AA_AbstractModulesettings;
    use AA_Module;
    use AA_Moduleconfig;
    use AA_Object;
    use AA_Property;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** Settings of a module, stored as an AA_Object - but namespaced, the way a module in src/ keeps them */
    final class NamespacedSettings extends AA_AbstractModulesettings
    {
        protected $market;

        public static function isProperty($prop): bool
        {
            return $prop === 'market';
        }

        public static function getClassProperties(): array
        {
            return ['market' => new AA_Property('market', 'Market', 'string', false, true, 'string', false)];
        }
    }

    /** A module type whose SETTING_CLASS is that namespaced class */
    final class ModuleWithNamespacedSettings extends AA_Module
    {
        protected const SETTING_CLASS = NamespacedSettings::class;

        public static function getModuleconfig(): AA_Moduleconfig
        {
            return new AA_Moduleconfig('TestNs', 'Test', self::class, 'modules/testns/', 'modules/testns/menu.php', [], false);
        }

        /** the two protected seams under test, reached the way AA_Module::getProperty() reaches them */
        public static function objectId(string $module_id): string
        {
            return static::getModuleObjectId($module_id);
        }

        public function settingProperty(string $prop)
        {
            return $this->_isModuleObjectProperty($prop) ? $this->_getModuleObjectProperty($prop) : null;
        }
    }

    final class ModulesettingsNamespacedTest extends TestCase
    {
        private const MODULE_ID = '1234567890abcdef1234567890abcdef';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        /** answers every object read with the rows of one stored settings object */
        private static function storedSettings(string $market): void
        {
            $rows = [
                ['tbl' => 'object_text', 'property' => 'aa_owner', 'value' => self::MODULE_ID, 'priority' => 0],
                ['tbl' => 'object_text', 'property' => 'aa_type', 'value' => NamespacedSettings::class, 'priority' => 0],
                ['tbl' => 'object_text', 'property' => 'market', 'value' => $market, 'priority' => 0],
            ];
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, '`object_text`') ? $rows : [];
        }

        public function test_objectId_isDerivedFromTheNamespacedClassName(): void
        {
            $id = ModuleWithNamespacedSettings::objectId(self::MODULE_ID);

            self::assertSame(string2id(NamespacedSettings::class . self::MODULE_ID), $id);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, 'the backslashes must not survive into the id');
        }

        public function test_load_returnsTheNamespacedClassStoredInAaType(): void
        {
            self::storedSettings('cz');

            $obj = AA_Object::load(ModuleWithNamespacedSettings::objectId(self::MODULE_ID));

            self::assertInstanceOf(NamespacedSettings::class, $obj, 'class_exists() resolves it through the composer autoloader');
            self::assertSame('cz', $obj->getProperty('market'));
        }

        public function test_module_readsItsSettingFromTheNamespacedObject(): void
        {
            self::storedSettings('cz');

            $module = new ModuleWithNamespacedSettings(self::MODULE_ID);

            self::assertSame('cz', $module->settingProperty('market'));
            self::assertNull($module->settingProperty('nothing'), 'a property the settings class does not own');
        }

        /** Managed::factoryFromType() routes by the first two characters - 'AA' must not read as a table */
        public function test_managedRouter_takesANamespacedClassAsAnObject(): void
        {
            $managed = Managed::factoryFromType(NamespacedSettings::class);

            self::assertInstanceOf(Managed_Object::class, $managed);
            self::assertSame(NamespacedSettings::class, $managed->getClass());
        }
    }
}
