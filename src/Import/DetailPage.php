<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Api\JsonResponse;
use AA\IO\SourceRefused;
use AA\Later\BatchFailed;
use AA\Later\Toexecute;
use AA\Util\FormToken;
use AA_Plannedtask;
use Throwable;

/**
 * The detail of one import definition (admin/se_dataimport.php): an overview, the actions, the
 * last runs and the state of the streams; the catalog is DetailCatalog's, the settings of the source
 * and the mapping are DetailConfig's. Every action is a POST with the token of this import, done
 * through Service and answered by a redirect to the GET page, which shows its outcome once - a
 * reload never repeats it. A GET only reads. Whatever came from the source - errors, samples, check
 * messages, stream keys and versions - is text: it goes out through safe() and never through {...}
 * or unalias.
 */
final class DetailPage
{
    /** the runs the page lists */
    public const RUNS_SHOWN = 20;

    /** streams or catalog entries listed on one page */
    public const PAGE_SIZE = 50;

    /** where the streams section starts, for a link back to it */
    public const STREAMS_ANCHOR = '#aa-import-streams';

    /** $_SESSION key of the outcome of the last action, per import, until its page shows it */
    private const FLASH = 'aa_dataimport_outcome';

    /** the actions that start a run: action => mode, options of Service::start() */
    private const STARTS = [
        'run'      => ['run', []],
        'changed'  => ['changed', []],
        'check'    => ['check', []],
        'dry'      => ['run', ['dry' => true]],
        'stream'   => ['run', []],
        'baseline' => ['baseline', []],
    ];

    /** an open run no step has worked on for long (BatchRun::isStuck()) - a page's own code, no state */
    private const HANGING = 'HANGING';

    /** an id of the request: 32 lower-case hex */
    public const ID = '/^[0-9a-f]{32}\z/';

    /** @var ?list<Run> newest first, read once per page */
    private ?array $runs = null;

    /** read once per page; false = not read yet */
    private StreamState|false|null $state = false;

    /** @var list<string>|null|false the mapping problems; null = the mapping cannot be read, false = not read yet */
    private array|false|null $problems = false;

    /** the source a refused save picked, so the page after the redirect shows its form again */
    private ?string $picked = null;

    /** @var array<string, string> further parameters of the page an action returns to */
    private array $back = [];

    /** the section of the page an action returns to */
    private string $anchor = '';

    private function __construct(private readonly Definition $def, private readonly string $uid) {}

    /**
     * The page of import $id of $slice for user $uid. null when there is no such import of the slice
     * - one of another slice is no different - or the user does not import into the slice.
     */
    public static function forRequest(string $slice, mixed $id, string $uid): ?self
    {
        if (!is_string($id) || (preg_match(self::ID, $id) !== 1) || !Definition::mayImportInto($uid, $slice)) {
            return null;
        }
        $def = Definition::byNameOrId($slice, $id);
        return ($def === null) ? null : new self($def, $uid);
    }

    /**
     * The progress of run $runId of import $id of $slice for the script of the page, as JSON:
     * liveFacts(). Not logged in = 401 - a page left open overnight must learn its session is gone,
     * not get the login form; a run the user may not see = 403, the same for a run of another import
     * or slice as for none. Reads only.
     * @param string $uid '' = nobody is logged in
     */
    public static function progressJson(mixed $slice, mixed $id, mixed $runId, string $uid): JsonResponse
    {
        if ($uid === '') {
            return self::loggedOut();
        }
        $page  = self::ofRequest($slice, $id, $uid);
        $run   = $page?->runOfThis($runId);
        $facts = (($page === null) || ($run === null)) ? null : $page->liveFacts($run, Run::clock());
        return ($facts === null) ? self::refused() : JsonResponse::ok($facts);
    }

    /**
     * The sections the script of the page replaces while a run goes on (html()), with the same rights
     * as the page; never the outcome of an action, which its own page shows once. Reads only.
     * @param string $uid '' = nobody is logged in
     * @param ?\Closure(string): void $prepare sets up the rendering for the slice, once the user may see it
     * @return JsonResponse|string the refusal, or the HTML
     */
    public static function sectionsAnswer(mixed $slice, mixed $id, string $uid, int $now, ?\Closure $prepare = null): JsonResponse|string
    {
        if ($uid === '') {
            return self::loggedOut();
        }
        $page = self::ofRequest($slice, $id, $uid);
        if ($page === null) {
            return self::refused();
        }
        if ($prepare !== null) {
            $prepare($page->slice());
        }
        return $page->html($now);
    }

    /** forRequest() of a slice id from the request */
    private static function ofRequest(mixed $slice, mixed $id, string $uid): ?self
    {
        return (is_string($slice) && (preg_match(self::ID, $slice) === 1)) ? self::forRequest($slice, $id, $uid) : null;
    }

    private static function loggedOut(): JsonResponse
    {
        return JsonResponse::error(401, 'LOGIN');
    }

    /** the one answer to whatever may not be seen */
    public static function refused(): JsonResponse
    {
        return JsonResponse::error(403, 'FORBIDDEN');
    }

    /** the token of the forms of import $id - a token of another import's page does not work here */
    public static function tokenPurpose(string $id): string
    {
        return 'import-' . $id;
    }

    public function definition(): Definition
    {
        return $this->def;
    }

