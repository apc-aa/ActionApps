<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA\Cache\PageCache;
use AA\Security\ViewDirect;
use AA\Util\LogOnce;
use AA_Showview;
use AA_View;
use AA_Views;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * What a view decides before it lists anything: a disabled view shows nothing, the first use of a view
 * marked as probably unused is logged and takes the mark off, a view not to be cached keeps the page out of
 * the page cache, a view that works only inside pages shows nothing to the address of view.php, a view with
 * a condition of its output shows its other code when the condition does not hold.
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
        $_SERVER['SCRIPT_NAME'] = '/aa/modules/site/site.php';
        (new ReflectionProperty(PageCache::class, 'cache_nostore'))->setValue(AA::Pagecache(), false);
    }

    protected function tearDown(): void
    {
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, []);
        [AA::$site_id, AA::$module_id, AA::$slice_id] = $this->saved;
        $GLOBALS['spareDBs'] = [];
        unset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_HOST'], $_SERVER['HTTP_REFERER'], AA::$headers['status']);
        LogOnce::reset();
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

    /** @return array<string, array{string, bool, bool}> script, the URL chose the view, shown */
    public static function callsOfAViewOnlyInsidePages(): array
    {
        return [
            'view.php'                      => ['/aa/view.php', true, false],
            'jsview.php'                    => ['/aa/jsview.php', true, false],
            'discussion.php shows any view' => ['/aa/discussion.php', true, false],
            'a script that includes view.php' => ['/rss.php', true, false],
            '{view.php?...} in view.php'    => ['/aa/view.php', false, true],
            '{view:...} on a page'          => ['/aa/modules/site/site.php', false, true],
        ];
    }

    #[DataProvider('callsOfAViewOnlyInsidePages')]
    public function test_viewOutput_viewOnlyInsidePages_isShownOnlyToTemplates(string $script, bool $from_request, bool $shown): void
    {
        self::view(VIEW_FLAG_NODIRECT | VIEW_FLAG_WATCH);
        $_SERVER['SCRIPT_NAME']  = $script;
        $_SERVER['HTTP_HOST']    = 'example.org';
        $_SERVER['REQUEST_URI']  = $script . '?vid=' . self::VID;
        $_SERVER['HTTP_REFERER'] = 'https://example.org/cz/akce?x=1';

        self::assertSame($shown ? 'shown' : '', self::shown(['from_request' => $from_request]));
        self::assertSame(!$shown, self::pageIsKeptOutOfTheCache(), 'a refusal is never cached - the view shows again once the setting is off');
        self::assertSame($shown ? null : 'HTTP/1.0 404 Not Found', AA::$headers['status'] ?? null);
        // a refused call is no use of the view: the mark stays
        self::assertCount($shown ? 1 : 0, self::viewUpdates());
        $refusals = array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row): bool => $row['type'] === ViewDirect::EVENT));
        self::assertCount($shown ? 0 : 1, $refusals);
        if (!$shown) {
            self::assertSame(ParamImplode([(string) self::VID, ViewDirect::BY_VIEW, '']), $refusals[0]['selector']);
            self::assertSame(ParamImplode(['example.org' . $script, 'example.org/cz/akce']), $refusals[0]['params']);
        }
    }

    public function test_viewOutput_discussionOnlyInsidePages_isRefusedLikeAnyView(): void
    {
        self::view(VIEW_FLAG_NODIRECT, ['type' => 'discus']);
        $_SERVER['SCRIPT_NAME'] = '/aa/discussion.php';
        $_SERVER['HTTP_HOST']   = 'example.org';
        $_SERVER['REQUEST_URI'] = '/aa/discussion.php?vid=' . self::VID;

        self::assertSame('', self::shown(['from_request' => true]));
        self::assertSame('HTTP/1.0 404 Not Found', AA::$headers['status'] ?? null);
    }

    /** @return array<string, array{array<string, string>, string}> settings of the view, output */
    public static function outputConditions(): array
    {
        return [
            'database without the columns' => [[], 'shown'],
            'no condition'                 => [['show_if' => '', 'show_else' => 'other'], 'shown'],
            'only spaces - no condition'   => [['show_if' => "  \n", 'show_else' => 'other'], 'shown'],
            'a text'                       => [['show_if' => '1', 'show_else' => 'other'], 'shown'],
            'zero is a text too'           => [['show_if' => '0', 'show_else' => 'other'], 'shown'],
            'an expression with a text'    => [['show_if' => '{ifeq:a:a:yes}', 'show_else' => 'other'], 'shown'],
            'an expression without one'    => [['show_if' => '{ifeq:a:b:yes}', 'show_else' => 'other'], 'other'],
            'spaces only'                  => [['show_if' => '{ifeq:a:a: }', 'show_else' => 'other'], 'other'],
            'the other code is a template' => [['show_if' => '{ifeq:a:b:yes}', 'show_else' => '<p>{ifeq:a:a:closed}</p>'], '<p>closed</p>'],
            'no other code'                => [['show_if' => '{ifeq:a:b:yes}', 'show_else' => ''], ''],
            'no other code in the database' => [['show_if' => '{ifeq:a:b:yes}'], ''],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('outputConditions')]
    public function test_viewOutput_conditionOfTheOutput(array $settings, string $output): void
    {
        self::view(0, $settings);

        self::assertSame($output, self::shown());
        self::assertFalse(self::pageIsKeptOutOfTheCache(), 'a page with the view is cached as with any template');
    }

    public function test_viewOutput_conditionAskedByTheAddress_isNotCached(): void
    {
        self::view(0, ['show_if' => '1']);
        $_SERVER['SCRIPT_NAME'] = '/aa/view.php';

        self::assertSame('shown', self::shown(['from_request' => true]));
        self::assertTrue(self::pageIsKeptOutOfTheCache());
    }

    public function test_viewOutput_otherCodeOfAViewWithComments_isMarkedAsTheView(): void
    {
        self::view(VIEW_FLAG_COMMENTS, ['show_if' => '{ifeq:a:b:yes}', 'show_else' => 'other']);

        self::assertStringStartsWith('<!-- ' . self::VID . ' -->other<!-- /' . self::VID, self::shown());
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
