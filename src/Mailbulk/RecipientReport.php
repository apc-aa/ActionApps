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
 * were in, when it went and - when the list has a statistics slice - when they first opened it and what
 * they clicked. A filter needs the whole set, so everything is read in a few batched reads, never per
 * reader. Rights are not checked here - the caller has.
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
    /** readers whose addresses one GetItemContent() call reads */
    private const CHUNK = 500;
    /** in front of a time that is the start of the run, not of the mail */
    private const ROUGHLY = '≈ ';
    /** the start of a CSV file that tells a spreadsheet it is UTF-8 */
    private const BOM = "\u{FEFF}";

    /** @var ?list<array{reader: string, email: string, outcome: string, sent: int, logged: bool, opened: int,
     *        clicks: list<array{url: string, time: int}>}> read once for all filters */
    private ?array $rows = null;

    public function __construct(private readonly AA_Collection $list, private readonly Campaign $campaign) {}

    /** the list has a statistics slice: opens and clicks are measured */
    public function measured(): bool
    {
        return $this->list->getStatSliceId() !== '';
    }

    /** @return list<string> the filters that make sense for the list */
    public function filters(): array
    {
        return $this->measured() ? [self::ALL, self::OPENED, self::UNOPENED, self::CLICKED, self::FAILED] : [self::ALL, self::FAILED];
    }

    /** $asked when it is one of filters(), else ALL */
    public function filter(string $asked): string
    {
        return in_array($asked, $this->filters(), true) ? $asked : self::ALL;
    }

    /**
     * The recipients $filter keeps, by address. sent = when the mail went (the last attempt in the log), or the
     * start of the run when the log has no row (it keeps LogCleanup::KEEP_DAYS days) - logged says which; 0 = no
     * mail went (skipped, not tried yet, or the mail could not be made).
     * @return list<array{reader: string, email: string, outcome: string, sent: int, logged: bool, opened: int,
     *         clicks: list<array{url: string, time: int}>}> email '' = the reader is gone; outcome '' = not tried yet
     */
    public function rows(string $filter = self::ALL): array
    {
        $rows = $this->rows ??= $this->allRows();
        $sent = Outcome::Sent->value;
        return array_values(array_filter($rows, static fn(array $row): bool => match ($filter) {
            self::OPENED   => $row['opened'] > 0,
            // a click records an open too; should the open be gone, the click still shows the mail was read
            self::UNOPENED => ($row['outcome'] === $sent) && ($row['opened'] === 0) && !$row['clicks'],
            self::CLICKED  => (bool) $row['clicks'],
            self::FAILED   => in_array($row['outcome'], [Outcome::Failed->value, Outcome::Uncertain->value], true),
            default        => true,
        }));
    }

    /** @param list<array<string, mixed>> $rows what rows() gave
     *  @param int $page the page of PER_PAGE rows to show, from 1 */
    public function table(array $rows, int $page = 1): string
    {
        $head = '';
        foreach ($this->labels() as $label) {
            $head .= '<th scope="col">' . safe($label) . '</th>';
        }
        $body = '';
        foreach (array_slice($rows, max(0, $page - 1) * self::PER_PAGE, self::PER_PAGE) as $row) {
            $sent  = safe(StatsView::date($row['sent']));
            $cells = [
                ($row['email'] === '') ? '<em>' . safe(_m('Not in the list any more')) . '</em>' : safe($row['email']),
                safe(self::outcome($row['outcome'])),
                (($row['sent'] > 0) && !$row['logged']) ? '<span title="' . safe(_m('When the run started: the log keeps the time of each mail for %1 days', [LogCleanup::KEEP_DAYS])) . '">' . self::ROUGHLY . "$sent</span>" : $sent,
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
        return '<table class="aa-mailbulk-recipients"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** the filters as links, the current one not, and the CSV of the current one
     *  @param string $url the page with the chosen mailing */
    public function filterBar(string $current, string $url): string
    {
        $names = [
            self::ALL      => _m('All'),
            self::OPENED   => _m('Opened'),
            self::UNOPENED => _m('Not opened'),
            self::CLICKED  => _m('Clicked'),
            self::FAILED   => _m('Failed or uncertain'),
        ];
        $links = [];
        foreach ($this->filters() as $filter) {
            $links[] = ($filter === $current)
                ? '<strong aria-current="true">' . safe($names[$filter]) . '</strong>'
                : '<a href="' . safe(get_url($url, ['rcpt' => $filter])) . '">' . safe($names[$filter]) . '</a>';
        }
        $csv = '<a href="' . safe(get_url($url, ['rcpt' => $current, 'csv' => 1])) . '">' . safe(_m('Download CSV')) . '</a>';
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

    /** $rows as CSV into $out: a BOM, the head and one line per reader, the clicks one per line of their cell
     *  @param list<array<string, mixed>> $rows what rows() gave
     *  @param resource $out */
    public function csv(array $rows, $out): void
    {
        fwrite($out, self::BOM);
        fputcsv($out, array_map(self::csvCell(...), $this->labels()), ',', '"', '');
        foreach ($rows as $row) {
            $sent = StatsView::date($row['sent']);
            $line = [$row['email'], self::outcome($row['outcome']), (($row['sent'] > 0) && !$row['logged']) ? self::ROUGHLY . $sent : $sent];
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

    /** the name of an outcome for people */
    public static function outcome(string $outcome): string
    {
        return match ($outcome) {
            Outcome::Sent->value      => _m('Sent'),
            Outcome::Failed->value    => _m('Failed'),
            Outcome::Skipped->value   => _m('Skipped'),
            Outcome::Uncertain->value => _m('Uncertain'),
            ''                        => _m('Not sent yet'),
            default                   => $outcome,
        };
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

    /** @return list<string> the head of the table and of the CSV */
    private function labels(): array
    {
        $labels = [_m('E-mail'), _m('Result'), _m('Time sent')];
        return $this->measured() ? [...$labels, _m('First opened'), _m('Clicked links')] : $labels;
    }

    /** @return list<array{reader: string, email: string, outcome: string, sent: int, logged: bool, opened: int,
     *          clicks: list<array{url: string, time: int}>}> */
    private function allRows(): array
    {
        $readers = $this->campaign->readers();
        if (!$readers) {
            return [];
        }
        $emails = $this->emails(array_map('strval', array_keys($readers)));
        $logged = $this->sendTimes($readers);
        $events = $this->measured() ? $this->stats()->readers($this->list->getStatSliceId(), $this->campaign->itemId()) : [];
        $unmade = [];
        $rows   = [];
        foreach ($readers as $id => ['outcome' => $outcome, 'run' => $run]) {
            $id     = (string) $id;
            $time   = $logged[$id] ?? 0;
            $tried  = self::mailWent($outcome) && !isset(($unmade[$run->getId()] ??= $run->notMadeFor())[$id]);
            $rows[] = [
                'reader'  => $id,
                'email'   => $emails[$id] ?? '',
                'outcome' => $outcome,
                'sent'    => ($time > 0) ? $time : ($tried ? (int) $run->getProperty('started') : 0),
                'logged'  => $time > 0,
                'opened'  => $events[strtolower($id)]['opened'] ?? 0,
                'clicks'  => $events[strtolower($id)]['clicks'] ?? [],
            ];
        }
        // by address; the readers gone last
        usort($rows, static fn(array $a, array $b): int => [$a['email'] === '', strtolower($a['email']), $a['reader']] <=> [$b['email'] === '', strtolower($b['email']), $b['reader']]);
        return $rows;
    }

    /**
     * When the mail of each reader went: the last attempt (a retry first fails) among the MAIL_SEND rows of the
     * run the reader was last in. A run made before the log named runs has none.
     * @param array<string, array{outcome: string, run: Run}> $readers
     * @return array<string, int> long id => time; a reader without a row is not in it
     */
    private function sendTimes(array $readers): array
    {
        $runs = [];
        foreach ($readers as $id => ['outcome' => $outcome, 'run' => $run]) {
            if (self::mailWent($outcome)) {
                $runs[$run->getId()][$id] = true;
            }
        }
        $times = [];
        foreach ($runs as $runId => $ids) {
            foreach ($this->sendLog((string) $runId) as $row) {
                $entry = AA_Logentry::factoryByStringReversed((string) $row['selector']);
                if (($entry instanceof AA_Logentry_Mailid) && isset($ids[$entry->reader])) {
                    $times[$entry->reader] = max($times[$entry->reader] ?? 0, (int) $row['time']);
                }
            }
        }
        return $times;
    }

    /** a mail went, or may have gone */
    private static function mailWent(string $outcome): bool
    {
        return in_array($outcome, [Outcome::Sent->value, Outcome::Failed->value, Outcome::Uncertain->value], true);
    }
}