    /**
     * Does the action a POST asks for. The caller then redirects (afterPost()) before the page reads
     * anything. A GET, or a POST without an action, does nothing.
     * @param array<mixed> $post   $_POST
     * @param array<mixed> $server $_SERVER
     * @return ?array{string, string} the outcome - a code of outcomeHtml() and a reason code; null = nothing asked
     */
    public function handle(array $post, array $server): ?array
    {
        if ((($server['REQUEST_METHOD'] ?? '') !== 'POST') || !isset($post['action'])) {
            return null;
        }
        $id    = $this->id();
        $token = $post['token'] ?? '';
        if (!FormToken::checkPost(self::tokenPurpose($id), is_string($token) ? $token : '', $server)) {
            return ['expired', ''];
        }
        if (!Definition::mayImportInto($this->uid, $this->slice())) {
            return ['refused', 'FORBIDDEN'];
        }
        $action = $post['action'];
        try {
            return match (true) {
                !is_string($action)          => ['no_action', ''],
                isset(self::STARTS[$action]) => $this->start($action, $post),
                $action === 'resume'         => $this->resume($post['run'] ?? null),
                $action === 'end'            => $this->end($post['run'] ?? null),
                $action === 'reset_mapping'  => $this->config()->resetMapping(),
                $action === 'settings'       => $this->changeSettings($post),
                $action === 'adopt'          => $this->adopt($post),
                default                      => ['no_action', ''],
            };
        } catch (Throwable $e) {
            Definition::logError($id, $e);
            return ['failed', ''];
        }
    }

    /** Keeps the outcome of handle() for the GET page, which shows it once.
     *  @param array{string, string} $outcome
     *  @return string the GET page to redirect to (303) */
    public function afterPost(array $outcome): string
    {
        $_SESSION[self::FLASH][$this->id()] = $outcome;
        return ($this->picked === null) ? self::urlOf($this->def, $this->back) . $this->anchor : self::urlOf($this->def, ['source' => $this->picked]);
    }

    /** @param array<mixed> $post @return array{string, string} */
    private function adopt(array $post): array
    {
        $page       = self::pageOf($post['catalog_page'] ?? null, PHP_INT_MAX);
        $this->back   = ($page > 1) ? ['catalog_page' => (string) $page] : [];
        $this->anchor = DetailCatalog::ANCHOR;
        return $this->catalog()->adopt($post['key'] ?? null);
    }

    /** @param array<mixed> $post @return array{string, string} */
    private function changeSettings(array $post): array
    {
        $outcome = $this->config()->changeSettings($post);
        $source       = $post['source'] ?? null;
        $this->picked = (($outcome[0] === 'settings_refused') && is_string($source) && ($source !== $this->def->sourceType())) ? $source : null;
        return $outcome;
    }

    /** the outcome of the last action on this import, once; '' = none */
    public function outcomeOnceHtml(): string
    {
        $outcome = $_SESSION[self::FLASH][$this->id()] ?? null;
        unset($_SESSION[self::FLASH][$this->id()]);
        return is_array($outcome) ? self::outcomeHtml((string) ($outcome[0] ?? ''), (string) ($outcome[1] ?? '')) : '';
    }

    /** the sections that follow a running run - the ones the script of the page replaces */
    public function html(int $now): string
    {
        return $this->liveHtml($now) . $this->overviewHtml($now) . $this->actionsHtml($now) . $this->runsHtml($now);
    }

    /** the catalog of the source: a section no run but a check changes, and only at its end */
    public function catalog(): DetailCatalog
    {
        return new DetailCatalog($this->def, $this->uid);
    }

    /** the settings of the source and the mapping: the sections no run changes */
    public function config(): DetailConfig
    {
        return new DetailConfig($this->def, $this->uid);
    }

    /** What the script of the page follows a running run by (javascript/dataimport.js): where to ask,
     *  what the page shows now, what to say when the page cannot follow any more - it fills the empty
     *  live region then. Nothing when no run goes on by itself. */
    private function liveHtml(int $now): string
    {
        $open = $this->openRun();
        if (($open === null) || ($open->state() !== Run::RUNNING) || (($facts = $this->liveFacts($open, $now)) === null)) {
            return '';
        }
        $ask = ['change_id' => $this->slice(), 'id' => $this->id()];
        return '<div id="aa-import-live" role="status"'
            . ' data-aa-progress="' . safe(get_admin_url('se_dataimport.php', $ask + ['progress' => (string) $open->getId()])) . '"'
            . ' data-aa-sections="' . safe(get_admin_url('se_dataimport.php', $ask + ['sections' => '1'])) . '"'
            . ' data-aa-shown="' . safe((string) json_encode($facts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE)) . '"'
            . ' data-aa-stale="' . safe(_m('This page is out of date – reload it.')) . '"></div>';
    }

    /** source, enabled, status, last run, the open run, next plan and the tasks, last check, mapping problems */
    public function overviewHtml(int $now): string
    {
        $last   = $this->runs()[0] ?? null;
        $open   = $this->openRun();
        $source = $this->def->sourceType();
        $rows   = [
            _m('Source')     => safe(Definition::sourceName($source)) . ($this->hasSource() ? '' : ' - ' . _m('this source is not available, so the import cannot run')),
            _m('Enabled')    => $this->def->isEnabled() ? _m('Yes - a planned task may start it') : _m('No - it runs only by hand'),
            _m('Status')     => safe(Definition::statusWord($last, $now)),
            _m('Last run')   => ($last === null) ? '--' : self::runSummaryHtml($last, $now),
            _m('Next plan')  => $this->nextPlanHtml($now),
            _m('Planned tasks') => $this->plannedTasksHtml($now),
            _m('Last check of the source') => $this->lastCheckHtml(),
        ];
        $html = self::factsHtml($rows);
        if ($open !== null) {
            $html .= $this->openRunHtml($open, $now);
        }
        $html .= $this->problemsHtml();
        return self::section('overview', _m('Overview'), $html);
    }

