<?php

declare(strict_types=1);

// AA_Modulesettings_Slice lives in include/slice.class.php, whose require chain reaches include/locsess.php
// and needs the constants include/config.php normally provides (as SiteAppSettingsTest).

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

    use AA\Security\ViewDirect;
    use AA_Form;
    use AA_Formrow;
    use AA_Modulesettings_Slice;
    use PHPUnit\Framework\TestCase;

    /**
     * "Views only inside pages" of a slice is stored with the other slice settings, but switched on the
     * list of views - by who may change the views. The form of the slice settings neither shows nor
     * changes it, whatever it posts.
     */
    final class SliceSettingsFormTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        public function test_the_setting_is_stored_with_the_slice_settings(): void
        {
            $this->assertTrue(AA_Modulesettings_Slice::isProperty(ViewDirect::PROPERTY));
            $this->assertArrayHasKey(ViewDirect::PROPERTY, AA_Modulesettings_Slice::getClassProperties());
        }

        public function test_the_form_of_the_slice_settings_has_no_row_for_it(): void
        {
            $form = new AA_Form();
            $form->setObject(AA_Modulesettings_Slice::class, '', str_repeat('a', 32));
            AA_Modulesettings_Slice::addFormrows($form);

            $rows = (new \ReflectionProperty(AA_Form::class, 'rows'))->getValue($form);
            $ids  = array_map(static fn(AA_Formrow $row): string => (string) $row->getRowProperty()?->getId(), $rows);
            $this->assertContains('historylog', $ids);
            $this->assertNotContains(ViewDirect::PROPERTY, $ids);
        }

        public function test_a_posted_form_keeps_it_as_stored(): void
        {
            $stored = new AA_Modulesettings_Slice();
            $stored->setProperty(ViewDirect::PROPERTY, '1');

            $posted = new AA_Modulesettings_Slice();
            $posted->keepOwnPageOf($stored);
            $this->assertSame('1', $posted->getProperty(ViewDirect::PROPERTY), 'on stays on');

            $posted->setProperty(ViewDirect::PROPERTY, '1');
            $posted->keepOwnPageOf(new AA_Modulesettings_Slice());
            $this->assertSame('', (string) $posted->getProperty(ViewDirect::PROPERTY), 'a posted value does not switch it on');

            $posted->setProperty(ViewDirect::PROPERTY, '1');
            $posted->keepOwnPageOf(null);
            $this->assertSame('', (string) $posted->getProperty(ViewDirect::PROPERTY), 'nothing stored - off');
        }
    }
}
