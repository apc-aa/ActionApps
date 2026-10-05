<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Api\JsonResponse;
use AA\Http\TransportException;
use AA\IO\SourceFailure;
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

    /** the newest import (Run::storesItems()), read once per page; false = not read yet */
    private Run|false|null $import = false;

    /** read once per page; false = not read yet */
    private StreamState|false|null $state = false;

    /** @var list<string>|null|false the mapping problems; null = the mapping cannot be read, false = not read yet */
    private array|false|null $problems = false;

    /** @var array<string, int> run id => Run::queuedUntil(), read once per page */
    private array $queuedUntil = [];

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
     * @return ?array{0: string, 1: string, 2?: string} the outcome - a code of outcomeHtml(), a reason code and maybe the detail of a
     *         refusal of the source; null = nothing asked
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
     *  @param array{0: string, 1: string, 2?: string} $outcome
     *  @return string the GET page to redirect to (303) */
    public function afterPost(array $outcome): string
    {
        $_SESSION[self::FLASH][$this->id()] = $outcome;
        return ($this->picked === null) ? self::urlOf($this->def, $this->back) . $this->anchor : self::urlOf($this->def, ['source' => $this->picked]);
    }

    /** @param array<mixed> $post @return array{0: string, 1: string, 2?: string} */
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
        return is_array($outcome) ? self::outcomeHtml((string) ($outcome[0] ?? ''), (string) ($outcome[1] ?? ''), (string) ($outcome[2] ?? '')) : '';
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

    /** the verdict and any mapping problems at the top, the outcomes of the latest runs, then the
     *  last import with its counts, the next start with its tasks, the last check of the source; the
     *  open run last */
    public function overviewHtml(int $now): string
    {
        $runs   = $this->runs();
        $open   = $this->openRun();
        $health = RunView::health($this->def->isEnabled(), $this->hasSource(), $this->problems() === [], $runs[0] ?? null, $this->lastImport(), $now);
        $html   = $this->healthHtml($health) . $this->problemsHtml() . self::ribbonHtml($runs, $now)
            . '<div class="aa-import-glance">'
            . '<div class="aa-import-last"><h3>' . _m('Last import') . '</h3>' . $this->lastImportHtml($runs, $now) . '</div>'
            . '<div class="aa-import-next"><h3>' . _m('Next start') . '</h3>' . $this->nextPlanHtml($now) . $this->plannedTasksHtml($now) . '</div>'
            . '<div class="aa-import-check"><h3>' . _m('Last check of the source') . '</h3>' . $this->lastCheckHtml($runs, $now) . '</div>'
            . '</div>';
        if ($open !== null) {
            $html .= $this->openRunHtml($open, $now);
        }
        // one child: the section styles each direct child as its own padded block
        return self::section('overview', _m('Overview'), '<div>' . $html . '</div>');
    }

    /** the forms of the actions; a button that cannot be used now is disabled and says why.
     *  Run is the one loud button, the rest is quieter, the rare ones are one click away. */
    public function actionsHtml(int $now): string
    {
        $why   = $this->startBlocked($now);
        $html  = '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions aa-import-start">' . $this->hidden('');
        $html .= '<div class="aa-import-acts">'
            . self::actHtml(self::button(_m('Run'), $why, ['name' => 'action', 'value' => 'run', 'class' => 'aa-button-run aa-import-primary']), _m('Imports every stream.'))
            . self::actHtml(self::button(_m('Run changed only'), $why, ['name' => 'action', 'value' => 'changed', 'class' => 'aa-import-quiet']), _m('Skips the streams the source says did not change.'))
            . self::actHtml(self::button(_m('Check source'), $why, ['name' => 'action', 'value' => 'check', 'class' => 'aa-import-quiet']), _m('Asks the source whether it works. Stores nothing.'))
            . self::actHtml(self::button(_m('Dry run'), $why, ['name' => 'action', 'value' => 'dry', 'class' => 'aa-import-quiet']), _m('Reads up to 500 items and shows what it would store. Stores nothing.'))
            . '</div>';
        $html .= ($why === '') ? '' : '<p class="aa-import-why">' . safe($why) . '</p>';
        $html .= '</form>';
        $html .= $this->controlFormHtml($now);
        // the id lets a refreshed page keep it open
        $html .= '<details id="aa-import-more" class="aa-import-more"><summary>' . _m('More actions') . '</summary>'
            . $this->streamFormHtml($why)
            . $this->baselineFormHtml($why)
            . '</details>';
        return self::section('actions', _m('Actions'), $html);
    }

    /** a button with its one-line hint under it */
    private static function actHtml(string $button, string $hint): string
    {
        return '<div class="aa-import-act">' . $button . '<small>' . safe($hint) . '</small></div>';
    }

    /** the last runs, newest first, each with its item errors and what a dry run would store */
    public function runsHtml(int $now): string
    {
        $runs = array_slice($this->runs(), 0, self::RUNS_SHOWN);
        if (!$runs) {
            return self::section('runs', _m('Runs'), '<p class="aa-empty-note">' . _m('No run yet.') . '</p>');
        }
        $heads = [_m('Outcome'), _m('Started'), _m('Took'), _m('Read'), _m('New'), _m('Updated'), _m('Skipped'), _m('Item errors')];
        $html  = '<table class="aa-import-runs"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($runs as $run) {
            $p       = $this->progressOf($run);
            $started = (int) $run->getProperty('started');
            $outcome = RunView::outcome($run, $now);
            $errors  = $run->errors();
            $worked  = self::worked($run, $now);
            $html   .= '<tr class="aa-import-run" data-outcome="' . $outcome['kind'] . '">'
                . '<th scope="row">' . RunView::markHtml($outcome['kind']) . ' <strong>' . safe($outcome['word']) . '</strong>'
                . (($outcome['then'] === '') ? '' : '<small class="aa-import-then">' . safe($outcome['then']) . '</small>')
                . '<small>' . safe(self::kindOfRun($run)) . '</small></th>'
                . '<td data-label="' . safe($heads[1]) . '">' . ($started ? date('Y-m-d H:i:s', $started) . self::ageHtml($started, $now) : '--') . '</td>'
                . '<td data-label="' . safe($heads[2]) . '">' . self::took($run, $now) . (($worked === '') ? '' : '<small>' . _m('working %1', [$worked]) . '</small>') . '</td>'
                . self::countCellsHtml($run, $now, array_slice($heads, 3), $errors ? (string) $run->getId() : '')
                . '</tr>';
            $samples = $run->samples();
            $more    = self::runErrorHtml($p['error'] ?? null, ($p['role'] ?? '') === 'active') . self::itemErrorsHtml($errors, (string) $run->getId()) . self::emptyKeysHtml($run->emptyKeys(), (string) $run->getId()) . (($samples || ($run->isDry() && ($run->state() === Run::DONE))) ? self::samplesHtml($samples, (string) $run->getId()) : '');
            if ($more !== '') {
                $html .= '<tr class="aa-import-run-more"><td colspan="' . count($heads) . '">' . $more . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        return self::section('runs', _m('Runs'), $html);
    }

    /** What the imports know of each stream: a verdict, the volume of the run that imported it, when it was
     *  imported and the source asked, and its data version. Read from the stored state, whatever it holds,
     *  page by page; not refreshed while a run goes on - a source may have thousands of streams.
     *  @param mixed $page the page of the listing (?streams_page=), from the request */
    public function streamsHtml(mixed $page = null, ?int $now = null): string
    {
        $now     = $now ?? Run::clock();
        $state   = $this->streamState();
        $streams = $state?->streams() ?? [];
        if (!$streams) {
            $html = ($state?->streamsDamaged())
                ? '<p class="aa-empty-note">' . _m('The stored state of the streams cannot be read.') . '</p>'
                : '<p class="aa-import-empty">' . _m('No stream has been imported or checked yet. %1 or %2 (Actions, above) fills this list.', ['<a href="#aa-import-actions">' . _m('Run') . '</a>', '<a href="#aa-import-actions">' . _m('Check source') . '</a>']) . '</p>';
            return self::section('streams', _m('Streams'), $html, false);
        }
        $pages   = self::pagesOf(count($streams));
        $page    = self::pageOf($page, $pages);
        $streams = array_slice($streams, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);
        $runs    = [];
        foreach ($this->runs() as $run) {
            $runs[(string) $run->getId()] = $run;
        }
        $heads = [_m('Stream'), _m('State'), _m('Records'), _m('Last recorded'), _m('Source checked'), _m('Data version')];
        $html  = '<table class="aa-import-streams"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($streams as $key => $stream) {
            $key  = (string) $key;
            // a run older than those listed is gone from the page
            $run  = is_string($stream['run'] ?? null) ? ($runs[$stream['run']] ?? null) : null;
            $since = self::timeOf($run ? (int) $run->getProperty('started') : null);
            [$kind, $mark, $word, $reason, $time] = self::streamKind($stream, $key, $run);
            $html .= '<tr><th scope="row" data-label="' . safe($heads[0]) . '">' . safe($key) . '</th>'
                . '<td data-label="' . safe($heads[1]) . '"><span class="aa-import-state aa-import-state-' . $kind . '">' . RunView::markHtml($mark) . $word . '</span>'
                . '<small class="aa-import-reason">' . $reason . '</small></td>'
                . '<td data-label="' . safe($heads[2]) . '">' . (($kind === 'imported' || $kind === 'warn') ? self::recordsHtml($run, $key) : '--') . '</td>'
                . '<td data-label="' . safe($heads[3]) . '">' . self::timeOf($time) . self::ageHtml($time, $now)
                . (($since !== '--') ? '<small class="aa-import-since">' . _m('by the run started %1', [$since]) . '</small>' : '') . '</td>'
                . '<td data-label="' . safe($heads[4]) . '">' . self::timeOf($stream['probed'] ?? null) . self::ageHtml($stream['probed'] ?? null, $now) . '</td>'
                . '<td data-label="' . safe($heads[5]) . '">' . self::versionHtml($stream['v'] ?? '', $kind) . '</td>'
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

    /** @param array<mixed> $post  @return array{0: string, 1: string, 2?: string} */
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
            default   => ['refused', ($r->reason !== '') ? $r->reason : strtoupper($r->code), ...(($r->detail === '') ? [] : [$r->detail])],
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
     *  source's own words may carry its data.
     *  @param string $detail of a failure of the source, as AA\IO\SourceFailure::$detail */
    public static function outcomeHtml(string $code, string $reason, string $detail = ''): string
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
            'refused'     => MsgErr(safe(self::failureText($reason, $detail)) . DetailConfig::settingsPointer($reason, true)),
            'failed'      => MsgErr(_m('The action failed. The log of import failures has its cause.')),
            'adopted', 'no_entry', 'adopt_busy', 'adopt_refused' => DetailCatalog::outcomeHtml($code, $reason, $detail),
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
            'COLUMNS'        => _m('The first row of the file lacks columns the mapping needs.'),
            'AUTH'           => _m('The source did not accept the key.'),
            'NO_CREDENTIAL'  => _m('The key of the source is damaged in the configuration.'),
            'TRANSPORT'      => _m('The source could not be reached.'),
            'WRONG_STATE'    => _m('The run is finished; it cannot go on.'),
            'OFF'            => _m('The import is switched off.'),
            'KEYS'           => _m('This import uses API keys, so only a superadmin may do this.'),
            'NO_VERSIONS'    => _m('The import source cannot tell the versions of its streams, so there is no state of it to record.'),
            default          => _m('The import source refused: %1', [$code]),
        };
    }

    /** reasonText() with what the detail of the failure adds. SOURCE with a detail is an answer of the
     *  source (a status, a too large answer), not a failure of its code; TRANSPORT with a status was
     *  reached, only it was down. Plain text. */
    public static function failureText(string $code, string $detail): string
    {
        $reason = match (true) {
            $detail === ''                                                => self::reasonText($code),
            $code === 'SOURCE'                                            => _m('The request to the source failed.'),
            ($code === 'TRANSPORT') && str_starts_with($detail, 'http:') => _m('The source is not available now.'),
            default                                                       => self::reasonText($code),
        };
        return trim($reason . ' ' . RunView::detailText($detail));
    }

    // ------------------------------------------------------------ overview parts

    /** the last import with its counts; a newer run that stored nothing (a check, a dry run) is told apart under it
     *  @param list<Run> $runs newest first */
    private function lastImportHtml(array $runs, int $now): string
    {
        $import = $this->lastImport();
        $newest = $runs[0] ?? null;
        $about  = ($newest === null) ? '' : safe(RunView::howItRan($newest)) . ', ' . RunView::whenText((int) $newest->getProperty('started'), $now) . ' - ' . safe(RunView::resultText($newest, $now));
        if ($import === null) {
            return '<p class="aa-import-value">' . _m('No import yet') . '</p>'
                . (($about === '') ? '' : '<p class="aa-import-sub aa-import-newer">' . _m('Latest run: %1', [$about]) . '</p>');
        }
        $how = RunView::howItRan($import);
        return '<p class="aa-import-value">' . RunView::whenText((int) $import->getProperty('started'), $now) . '</p>'
            . '<p class="aa-import-sub">' . (($import->state() === Run::DONE) ? '' : safe(RunView::outcomeText($import, $now)) . ' &middot; ') . (($how === '') ? '' : safe($how) . ' &middot; ') . self::tookText($import, $now) . '</p>'
            . self::countsHtml($import)
            . ((($newest === null) || ((string) $newest->getId() === (string) $import->getId())) ? '' : '<p class="aa-import-sub aa-import-newer">' . _m('Since then: %1', [$about]) . '</p>');
    }

    /** the verdict in words with a mark, why, and what went wrong - colour is never the only signal
     *  @param array{kind: string, word: string, reason: string} $health */
    private function healthHtml(array $health): string
    {
        $enabled = $this->def->isEnabled();
        $source  = safe(Definition::sourceName($this->def->sourceType()));
        $line    = _m('Source: %1', [$source]) . ($this->hasSource() ? '' : ' - ' . _m('this source is not available')) . ' &middot; ' . ($enabled ? _m('Enabled') : _m('Disabled'));
        return '<div class="aa-import-health" data-kind="' . $health['kind'] . '">'
            . RunView::chipHtml($health['kind'], $health['word'])
            . '<p class="aa-import-reason">' . safe($health['reason']) . '</p></div>'
            . $this->firstErrorHtml()
            . '<p class="aa-import-sub">' . $line . '</p>';
    }

    /** What went wrong: why the open run stopped, else the first item error of the last import, in
     *  the source's words. Not shown when neither has one. */
    private function firstErrorHtml(): string
    {
        $open = $this->openRun();
        if ($open?->state() === Run::ERROR) {
            return self::runErrorHtml($this->progressOf($open)['error'] ?? null);
        }
        $import = $this->lastImport();
        $first  = ($import === null) ? null : ($import->errors()[0] ?? null);
        if (($first === null) || ($import->counts()['errors'] === 0)) {
            return '';
        }
        $where = trim($first['stream'] . ' ' . $first['job']);
        return self::alertHtml(_m('First item error:'), $first['code'] . (($first['msg'] === '') ? '' : ': ' . $first['msg']) . (($where === '') ? '' : ' (' . $where . ')'));
    }

    /** @param string $label plain text */
    private static function alertHtml(string $label, string $text): string
    {
        return '<p class="aa-import-alert">' . RunView::markHtml('warn') . ' <strong>' . safe($label) . '</strong> ' . safe($text) . '</p>';
    }

    /** One tick per latest run, newest first: the shape and the hidden word say how it went; a dashed
     *  tick is a run that stored nothing (a check, a dry run, a baseline), so it never looks like an import.
     *  @param list<Run> $runs newest first */
    private static function ribbonHtml(array $runs, int $now): string
    {
        $runs = array_slice($runs, 0, 10);
        if (count($runs) < 2) {
            return '';
        }
        $ticks = '';
        foreach ($runs as $run) {
            $kind    = RunView::outcome($run, $now)['kind'];
            $what    = trim(self::kindOfRun($run) . ' - ' . RunView::outcomeText($run, $now), ' -');
            $text    = RunView::whenText((int) $run->getProperty('started'), $now) . ' - ' . $what;
            $ticks  .= '<li data-outcome="' . $kind . '" data-stored="' . ($run->storesItems() ? 'yes' : 'no') . '">'
                . '<a href="#aa-import-runs" title="' . safe($text) . '">' . RunView::markHtml($kind)
                . '<span class="aa-import-sr">' . safe($text) . '</span></a></li>';
        }
        return '<div class="aa-import-ribbon"><h3>' . _m('Latest runs') . '</h3><ol aria-label="' . safe(_m('Outcomes of the latest runs, newest first')) . '">' . $ticks . '</ol>'
            . '<p class="aa-import-sub">' . _m('Newest first. A dashed circle is a run that stored nothing: a check, a dry run or a baseline. A filled square %1 is a run ended before it finished.', [RunView::markHtml('ended')]) . '</p></div>';
    }

    /** the counts of an import as numbers under their names; item errors get a mark when there are any */
    private static function countsHtml(Run $run): string
    {
        $nothing = RunView::nothingReadText($run);
        if ($nothing !== '') {
            return '<p class="aa-import-sub">' . safe($nothing) . '</p>';
        }
        $counts = $run->counts();
        $html   = '';
        foreach (['read' => _m('Read'), 'new' => _m('New'), 'updated' => _m('Updated'), 'skipped' => _m('Skipped'), 'errors' => _m('Item errors')] as $key => $label) {
            $bad   = (($key === 'errors') && ($counts[$key] > 0));
            $html .= '<div' . ($bad ? ' data-kind="warn"' : '') . '><dt>' . $label . '</dt><dd>' . ($bad ? RunView::markHtml('warn') . ' ' : '') . (int) $counts[$key] . '</dd></div>';
        }
        return '<dl class="aa-import-counts">' . $html . '</dl>';
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
            $run->state() === Run::ERROR => '<p>' . _m('Nothing more is imported until you act or a planned task starts the import again (that ends this run).') . '</p>'
                . '<p>' . _m('Continue goes on from where it stopped once the cause is fixed; End stops it for good and keeps what was imported.') . '</p>',
            $run->isStuck($now)   => '<p>' . _m('No step has worked on it for a long time - check that cron runs the task queue and that its rounds are long enough for a step (TOEXECUTE_ALLOWED_TIME in config.php). Continue tries it again now; End stops it for good and keeps what was imported. A planned start of the import ends it and starts a new run.') . '</p>',
            $p['running']         => '<p>' . _m('A step is working now.') . '</p>',
            $run->isWaiting($now) => '<p>' . match (true) {
                $p['failures'] === 1 => _m('Waiting to try again at %1 after 1 failed try.', [date('H:i', $p['next_at'])]),
                $p['failures'] > 1   => _m('Waiting to try again at %1 after %2 failed tries.', [date('H:i', $p['next_at']), $p['failures']]),
                default              => _m('Waiting to try again at %1.', [date('H:i', $p['next_at'])]),
            } . '</p>',
            $run->stepMayStillWork($now) => '<p>' . _m('Its last step may still be working.') . '</p>',
            $run->hasDeadStep($now) => '<p>' . _m('Its last step stopped without finishing. The task queue tries it again within %1 minutes; Try now does it at once.', [intdiv(Toexecute::CLAIM_LEASE, 60)]) . '</p>',
            default               => '<p>' . $this->queuedText($run, $now) . '</p>',
        };
        return $html;
    }

    private function queuedText(Run $run, int $now): string
    {
        $until = $this->heldBackUntil($run, $now);
        return $until ? _m('Its next step waits in the task queue until %1.', [date('H:i', $until)]) : _m('Its next step waits in the task queue.');
    }

    /** When the queue lets the run's step go, if later than $now; 0 = it does not hold it back. A step
     *  at work keeps its queue row leased for the whole claim, which is no waiting. */
    private function heldBackUntil(Run $run, int $now): int
    {
        if ($run->hasLiveLease($now) || $run->stepMayStillWork($now)) {
            return 0;
        }
        $until = $this->queuedUntil[(string) $run->getId()] ??= $run->queuedUntil();
        return ($until > $now) ? $until : 0;
    }

    private function nextPlanHtml(int $now): string
    {
        // a planned start of a disabled import does nothing
        if (!$this->def->isEnabled()) {
            return '<p class="aa-import-value">' . _m('Off') . '</p><p class="aa-import-sub">' . _m('The import is disabled, so no planned task starts it.') . '</p>';
        }
        $next = $this->def->nextPlanned($now);
        if ($next !== null) {
            return '<p class="aa-import-value">' . date('Y-m-d H:i', $next) . '</p>';
        }
        return '<p class="aa-import-value">' . _m('Not planned') . '</p>' . ($this->def->plannedTasks($now)
            ? '<p class="aa-import-sub">' . _m('A planned task calls it, but none has a time to start it.') . '</p>'
            : '<p class="aa-import-sub">' . _m('It runs only when you start it. To run it on a schedule, add a planned task.') . '</p>' . $this->taskCallHtml());
    }

    /** For a superadmin, who may write a planned task by hand: the call that starts this import. The
     *  braces are entities, so no template expansion of the page can ever run it. */
    private function taskCallHtml(): string
    {
        if (!Definition::isSuperadmin($this->uid)) {
            return '';
        }
        $call = '<code>&#123;import:' . safe($this->id()) . ':changed&#125;</code>';
        return '<p class="aa-import-sub"><small>' . _m('Written by hand, a planned task starts it with %1.', [$call]) . '</small></p>';
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
                !$tasks => '',
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
            $unsaved = $task['saved'] ? '' : ' - ' . _m('save the task again to let it start the import');
            $items  .= '<li>' . $name . ': ' . $modes . ', ' . $next . ', ' . ($task['queued'] ? _m('queued') : _m('not queued')) . $off . $unsaved . '</li>';
        }
        $html = ($items === '') ? '' : '<ul class="aa-import-tasks">' . $items . '</ul>';
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

    /** The newer of the result the stream state holds and the newest finished check run of the
     *  settings in force: saving other settings resets the state, while the run list keeps the history.
     *  @param list<Run> $runs newest first */
    private function lastCheckHtml(array $runs, int $now): string
    {
        $state   = $this->streamState();
        $check   = $state?->lastCheck();
        $current = Service::settingsHash($this->def);
        $fromRun = null;
        foreach ($runs as $run) {
            if (($run->mode() === 'check') && in_array($run->state(), [Run::DONE, Run::ERROR], true) && hash_equals($current, Service::settingsHash($run))) {
                $fromRun = $run;
                break;
            }
        }
        $runTime = ($fromRun === null) ? 0 : ((int) $fromRun->getProperty('finished') ?: (int) $fromRun->getProperty('started'));
        if (($fromRun !== null) && ($runTime > (int) ($check['time'] ?? 0))) {
            $ok   = ($fromRun->state() === Run::DONE);
            $code = $ok ? '' : (string) ($this->progressOf($fromRun)['error']['code'] ?? '');
            return '<p class="aa-import-value">' . RunView::chipHtml($ok ? 'ok' : 'bad', $ok ? _m('Passed') : _m('Failed')) . '</p>'
                . '<p class="aa-import-sub">' . RunView::whenText($runTime, $now) . (($code === '') ? '' : ' <code>' . safe($code) . '</code>') . '</p>'
                . '<p class="aa-import-sub"><small>' . _m('Taken from the run list: the stream state holds no newer check result.') . '</small></p>';
        }
        if ($check === null) {
            return '<p class="aa-import-value">' . ($state?->checkDamaged() ? _m('The stored result cannot be read.') : _m('Never checked')) . '</p>';
        }
        $html = '<p class="aa-import-value">' . RunView::chipHtml($check['ok'] ? 'ok' : 'bad', $check['ok'] ? _m('Passed') : _m('Failed')) . '</p>'
            . '<p class="aa-import-sub">' . RunView::whenText($check['time'], $now);
        if ($check['code'] !== '') {
            $html .= ' <code>' . safe($check['code']) . '</code>';
        }
        if ($check['msg'] !== '') {
            $html .= ': ' . safe($check['msg']);
        }
        return $html . '</p>';
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
        $unknown = in_array('UNKNOWN_FIELD', $problems, true) ? $this->def->mapping()->unknownFields($this->slice()) : [];
        $items   = array_map(static fn(string $code): string => '<li>' . safe(Mapping::problemText($code, $unknown)) . ' <code>' . safe($code) . '</code></li>', $problems);
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
        return '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions aa-import-rare">' . $this->hidden('stream')
            . '<label>' . _m('One stream') . ' <select name="stream_key"' . ($keys ? '' : ' disabled') . '>' . $options . '</select></label> '
            . self::button(_m('Run the stream'), $off, ['name' => 'dry', 'value' => '0', 'class' => 'aa-import-quiet'])
            . ' ' . self::button(_m('Dry run of the stream'), $off, ['name' => 'dry', 'value' => '1', 'class' => 'aa-import-quiet'])
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
        return '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions aa-import-rare" data-aa-confirm="' . safe(_m('Record the versions the source has now, as if they were imported? Streams that do not change afterwards will not be imported.')) . '">' . $this->hidden('baseline')
            . self::button(_m('Record the current state of the source'), $off, ['class' => 'aa-import-quiet'])
            . (($refused === null) ? '' : self::whyHtml($off))
            . '<p><small>' . _m('Saves the version each stream has now without importing, so Run changed only then takes just what changes later. Use it only when the source data are already in the slice.') . '</small></p>'
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
                default                   => $this->queuedText($open, $now),
            };
            $resume = ($open->isWaiting($now) || $open->continueKeepsCount($now)) ? _m('Try now') : _m('Continue');
        }
        $run = ($open === null) ? '' : self::hiddenInput('run', (string) $open->getId());
        $why = self::whyHtml(...);
        return '<div class="aa-import-open">'
            . '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions">' . $this->hidden('resume') . $run
            . self::button($resume, $whyGo, ['class' => 'aa-import-quiet'])
            // one reason for both when there is no run
            . (($open === null) ? '' : $why($whyGo))
            . '</form>'
            . '<form method="post" action="' . safe($this->url()) . '" class="aa-import-actions" data-aa-confirm="' . safe(_m('End the run for good? What it imported stays; the next run starts from the beginning.')) . '">' . $this->hidden('end') . $run
            . self::button(_m('End'), $whyEnd, ['class' => 'aa-import-quiet'])
            . (($open === null) ? '' : $why($whyEnd))
            . '</form>'
            . (($open === null) ? $why($whyEnd) : '')
            . '</div>';
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

    /** items skipped as their key came out empty: the source values the key was computed from
     *  @param array{count: int, samples: list<array{stream: string, job: string, values: array<string, string>}>} $skipped */
    private static function emptyKeysHtml(array $skipped, string $runId): string
    {
        if ($skipped['count'] === 0) {
            return '';
        }
        $summary = _m('Skipped - the key came out empty (%1)', [$skipped['count']]);
        if (!$skipped['samples']) {
            return '<p>' . $summary . '</p>';
        }
        $rows = '';
        foreach ($skipped['samples'] as $s) {
            $values = array_map(static fn(string $field, string $value): string => safe($field) . ' = ' . safe($value), array_keys($s['values']), $s['values']);
            $rows  .= '<tr><td>' . safe($s['stream']) . '</td><td>' . safe($s['job']) . '</td><td>' . implode('<br>', $values) . '</td></tr>';
        }
        return '<details id="aa-import-nokey-' . safe($runId) . '"><summary>' . $summary . '</summary>'
            . '<table><thead><tr><th scope="col">' . _m('Stream') . '</th><th scope="col">' . _m('Job') . '</th><th scope="col">' . _m('Source value') . '</th></tr></thead>'
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

    /** The failure of a stopped run in words, what to do about it, and where it stopped; the code is
     *  for the log. A run that still tries again shows why its last try failed, with nothing to do yet.
     *  @param ?array{code: string, where: string, detail?: string} $error */
    private static function runErrorHtml(?array $error, bool $retrying = false): string
    {
        if ($error === null) {
            return '';
        }
        $detail  = (string) ($error['detail'] ?? '');
        $message = self::failureText($error['code'], $detail);
        $todo    = $retrying ? '' : self::nextStep($error['code'], $detail);
        return '<div class="aa-import-fail"><p>' . RunView::markHtml('error') . ' <strong>' . ($retrying ? _m('Last try failed:') : _m('Error:')) . '</strong> ' . safe($message)
            . (($error['where'] === '') ? '' : ' <span class="aa-import-where">' . _m('At: %1', ['<code>' . safe($error['where']) . '</code>']) . '</span>')
            . (str_contains($message, $error['code']) ? '' : ' <code class="aa-import-code">' . safe($error['code']) . '</code>') . '</p>'
            . (($todo === '') ? '' : '<p class="aa-import-todo"><strong>' . _m('What to do:') . '</strong> ' . safe($todo) . '</p>')
            . '</div>';
    }

    /** the next step for an error code the source or the run throws; '' when none is known */
    private static function nextStep(string $code, string $detail = ''): string
    {
        if (($code === 'SOURCE') && ($detail !== '')) {
            // the address is wrong, moved or gone; other answers (too large, 1xx) have no advice of their own
            return ((preg_match('/^http:[34][0-9]{2}$/D', $detail) === 1) || in_array($detail, [TransportException::ADDRESS, SourceFailure::REDIRECTS], true))
                ? _m('Check the address of the source in its settings, then Continue or End the run.')
                : '';
        }
        return match ($code) {
            'COLUMNS'       => _m('Fix the column mapping or the file so that the missing columns exist, then run Check source again.'),
            'AUTH'          => _m('Check the key of the source in its settings, then run Check source.'),
            'NO_CREDENTIAL' => _m('Enter the key of the source again in its settings, then run Check source.'),
            'TRANSPORT'     => _m('The source could not be reached. Check its address in the settings; if it is right, Continue the run later.'),
            'SETTINGS'      => _m('Correct the settings of the source, then run Check source.'),
            'STREAMS'       => _m('Choose a stream the source still has, then start the import again.'),
            'DEFINITION'    => _m('Check that every mapped field exists in the slice, then start the import again.'),
            'SOURCE'        => _m('Look the failure up in the log of import failures, then Continue or End the run.'),
            default         => '',
        };
    }

    /** what kind of run it was: how much it reads, a dry run, who started it */
    private static function kindOfRun(Run $run): string
    {
        $how = RunView::howItRan($run);
        return ($run->mode() === 'run') ? trim(_m('full run') . ', ' . $how, ', ') : $how;
    }

    /** the five count cells; a run that imports nothing, or stopped before it read anything, says so in one cell
     *  @param list<string> $labels */
    private static function countCellsHtml(Run $run, int $now, array $labels, string $errorsOf = ''): string
    {
        $nothing = in_array($run->mode(), Run::SOURCE_ONLY_MODES, true) ? RunView::resultText($run, $now) : RunView::nothingReadText($run);
        if ($nothing !== '') {
            return '<td colspan="5" class="aa-import-nocounts">' . safe($nothing) . '</td>';
        }
        $counts = $run->counts();
        $html   = '';
        foreach (['read', 'new', 'updated', 'skipped', 'errors'] as $i => $key) {
            $n     = (int) $counts[$key];
            $class = ($n === 0) ? ' class="aa-import-zero"' : (($key === 'errors') ? ' class="aa-import-some"' : '');
            $cell  = (($key === 'errors') && ($n > 0) && ($errorsOf !== '')) ? '<a href="#aa-import-errors-' . safe($errorsOf) . '" data-aa-open>' . $n . '</a>' : (string) $n;
            $html .= '<td data-label="' . safe($labels[$i]) . '"' . $class . '>' . $cell . '</td>';
        }
        return $html;
    }

    /** What the stream is, for the eye and for the operator: css kind (the mark's shape differs, not only
     *  its colour), the verdict word, a line saying what follows from it, and the time that state began -
     *  the import, or the baseline when that came later. The stored state keeps one version per stream, so
     *  it cannot say the source changed since: nothing here claims it.
     *  @param array<string, string|int> $stream
     *  @return array{string, string, string, string, int|null} kind, mark, word, reason (html), time */
    private static function streamKind(array $stream, string $key = '', ?Run $run = null): array
    {
        $imported = is_int($stream['imported'] ?? null) ? $stream['imported'] : 0;
        $baseline = is_int($stream['baseline'] ?? null) ? $stream['baseline'] : 0;
        $probed   = is_int($stream['probed'] ?? null) ? $stream['probed'] : 0;
        if ($baseline > $imported) {
            return ['baseline', 'baseline', _m('Baseline only'), _m('Nothing imported yet. Run changed only takes what changes after this.'), $baseline];
        }
        if ($imported > 0) {
            $bad = 0;
            foreach ($run?->errors() ?? [] as $error) {
                $bad += ($error['stream'] === $key) ? 1 : 0;
            }
            return match (true) {
                $bad === 1 => ['warn', 'warn', _m('Imported with item errors'), _m('1 item error in the run that imported it - see Runs.'), $imported],
                $bad > 1   => ['warn', 'warn', _m('Imported with item errors'), _m('%1 item errors in the run that imported it - see Runs.', [$bad]), $imported],
                // a probe in the same second came with the import
                default    => ['imported', 'ok', _m('Imported'), (($probed > $imported) ? _m('The source was asked again after the import.') : _m('The source has not been asked since.')), $imported],
            };
        }
        return ($probed > 0)
            ? ['checked', 'checked', _m('Checked, not imported yet'), _m('Run imports it; Run changed only too, as it has no version to compare.'), null]
            : ['never', 'never', _m('Not seen yet'), _m('No run has imported or checked it.'), null];
    }

    /** the records of the run that imported the stream. The state keeps no count per stream, so a run of
     *  one stream gives its own numbers and a run of several is given as a whole and said to be */
    private static function recordsHtml(?Run $run, string $key): string
    {
        if (($run === null) || !in_array($run->mode(), ['run', 'changed'], true)) {
            return '--';
        }
        $counts = $run->counts();
        $keys   = json_decode((string) $run->getProperty('stream_keys'), true);
        $alone  = is_array($keys) && ($keys === [$key]);
        $html   = '<strong>' . _m('%1 read', [$counts['read']]) . '</strong><small class="aa-import-reason">'
            . _m('%1 new, %2 updated, %3 skipped', [$counts['new'], $counts['updated'], $counts['skipped']]) . '</small>';
        $note   = $run->isDry() ? _m('dry run - nothing stored') : ($alone ? '' : _m('all streams of the run together'));
        return $html . (($note === '') ? '' : '<small class="aa-import-reason">' . $note . '</small>');
    }

    /** "25 min ago" under an absolute time, so the freshness reads at a glance */
    private static function ageHtml(mixed $time, int $now): string
    {
        $age = is_int($time) ? RunView::ageText($time, $now) : '';
        return ($age === '') ? '' : '<small class="aa-import-since">' . safe($age) . '</small>';
    }

    /** The data version is for the technical eye: a short head; a long one opens to the whole value,
     *  which a touch screen has no tooltip for. None says why: a check records none, nor does a
     *  baseline of a stream versioned by a field.
     *  @param string $kind of streamKind() */
    private static function versionHtml(mixed $version, string $kind = ''): string
    {
        if (!is_string($version) || ($version === '')) {
            return match ($kind) {
                'checked', 'baseline' => '<small>' . _m('None yet - an import records it') . '</small>',
                'imported', 'warn'    => '<small>' . _m('None - the source gives no version') . '</small>',
                default               => '--',
            };
        }
        if (mb_strlen($version) <= 12) {
            return '<code class="aa-import-version">' . safe($version) . '</code>';
        }
        return '<details class="aa-import-vfull"><summary><code class="aa-import-version">' . safe(mb_substr($version, 0, 10)) . '…</code></summary>'
            . '<code class="aa-import-version">' . safe($version) . '</code></details>';
    }

    private static function timeOf(mixed $time): string
    {
        return (is_int($time) && ($time > 0)) ? date('Y-m-d H:i', $time) : '--';
    }

    /** how long the run took: till it finished, till its last progress when it stopped by an error,
     *  till now while it runs */
    private static function took(Run $run, int $now): string
    {
        $seconds = self::tookSeconds($run, $now);
        return ($seconds === null) ? '--' : self::duration($seconds);
    }

    /** took() in seconds; null before the run started */
    private static function tookSeconds(Run $run, int $now): ?int
    {
        $started = (int) $run->getProperty('started');
        if (!$started) {
            return null;
        }
        $end = (int) $run->getProperty('finished') ?: (($run->state() === Run::ERROR) ? ((int) $run->getProperty('moved') ?: $started) : $now);
        return max(0, $end - $started);
    }

    /** How long the steps of the run worked, when shorter than took() reads - the rest the run waited
     *  in the task queue; '' when the run did not count it. A run in error took till its last progress,
     *  while its failed tries worked on after it: then only Took is shown. */
    private static function worked(Run $run, int $now): string
    {
        $worked = $run->workedSeconds();
        $took   = self::tookSeconds($run, $now);
        if (($worked === null) || ($took === null) || ($worked >= $took)) {
            return '';
        }
        return (self::duration($worked) === self::duration($took)) ? '' : self::duration($worked);
    }

    /** "took …" of the last import, with its working time when that differs */
    private static function tookText(Run $run, int $now): string
    {
        $took   = self::took($run, $now);
        $worked = self::worked($run, $now);
        return ($worked === '') ? _m('took %1', [$took]) : _m('took %1, working %2', [$took, $worked]);
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return _m('%1 s', [$seconds]);
        }
        if ($seconds < 600) {
            return ($seconds % 60) ? _m('%1 min %2 s', [intdiv($seconds, 60), $seconds % 60]) : _m('%1 min', [intdiv($seconds, 60)]);
        }
        return ($seconds < 7200) ? _m('%1 min', [intdiv($seconds, 60)]) : _m('%1 h', [intdiv($seconds, 3600)]);
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

    /** the newest import, however many runs that stored nothing came after it */
    private function lastImport(): ?Run
    {
        if ($this->import === false) {
            $this->import = Run::newestOf($this->id())[1];
        }
        return $this->import;
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
     *                 error: ?array{code: string, where: string, detail: string}, note: string, warning: string, extra: array} */
    private function progressOf(Run $run): ?array
    {
        try {
            return $run->progress($this->uid);
        } catch (BatchFailed) {
            return null;
        }
    }

    /** progressOf() and what only time changes - a run that hangs, a retry that is due, when the
     *  queue lets its step go - so the script learns of those too
     *  @return ?array<string, mixed> */
    private function liveFacts(Run $run, int $now): ?array
    {
        $progress = $this->progressOf($run);
        return ($progress === null) ? null : $progress + ['stuck' => $run->isStuck($now), 'waiting' => self::waits($run, $now), 'dead' => $run->hasDeadStep($now), 'queued_until' => $this->heldBackUntil($run, $now)];
    }

    /** Open, and no step works on it, hangs or died: it waits for its retry or for the queue to take
     *  its next step - a run just started waits too, though the run itself set no time. */
    private static function waits(Run $run, int $now): bool
    {
        return $run->isWaiting($now)
            || (($run->state() === Run::RUNNING) && !$run->stepMayStillWork($now) && !$run->isStuck($now) && !$run->hasDeadStep($now));
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
