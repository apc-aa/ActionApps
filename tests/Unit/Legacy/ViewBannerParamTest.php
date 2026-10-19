<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA\Security\ViewSlices;
use AA_View;
use AA_Views;
use Closure;
use itemview;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use zids;

/** banner-<position>-<view>-[<weight field>|norandom] comes from the URL and becomes the parameters of a view */
final class ViewBannerParamTest extends TestCase
{
    private const LOCKED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OPEN   = 'cccccccccccccccccccccccccccccccc';
    private const OTHER  = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** what AA_View::getViewFormat() reads */
    private const FORMAT_FIELDS = ['group_by1', 'gb_header', 'group_title', 'group_bottom', 'before', 'after', 'remove_string', 'even', 'odd',
        'row_delimiter', 'even_odd_differ', 'slice_id', 'id', 'noitem_msg', 'field1', 'field2', 'aditional', 'aditional2', 'aditional3', 'calendar_type'];

    /** @var list<int> views this test put into AA_Views */
    private array $fake_views = [];

    /** @var list<string> slices the gate was asked about */
    private array $asked = [];

    /** @var list<string> selectors of the log rows written */
    private array $logged = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // VIEW_FLAG_DISABLE of AA_View::isValid(), ParamImplode() of the log
    }

    /** AA_Views and the gate of the request are static - what a test put there must not reach the next one */
    protected function tearDown(): void
    {
        $vids = $this->fake_views;
        Closure::bind(function () use ($vids): void {
            foreach ($vids as $vid) {
                unset($this->a[$vid]);
            }
        }, AA_Views::singleton(), AA_Views::class)();
        $this->setRequestGate(null);
        unset($GLOBALS['QueryIDsCount']);
    }

    private function setRequestGate(?ViewSlices $gate): void
    {
        Closure::bind(static function () use ($gate): void {
            self::$request = $gate;
        }, null, ViewSlices::class)();
    }

    /**
     * LOCKED and OTHER have a reading password the request does not know, OPEN has none.
     * @param bool    $asking  false: must not be asked
     * @param ?string $stop_at the last slice to be asked - stops get_output() there, before it draws the banners
     */
    private function gate(bool $asking = true, ?string $stop_at = null): ViewSlices
    {
        return new ViewSlices(
            static fn(): array => [self::LOCKED, self::OTHER],
            function (string $slice_id) use ($asking, $stop_at): bool {
                if (!$asking) {
                    throw new RuntimeException('gate asked');
                }
                $this->asked[] = $slice_id;
                if ($slice_id === $stop_at) {
                    throw new LogicException('decided');
                }
                return $slice_id === self::OPEN;
            },
            static fn(array $ids): array => throw new RuntimeException('slices looked up'),
            static fn(zids $zids): array => throw new RuntimeException('items looked up'),
            function (string $selector): void {
                $this->logged[] = $selector;
            },
        );
    }

    /**
     * A listing from the URL with these banners. A listing in order decides them before its content, whose load
     * throws and stops get_output() there - the rows need the whole item machinery; a random pick loads the
     * content (no items) first and decides after it.
     * @param array<int|string, string> $banners
     * @param int|string                $from    first item shown, or random:
     */
    private function listing(array $banners, int $rows, int $items = 1, int|string $from = 0): itemview
    {
        $GLOBALS['QueryIDsCount'] = $items;
        $slice_info = ['group_by' => '', 'selected_item' => '', 'banners' => $banners, 'banners_from_request' => true];
        $content    = static fn(zids $zids, mixed $param): array => is_string($from) ? [] : throw new LogicException('content loaded');
        return (new itemview($slice_info, [], new zids($items ? array_map('strval', range(1, $items)) : [], 's'), $from, $rows))
            ->setContentFunct([$content, null]);
    }

    private function outputUntilContent(itemview $listing, string $view_type, string $stop = 'content loaded'): void
    {
        try {
            $listing->get_output($view_type);
            $this->fail("get_output() did not stop: $stop");
        } catch (LogicException $e) {
            $this->assertSame($stop, $e->getMessage());
        }
    }

    /** a view in AA_Views' cache, as AA_Views::getView() returns it - no database */
    private function fakeView(int $vid, string $type, string $slice_id, string $deleted = '0'): void
    {
        $this->fake_views[] = $vid;
        $view = new AA_View($vid, ['id' => (string) $vid, 'type' => $type, 'slice_id' => pack_id($slice_id), 'deleted' => $deleted, 'flag' => '0']);
        Closure::bind(function () use ($vid, $view): void {
            $this->a[$vid] = $view;
        }, AA_Views::singleton(), AA_Views::class)();
    }

    /** @return array<string, array{string, int, array<string, mixed>}> banner, position, the banner view's parameters */
    public static function validBanners(): array
    {
        return [
            'weight 1'      => ['2-15', 2, ['vid' => '15', 'set' => ['15' => 'random-1']]],
            'empty field'   => ['2-15-', 2, ['vid' => '15', 'set' => ['15' => 'random-1']]],
            'digits'        => ['0-15-3', 0, ['vid' => '15', 'set' => ['15' => 'random-3']]],
            'weight field'  => ['1-15-hit_7_days......', 1, ['vid' => '15', 'set' => ['15' => 'random-hit_7_days......']]],
            'norandom'      => ['1-15-norandom', 1, ['vid' => '15']],
            'named view'    => ['1-news_box', 1, ['vid' => 'news_box', 'set' => ['news_box' => 'random-1']]],
        ];
    }

    /** the same parameters as the string the banner was written into before */
    #[DataProvider('validBanners')]
    public function test_parseBannerParam_validBanner_givesTheViewParameters(string $banner, int $position, array $expected): void
    {
        $banners = AA_View::parseBannerParam($banner, true)['banners'];
        parse_str($banners[$position], $params);

        $this->assertSame($expected, $params);
    }

    /** @return array<string, array{string}> */
    public static function injectedBanners(): array
    {
        return [
            'more in the view'  => ['0-15&set[15]=slices-0123456789abcdef0123456789abcdef-norandom'],
            'more in the field' => ['0-15-1&cmd[15][]=x-15-12'],
            'bracket in view'   => ['0-15]-1'],
            'short field'       => ['0-15-headline'],
            'no position'       => ['x-15-1'],
        ];
    }

    #[DataProvider('injectedBanners')]
    public function test_parseBannerParam_anythingElse_isNoBanner(string $banner): void
    {
        $this->assertSame([], AA_View::parseBannerParam($banner, true));
    }

    public function test_parseBannerParam_listOfBanners_keepsEachPosition(): void
    {
        $this->assertSame([2, 5], array_keys(AA_View::parseBannerParam(['2-15', '5-16-norandom'], true)['banners']));
    }

    /** the banner views then list only what the request may read, as the view itself (AA\Security\ViewSlices) */
    public function test_parseBannerParam_fromUrl_marksTheBanners(): void
    {
        $this->assertTrue(AA_View::parseBannerParam(['2-15', '5-16-norandom'], true)['banners_from_request']);
    }

    public function test_parseBannerParam_fromTemplate_leavesTheBannersTrusted(): void
    {
        $this->assertFalse(AA_View::parseBannerParam('2-15', false)['banners_from_request']);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function noBanners(): array
    {
        return [
            'injected from url' => ['0-15-1&cmd[15][]=x-15-12', true],
            'injected template' => ['0-15-1&cmd[15][]=x-15-12', false],
            'empty from url'    => ['', true],
            'none from url'     => [null, true],
        ];
    }

    /** no flag without a banner - merged into a view, it cannot pair with banners left there by an earlier call */
    #[DataProvider('noBanners')]
    public function test_parseBannerParam_noBanner_givesNoFlag(mixed $banner, bool $from_request): void
    {
        $this->assertSame([], AA_View::parseBannerParam($banner, $from_request));
    }

    /** AA_Views keeps one AA_View per request - the last banners set go with their own flag */
    public function test_getViewFormat_banners_carryTheFlagOfTheirCall(): void
    {
        $view = new AA_View(15, array_fill_keys(self::FORMAT_FIELDS, ''));

        $view->setBannerParam(AA_View::parseBannerParam('2-16', true));
        $this->assertTrue($view->getViewFormat()['banners_from_request']);

        $view->setBannerParam(AA_View::parseBannerParam('3-17', false));
        $format = $view->getViewFormat();
        $this->assertSame([3], array_keys($format['banners']));
        $this->assertFalse($format['banners_from_request']);
    }

    /** a view the URL called draws only the banners the URL chose - not those a template call in the request left there */
    public function test_getViewFormat_calledFromUrl_dropsTheBannersATemplateLeft(): void
    {
        $view = new AA_View(15, array_fill_keys(self::FORMAT_FIELDS, ''));
        $view->setBannerParam(AA_View::parseBannerParam('0-30', false));

        $this->assertSame([0 => 'vid=30&set%5B30%5D=random-1'], $view->getViewFormat()['banners']);
        $format = $view->getViewFormat('', true);
        $this->assertNull($format['banners']);
        $this->assertFalse($format['banners_from_request']);

        $view->setBannerParam(AA_View::parseBannerParam('0-31', true));
        $format = $view->getViewFormat('', true);
        $this->assertSame([0 => 'vid=31&set%5B31%5D=random-1'], $format['banners']);
        $this->assertTrue($format['banners_from_request']);
    }

    /**
     * Each banner view AA_Showview would narrow is asked about by its id, type and own slice, and pinned to the id of
     * the view found - a missing or invalid one is left out, as a named view found nothing with no module yet.
     */
    public function test_decideBanners_keepsTheBannersToDrawPinnedToTheirView(): void
    {
        $this->fakeView(9015, 'list', self::LOCKED);
        $this->fakeView(9016, 'static', self::LOCKED);        // lists no items
        $this->fakeView(9017, 'list', self::LOCKED, '1');     // deleted slice - renders nothing
        $this->fakeView(9018, 'list', self::OPEN);
        $context = [AA::$site_id, AA::$module_id, AA::$slice_id];
        AA::$site_id = AA::$module_id = AA::$slice_id = null;  // slice.php: a row's view sets AA::$slice_id only later

        try {
            $banners = AA_View::parseBannerParam(['0-009015', '1-9016', '2-9017', '3-9018-norandom', '4-news_box'], true)['banners'];
            $decided = AA_View::decideBanners($banners, $this->gate());
        } finally {
            [AA::$site_id, AA::$module_id, AA::$slice_id] = $context;
        }

        $this->assertSame([
            0 => 'vid=9015&set%5B9015%5D=random-1',   // as written in the URL: 009015
            1 => 'vid=9016&set%5B9016%5D=random-1',
            3 => 'vid=9018',
        ], $decided);
        $this->assertSame([self::LOCKED, self::OPEN], $this->asked);
        $this->assertSame(['9015:' . self::LOCKED], $this->logged);
    }

    /** the compact listing decides the banners it draws - one before each row - before its content, and draws the decided ones */
    public function test_getOutput_decidesTheUrlBannersBeforeTheListing(): void
    {
        $this->fakeView(9015, 'list', self::LOCKED);
        $this->fakeView(9018, 'list', self::OPEN);
        $this->fakeView(9019, 'list', self::OTHER);
        $this->setRequestGate($this->gate());
        $listing = $this->listing(AA_View::parseBannerParam(['0-009015-norandom', '1-9018-norandom', '2-9019'], true)['banners'], 2);

        $this->outputUntilContent($listing, '');

        $this->assertSame([self::LOCKED, self::OPEN], $this->asked, 'position 2 is past the 2 rows - never drawn, never asked');
        $this->assertSame([0 => 'vid=9015', 1 => 'vid=9018'], $listing->slice_info['banners']);
    }

    /** @return array<string, array{int, int|string, list<int>}> rows, first item, positions drawn */
    public static function drawnPositions(): array
    {
        return [
            // the loop draws a banner before each row and one past the last item, then stops
            'listlen over 2 items' => [99, 0, [0, 1, 2]],
            'from the 2nd item'    => [99, 1, [0, 1]],
            'past the items'       => [99, 5, [0]],
            'fewer rows'           => [2, 0, [0, 1]],
        ];
    }

    /** only the positions the compact listing draws are asked about - never one it skips, as 03 or one past its items */
    #[DataProvider('drawnPositions')]
    public function test_getOutput_decidesOnlyTheDrawnPositions(int $rows, int|string $from, array $drawn): void
    {
        foreach ([0, 1, 2, 3, 4] as $pos) {
            $this->fakeView(9100 + $pos, 'list', str_repeat((string) ($pos + 1), 32));   // a slice of its own for each
        }
        $this->fakeView(9110, 'list', self::LOCKED);
        $this->fakeView(9113, 'list', self::OTHER);
        $this->setRequestGate($this->gate());
        $banners = AA_View::parseBannerParam(['0-9100', '1-9101', '2-9102', '3-9103', '4-9104', '00-9110', '03-9113'], true)['banners'];
        $listing = $this->listing($banners, $rows, 2, $from);

        $this->outputUntilContent($listing, '');

        $this->assertSame($drawn, array_keys($listing->slice_info['banners']));
        $this->assertSame(array_map(static fn(int $pos): string => str_repeat((string) ($pos + 1), 32), $drawn), $this->asked);
    }

    /** @return array<string, array{int, int, list<int>}> rows, items to pick from, positions drawn */
    public static function randomPicks(): array
    {
        return [
            'repeated item' => [3, 1, [0, 1, 2]],   // the pick repeats the one item - 3 rows
            'listlen -1'    => [-1, 0, [0]],        // nothing picked - the banner before the first row only
        ];
    }

    /** a random listing decides once the pick knows its rows - its items before it bound nothing */
    #[DataProvider('randomPicks')]
    public function test_getOutput_randomListing_decidesAfterThePick(int $rows, int $items, array $drawn): void
    {
        foreach ([0, 1, 2, 3, 4] as $pos) {
            $this->fakeView(9100 + $pos, 'list', str_repeat((string) ($pos + 1), 32));
        }
        $this->fakeView(9110, 'list', self::LOCKED);
        $slice_of = static fn(int $pos): string => str_repeat((string) ($pos + 1), 32);
        $this->setRequestGate($this->gate(true, $slice_of(end($drawn))));
        // the skipped positions come first - asked at all, they would be asked before the stop
        $banners = AA_View::parseBannerParam(['4-9104', '3-9103', '00-9110', '0-9100', '1-9101', '2-9102'], true)['banners'];

        $this->outputUntilContent($this->listing($banners, $rows, $items, 'random:'), '', 'decided');

        $this->assertSame(array_map($slice_of, $drawn), $this->asked);
    }

    /** a weighted pick with no weight fails before the banners are decided - none is asked about */
    public function test_getOutput_weightedPickWithoutWeight_asksNothing(): void
    {
        $this->fakeView(9100, 'list', self::LOCKED);
        $this->setRequestGate($this->gate(false));
        $listing = $this->listing(AA_View::parseBannerParam('0-9100', true)['banners'], 99, 1, 'random:weight..........');
        // the weighted pick sums into an undefined $weightsum and hands mt_rand() a null - legacy notices of itemview only
        set_error_handler(static fn(int $no, string $msg, string $file): bool => str_ends_with($file, '/include/itemview.php'), E_WARNING | E_DEPRECATED);

        try {
            $listing->get_output();
            $this->fail('the pick did not fail');
        } catch (\ValueError) {
            $this->assertSame([], $this->asked);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{string}> */
    public static function outputsWithoutBanners(): array
    {
        return ['fulltext' => ['fulltext'], 'itemlist' => ['itemlist'], 'calendar' => ['calendar']];
    }

    #[DataProvider('outputsWithoutBanners')]
    public function test_getOutput_outputWithoutBanners_asksNothing(string $view_type): void
    {
        $this->fakeView(9015, 'list', self::LOCKED);
        $this->setRequestGate($this->gate(false));
        $banners = AA_View::parseBannerParam('0-9015', true)['banners'];
        $listing = $this->listing($banners, 2);

        $this->outputUntilContent($listing, $view_type);

        $this->assertSame($banners, $listing->slice_info['banners']);
    }
}
