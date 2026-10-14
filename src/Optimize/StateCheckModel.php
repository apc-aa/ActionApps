<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\StateCheck;
use AA\Util\Langs;
use AA\Util\Note;
use AA\Util\Notes;
use AA_Log;
use AA_Logentry;
use AA_Logentry_Sc;
use Closure;

/** The one model behind State Check: admin/aa_statecheck.php renders it as the
 *  page and serializes it for ?format=json, which the nightly mail reads - so
 *  the mail is a faithful picture of the page.
 *
 *  Findings are filed into sections (new, ongoing, not verified, muted, passed,
 *  environment) by comparing them with the latest daily snapshot (STATE_CHK rows,
 *  AA\Later\StateCheck). The snapshot keeps one row per check, not per finding,
 *  so a finding without a time of its own (Finding::$since) is new or ongoing
 *  as its whole check was.
 *
 *  Near-duplicates collapse into one group row (Sentry-like): the findings of one
 *  check in one section that share their advice - one kind of problem.
 */
final class StateCheckModel
{
    public const NEW = 'new';
    public const REGRESSED = 'regressed';
    public const ONGOING = 'ongoing';
    public const NOT_VERIFIED = 'not_verified';
    public const MUTED = 'muted';
    public const PASSED = 'passed';
    public const ENVIRONMENT = 'environment';
    /** sections in display order; "new" holds the regressed items too */
    public const SECTIONS = [self::NEW, self::ONGOING, self::NOT_VERIFIED, self::MUTED, self::PASSED, self::ENVIRONMENT];
    /** sections open on the page; the others start collapsed */
    public const OPEN_SECTIONS = [self::NEW, self::ONGOING];

    public const ACTION_RECHECK = 'recheck';
    public const ACTION_REPAIR = 'repair';
    public const ACTION_RESOLVE = 'resolve';
    /** the key Recheck posts for a check known only from the snapshot, which keeps no keys: any of its problems answers */
    public const ANY_KEY = '*';

    public const SOURCE_STATECHECK = 'statecheck';
    public const SOURCE_NIGHTLY = 'nightly';

    /** the types() tags State Check shows */
    public const TAGS = [AbstractOptimize::CHECK_BASIC, AbstractOptimize::CHECK_EXTENDED, AbstractOptimize::CHECK_SLOW, AbstractOptimize::CHECK_SECURITY];

    /** short name of the check that carries the nightly run's findings */
    private const NIGHTLY_CHECK = 'CheckNightly';
    /** snapshot rows read back - a few per day, so years of history */
    private const HISTORY_ROWS = 5000;
    /** characters of the place a group headline names */
    private const PLACE_MAX = 60;

    /** @var array<string, list<StateCheckItem>> every section in display order, empty ones included; near-duplicates as groups */
    public readonly array $sections;
    /** @var array<string, list<array{text: string, count: int}>> plain lines under the items: checks with nothing to show one by one */
    public readonly array $lines;

    /** @var array<string, list<array{time: int, check: string, severity: string, count: int, title: string}>> check => its snapshot rows, oldest first */
    private array $history = [];
    private string $install_url;
    /** @var Closure(string): string */
    private Closure $user_name;
    /** @var array<string, string> check => its human name, for a group row */
    private array $names;

