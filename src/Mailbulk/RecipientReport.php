<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Later\LogCleanup;
use AA_Collection;
use AA_Logentry;
use AA_Logentry_Mailid;
use zids;

/**
 * Who one sending went to: each reader of its runs once, with what came of the mail in the last run they
 * were in, when it went and - when that run tracked its mails - when they first opened it and what they
 * clicked. A filter needs the whole set, so everything is read in a few batched reads, never per reader.
 * Rights are not checked here - the caller has.
 * @phpstan-type Click array{url: string, time: int}
 * @phpstan-type RecipientRow array{reader: string, email: string, gone: bool, outcome: string, sent: int,
 *               logged: bool, tracked: bool, opened: int, clicks: list<Click>}
 */
class RecipientReport
{
    public const ALL      = 'all';
    public const OPENED   = 'opened';
    public const UNOPENED = 'unopened';
    public const CLICKED  = 'clicked';
    /** failed or uncertain */
    public const FAILED   = 'failed';

    /** rows on one page of the table */
    public const PER_PAGE = 100;
    /** the heading of the table on the page (mailings.php) - the links of the filters and the pages lead to it */
    public const ANCHOR = '#aa-recipients';
    /** readers whose addresses one GetItemContent() call reads */
    private const CHUNK = 500;
    /** in front of a time that is the start of the run, not of the mail */
    private const ROUGHLY = '≈ ';
    /** the start of a CSV file that tells a spreadsheet it is UTF-8 */
    private const BOM = "\u{FEFF}";

    /** @var ?list<RecipientRow> read once for all filters */
    private ?array $rows = null;
    private ?bool $measured = null;

    public function __construct(private readonly AA_Collection $list, private readonly Campaign $campaign) {}

    /** opens and clicks are measured: the list has a statistics slice and a run of the sending tracked its mails */
    public function measured(): bool
    {
        return $this->measured ??= ($this->list->getStatSliceId() !== '') && (bool) array_filter($this->campaign->runs(), self::tracked(...));
    }

    /** @return list<string> the filters that make sense for the sending */
    public function filters(): array
    {
        return array_keys($this->filterNames());
    }

    /** $asked when it is one of filters(), else ALL */
    public function filter(string $asked): string
    {
        return in_array($asked, $this->filters(), true) ? $asked : self::ALL;
    }

    /**
     * The recipients $filter keeps, by address. sent = when the mail went (the last attempt in the log), or the
     * start of the run when the log has no row (it keeps LogCleanup::KEEP_DAYS days) - logged says which; 0 = no
     * mail went (skipped, not tried yet, or the mail could not be made). opened = the first open or click, 0 = none;
     * a row of a run that tracked nothing (tracked false) has neither and no filter of them keeps it.
     * @return list<RecipientRow> email '' = the reader has no address, gone = the reader is deleted;
     *         outcome '' = not tried yet
     */
    public function rows(string $filter = self::ALL): array
    {
        $rows = $this->rows ??= $this->allRows();
        if ($filter === self::ALL) {
            return $rows;
        }
        $sent = Outcome::Sent->value;
        return array_values(array_filter($rows, static fn(array $row): bool => match ($filter) {
            // a click stores an open too; should the open be gone, the click still shows the mail was read
            self::OPENED   => $row['opened'] > 0,
            self::UNOPENED => $row['tracked'] && ($row['outcome'] === $sent) && ($row['opened'] === 0),
            self::CLICKED  => (bool) $row['clicks'],
            self::FAILED   => in_array($row['outcome'], [Outcome::Failed->value, Outcome::Uncertain->value], true),
            default        => true,
        }));
    }

