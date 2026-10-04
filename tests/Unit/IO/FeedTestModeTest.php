<?php

declare(strict_types=1);

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

namespace Tests\Unit\IO {

    use AA;
    use AA\IO\Feed;
    use ItemContent;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\IO\Fixtures\ArrayGrabber;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Feed::feed('test') - the "test the feed" button: Importer::toexecutelater() runs the
     * Saver dry, right away, without the queue. The feed is built over an ArrayGrabber; the
     * Saver it creates inside feed() cannot be swapped, so "stores nothing" is proven by the
     * only database handle (RecordingDb) receiving no statement.
     *
     * Each test in its own process: ItemContent loads include/locsess.php, whose getDB()
     * collides with the stub tests/Unit/IO/DB loads when that directory runs first.
     */
    #[RunTestsInSeparateProcesses]
    final class FeedTestModeTest extends TestCase
    {
        private const SLICE = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';
        private const ITEM  = '7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c';

        private ?string $slice_id_before = null;

        protected function setUp(): void
        {
            // not in setUpBeforeClass(): that one runs in the parent process as well; the
            // warnings are locsess.php missing the web request and config of a real install
            set_error_handler(static fn() => true, E_WARNING | E_DEPRECATED);
            try {
                require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            } finally {
                restore_error_handler();
            }
            require_once __DIR__ . '/fixtures/ArrayGrabber.php';
            new FakeSlice(self::SLICE);
            RecordingDb::reset();
            $GLOBALS['spareDBs']       = [new RecordingDb()];
            $GLOBALS['permission_uid'] = '';
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => PS_EDIT_ALL_ITEMS]];
            $this->slice_id_before     = AA::$slice_id;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            AA::$slice_id = $this->slice_id_before;
            RecordingDb::reset();
        }

        public function test_feed_testModeDoesNotStore(): void
        {
            $item = new ItemContent();
            $item->setItemID(self::ITEM);
            $item->setValue('headline........', 'Fed');
            $grabber = new ArrayGrabber([$item]);

            $this->expectOutputRegex('/<table .*Saver->run\(\): finish - 1 OK, 0 Err\./s');
            $ret = (new Feed($grabber, self::SLICE, 'test'))->feed('test');

            $this->assertStringStartsWith('items OK: 1', $ret);
            $this->assertStringNotContainsString('error items', $ret);
            $this->assertStringNotContainsString('new[', $ret);
            $this->assertStringNotContainsString('updated[', $ret);
            $this->assertSame([], RecordingDb::$log, 'the test mode writes nothing');
            $this->assertSame(1, $grabber->taken);
            $this->assertSame(1, $grabber->finished);
        }
    }
}
