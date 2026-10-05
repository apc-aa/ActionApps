<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Api\JsonResponse;
use AA\Import\Definition;
use AA\Import\DetailLive;
use AA\Import\DetailPage;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Service;
use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use AA\Util\UiPrefs;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Api\Fixtures\JsonResponseReader;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/fixtures/RunSteps.php';
require_once __DIR__ . '/../Api/Fixtures/JsonResponseReader.php';

/**
 * What the script of the import detail page asks for while a run goes on: the progress of the run as
 * JSON and the sections that follow it as HTML, to a user who controls the run; a JSON 401 or 403 to
 * anyone else - never the login form - and nothing written, the session neither.
 * Each test in its own process, as DetailPageTest; the session tests open a real file session.
 */
#[RunTestsInSeparateProcesses]
final class ProgressEndpointTest extends TestCase
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
    /** the id of the real session of the session tests */
    private const SID = 'progresssessiontest0123456';

    private string $sessionDir = '';

    protected function setUp(): void
    {
        // logged in: the endpoint tells nobody (uid '') from a user
        $GLOBALS['auth'] = new class {
            public function getUid(): string
            {
                return 'uid-1';
            }

            public function getUname(): string
            {
                return 'tester';
            }
        };
        $this->stepsSetUp();
        session_id('test-session');
        $dir = __DIR__ . '/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys = ['s1', 's2', 's3'];
        \ServiceSource::$refuse     = null;
        \ServiceSource::$mapping    = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
        \AA::$module_id = self::SLICE;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        $_SESSION = [];
        \AA::$module_id = null;
        session_id('');
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
        unset($GLOBALS['auth']);
        if ($this->sessionDir !== '') {
            array_map('unlink', glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
    }

    // ------------------------------------------------------------ progress

    public function test_progress_json(): void
    {
        self::definition();
        $id = self::start();
        // the source's words are data in JSON - the script never puts them in as HTML
        self::storedSet($id, 'progress', '{"label":"s2 <b>x</b>","done":1,"total":3}');

        ['status' => $status, 'body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));

        $run = Run::get($id) ?? throw new \LogicException('No run');
        $this->assertSame(200, $status);
        // queued, no step at work yet: it waits
        $this->assertSame($run->progress(AuthGetUid()) + ['stuck' => false, 'waiting' => true, 'dead' => false, 'queued_until' => 0], $body);
        $this->assertSame([Run::RUNNING, 's2 <b>x</b>', 1, 3], [$body['state'], $body['label'], $body['done'], $body['total']]);

        // a finished run answers too: its state tells the script to stop
        self::storedSet($id, 'state', Run::DONE);
        ['status' => $status, 'body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([200, Run::DONE, false], [$status, $body['state'], $body['waiting']]);
    }

    /** between two tries the script learns why the last one failed - not error: null - and never the URL */
    public function test_progress_jsonWhileWaitingSaysWhy(): void
    {
        self::definition();
        $id = self::start();
        \ServiceSource::$failStreams = new \AA\IO\SourceUnavailable('TRANSPORT', 'Host name not found: https://1.1.1.1/x?token=tok3n7272', 'dns');

        self::runOne();

        ['status' => $status, 'body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([200, Run::RUNNING, true], [$status, $body['state'], $body['waiting']]);
        $this->assertSame(['code' => 'TRANSPORT', 'where' => '', 'detail' => 'dns'], $body['error']);
        $this->assertStringNotContainsString('tok3n7272', json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** what only time changes is in the JSON too, so the page learns a run hangs or its retry is due */
    public function test_progress_timeDependentFacts(): void
    {
        self::definition();
        $id = self::start();

        self::storedSet($id, 'next_at', (string) (Run::clock() + 600));
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([false, true], [$body['stuck'], $body['waiting']]);

        // the queue holds the step back, so the run waits: till its first row goes; a row the queue
        // gave up (priority 0) never runs
        $step = TableDb::rows('toexecute')[0];
        TableDb::$tables['toexecute'] = [
            ['id' => 997, 'execute_after' => Run::clock() + 1500] + $step,
            ['execute_after' => Run::clock() + 1200] + $step,
            ['id' => 999, 'execute_after' => Run::clock() + 60, 'priority' => 0] + $step,
        ];
        self::storedSet($id, 'next_at', (string) Run::clock());
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([true, Run::clock() + 1200], [$body['waiting'], $body['queued_until']]);

        // a step at work keeps its row leased for the whole claim - that is no waiting
        self::storedSet($id, 'lease', (string) (Run::clock() + 600));
        self::storedSet($id, 'lease_tok', 'working-step');
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([true, false, 0], [$body['running'], $body['waiting'], $body['queued_until']]);
        self::storedSet($id, 'lease', '');
        self::storedSet($id, 'lease_tok', '');

        // due now: 0, so the page sees the change and refreshes
        TableDb::$tables['toexecute'] = [['execute_after' => Run::clock() - 5] + $step];
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame(0, $body['queued_until']);

        self::storedSet($id, 'started', '1000');
        self::storedSet($id, 'next_at', '');
        TableDb::$tables['toexecute'] = [];
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([true, false], [$body['stuck'], $body['waiting']]);

        // its lease and the grace past it run out - the button to try now turns on by itself
        self::storedSet($id, 'started', (string) Run::clock());
        self::storedSet($id, 'lease', (string) (Run::clock() - Run::DEAD_GRACE));
        self::storedSet($id, 'lease_tok', 'dead-step');
        ['body' => $body] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame([false, false, true], [$body['stuck'], $body['waiting'], $body['dead']]);
    }

    /** a GET changes nothing - neither the tables nor the session, whatever it answers */
    public function test_progress_noWrite(): void
    {
        self::definition();
        $id = self::start();
        $_SESSION = ['kept' => 'as it was', 'aa_dataimport_outcome' => [self::DEF => ['queued', '']]];
        TableDb::$log = [];

        foreach ([AuthGetUid(), ''] as $uid) {
            foreach ([$id, 'ffffffffffffffffffffffffffffffff'] as $run) {
                DetailPage::progressJson(self::SLICE, self::DEF, $run, $uid);
            }
            DetailPage::sectionsAnswer(self::SLICE, self::DEF, $uid, time());
        }

        $this->assertSame([], TableDb::writes());
        $this->assertSame(['kept' => 'as it was', 'aa_dataimport_outcome' => [self::DEF => ['queued', '']]], $_SESSION, 'the outcome of an action stays for its page');
    }

    /** a run of another import, of another slice, unknown or malformed - one answer, so nothing is told */
    public function test_progress_runOfOtherDefinitionRefused(): void
    {
        self::definition();
        self::definition([], self::SIBLING);
        self::definition([], self::FOREIGN, self::OTHER);
        $own     = self::start();
        $sibling = self::runOf(self::SIBLING);
        $foreign = self::runOf(self::FOREIGN);
        $refused = ['status' => 403, 'body' => ['error' => 'FORBIDDEN']];

        $cases = [
            'run of another import of the slice' => [self::SLICE, self::DEF, $sibling],
            'run of an import of another slice'  => [self::SLICE, self::DEF, $foreign],
            'import of another slice'            => [self::SLICE, self::FOREIGN, $foreign],
            'slice without the feeding right'    => [self::OTHER, self::FOREIGN, $foreign],
            'unknown run'                        => [self::SLICE, self::DEF, 'ffffffffffffffffffffffffffffffff'],
            'upper case run'                     => [self::SLICE, self::DEF, strtoupper($own)],
            'run as an array'                    => [self::SLICE, self::DEF, [$own]],
            'no run'                             => [self::SLICE, self::DEF, null],
            'short run'                          => [self::SLICE, self::DEF, substr($own, 1)],
            'longer run'                         => [self::SLICE, self::DEF, $own . '0'],
            'unknown import'                     => [self::SLICE, 'ffffffffffffffffffffffffffffffff', $own],
            'import as an array'                 => [self::SLICE, [self::DEF], $own],
            'upper case slice'                   => [strtoupper(self::SLICE), self::DEF, $own],
            'longer slice'                       => [self::SLICE . '0', self::DEF, $own],
            'slice as an array'                  => [[self::SLICE], self::DEF, $own],
        ];
        // a malformed id is refused before it reaches the permission cache - not by a warning there
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
        try {
            foreach ($cases as $case => [$slice, $def, $run]) {
                $this->assertSame($refused, JsonResponseReader::read(DetailPage::progressJson($slice, $def, $run, AuthGetUid())), $case);
            }
        } finally {
            restore_error_handler();
        }

        // the run checks its own slice, the one of its snapshot - here a damaged record names another
        $snapshot = (Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'))->snapshot();
        self::storedSet($own, 'snapshot', json_encode(['slice' => self::OTHER] + $snapshot, JSON_THROW_ON_ERROR));
        $this->assertSame($refused, JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $own, AuthGetUid())), 'snapshot of another slice');
        self::storedSet($own, 'snapshot', json_encode($snapshot, JSON_THROW_ON_ERROR));

        // the run of the page itself, to a user who lost the feeding right of the slice
        self::perms(PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        $this->assertSame($refused, JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $own, AuthGetUid())), 'no feeding right');
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailPage::sectionsAnswer(self::SLICE, self::DEF, AuthGetUid(), time()))), 'no sections either');
    }

    /** an id of a request is exactly 32 lower-case hex */
    public function test_id_anchoredLowerCase(): void
    {
        $id = (string) (new \ReflectionClassConstant(DetailPage::class, 'ID'))->getValue();
        $this->assertSame(1, preg_match($id, self::DEF));
        foreach (['longer' => self::DEF . '0', 'prefixed' => '0' . self::DEF, 'upper case' => strtoupper(self::DEF), 'new line' => self::DEF . "\n", 'short' => substr(self::DEF, 1)] as $case => $bad) {
            $this->assertSame(0, preg_match($id, $bad), $case);
        }
    }

    // ------------------------------------------------------------ the live element and the sections

    /** the script polls only a running run, from what the page shows; the sections it replaces are marked */
    public function test_live_onlyWhileRunning(): void
    {
        self::definition();
        $this->assertStringNotContainsString('aa-import-live', self::page()->html(time()), 'no run');

        $id   = self::start();
        self::storedSet($id, 'progress', '{"label":"s1 </div><b>&amp;","done":0,"total":3}');
        $html = self::page()->html(time());
        $live = self::element($html, 'aa-import-live');

        $this->assertSame(['div', 'status', ''], [$live->nodeName, $live->getAttribute('role'), $live->textContent], 'empty until the script has something to say');
        $this->assertSame(get_admin_url('se_dataimport.php', ['change_id' => self::SLICE, 'id' => self::DEF, 'progress' => $id]), $live->getAttribute('data-aa-progress'));
        $this->assertSame(get_admin_url('se_dataimport.php', ['change_id' => self::SLICE, 'id' => self::DEF, 'sections' => '1']), $live->getAttribute('data-aa-sections'));
        $this->assertSame('This page is out of date – reload it.', $live->getAttribute('data-aa-stale'));
        ['body' => $now] = JsonResponseReader::read(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $this->assertSame($now, json_decode($live->getAttribute('data-aa-shown'), true), 'what the page was rendered with');
        $this->assertStringNotContainsString('</div><b>', $html);
        foreach (['overview', 'actions', 'runs'] as $section) {
            $this->assertMatchesRegularExpression('~<details id="aa-import-' . $section . '"[^>]* data-aa-live~', $html, $section);
        }

        self::storedSet($id, 'state', Run::ERROR);
        $this->assertStringNotContainsString('aa-import-live', self::page()->html(time()), 'stopped by an error - nothing moves until the user acts');
    }

    /** the details a user opens inside a section are found again in the fresh one */
    public function test_runs_nestedDetailsHaveStableIds(): void
    {
        self::definition();
        $id = self::start();
        self::storedSet($id, 'errors', '[{"stream":"s1","job":"a","code":"VALIDATE","msg":"bad"}]');
        self::storedSet($id, 'samples', '[{"headline........":["x"]}]');

        $html = self::page()->runsHtml(time());

        $this->assertStringContainsString('<details id="aa-import-errors-' . $id . '">', $html);
        $this->assertStringContainsString('<details id="aa-import-samples-' . $id . '">', $html);
    }

    /** the sections alone, as the page renders them - never the outcome of an action, which is its page's */
    public function test_sections_html(): void
    {
        self::definition();
        $id = self::start();
        $_SESSION['aa_dataimport_outcome'][self::DEF] = ['queued', ''];

        $html = DetailPage::sectionsAnswer(self::SLICE, self::DEF, AuthGetUid(), time());

        $this->assertIsString($html);
        $this->assertSame(self::page()->html(time()), $html);
        $this->assertStringContainsString('progress=' . $id, $html, 'the run it follows');
        $this->assertStringNotContainsString('The run is queued', $html);
        $this->assertSame(['queued', ''], $_SESSION['aa_dataimport_outcome'][self::DEF]);

        $this->assertSame(['status' => 401, 'body' => ['error' => 'LOGIN']], JsonResponseReader::read(self::json(DetailPage::sectionsAnswer(self::SLICE, self::DEF, '', time()))));
        foreach (['foreign' => [self::SLICE, self::FOREIGN], 'slice array' => [[self::SLICE], self::DEF], 'no import' => [self::SLICE, null]] as $case => [$slice, $def]) {
            $this->assertSame(['status' => 403, 'body' => ['error' => 'FORBIDDEN']], JsonResponseReader::read(self::json(DetailPage::sectionsAnswer($slice, $def, AuthGetUid(), time()))), $case);
        }
    }

    // ------------------------------------------------------------ the bootstrap: a real session

    /** the page left open overnight: its login expired, so it gets JSON it can act on - not the login form */
    public function test_progress_expiredSession401Json(): void
    {
        self::definition();
        $id     = self::start();
        $before = $this->session(time() - 60);

        $answer = DetailLive::answer(['change_id' => self::SLICE, 'id' => self::DEF, 'progress' => $id]);

        $this->assertSame(['status' => 401, 'body' => ['error' => 'LOGIN']], JsonResponseReader::read(self::json($answer)));
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'closed');
        $this->assertSame($before, $this->sessionFile(), 'not even the nobody login is stored');
    }

    /** a logged-in poll gets its answer and leaves the session as it found it: polling keeps no login alive */
    public function test_live_sessionNeverWritten(): void
    {
        self::definition();
        $id     = self::start();
        $before = $this->session(time() + 600);

        ['status' => $status, 'body' => $body] = JsonResponseReader::read(self::json(DetailLive::answer(['change_id' => self::SLICE, 'id' => self::DEF, 'progress' => $id])));
        $this->assertSame([200, Run::RUNNING], [$status, $body['state'] ?? '']);
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());

        $sections = DetailLive::answer(['change_id' => self::SLICE, 'id' => self::DEF, 'sections' => '1']);
        $this->assertIsString($sections);
        $this->assertStringContainsString('data-aa-live', $sections);
        $this->assertStringContainsString('name="token" value="' . \AA\Util\FormToken::get(DetailPage::tokenPurpose(self::DEF)) . '"', $sections, 'the forms of the fresh sections work');
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame($before, $this->sessionFile(), 'the refreshed login expiry is not stored');
    }

    /** a body that ever rendered as HTML - after a notice before the header - would run no markup */
    public function test_live_emitEscapesMarkup(): void
    {
        self::definition();
        $id = self::start();
        self::storedSet($id, 'progress', '{"label":"<script>x</script>&","done":0,"total":1}');

        ob_start();
        DetailLive::emit(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $body = (string) ob_get_clean();

        $this->assertStringNotContainsString('<', $body);
        $this->assertStringNotContainsString('&', $body);
        $this->assertSame('<script>x</script>&', json_decode($body, true)['label']);
    }

    /** an invalid byte from the source still gives JSON the script can read */
    public function test_live_emitSubstitutesInvalidUtf8(): void
    {
        self::definition();
        $id = self::start();
        self::storedSet($id, 'state', Run::ERROR);
        self::storedSet($id, 'error_code', 'TRANSPORT');
        self::storedSet($id, 'error_path', "s1:\xff");

        ob_start();
        DetailLive::emit(DetailPage::progressJson(self::SLICE, self::DEF, $id, AuthGetUid()));
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertIsArray($body);
        $this->assertSame("s1:\u{FFFD}", $body['error']['where']);
    }

    /** neither answer may be framed by another site - the sections carry the Run and End buttons */
    public function test_live_headersForbidFraming(): void
    {
        foreach (['html' => '<details></details>', 'json' => JsonResponse::error(403, 'FORBIDDEN')] as $case => $answer) {
            $headers = DetailLive::headers($answer);
            $this->assertContains('X-Frame-Options: SAMEORIGIN', $headers, $case);
            $this->assertContains("Content-Security-Policy: frame-ancestors 'self'", $headers, $case);
            $this->assertContains('X-Content-Type-Options: nosniff', $headers, $case);
        }
        $this->assertContains('Content-Type: text/html; charset=utf-8', DetailLive::headers('<details></details>'));
    }

    /** whatever init_page.php keeps from every page of a slice is refused here too */
    public function test_live_gateOfThePage(): void
    {
        self::definition();
        $id = self::start();
        $this->session(time() + 600);
        $progress = ['change_id' => self::SLICE, 'id' => self::DEF, 'progress' => $id];
        $sections = ['change_id' => self::SLICE, 'id' => self::DEF, 'sections' => '1'];
        $refused  = ['status' => 403, 'body' => ['error' => 'FORBIDDEN']];
        $this->assertSame(200, JsonResponseReader::read(self::json(DetailLive::answer($progress)))['status'], 'a member of the slice');

        // the feeding right of a role in the whole AA, without one in the slice: the page sends the user away
        // (an empty entry, as a missing one, only without CheckPerms' warning about the missing key)
        $GLOBALS['permission_to'] = ['aa' => [AA_ID => PS_FEEDING . PS_EDIT_ALL_ITEMS], 'slice' => [self::SLICE => '', self::OTHER => PS_EDIT_ALL_ITEMS]];
        $this->assertTrue(Definition::mayImportInto(AuthGetUid(), self::SLICE), 'the import rule alone would let it through');
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailLive::answer($progress))), 'progress');
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailLive::answer($sections))), 'sections');

        // a deleted slice is hidden from all but a superadmin
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        $slice = \AA_Module::getModule(self::SLICE);
        $this->assertInstanceOf(\Tests\Unit\Legacy\Fixtures\FakeSlice::class, $slice);
        $slice->deleted = true;
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailLive::answer($progress))), 'deleted, progress');
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailLive::answer($sections))), 'deleted, sections');

        // a superadmin opens every module, a deleted one too, without a role in it
        $GLOBALS['permission_to'] = ['aa' => [AA_ID => PS_FEEDING . PS_EDIT_ALL_ITEMS], 'slice' => [self::SLICE => '']];
        $GLOBALS['r_superuser']   = [AA_ID => true];
        $this->assertSame(200, JsonResponseReader::read(self::json(DetailLive::answer($progress)))['status'], 'superadmin, deleted');
        $this->assertIsString(DetailLive::answer($sections), 'superadmin, deleted, sections');

        // a role over all slices of the AA (GetUserSlices() says 'all'): every module that is not deleted
        unset($GLOBALS['r_superuser']);
        $slice->deleted = false;
        $GLOBALS['permission_to']['aa'][AA_ID] = PS_MANAGE_ALL_SLICES . PS_FEEDING . PS_EDIT_ALL_ITEMS;
        $this->assertSame('all', GetUserSlices());
        $this->assertSame(200, JsonResponseReader::read(self::json(DetailLive::answer($progress)))['status'], 'all slices');
        $slice->deleted = true;
        $this->assertSame($refused, JsonResponseReader::read(self::json(DetailLive::answer($progress))), 'all slices, deleted');
    }

    /** the sections render as on their page: its module in the links, its language - set only once allowed */
    public function test_sections_moduleAndLanguageOfThePage(): void
    {
        self::definition();
        self::definition([], self::FOREIGN, self::OTHER);
        $id = self::start();
        $this->session(time() + 600);
        $_COOKIE[UiPrefs::COOKIE_LANG] = 'cz-utf8';
        \AA::$module_id = null;

        DetailLive::answer(['change_id' => self::SLICE, 'id' => self::FOREIGN, 'sections' => '1']);
        $this->assertNull(\AA::$module_id, 'nothing set for a refused request');

        $html = DetailLive::answer(['change_id' => self::SLICE, 'id' => self::DEF, 'sections' => '1']);
        $this->assertIsString($html);
        $this->assertStringContainsString('>Nový<', $html, 'Status in the language of the slice page');
        \AA::$module_id = self::SLICE;
        $this->assertSame(self::links(self::page()->html(time())), self::links($html));
        $this->assertContains(get_admin_url('se_dataimport.php', ['change_id' => self::SLICE, 'id' => self::DEF, 'progress' => $id]), self::links($html));
    }

    // ------------------------------------------------------------ helpers

    /** @return list<string> the form actions and the URLs for the script */
    private static function links(string $html): array
    {
        preg_match_all('~(?:action|data-aa-progress|data-aa-sections)="([^"]*)"~', $html, $m);
        return array_map(static fn(string $u): string => html_entity_decode($u, ENT_QUOTES | ENT_HTML5), $m[1]);
    }

    private static function json(JsonResponse|string $answer): JsonResponse
    {
        return ($answer instanceof JsonResponse) ? $answer : throw new \LogicException('HTML, not JSON: ' . substr($answer, 0, 200));
    }

    private static function element(string $html, string $id): \DOMElement
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return $doc->getElementById($id) ?? throw new \LogicException("No #$id");
    }

    /** A stored file session of the test user whose login expires at $exp, as an admin page leaves it;
     *  the next session start opens it. @return string the stored session */
    private function session(int $exp): string
    {
        if (!defined('DEFAULT_LANG_INCLUDE')) {
            define('DEFAULT_LANG_INCLUDE', 'en_news_lang.php');
        }
        $this->sessionDir = sys_get_temp_dir() . '/aa-progress-' . getmypid();
        @mkdir($this->sessionDir);
        ini_set('session.save_path', $this->sessionDir);
        session_id('');
        session_name('AA_Session');
        session_id(self::SID);
        session_start();
        $auth = new \AA_Auth();
        (new \ReflectionProperty(\Auth::class, 'auth'))->setValue($auth, ['uid' => 'uid-1', 'uname' => 'tester', 'perm' => '', 'exp' => $exp, 'refresh' => 0]);
        $_SESSION = ['auth' => $auth];
        session_write_close();
        unset($GLOBALS['auth'], $GLOBALS['sess']);
        $_SESSION = [];
        session_id(self::SID);
        return $this->sessionFile();
    }

    private function sessionFile(): string
    {
        clearstatcache();
        return (string) file_get_contents($this->sessionDir . '/sess_' . self::SID);
    }

    private static function page(): DetailPage
    {
        return DetailPage::forRequest(self::SLICE, self::DEF, AuthGetUid()) ?? throw new \LogicException('No page');
    }

    /** @return string the id of a run of DEF, as a manual start queues it */
    private static function start(): string
    {
        $r = Service::start(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'), 'run', [], 'manual', AuthGetUid());
        self::assertSame('queued', $r->code);
        return $r->runId;
    }

    /** @return string the id of a running run of import $def, whatever its slice */
    private static function runOf(string $def): string
    {
        $definition = Definition::fresh($def) ?? throw new \LogicException('No definition');
        return (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => $def,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($definition->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
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
}
