<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA\IO\Grabber\Form;
    use AA\Optimize\CheckFormRobotRefusals;
    use AA\Optimize\CheckRunner;
    use AA\Util\LogOnce;
    use AA_Log;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * {formantispam:<slice_id>} and hand-made forms post slice_id empty unless their JS saw the visitor move
     * the mouse or press a key. filler.php refuses such new items of the aa[...] form as robots.
     */
    final class FormRobotTest extends TestCase
    {
        private const SLICE   = 'a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8';
        private const UNKNOWN = 'f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8';
        private const ITEM    = 'b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            $_POST  = [];
            $_FILES = [];
            unset($_SERVER['HTTP_REFERER']);
        }

        /** @return array<string, array{array<string, mixed>, bool}> */
        public static function posts(): array
        {
            $new  = ['n1_' . self::SLICE => ['headline________' => ['x']]];
            $edit = ['u' . self::ITEM => ['headline________' => ['x']]];
            return [
                'new item, slice_id empty'           => [['slice_id' => '', 'aa' => $new], true],
                'new item, slice_id blank'           => [['slice_id' => ' ', 'aa' => $new], true],
                'new item, slice_id filled by JS'    => [['slice_id' => self::SLICE, 'aa' => $new], false],
                'new item, form without slice_id'    => [['aa' => $new], false],
                'new item, slice_id not a string'    => [['slice_id' => [''], 'aa' => $new], false],
                // the item history posts an empty slice_id when the item is gone
                'edit of an item, slice_id empty'    => [['slice_id' => '', 'aa' => $edit], false],
                'new and edited item, slice_id empty' => [['slice_id' => '', 'aa' => $new + $edit], true],
            ];
        }

        /** @param array<string, mixed> $post */
        #[DataProvider('posts')]
        public function test_postedByRobot(array $post, bool $robot): void
        {
            $_POST = $post;

            $this->assertSame($robot, Form::postedByRobot());
        }

        public function test_postedByRobot_logsTheSliceAndPage(): void
        {
            $_POST = ['slice_id' => '', 'aa' => ['n1_' . self::SLICE => ['headline________' => ['x']]]];
            $_SERVER['HTTP_REFERER'] = 'https://ulpan.cz/cz/kurzy/alef-p?x=1';

            Form::postedByRobot();

            $rows = self::logRows();
            $this->assertCount(1, $rows);
            $this->assertSame(self::SLICE, $rows[0]['selector']);
            $this->assertSame('ulpan.cz/cz/kurzy/alef-p', $rows[0]['params'], 'the page without its query');
        }

        /** an upload is keyed by name, full_path, type, ... before the item id - those are no new items */
        public function test_postedByRobot_uploadToEditedItem_notRobot(): void
        {
            $_POST  = ['slice_id' => '', 'aa' => ['u' . self::ITEM => ['headline________' => ['x']]]];
            $_FILES = ['aa' => self::upload('u' . self::ITEM)];

            $this->assertFalse(Form::postedByRobot());
        }

        /** drop-file uploads send no slice_id nor form token - they are not checked */
        public function test_postedByRobot_uploadOnly_notRobot(): void
        {
            $_POST  = ['slice_id' => ''];
            $_FILES = ['aa' => self::upload('f_' . self::SLICE)];

            $this->assertFalse(Form::postedByRobot());
        }

        /** AA finds the slice in any letter case - each spelling would be a new log row a day */
        public function test_postedByRobot_upperCaseSlice_logsItOnce(): void
        {
            $upper = strtoupper(self::SLICE);
            $mixed = substr(self::SLICE, 0, 16) . substr($upper, 16);
            $_POST = ['slice_id' => '', 'aa' => ['n1_' . $upper => ['headline________' => ['x']], 'n2_' . $mixed => ['headline________' => ['x']]]];

            Form::postedByRobot();

            $this->assertSame([self::SLICE], array_column(self::logRows(), 'selector'));
        }

        public function test_the_event_fits_the_log_column(): void
        {
            $this->assertLessThanOrEqual(AA_Log::TYPE_MAX, strlen(CheckFormRobotRefusals::EVENT));
        }

        public function test_it_is_a_basic_security_check_which_only_tests(): void
        {
            $this->assertSame(['test'], CheckFormRobotRefusals::actions());
            $this->assertContains(CheckFormRobotRefusals::class, CheckRunner::classes(CheckFormRobotRefusals::types()), 'found by State Check');
        }

        /** the slice comes from the request - a made-up one must not fill the log */
        public function test_postedByRobot_unknownSlice_noLog(): void
        {
            $_POST = ['slice_id' => '', 'aa' => ['n1_' . self::UNKNOWN => ['headline________' => ['x']], 'n1_nonsense' => ['headline________' => ['x']]]];

            $this->assertTrue(Form::postedByRobot());
            $this->assertSame([], self::logRows());
        }

        /** @return array<string, array<string, array<string, list<string|int>>>> $_FILES['aa'] of one uploaded file */
        private static function upload(string $item): array
        {
            $one = static fn($value) => [$item => ['img_upload______' => [$value]]];
            return ['name' => $one('a.jpg'), 'full_path' => $one('a.jpg'), 'type' => $one('image/jpeg'), 'tmp_name' => $one('/tmp/x'), 'error' => $one(0), 'size' => $one(1)];
        }

        /** @return list<array<string, mixed>> */
        private static function logRows(): array
        {
            return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckFormRobotRefusals::EVENT));
        }
    }
}