    /** @param list<RecipientRow> $rows what rows() gave
     *  @param int $page the page of PER_PAGE rows to show, from 1 */
    public function table(array $rows, int $page = 1): string
    {
        $head = '';
        foreach ($this->labels() as $label) {
            $head .= '<th scope="col">' . safe($label) . '</th>';
        }
        $roughly = safe(_m('When the run started: the log keeps the time of each mail for %1 days', [LogCleanup::KEEP_DAYS]));
        $body    = '';
        foreach (array_slice($rows, max(0, $page - 1) * self::PER_PAGE, self::PER_PAGE) as $row) {
            $sent  = safe(StatsView::date($row['sent']));
            $cells = [
                $row['gone'] ? '<em>' . safe(_m('Not in the list any more')) . '</em>' : safe($row['email']),
                safe(StatsView::outcome($row['outcome'])),
                self::roughly($row) ? '<span title="' . $roughly . '">' . self::ROUGHLY . "$sent</span>" : $sent,
            ];
            if ($this->measured()) {
                $clicks = '';
                foreach ($row['clicks'] as $click) {
                    $clicks .= '<li>' . safe(StatsView::date($click['time'])) . ' ' . StatsView::link($click['url']) . '</li>';
                }
                $cells[] = safe(StatsView::date($row['opened']));
                $cells[] = ($clicks === '') ? '' : "<ul>$clicks</ul>";
            }
            $body .= '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
        }
        return '<table class="aa-table aa-mailbulk-recipients"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** the filters as links, the current one not, and the CSV of the current one
     *  @param string $url the page with the chosen mailing */
    public function filterBar(string $current, string $url): string
    {
        $links = [];
        foreach ($this->filterNames() as $filter => $name) {
            $links[] = ($filter === $current)
                ? '<strong aria-current="true">' . safe($name) . '</strong>'
                : '<a href="' . safe(get_url($url, ['rcpt' => $filter]) . self::ANCHOR) . '">' . safe($name) . '</a>';
        }
        $csv = '<a href="' . safe(get_url($url, ['rcpt' => $current, 'csv' => 1]) . self::ANCHOR) . '">' . safe(_m('Download CSV')) . '</a>';
        return '<p class="aa-mailbulk-filter">' . implode(' | ', $links) . ' &ndash; ' . $csv . '</p>';
    }

    /** the note under the table */
    public function note(): string
    {
        $text = _m('These are personal data: the privacy notice of the web must mention them.');
        if ($this->measured()) {
            $text .= ' ' . _m('Opens and clicks are indicative: image proxies and link checkers open mails and follow links on their own.');
        }
        return '<p class="aa-mailbulk-text">' . safe($text) . '</p>';
    }

    /** the rows $filter keeps as CSV into $out: a BOM, the head and one line per reader, the clicks one per line of
     *  their cell; a time from the start of the run marked as in the table
     *  @param resource $out */
    public function csv(string $filter, $out): void
    {
        fwrite($out, self::BOM);
        fputcsv($out, array_map(self::csvCell(...), $this->labels()), ',', '"', '');
        foreach ($this->rows($filter) as $row) {
            $sent = StatsView::date($row['sent']);
            $line = [$row['email'], StatsView::outcome($row['outcome']), self::roughly($row) ? self::ROUGHLY . $sent : $sent];
            if ($this->measured()) {
                $clicks = array_map(static fn(array $c): string => StatsView::date($c['time']) . ' ' . $c['url'], $row['clicks']);
                array_push($line, StatsView::date($row['opened']), implode("\n", $clicks));
            }
            fputcsv($out, array_map(self::csvCell(...), $line), ',', '"', '');
        }
    }

    /** a cell a spreadsheet would run as a formula becomes text (OWASP: CSV injection) */
    public static function csvCell(string $value): string
    {
        return (($value !== '') && str_contains("=+-@\t\r", $value[0])) ? "'$value" : $value;
    }

    // ------------------------------------------------------------ what tests replace

    /** @param list<string> $ids long ids of readers
     *  @return array<string, string> long id => address; a reader gone is not in it */
    protected function emails(array $ids): array
    {
        $emails = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            // a reader slice may have a reading password (as in getReadersSelectArray()); the caller checked the rights
            foreach (GetItemContent(new zids($chunk, 'l'), false, true, [FIELDID_EMAIL]) as $id => $content) {
                $emails[(string) $id] = trim((string) ($content[FIELDID_EMAIL][0]['value'] ?? ''));
            }
        }
        return $emails;
    }

    /** the MAIL_SEND rows of the mails of a run - AaMailer names the run in the selector, so the index
     *  type_selector_time finds them by its start
     *  @return list<array{time: int|string, selector: string}> */
    protected function sendLog(string $runId): array
    {
        return DB_AA::select([], 'SELECT `time`, `selector` FROM `log`', [['type', 'MAIL_SEND'], ['selector', \AA_Mail::logSelectorStart($runId), 'BEGIN']]);
    }

    protected function stats(): Stats
    {
        return new Stats();
    }

    // ------------------------------------------------------------

    /** @return array<string, string> filter => its name; those that make sense for the sending */
    private function filterNames(): array
    {
        $names = [self::ALL => _m('All')];
        if ($this->measured()) {
            $names += [self::OPENED => _m('Opened'), self::UNOPENED => _m('Not opened'), self::CLICKED => _m('Clicked')];
        }
        return $names + [self::FAILED => _m('Failed or uncertain')];
    }

    /** @return list<string> the head of the table and of the CSV */
    private function labels(): array
    {
        $labels = [_m('E-mail'), _m('Result'), _m('Time sent')];
        return $this->measured() ? [...$labels, _m('First opened'), _m('Clicked links')] : $labels;
    }

