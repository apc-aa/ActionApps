<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA\Cache\PageCache;
use AA_Showview;
use AA_View;
use AA_Views;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * What a view decides before it lists anything: a disabled view shows nothing, the first use of a view
 * marked as probably unused is logged and takes the mark off, a view not to be cached keeps the page out of
 * the page cache.
 */
final class ViewGatesTest extends TestCase
{
    private const SLICE = 'cafe0000000000000000000000000001';
    private const VID   = 57;

    /** @var array{mixed, mixed, mixed} AA::$site_id, $module_id and $slice_id before the test */
    private array $saved = [null, null, null];

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $this->saved = [AA::$site_id, AA::$module_id, AA::$slice_id];
        [AA::$site_id, AA::$module_id, AA::$slice_id] = [null, null, null];
        $_SERVER['REQUEST_URI'] = '/cz/kontakty';
        (new ReflectionProperty(PageCache::class, 'cache_nostore'))->setValue(AA::Pagecache(), false);
    }

    protected function tearDown(): void
    {
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, []);
        [AA::$site_id, AA::$module_id, AA::$slice_id] = $this->saved;
        $GLOBALS['spareDBs'] = [];
        unset($_SERVER['REQUEST_URI']);
        (new ReflectionProperty(PageCache::class, 'cache_nostore'))->setValue(AA::Pagecache(), false);
        RecordingDb::reset();
        AA::Contentcache()->clear();
    }

    /** a static view "shown" with these flags and settings */
    private static function view(int $flag, array $settings = []): AA_View
    {
        $view  = new AA_View(self::VID, $settings + ['id' => (string) self::VID, 'slice_id' => pack_id(self::SLICE), 'deleted' => '0', 'type' => 'static', 'odd' => 'shown', 'flag' => (string) $flag, 'scroller' => '0', 'lang_file' => 'en-utf8_news_lang.php']);
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, [self::VID => $view]);
        return $view;
    }

    private static function shown(array $view_param = []): string
    {
        // the keys AA_View::parseViewParameters() always gives
        return (new AA_Showview($view_param + ['vid' => (string) self::VID, 'als' => [], 'conds' => null, 'slices' => null, 'param_conds' => [], 'cat' => null, 'zids' => null, 'selected' => null, 'slice_id' => null, 'convertfrom' => null, 'convertto' => null]))->getViewOutput();
    }

    /** @return list<string> the statements that change the view table */
    private static function viewUpdates(): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'UPDATE view')));
    }

    private static function pageIsKeptOutOfTheCache(): bool
    {
        return (new ReflectionProperty(PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache());
    }

    public function test_viewOutput_plainView_writesNothing(): void
    {
        self::view(0);

        self::assertSame('shown', self::shown());
        self::assertFalse(self::pageIsKeptOutOfTheCache());
        self::assertSame([], self::viewUpdates());
        self::assertSame([], RecordingDb::inserts('log'));
    }

    public function test_viewOutput_firstUseOfAWatchedView_isShownLoggedAndUnmarked(): void
    {
        self::view(VIEW_FLAG_WATCH | VIEW_FLAG_COMMENTS);

        self::assertStringContainsString('shown', self::shown());
        self::assertCount(1, self::viewUpdates());
        self::assertStringContainsString('flag = flag & ~8', self::viewUpdates()[0]);
        $logged = RecordingDb::inserts('log');
        self::assertCount(1, $logged);
        self::assertSame('VIEW', $logged[0]['type']);
        self::assertSame((string) self::VID, (string) $logged[0]['selector']);
        self::assertStringContainsString('/cz/kontakty', $logged[0]['params']);

        // the mark is off for the rest of the request too
        self::assertStringContainsString('shown', self::shown());
        self::assertCount(1, self::viewUpdates());
        self::assertCount(1, RecordingDb::inserts('log'));
    }

    public function test_viewOutput_watchedViewAnotherRequestUnmarked_isNotLoggedTwice(): void
    {
        self::view(VIEW_FLAG_WATCH);
        RecordingDb::$affected = static fn(string $sql): int => 0;

        self::assertSame('shown', self::shown());
        self::assertCount(1, self::viewUpdates());
        self::assertSame([], RecordingDb::inserts('log'));
    }

    public function test_viewOutput_disabledWatchedView_keepsTheMark(): void
    {
        self::view(VIEW_FLAG_WATCH | VIEW_FLAG_DISABLE);

        self::assertSame('', self::shown());
        self::assertSame([], self::viewUpdates());
        self::assertSame([], RecordingDb::inserts('log'));
    }

    public function test_viewOutput_viewNotToBeCached_keepsThePageOutOfTheCache(): void
    {
        self::view(VIEW_FLAG_NOCACHE);

        self::assertSame('shown', self::shown());
        self::assertTrue(self::pageIsKeptOutOfTheCache());
    }

    public function test_viewOutput_disabledViewNotToBeCached_leavesThePageToTheCache(): void
    {
        self::view(VIEW_FLAG_NOCACHE | VIEW_FLAG_DISABLE);

        self::assertSame('', self::shown());
        self::assertFalse(self::pageIsKeptOutOfTheCache());
    }

    public function test_viewOutput_logRowOfALongAddress_fitsTheColumn(): void
    {
        self::view(VIEW_FLAG_WATCH);
        $_SERVER['REQUEST_URI'] = '/cz/hledani?q=' . str_repeat('příliš:', 60);

        self::assertSame('shown', self::shown());
        $logged = RecordingDb::inserts('log');
        self::assertCount(1, $logged);
        self::assertLessThanOrEqual(128, strlen($logged[0]['params']));
        self::assertStringContainsString('/cz/hledani', $logged[0]['params']);
    }
}