    /**
     * @param Finding[]                                                                      $findings    what this load knows (CheckRunner, without the resolved ones), including
     *                                                                                                    findings a source verified earlier (Finding::$checked)
     * @param list<array{0: Finding, 1: Note}>                                               $resolved    CheckRunner::splitResolved()[1]
     * @param array<string, Note>                                                            $notes       Note::resolved(): the newest resolution of each finding, expired ones too
     * @param list<array{time: int, check: string, severity: string, count: int, title: string}> $history  the checks' snapshot rows, oldest first (parseHistory())
     * @param int                                                                            $snapshot    time of the latest daily snapshot, 0 = none yet
     * @param array<string, array{name: string, slow: bool, category: string}>              $unverified  extended and slow checks that did not run on this load
     * @param string[]                                                                       $recheckable checks the page's Recheck can run
     * @param ?array{run: string, time: int}                                                 $nightly     the latest nightly run
     * @param int                                                                            $nightly_full when the latest full nightly run started, 0 = none
     * @param ?Closure(string): string                                                       $user_name   uid => name of who resolved a finding
     * @param array<string, string>                                                          $names       check => its human name (AbstractOptimize::name())
     */
    public function __construct(
        array $findings,
        array $resolved = [],
        private readonly array $notes = [],
        array $history = [],
        public readonly int $snapshot = 0,
        array $unverified = [],
        private readonly array $recheckable = [],
        public readonly int $now = 0,
        string $install_url = 'https://localhost/',
        public readonly string $lang = 'en',
        public readonly ?array $nightly = null,
        ?Closure $user_name = null,
        array $names = [],
        private readonly int $nightly_full = 0,
    ) {
        $this->install_url = rtrim($install_url, '/') . '/';
        $this->user_name   = $user_name ?? static fn(string $uid): string => $uid;
        $this->names       = $names;
        foreach ($history as $row) {
            $this->history[$row['check']][] = $row;
        }

        $sections = array_fill_keys(self::SECTIONS, []);
        $ids      = [];
        $add      = static function (string $section, StateCheckItem $item) use (&$sections, &$ids): void {
            // a check tagged both basic and extended runs twice when the extended ones are run - one row is enough
            if (!isset($ids[$item->id])) {
                $ids[$item->id]         = true;
                $sections[$section][] = $item;
            }
        };
        // not verified now, but nothing wrong when last known: a line each, not a row per check
        $quiet = ['passed' => [], 'no_snapshot' => [], 'slow' => [], 'nightly' => []];
        foreach (CheckRunner::sort($findings) as $finding) {
            $item = $this->fromFinding($finding);
            if (($item->state === self::NOT_VERIFIED) && !$finding->isProblem()) {
                $quiet['nightly'][] = ($finding->check === self::NIGHTLY_CHECK) ? CheckNightly::describe($finding->key)[0] : $finding->title;
                continue;
            }
            $add(self::isEnvironment($finding) ? self::ENVIRONMENT : self::sectionOf($item->state), $item);
        }
        foreach ($resolved as [$finding, $note]) {
            $add(self::MUTED, $this->resolvedItem($finding, $note));
        }
        foreach ($unverified as $check => $info) {
            if (!$info['slow'] && ($item = $this->unverifiedItem($check, $info['category']))) {
                $add(self::NOT_VERIFIED, $item);
            } else {
                $quiet[$info['slow'] ? 'slow' : ($this->snapshot ? 'passed' : 'no_snapshot')][] = $info['name'];
            }
        }
        foreach ($sections as $id => $items) {
            usort($items, [self::class, 'compare']);
            $sections[$id] = ($id === self::ENVIRONMENT) ? $items : $this->group($id, $items);
        }
        $this->sections = $sections;
        $this->lines    = [self::NOT_VERIFIED => $this->quietLines($quiet)];
    }

    /** The model of this installation now: the findings as the page ran them, the
     *  rest (resolutions, snapshot history, checks that did not run) read here.
     * @param Finding[] $findings everything this load ran
     * @param string[]  $ran      short names of the checks that ran on this load
     */
    public static function load(array $findings, array $ran, int $now): self
    {
        $notes                = Note::resolved();
        [$visible, $resolved] = CheckRunner::splitResolved($findings, $now, $notes);
        $unverified = [];
        foreach (CheckRunner::classes([AbstractOptimize::CHECK_EXTENDED, AbstractOptimize::CHECK_SLOW]) as $short => $class) {
            if (!in_array($short, $ran, true)) {
                $unverified[$short] = [
                    'name'     => $class::name() ?: $short,
                    'slow'     => !in_array(AbstractOptimize::CHECK_EXTENDED, $class::types(), true),
                    'category' => $class::category(),
                ];
            }
        }
        $last  = AA_Log::getLastLogEvents(StateCheck::EVENT, StateCheck::SUMMARY);
        $night = NightlyLog::load();
        return new self(
            findings: $visible,
            resolved: $resolved,
            notes: $notes,
            history: self::parseHistory(array_reverse(DB_AA::select([], 'SELECT time, selector, params FROM log', [['type', StateCheck::EVENT]], ['time-', 'id-'], null, self::HISTORY_ROWS))),
            snapshot: (int) ($last['time'] ?? 0),
            unverified: $unverified,
            recheckable: array_keys(CheckRunner::classes(self::TAGS)),
            now: $now,
            install_url: (string) constant('AA_INSTAL_URL'),
            lang: explode('-', Langs::currentLang())[0],
            nightly: $night ? ['run' => $night['run']['run'], 'time' => $night['run']['finished']] : null,
            user_name: static fn(string $uid): string => (string) AA::$perm->getUserName($uid),
            names: array_map(static fn(string $class): string => $class::name(), CheckRunner::classes(self::TAGS)),
            nightly_full: $night ? $night['full']['started'] : 0,
        );
    }

