<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CheckResult;
use AA\Import\Definition;
use AA\Import\DetailPage;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Service;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * The detail page of an import: an overview, the actions - each only a POST with the token of
 * this import, through Service - and the last runs with their errors and samples, all escaped.
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class DetailPageTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE = 'custom:service';
    /** another import of SLICE */
    private const SIBLING = 'fedcba9876543210fedcba9876543210';
    /** an import of OTHER */
    private const FOREIGN = 'abcdefabcdefabcdefabcdefabcdef01';
    private const POST    = ['REQUEST_METHOD' => 'POST'];
    /** a site module */
    private const SITE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    /** an import stored under SITE */
    private const OF_SITE = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';
    /** what a start disables while a run is in the way */
    private const STARTS = ['run', 'changed', 'check', 'dry', 'stream', 'baseline'];

    protected function setUp(): void
    {
        $this->stepsSetUp();
        session_id('test-session');
        $dir = __DIR__ . '/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys     = ['s1', 's2', 's3'];
        \ServiceSource::$refuse         = null;
        \ServiceSource::$failStreams    = null;
        \ServiceSource::$created        = [];
        \ServiceSource::$mapping        = null;
        \ServiceSource::$failProperties = null;
        \ServiceSource::$failMapping    = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S'], ['id' => self::SITE, 'type' => 'W']];
        \AA::$module_id = self::SLICE;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        \AA::$module_id = null;
        session_id('');
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
    }

    // ------------------------------------------------------------ the page of a definition

    /** unknown, foreign or malformed - one answer, so the page tells nothing of other slices' imports */
    public function test_forRequest_onlyDefinitionOfTheSlice(): void
    {
        self::definition();
        self::definition([], self::FOREIGN, self::OTHER);

        $this->assertInstanceOf(DetailPage::class, DetailPage::forRequest(self::SLICE, self::DEF, AuthGetUid()));
        foreach (['foreign' => self::FOREIGN, 'unknown' => 'ffffffffffffffffffffffffffffffff', 'name' => 'prices ' . self::DEF, 'upper' => strtoupper(self::DEF), 'array' => [self::DEF], 'null' => null, 'empty' => ''] as $case => $id) {
            $this->assertNull(DetailPage::forRequest(self::SLICE, $id, AuthGetUid()), $case);
        }
        $this->assertNull(DetailPage::forRequest(self::OTHER, self::DEF, AuthGetUid()), 'not on the page of another slice');
    }

    // ------------------------------------------------------------ overview

    public function test_overview_showsLastCheck(): void
    {
        self::definition();
        self::check(new CheckResult(false, 'AUTH', 'Key <b>refused</b> {user:password}'), self::NOW - 60);

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString(date('Y-m-d H:i', self::NOW - 60), $html);
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('AUTH', $html);
        $this->assertStringContainsString('Key &lt;b&gt;refused&lt;/b&gt; {user:password}', $html, 'escaped, never expanded');
        $this->assertStringNotContainsString('<b>refused', $html);
    }

    /** a refusal the source threw is stored with its code and no message - the code tells it */
    public function test_overview_checkRefusalShowsItsCode(): void
    {
        self::definition();
        self::check(new CheckResult(false, 'NO_CREDENTIAL', ''), self::NOW);
        $this->assertStringContainsString('NO_CREDENTIAL', self::page()->overviewHtml(time()));

        self::check(new CheckResult(true, '', ''), self::NOW);
        $this->assertStringContainsString('Passed', self::page()->overviewHtml(time()));
    }

    public function test_overview_neverCheckedNeverRan(): void
    {
        self::definition();

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('Never ran', $html);
        $this->assertStringContainsString('Never checked', $html);
        $this->assertStringContainsString('Service', $html, 'the name of the source');
    }

    public function test_overview_lastRunAndOpenRun(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'counts', '{"read":7,"new":3,"updated":2,"skipped":1,"errors":1}');
        self::storedSet($id, 'progress', '{"label":"s2","done":1,"total":3}');
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'TRANSPORT');
        self::storedSet($id, 'error_path', 's2:<i>job</i>');

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('Stopped by an error', $html);
        $this->assertStringContainsString('by hand', $html);
        $this->assertMatchesRegularExpression('~<dt>Read</dt><dd>7</dd>.*<dt>New</dt><dd>3</dd>.*<dt>Updated</dt><dd>2</dd>.*<dt>Skipped</dt><dd>1</dd>.*<dt>Item errors</dt><dd>(?:<span[^>]*>.*?</span> )?1</dd>~s', $html, 'the counts as numbers under their names');
        $this->assertStringContainsString('data-kind="bad"', $html, 'a stopped run is marked, not only worded');
        $this->assertStringContainsString('1 of 3', $html, 'how far the open run got');
        $this->assertStringContainsString('TRANSPORT', $html);
        $this->assertStringContainsString('s2:&lt;i&gt;job&lt;/i&gt;', $html);
        $this->assertMatchesRegularExpression('~Continue.*End~s', $html, 'what to do about it');
    }

    public function test_overview_healthSaysEnabledAndNextStart(): void
    {
        self::definition();
        $html = self::page()->overviewHtml(time());
        $this->assertStringContainsString('Enabled', $html);
        $this->assertStringContainsString('Not planned', $html);
        $this->assertSame(1, substr_count($html, 'add a planned task'), 'the reason is said once');

        self::definition(['enabled' => '0']);
        $off = self::page()->overviewHtml(time());
        $this->assertStringContainsString('Disabled', $off);
        $this->assertStringContainsString('data-kind="off"', $off);
        $this->assertStringContainsString('so no planned task starts it', $off);
    }

    /** the call a planned task makes is for whoever may write one by hand, and never live template code */
    public function test_overview_taskCallOnlyForASuperadmin(): void
    {
        self::definition();
        $call = '<code>&#123;import:' . self::DEF . ':changed&#125;</code>';
        $this->assertStringNotContainsString($call, self::page()->overviewHtml(time()));
        $GLOBALS['r_superuser'] = [AA_ID => true];
        try {
            $html = self::page()->overviewHtml(time());
        } finally {
            unset($GLOBALS['r_superuser']);
        }
        $this->assertStringContainsString($call, $html);
        $this->assertStringNotContainsString('{import', $html);
    }

    /** the page is rendered for its user, whoever is logged in */
    public function test_overview_taskCallByTheUserOfThePage(): void
    {
        self::definition();
        $call  = '<code>&#123;import:' . self::DEF . ':changed&#125;</code>';
        $other = 'uid-9';
        // the perms of the page's user, then those of the superadmin logged in
        $GLOBALS['permission_uid'] = $other;
        $page = DetailPage::forRequest(self::SLICE, self::DEF, $other) ?? throw new \LogicException('No page');
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['r_superuser']    = [AA_ID => true];
        try {
            // alone: the rest of the overview asks for the perms of the page's user again
            $html = (new \ReflectionMethod($page, 'taskCallHtml'))->invoke($page);
        } finally {
            unset($GLOBALS['r_superuser']);
        }
        $this->assertStringNotContainsString($call, $html, 'the superadmin logged in is not the user of the page');
    }

    /** the ribbon, the runs table and the list read a run ended after an error the same way: failed */
    public function test_overview_ribbonShowsARunEndedAfterAnErrorAsFailed(): void
    {
        self::definition();
        $a = self::start()->runId;
        self::storedSet($a, 'state', Run::ENDED);
        self::storedSet($a, 'error_code', 'COLUMNS');
        self::storedSet($a, 'progress', '{"note":"SETTINGS_CHANGED"}');
        self::storedSet($a, 'started', (string) (time() - 900));
        $b = self::startMode('check');
        self::storedSet($b, 'state', Run::DONE);

        $html = self::page()->overviewHtml(time());

        $this->assertMatchesRegularExpression('~<li data-outcome="error" data-stored="yes">.*Stopped by an error, then ended - the settings changed</span>~', $html);
        $this->assertMatchesRegularExpression('~A filled square <span class="aa-import-glyph" aria-hidden="true">.*?</span> is a run ended before it finished~', $html, 'the legend names the mark in words');
        $this->assertStringContainsString('a check, a dry run or a baseline', $html);
    }

    /** a check or a dry run is not an import: it never fills the last-import block or hides a real result */
    public function test_overview_lastImportIsToldApartFromANewerCheck(): void
    {
        self::definition();
        $import = self::start()->runId;
        self::storedSet($import, 'counts', '{"read":31,"new":1,"updated":30}');
        self::storedSet($import, 'state', Run::DONE);
        self::storedSet($import, 'started', (string) (time() - 7200));
        $check = self::startMode('check');
        self::storedSet($check, 'state', Run::DONE);
        self::storedSet($check, 'started', (string) (time() - 600));

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('Healthy', $html);
        $this->assertMatchesRegularExpression('~Last import</h3><p class="aa-import-value">[^<]*2 h ago~', $html);
        $this->assertMatchesRegularExpression('~<dt>Read</dt><dd>31</dd>~', $html, 'the counts are the import\'s');
        $this->assertStringContainsString('Since then: check', $html);
        $this->assertStringContainsString('Source reachable - nothing imported', $html);
    }

    /** with no import, nothing is "since" it */
    public function test_overview_noImportSaysTheLatestRun(): void
    {
        self::definition();
        $check = self::startMode('check');
        self::storedSet($check, 'state', Run::DONE);

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('No import yet', $html);
        $this->assertStringContainsString('Latest run: check', $html);
        $this->assertStringNotContainsString('Since then', $html);
    }

    public function test_overview_checkRunFillsInWhenTheStreamStateHoldsNoCheck(): void
    {
        self::definition();
        $check = self::startMode('check');
        self::storedSet($check, 'state', Run::DONE);

        $html = self::page()->overviewHtml(time());

        $this->assertStringNotContainsString('Never checked', $html);
        $this->assertStringContainsString('Passed', $html);
        $this->assertStringContainsString('Taken from the run list', $html);
    }

    /** a check of the settings before a change says nothing of the new ones */
    public function test_overview_checkRunOfOldSettingsDoesNotCount(): void
    {
        self::definition();
        $check = self::startMode('check');
        self::storedSet($check, 'state', Run::DONE);
        self::storedSet(self::DEF, 'settings', '{"territory":"at"}');

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('Never checked', $html);
        $this->assertStringNotContainsString('Taken from the run list', $html);
    }

    /** the last import is found however many runs that stored nothing came after it */
    public function test_overview_lastImportBehindManyChecks(): void
    {
        self::definition();
        $import = self::importRun();
        self::storedSet($import, 'counts', '{"read":4,"new":4}');
        self::storedSet($import, 'started', (string) (self::NOW - 90000));
        for ($i = 0; $i < DetailPage::RUNS_SHOWN + 2; $i++) {
            $check = self::startMode('check');
            self::storedSet($check, 'state', Run::DONE);
            self::storedSet($check, 'started', (string) (self::NOW - 80000 + $i));
            TableDb::$tables['toexecute'] = [];
        }

        $html = self::page()->overviewHtml(self::NOW);

        $this->assertStringNotContainsString('No import yet', $html);
        $this->assertStringContainsString('Healthy', $html);
        $this->assertMatchesRegularExpression('~<dt>Read</dt><dd>4</dd>~', $html);
    }

    public function test_overview_ribbonHasATickPerRunWithItsWords(): void
    {
        self::definition();
        $a = self::start()->runId;
        self::storedSet($a, 'state', Run::DONE);
        self::storedSet($a, 'counts', '{"read":2,"new":2}');
        self::storedSet($a, 'started', (string) (time() - 900));
        $b = self::startMode('check');
        self::storedSet($b, 'state', Run::DONE);

        $html = self::page()->overviewHtml(time());

        $this->assertSame(2, substr_count($html, 'href="#aa-import-runs"'));
        $this->assertStringContainsString('data-stored="no"', $html, 'a check is a dashed tick');
        $this->assertStringContainsString('data-stored="yes"', $html);
        $this->assertMatchesRegularExpression('~<span class="aa-import-sr">[^<]*check, by hand - Finished</span>~', $html, 'the tick says it in words too');
    }

    public function test_overview_firstItemErrorIsShownEscaped(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'counts', '{"read":5,"new":3,"errors":2}');
        self::storedSet($id, 'errors', '[{"stream":"s1","job":"j","code":"BAD_ROW","msg":"<b>x</b>"}]');

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('Needs attention', $html);
        $this->assertStringContainsString('First item error:', $html);
        $this->assertStringContainsString('BAD_ROW: &lt;b&gt;x&lt;/b&gt; (s1 j)', $html);
    }

    public function test_overview_mappingProblemsInWords(): void
    {
        self::definition(['key_fields' => '["headline........"]']);

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('KEY_NOT_TARGET', $html);
        $this->assertStringContainsString('A key field is not filled by the mapping', $html);
    }

    // ------------------------------------------------------------ actions

    public function test_post_withoutTokenDoesNothing(): void
    {
        self::definition();
        self::definition([], self::SIBLING);
        $page = self::page();
        TableDb::$log = [];

        foreach (['none' => [], 'wrong' => ['token' => 'x'], 'of another import' => ['token' => FormToken::get('import-' . self::SIBLING)], 'array' => ['token' => [self::token()]]] as $case => $token) {
            $msg = self::shown($page, $page->handle(['action' => 'run'] + $token, self::POST));

            $this->assertStringContainsString('expired', $msg, $case);
            $this->assertSame([], TableDb::writes(), $case);
        }
    }

    public function test_get_withActionDoesNothing(): void
    {
        self::definition();
        TableDb::$log = [];

        foreach (['GET', 'HEAD', ''] as $method) {
            $this->assertNull(self::page()->handle(['action' => 'run', 'token' => self::token()], ['REQUEST_METHOD' => $method]), $method);
        }
        $this->assertNull(self::page()->handle(['token' => self::token()], self::POST), 'a POST without an action');
        $this->assertSame([], TableDb::writes());
    }

    public function test_get_pageChangesNothing(): void
    {
        self::definition();
        self::start();
        TableDb::$log = [];

        $html = self::page()->html(time());

        $this->assertStringContainsString('<form method="post"', $html);
        $this->assertSame([], TableDb::writes());
    }

    public function test_post_runQueues(): void
    {
        self::definition();

        $msg = self::post('run');

        $runs = Run::unfinished(self::DEF);
        $this->assertCount(1, $runs);
        $this->assertSame(['run', 'manual', AuthGetUid(), false], [$runs[0]->mode(), (string) $runs[0]->getProperty('trigger'), (string) $runs[0]->getProperty('user'), $runs[0]->isDry()]);
        $this->assertStringContainsString('queued', $msg);
        $this->assertStringContainsString('aa-ok', $msg);
    }

    /** @return iterable<string, array{array<string, string>, string, string, string, list<string>}> post, mode, dry, limit, streams */
    public static function startActions(): iterable
    {
        yield 'changed'        => [['action' => 'changed'], 'changed', '', '', ['s1', 's2', 's3']];
        yield 'check'          => [['action' => 'check'], 'check', '', '', ['s1', 's2', 's3']];
        yield 'dry'            => [['action' => 'dry'], 'run', '1', '500', ['s1', 's2', 's3']];
        yield 'stream'         => [['action' => 'stream', 'stream_key' => 's2'], 'run', '', '', ['s2']];
        yield 'run of stream'  => [['action' => 'stream', 'stream_key' => 's1', 'dry' => '0'], 'run', '', '', ['s1']];
        yield 'dry of stream'  => [['action' => 'stream', 'stream_key' => 's3', 'dry' => '1'], 'run', '1', '500', ['s3']];
        yield 'baseline'       => [['action' => 'baseline'], 'baseline', '', '', ['s1', 's2', 's3']];
    }

    /** @param array<string, string> $post @param list<string> $streams */
    #[DataProvider('startActions')]
    public function test_post_eachStartAction(array $post, string $mode, string $dry, string $limit, array $streams): void
    {
        self::definition();

        self::act($post);

        $run = self::stored((string) Run::unfinished(self::DEF)[0]->getId());
        $this->assertSame([$mode, $dry, $limit, $streams], [$run['mode'], $run['dry'], $run['limit'], json_decode($run['stream_keys'], true)]);
    }

    public function test_post_badStreamOrActionRefused(): void
    {
        self::definition();

        foreach (['unknown stream' => ['action' => 'stream', 'stream_key' => 's9'], 'bad key' => ['action' => 'stream', 'stream_key' => 'a:b'], 'no key' => ['action' => 'stream'], 'array key' => ['action' => 'stream', 'stream_key' => ['s1']], 'unknown action' => ['action' => 'delete'], 'array action' => ['action' => ['run']]] as $case => $post) {
            $msg = self::act($post);

            $this->assertStringContainsString('aa-err', $msg, $case);
            $this->assertSame([], Run::unfinished(self::DEF), $case);
        }
    }

    public function test_post_withoutFeedingDoesNothing(): void
    {
        self::definition();
        $page = self::page();
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);
        TableDb::$log = [];

        $msg = self::shown($page, $page->handle(['action' => 'run', 'token' => self::token()], self::POST));

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertSame([], TableDb::writes());
    }

    /** Review Focus 3: a run of another definition - of this slice or another - is unknown here */
    public function test_post_definitionOfOtherSliceRefused(): void
    {
        self::definition();
        self::definition([], self::SIBLING);
        self::definition([], self::FOREIGN, self::OTHER);
        self::perms(PS_FEEDING, PS_FEEDING);
        $foreign  = self::start(self::FOREIGN)->runId;
        $sibling  = self::start(self::SIBLING)->runId;
        foreach ([$foreign, $sibling] as $id) {
            self::storedSet($id, 'state', Run::ERROR);
        }
        TableDb::$tables['toexecute'] = [];
        $before = TableDb::$tables;

        foreach (['foreign' => $foreign, 'sibling' => $sibling, 'junk' => 'x', 'array' => [$sibling]] as $case => $run) {
            foreach (['resume', 'end'] as $action) {
                $msg = self::act(['action' => $action, 'run' => $run]);

                $this->assertStringContainsString('No such run', $msg, "$case $action");
            }
        }
        $this->assertSame($before, TableDb::$tables);
        $this->assertNull(DetailPage::forRequest(self::SLICE, self::FOREIGN, AuthGetUid()));
    }

    public function test_post_resumeAndEndUseService(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'TRANSPORT');
        TableDb::$tables['toexecute'] = [];

        $msg = self::act(['action' => 'resume', 'run' => $id]);

        $this->assertSame([Run::RUNNING, ''], [self::stored($id)['state'], self::stored($id)['error_code']]);
        $this->assertSame(['Import_' . self::DEF], array_column(self::queue(), 'selector'));
        $this->assertStringContainsString('aa-ok', $msg);

        $again = self::act(['action' => 'resume', 'run' => $id]);
        $this->assertStringContainsString('aa-err', $again, 'its step is queued already');
        $this->assertStringContainsString('already on its way', $again);
        $this->assertStringNotContainsString('Not started', $again, 'Continue starts no run');

        $msg = self::act(['action' => 'end', 'run' => $id]);

        $this->assertSame(Run::ENDED, self::stored($id)['state']);
        $this->assertStringContainsString('aa-ok', $msg);
        $this->assertStringContainsString('finished already', self::act(['action' => 'end', 'run' => $id]), 'ended already');
    }

    /** a start while a run stopped by an error: Continue or End is the way on */
    public function test_post_startWhileErrorExplainsContinueEnd(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);

        $msg = self::post('run');

        $this->assertMatchesRegularExpression('~Continue.*End~s', $msg);
        $this->assertCount(1, self::runIds());
    }

    public function test_post_failureOfServiceLoggedByCode(): void
    {
        self::definition();
        \ServiceSource::$failStreams = new \RuntimeException('secret <b>key</b>');

        $msg = self::post('run');

        $this->assertStringContainsString('SOURCE', $msg);
        $this->assertStringNotContainsString('secret', $msg);
    }

    // ------------------------------------------------------------ buttons

    public function test_buttons_disabledWithReasonWhileBusy(): void
    {
        self::definition();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '1']);
        $state->store();
        $free = self::buttons(self::page()->actionsHtml(time()));
        foreach (self::STARTS as $action) {
            $this->assertFalse($free[$action]['disabled'], $action);
        }
        $this->assertTrue($free['resume']['disabled'], 'nothing to continue');
        $this->assertTrue($free['end']['disabled'], 'nothing to end');
        self::assertReasonShown($free, ['resume', 'end'], self::page()->actionsHtml(time()));

        $id   = self::start()->runId;
        $busy = self::buttons($html = self::page()->actionsHtml(time()));
        self::assertReasonShown($busy, self::STARTS, $html);
        $this->assertStringContainsString('still running', $html);
        $this->assertTrue($busy['resume']['disabled'], 'its first step is queued');
        $this->assertFalse($busy['end']['disabled']);

        self::storedSet($id, 'state', Run::ERROR);
        $error = self::buttons($html = self::page()->actionsHtml(time()));
        self::assertReasonShown($error, self::STARTS, $html);
        $this->assertMatchesRegularExpression('~stopped by an error.*Continue.*End~s', $html);
        $this->assertFalse($error['resume']['disabled']);
        $this->assertSame($id, $error['resume']['run']);

        self::storedSet($id, 'state', Run::RUNNING);
        self::storedSet($id, 'next_at', (string) (time() + 600));
        $waiting = self::buttons(self::page()->actionsHtml(time()));
        $this->assertFalse($waiting['resume']['disabled']);
        $this->assertSame('Try now', $waiting['resume']['label']);

        self::storedSet($id, 'state', Run::ENDED);
        self::storedSet($id, 'lease', (string) (time() + 600));
        self::storedSet($id, 'lease_tok', 'working');
        $finishing = self::buttons($html = self::page()->actionsHtml(time()));
        self::assertReasonShown($finishing, self::STARTS, $html);
        $this->assertStringContainsString('still finishing', $html);
        $this->assertTrue($finishing['end']['disabled']);
    }

    /** the page does not call the source: the streams it knows are those of the stream state and of the last runs */
    public function test_buttons_streamsKnownFromStateAndRuns(): void
    {
        self::definition();
        $this->assertTrue(self::buttons(self::page()->actionsHtml(time()))['stream']['disabled'], 'no stream known yet');

        $state = StreamState::create(self::DEF);
        $state->record('s2', ['v' => '2026']);
        $state->store();
        self::storedSet(StreamState::idOf(self::DEF), 'streams', '{"s2":{"v":"2026"},"<x>":{"v":"1"}}');
        $id = self::importRun();
        self::storedSet($id, 'stream_keys', '["s1","s2","<y>"]');
        TableDb::$log = [];

        $html = self::page()->actionsHtml(time());

        $this->assertStringContainsString('<option value="s1">s1</option>', $html);
        $this->assertSame(1, substr_count($html, '<option value="s2">s2</option>'));
        foreach (['x', 'y'] as $bad) {
            $this->assertStringNotContainsString("<$bad>", $html);
            $this->assertStringNotContainsString("&lt;$bad&gt;", $html, 'a key the run would refuse is not offered');
        }
        $this->assertFalse(self::buttons($html)['stream']['disabled']);
        $this->assertSame([], TableDb::writes(), 'read only');
        $this->assertSame([], \ServiceSource::$created, 'the source is not asked');
    }

    public function test_buttons_formsCarryTheTokenOfTheImport(): void
    {
        self::definition();

        $html = self::page()->actionsHtml(time());

        preg_match_all('~<form method="post"~', $html, $forms);
        preg_match_all('~name="token" value="' . self::token() . '"~', $html, $tokens);
        $this->assertNotEmpty($forms[0]);
        $this->assertCount(count($forms[0]), $tokens[0]);
    }

    // ------------------------------------------------------------ runs

    public function test_runs_listEscapesErrorsAndSamples(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'dry', '1');
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'errors', json_encode([['stream' => 's1', 'job' => '<script>x()</script>', 'code' => 'VALIDATE', 'msg' => 'Item 1: <img src=x onerror=alert(1)> {user:password} _#AA_ID___']], JSON_THROW_ON_ERROR));
        self::storedSet($id, 'samples', json_encode([['headline........' => ['<b>bold</b>', '{now}']], ['<i>field</i>' => 'scalar <u>x</u>']], JSON_THROW_ON_ERROR));
        self::storedSet($id, 'error_path', '<svg onload=alert(1)>');

        $html = self::page()->runsHtml(time());

        foreach (['<script>', '<img', '<b>bold', '<i>field', '<u>x', '<svg onload'] as $markup) {
            $this->assertStringNotContainsString($markup, $html);
        }
        $this->assertStringContainsString('&lt;script&gt;x()&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; {user:password} _#AA_ID___', $html, 'never expanded');
        $this->assertStringContainsString('VALIDATE', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('{now}', $html);
        $this->assertStringContainsString('&lt;i&gt;field&lt;/i&gt;', $html);
        $this->assertStringContainsString('dry run', $html);
    }

    public function test_runs_listEscapesEmptyKeySamples(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'empty_keys', json_encode(['count' => 7, 'samples' => [
            ['stream' => 's1', 'job' => '<i>job</i>', 'values' => ['<b>geo</b>' => '<script>alert(1)</script> {user:password}']],
        ]], JSON_THROW_ON_ERROR));

        $html = self::page()->runsHtml(time());

        foreach (['<script>', '<b>geo', '<i>job'] as $markup) {
            $this->assertStringNotContainsString($markup, $html);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; {user:password}', $html, 'never expanded');
        $this->assertStringContainsString('&lt;b&gt;geo&lt;/b&gt;', $html);
        $this->assertStringContainsString('id="aa-import-nokey-' . $id . '"', $html);
        $this->assertStringContainsString('(7)', $html, 'all of them counted, not only the samples');
    }

    public function test_runs_emptyKeysWithoutSamples_onlyTheCount(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'empty_keys', json_encode(['count' => 3, 'samples' => []], JSON_THROW_ON_ERROR));

        $html = self::page()->runsHtml(time());

        $this->assertStringContainsString('<p>Skipped - the key came out empty (3)</p>', $html);
        $this->assertStringNotContainsString('aa-import-nokey-', $html, 'no empty table');
    }

    public function test_runs_lastTwenty(): void
    {
        self::definition();
        for ($i = 0; $i < 22; $i++) {
            $id = self::importRun();
            self::storedSet($id, 'started', (string) (self::NOW + $i));
        }

        $html = self::page()->runsHtml(time());

        $this->assertSame(20, substr_count($html, '<tr class="aa-import-run"'));
        $this->assertStringContainsString(date('Y-m-d H:i:s', self::NOW + 21), $html, 'the newest');
        $this->assertStringNotContainsString(date('Y-m-d H:i:s', self::NOW + 1), $html, 'the two oldest are left out');
    }

    public function test_runs_none(): void
    {
        self::definition();

        $this->assertStringContainsString('No run yet', self::page()->runsHtml(time()));
    }

    // ------------------------------------------------------------ baseline: the state of the source recorded

    public function test_baseline_postAndPermOnly(): void
    {
        self::definition();
        $page = self::page();
        TableDb::$log = [];

        $this->assertNull($page->handle(['action' => 'baseline', 'token' => self::token()], ['REQUEST_METHOD' => 'GET']), 'a GET');
        $this->assertStringContainsString('expired', self::shown($page, $page->handle(['action' => 'baseline'], self::POST)), 'no token');
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);
        $this->assertStringContainsString('aa-err', self::shown($page, $page->handle(['action' => 'baseline', 'token' => self::token()], self::POST)), 'no right to the imports of the slice');
        $this->assertSame([], TableDb::writes());

        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        \ServiceSource::$credentials = [['vendor' => 'csu', 'context' => '']];
        $html   = self::page()->actionsHtml(time());
        $button = self::buttons($html)['baseline'];
        $this->assertTrue($button['disabled'], 'a source with keys, not a superadmin');
        $this->assertStringContainsString('superadmin', $button['title']);
        $this->assertStringContainsString($button['title'], html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('superadmin', self::act(['action' => 'baseline']), 'a POST all the same');
        $this->assertSame([], self::runIds());

        $GLOBALS['r_superuser'] = [AA_ID => true];
        $this->assertFalse(self::buttons(self::page()->actionsHtml(time()))['baseline']['disabled']);
        $this->assertStringContainsString('queued', self::act(['action' => 'baseline']));
        $runs = Run::unfinished(self::DEF);
        $this->assertSame(['baseline', 'manual'], [$runs[0]->mode(), (string) $runs[0]->getProperty('trigger')]);

        // a run in the way is the reason, said once above the forms
        $GLOBALS['r_superuser'] = [AA_ID => false];
        $busy = self::buttons(self::page()->actionsHtml(time()))['baseline'];
        $this->assertTrue($busy['disabled']);
        $this->assertStringContainsString('still running', $busy['title']);
    }

    /** the page asks before a baseline: it cannot be taken back */
    public function test_baseline_formAsksFirst(): void
    {
        self::definition();
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . self::page()->actionsHtml(time()) . '</body>');

        $asks = (new \DOMXPath($doc))->query('//form[.//input[@name="action"][@value="baseline"]]/@data-aa-confirm');
        $this->assertSame(1, $asks?->length);
        $this->assertStringContainsString('will not be imported', (string) $asks->item(0)?->nodeValue);
    }

    public function test_baseline_sourceWithoutProbeDisabled(): void
    {
        $dir = __DIR__ . '/fixtures/definition';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        self::definition(['source' => 'custom:definitionplain']);

        $html   = self::page()->actionsHtml(time());
        $button = self::buttons($html)['baseline'];

        $this->assertTrue($button['disabled']);
        $this->assertStringContainsString('cannot tell the versions', $button['title']);
        $this->assertStringContainsString($button['title'], html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $this->assertFalse(self::buttons($html)['run']['disabled'], 'only the baseline');
        $this->assertStringContainsString('cannot tell the versions', self::act(['action' => 'baseline']));
        $this->assertSame([], self::runIds());
    }

    public function test_baseline_runShownWithItsCounts(): void
    {
        self::definition();
        $id = self::importRun();
        self::storedSet($id, 'mode', 'baseline');
        self::storedSet($id, 'counts', '{"streams":3,"unversioned":1}');

        $text = html_entity_decode(strip_tags(self::page()->runsHtml(time())), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('recording the state of the source', $text);
        $this->assertStringContainsString('Recorded the state of 3 streams - nothing imported. 1 without a version', $text);
        $this->assertStringNotContainsString('read 0', $text);
    }

    /** a stream a baseline recorded is told apart from one imported - until an import comes after it */
    public function test_baseline_streamsTableSaysBaseline(): void
    {
        self::definition();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '1', 'imported' => 1000, 'baseline' => 2000000000]);
        $state->record('s2', ['v' => '2', 'imported' => 2000000000, 'baseline' => 1000]);
        $state->record('s3', ['baseline' => 2000000000]);
        $state->store();

        $rows = [];
        $doc  = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . self::page()->streamsHtml() . '</body>');
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            $cells = array_map(static fn(\DOMNode $td): string => trim($td->textContent), iterator_to_array($tr->getElementsByTagName('td')));
            $head  = $tr->getElementsByTagName('th')->item(0);
            if ($cells && $head) {
                $rows[trim($head->textContent)] = trim($tr->getElementsByTagName('span')->item(0)->textContent) . ' ' . $cells[2];
            }
        }

        $at = date('Y-m-d H:i', 2000000000);
        $this->assertSame(['s1' => "Baseline only $at", 's2' => "Imported $at", 's3' => "Baseline only $at"], $rows);
    }

    /** a stream without a version says why, not a bare "--" */
    public function test_streams_missingVersionExplained(): void
    {
        self::definition();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['probed' => 2000000000]);
        $state->record('s2', ['imported' => 2000000000]);
        $state->record('s3', ['v' => '7', 'imported' => 2000000000]);
        $state->record('s4', ['baseline' => 2000000000]);
        $state->store();

        $html = self::page()->streamsHtml();

        $this->assertSame(2, substr_count($html, '<small>None yet - an import records it</small>'), 'neither a check nor a baseline of a stream versioned by a field records one');
        $this->assertSame(1, substr_count($html, '<small>None - the source gives no version</small>'));
        $this->assertStringContainsString('<code class="aa-import-version">7</code>', $html);
    }

    /** a probe in the same second as the import came with it, not after it */
    public function test_streams_probeOfTheImportIsNotAskedAgain(): void
    {
        self::definition();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '1', 'imported' => 2000000000, 'probed' => 2000000000]);
        $state->record('s2', ['v' => '1', 'imported' => 2000000000, 'probed' => 2000000001]);
        $state->store();

        $html = self::page()->streamsHtml();

        $this->assertSame(1, substr_count($html, 'The source has not been asked since.'));
        $this->assertSame(1, substr_count($html, 'The source was asked again after the import.'));
    }

    // ------------------------------------------------------------ redirect after an action

    /** a POST is answered by the GET page, which shows the outcome once - a reload repeats nothing */
    public function test_post_redirectsToGetPageShowingOutcomeOnce(): void
    {
        self::definition();
        self::definition([], self::SIBLING);
        $page = self::page();

        $outcome = $page->handle(['action' => 'check', 'token' => self::token()], self::POST);
        $url     = $page->afterPost((array) $outcome);

        $this->assertSame(['queued', ''], $outcome);
        $this->assertStringContainsString('admin/se_dataimport.php?', $url);
        $this->assertStringContainsString('change_id=' . self::SLICE, $url);
        $this->assertStringContainsString('id=' . self::DEF, $url);
        $this->assertStringNotContainsString('action', $url);
        $this->assertSame('', (DetailPage::forRequest(self::SLICE, self::SIBLING, AuthGetUid()) ?? throw new \LogicException())->outcomeOnceHtml(), 'not on the page of another import');
        $this->assertStringContainsString('queued', self::page()->outcomeOnceHtml());
        $this->assertSame('', self::page()->outcomeOnceHtml(), 'once');
        $this->assertCount(1, self::runIds());
    }

    public function test_outcome_unknownCodeShowsNothing(): void
    {
        $this->assertSame('', DetailPage::outcomeHtml('<b>', 'X'));
        $this->assertStringContainsString('The import source refused: &lt;b&gt;', DetailPage::outcomeHtml('refused', '<b>'));
    }

    /** a start of an import deleted meanwhile: the import is gone, not "no such run" */
    public function test_post_startOfDeletedImport(): void
    {
        self::definition();
        $page = self::page();
        TableDb::$tables['object_text'] = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] !== self::DEF));

        $msg = self::shown($page, $page->handle(['action' => 'run', 'token' => self::token()], self::POST));

        $this->assertStringContainsString('no longer exists', $msg);
    }

    // ------------------------------------------------------------ what Service would refuse

    public function test_buttons_disabledWhenSourceUnavailable(): void
    {
        self::definition(['source' => 'custom:gone']);
        self::knownStream();

        $html = self::page()->actionsHtml(time());

        self::assertReasonShown(self::buttons($html), self::STARTS, $html);
        $this->assertStringContainsString('The import source is not available.', $html);
        $this->assertStringContainsString('custom:gone - this source is not available', self::page()->overviewHtml(time()));
    }

    public function test_buttons_disabledWhenMappingDoesNotFit(): void
    {
        self::definition(['key_fields' => '["headline........"]']);
        self::knownStream();

        $html = self::page()->actionsHtml(time());

        self::assertReasonShown(self::buttons($html), self::STARTS, $html);
        $this->assertStringContainsString('(DEFINITION)', $html);

        self::storedSet(self::DEF, 'mapping', '{broken');
        $html = self::page()->actionsHtml(time());
        self::assertReasonShown(self::buttons($html), self::STARTS, $html);
        $this->assertStringContainsString('cannot be read', self::page()->overviewHtml(time()));
    }

    // ------------------------------------------------------------ slices only

    /** the admin role of a site holds PS_FEEDING too - imports fill slices only */
    public function test_slicesOnly(): void
    {
        $GLOBALS['permission_to']['slice'][self::SITE] = PS_FEEDING;
        // the type of each module, as AA_Module::getModuleInfo() reads it - TableDb has no HEX()
        TableDb::$answer = static fn(string $sql): ?array => str_contains($sql, 'LOWER(HEX(`id`))') ? [['unpackid' => self::SITE, 'type' => 'W']] : null;
        $def = self::definition([], self::OF_SITE, self::SITE);

        $this->assertTrue(Definition::mayImportInto(AuthGetUid(), self::SLICE));
        $this->assertFalse(Definition::mayImportInto(AuthGetUid(), self::SITE));
        $this->assertNull(DetailPage::forRequest(self::SITE, self::OF_SITE, AuthGetUid()));
        $this->assertSame(['invalid', 'FORBIDDEN'], [($r = Service::start($def, 'run', [], 'manual', AuthGetUid()))->code, $r->reason]);
        $this->assertSame([], self::runIds());
    }

    // ------------------------------------------------------------ states of the open run

    /** no step for long: Continue and End are the way, said once and the same everywhere */
    public function test_hangingRun(): void
    {
        self::definition();
        self::knownStream();
        $id = self::start()->runId;
        self::storedSet($id, 'started', '1000');
        self::storedSet($id, 'next_at', '');
        TableDb::$tables['toexecute'] = [];

        $actions  = self::page()->actionsHtml(time());
        $overview = self::page()->overviewHtml(time());
        $buttons  = self::buttons($actions);

        $this->assertFalse($buttons['resume']['disabled']);
        $this->assertSame('Continue', $buttons['resume']['label']);
        self::assertReasonShown($buttons, self::STARTS, $actions);
        $this->assertStringContainsString('No step has worked on the last run for a long time. Continue it or End it first', $actions);
        $this->assertMatchesRegularExpression('~No step has worked on it for a long time.*TOEXECUTE_ALLOWED_TIME.*Continue.*End~s', $overview);
        $this->assertStringNotContainsString('waits in the task queue', $overview);
        $this->assertStringContainsString('No step has worked on the last run', self::post('run'));
    }

    public function test_runWithStepWorking(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'lease', (string) (time() + 600));
        self::storedSet($id, 'lease_tok', 'working');

        $actions = self::page()->actionsHtml(time());
        $resume  = self::buttons($actions)['resume'];

        $this->assertSame([true, 'Its step is working now.'], [$resume['disabled'], $resume['title']]);
        $this->assertStringContainsString('A step is working now.', self::page()->overviewHtml(time()));

        // stopped by an error while its last step still holds the lease: the error is what to act on,
        // once that step is surely gone
        self::storedSet($id, 'state', Run::ERROR);
        $overview = self::page()->overviewHtml(time());
        $this->assertStringContainsString('Stopped by an error', $overview);
        $this->assertStringNotContainsString('A step is working now.', $overview);
        $this->assertSame([true, 'Its step is working now.'], [self::buttons(self::page()->actionsHtml(time()))['resume']['disabled'], self::buttons(self::page()->actionsHtml(time()))['resume']['title']]);
        self::storedSet($id, 'lease', (string) (time() - Run::DEAD_GRACE));
        $this->assertFalse(self::buttons(self::page()->actionsHtml(time()))['resume']['disabled']);
    }

    /** its step died holding the lease: the page offers what Continue does - the same rule */
    public function test_deadStep_tryNow(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'lease', (string) (time() - Run::DEAD_GRACE + 60));
        self::storedSet($id, 'lease_tok', 'dead-step');
        $grace = self::buttons(self::page()->actionsHtml(time()))['resume'];
        $this->assertSame([true, 'Its last step may still be working.'], [$grace['disabled'], $grace['title']]);
        $this->assertStringContainsString('Its last step may still be working.', self::page()->overviewHtml(time()));
        $this->assertStringNotContainsString('waits in the task queue', self::page()->overviewHtml(time()));
        $this->assertStringContainsString('Its last step may still be working. Try again in a few minutes.', self::act(['action' => 'resume', 'run' => $id]));
        self::storedSet($id, 'lease', (string) (time() - Run::DEAD_GRACE));

        $resume = self::buttons(self::page()->actionsHtml(time()))['resume'];
        $this->assertSame([false, 'Try now'], [$resume['disabled'], $resume['label']]);
        $this->assertStringContainsString('Its last step stopped without finishing', self::page()->overviewHtml(time()));

        $this->assertStringContainsString('its next step is queued', self::act(['action' => 'resume', 'run' => $id]));
        $again = self::buttons(self::page()->actionsHtml(time()))['resume'];
        $this->assertSame([true, 'Its next step waits in the task queue.'], [$again['disabled'], $again['title']]);
        $this->assertStringNotContainsString('Its last step stopped without finishing', self::page()->overviewHtml(time()));
    }

    /** the queue holds its step back (a queue round could not run it): the page says till when */
    public function test_queuedStep_saysUntilWhen(): void
    {
        self::definition();
        self::start();
        $until = time() + 1200;
        TableDb::$tables['toexecute'] = array_map(static fn(array $row): array => ['execute_after' => $until] + $row, TableDb::rows('toexecute'));

        $said = 'Its next step waits in the task queue until ' . date('H:i', $until) . '.';
        $this->assertStringContainsString($said, self::page()->overviewHtml(time()));
        $this->assertSame([true, $said], [self::buttons(self::page()->actionsHtml(time()))['resume']['disabled'], self::buttons(self::page()->actionsHtml(time()))['resume']['title']]);

        TableDb::$tables['toexecute'] = array_map(static fn(array $row): array => ['execute_after' => time() - 1] + $row, TableDb::rows('toexecute'));
        $this->assertStringContainsString('Its next step waits in the task queue.', self::page()->overviewHtml(time()));
    }

    /** a step at work keeps its queue row leased for the whole claim - the page says it works, not that it waits */
    public function test_workingStep_notQueuedUntil(): void
    {
        self::definition();
        $id = self::start()->runId;
        TableDb::$tables['toexecute'] = array_map(static fn(array $row): array => ['execute_after' => time() + 1800] + $row, TableDb::rows('toexecute'));
        self::storedSet($id, 'lease', (string) (time() + 600));
        self::storedSet($id, 'lease_tok', 'working-step');

        $overview = self::page()->overviewHtml(time());
        $this->assertStringContainsString('A step is working now.', $overview);
        $this->assertStringNotContainsString('waits in the task queue', $overview);
        $this->assertSame('Its step is working now.', self::buttons(self::page()->actionsHtml(time()))['resume']['title']);
    }

    public function test_waitingRun_failedTriesOnlyWhenAny(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'next_at', (string) (time() + 600));

        self::storedSet($id, 'failures', '0');
        $this->assertStringNotContainsString('failed tr', self::page()->overviewHtml(time()));
        self::storedSet($id, 'failures', '1');
        $this->assertStringContainsString('after 1 failed try.', self::page()->overviewHtml(time()));
        self::storedSet($id, 'failures', '2');
        $this->assertStringContainsString('after 2 failed tries', self::page()->overviewHtml(time()));
    }

    public function test_overview_namesTheFieldTheSliceLacks(): void
    {
        self::definition(['mapping' => '{"rows":[{"to":"relation........","t":"copyfield","p":{"field2copy":"key"}},{"to":"text...........9","t":"copyfield","p":{"field2copy":"x"}}]}']);

        $html = self::page()->overviewHtml(time());

        $this->assertStringContainsString('The mapping fills a field the slice does not have: text...........9. <code>UNKNOWN_FIELD</code>', $html);
    }

    public function test_noOpenRun_reasonOnce(): void
    {
        self::definition();

        $this->assertSame(1, substr_count(self::page()->actionsHtml(time()), '>No run is open.<'));
    }

    /** an error stops the clock at the last progress, not at now */
    public function test_took_stopsWithTheRun(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'started', (string) (self::NOW - 7000));
        self::storedSet($id, 'moved', (string) (self::NOW - 6880));
        self::storedSet($id, 'state', Run::ERROR);

        $this->assertMatchesRegularExpression('~>2 min</td>~', self::page()->runsHtml(self::NOW));

        self::storedSet($id, 'state', Run::ENDED);
        self::storedSet($id, 'finished', (string) (self::NOW - 6400));
        $this->assertMatchesRegularExpression('~>10 min</td>~', self::page()->runsHtml(self::NOW));

        self::storedSet($id, 'state', Run::RUNNING);
        self::storedSet($id, 'finished', '');
        $this->assertMatchesRegularExpression('~>116 min</td>~', self::page()->runsHtml(self::NOW));
    }

    /** the waits in the task queue count in Took; the steps' own time stands under it when it reads otherwise */
    public function test_took_workingTimeApart(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'started', (string) (self::NOW - 110));
        self::storedSet($id, 'finished', (string) self::NOW);
        self::storedSet($id, 'state', Run::DONE);

        $this->assertStringNotContainsString('working', self::page()->runsHtml(self::NOW), 'a run from before working time was counted');

        self::storedSet($id, 'work_ms', '2400');
        $this->assertStringContainsString('>1 min 50 s<small>working 2 s</small></td>', self::page()->runsHtml(self::NOW));

        self::storedSet($id, 'work_ms', '110300');
        $this->assertStringNotContainsString('working', self::page()->runsHtml(self::NOW), 'no wait worth telling');

        // in error Took ends at the last progress, the failed tries worked on after it
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'finished', '');
        self::storedSet($id, 'moved', '');
        self::storedSet($id, 'work_ms', '90000');
        $html = self::page()->runsHtml(self::NOW);
        $this->assertStringContainsString('>0 s</td>', $html);
        $this->assertStringNotContainsString('working', $html, 'never more working than Took');
    }

    public function test_runs_endedSaysWhyByItsNote(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ENDED);
        $this->assertStringContainsString('Ended by hand', self::page()->runsHtml(self::NOW));

        self::storedSet($id, 'progress', '{"note":"SETTINGS_CHANGED"}');
        $html = self::page()->runsHtml(self::NOW);
        $this->assertStringContainsString('Ended - the settings changed', $html);
        $this->assertStringNotContainsString('Ended by hand', $html);

        self::storedSet($id, 'progress', '{"note":"SCHEDULED_START"}');
        $this->assertStringContainsString('Ended - a planned run started', self::page()->runsHtml(self::NOW));
    }

    public function test_runs_errorTellsWhatToDoAndThatEndedCameAfter(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'COLUMNS');
        $html = self::page()->runsHtml(self::NOW);
        $this->assertStringContainsString('What to do:', $html);
        $this->assertStringContainsString('<code class="aa-import-code">COLUMNS</code>', $html);
        $this->assertStringNotContainsString('afterwards', $html);

        self::storedSet($id, 'state', Run::ENDED);
        $html = self::page()->runsHtml(self::NOW);
        $this->assertStringContainsString('data-outcome="error"', $html, 'ending it does not hide that it failed');
        $this->assertStringContainsString('<strong>Stopped by an error</strong><small class="aa-import-then">then ended by hand</small>', $html);
        $this->assertStringContainsString('Stopped before reading anything', $html, 'no row of zeros');
    }

    // ------------------------------------------------------------ why the source failed

    public function test_runs_errorShowsItsDetailInWords(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'SOURCE');
        self::storedSet($id, 'error_detail', 'http:404');

        $html = self::page()->runsHtml(self::NOW);

        $this->assertStringContainsString('The source answered 404 Not Found.', $html);
        $this->assertStringContainsString('<code class="aa-import-code">SOURCE</code>', $html);
        $this->assertStringNotContainsString('class and line', $html, 'a status is no bug of the source code');

        self::storedSet($id, 'error_code', 'TRANSPORT');
        self::storedSet($id, 'error_detail', 'http:503');
        $this->assertStringContainsString('The source is not available now. The source answered 503 Service Unavailable.', self::page()->runsHtml(self::NOW));
    }

    // a run stored before the detail existed reads as it did
    public function test_runs_oldErrorWithoutDetailAsBefore(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'TRANSPORT');
        TableDb::$tables['object_text'] = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => !(($r['object_id'] === $id) && in_array($r['property'], ['error_detail', 'retry_code'], true))));

        $html = self::page()->runsHtml(self::NOW);

        $this->assertStringContainsString('The source could not be reached.', $html);
        $this->assertStringContainsString('What to do:', $html);
        $this->assertStringNotContainsString('The source answered', $html);
    }

    // a forged or damaged detail is not shown at all - the code and its words stay
    public function test_runs_detailNotOfTheSetIsNotShown(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'TRANSPORT');
        self::storedSet($id, 'error_detail', '<img src=x onerror=alert(1)>');

        $html = self::page()->runsHtml(self::NOW) . self::page()->overviewHtml(self::NOW);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('The source could not be reached.', $html);
        $this->assertSame('', \AA\Import\RunView::detailText('dns2'));
    }

    /** the next step fits what the source answered */
    #[DataProvider('sourceDetailSteps')]
    public function test_runs_nextStepOfARefusalByItsDetail(string $detail, bool $checkAddress): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'SOURCE');
        self::storedSet($id, 'error_detail', $detail);

        $html = self::page()->runsHtml(self::NOW);

        $this->assertSame($checkAddress, str_contains($html, 'Check the address of the source'));
        $this->assertStringNotContainsString('log of import failures', $html, 'an answer of the source is no bug to look up');
    }

    public static function sourceDetailSteps(): array
    {
        return [
            '404'       => ['http:404', true],
            '410'       => ['http:410', true],
            'address'   => ['address', true],
            'redirects' => ['redirects', true],
            'too large' => ['too_large', false],
            '100'       => ['http:100', false],
        ];
    }

    /** a source down between two tries: the open run says why, the URL with its token goes nowhere */
    public function test_waitingRun_showsWhyTheLastTryFailed(): void
    {
        self::definition();
        $id = self::start()->runId;
        \ServiceSource::$failStreams = new \AA\IO\SourceUnavailable('TRANSPORT', 'Host name not found: https://1.1.1.1/x?token=tok3n6161', 'dns');

        self::runOne();

        $html = self::page()->runsHtml(time()) . self::page()->overviewHtml(time());
        $this->assertStringContainsString('Last try failed:', $html);
        $this->assertStringNotContainsString('What to do:', $html, 'nothing to do yet - it tries again by itself');
        $this->assertStringContainsString('The address of the source could not be resolved (DNS).', $html);
        $this->assertStringNotContainsString('tok3n6161', $html);
        $this->assertStringNotContainsString('tok3n6161', json_encode(self::stored($id), JSON_THROW_ON_ERROR));
    }

    public function test_errorRun_refusalDetailWithoutItsUrl(): void
    {
        self::definition();
        $id = self::start()->runId;
        \ServiceSource::$failStreams = new \AA\IO\SourceRefused('SOURCE', 'HTTP 410 from https://1.1.1.1/x?token=tok3n6262', 'http:410');

        self::runOne();

        $html = self::page()->runsHtml(self::NOW) . self::page()->overviewHtml(self::NOW);
        $this->assertSame(Run::ERROR, self::stored($id)['state']);
        $this->assertStringContainsString('The source answered 410 Gone.', $html);
        $this->assertStringNotContainsString('tok3n6262', $html);
        $this->assertStringNotContainsString('tok3n6262', json_encode(self::stored($id), JSON_THROW_ON_ERROR));
    }

    public function test_post_startRefusedBySourceSaysItsDetail(): void
    {
        self::definition();
        \ServiceSource::$failStreams = new \AA\IO\SourceUnavailable('TRANSPORT', 'down at https://1.1.1.1/?token=tok3n6363', 'timeout');
        $page = self::page();

        $outcome = $page->handle(['action' => 'run', 'token' => self::token()], self::POST);
        $page->afterPost((array) $outcome);

        $this->assertSame(['refused', 'TRANSPORT', 'timeout'], $outcome);
        $html = self::page()->outcomeOnceHtml();
        $this->assertStringContainsString('The source did not answer in time.', $html);
        $this->assertStringNotContainsString('tok3n6363', $html);
    }

    public function test_runs_itemErrorsLinkToTheirList(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'counts', '{"read":3,"new":1,"updated":0,"skipped":0,"errors":2}');
        self::storedSet($id, 'errors', json_encode([['stream' => 's', 'job' => 'j', 'code' => 'VALIDATE', 'msg' => 'm']], JSON_THROW_ON_ERROR));
        $html = self::page()->runsHtml(self::NOW);
        $this->assertStringContainsString('<a href="#aa-import-errors-' . $id . '" data-aa-open>2</a>', $html);
        $this->assertStringContainsString('id="aa-import-errors-' . $id . '"', $html);
    }

    public function test_runs_startedShowsAge(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'started', (string) (self::NOW - 300));
        $this->assertStringContainsString('<small class="aa-import-since">5 min ago</small>', self::page()->runsHtml(self::NOW));
    }

    public function test_took_secondsBelowTenMinutes(): void
    {
        self::definition();
        $id = self::start()->runId;
        self::storedSet($id, 'started', (string) (self::NOW - 129));
        self::storedSet($id, 'state', Run::DONE);
        self::storedSet($id, 'finished', (string) self::NOW);
        $this->assertMatchesRegularExpression('~>2 min 9 s</td>~', self::page()->runsHtml(self::NOW));
        self::storedSet($id, 'finished', (string) (self::NOW - 84));
        $this->assertMatchesRegularExpression('~>45 s</td>~', self::page()->runsHtml(self::NOW));
    }

    // ------------------------------------------------------------ runs, pinned

    /** the open run is listed even when twenty newer ones are done */
    public function test_runs_openRunOutsideNewestListed(): void
    {
        self::definition();
        $open = self::start()->runId;
        self::storedSet($open, 'started', (string) (self::NOW - 100));
        self::storedSet($open, 'state', Run::ERROR);
        for ($i = 0; $i < 22; $i++) {
            self::storedSet(self::importRun(), 'started', (string) (self::NOW + $i));
        }

        $html = self::page()->runsHtml(time());

        $this->assertSame(20, substr_count($html, '<tr class="aa-import-run"'));
        $this->assertStringContainsString(date('Y-m-d H:i:s', self::NOW - 100), $html);
    }

    public function test_runs_dryRunWithoutSamples(): void
    {
        self::definition();
        $id = self::importRun();
        self::storedSet($id, 'dry', '1');

        $this->assertStringContainsString('The dry run found no item to store.', self::page()->runsHtml(time()));

        self::storedSet($id, 'state', Run::RUNNING);
        $this->assertStringNotContainsString('found no item', self::page()->runsHtml(time()), 'it may still find some');
    }

    public function test_overview_damagedLastCheck(): void
    {
        self::definition();
        self::check(new CheckResult(true, '', ''), self::NOW);
        self::storedSet(StreamState::idOf(self::DEF), 'check', '{broken');

        $this->assertStringContainsString('The stored result cannot be read.', self::page()->overviewHtml(time()));
    }

    /** the stored lists are data of the source: whatever they hold comes out as text */
    public function test_run_errorsAndSamplesNormalised(): void
    {
        $run = new Run([
            'errors'  => '[{"stream":["x"],"job":"j","code":7,"msg":null}, "loose", {"stream":"s"}]',
            'samples' => '[{"f":{"a":"b","c":["d"]},"g":3}, "loose", {"h":null}]',
        ]);

        $this->assertSame([['stream' => '', 'job' => 'j', 'code' => '7', 'msg' => ''], ['stream' => 's', 'job' => '', 'code' => '', 'msg' => '']], $run->errors());
        $this->assertSame([['f' => ['b', ''], 'g' => ['3']], ['h' => ['']]], $run->samples());
        $this->assertSame([], (new Run(['errors' => '{"a":{"stream":"s"}}', 'samples' => '{broken']))->errors());
        $this->assertSame([], (new Run(['samples' => '{"a":{"f":["x"]}}']))->samples(), 'an object is no list');
    }

    // ------------------------------------------------------------ helpers

    private static function page(): DetailPage
    {
        return DetailPage::forRequest(self::SLICE, self::DEF, AuthGetUid()) ?? throw new \LogicException('No page');
    }

    private static function token(): string
    {
        return FormToken::get('import-' . self::DEF);
    }

    private static function post(string $action): string
    {
        return self::act(['action' => $action]);
    }

    /** posts $post with the token, follows the redirect: the message the GET page shows
     *  @param array<string, mixed> $post */
    private static function act(array $post): string
    {
        $page = self::page();
        return self::shown($page, $page->handle($post + ['token' => self::token()], self::POST));
    }

    /** @param ?array{string, string} $outcome  @return string what the page after the redirect shows */
    private static function shown(DetailPage $page, ?array $outcome): string
    {
        self::assertNotNull($outcome, 'the POST did something');
        $page->afterPost($outcome);
        return $page->outcomeOnceHtml();
    }

    private static function start(string $id = self::DEF): \AA\Import\StartResult
    {
        $r = Service::start(Definition::fresh($id) ?? throw new \LogicException('No definition'), 'run', [], 'manual', AuthGetUid());
        self::assertSame('queued', $r->code);
        return $r;
    }

    /** the id of a new run of $mode */
    private static function startMode(string $mode): string
    {
        $r = Service::start(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'), $mode, [], 'manual', AuthGetUid());
        self::assertSame('queued', $r->code);
        return $r->runId;
    }

    /** a stream the page can offer */
    private static function knownStream(): void
    {
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '1']);
        $state->store();
    }

    private static function check(CheckResult $r, int $time): void
    {
        $state = StreamState::forDefinition(self::DEF) ?? StreamState::create(self::DEF);
        $state->recordCheck($r, $time);
        $state->store();
    }

    /** @param array<string, string> $props  a stored definition over ServiceSource */
    private static function definition(array $props = [], string $id = self::DEF, string $slice = self::SLICE): Definition
    {
        $def = new Definition();
        $def->setNew($id, $slice, 'prices ' . $id);
        $props += [
            'source'     => self::SOURCE,
            'mapping'    => self::NO_PARAM_MAPPING,
            'key_fields' => '["relation........"]',
            'store_mode' => 'update/insert',
            'enabled'    => '1',
        ];
        foreach ($props as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
        return $def;
    }

    /** @return string a done run of DEF, as Service starts it */
    private static function importRun(): string
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
        self::storedSet($id, 'state', Run::DONE);
        TableDb::$tables['toexecute'] = [];
        return $id;
    }

    /** @return list<string> the ids of the stored import runs */
    private static function runIds(): array
    {
        $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === Run::class));
        return array_values(array_map(static fn(array $r): string => (string) $r['object_id'], $rows));
    }

    /** @return array<string, array{disabled: bool, title: string, label: string, run: string}> the action buttons by their action */
    private static function buttons(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        $buttons = [];
        foreach ($doc->getElementsByTagName('form') as $form) {
            $action = '';
            $run    = '';
            foreach ($form->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') === 'action') {
                    $action = $input->getAttribute('value');
                }
                if ($input->getAttribute('name') === 'run') {
                    $run = $input->getAttribute('value');
                }
            }
            foreach ($form->getElementsByTagName('button') as $button) {
                $name = ($button->getAttribute('name') === 'action') ? $button->getAttribute('value') : $action;
                // the stream form has two buttons: the run of the stream decides
                $buttons[$name] ??= ['disabled' => $button->hasAttribute('disabled'), 'title' => $button->getAttribute('title'), 'label' => trim($button->textContent), 'run' => $run];
            }
        }
        return $buttons;
    }

    /** each disabled button of $actions says why, in its title and in text on the page
     *  @param array<string, array{disabled: bool, title: string, label: string, run: string}> $buttons
     *  @param list<string> $actions */
    private static function assertReasonShown(array $buttons, array $actions, string $html): void
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        foreach ($actions as $action) {
            self::assertTrue($buttons[$action]['disabled'], $action);
            self::assertNotSame('', $buttons[$action]['title'], "$action: the reason in its title");
            self::assertStringContainsString($buttons[$action]['title'], $text, "$action: the reason in text on the page");
        }
    }
}
