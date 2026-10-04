<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA\Managed_Object;
    use AA\Manager;
    use AA\Manageraction\Import_Delete;
    use AA\Manageraction\Manageractions;
    use AA\Manageraction\Object_Move2slice;
    use AA_Form;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\FormTarget;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use Tests\Unit\Legacy\Fixtures\SearchlessManager;
    use zids;

    /**
     * The edit form of a Manager page shows what is stored at the id of the link (?id=), so it opens
     * only for an object of the page's class its module owns - a stale or forged link would show the
     * name and properties of an object of another module. Whatever the form reports goes out escaped.
     */
    final class ManagerEditFormTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER  = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const OWN    = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const VICTIM = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';
        private const TASK   = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';

        private const REFUSED = 'it is not an object of this module';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
        }

        protected function setUp(): void
        {
            session_id('test-session');
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            FakeStore::install([
                self::OWN    => [self::MODULE, FormTarget::class],
                self::VICTIM => [self::OTHER, FormTarget::class],
                self::TASK   => [self::MODULE, 'AA_Plannedtask'],
            ]);
        }

        protected function tearDown(): void
        {
            session_id('');
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_GET, $_POST, $_REQUEST] = [[], [], []];
            unset($GLOBALS['r_state']);
        }

        public function test_the_form_of_an_own_object_opens(): void
        {
            $html = self::page(['id' => self::OWN]);

            $this->assertStringNotContainsString(self::REFUSED, $html);
            $this->assertStringContainsString('[code]', $html, 'the form is rendered');
        }

        public function test_the_form_of_a_new_object_opens(): void
        {
            $html = self::page(['add' => '1']);

            $this->assertStringNotContainsString(self::REFUSED, $html);
            $this->assertStringContainsString('[code]', $html);
        }

        public function test_the_form_of_another_modules_object_is_refused(): void
        {
            $html = self::page(['id' => self::VICTIM]);

            $this->assertStringContainsString(self::REFUSED, $html);
            $this->assertStringNotContainsString('[code]', $html, 'no form');
        }

        public function test_the_form_of_an_own_object_of_another_class_is_refused(): void
        {
            $html = self::page(['id' => self::TASK]);

            $this->assertStringContainsString(self::REFUSED, $html);
            $this->assertStringNotContainsString('[code]', $html);
        }

        public function test_a_markup_id_is_refused_and_not_echoed(): void
        {
            $html = self::page(['id' => '<script>alert(1)</script>']);

            $this->assertStringContainsString(self::REFUSED, $html);
            $this->assertStringNotContainsString('<script>', $html);
        }

        /** a failing form reports its exception - whose message may carry the id of the request */
        public function test_the_error_of_the_form_is_escaped(): void
        {
            $managed = new class (FormTarget::class) extends Managed_Object {
                public function getForm(?string $id, ?string $module_id): ?AA_Form
                {
                    return new class extends AA_Form {
                        public function getObjectEditHtml(bool $std_table = false): string
                        {
                            throw new \RuntimeException('object not loaded: <img src=x onerror=alert(1)>');
                        }
                    };
                }
            };

            $html = self::page(['id' => self::OWN], $managed);

            $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
            $this->assertStringNotContainsString('<img', $html);
        }

        /** the settings of a chosen action come by AJAX - an action without any has nothing to show */
        public function test_action_settings_only_for_an_action_with_settings(): void
        {
            $actions = new Manageractions();
            $actions->addAction(new Import_Delete('DeleteObject'));
            $actions->addAction(new Object_Move2slice('Move2slice', self::MODULE));
            $manager = self::manager(null, $actions);

            $this->assertSame('', $manager->actionSettingsHtml('DeleteObject'), 'no fatal error for an action without settings');
            $this->assertNotSame('', $manager->actionSettingsHtml('Move2slice'));
        }

        /** the Manager page of MODULE, opened with $get; what it shows */
        private static function page(array $get, ?Managed_Object $managed = null): string
        {
            $_GET     = $get + ['id' => '', 'add' => ''];
            $_REQUEST = $_GET;
            return self::manager($managed)->getDisplayPage(new zids([], 'l'));
        }

        private static function manager(?Managed_Object $managed = null, ?Manageractions $actions = null): Manager
        {
            $GLOBALS['r_state'] = ['manager' => ['test' => null]];
            $managed ??= new Managed_Object(FormTarget::class);
            $managed->setAction('edit')->setAction('add')->setSettings(['module_id' => self::MODULE, 'actions' => $actions, 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]]);
            return new SearchlessManager('test', $managed);
        }
    }
}