    /** the forms of the actions; a button that cannot be used now is disabled and says why */
    public function actionsHtml(int $now): string
    {
        $why   = $this->startBlocked($now);
        $html  = '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions">' . $this->hidden('');
        $html .= self::button(_m('Run'), $why, ['name' => 'action', 'value' => 'run', 'class' => 'aa-button-run'])
            . ' ' . self::button(_m('Run changed only'), $why, ['name' => 'action', 'value' => 'changed'])
            . ' ' . self::button(_m('Check source'), $why, ['name' => 'action', 'value' => 'check'])
            . ' ' . self::button(_m('Dry run'), $why, ['name' => 'action', 'value' => 'dry']);
        $html .= ($why === '') ? '' : '<p class="aa-import-why">' . safe($why) . '</p>';
        $html .= '</form>';
        $html .= '<p><small>' . _m('Run changed only skips the streams the source says did not change. Check source asks the source whether it works and stores nothing. A dry run reads up to 500 items and shows what it would store, without storing it.') . '</small></p>';
        $html .= $this->streamFormHtml($why);
        $html .= $this->baselineFormHtml($why);
        $html .= $this->controlFormHtml($now);
        return self::section('actions', _m('Actions'), $html);
    }

    /** the last runs, newest first, each with its item errors and what a dry run would store */
    public function runsHtml(int $now): string
    {
        $runs = array_slice($this->runs(), 0, self::RUNS_SHOWN);
        if (!$runs) {
            return self::section('runs', _m('Runs'), '<p class="aa-empty-note">' . _m('No run yet.') . '</p>');
        }
        $heads = [_m('Started'), _m('How'), _m('Status'), _m('Took'), _m('Counts'), _m('Error')];
        $html  = '<table class="aa-import-runs"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($runs as $run) {
            $p       = $this->progressOf($run);
            $started = (int) $run->getProperty('started');
            $html   .= '<tr class="aa-import-run">'
                . '<td>' . ($started ? date('Y-m-d H:i:s', $started) : '--') . '</td>'
                . '<td>' . safe(Definition::howItRan($run)) . '</td>'
                . '<td>' . safe(Definition::statusWord($run, $now)) . '</td>'
                . '<td>' . self::took($run, $now) . '</td>'
                . '<td>' . self::countsText($run) . '</td>'
                . '<td>' . self::errorHtml($p['error'] ?? null) . '</td>'
                . '</tr>';
            $samples = $run->samples();
            $more    = self::itemErrorsHtml($run->errors(), (string) $run->getId()) . (($samples || ($run->isDry() && ($run->state() === Run::DONE))) ? self::samplesHtml($samples, (string) $run->getId()) : '');
            if ($more !== '') {
                $html .= '<tr class="aa-import-run-more"><td colspan="' . count($heads) . '">' . $more . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        return self::section('runs', _m('Runs'), $html);
    }

    /** What the imports know of each stream: its data version, when the source was last asked whether
     *  it changed, when it was imported and by which run. Read from the stored state, whatever it holds,
     *  page by page; not refreshed while a run goes on - a source may have thousands of streams.
     *  @param mixed $page the page of the listing (?streams_page=), from the request */
    public function streamsHtml(mixed $page = null): string
    {
        $state   = $this->streamState();
        $streams = $state?->streams() ?? [];
        if (!$streams) {
            $note = ($state?->streamsDamaged()) ? _m('The stored state of the streams cannot be read.') : _m('No stream has been imported or checked yet.');
            return self::section('streams', _m('Streams'), '<p class="aa-empty-note">' . $note . '</p>', false);
        }
        $pages   = self::pagesOf(count($streams));
        $page    = self::pageOf($page, $pages);
        $streams = array_slice($streams, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);
        $started = [];
        foreach ($this->runs() as $run) {
            $started[(string) $run->getId()] = (int) $run->getProperty('started');
        }
        $heads = [_m('Stream'), _m('Data version'), _m('Checked for changes'), _m('Imported'), _m('By the run started')];
        $html  = '<table class="aa-import-streams"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($streams as $key => $stream) {
            $version = $stream['v'] ?? '';
            $run     = $stream['run'] ?? '';
            $html   .= '<tr><td>' . safe((string) $key) . '</td>'
                . '<td>' . ((is_string($version) && ($version !== '')) ? safe($version) : '--') . '</td>'
                . '<td>' . self::timeOf($stream['probed'] ?? null) . '</td>'
                . '<td>' . self::importedHtml($stream) . '</td>'
                // a run older than those listed is gone from the page
                . '<td>' . self::timeOf(is_string($run) ? ($started[$run] ?? null) : null) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>' . self::pagerHtml($this->def, 'streams_page', self::STREAMS_ANCHOR, _m('Pages of the streams'), $page, $pages);
        return self::section('streams', _m('Streams'), $html, false);
    }

    /** the page of a listing the request asks for: a whole number from 1, past the end the last */
    public static function pageOf(mixed $page, int $pages): int
    {
        $n = is_string($page) ? filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        return ($n === false) ? 1 : min($n, $pages);
    }

    /** how many pages $count rows of a listing take - one at least */
    public static function pagesOf(int $count): int
    {
        return max(1, intdiv($count + self::PAGE_SIZE - 1, self::PAGE_SIZE));
    }

    /** links to the page before and after of a listing of the page of $def, by its GET parameter $param */
    public static function pagerHtml(Definition $def, string $param, string $anchor, string $label, int $page, int $pages): string
    {
        if ($pages < 2) {
            return '';
        }
        $link = static fn(int $to, string $text): string => '<a href="' . safe(self::urlOf($def, [$param => (string) $to]) . $anchor) . '">' . $text . '</a>';
        return '<nav class="aa-import-pages" aria-label="' . safe($label) . '">'
            . (($page > 1) ? $link($page - 1, _m('Previous')) . ' ' : '')
            . _m('Page %1 of %2', [$page, $pages])
            . (($page < $pages) ? ' ' . $link($page + 1, _m('Next')) : '')
            . '</nav>';
    }

    // ------------------------------------------------------------ actions

    /** @param array<mixed> $post  @return array{string, string} */
    private function start(string $action, array $post): array
    {
        [$mode, $opt] = self::STARTS[$action];
        if ($action === 'stream') {
            $key = $post['stream_key'] ?? '';
            if (!is_string($key) || (preg_match(Stream::KEY, $key) !== 1)) {
                return ['no_stream', ''];
            }
            $opt = ['streams' => [$key]] + ((($post['dry'] ?? '') === '1') ? ['dry' => true] : []);
        }
        $r = Service::start($this->def, $mode, $opt, 'manual', $this->uid);
        return match ($r->code) {
            'queued'  => ['queued', ''],
            'busy'    => ['busy', $this->busyReason($r)],
            'unknown' => ['gone', ''],
            default   => ['refused', ($r->reason !== '') ? $r->reason : strtoupper($r->code)],
        };
    }

    /** @return array{string, string} */
    private function resume(mixed $runId): array
    {
        $run = $this->runOfThis($runId);
        if ($run === null) {
            return ['no_run', ''];
        }
        $r = Service::resume((string) $run->getId(), $this->uid);
        return match ($r->code) {
            'queued'  => ['resumed', ''],
            'busy'    => ($r->reason === 'LEASE') ? ['still_working', ''] : ['busy_resume', $r->reason],
            'unknown' => ['no_run', ''],
            default   => ['refused', $r->reason],
        };
    }

    /** @return array{string, string} */
    private function end(mixed $runId): array
    {
        $run = $this->runOfThis($runId);
        if ($run === null) {
            return ['no_run', ''];
        }
        try {
            Service::end((string) $run->getId(), $this->uid);
        } catch (BatchFailed $e) {
            return match ($e->reason) {
                'UNKNOWN'     => ['no_run', ''],
                'WRONG_STATE' => ['finished', ''],
                default       => ['refused', $e->reason],
            };
        }
        return ['ended', ''];
    }

    /** the run $runId of the request, when it is one of this import - never one of another */
    private function runOfThis(mixed $runId): ?Run
    {
        if (!is_string($runId) || (preg_match(self::ID, $runId) !== 1)) {
            return null;
        }
        $run = Run::get($runId);
        return (($run !== null) && ($run->definitionId() === $this->id())) ? $run : null;
    }

    /** a running run in the way that no step works on is told apart: Continue or End helps it */
    private function busyReason(StartResult $r): string
    {
        return (($r->reason === Run::RUNNING) && (Run::get($r->runId)?->isStuck(Run::clock()))) ? self::HANGING : $r->reason;
    }

    /** What an action did, by the outcome of handle(), for the GET page after it. Codes only - the
     *  source's own words may carry its data. */
    public static function outcomeHtml(string $code, string $reason): string
    {
        return match ($code) {
            'queued'      => MsgOk(_m('The run is queued. It goes on in the background; reload the page to see how far it got.')),
            'resumed'     => MsgOk(_m('The run goes on - its next step is queued.')),
            'ended'       => MsgOk(_m('The run is ended. What it imported stays.')),
            'expired'     => MsgErr(_m('The form has expired; reload the page and try again.')),
            'no_action'   => MsgErr(_m('No such action.')),
            'no_stream'   => MsgErr(_m('Choose a stream of the source.')),
            'busy'        => MsgErr(_m('Not started.') . ' ' . safe(self::busyText($reason))),
            'busy_resume' => MsgErr(safe(self::busyText($reason))),
            'still_working' => MsgErr(_m('Its last step may still be working. Try again in a few minutes.')),
            'gone'        => MsgErr(_m('This import no longer exists in this slice.')),
            'no_run'      => MsgErr(_m('No such run of this import.')),
            'finished'    => MsgErr(_m('The run is finished already.')),
            'refused'     => MsgErr(safe(self::reasonText($reason)) . DetailConfig::settingsPointer($reason, true)),
            'failed'      => MsgErr(_m('The action failed. The log of import failures has its cause.')),
            'adopted', 'no_entry', 'adopt_busy', 'adopt_refused' => DetailCatalog::outcomeHtml($code, $reason),
            default       => DetailConfig::outcomeHtml($code, $reason),
        };
    }

    /** why a run is in the way, by the reason of a busy StartResult, or HANGING */
    public static function busyText(string $reason): string
    {
        return match ($reason) {
            Run::ERROR    => _m('The last run stopped by an error. Continue it or End it first. A planned start ends it on its own; a start by hand does not.'),
            self::HANGING => _m('No step has worked on the last run for a long time. Continue it or End it first. A planned start ends it on its own; a start by hand does not.'),
            'LEASE'       => _m('The last step of the previous run is still finishing. Try again in a few minutes.'),
            'WRONG_STATE' => _m('Its next step is already on its way.'),
            default       => _m('The last run is still running.'),
        };
    }

    /** A refusal for the user, by its code. A code of the source is shown as it is - the source's
     *  own words may carry its data. Plain text. */
    public static function reasonText(string $code): string
    {
        return match ($code) {
            'FORBIDDEN'      => _m('Only a user who manages the feeding of the slice may run its imports.'),
            'NO_TRANSACTION' => _m('Imports cannot run here: their database tables (object_text, toexecute) do not support transactions (InnoDB).'),
            'NO_SOURCE'      => _m('The import source is not available.'),
            'DEFINITION'     => _m('The definition of the import cannot be used - its mapping does not fit the slice or cannot be read (DEFINITION).'),
            'SETTINGS'       => _m('A setting of the import source is not valid.'),
            'SLICE'          => _m('The settings name a slice whose feeding you do not manage.'),
            'STREAMS'        => _m('The source has no such stream now.'),
            'SOURCE'         => _m('The code of the import source failed (SOURCE). The log of import failures has its class and line.'),
            'WRONG_STATE'    => _m('The run is finished; it cannot go on.'),
            'OFF'            => _m('The import is switched off.'),
            'KEYS'           => _m('This import uses API keys, so only a superadmin may do this.'),
            'NO_VERSIONS'    => _m('The import source cannot tell the versions of its streams, so there is no state of it to record.'),
            default          => _m('The import source refused: %1', [$code]),
        };
    }

    // ------------------------------------------------------------ overview parts

    private static function runSummaryHtml(Run $run, int $now): string
    {
        $started = (int) $run->getProperty('started');
        return ($started ? date('Y-m-d H:i', $started) : '--')
            . ' <small>' . safe(Definition::howItRan($run)) . '</small><br>'
            . _m('took %1', [self::took($run, $now)]) . '; ' . self::countsText($run);
    }

    private function openRunHtml(Run $run, int $now): string
    {
        $p = $this->progressOf($run);
        if ($p === null) {
            return '';
        }
        $html = '<h3>' . _m('The open run') . '</h3><p>';
        if ($p['total'] > 0) {
            $html .= _m('Streams done: %1 of %2.', [$p['done'], $p['total']]) . ' ';
        }
        if ($p['label'] !== '') {
            $html .= _m('Now at: %1.', [safe($p['label'])]) . ' ';
        }
        $html .= _m('Last progress: %1.', [$p['moved'] ? date('Y-m-d H:i', $p['moved']) : '--']) . '</p>';
        // an error first: a step of it may still hold its lease
        $html .= match (true) {
            $run->state() === Run::ERROR => '<p>' . _m('Stopped by an error: %1', [self::errorHtml($p['error'])]) . '</p>'
                . '<p>' . _m('Nothing more is imported until you act or a planned task starts the import again (that ends this run).') . '</p>'
                . '<p>' . _m('Continue goes on from where it stopped once the cause is fixed; End stops it for good and keeps what was imported.') . '</p>',
            $run->isStuck($now)   => '<p>' . _m('No step has worked on it for a long time - check that cron runs the task queue and that its rounds are long enough for a step (TOEXECUTE_ALLOWED_TIME in config.php). Continue tries it again now; End stops it for good and keeps what was imported. A planned start of the import ends it and starts a new run.') . '</p>',
            $p['running']         => '<p>' . _m('A step is working now.') . '</p>',
            $run->isWaiting($now) => '<p>' . (($p['failures'] > 0)
                ? _m('Waiting to try again at %1 after %2 failed tries.', [date('H:i', $p['next_at']), $p['failures']])
                : _m('Waiting to try again at %1.', [date('H:i', $p['next_at'])])) . '</p>',
            $run->stepMayStillWork($now) => '<p>' . _m('Its last step may still be working.') . '</p>',
            $run->hasDeadStep($now) => '<p>' . _m('Its last step stopped without finishing. The task queue tries it again within %1 minutes; Try now does it at once.', [intdiv(Toexecute::CLAIM_LEASE, 60)]) . '</p>',
            default               => '<p>' . _m('Its next step waits in the task queue.') . '</p>',
        };
        return $html;
    }

    private function nextPlanHtml(int $now): string
    {
        // a planned start of a disabled import does nothing
        if (!$this->def->isEnabled()) {
            return _m('Off');
        }
        $next = $this->def->nextPlanned($now);
        return ($next === null) ? _m('Not planned - no planned task of the slice calls {import} with its name or id') : date('Y-m-d H:i', $next);
    }

    /** The planned tasks that start this import, each linked to its form, and Schedule - a link to the
     *  form of a new task filled in for this import, saved only when the user posts it. Both open the
     *  page of the planned tasks, which asks for the Forms permission; without it the user learns only
     *  how many tasks there are and when the first starts. */
    private function plannedTasksHtml(int $now): string
    {
        $tasks = $this->def->plannedTasks($now);
        if (!CheckPerms($this->uid, 'slice', $this->slice(), PS_FORMS)) {
            $why  = _m('Planned tasks need the Forms permission');
            $next = array_filter(array_column($tasks, 'next'));
            $html = match (true) {
                !$tasks => '<p>' . _m('No planned task of the slice calls this import.') . '</p>',
                !$next  => '<p>' . _m('Planned tasks: %1', [count($tasks)]) . '</p>',
                default => '<p>' . _m('Planned tasks: %1, next %2', [count($tasks), date('Y-m-d H:i', min($next))]) . '</p>',
            };
            return $html . htmltag('button', _m('Schedule'), ['type' => 'button', 'disabled' => 'disabled', 'title' => $why]) . self::whyHtml($why);
        }
        // the Next plan row says Off - a task must not look as if it started the import
        $off   = $this->def->isEnabled() ? '' : ' ' . _m('(import off)');
        $items = '';
        foreach ($tasks as $task) {
            $edit = new AA_Plannedtask();
            $edit->setNew($task['id'], $this->slice());
            $name   = a_href($edit->getAdminUrl(), safe(($task['name'] === '') ? $task['id'] : $task['name']));
            $modes  = implode(', ', array_unique(array_map(self::modeText(...), $task['modes'])));
            $next   = $task['next'] ? _m('next %1', [date('Y-m-d H:i', $task['next'])]) : _m('no time set');
            $items .= '<li>' . $name . ': ' . $modes . ', ' . $next . ', ' . ($task['queued'] ? _m('queued') : _m('not queued')) . $off . '</li>';
        }
        $html = ($items === '') ? '<p>' . _m('No planned task of the slice calls this import.') . '</p>' : '<ul>' . $items . '</ul>';
        return $html . a_href(self::scheduleUrl($this->def), _m('Schedule'), ['class' => 'aa-button-new']);
    }

    /** the form of a new planned task that runs import $def changed only, nightly */
    private static function scheduleUrl(Definition $def): string
    {
        $prefill = ['aa_name' => self::taskName($def), 'task' => '{import:' . $def->getId() . ':changed}', 'time' => '03:00'];
        return get_admin_url('se_tasks.php', ['module_id' => strtolower((string) $def->getOwnerId()), 'add' => '1', 'prefill' => $prefill]);
    }

    /** the name of the import as a task name may be - the form drops a name with markup or braces */
    private static function taskName(Definition $def): string
    {
        $name = trim((string) preg_replace('/[\s<>&"{}\x00-\x1f\x7f]+/u', ' ', mb_scrub((string) $def->getName(), 'UTF-8')));
        $name = trim(mb_substr($name, 0, 100));
        return ($name === '') ? _m('Import %1', [$def->getId()]) : $name;
    }

    /** the mode of an {import} call in the words of the buttons that run the same */
    private static function modeText(string $mode): string
    {
        return match ($mode) {
            'run'      => _m('Run'),
            'changed'  => _m('Run changed only'),
            'check'    => _m('Check source'),
            default    => str_ends_with($mode, '{') ? _m('mode set by an expression') : _m('unknown mode %1', [safe($mode)]),
        };
    }

    private function lastCheckHtml(): string
    {
        $state = $this->streamState();
        $check = $state?->lastCheck();
        if ($check === null) {
            return ($state?->checkDamaged()) ? _m('The stored result cannot be read.') : _m('Never checked');
        }
        $html = ($check['time'] ? date('Y-m-d H:i', $check['time']) : '--') . ' ' . ($check['ok'] ? _m('Passed') : _m('Failed'));
        if ($check['code'] !== '') {
            $html .= ' <code>' . safe($check['code']) . '</code>';
        }
        if ($check['msg'] !== '') {
            $html .= ': ' . safe($check['msg']);
        }
        return $html;
    }

    /** the problems of the mapping in words - each refuses every start (DEFINITION) */
    private function problemsHtml(): string
    {
        $problems = $this->problems();
        if ($problems === null) {
            return '<div class="aa-err">' . _m('The mapping of the import cannot be read; no run starts until it is set again.') . '</div>';
        }
        if (!$problems) {
            return '';
        }
        $items = array_map(static fn(string $code): string => '<li>' . safe(Mapping::problemText($code)) . ' <code>' . safe($code) . '</code></li>', $problems);
        return '<div class="aa-err">' . _m('The mapping does not fit the slice; no run starts until it is fixed:') . '<ul>' . implode('', $items) . '</ul></div>';
    }

    // ------------------------------------------------------------ action parts

    /** why a new run cannot start now - what Service would refuse it with; '' = it can */
    private function startBlocked(int $now): string
    {
        $open = $this->openRun();
        return match (true) {
            $open !== null                      => self::busyText($open->isStuck($now) ? self::HANGING : $open->state()),
            (bool) Run::leasedOf($this->id(), $now) => self::busyText('LEASE'),
            !$this->hasSource()                 => self::reasonText('NO_SOURCE'),
            $this->problems() !== []            => self::reasonText('DEFINITION'),
            default                             => '',
        };
    }

    /** One stream, chosen from those the page knows without asking the source: the stream state and
     *  the last runs. */
    private function streamFormHtml(string $why): string
    {
        $keys = array_map('strval', array_keys($this->streamState()?->streams() ?? []));
        foreach ($this->runs() as $run) {
            $stored = json_decode((string) $run->getProperty('stream_keys'), true);
            foreach (is_array($stored) ? $stored : [] as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys, static fn(string $k): bool => preg_match(Stream::KEY, $k) === 1)));
        sort($keys, SORT_NATURAL);
        $none = $keys ? '' : _m('No stream is known yet - run the import or check the source first.');
        $off  = ($why !== '') ? $why : $none;
        $options = implode('', array_map(static fn(string $k): string => '<option value="' . safe($k) . '">' . safe($k) . '</option>', $keys));
        return '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions">' . $this->hidden('stream')
            . '<label>' . _m('One stream') . ' <select name="stream_key"' . ($keys ? '' : ' disabled') . '>' . $options . '</select></label> '
            . self::button(_m('Run the stream'), $off, ['name' => 'dry', 'value' => '0'])
            . ' ' . self::button(_m('Dry run of the stream'), $off, ['name' => 'dry', 'value' => '1'])
            // a run in the way is said above the form already
            . ((($why === '') && ($none !== '')) ? '<p class="aa-import-why">' . safe($none) . '</p>' : '')
            . '</form>';
    }

