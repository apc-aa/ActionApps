<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\Context;
use AA\Import\Definition;
use AA\Import\DetailPage;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * The streams and the catalog in the import detail: the state of each stream, what the catalog the
 * last check listed has new, missing or renamed against the streams of that check, page by page, and
 * Add/Update of an entry - a POST with the token of the import, the entry taken from the stored
 * catalog, the right to edit all items of the slice the source adds to. Showing the page never runs
 * the source's code; everything from the source is escaped.
 * Each test in its own process, as DetailPageTest: FakeSlice loads include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class DetailCatalogTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE = 'custom:adopt';
    private const POST   = ['REQUEST_METHOD' => 'POST'];

    protected function setUp(): void
    {
        $this->stepsSetUp();
        session_id('test-session');
        $dir = __DIR__ . '/fixtures/catalog';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \AdoptSource::$calls       = [];
        \AdoptSource::$adopted     = [];
        \AdoptSource::$adoptSlice  = self::OTHER;
        \AdoptSource::$failAdopt   = null;
        \AdoptSource::$credentials = [];
        \AdoptSource::$askKey      = null;
        \AdoptSource::$keySeen     = false;
        \AdoptSource::$maxBytes    = Context::DEFAULT_MAX_BYTES;
        \AdoptSource::$capSeen     = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
        \AA::$module_id = self::SLICE;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        \AA::$module_id = null;
        unset($GLOBALS['r_superuser']);
        session_id('');
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
    }

    // ------------------------------------------------------------ streams

    public function test_streams_tableFromState(): void
    {
        self::definition();
        $this->assertStringContainsString('No stream has been imported or checked yet.', self::page()->streamsHtml());

        $run   = self::importRun();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '<i>2026</i>', 'probed' => self::NOW - 7200, 'imported' => self::NOW - 3600, 'run' => $run]);
        $state->record('s2', ['probed' => self::NOW - 60]);
        $state->store();
        TableDb::$log = [];

        $html = self::page()->streamsHtml();

        $this->assertSame([
            ['s1', '<i>2026</i>', date('Y-m-d H:i', self::NOW - 7200), date('Y-m-d H:i', self::NOW - 3600), date('Y-m-d H:i', (int) self::stored($run)['started'])],
            ['s2', '--', date('Y-m-d H:i', self::NOW - 60), '--', '--'],
        ], self::tableRows($html, 'aa-import-streams'));
        $this->assertStringNotContainsString('<i>2026', $html);
        $this->assertSame([], TableDb::writes(), 'read only');
        // html() is what the live refresh (?sections=1) sends: neither the streams nor the catalog - both may be long
        $this->assertStringNotContainsString('data-aa-live', $html);
        $live = self::page()->html(self::NOW);
        $this->assertStringNotContainsString('aa-import-streams', $live);
        $this->assertStringNotContainsString('aa-import-catalog', $live);
    }

    public function test_streams_paginated(): void
    {
        self::definition();
        $state = StreamState::create(self::DEF);
        foreach (range(0, 119) as $i) {
            $state->record(sprintf('s%03d', $i), ['v' => (string) $i]);
        }
        $state->store();
        $keys = static fn(string $html): array => array_column(self::tableRows($html, 'aa-import-streams'), 0);

        $first = self::page()->streamsHtml();
        $this->assertSame(array_map(static fn(int $i): string => sprintf('s%03d', $i), range(0, 49)), $keys($first));
        $this->assertStringContainsString('Page 1 of 3', self::text($first));
        $this->assertStringContainsString('streams_page=2#aa-import-streams', $first, 'a link to the next page, at the streams');

        $last = self::page()->streamsHtml('3');
        $this->assertSame(array_map(static fn(int $i): string => sprintf('s%03d', $i), range(100, 119)), $keys($last));
        $this->assertSame($keys($last), $keys(self::page()->streamsHtml('99')), 'past the end = the last page');
        foreach (['x', '-1', '0', '2.5', ['2'], null] as $bad) {
            $html = self::page()->streamsHtml($bad);
            $this->assertSame($keys($first), $keys($html), var_export($bad, true));
            $this->assertStringContainsString('Page 1 of 3', self::text($html), var_export($bad, true));
        }
    }

    /** only damaged streams say their state cannot be read - not a damaged result of the last check */
    public function test_streams_damagedApartFromCheck(): void
    {
        self::definition();
        StreamState::create(self::DEF)->store();
        self::storedSet(StreamState::idOf(self::DEF), 'check', '{not json');
        // the page reads the check first, as the overview comes before the streams
        $page = self::page();
        $this->assertStringContainsString('cannot be read', $page->html(self::NOW), 'the overview says the check is damaged');
        $this->assertStringContainsString('No stream has been imported or checked yet.', $page->streamsHtml(), 'only the check damaged');

        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{"s1":{"v":"1"}}');
        $this->assertSame([['s1', '1', '--', '--', '--']], self::tableRows(self::page()->streamsHtml(), 'aa-import-streams'));

        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{not json');
        $this->assertStringContainsString('The stored state of the streams cannot be read.', self::page()->streamsHtml());
    }

    /** damaged streams do not make the overview say the check cannot be read - there was none */
    public function test_overview_neverCheckedWithDamagedStreams(): void
    {
        self::definition();
        StreamState::create(self::DEF)->store();
        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{not json');
        $page = self::page();

        // the streams read first, so the record is damaged before the overview asks for the check
        $this->assertStringContainsString('cannot be read', $page->streamsHtml());
        $overview = $page->overviewHtml(self::NOW);

        $this->assertStringContainsString('Never checked', $overview);
        $this->assertStringNotContainsString('The stored result cannot be read.', $overview);
    }

    /** whatever the stored state holds, the table shows it as text */
    public function test_streams_hostileStateEscaped(): void
    {
        self::definition();
        StreamState::create(self::DEF)->store();
        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{"<b>k</b>":{"v":"<script>x</script>","probed":"<i>t</i>","run":"<x>"},"s2":"text"}');

        $html = self::page()->streamsHtml();

        $this->assertSame([['<b>k</b>', '<script>x</script>', '--', '--', '--']], self::tableRows($html, 'aa-import-streams'));
        foreach (['<b>k', '<script>', '<i>t', '<x>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
    }

    // ------------------------------------------------------------ catalog

    public function test_catalogDiff_rendered(): void
    {
        self::definition();
        $this->assertStringContainsString('No catalog yet', self::catalogHtml());
        self::catalog(
            [new CatalogEntry('e1', 'Prices', ['unit' => 'CZK'], '2026-09'), new CatalogEntry('e2', 'Wages, new name'), new CatalogEntry('e3', 'Rents')],
            [new Stream('s1', 'Prices', [], 'e1'), new Stream('s2', 'Wages', [], 'e2'), new Stream('s3', 'Old', [], 'gone'), new Stream('s4', 'Own', [])],
        );
        TableDb::$log = [];

        $html = self::catalogHtml();

        $this->assertStringContainsString('3 entries', $html);
        $this->assertStringContainsString('1 entry is new at the source', $html);
        $this->assertSame([
            ['Missing at the source', 's3', 'gone', 'Old', '--'],
            ['Renamed at the source', 's2', 'e2', 'Wages', 'Wages, new name'],
        ], self::tableRows($html, 'aa-import-diff'));
        $this->assertSame([
            ['e1', 'Prices', '2026-09', 'unit: CZK', 's1', 'Update'],
            ['e2', 'Wages, new name', '', '', 's2', 'Update'],
            ['e3', 'Rents', '', '', 'new', 'Add'],
        ], self::tableRows($html, 'aa-import-catalog'));
        $this->assertSame([], TableDb::writes(), 'read only');
    }

    public function test_catalog_incompleteShownNoMissing(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e2', 'Wages, new name')], [new Stream('s2', 'Wages', [], 'e2'), new Stream('s3', 'Old', [], 'gone')], false);

        $html = self::catalogHtml();

        $this->assertStringContainsString('The catalog is incomplete', $html);
        $this->assertSame([['Renamed at the source', 's2', 'e2', 'Wages', 'Wages, new name']], self::tableRows($html, 'aa-import-diff'), 'its entry may come later');
    }

    public function test_catalog_paginated(): void
    {
        self::definition();
        $entries = array_map(static fn(int $i): CatalogEntry => new CatalogEntry(sprintf('k%03d', $i), "Entry $i"), range(0, 119));
        self::catalog($entries, []);

        $first = self::catalogHtml();
        $this->assertSame(array_map(static fn(int $i): string => sprintf('k%03d', $i), range(0, 49)), array_column(self::tableRows($first, 'aa-import-catalog'), 0));
        $this->assertStringContainsString('Page 1 of 3', self::text($first));
        $this->assertStringContainsString('catalog_page=2#aa-import-catalog', $first, 'a link to the next page, at the catalog');

        $last = self::catalogHtml('3');
        $this->assertSame(array_map(static fn(int $i): string => sprintf('k%03d', $i), range(100, 119)), array_column(self::tableRows($last, 'aa-import-catalog'), 0));
        $this->assertStringContainsString('catalog_page=2', $last, 'a link to the page before');
        $this->assertSame(self::tableRows($last, 'aa-import-catalog'), self::tableRows(self::catalogHtml('99'), 'aa-import-catalog'), 'past the end = the last page');
        foreach (['x', '-1', '0', '2.5', '1e1', ['2'], null] as $bad) {
            $html = self::catalogHtml($bad);
            $this->assertSame(self::tableRows($first, 'aa-import-catalog'), self::tableRows($html, 'aa-import-catalog'), var_export($bad, true));
            $this->assertStringContainsString('Page 1 of 3', self::text($html), var_export($bad, true));
        }
    }

    /** keys, names, dates, details and labels come from the source: text everywhere, the key also in its form */
    public function test_catalog_sourceTextsEscaped(): void
    {
        self::definition();
        $key = '<script>alert(1)</script>';
        self::catalog(
            [new CatalogEntry($key, '<img src=x onerror=alert(2)>', ['<i>unit</i>' => '"q"&<u>'], '<b>u</b>')],
            [new Stream('s1', '<s>old</s>', [], $key), new Stream('s2', 'x', [], '<em>gone</em>')],
        );

        $html = self::catalogHtml();

        foreach (['<script', '<img', '<i>unit', '<u>', '<b>u', '<s>old', '<em>gone'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw);
        }
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertSame([$key, '<img src=x onerror=alert(2)>', '<b>u</b>', '<i>unit</i>: "q"&<u>', 's1', 'Update'], self::tableRows($html, 'aa-import-catalog')[0]);
        $this->assertSame(['Renamed at the source', 's1', $key, '<s>old</s>', '<img src=x onerror=alert(2)>'], self::tableRows($html, 'aa-import-diff')[1]);

        // the key the form posts finds the entry
        $msg = self::act(['action' => 'adopt', 'key' => self::formKeys($html)[0]]);
        $this->assertStringContainsString('aa-ok', $msg);
        $this->assertSame([$key], array_map(static fn(CatalogEntry $e): string => $e->key, \AdoptSource::$adopted));
    }

    /** a form for each entry; a source with keys only a superadmin may use, as he alone may manage its import */
    public function test_catalog_buttonsNeedTheRightToManage(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e1', 'Prices')], []);
        $this->assertCount(1, self::formKeys(self::catalogHtml()));
        $this->assertCount(1, self::tableRows(self::catalogHtml(), 'aa-import-catalog'));

        \AdoptSource::$credentials = [['vendor' => 'csu', 'context' => '']];
        $html = self::catalogHtml();
        $this->assertStringContainsString('disabled', $html, 'keys: a superadmin only');
        $this->assertStringContainsString('only a superadmin', $html);
    }

    // ------------------------------------------------------------ adopt

    public function test_adopt_needsEditAllItemsOnAdoptSlice(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        // all rights to the slice of the page, none to the one the source adds to
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_FEEDING);

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('edit all items', $msg);
        $this->assertSame([], \AdoptSource::$adopted);

        \AdoptSource::$adoptSlice = '<x>';
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        // a right to the whole AA counts for any slice id, even one that is not
        $GLOBALS['permission_to']['aa'][AA_ID] = PS_EDIT_ALL_ITEMS;
        $this->assertStringContainsString('edit all items', self::act(['action' => 'adopt', 'key' => 'e3']), 'not a slice id');
        $this->assertSame([], \AdoptSource::$adopted);

        \AdoptSource::$adoptSlice = self::OTHER;
        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('aa-ok', $msg);
        $this->assertSame(['e3'], array_map(static fn(CatalogEntry $e): string => $e->key, \AdoptSource::$adopted));
    }

    /** whoever may not import into the slice adopts nothing, and a source with keys only a superadmin */
    public function test_adopt_needsTheRightsOfTheImport(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        $page = self::page();
        self::perms(PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        $this->assertStringContainsString('aa-err', self::shown($page, $page->handle(['action' => 'adopt', 'key' => 'e3', 'token' => self::token()], self::POST)), 'the feeding right lost after the page opened');

        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        \AdoptSource::$credentials = [['vendor' => 'csu', 'context' => '']];
        $this->assertStringContainsString('only a superadmin', self::act(['action' => 'adopt', 'key' => 'e3']));
        $this->assertSame([], \AdoptSource::$calls);
    }

    public function test_adopt_postAndTokenOnly(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        $page = self::page();

        $this->assertNull($page->handle(['action' => 'adopt', 'key' => 'e3', 'token' => self::token()], ['REQUEST_METHOD' => 'GET']), 'a GET');
        $this->assertStringContainsString('expired', self::shown($page, $page->handle(['action' => 'adopt', 'key' => 'e3'], self::POST)), 'no token');
        $other = FormToken::get('import-' . self::OTHER);
        $this->assertStringContainsString('expired', self::shown($page, $page->handle(['action' => 'adopt', 'key' => 'e3', 'token' => $other], self::POST)), 'a token of another import');
        $this->assertSame([], \AdoptSource::$calls);

        $page = self::page();
        $outcome = $page->handle(['action' => 'adopt', 'key' => 'e3', 'token' => self::token()], self::POST);
        $this->assertNotNull($outcome);
        $this->assertSame(DetailPage::urlOf(self::page()->definition()) . '#aa-import-catalog', $page->afterPost($outcome), 'back to the GET page, at the catalog');
        $this->assertStringContainsString('aa-ok', $page->outcomeOnceHtml());
        $this->assertSame('', $page->outcomeOnceHtml(), 'once');
        $this->assertSame(['fromSettings', 'adoptSlice', 'adopt'], \AdoptSource::$calls);
    }

    /** the entry comes from the stored catalog - a key it does not have adopts nothing, posted data is not taken */
    public function test_adopt_unknownKeyRefused(): void
    {
        self::definition();
        foreach (['no catalog' => 'e3'] as $case => $key) {
            $this->assertStringContainsString('no such entry', self::act(['action' => 'adopt', 'key' => $key]), $case);
        }
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        foreach (['unknown' => 'e4', 'empty' => '', 'list' => ['e3'], 'case' => 'E3', 'none' => null] as $case => $key) {
            $this->assertStringContainsString('no such entry', self::act(['action' => 'adopt'] + (($key === null) ? [] : ['key' => $key])), $case);
        }
        $this->assertSame([], \AdoptSource::$calls);

        self::act(['action' => 'adopt', 'key' => 'e3', 'name' => '<b>forged</b>', 'meta' => ['x' => 'y']]);

        $this->assertSame([['e3', 'Rents', []]], array_map(static fn(CatalogEntry $e): array => [$e->key, $e->name, $e->meta], \AdoptSource::$adopted));
    }

    /** a failure of the source's code is logged and said by its code, not its text */
    public function test_adopt_sourceFailureReported(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        \AdoptSource::$failAdopt = new \RuntimeException('<b>secret</b>');

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('(SOURCE)', $msg);
        $this->assertStringNotContainsString('secret', $msg);
    }

    /** back on the page of the listing the entry was on */
    public function test_adopt_returnsToItsCatalogPage(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        $page    = self::page();
        $outcome = $page->handle(['action' => 'adopt', 'key' => 'e3', 'catalog_page' => '2', 'token' => self::token()], self::POST);
        $this->assertNotNull($outcome);

        $this->assertSame(DetailPage::urlOf($page->definition(), ['catalog_page' => '2']) . '#aa-import-catalog', $page->afterPost($outcome));
    }

    /** a run goes through the streams it started with - a new one waits till it is over */
    public function test_adopt_refusedWhileRunOpen(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        self::importRun(Run::RUNNING);

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('The last run is still running.', $msg);
        $this->assertSame([], \AdoptSource::$calls);
    }

    /** a run ended while its last step may still store items is in the way too, as for a start */
    public function test_adopt_refusedWhileLastStepFinishes(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        $run = self::importRun(Run::ENDED);
        self::storedSet($run, 'lease', (string) (time() + 600));
        self::storedSet($run, 'lease_tok', 'working');

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('still finishing', $msg);
        $this->assertSame([], \AdoptSource::$calls);

        self::storedSet($run, 'lease', (string) (time() - 600));
        $this->assertStringContainsString('aa-ok', self::act(['action' => 'adopt', 'key' => 'e3']), 'its lease over');
    }

    /** adopt() gets the keys the source declares, as a run does */
    public function test_adopt_getsTheKeysOfTheSource(): void
    {
        define('AA_APIKEY_ADOPTTEST', 'sekret-adopt-1234');
        $GLOBALS['r_superuser']     = [AA_ID => true];
        \AdoptSource::$credentials = [['vendor' => 'ADOPTTEST', 'context' => '']];
        \AdoptSource::$askKey      = 'ADOPTTEST';
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);

        $this->assertStringContainsString('aa-ok', self::act(['action' => 'adopt', 'key' => 'e3']));
        $this->assertTrue(\AdoptSource::$keySeen);
    }

    public function test_adopt_contextHasTheCapOfTheSource(): void
    {
        \AdoptSource::$maxBytes = 1234;
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);

        $this->assertStringContainsString('aa-ok', self::act(['action' => 'adopt', 'key' => 'e3']));
        $this->assertSame(1234, \AdoptSource::$capSeen);
    }

    /** the settings are checked for whoever adopts: a slice they name must be one he imports into */
    public function test_adopt_settingsCheckedForTheUser(): void
    {
        self::definition(['settings' => '{"defs":"' . self::OTHER . '"}']);
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('whose feeding you do not manage', $msg);
        $this->assertSame([], \AdoptSource::$calls);
    }

    /** an import moved to another slice after the page opened is not adopted into from this one */
    public function test_adopt_definitionOfAnotherSliceMeanwhile(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_FEEDING . PS_EDIT_ALL_ITEMS);
        $page = self::page();
        self::storedSet(self::DEF, 'aa_owner', self::OTHER);

        $msg = self::shown($page, $page->handle(['action' => 'adopt', 'key' => 'e3', 'token' => self::token()], self::POST));

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertSame([], \AdoptSource::$calls);
    }

    /** a source that runs out of time did not answer - no bug of its code */
    public function test_adopt_timeRanOut(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e3', 'Rents')], []);
        \AdoptSource::$failAdopt = new \AA\Later\BatchBudgetSpent('spent');
        TableDb::$log = [];

        $msg = self::act(['action' => 'adopt', 'key' => 'e3']);

        $this->assertStringContainsString('could not be reached or did not answer', $msg);
        $this->assertStringNotContainsString('(SOURCE)', $msg);
        $this->assertSame([], TableDb::writes(), 'nothing logged');
    }

    /** a catalog whose stored entries or streams cannot be read says so instead of an empty listing */
    public function test_catalog_damagedShown(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e1', 'Prices')], [new Stream('s1', 'Prices', [], 'e1')]);
        $this->assertStringNotContainsString('cannot be read', self::catalogHtml());

        foreach (['entries', 'streams'] as $property) {
            self::catalog([new CatalogEntry('e1', 'Prices')], [new Stream('s1', 'Prices', [], 'e1')]);
            self::storedSet(Catalog::idOf(self::DEF), $property, '{not json');

            $html = self::catalogHtml();

            $this->assertStringContainsString('The stored catalog cannot be read.', $html, $property);
            $this->assertSame([], self::tableRows($html, 'aa-import-catalog'), $property);
        }
    }

    /** showing the page reads what the last check stored: no code of the source runs */
    public function test_view_doesNotCallSource(): void
    {
        self::definition();
        self::catalog([new CatalogEntry('e1', 'Prices'), new CatalogEntry('e3', 'Rents')], [new Stream('s1', 'Prices', [], 'e1')]);
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '2026']);
        $state->store();
        TableDb::$log = [];

        $html = self::page()->html(self::NOW) . self::page()->streamsHtml() . self::page()->streamsHtml('2') . self::catalogHtml() . self::catalogHtml('2');

        $this->assertStringContainsString('aa-import-catalog', $html);
        $this->assertSame([], \AdoptSource::$calls);
        $this->assertSame([], TableDb::writes());
    }

    // ------------------------------------------------------------ helpers

    private static function page(): DetailPage
    {
        return DetailPage::forRequest(self::SLICE, self::DEF, AuthGetUid()) ?? throw new \LogicException('No page');
    }

    private static function catalogHtml(mixed $page = null): string
    {
        return self::page()->catalog()->html($page);
    }

    private static function token(): string
    {
        return FormToken::get('import-' . self::DEF);
    }

    /** posts $post with the token, follows the redirect: the message the GET page shows
     *  @param array<string, mixed> $post */
    private static function act(array $post): string
    {
        $page = self::page();
        return self::shown($page, $page->handle($post + ['token' => self::token()], self::POST));
    }

    /** @param ?array{string, string} $outcome */
    private static function shown(DetailPage $page, ?array $outcome): string
    {
        self::assertNotNull($outcome, 'the POST did something');
        $page->afterPost($outcome);
        return $page->outcomeOnceHtml();
    }

    /** a stored definition of SLICE over AdoptSource
     *  @param array<string, string> $props */
    private static function definition(array $props = []): void
    {
        $def = new Definition();
        $def->setNew(self::DEF, self::SLICE, 'prices');
        foreach ($props + ['source' => self::SOURCE, 'mapping' => self::NO_PARAM_MAPPING, 'key_fields' => '["relation........"]', 'store_mode' => 'update/insert', 'enabled' => '1'] as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame(self::DEF, $def->save());
    }

    /** the catalog a check stored: its entries, and the streams the source had when it listed them
     *  @param list<CatalogEntry> $entries  @param list<Stream> $streams */
    private static function catalog(array $entries, array $streams, bool $filled = true): void
    {
        $catalog = Catalog::create(self::DEF);
        $catalog->listStreams($streams);
        $catalog->addPage(new CatalogPage($entries, $filled ? '' : 'next'), self::NOW);
        $catalog->store();
    }

    /** @return string a run of DEF in $state */
    private static function importRun(string $state = Run::DONE): string
    {
        $def = Definition::fresh(self::DEF) ?? throw new \LogicException('No definition');
        $id  = (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => self::DEF,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
        self::storedSet($id, 'state', $state);
        TableDb::$tables['toexecute'] = [];
        return $id;
    }

    /** @return list<string> the keys the Add/Update forms of $html post */
    private static function formKeys(string $html): array
    {
        $keys = [];
        foreach (self::dom($html)->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('name') === 'key') {
                $keys[] = $input->getAttribute('value');
            }
        }
        return $keys;
    }

    private static function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return $doc;
    }

    private static function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    }

    /** @return list<list<string>> the text of the cells of the body rows of the table of class $class */
    private static function tableRows(string $html, string $class): array
    {
        $rows = [];
        foreach (self::dom($html)->getElementsByTagName('table') as $table) {
            if (!in_array($class, explode(' ', $table->getAttribute('class')), true)) {
                continue;
            }
            foreach ($table->getElementsByTagName('tbody')->item(0)?->getElementsByTagName('tr') ?? [] as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof \DOMElement) {
                        $cells[] = trim((string) preg_replace('/\s+/', ' ', $td->textContent));
                    }
                }
                $rows[] = $cells;
            }
        }
        return $rows;
    }
}
