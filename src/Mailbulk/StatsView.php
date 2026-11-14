<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA_Collection;

/**
 * One block of the statistics of one sending: its runs with what came of them and, when the list has
 * a statistics slice, how many opened and clicked and which links. The same block on the Alerts page
 * and under the control of the sending. Rights are not checked here - the caller has.
 */
class StatsView
{
    /** @return string HTML; '' when the item was never sent to the list */
    public function mailing(AA_Collection $list, string $itemId): string
    {
        $itemId = strtolower($itemId);
        if (!is_long_id($itemId)) {
            return '';
        }
        $campaign = $this->campaign($list->getId(), $itemId);
        $runs     = $campaign->runs();
        if (!$runs) {
            return '';
        }
        $html = '<h3 class="aa-mailbulk-title">' . safe(self::title($runs, $itemId)) . '</h3>' . self::runsTable($runs, $campaign->counts());

        $statSlice = $list->getStatSliceId();
        if ($statSlice !== '') {
            $stats   = $this->stats();
            $counts  = $stats->counts($statSlice, $itemId);
            $html   .= '<p class="aa-mailbulk-text">' . safe(_m('Opened %1, clicked %2', [$counts['opened'], $counts['clicked']])) . '</p>';
            $html   .= self::linksTable($stats->links($statSlice, $itemId));
        }
        return '<div class="aa-mailbulk-stats">' . $html . '</div>';
    }

    // ------------------------------------------------------------ what tests replace

    protected function campaign(string $list, string $itemId): Campaign
    {
        return Campaign::of($list, $itemId);
    }

    protected function stats(): Stats
    {
        return new Stats();
    }

    // ------------------------------------------------------------ rendering

    /** the name the newest run was started with - the item may have changed or be gone since
     *  @param list<Run> $runs */
    private static function title(array $runs, string $itemId): string
    {
        foreach (array_reverse($runs) as $run) {
            $title = trim((string) $run->getProperty('title'));
            if ($title !== '') {
                return $title;
            }
        }
        return $itemId;
    }

    /** each run, and with more of them the sending as a whole - each reader counted once
     *  @param list<Run> $runs
     *  @param array{total: int, sent: int, failed: int, skipped: int, uncertain: int, date: int} $counts */
    private static function runsTable(array $runs, array $counts): string
    {
        $head = '';
        foreach ([_m('Started'), _m('Finished'), _m('State'), _m('Recipients'), _m('Sent'), _m('Failed'), _m('Skipped'), _m('Uncertain')] as $label) {
            $head .= '<th scope="col">' . safe($label) . '</th>';
        }
        $body = '';
        foreach ($runs as $run) {
            $outcomes = $run->outcomes();
            $cells    = [
                self::date((int) $run->getProperty('started')),
                self::date((int) $run->getProperty('finished')),
                self::state($run->state()),
                (string) (int) $run->getProperty('total'),
                (string) $outcomes['sent'],
                (string) $outcomes['failed'],
                (string) $outcomes['skipped'],
                (string) $outcomes['uncertain'],
            ];
            $body .= '<tr><td>' . implode('</td><td>', array_map(safe(...), $cells)) . '</td></tr>';
        }
        $foot = '';
        if (count($runs) > 1) {
            $sums = [$counts['total'], $counts['sent'], $counts['failed'], $counts['skipped'], $counts['uncertain']];
            $foot = '<tfoot><tr><th scope="row" colspan="3">' . safe(_m('Total')) . '</th><td>' . implode('</td><td>', $sums) . '</td></tr></tfoot>';
        }
        return '<table class="aa-mailbulk-runs"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody>' . $foot . '</table>';
    }

    /** @param list<array{url: string, readers: int}> $links */
    private static function linksTable(array $links): string
    {
        if (!$links) {
            return '';
        }
        $rows = '';
        foreach ($links as $link) {
            $url = safe($link['url']);
            // only a web address becomes a link: an event item may be edited by hand
            $shown = preg_match('~^https?://~i', $link['url']) ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' : $url;
            $rows .= '<tr><td>' . $shown . '</td><td>' . $link['readers'] . '</td></tr>';
        }
        return '<table class="aa-mailbulk-links"><thead><tr><th scope="col">' . safe(_m('Link')) . '</th><th scope="col">' . safe(_m('Readers clicked')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private static function date(int $time): string
    {
        return ($time > 0) ? date('Y-m-d H:i', $time) : '';
    }

    /** the name of a run state for people */
    public static function state(string $state): string
    {
        return match ($state) {
            Run::SENDING => _m('Sending'),
            Run::ERROR   => _m('Stopped on an error'),
            Run::DONE    => _m('Done'),
            Run::ENDED   => _m('Stopped'),
            default      => $state,
        };
    }
}