    /** Record the current state of the source: for whoever may manage the import, of a source that
     *  tells versions, as Service decides - the reason a run is in the way, $why, is said above the
     *  forms already */
    private function baselineFormHtml(string $why): string
    {
        $refused = ($why === '') ? ($this->def->manageRefusal($this->uid) ?? ($this->sourceProbes() ? null : 'NO_VERSIONS')) : null;
        $off     = ($refused === null) ? $why : self::reasonText($refused);
        return '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions" data-aa-confirm="' . safe(_m('Record the versions the source has now, as if they were imported? Streams that do not change afterwards will not be imported.')) . '">' . $this->hidden('baseline')
            . self::button(_m('Record the current state of the source'), $off, [])
            . (($refused === null) ? '' : self::whyHtml($off))
            . '<p><small>' . _m('Record the current state of the source stores the version of each stream the source has now, without importing anything, so that Run changed only then imports just what changes after that. Use it only when the data of the source already are in the slice.') . '</small></p>'
            . '</form>';
    }

    /** whether the source can tell the versions of its streams - by its class, the page does not create it */
    private function sourceProbes(): bool
    {
        $class = Registry::sources()[$this->def->sourceType()] ?? null;
        return ($class !== null) && is_subclass_of($class, Probeable::class);
    }

    /** Continue and End of the open run */
    private function controlFormHtml(int $now): string
    {
        $open = $this->openRun();
        if ($open === null) {
            $whyGo  = $whyEnd = _m('No run is open.');
            $resume = _m('Continue');
        } else {
            $whyEnd = '';
            $whyGo  = match (true) {
                $open->mayResume($now)    => '',
                $open->hasLiveLease($now) => _m('Its step is working now.'),
                $open->stepMayStillWork($now) => _m('Its last step may still be working.'),
                default                   => _m('Its next step waits in the task queue.'),
            };
            $resume = ($open->isWaiting($now) || $open->continueKeepsCount($now)) ? _m('Try now') : _m('Continue');
        }
        $run = ($open === null) ? '' : self::hiddenInput('run', (string) $open->getId());
        $why = self::whyHtml(...);
        return '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions">' . $this->hidden('resume') . $run
            . self::button($resume, $whyGo, ['class' => 'aa-button-run'])
            // one reason for both when there is no run
            . (($open === null) ? '' : $why($whyGo))
            . '</form>'
            . '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions" data-aa-confirm="' . safe(_m('End the run for good? What it imported stays; the next run starts from the beginning.')) . '">' . $this->hidden('end') . $run
            . self::button(_m('End'), $whyEnd, [])
            . $why($whyEnd)
            . '</form>';
    }

