<?php

declare(strict_types=1);

// AA_Modulesettings_Site lives in include/slice.class.php, whose require chain reaches include/locsess.php
// and needs the constants include/config.php normally provides (as PropertyLazyEnumTest).

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

    use AA_Form;
    use AA_Formrow;
    use AA_Modulesettings_Site;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The app store settings of a site (app_publish, app_description, app_version): stored with the other
     * site settings, but set only by a superadmin on their own part of the site form - the generic
     * settings form neither shows nor changes them, whatever it posts.
     */
    final class SiteAppSettingsTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        public function test_the_app_store_settings_are_site_settings(): void
        {
            $properties = AA_Modulesettings_Site::getClassProperties();
            foreach (['app_publish' => 'string', 'app_description' => 'text', 'app_version' => 'string', 'app_modules' => 'string'] as $name => $type) {
                $this->assertTrue(AA_Modulesettings_Site::isProperty($name), $name);
                $this->assertSame($type, $properties[$name]->getType(), $name);
            }
            $this->assertSame(['app_publish', 'app_description', 'app_version', 'app_modules'], AA_Modulesettings_Site::APP_PROPERTIES);
        }

        public function test_the_generic_form_has_no_app_store_settings(): void
        {
            $form = new AA_Form();
            $form->setObject(AA_Modulesettings_Site::class, '', str_repeat('a', 32));
            AA_Modulesettings_Site::addFormrows($form);

            $rows = (new \ReflectionProperty(AA_Form::class, 'rows'))->getValue($form);
            $ids  = array_map(static fn(AA_Formrow $row): string => (string) $row->getRowProperty()?->getId(), $rows);
            $this->assertContains('page404', $ids);
            $this->assertSame([], array_values(array_intersect($ids, AA_Modulesettings_Site::APP_PROPERTIES)));
        }

        private const SITE  = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const SLICE = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const NONE  = ['publish' => false, 'description' => '', 'version' => '', 'modules' => []];

        public function test_a_form_keeps_the_app_store_settings_as_stored(): void
        {
            $stored = new AA_Modulesettings_Site();
            $stored->setApp(true, 'A web for schools', '1.2', [self::SITE, self::SLICE]);

            $posted = new AA_Modulesettings_Site();
            $posted->setApp(true, 'posted', '9', [self::SITE, self::SLICE, str_repeat('c', 32)]);
            $posted->keepAppOf($stored);
            $this->assertSame(['publish' => true, 'description' => 'A web for schools', 'version' => '1.2', 'modules' => [self::SITE, self::SLICE]], $posted->app());

            $posted->keepAppOf(null);
            $this->assertSame(self::NONE, $posted->app(), 'nothing stored - not published');
        }

        public function test_the_approved_modules_are_long_ids_only(): void
        {
            $settings = new AA_Modulesettings_Site();
            $settings->setApp(true, '', '', [self::SITE, 'x,y', strtoupper(self::SLICE), self::SITE]);

            $this->assertSame([self::SITE, self::SLICE], $settings->app()['modules']);
        }

        /** @return array<string, array{string, string, bool}> */
        public static function appInputs(): array
        {
            return [
                'empty'                  => ['', '', true],
                'a version'              => ['A web', '1.2.0-rc.1+build', true],
                'a long description'     => [str_repeat('ž', 2000), '1', true],
                'too long a description' => [str_repeat('ž', 2001), '1', false],
                'a space in the version' => ['', '1 2', false],
                'markup in the version'  => ['', '<b>1</b>', false],
                'too long a version'     => ['', str_repeat('1', 33), false],
            ];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('appInputs')]
        public function test_the_app_description_and_version_are_checked(string $description, string $version, bool $ok): void
        {
            $this->assertSame($ok, AA_Modulesettings_Site::appInputError($description, $version) === null);
        }

        /** a form can write any object id: the settings count only when they are the site's own */
        public function test_the_settings_of_another_owner_are_not_the_sites(): void
        {
            $stored = new AA_Modulesettings_Site();
            $stored->setNew(str_repeat('d', 32), self::SLICE);
            $stored->setApp(true, 'hijacked', '6.6', [self::SITE]);

            $this->assertSame(self::NONE, AA_Modulesettings_Site::appOf($stored, self::SITE));
            $this->assertSame(self::NONE, AA_Modulesettings_Site::appOf(null, self::SITE));
            $stored->setOwnerId(self::SITE);
            $this->assertTrue(AA_Modulesettings_Site::appOf($stored, self::SITE)['publish']);
        }

        /** a superadmin's save makes the object the site's again */
        public function test_a_save_takes_the_settings_back_for_the_site(): void
        {
            $id     = str_repeat('d', 32);
            $stored = new AA_Modulesettings_Site();
            $stored->setNew($id, self::SLICE);

            $this->assertSame(self::SITE, AA_Modulesettings_Site::ofSite($stored, $id, self::SITE)->getOwnerId());
            $fresh = AA_Modulesettings_Site::ofSite(null, $id, self::SITE);
            $this->assertSame([$id, self::SITE], [$fresh->getId(), $fresh->getOwnerId()]);
            $this->assertSame(self::SITE, AA_Modulesettings_Site::ofSite(new \AA_Modulesettings_Slice(), $id, self::SITE)->getOwnerId(), 'another class - a new object');
        }
    }
}
