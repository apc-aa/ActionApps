<?php

declare(strict_types=1);

/**
 * Regression test for AA_Module::getModuleName() - the TypeError and the escaping.
 *
 * AA_Module::$_modules caches one instance per module id - of whichever class asked first.
 * getModule() used to be typed ?static, so asking through a different class than the cached
 * one (AA_Slice::getModuleName() for a site module - admin/aafinder.php finding an object
 * owned by a site module) ended in
 * "Return value must be of type ?AA_Slice, AA_Module_Site returned".
 *
 * The name is stored in the same `module` column for every module type, so getModuleName()
 * (and getModuleProperty()) must work whatever class they are called on.
 *
 * Escaping contract (2026-09-09): getModuleName() returns the name RAW - a value stays clean
 * until the context that outputs it is known. getModuleNameSafe() is the HTML-context
 * counterpart. module.name is admin-editable, so every HTML caller must use the Safe variant;
 * both directions are pinned below.
 *
 * No DB: the fake below overrides the getters, and Txt::safe() is autoloaded (unlike safe()
 * from include/util.php, which needs the whole locsess boot).
 */

namespace {
    // AA_Module_Site pulls include/constants.php through the autoloader; these are the constants
    // include/config.php normally provides (same block as ItemsPreloadTest). Without them this
    // file cannot be run on its own - only as part of a suite where another test defines them.
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Module;
    use AA_Module_Site;
    use AA_Moduleconfig;
    use AA_Slice;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** Site module fake - lives in the shared module cache, no DB behind the getters. */
    final class NamedSiteModule extends AA_Module_Site
    {
        /** Puts the fake into the module cache - getModule() returns it from now on. */
        public static function register(string $module_id, string $name): void
        {
            AA_Module::$_modules[$module_id] = new self($module_id, $name);
        }

        public static function forget(string $module_id): void
        {
            unset(AA_Module::$_modules[$module_id]);
        }

        public function __construct(string $module_id, private string $name)
        {
            parent::__construct($module_id);
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getProperty($prop)
        {
            return $prop === 'name' ? $this->name : null;
        }
    }

    /** Another module type in the cache - the direction the site call sites guard against.
     *  Extends AA_Module, not AA_Slice: the AA_Slice constructor calls AA_Fields::factoryFor(),
     *  which pulls in the whole legacy boot (field.class.php … formutil.php, PERM_LIB).
     */
    final class CachedOtherModule extends AA_Module
    {
        public static function register(string $module_id): void
        {
            AA_Module::$_modules[$module_id] = new self($module_id);
        }

        public static function forget(string $module_id): void
        {
            unset(AA_Module::$_modules[$module_id]);
        }

        public static function getModuleconfig(): AA_Moduleconfig
        {
            return new AA_Moduleconfig('X', 'Fake', self::class, 'fake/', 'fake/menu.php', [], false);
        }
    }

    final class ModuleNameClassMismatchTest extends TestCase
    {
        private const SITE_ID  = '2438f1a5adf91ca7f7160d5071249b80';
        private const OTHER_ID = '9a7f16005071249b802438f1a5adf91c';

        protected function tearDown(): void
        {
            NamedSiteModule::forget(self::SITE_ID);
            CachedOtherModule::forget(self::OTHER_ID);
        }

        /** @return array<string, array{class-string<AA_Module>}> */
        public static function moduleClasses(): array
        {
            return [
                'AA_Module'      => [AA_Module::class],
                'AA_Slice'       => [AA_Slice::class],
                'AA_Module_Site' => [AA_Module_Site::class],
            ];
        }

        #[DataProvider('moduleClasses')]
        public function testNameOfSiteModuleIsReturnedWhicheverClassAsks(string $class): void
        {
            NamedSiteModule::register(self::SITE_ID, 'historickademografie');
            self::assertSame('historickademografie', $class::getModuleName(self::SITE_ID));
        }

        #[DataProvider('moduleClasses')]
        public function testPropertyOfSiteModuleIsReturnedWhicheverClassAsks(string $class): void
        {
            NamedSiteModule::register(self::SITE_ID, 'historickademografie');
            self::assertSame('historickademografie', $class::getModuleProperty(self::SITE_ID, 'name'));
        }

        public function testNameIsReturnedRaw(): void
        {
            NamedSiteModule::register(self::SITE_ID, '<img src=x onerror="alert(1)"> R&D');
            self::assertSame('<img src=x onerror="alert(1)"> R&D', AA_Slice::getModuleName(self::SITE_ID));
        }

        public function testSafeVariantEscapesForHtml(): void
        {
            NamedSiteModule::register(self::SITE_ID, '<img src=x onerror="alert(1)"> R&D');
            self::assertSame('&lt;img src=x onerror=&quot;alert(1)&quot;&gt; R&amp;D', AA_Slice::getModuleNameSafe(self::SITE_ID));
        }

        /** getOwnModule() is the null check getModule() looks like it does - the guard for request ids. */
        public function testOwnModuleIsNullWhenTheClassDoesNotMatch(): void
        {
            NamedSiteModule::register(self::SITE_ID, 'historickademografie');
            CachedOtherModule::register(self::OTHER_ID);
            // what modules/site/index.php, site.php and Adminlink::siteLink() guard against
            self::assertNull(AA_Module_Site::getOwnModule(self::OTHER_ID));
            self::assertInstanceOf(AA_Module_Site::class, AA_Module_Site::getOwnModule(self::SITE_ID));
            self::assertNull(AA_Slice::getOwnModule(self::SITE_ID));
            self::assertInstanceOf(AA_Module_Site::class, AA_Module::getOwnModule(self::SITE_ID));  // every module is an AA_Module
        }

        public function testMalformedIdGivesEmptyName(): void
        {
            // 'not-an-id' fails is_long_id(), so getModule() returns before any module lookup.
            // A well-formed but unknown id goes on to getModuleInfo() - that path needs a DB.
            self::assertSame('', AA_Slice::getModuleName('not-an-id'));
            self::assertSame('', AA_Slice::getModuleNameSafe('not-an-id'));
        }
    }
}