    /** the token, and the action when the form has one */
    private function hidden(string $action): string
    {
        return self::tokenFields($this->id(), $action);
    }

    /** the token of the forms of import $id, and the action when the form has one */
    public static function tokenFields(string $id, string $action): string
    {
        return FormToken::field(self::tokenPurpose($id)) . (($action === '') ? '' : self::hiddenInput('action', $action));
    }

    public static function hiddenInput(string $name, string $value): string
    {
        return '<input type="hidden" name="' . safe($name) . '" value="' . safe($value) . '">';
    }

    /** the reason next to a disabled button */
    public static function whyHtml(string $why): string
    {
        return ($why === '') ? '' : ' <span class="aa-import-why">' . safe($why) . '</span>';
    }

    /** a table of label => value rows; the values are HTML
     *  @param array<string, string> $rows */
    public static function factsHtml(array $rows): string
    {
        $html = '<table class="aa-import-facts"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th scope="row">' . $label . '</th><td>' . $value . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** a submit button; one that cannot be used now is disabled and says why in its title
     *  @param array<string, string> $attr */
    public static function button(string $label, string $why, array $attr): string
    {
        $attr = ['type' => 'submit'] + $attr;
        if ($why !== '') {
            $attr['disabled'] = 'disabled';
            $attr['title']    = $why;
        }
        return htmltag('button', safe($label), $attr);
    }

    // ------------------------------------------------------------ runs parts

    /** the ids of the details let a refreshed page keep them open
     *  @param list<array{stream: string, job: string, code: string, msg: string}> $errors */
    private static function itemErrorsHtml(array $errors, string $runId): string
    {
        if (!$errors) {
            return '';
        }
        $rows = '';
        foreach ($errors as $e) {
            $rows .= '<tr><td>' . safe($e['stream']) . '</td><td>' . safe($e['job']) . '</td><td><code>' . safe($e['code']) . '</code></td><td>' . safe($e['msg']) . '</td></tr>';
        }
        return '<details id="aa-import-errors-' . safe($runId) . '"><summary>' . _m('The first item errors (%1)', [count($errors)]) . '</summary>'
            . '<table><thead><tr><th scope="col">' . _m('Stream') . '</th><th scope="col">' . _m('Job') . '</th><th scope="col">' . _m('Code') . '</th><th scope="col">' . _m('Message') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></details>';
    }

    /** @param list<array<string, list<string>>> $samples */
    private static function samplesHtml(array $samples, string $runId): string
    {
        if (!$samples) {
            return '<p><small>' . _m('The dry run found no item to store.') . '</small></p>';
        }
        $html = '<details id="aa-import-samples-' . safe($runId) . '"><summary>' . _m('What the dry run would store - the first %1 items', [count($samples)]) . '</summary>';
        foreach ($samples as $item) {
            $html .= '<table class="aa-import-sample"><tbody>';
            foreach ($item as $field => $values) {
                $html .= '<tr><th scope="row">' . safe($field) . '</th><td>' . implode('<br>', array_map(safe(...), $values)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</details>';
    }

    /** @param ?array{code: string, where: string} $error */
    private static function errorHtml(?array $error): string
    {
        if ($error === null) {
            return '';
        }
        return '<code>' . safe($error['code']) . '</code>' . (($error['where'] === '') ? '' : ' <small>' . safe($error['where']) . '</small>');
    }

    private static function countsText(Run $run): string
    {
        $counts = $run->counts();
        if ($run->mode() === 'baseline') {
            return _m('streams recorded %1, without a version %2', [$counts['streams'], $counts['unversioned']]);
        }
        return _m('read %1, new %2, updated %3, skipped %4, item errors %5', [$counts['read'], $counts['new'], $counts['updated'], $counts['skipped'], $counts['errors']]);
    }

    /** when the stream was imported, or recorded by a baseline when that came later
     *  @param array<string, string|int> $stream */
    private static function importedHtml(array $stream): string
    {
        $imported = $stream['imported'] ?? 0;
        $baseline = $stream['baseline'] ?? 0;
        if (is_int($baseline) && ($baseline > 0) && (!is_int($imported) || ($baseline > $imported))) {
            return _m('Baseline %1', [self::timeOf($baseline)]);
        }
        return self::timeOf($imported ?: null);
    }

    private static function timeOf(mixed $time): string
    {
        return (is_int($time) && ($time > 0)) ? date('Y-m-d H:i', $time) : '--';
    }

    /** how long the run took: till it finished, till its last progress when it stopped by an error,
     *  till now while it runs */
    private static function took(Run $run, int $now): string
    {
        $started = (int) $run->getProperty('started');
        if (!$started) {
            return '--';
        }
        $end = (int) $run->getProperty('finished') ?: (($run->state() === Run::ERROR) ? ((int) $run->getProperty('moved') ?: $started) : $now);
        $seconds = max(0, $end - $started);
        return ($seconds < 60) ? _m('%1 s', [$seconds]) : (($seconds < 7200) ? _m('%1 min', [intdiv($seconds, 60)]) : _m('%1 h', [intdiv($seconds, 3600)]));
    }

    // ------------------------------------------------------------ reading

    /** @return list<Run> the runs of the import, newest first - the open one always among them. Each
     *                    run is read on its own (BatchRun::get()); 20 small reads are fine here. */
    private function runs(): array
    {
        if ($this->runs === null) {
            $runs = Run::ofSubject($this->id(), self::RUNS_SHOWN);
            $ids  = array_map(static fn(Run $r): string => (string) $r->getId(), $runs);
            foreach (Run::unfinished($this->id()) as $open) {
                if (!in_array((string) $open->getId(), $ids, true)) {
                    array_unshift($runs, $open);
                }
            }
            $this->runs = $runs;
        }
        return $this->runs;
    }

    /** the unfinished run - Service keeps at most one */
    private function openRun(): ?Run
    {
        foreach ($this->runs() as $run) {
            if (in_array($run->state(), [Run::RUNNING, Run::ERROR], true)) {
                return $run;
            }
        }
        return null;
    }

    private function streamState(): ?StreamState
    {
        if ($this->state === false) {
            $this->state = StreamState::forDefinition($this->id());
        }
        return $this->state;
    }

    /** @return ?list<string> the problems of the mapping in the slice; null = it cannot be read */
    private function problems(): ?array
    {
        if ($this->problems === false) {
            try {
                $this->problems = $this->def->mapping()->problems($this->slice());
            } catch (SourceRefused) {
                $this->problems = null;
            }
        }
        return $this->problems;
    }

    private function hasSource(): bool
    {
        return isset(Registry::sources()[$this->def->sourceType()]);
    }

    /** @return ?array{role: string, state: string, label: string, done: int, total: int, moved: int, started: int,
     *                 running: bool, next_at: int, failures: int, counts: array, warnings: array,
     *                 error: ?array{code: string, where: string}, note: string, warning: string, extra: array} */
    private function progressOf(Run $run): ?array
    {
        try {
            return $run->progress($this->uid);
        } catch (BatchFailed) {
            return null;
        }
    }

    /** progressOf() and what only time changes - a run that hangs, a retry that is due - so the
     *  script learns of those too
     *  @return ?array<string, mixed> */
    private function liveFacts(Run $run, int $now): ?array
    {
        $progress = $this->progressOf($run);
        return ($progress === null) ? null : $progress + ['stuck' => $run->isStuck($now), 'waiting' => $run->isWaiting($now), 'dead' => $run->hasDeadStep($now)];
    }

    private function url(): string
    {
        return self::urlOf($this->def);
    }

    /** the page of definition $def
     *  @param array<string, string> $more further parameters of the page */
    public static function urlOf(Definition $def, array $more = []): string
    {
        return get_admin_url('se_dataimport.php', ['change_id' => strtolower((string) $def->getOwnerId()), 'id' => (string) $def->getId()] + $more);
    }

    private function id(): string
    {
        return (string) $this->def->getId();
    }

    private function slice(): string
    {
        return strtolower((string) $this->def->getOwnerId());
    }

    /** $live: the script replaces the section by the one of a fresh page while a run moves */
    public static function section(string $name, string $title, string $body, bool $live = true): string
    {
        return '<details id="aa-import-' . $name . '" class="aa-page-section" open' . ($live ? ' data-aa-live' : '') . '><summary><h2 class="aa-tab-title">' . $title . '</h2></summary>' . $body . '</details>';
    }
}