    /** The per-check rows of the daily snapshot; the summary rows and anything unreadable are left out.
     * @param array<array{time: int|string, selector: string, params: string}> $rows log rows, oldest first
     * @return list<array{time: int, check: string, severity: string, count: int, title: string}>
     */
    public static function parseHistory(array $rows): array
    {
        $history = [];
        foreach ($rows as $row) {
            if ((string) $row['selector'] === StateCheck::SUMMARY) {
                continue;
            }
            $entry = AA_Logentry::factoryByString((string) $row['params']);
            if (!($entry instanceof AA_Logentry_Sc) || (Finding::rank($entry->severity) > Finding::rank(Finding::OK))) {
                continue;
            }
            $history[] = ['time' => (int) $row['time'], 'check' => (string) $row['selector'], 'severity' => $entry->severity, 'count' => (int) $entry->count, 'title' => $entry->title];
        }
        return $history;
    }

    /** a stable id for the anchor (page) and the url (mail): f-{check}-{short hash of the key} */
    public static function anchor(string $check, string $key): string
    {
        return 'f-' . preg_replace('/[^A-Za-z0-9_-]/', '', $check) . '-' . substr(md5($key), 0, 8);
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            Finding::ERROR   => _m('Problem'),
            Finding::WARNING => _m('Warning'),
            Finding::INFO    => _m('Advice'),
            default          => _m('OK'),
        };
    }

    /** the state chip of a row, the same words on the page and in the mail; '' = no chip:
     *  an ongoing row has its date in the Since column, a passed one needs none */
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::NEW          => _m('New'),
            self::REGRESSED    => _m('Back again'),
            self::NOT_VERIFIED => _m('Not verified'),
            self::MUTED        => _m('Muted'),
            default            => '',
        };
    }

    /** The chip a row shows where it is - only what the place does not say already: "Back again"
     *  in new; nothing in the environment, which is no problem.
     * @param string $context  the section of the row, or its group's state for a member */
    public static function chip(string $state, string $context): string
    {
        return (($state === $context) || ($context === self::ENVIRONMENT)) ? '' : self::stateLabel($state);
    }

    /** how long ago, as coarse as a reader scans it: just now, 12 min, 3 h, 5 d, 3 w, 4 mo, 2 y; '' for 0 = unknown */
    public static function ago(int $time, int $now): string
    {
        if ($time <= 0) {
            return '';
        }
        $sec = max(0, $now - $time);
        $day = 86400;
        return match (true) {
            $sec < 60         => _m('just now'),
            $sec < 3600       => _m('%1 min', [intdiv($sec, 60)]),
            $sec < $day       => _m('%1 h', [intdiv($sec, 3600)]),
            $sec < 14 * $day  => _m('%1 d', [intdiv($sec, $day)]),
            $sec < 60 * $day  => _m('%1 w', [intdiv($sec, 7 * $day)]),
            $sec < 365 * $day => _m('%1 mo', [intdiv($sec, 30 * $day)]),
            default           => _m('%1 y', [intdiv($sec, 365 * $day)]),
        };
    }

    /** the heads of the time columns, the same words on the page and in the mail
     *  @return array{last_seen: string, since: string, count: string} */
    public static function labels(): array
    {
        return ['last_seen' => _m('Last'), 'since' => _m('Since'), 'count' => _m('Count')];
    }

    /** Recheck's answer: the problem the check still finds, null when it is gone.
     * @param Finding[] $findings  of the check run again
     * @param string    $key       Finding::$key of the row, or ANY_KEY
     */
    public static function stillThere(array $findings, string $check, string $key): ?Finding
    {
        foreach ($findings as $finding) {
            if (($finding->check === $check) && $finding->isProblem() && (($key === self::ANY_KEY) || ($finding->key === $key))) {
                return $finding;
            }
        }
        return null;
    }

    public static function sectionTitle(string $section): string
    {
        return match ($section) {
            self::NEW          => _m('New findings'),
            self::ONGOING      => _m('Ongoing'),
            self::NOT_VERIFIED => _m('Not verified now'),
            self::MUTED        => _m('Muted'),
            self::PASSED       => _m('Passed checks'),
            default            => _m('Environment'),
        };
    }

    /** under the section head: what belongs there, and what all its rows share */
    public function sectionIntro(string $section): string
    {
        $date = date('Y-m-d H:i', $this->snapshot);
        return match ($section) {
            self::NEW => $this->snapshot
                ? _m('Not there at the daily snapshot of %1 (nightly test: at its previous run), or back after it was resolved or had gone.', [$date])
                : _m('There is no daily snapshot to compare with yet, so every problem found counts as new.'),
            self::ONGOING => $this->snapshot
                ? _m('Already there at the daily snapshot of %1 (nightly test: at its previous run), and still there.', [$date])
                : _m('Already there at the previous run of the nightly test, and still there.'),
            self::NOT_VERIFIED => $this->notVerifiedIntro(),
            self::MUTED        => _m('Hidden on purpose: resolved by a note, or a known issue of the nightly test. Each says why.'),
            self::PASSED       => _m('Ran now and found nothing.'),
            default            => _m('What this installation runs on - for reference, nothing to fix.'),
        };
    }

    /** the findings of a section with the groups opened up
     * @return list<StateCheckItem> */
    public function findings(string $section): array
    {
        $flat = [];
        foreach ($this->sections[$section] as $item) {
            array_push($flat, ...($item->members ?: [$item]));
        }
        return $flat;
    }

    /** the number in the section head and the summary: rows (a group is one), and the checks the plain lines speak of */
    public function count(string $section): int
    {
        return count($this->sections[$section]) + array_sum(array_column($this->lines[$section] ?? [], 'count'));
    }

    /** as count(), with the findings of a group counted one by one */
    public function findingsCount(string $section): int
    {
        return count($this->findings($section)) + array_sum(array_column($this->lines[$section] ?? [], 'count'));
    }

    /** the problems inside, which a collapsed section marks; the environment describes, it has none
     *  @return array<string, int> severity => rows of the sections, for error, warning and info */
    public function severities(string ...$sections): array
    {
        $counts = array_fill_keys([Finding::ERROR, Finding::WARNING, Finding::INFO], 0);
        foreach (array_diff($sections, [self::ENVIRONMENT]) as $section) {
            foreach ($this->sections[$section] as $item) {
                if (isset($counts[$item->severity])) {
                    ++$counts[$item->severity];
                }
            }
        }
        return $counts;
    }

    /** something to fix now, or a problem when last known: rows in new, ongoing or not verified */
    public function hasProblems(): bool
    {
        return (bool) ($this->sections[self::NEW] || $this->sections[self::ONGOING] || $this->sections[self::NOT_VERIFIED]);
    }

    public function pageUrl(): string
    {
        return $this->install_url . 'admin/aa_statecheck.php';
    }

    /** One sentence: the page's heading line and the mail's first line. An empty section is
     *  not shown, so the sentence says it is empty. */
    public function summary(): string
    {
        $by    = $this->severities(self::NEW, self::ONGOING);
        $new   = $this->count(self::NEW);
        $now   = $this->count(self::ONGOING);
        $parts = [
            ($new ? _m('%1 new', [$new]) : _m('Nothing new')) . ', ' . ($now ? _m('%1 ongoing', [$now]) : _m('nothing ongoing'))
                . (($new + $now) ? ' (' . _m('%1 problems, %2 warnings, %3 recommendations', [$by[Finding::ERROR], $by[Finding::WARNING], $by[Finding::INFO]]) . ')' : ''),
            ($n = $this->count(self::NOT_VERIFIED)) ? _m('%1 checks not verified now', [$n]) : _m('every check verified now'),
            ($n = $this->count(self::MUTED)) ? _m('%1 muted', [$n]) : _m('nothing muted'),
            _m('%1 checks passed', [$this->count(self::PASSED)]),
        ];
        return implode('; ', $parts) . '.';
    }

    /** ?format=json: only the sections with content, in display order; plain text throughout
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $page     = $this->pageUrl();
        $sections = [];
        foreach ($this->sections as $id => $items) {
            $lines = array_column($this->lines[$id] ?? [], 'text');
            if ($items || $lines) {
                $sections[] = [
                    'id'         => $id,
                    'title'      => self::sectionTitle($id),
                    'intro'      => $this->sectionIntro($id),
                    'count'      => $this->count($id),
                    'findings'   => $this->findingsCount($id),
                    'severities' => $this->severities($id),
                    'items'      => array_map(fn(StateCheckItem $item): array => $item->toArray($page, $id, $this->now), $items),
                    'lines'      => $lines,
                ];
            }
        }
        return [
            'install'   => rtrim($this->install_url, '/'),
            'page'      => $page,
            'generated' => $this->now,
            'lang'      => $this->lang,
            'snapshot'  => $this->snapshot,
            'nightly'   => $this->nightly,
            'summary'   => $this->summary(),
            'labels'    => self::labels(),
            'sections'  => $sections,
        ];
    }

    public function json(): string
    {
        // a title cut by a byte budget elsewhere may end in half a character
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function fromFinding(Finding $finding): StateCheckItem
    {
        $checked = $finding->checked;
        $state   = match (true) {
            $finding->muted !== '' => self::MUTED,
            $checked > 0           => self::NOT_VERIFIED,
            !$finding->isProblem() => self::PASSED,
            default                => $this->timeState($finding),
        };
        $problem = $finding->isProblem() && ($state !== self::MUTED) && !self::isEnvironment($finding);
        $recheck = $problem && $finding->recheckable && in_array($finding->check, $this->recheckable, true);
        $verify  = $recheck ? _m('After the fix, press Recheck at this item on the State Check page.') : $finding->verify;
        $reason  = match ($state) {
            self::MUTED        => $finding->muted,
            self::NOT_VERIFIED => _m('As of %1.', [date('Y-m-d H:i', $checked)]),     // why it is not current: the section intro
            default            => '',
        };
        return new StateCheckItem(
            id: self::anchor($finding->check, $finding->key),
            state: $state,
            severity: $finding->severity,
            source: ($finding->check === self::NIGHTLY_CHECK) ? self::SOURCE_NIGHTLY : self::SOURCE_STATECHECK,
            check: $finding->check,
            category: $finding->category,
            title: $finding->title,
            advice: $finding->advice,
            count: $finding->count,
            first_seen: $finding->since ?: (($state === self::ONGOING) ? $this->streakStart($finding->check) : 0),
            // a state finding (cron is dead) holds now; a passed one was never seen
            last_seen: $finding->last ?: ($checked ?: (($state === self::PASSED) ? 0 : $this->now)),
            holds_now: !$finding->last && !$checked && ($state !== self::PASSED),
            where: $this->where($finding->links),
            verify: $problem ? $verify : '',
            reason: $reason,
            actions: array_keys(array_filter([
                self::ACTION_REPAIR  => $problem && $finding->repairable && ($checked === 0),
                self::ACTION_RESOLVE => $problem,
                self::ACTION_RECHECK => $recheck,
            ])),
            finding: $finding,
        );
    }

    /** new, back again or ongoing - measured against the latest daily snapshot */
    private function timeState(Finding $finding): string
    {
        $note = $this->notes[Note::resource('finding', $finding->check, $finding->key)] ?? null;
        if ($note) {
            // it was resolved and no longer is: it came back when the resolution ended, or
            // (an event finding) with the first occurrence after it - assumed the newest
            // one when the first known occurrence is older than the resolution
            $back = (int) $note->until;
            if ($finding->last > 0) {
                $back = max($back, ($finding->since > (int) $note->created) ? $finding->since : $finding->last);
            }
            if ($back > $this->snapshot) {
                return self::REGRESSED;
            }
        }
        if ($finding->check === self::NIGHTLY_CHECK) {
            // compared with the previous nightly run, not the daily snapshot: new when every page
            // of the check type was first seen by the latest run that ran it (NightlyLog)
            return ($finding->since >= $finding->last) ? self::NEW : self::ONGOING;
        }
        if (($this->snapshot === 0) || ($finding->since > $this->snapshot)) {
            return self::NEW;
        }
        [$at, $before] = $this->atSnapshot($finding->check);
        if ($at === null) {
            return self::NEW;
        }
        if ($at['severity'] !== Finding::OK) {
            return self::ONGOING;
        }
        return $before ? self::REGRESSED : self::NEW;
    }

    /** the check's row valid at the snapshot, and whether a problem row came before it
     * @return array{0: ?array{time: int, check: string, severity: string, count: int, title: string}, 1: bool}
     */
    private function atSnapshot(string $check): array
    {
        $at     = null;
        $before = false;
        foreach ($this->history[$check] ?? [] as $row) {
            if ($row['time'] > $this->snapshot) {
                break;
            }
            $before = $before || ($at && ($at['severity'] !== Finding::OK));
            $at     = $row;
        }
        return [$at, $before];
    }

    /** when the check's current run of problem rows began (per check - the snapshot keeps no keys) */
    private function streakStart(string $check): int
    {
        $start = 0;
        foreach ($this->history[$check] ?? [] as $row) {
            if ($row['time'] > $this->snapshot) {
                break;
            }
            $start = ($row['severity'] === Finding::OK) ? 0 : ($start ?: $row['time']);
        }
        return $start;
    }

    private function resolvedItem(Finding $finding, Note $note): StateCheckItem
    {
        $until  = (int) $note->until;
        $who    = ($this->user_name)((string) $note->author);
        $reason = $until ? _m('Marked resolved by %1 until %2.', [$who, date('Y-m-d', $until)]) : _m('Marked resolved by %1 for good.', [$who]);
        $why    = Notes::preview((string) $note->text, 200);
        return new StateCheckItem(
            id: self::anchor($finding->check, $finding->key),
            state: self::MUTED,
            severity: $finding->severity,
            source: ($finding->check === self::NIGHTLY_CHECK) ? self::SOURCE_NIGHTLY : self::SOURCE_STATECHECK,
            check: $finding->check,
            category: $finding->category,
            title: $finding->title,
            advice: $finding->advice,
            count: $finding->count,
            first_seen: $finding->since,
            last_seen: $finding->last,
            where: $this->where($finding->links),
            reason: ($why === '') ? $reason : "$reason $why",
            finding: $finding,
            note: $note,
        );
    }

    /** An extended check that did not run now, as the snapshot last found it - null when that
     *  was no problem, or there is no snapshot. A slow check is never in the snapshot: an old
     *  row of it is from before it was slow, so it is not asked. */
    private function unverifiedItem(string $check, string $category): ?StateCheckItem
    {
        $row = $this->snapshot ? $this->atSnapshot($check)[0] : null;
        if (!$row || ($row['severity'] === Finding::OK)) {
            return null;
        }
        return new StateCheckItem(
            id: self::anchor($check, $check),
            state: self::NOT_VERIFIED,
            severity: $row['severity'],
            source: self::SOURCE_STATECHECK,
            check: $check,
            category: $category,
            title: $row['title'],
            count: $row['count'],
            first_seen: $this->streakStart($check),
            last_seen: $this->snapshot,
            verify: _m('Press Recheck to run this check now.'),
            reason: _m('As of %1.', [date('Y-m-d H:i', $this->snapshot)]),
            actions: in_array($check, $this->recheckable, true) ? [self::ACTION_RECHECK] : [],
        );
    }

    /** the intro of not verified: why the rows are not current, said once for all of them */
    private function notVerifiedIntro(): string
    {
        $parts = [_m('These checks did not run on this load; each row shows the problem as it was last known.')];
        if ($this->snapshot) {
            $parts[] = _m('Extended checks: as the daily snapshot of %1 found them.', [date('Y-m-d H:i', $this->snapshot)]);
        }
        if ($this->nightly !== null) {
            $parts[] = match (true) {
                CheckNightly::isCurrent($this->nightly_full, $this->now) => _m('Nightly test: the types missing from its latest full run, as their own last run found them.'),
                $this->nightly_full > 0 => _m('Nightly test: its latest full run is from %1, older than %2 h, so every type shows its own last run.', [date('Y-m-d H:i', $this->nightly_full), CheckNightly::STALE_AFTER / 3600]),
                default                 => _m('Nightly test: no full run in the last 40 days, so every type shows its own last run.'),
            };
        }
        return implode(' ', $parts);
    }

    /** @param array{passed: string[], no_snapshot: string[], slow: string[], nightly: string[]} $quiet  names of the checks
     *  @return list<array{text: string, count: int}> */
    private function quietLines(array $quiet): array
    {
        $lines = [];
        foreach ($quiet as $kind => $names) {
            if (!$names) {
                continue;
            }
            $n    = count($names);
            $list = implode(', ', $names);
            $text = match ($kind) {
                'passed'      => _m('%1 extended checks did not run now; they passed at the last daily snapshot, %2: %3.', [$n, date('Y-m-d H:i', $this->snapshot), $list]),
                'no_snapshot' => _m('%1 extended checks did not run now, and there is no daily snapshot yet: %2.', [$n, $list]),
                'slow'        => _m('%1 slow checks did not run now; they are never part of the daily snapshot: %2.', [$n, $list]),
                default       => _m('%1 checks of the nightly test found nothing when they last ran: %2.', [$n, $list]),
            };
            $lines[] = ['text' => $text, 'count' => $n];
        }
        return $lines;
    }

    /** Near-duplicates of a section as one group row each: the findings of one check of one kind
     *  (Finding::$kind, else the same advice); the odd ones of a check left over form a group of their own.
     * @param list<StateCheckItem> $items  sorted
     * @return list<StateCheckItem>
     */
    private function group(string $section, array $items): array
    {
        $by_check = [];
        foreach ($items as $item) {
            $by_check[$item->check][] = $item;
        }
        $out = [];
        foreach ($by_check as $check => $list) {
            // a finding of the nightly test is already one type of its checks with the pages inside
            if ((count($list) === 1) || ($check === self::NIGHTLY_CHECK)) {
                array_push($out, ...$list);
                continue;
            }
            $kinds = [];
            foreach ($list as $item) {
                $kinds[($item->kind() !== '') ? "kind\n" . $item->kind() : "advice\n" . $item->advice][] = $item;
            }
            $rest = [];
            foreach ($kinds as $kind) {
                if (count($kind) > 1) {
                    $out[] = $this->groupItem($section, (string) $check, $kind);
                } else {
                    $rest[] = $kind[0];
                }
            }
            if (count($rest) > 1) {
                $out[] = $this->groupItem($section, (string) $check, $rest);
            } elseif ($rest) {
                $out[] = $rest[0];
            }
        }
        usort($out, [self::class, 'compare']);
        return $out;
    }

    /** The group keeps the id of its oldest member (g-… for f-…), so it stays as new members join,
     *  when the group moves from new to ongoing and in any language.
     * @param non-empty-list<StateCheckItem> $members  sorted, worst first */
    private function groupItem(string $section, string $check, array $members): StateCheckItem
    {
        $first  = $members[0];
        $same   = static fn(string $field): string => (count(array_unique(array_map(static fn(StateCheckItem $m): string => $m->$field, $members))) === 1) ? $first->$field : '';
        $states = array_unique(array_map(static fn(StateCheckItem $m) => $m->state, $members));
        $since  = array_filter(array_map(static fn(StateCheckItem $m) => $m->first_seen, $members));
        $kinds  = array_unique(array_map(static fn(StateCheckItem $m): string => $m->kind(), $members));
        $oldest = $members;
        usort($oldest, static fn(StateCheckItem $a, StateCheckItem $b): int => [$a->first_seen ?: PHP_INT_MAX, $a->id] <=> [$b->first_seen ?: PHP_INT_MAX, $b->id]);
        // each member's Recheck runs the check - the group row has none of its own
        $recheck = !array_filter($members, static fn(StateCheckItem $m): bool => !$m->can(self::ACTION_RECHECK));
        return new StateCheckItem(
            id: 'g' . substr($oldest[0]->id, 1),
            state: (count($states) === 1) ? $first->state : (($section === self::NEW) ? self::NEW : $first->state),
            severity: $first->severity,
            source: $first->source,
            check: $check,
            category: $first->category,
            title: self::headline(
                ((count($kinds) === 1) && ($first->kind() !== '')) ? $first->kind() : ($this->names[$check] ?? $check),
                array_map(fn(StateCheckItem $m): string => $this->place($m), $members),
                array_map(static fn(StateCheckItem $m): int => $m->count, $members),
            ),
            advice: $same('advice'),
            count: array_sum(array_map(static fn(StateCheckItem $m) => $m->count, $members)),
            first_seen: $since ? min($since) : 0,
            last_seen: max(array_map(static fn(StateCheckItem $m) => $m->last_seen, $members)),
            verify: $recheck ? _m('After the fix, open a finding below and press its Recheck.') : $same('verify'),
            reason: $same('reason'),
            members: $members,
            holds_now: (bool) array_filter($members, static fn(StateCheckItem $m): bool => $m->holds_now),
        );
    }

    /** The row of a group: the kind of problem, how many findings, and the place that stands out
     *  - "Unsigned expression refused — 7 findings, most often on claude-test-page".
     * @param string       $what    the kind of problem, or the check's name
     * @param list<string> $places  of the members, '' where none is known
     * @param list<int>    $counts  of the members, in the same order
     */
    public static function headline(string $what, array $places, array $counts): string
    {
        $top = self::mostOften($places, $counts);
        return ($top === '')
            ? _m('%1 — %2 findings', [$what, count($places)])
            : _m('%1 — %2 findings, most often on %3', [$what, count($places), $top]);
    }

    /** The place with the most occurrences (a member weighs its count), when it has at least two
     *  and more than any other place; '' when none stands out.
     * @param list<string> $places
     * @param list<int>    $counts
     */
    private static function mostOften(array $places, array $counts): string
    {
        $tally = [];
        foreach ($places as $i => $place) {
            if ($place !== '') {
                $tally[$place] = ($tally[$place] ?? 0) + max(1, $counts[$i] ?? 0);
            }
        }
        arsort($tally);
        [$top, $next] = array_pad(array_values($tally), 2, 0);
        return (($top >= 2) && ($top > $next)) ? (string) array_key_first($tally) : '';
    }

    /** a member's place as a reader knows it: without this installation's own address, never an id */
    private function place(StateCheckItem $item): string
    {
        $place = (string) preg_replace('~^https?://~i', '', $item->finding->place ?? '');
        $own   = (string) preg_replace('~^https?://~i', '', $this->install_url);
        if (str_starts_with($place, $own) && (strlen($place) > strlen($own))) {
            $place = substr($place, strlen($own));
        }
        return preg_match('/[0-9a-f]{32}/i', $place) ? '' : mb_strimwidth($place, 0, self::PLACE_MAX, '…', 'UTF-8');
    }

    /** @param array<string, string> $links label => url, as Finding keeps them
     *  @return list<array{label: string, url: string}> absolute urls - the mail is read elsewhere */
    private function where(array $links): array
    {
        $origin = (string) preg_replace('~^(https?://[^/]+).*$~i', '$1', $this->install_url);
        $where  = [];
        foreach ($links as $label => $url) {
            $url = (string) $url;
            if (!preg_match('~^https?://~i', $url)) {
                $url = str_starts_with($url, '/') ? $origin . $url : $this->install_url . 'admin/' . $url;
            }
            $where[] = ['label' => (string) $label, 'url' => $url];
        }
        return $where;
    }

    /** a description of the installation (versions, settings), not something to fix - an
     *  error or warning of the environment category is a problem like any other */
    private static function isEnvironment(Finding $finding): bool
    {
        return ($finding->category === self::ENVIRONMENT) && in_array($finding->severity, [Finding::INFO, Finding::OK], true);
    }

    private static function sectionOf(string $state): string
    {
        return ($state === self::REGRESSED) ? self::NEW : $state;
    }

    /** worst first, then the most frequent; stable otherwise */
    private static function compare(StateCheckItem $a, StateCheckItem $b): int
    {
        return (Finding::rank($a->severity) <=> Finding::rank($b->severity)) ?: ($b->count <=> $a->count);
    }
}
