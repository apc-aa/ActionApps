<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\MatchRow;
use AA\Isds\Registry\Page;
use AA\Isds\Registry\SdsList;
use AA\Isds\Registry\Sync;
use AA\Util\FormToken;
use AA_Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Isds\Registry\Fixtures\RecordingPage;
use Tests\Unit\Isds\Registry\Fixtures\RecordingSync;
use Tests\Unit\Legacy\Fixtures\FakeFields;

/**
 * The page that pairs a slice's items with the register: every action a POST with the token of the
 * slice's page and the right permission; a write only of the ticked rows that still show what the
 * person saw, against the register and the settings the person saw. What failed reading, downloading
 * or writing goes to the log, never to the page. The items come from RecordingSync, the boxes from the
 * 31 real ones of the SdsList fixture.
 */
final class PageTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/seznam_ds_ovm-sample.xml';

    private const SLICE = '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b';
    private const OTHER = '7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c';

    private const NAME = 'headline........';
    private const ICO  = 'text...........1';
    private const DS   = 'text...........2';
    private const TYPE = 'category........';

    private const CITY = 'obec';

    private const POST = ['REQUEST_METHOD' => 'POST'];

    /** @var list<Box> */
    private static array $boxes = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';
        require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/fixtures/RecordingSync.php';
        require_once __DIR__ . '/fixtures/RecordingPage.php';
        require_once __DIR__ . '/fixtures/PropertySlice.php';
        self::$boxes = iterator_to_array(SdsList::fromXml(self::FIXTURE), false);
    }

    protected function setUp(): void
    {
        session_id('isds-page-test');
        $_SESSION = [];
        new PropertySlice(self::SLICE, self::fields(), []);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        session_id('');
        PropertySlice::forget(self::SLICE);
    }

    // ------------------------------------------------------------ the gate of every action

    public function test_handle_getOrAnotherActionDoesNothing(): void
    {
        $page = self::page(self::items());

        self::assertNull($page->handle(['action' => 'write', 'token' => self::token()], ['REQUEST_METHOD' => 'GET']));
        self::assertNull($page->handle(['action' => 'delete', 'token' => self::token()], self::POST));
        self::assertNull($page->handle(['token' => self::token()], self::POST));
        self::assertNull($page->handle(['action' => ['write'], 'token' => self::token()], self::POST));
    }

    /** @return array<string, array{array<string, mixed>, array<string, string>}> */
    public static function forgedPosts(): array
    {
        return [
            'no token'            => [[], self::POST],
            'a wrong token'       => [['token' => 'x'], self::POST],
            'another slice\'s'    => [['token' => '@other'], self::POST],
            'a token as an array' => [['token' => ['@token']], self::POST],
            'from another site'   => [['token' => '@token'], self::POST + ['HTTP_SEC_FETCH_SITE' => 'cross-site']],
            'another origin'      => [['token' => '@token'], self::POST + ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'aa.example']],
        ];
    }

    /**
     * @param array<string, mixed>  $post
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_handle_forgedFormRefused(array $post, array $server): void
    {
        $post = array_map(static fn(mixed $v): mixed => match ($v) {
            '@token' => self::token(),
            ['@token'] => [self::token()],
            '@other' => FormToken::get('isds-registry:' . self::OTHER),
            default  => $v,
        }, $post);
        $page = self::page(self::items());

        foreach (['write', 'download', 'settings'] as $action) {
            self::assertSame(['code' => 'expired'], $page->handle(['action' => $action, 'stamp' => self::stamp($page), 'tick' => [self::id(1) => 'a7kbrrn|'], 'aa' => self::settingsPost(self::configValues())] + $post, $server), $action);
        }
        self::assertSame([[], 0, []], [$page->sync?->stored, $page->downloads, $page->saved]);
    }

    public function test_handle_refusedWithoutPermission(): void
    {
        $editor = self::page(self::items(), mayConfigure: false);
        $nobody = self::page(self::items(), mayWrite: false, mayConfigure: false);

        self::assertSame(['code' => 'refused'], $nobody->handle(self::write($nobody, [self::id(1) => 'a7kbrrn|']), self::POST));
        self::assertSame(['code' => 'refused'], $nobody->handle(self::post('download'), self::POST));
        self::assertSame(['code' => 'refused'], $editor->handle(self::post('settings') + ['aa' => self::settingsPost(self::configValues())], self::POST));
        self::assertSame([[], 0, []], [$nobody->sync?->stored, $nobody->downloads, $editor->saved]);
    }

    // ------------------------------------------------------------ write

    public function test_write_onlyTheTickedRows(): void
    {
        $page = self::page(self::items());

        // Brno new by IČO, Praha new by name and ticked by hand; Liberec (changed) left unticked
        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|', self::id(4) => '48ia97h|']), self::POST);

        self::assertSame(['code' => 'written', 'written' => 2, 'stale' => 0, 'left' => 0, 'failed' => []], $outcome);
        $stored = array_map(static fn(array $s): array => [$s['content']['unpacked_id.....'][0]['value'], $s['content'][self::DS][0]['value']], $page->sync->stored ?? []);
        self::assertSame([[self::id(1), 'a7kbrrn'], [self::id(4), '48ia97h']], $stored);
        foreach ($page->sync->stored ?? [] as $s) {
            self::assertEqualsCanonicalizing(['id..............', 'slice_id........', self::DS, 'unpacked_id.....'], array_keys($s['content']), 'only the data box field');
        }
    }

    // the person was shown another box; a same row is no tick to write
    public function test_write_staleRowsReported(): void
    {
        $page = self::page(self::items());

        $outcome = $page->handle(self::write($page, [self::id(1) => 'x2pbqzq|', self::id(2) => '7c6by6u|x2pbqzq', self::id(3) => 'eerak7a|eerak7a']), self::POST);

        self::assertSame(['code' => 'written', 'written' => 1, 'stale' => 1, 'left' => 0, 'failed' => []], $outcome);
        self::assertStringContainsString('Ticked rows that changed meanwhile and were not written: 1.', Page::outcomeHtml($outcome));
    }

    // another editor wrote a box into the item after the person looked at it
    public function test_write_storedBoxChangedSinceTheViewIsStale(): void
    {
        $items    = self::items();
        $items[1] = self::item('Brno', '44992785', 'abcdefg');
        $page     = self::page($items);

        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|']), self::POST);

        self::assertSame(['code' => 'written', 'written' => 0, 'stale' => 1, 'left' => 0, 'failed' => []], $outcome);
        self::assertSame([], $page->sync->stored);
        self::assertStringNotContainsString('Nothing was written: no row was ticked', Page::outcomeHtml($outcome));
    }

    public function test_write_refusedWhenTheViewChanged(): void
    {
        $page = self::page(self::items());
        $post = self::write($page, [self::id(1) => 'a7kbrrn|']);

        $page->register = ['mtime' => 1760009999, 'size' => 17000001];
        self::assertSame(['code' => 'view_changed'], $page->handle($post, self::POST), 'downloaded again');
        self::assertSame(['code' => 'view_changed'], $page->handle(['stamp' => [self::stamp($page)]] + $post, self::POST));
        $settings = Config::fromValues(self::SLICE, ['types' => [self::CITY]] + self::configValues());
        self::assertSame(['code' => 'view_changed'], $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|'], $settings), self::POST), 'settings saved since');
        $page->register = null;
        self::assertSame(['code' => 'view_changed'], $page->handle(['stamp' => ''] + $post, self::POST), 'no register at all');
        self::assertSame([], $page->sync?->stored);
        self::assertStringContainsString('nothing was written', Page::outcomeHtml(['code' => 'view_changed']));
    }

    public function test_write_unreadableRegisterLoggedNotShown(): void
    {
        $page = self::page(self::items());
        $post = self::write($page, [self::id(1) => 'a7kbrrn|']);

        $page->boxes = new \RuntimeException('/srv/aa/img_upload/x.xml: not well-formed');
        $outcome     = $page->handle($post, self::POST);
        $page->register = new \RuntimeException('PHP cannot look into the directory /srv/aa/img_upload');
        $looked      = $page->handle($post, self::POST);

        self::assertSame([['code' => 'register_unreadable'], ['code' => 'register_unreadable']], [$outcome, $looked]);
        // look: the file, as on the page; read: its content
        self::assertSame([['read', '/srv/aa/img_upload/x.xml: not well-formed'], ['look', 'PHP cannot look into the directory /srv/aa/img_upload']], $page->logged);
        self::assertSame([], $page->sync->stored);
        $html = Page::outcomeHtml($outcome ?? []);
        self::assertStringContainsString('The copy of the register cannot be read, so nothing was written. An administrator finds the details in the AA log (type ISDS_REG).', $html);
        self::assertStringNotContainsString('Download it again', $html, 'the directory may be the cause');
        self::assertStringNotContainsString('/srv', $html);
    }

    // the rest stays ticked in the report for the next write
    public function test_write_atMostMaxWriteTheRestLeft(): void
    {
        $page = self::manyRows(Sync::MAX_WRITE + 3);
        $all  = [];
        foreach ($page->sync->content ?? [] as $id => $content) {
            $all[$id] = sprintf('b%06d|', (int) substr($id, 1));
        }

        $outcome = $page->handle(self::write($page, $all), self::POST);

        self::assertSame(['code' => 'written', 'written' => Sync::MAX_WRITE, 'stale' => 0, 'left' => 3, 'failed' => []], $outcome);
        self::assertCount(Sync::MAX_WRITE, $page->sync->stored);
        self::assertStringContainsString('Ticked rows left for the next write: 3. One write takes at most 500 rows.', Page::outcomeHtml($outcome));
    }

    public function test_write_needsTheSettings(): void
    {
        $page = new RecordingPage(self::SLICE, null, true, true);

        self::assertSame(['code' => 'no_settings'], $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|']), self::POST));
    }

    public function test_write_refusedWhenTheSettingsNoLongerFit(): void
    {
        $page = self::page(self::items());
        $page->sync->fields = self::fields(without: [self::DS]);   // the box field deleted since

        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|']), self::POST);

        self::assertSame('misfit', $outcome['code'] ?? null);
        self::assertSame([], $page->sync->stored);
    }

    public function test_write_failuresByNameAndEscaped(): void
    {
        $items = self::items();
        $items[1][self::NAME] = [['value' => 'Brno <script>x</script>', 'flag' => 0]];
        $page = self::page($items);
        $page->sync->refuse = [self::id(1) => 'Bad <b>item</b>'];

        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|', self::id(2) => '7c6by6u|x2pbqzq']), self::POST);

        self::assertSame(['code' => 'written', 'written' => 1, 'stale' => 0, 'left' => 0, 'failed' => ['Brno <script>x</script>: Bad <b>item</b>']], $outcome);
        $html = Page::outcomeHtml($outcome);
        self::assertStringContainsString('Brno &lt;script&gt;x&lt;/script&gt;: Bad &lt;b&gt;item&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    // storeItem() threw: the rows before it are written, the person learns that the count is lost;
    // the message may quote the database, so only the log has it
    public function test_write_stoppedByAnException(): void
    {
        $page   = self::page([]);
        $config = Config::fromValues(self::SLICE, self::configValues());
        $page->sync = new class ($config) extends RecordingSync {
            protected function store(\ItemContent $content, string $mode, array $flags): bool
            {
                throw new \RuntimeException('Lock wait <b>timeout</b>');
            }
        };
        $page->sync->fields  = self::fields();
        $page->sync->content = [self::id(1) => self::item('Brno', '44992785')];

        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|']), self::POST);

        self::assertSame(['code' => 'write_stopped'], $outcome);
        self::assertSame([['write', 'Lock wait <b>timeout</b>']], $page->logged);
        $html = Page::outcomeHtml($outcome);
        self::assertStringContainsString('Writing stopped with an error. The rows written before it stay written', $html);
        self::assertStringNotContainsString('Lock wait', $html);
    }

    // ------------------------------------------------------------ the outcome after the redirect

    public function test_afterPost_shownOnceOnTheFilterItCameFrom(): void
    {
        $page = self::page(self::items());
        $outcome = $page->handle(self::write($page, [self::id(1) => 'a7kbrrn|']), self::POST);

        $url = $page->afterPost($outcome ?? [], MatchRow::NEW);

        self::assertStringContainsString('se_isds_registry.php', $url);
        self::assertStringContainsString('change_id=' . self::SLICE, $url);
        self::assertStringContainsString('status=new', $url);
        self::assertStringContainsString('Data boxes written: 1.', self::page(self::items())->html());
        self::assertStringNotContainsString('Data boxes written', self::page(self::items())->html(), 'once');

        self::assertStringNotContainsString('status=', $page->afterPost(['code' => 'downloaded'], '"><script>'), 'not a status of the report');
    }

    // ------------------------------------------------------------ the report

    /** @return array<string, array{string, bool}> */
    public static function statusFilters(): array
    {
        return ['all' => ['', true], 'new' => ['new', true], 'ambiguous' => ['ambiguous', true], 'unknown' => ['news', false], 'capitals' => ['NEW', false], 'markup' => ['<b>', false]];
    }

    #[DataProvider('statusFilters')]
    public function test_statusOf_onlyAStatusOfTheReport(string $status, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($status, Page::statusOf($status));
    }

    public function test_html_statusFilterShowsThatStatusOnly(): void
    {
        $page = self::page(self::items());

        $html = $page->html(MatchRow::NEW);

        self::assertSame(['Brno', 'Praha', 'Plzeň'], array_keys(self::checkboxes($html)));
        self::assertMatchesRegularExpression('/<a href="[^"]*status=new"[^>]*aria-current="page">New <span class="aa-isds-n">3</', $html);
        self::assertStringContainsString('>All <span class="aa-isds-n">8</span>', $html);
        self::assertSame(['Liberec', 'Brno', 'Praha', 'Cerhenice', 'Plzeň', 'Adamov', 'Nikde', '<script>alert(1)</script>'], array_keys(self::checkboxes(self::page(self::items())->html())));
    }

    public function test_html_checkboxesTickedOnlyForBoxesFoundByIco(): void
    {
        $page = self::page(self::items());
        $html = $page->html();

        // name => [has a checkbox, ticked]
        self::assertSame([
            'Liberec'  => [true, true],     // changed by IČO
            'Brno'     => [true, true],     // new by IČO
            'Praha'    => [true, false],    // new by name
            'Cerhenice' => [false, false],  // same
            'Plzeň'    => [true, false],    // by name, the item's IČO is a school's
            'Adamov'   => [false, false],   // ambiguous
            'Nikde'    => [false, false],   // unmatched
            '<script>alert(1)</script>' => [false, false],
        ], self::checkboxes($html));
        self::assertStringContainsString('name="tick[' . self::id(2) . ']" value="' . self::tick('7c6by6u', 'x2pbqzq') . '" aria-label="Write the data box 7c6by6u into Liberec" checked="checked"', $html);
        self::assertStringContainsString('IČO of the item 05282446, of the box 00075370', $html);
        self::assertStringContainsString('by name, check it', $html);
        self::assertStringContainsString('<code>q7bb677</code>', $html, 'the candidates of Adamov');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('name="stamp" value="' . self::stamp($page) . '"', $html);
        self::assertStringContainsString('name="token" value="' . self::token() . '"', $html);
    }

    // two items would hold one box: neither is ticked in advance, both say so
    public function test_html_sharedBoxWarned(): void
    {
        $html = self::page([
            1 => self::item('Adamov', '00581160'),
            2 => self::item('Adamov', '581160'),
            3 => self::item('Brno', '44992785'),
        ])->html();

        self::assertSame(['Adamov' => [true, false], 'Brno' => [true, true]], self::checkboxes($html));
        self::assertSame(2, substr_count($html, 'Another item has or would get this data box too'));
    }

    // every row can be ticked by hand, only as many as one write takes are ticked in advance
    public function test_html_atMostMaxTicksTickedInAdvance(): void
    {
        $html = self::manyRows(Page::MAX_TICKS + 3)->html();

        self::assertSame(Page::MAX_TICKS + 3, substr_count($html, 'type="checkbox"'));
        self::assertSame(Page::MAX_TICKS, substr_count($html, 'checked="checked"'));
        self::assertStringContainsString('One write takes at most 500 rows, and at most as many are ticked in advance. Write the rest afterwards.', $html);
    }

    public function test_html_noRegisterNoReport(): void
    {
        $page = self::page(self::items());
        $page->register = null;

        $html = $page->html();

        self::assertStringContainsString('The register has not been downloaded yet', $html);
        self::assertStringContainsString('value="download"', $html);
        self::assertStringNotContainsString('aa-table-simple aa-isds-report', $html);
    }

    // today and yesterday by the calendar: 23 hours across midnight are yesterday
    public function test_html_registerAgeByTheCalendar(): void
    {
        $page = self::page(self::items());
        $page->register = ['mtime' => (int) mktime(10, 0, 0, 10, 16, 2026), 'size' => 17000000];

        $page->now = (int) mktime(23, 59, 0, 10, 16, 2026);
        self::assertStringContainsString('Downloaded: 2026-10-16 10:00 (today). Size: 16.2 MB.', $page->html());
        $page->now = (int) mktime(9, 0, 0, 10, 17, 2026);
        self::assertStringContainsString('Downloaded: 2026-10-16 10:00 (yesterday).', $page->html());
        $page->now = (int) mktime(9, 0, 0, 10, 23, 2026);
        $html      = $page->html();
        self::assertStringContainsString('Downloaded: 2026-10-16 10:00. Size', $html);
        self::assertStringNotContainsString('older than', $html);
        $page->now = (int) mktime(11, 0, 0, 10, 23, 2026);
        self::assertStringContainsString('The register is older than 7 days', $page->html());
    }

    // the message may name a server path
    public function test_html_unreadableRegisterLoggedNotShown(): void
    {
        $page = self::page(self::items());
        $page->register = new \RuntimeException('PHP cannot look into the directory /srv/aa/img_upload');

        $html = $page->html();

        self::assertStringContainsString('The copy of the register cannot be read. An administrator finds the details in the AA log (type ISDS_REG).', $html);
        self::assertStringNotContainsString('/srv', $html);
        self::assertStringNotContainsString('aa-table-simple aa-isds-report', $html);
        self::assertSame([['look', 'PHP cannot look into the directory /srv/aa/img_upload']], $page->logged);
        self::assertStringContainsString('value="download"', $html, 'downloading again is the way out');

        $page->register = ['mtime' => 1760000000, 'size' => 17000000];
        $page->boxes    = new \RuntimeException('/srv/aa/img_upload/x.xml: not well-formed');
        $html = $page->html();

        self::assertStringContainsString('The copy of the register cannot be read.', $html);
        self::assertStringNotContainsString('/srv', $html);
        self::assertSame(['read', '/srv/aa/img_upload/x.xml: not well-formed'], $page->logged[1] ?? null);
    }

    public function test_html_misfitSaysWhatToChoose(): void
    {
        $page = self::page(self::items());
        $page->sync->fields = self::fields(without: [self::DS]);

        $html = $page->html();

        self::assertStringContainsString('The settings of the pairing no longer fit the fields of the slice', $html);
        self::assertStringNotContainsString('aa-table-simple aa-isds-report', $html);
    }

    // ------------------------------------------------------------ download

    // the message may quote the operator's server
    public function test_download_failureKeepsThePreviousRegister(): void
    {
        $page = self::page(self::items());

        self::assertSame(['code' => 'downloaded'], $page->handle(self::post('download'), self::POST));
        $page->downloadFails = new \RuntimeException('HTTP 503 <b>busy</b>');
        $outcome = $page->handle(self::post('download'), self::POST);

        self::assertSame(['code' => 'download_failed'], $outcome);
        self::assertSame(2, $page->downloads);
        self::assertSame([['download', 'HTTP 503 <b>busy</b>']], $page->logged);
        $html = Page::outcomeHtml($outcome);
        self::assertStringContainsString('the previous one stays in use. Try again later. An administrator finds the details in the AA log (type ISDS_REG).', $html);
        self::assertStringNotContainsString('503', $html);
    }

    // Sync::download() decides, as it holds the lock
    public function test_download_refusalsSaySo(): void
    {
        $page = self::page(self::items());
        $page->downloadAnswer = ['status' => Sync::RECENT, 'last' => (int) mktime(10, 0, 0, 10, 16, 2026)];

        $recent = $page->handle(self::post('download'), self::POST);
        $page->downloadAnswer = ['status' => Sync::RUNNING, 'last' => 0];
        $running = $page->handle(self::post('download'), self::POST);

        self::assertSame([['code' => 'download_recent', 'time' => (int) mktime(10, 0, 0, 10, 16, 2026)], ['code' => 'download_running']], [$recent, $running]);
        // no errors: the page says what is possible
        self::assertSame('<div class="aa-info" role="status">The last download attempt was at 2026-10-16 10:00. Another one is possible from 10:10.</div>', Page::outcomeHtml($recent ?? [], (int) mktime(10, 1, 0, 10, 16, 2026)));
        self::assertSame('<div class="aa-info" role="status">The register is being downloaded right now. Reload the page in a minute.</div>', Page::outcomeHtml($running ?? []));
        $late = ['code' => 'download_recent', 'time' => (int) mktime(23, 55, 0, 10, 16, 2026)];
        self::assertStringContainsString('Another one is possible from 2026-10-17 00:05.', Page::outcomeHtml($late, (int) mktime(23, 56, 0, 10, 16, 2026)), 'another day says which');
    }

    // the register serves no slice before its fields are chosen
    public function test_download_needsTheSettings(): void
    {
        $page = new RecordingPage(self::SLICE, null, true, true);

        self::assertSame(['code' => 'no_settings'], $page->handle(self::post('download'), self::POST));
        self::assertSame(0, $page->downloads);
        self::assertStringNotContainsString('value="download"', $page->html());
    }

    // the page does not look at the register before the download: Sync::download() checks the directory
    public function test_download_triedWhenTheRegisterCannotBeLookedAt(): void
    {
        $page = self::page(self::items());
        $page->register = new \RuntimeException('PHP cannot look into the directory /srv/aa/img_upload');

        self::assertSame(['code' => 'downloaded'], $page->handle(self::post('download'), self::POST));
        self::assertSame(1, $page->downloads);
    }

    // ------------------------------------------------------------ settings

    public function test_settings_savedFromTheForm(): void
    {
        $page   = self::page(self::items());
        $values = ['types' => [' obec ', 'městská část'], 'district_types' => ['městská část']] + self::configValues();

        $outcome = $page->handle(self::post('settings') + ['aa' => self::settingsPost($values)], self::POST);

        self::assertSame(['code' => 'settings_saved'], $outcome);
        self::assertCount(1, $page->saved);
        self::assertSame(Config::idFor(self::SLICE), $page->saved[0]->getId());
        self::assertSame(['name_field' => self::NAME, 'ico_field' => self::ICO, 'ds_field' => self::DS, 'type_field' => self::TYPE, 'types' => ['obec', 'městská část'], 'district_types' => ['městská část']], $page->saved[0]->values());
    }

    public function test_settings_refusedShowsWhyAndKeepsTheValues(): void
    {
        $page   = self::page(self::items());
        $values = ['ds_field' => self::NAME, 'types' => ['obec <i>']] + self::configValues();

        $outcome = $page->handle(self::post('settings') + ['aa' => self::settingsPost($values)], self::POST);

        self::assertSame('settings_refused', $outcome['code'] ?? null);
        self::assertSame([], $page->saved);
        $page->afterPost($outcome ?? []);
        $html = self::page(self::items())->html();
        self::assertStringContainsString('The settings were not saved:<ul><li>The data box needs a field of its own', $html);
        self::assertStringContainsString('value="obec &lt;i&gt;"', $html, 'the typed values again, escaped');
        self::assertMatchesRegularExpression('/<details id="aa-isds-settings" class="aa-page-section" open>/', $html);
    }

    /** @return array<string, array{string, mixed}> property => what no form of the page posts for it */
    public static function forgedSettings(): array
    {
        return [
            'an array for a field'     => ['name_field', [['headline........']]],
            'an array in the list'     => ['types', ['mfl' => [['obec']]]],
            'a named key in the list'  => ['types', ['mfl' => ['x' => 'obec']]],
            'a text for the values'    => ['types', 'obec'],
            'a named key for a field'  => ['ds_field', ['x' => self::DS]],
        ];
    }

    // the widgets' code expects its own shape and throws a TypeError on an array for a text
    #[DataProvider('forgedSettings')]
    public function test_settings_onlyTheShapeTheFormPosts(string $property, mixed $value): void
    {
        $page   = self::page(self::items());
        $forged = self::settingsPost(self::configValues());
        $forged['u' . Config::idFor(self::SLICE)][$property] = $value;

        $outcome = $page->handle(self::post('settings') + ['aa' => $forged], self::POST);

        self::assertSame(['code' => 'settings_refused', 'errors' => ['The form sent values this page does not send. Reload the page and try again.']], $outcome);
        self::assertSame([], $page->saved);
    }

    // the list widget posts an HTML flag beside its values
    public function test_settings_flagOfTheListIgnored(): void
    {
        $page   = self::page(self::items());
        $posted = self::settingsPost(['types' => [self::CITY]] + self::configValues());
        $posted['u' . Config::idFor(self::SLICE)]['types']['flag'] = 'abc';

        self::assertSame(['code' => 'settings_saved'], $page->handle(self::post('settings') + ['aa' => $posted], self::POST));
        self::assertSame([self::CITY], $page->saved[0]->types());
    }

    public function test_settings_ofAnotherSliceNotTaken(): void
    {
        $page  = self::page(self::items());
        $other = $page->handle(self::post('settings') + ['aa' => ['u' . Config::idFor(self::OTHER) => self::settingsPost(self::configValues())['u' . Config::idFor(self::SLICE)]]], self::POST);

        self::assertSame(['Choose the field with the name', 'Choose the field the data box is written to'], $other['errors'] ?? null);
        self::assertSame([], $page->saved);
    }

    public function test_settings_formOffersTheSlicesFields(): void
    {
        $fields = self::fields(extra: [
            self::field('password......pw', ['input_insert_func' => 'pwd:', 'name' => 'Password']),
            self::field('status_code.....', ['in_item_tbl' => 'status_code', 'text_stored' => 0, 'name' => 'Status']),
        ]);
        PropertySlice::forget(self::SLICE);
        new PropertySlice(self::SLICE, $fields, []);

        $html = self::page(self::items())->html();

        self::assertStringContainsString('<option value="' . self::DS . '" selected class="sel_on">Box &lt;DS&gt; (' . self::DS . ')</option>', $html);
        self::assertStringNotContainsString('password......pw', $html, 'a protected field is never offered');
        self::assertSame(3, substr_count($html, '<option value="status_code.....">'), 'an item-table column for anything but the box');
        self::assertStringContainsString('name="aa[u' . Config::idFor(self::SLICE) . '][types][mfl][0]" ', $html);
    }

    // PS_FIELDS without PS_EDIT_ALL_ITEMS: the pairing set up, the items neither read nor written
    public function test_settings_withoutTheRightToEditItems(): void
    {
        $page = self::page(self::items(), mayWrite: false);
        $page->register = null;

        $html = $page->html();

        self::assertStringContainsString('value="settings"', $html);
        self::assertStringNotContainsString('value="download"', $html);
        self::assertStringContainsString('The register has not been downloaded yet.</p>', $html, 'no button to download it with');
        self::assertStringNotContainsString('aa-table-simple aa-isds-report', $html);
        self::assertStringNotContainsString('Liberec', $html);
        self::assertSame(['code' => 'settings_saved'], $page->handle(self::post('settings') + ['aa' => self::settingsPost(self::configValues())], self::POST));
    }

    public function test_settings_readOnlyWithoutTheRightToChangeFields(): void
    {
        $html = self::page(self::items(), mayConfigure: false)->html();

        self::assertStringNotContainsString('value="settings"', $html);
        self::assertStringContainsString('Data box field</th><td>Box &lt;DS&gt; <code>' . self::DS . '</code></td>', $html);
        self::assertStringContainsString('Types to pair</th><td><span class="aa-isds-none">none</span>', $html);
    }

    // ------------------------------------------------------------ helpers

    /**
     * The page over items $items, with the stored settings, the fixture boxes and a register stamp.
     * @param array<int, array<string, mixed>> $items number => content, see id()
     */
    private static function page(array $items, bool $mayWrite = true, bool $mayConfigure = true): RecordingPage
    {
        $config = self::config();
        $page   = new RecordingPage(self::SLICE, $config, $mayWrite, $mayConfigure);
        $page->sync   = new RecordingSync($config);
        $page->sync->fields = self::fields();
        foreach ($items as $n => $content) {
            $page->sync->content[self::id($n)] = $content;
        }
        $page->boxes = self::$boxes;
        return $page;
    }

    /** $count items, each new by IČO, with a box of its own */
    private static function manyRows(int $count): RecordingPage
    {
        $boxes = [];
        $items = [];
        for ($n = 1; $n <= $count; ++$n) {
            $ico       = sprintf('%08d', 10000000 + $n);
            $boxes[]   = new Box(sprintf('b%06d', $n), $ico, '', "Obec Ves $n", 'OVM', 10, true, false);
            $items[$n] = self::item("Ves $n", $ico);
        }
        $page = self::page($items);
        $page->boxes = $boxes;
        return $page;
    }

    private static function config(): Config
    {
        return Config::fromValues(self::SLICE, self::configValues());
    }

    /** the stamp of the page's register and of $seen, the settings the person saw */
    private static function stamp(RecordingPage $page, ?Config $seen = null): string
    {
        return Sync::registerStamp(is_array($page->register) ? $page->register : null, $seen ?? self::config());
    }

    /** @return array<int, array<string, mixed>> one item of each kind of row, in the slice's order */
    private static function items(): array
    {
        return [
            2 => self::item('Liberec', '00262978', 'x2pbqzq'),     // changed by IČO
            1 => self::item('Brno', '44992785'),                   // new by IČO
            4 => self::item('Praha'),                              // new by name
            3 => self::item('Cerhenice', '00235300', 'eerak7a'),   // same
            5 => self::item('Plzeň', '05282446'),                  // by name, the IČO of a school
            6 => self::item('Adamov'),                             // ambiguous
            7 => self::item('Nikde'),                              // unmatched
            8 => self::item('<script>alert(1)</script>'),          // unmatched
        ];
    }

    /** @return array<string, string|list<string>> */
    private static function configValues(): array
    {
        return ['name_field' => self::NAME, 'ico_field' => self::ICO, 'ds_field' => self::DS, 'type_field' => self::TYPE, 'types' => [], 'district_types' => []];
    }

    /**
     * $values as the settings form posts them (AA_Form)
     * @param array<string, string|list<string>> $values
     * @return array<string, array<string, array<array-key, mixed>>>
     */
    private static function settingsPost(array $values): array
    {
        $object = [];
        foreach ($values as $property => $value) {
            $object[$property] = is_array($value) ? ['mfl' => $value] : [$value];
        }
        return ['u' . Config::idFor(self::SLICE) => $object];
    }

    /** @return array<string, string> */
    private static function post(string $action): array
    {
        return ['action' => $action, 'token' => self::token()];
    }

    /**
     * The write form as posted
     * @param array<string, string> $ticked item id => "the box offered|the box stored", as the person saw
     *                                      them; posted as Sync::tickValue() makes them
     * @return array<string, mixed>
     */
    private static function write(RecordingPage $page, array $ticked, ?Config $seen = null): array
    {
        $values = array_map(static fn(string $shown): string => self::tick(...explode('|', $shown, 2)), $ticked);
        return self::post('write') + ['stamp' => self::stamp($page, $seen), 'tick' => $values];
    }

    /** a checkbox value: the box offered, a hash of the box the item had */
    private static function tick(string $box, string $stored = ''): string
    {
        return $box . '|' . substr(md5(strtolower(trim($stored))), 0, 12);
    }

    private static function token(): string
    {
        return FormToken::get('isds-registry:' . self::SLICE);
    }

    /**
     * @param list<string>   $without
     * @param list<AA_Field> $extra
     */
    private static function fields(array $without = [], array $extra = []): FakeFields
    {
        $names = [self::NAME => 'Name', self::ICO => 'IČO', self::DS => 'Box <DS>', self::TYPE => 'Type'];
        $fields = [];
        foreach (array_diff_key($names, array_flip($without)) as $id => $name) {
            $fields[] = self::field($id, ['name' => $name]);
        }
        return new FakeFields(self::SLICE, [...$fields, ...$extra]);
    }

    /** @param array<string, mixed> $data */
    private static function field(string $id, array $data = []): AA_Field
    {
        // no slice id: AA_Field::isPasswordCopy() would load the slice
        return new AA_Field($data + ['id' => $id, 'name' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0]);
    }

    /** @return array<string, list<array{value: string, flag: int}>> */
    private static function item(string $name, string $ico = '', string $ds = '', string $type = self::CITY): array
    {
        $values = [self::NAME => $name, self::ICO => $ico, self::DS => $ds, self::TYPE => $type];
        return array_map(static fn(string $v): array => [['value' => $v, 'flag' => 0]], array_filter($values, static fn(string $v): bool => $v !== ''));
    }

    private static function id(int $n): string
    {
        return sprintf('a%031d', $n);
    }

    /** @return array<string, array{bool, bool}> item name => [has a checkbox, ticked] */
    private static function checkboxes(string $html): array
    {
        preg_match_all('~<tr><td>(.*?)</td><th scope="row">(.*?)</th>~s', $html, $m, PREG_SET_ORDER);
        $ret = [];
        foreach ($m as [, $cell, $name]) {
            $ret[html_entity_decode($name, ENT_QUOTES | ENT_HTML5)] = [str_contains($cell, 'type="checkbox"'), str_contains($cell, 'checked')];
        }
        return $ret;
    }
}
