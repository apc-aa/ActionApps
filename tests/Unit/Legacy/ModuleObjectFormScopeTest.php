<?php

declare(strict_types=1);

/**
 * A module's settings form may only ever write that module's own settings object.
 *
 * The object form grabber reads $_POST['aa'] on its own and writes whichever object the key
 * names, and AA_Object::save() begins by deleting that object's rows - so a request naming
 * somebody else's object (ids are derived from the module id, hence guessable) would wipe it.
 * Managed_Object::save() therefore refuses any object but the form's own and logs OBJ_DENY.
 */

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

    use AA_Module;
    use AA_Moduleconfig;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** A module type whose settings are the namespaced class the other test already defines */
    final class ScopedSettingsModule extends AA_Module
    {
        protected const SETTING_CLASS = NamespacedSettings::class;

        public static function getModuleconfig(): AA_Moduleconfig
        {
            return new AA_Moduleconfig('TestScope', 'Test', self::class, 'modules/testscope/', 'modules/testscope/menu.php', [], false);
        }

        public static function objectId(string $module_id): string
        {
            return static::getModuleObjectId($module_id);
        }
    }

    final class ModuleObjectFormScopeTest extends TestCase
    {
        private const MODULE_ID = 'abcdef0123456789abcdef0123456789';
        /** the settings object of some other module - the one an attacker would name */
        private const VICTIM_ID = '00112233445566778899aabbccddeeff';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/ModulesettingsNamespacedTest.php';   // NamespacedSettings
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb(), new RecordingDb(), new RecordingDb()];
            // the settings object already exists, so processModuleObject() goes straight to the form
            RecordingDb::$answer = static function (string $sql): array {
                if (!str_contains($sql, '`object_text`')) {
                    return [];
                }
                preg_match("/object_id = '([^']*)'/", $sql, $m);
                $id = $m[1] ?? '';
                return [
                    ['object_id' => $id, 'tbl' => 'object_text', 'property' => 'aa_owner', 'value' => self::MODULE_ID, 'priority' => 0],
                    ['object_id' => $id, 'tbl' => 'object_text', 'property' => 'aa_type', 'value' => NamespacedSettings::class, 'priority' => 0],
                ];
            };
        }

        protected function tearDown(): void
        {
            unset($_POST['aa']);
        }

        public function test_aPostNamingAnotherObject_neverTouchesIt(): void
        {
            $_POST['aa'] = ['u' . self::VICTIM_ID => ['market' => ['cz']]];

            ScopedSettingsModule::processModuleObject(self::MODULE_ID);

            $writes = array_filter(RecordingDb::$log, static fn(string $sql) => !str_starts_with($sql, 'SELECT') && !str_starts_with($sql, 'INSERT INTO `log`'));
            foreach ($writes as $sql) {   // reading its owner and logging the refusal is fine
                self::assertStringNotContainsString(self::VICTIM_ID, $sql, 'the request named a foreign object and it was written anyway');
            }
            self::assertNotEmpty(preg_grep('/OBJ_DENY.*' . self::VICTIM_ID . '/', RecordingDb::$log), 'the refusal is logged');
        }

        public function test_theRequestIsLeftAsItWasFound(): void
        {
            $posted      = ['u' . self::VICTIM_ID => ['market' => ['cz']]];
            $_POST['aa'] = $posted;

            ScopedSettingsModule::processModuleObject(self::MODULE_ID);

            self::assertSame($posted, $_POST['aa'], 'the rest of the page still reads $_POST itself');
        }

        public function test_aRequestWithoutTheFormAtAllStaysWithoutIt(): void
        {
            ScopedSettingsModule::processModuleObject(self::MODULE_ID);

            self::assertArrayNotHasKey('aa', $_POST);
        }

        public function test_theModulesOwnKeyIsTheOneThatIsProcessed(): void
        {
            $own         = ScopedSettingsModule::objectId(self::MODULE_ID);
            $_POST['aa'] = ['u' . $own => ['market' => ['cz']], 'u' . self::VICTIM_ID => ['market' => ['sk']]];

            ScopedSettingsModule::processModuleObject(self::MODULE_ID);

            $touched = implode("\n", RecordingDb::$log);
            self::assertStringContainsString($own, $touched, 'its own settings object is written');
            self::assertStringNotContainsString(self::VICTIM_ID, $touched);
        }
    }
}