    /** @return list<RecipientRow> */
    private function allRows(): array
    {
        $readers = $this->campaign->readers();
        if (!$readers) {
            return [];
        }
        // the readers a mail went to (or may have gone to), by run - a mail that could not be made never went
        $went    = [];
        $notMade = [];
        $tracked = [];
        foreach ($readers as $id => ['outcome' => $outcome, 'run' => $run]) {
            $runId = $run->getId();
            if (self::mailWent($outcome) && !isset(($notMade[$runId] ??= $run->notMadeFor())[$id])) {
                $went[$runId][$id] = true;
            }
            if ($this->measured() && self::tracked($run)) {
                $tracked[] = $id;
            }
        }
        $emails = $this->emails(array_keys($readers));
        $times  = $this->sendTimes($went);
        [$opens, $clicks] = $tracked ? $this->events($tracked) : [[], []];
        $tracked = array_fill_keys($tracked, true);

        $rows = $gone = $addresses = $ids = [];
        foreach ($readers as $id => ['outcome' => $outcome, 'run' => $run]) {
            $first       = $clicks[$id][0]['time'] ?? 0;
            $open        = $opens[$id] ?? 0;
            $rows[]      = [
                'reader'  => $id,
                'email'   => $emails[$id] ?? '',
                'gone'    => !isset($emails[$id]),
                'outcome' => $outcome,
                'sent'    => $times[$id] ?? (isset($went[$run->getId()][$id]) ? (int) $run->getProperty('started') : 0),
                'logged'  => isset($times[$id]),
                'tracked' => isset($tracked[$id]),
                // a click without the open: the mail was read by then
                'opened'  => (($open > 0) && ($first > 0)) ? min($open, $first) : max($open, $first),
                'clicks'  => $clicks[$id] ?? [],
            ];
            $gone[]      = isset($emails[$id]) ? 0 : 1;
            $addresses[] = strtolower($emails[$id] ?? '');
            $ids[]       = $id;
        }
        unset($emails, $times, $opens, $clicks, $went, $tracked);
        // by address; the readers gone last
        array_multisort($gone, SORT_NUMERIC, $addresses, SORT_STRING, $ids, SORT_STRING, $rows);
        return $rows;
    }

    /**
     * When the mail of each reader went: the last attempt (a retry first fails) among the MAIL_SEND rows of the
     * run the reader was last in. A run made before the log named runs has none.
     * @param array<string, array<string, true>> $went run id => the readers a mail went to in it
     * @return array<string, int> long id => time; a reader without a row is not in it
     */
    private function sendTimes(array $went): array
    {
        $times = [];
        foreach ($went as $runId => $ids) {
            foreach ($this->sendLog((string) $runId) as $row) {
                $entry = AA_Logentry::factoryByStringReversed((string) $row['selector']);
                if (($entry instanceof AA_Logentry_Mailid) && isset($ids[$entry->reader])) {
                    $times[$entry->reader] = max($times[$entry->reader] ?? 0, (int) $row['time']);
                }
            }
        }
        return $times;
    }

    /** The opens and clicks of the readers of the runs that tracked: the opens computed, the clicks those Stats
     *  read for the figures above the table anyway.
     *  @param list<string> $readers
     *  @return array{array<string, int>, array<string, list<Click>>} reader => the time of the open;
     *          reader => the clicks, the first first */
    private function events(array $readers): array
    {
        $stats  = $this->stats();
        $slice  = $this->list->getStatSliceId();
        $opens  = $stats->openTimes($slice, $this->list->getId(), $this->campaign->itemId(), $readers);
        $all    = $stats->clicks($slice, $this->campaign->itemId());
        $ours   = array_fill_keys($readers, true);
        $times  = array_column($all, 'time');
        $urls   = array_column($all, 'url');
        array_multisort($times, SORT_NUMERIC, $urls, SORT_STRING, $all);
        $clicks = [];
        foreach ($all as $click) {
            $reader = strtolower($click['reader']);
            if (isset($ours[$reader])) {
                $clicks[$reader][] = ['url' => $click['url'], 'time' => $click['time']];
            }
        }
        return [$opens, $clicks];
    }

    /** the run tracked its mails - the base of the tracked addresses was set when it started */
    private static function tracked(Run $run): bool
    {
        return (string) $run->getProperty('base') !== '';
    }

    /** a mail went, or may have gone */
    private static function mailWent(string $outcome): bool
    {
        return in_array($outcome, [Outcome::Sent->value, Outcome::Failed->value, Outcome::Uncertain->value], true);
    }

    /** @param RecipientRow $row
     *  @return bool its time is the start of the run - the log has no row of the mail */
    private static function roughly(array $row): bool
    {
        return ($row['sent'] > 0) && !$row['logged'];
    }
}
