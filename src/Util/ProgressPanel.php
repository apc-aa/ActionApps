<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * What the two progress panels have in common.
 *
 * VersionProgress compares page speed and TaskProgress queue durations, but both
 * ask it of the same measurement windows and both answer it the same way: a
 * percentage is never a claim on its own, it is highlighted only once a sign test
 * over the cohort backs it. The table shape, the coloured deltas and the
 * "n of m got faster" cell therefore live here rather than twice over, so the two
 * panels cannot drift into saying the same thing differently.
 *
 * The CSS class prefix is `aa-ver-`, from the version panel this vocabulary was
 * first written for.
 */
final class ProgressPanel
{
    /**
     * Whether a split this lopsided would be surprising from coin flips: the
     * two-sigma bound of a binomial sign test, which at 17 members means 13 of
     * them have to agree.
     *
     * @param array{faster:int,total:int} $agreement
     */
    public static function isSignificant(array $agreement): bool
    {
        $n = $agreement['total'];
        return $n >= 5 && abs((2 * $agreement['faster']) - $n) >= 2 * sqrt($n);
    }

    /**
     * How many of the cohort moved the same way as the window as a whole. A sign
     * test over cohort members is what tells a real move from noise here: the
     * percentage can be carried by one busy member, agreement cannot.
     *
     * @param  array<string,array<int,?float>> $values  member => window index => measurement
     * @param  list<string>                    $cohort  members allowed to speak
     * @return array{faster:int,total:int}
     */
    public static function agreement(array $values, array $cohort, int $i): array
    {
        $faster = $total = 0;
        foreach ($cohort as $member) {
            $now  = $values[$member][$i] ?? null;
            $prev = $values[$member][$i - 1] ?? null;
            if ($now === null || $prev === null || $now === $prev) {
                continue;
            }
            $total++;
            $faster += ($now < $prev) ? 1 : 0;
        }
        return ['faster' => $faster, 'total' => $total];
    }

    /**
     * The configuration a window ended up running: the last deployment inside it,
     * or the one in force when it opened for a window cut at a time rather than at
     * a change. Null for the leading window of an install with no history before
     * it, and for "there is no window above this row".
     *
     * @param  array<string,mixed>|null $interval
     * @return array<string,mixed>|null
     */
    public static function lastChange(?array $interval): ?array
    {
        $changes = $interval['changes'] ?? [];
        return $changes ? $changes[count($changes) - 1] : ($interval['running'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** A short explanation in place of a panel, for the states where there is nothing to compare yet. */
    public static function note(string $text): string
    {
        return '<p class="aa-ver-note">' . \safe($text) . '</p>';
    }

    /**
     * The line above a table: what it rests on, and how to read it.
     *
     * @param string $scope_html  what the table rests on, ready-escaped because one panel links it
     */
    public static function head(string $scope_html, string $hint): string
    {
        return '<div class="aa-ver-head">'
             . '<span class="aa-ver-scope">' . $scope_html . '</span>'
             . '<span class="aa-ver-hint">' . \safe($hint) . '</span>'
             . '</div>';
    }

    /**
     * Table opening, header row included.
     *
     * @param list<array{0:string,1?:string}> $columns  label, and the tooltip explaining it
     */
    public static function tableOpen(array $columns): string
    {
        $h = '<div class="aa-stat-wrap"><table class="aa-stat-table aa-ver-table"><thead><tr>';
        foreach ($columns as $column) {
            $title = $column[1] ?? '';
            $h .= '<th' . ($title === '' ? '' : ' title="' . \safe($title) . '"') . '>' . \safe($column[0]) . '</th>';
        }
        return $h . '</tr></thead><tbody>';
    }

    public static function tableClose(): string
    {
        return '</tbody></table></div>';
    }

    /**
     * The two cells every progress row opens with: since when this configuration
     * has been running, and what it changed against the window above.
     *
     * @param array<string,mixed>      $interval
     * @param array<string,mixed>|null $previous  the window above this row
     * @param string                   $title     what the window rests on, as a tooltip
     */
    public static function whenCells(array $interval, ?array $previous, string $title): string
    {
        $diff = ConfigTimeline::describeDiff($interval['changes'], self::lastChange($previous));

        // The window's own start, not the moment the change was logged: on whole-day
        // data the change day belongs to no window, so a deployment detected on the 7th
        // opens a window on the 8th, and the leading window starts with the measured
        // period. The duration and the tooltip are measured from `from` too. A window
        // cut at the change itself (exact data, the last week) shows the time as well.
        $h  = '<td class="aa-ver-when" title="' . \safe($title) . '">'
            . \safe(date($interval['exact'] ? 'Y-m-d H:i' : 'Y-m-d', $interval['from']))
            . '<span class="aa-ver-days">' . \safe(self::spanLabel($interval['span'])) . '</span>'
            . '</td>';

        // Nothing to name: the leading window of an install without history, or a
        // window cut at a time, which measures the deployment before it cleanly.
        if (!$diff) {
            $diff = [[$previous ? \_m('unchanged') : \_m('first window'), '']];
        }
        $h .= '<td class="aa-ver-changed">';
        foreach ($diff as [$tag, $tag_title]) {
            $h .= '<span class="aa-ver-tag"' . ($tag_title === '' ? '' : ' title="' . \safe($tag_title) . '"') . '>' . \safe($tag) . '</span>';
        }
        return $h . '</td>';
    }

    /** A window's tooltip: when it ran, and which part of it is measured. */
    public static function spanTitle(array $interval): string
    {
        $format = $interval['exact'] ? 'Y-m-d H:i' : 'Y-m-d';
        $title  = sprintf(
            \_m('Running %1$s to %2$s (%3$s).'),
            date($format, $interval['from']),
            date($format, max($interval['from'], $interval['to'] - 1)),
            self::spanLabel($interval['span']),
        );
        if ($interval['short']) {
            return $title;
        }
        // With the weekday: a weekend and a working day load the server differently,
        // and two windows a few days apart can easily be one of each.
        return $title . ' ' . sprintf(
            \_m('Measured %1$s to %2$s, its freshest whole days.'),
            date('D Y-m-d H:i', $interval['measure_from']),
            date('D Y-m-d H:i', $interval['measure_to']),
        );
    }

    /** How long a window ran, in the unit that reads best: "40 min", "14 h", "6 days". */
    public static function spanLabel(int $span): string
    {
        if ($span < 3600) {
            return sprintf(\_m('%d min'), intdiv($span, 60));
        }
        if ($span < 2 * ConfigTimeline::DAY) {
            return sprintf(\_m('%d h'), (int) round($span / 3600));
        }
        return sprintf(\_m('%d days'), (int) round($span / ConfigTimeline::DAY));
    }

    /**
     * The measured cells of a row whose window has not run long enough to say
     * anything yet, as one cell. It is listed anyway: it is what is running now.
     */
    public static function tooEarlyCell(int $colspan): string
    {
        return '<td class="aa-ver-early" colspan="' . $colspan . '">'
             . \safe(sprintf(
                 \_m('Too early to measure. It is compared once it has run for %s.'),
                 self::spanLabel(ConfigTimeline::MIN_SPAN),
             ))
             . '</td>';
    }

    /**
     * The move against the window above, in percent.
     *
     * Colour is a claim about reality, so it waits for the sign test the caller
     * passes in as $real; the number is always shown, greyed, so nothing is hidden.
     */
    public static function delta(?float $delta, bool $real, string $title_real, string $title_flat): string
    {
        if ($delta === null) {
            return '';
        }
        $class = !$real ? 'aa-ver-delta--flat' : ($delta < 0 ? 'aa-ver-delta--good' : 'aa-ver-delta--bad');
        return '<span class="aa-ver-delta ' . $class . '" title="' . \safe($real ? $title_real : $title_flat) . '">'
             . \safe(sprintf('%+.0f%%', $delta)) . '</span>';
    }

    /**
     * The sign test as a cell: "13/17", highlighted when that many agreeing is
     * more than coin flips would give.
     *
     * @param array{faster:int,total:int}|null $agreement
     */
    public static function agreeCell(?array $agreement): string
    {
        if (!$agreement || !$agreement['total']) {
            return '<td class="aa-stat-zero">-</td>';
        }
        return '<td' . (self::isSignificant($agreement) ? ' class="aa-ver-agree"' : '') . '>'
             . \safe($agreement['faster'] . '/' . $agreement['total']) . '</td>';
    }

    /** The styling both panels' tables share. */
    public static function css(): string
    {
        return '<style>'
            . '.aa-ver-head{display:flex;flex-wrap:wrap;gap:.4em 1.5em;margin:0 0 .5em;font-size:90%;color:var(--clr-text-accent,#6a5e22)}'
            . '.aa-ver-hint{color:#555}'
            . '.aa-ver-note{margin:.5em 0;color:#555;max-width:70ch;line-height:1.5}'
            . '.aa-ver-table td{vertical-align:top}'
            /* the first two columns read as text, not as figures - header and cells alike */
            . '.aa-ver-table th:nth-child(2),.aa-ver-table td:first-child,.aa-ver-table td:nth-child(2){text-align:left}'
            . '.aa-ver-when{white-space:nowrap}'
            . '.aa-ver-days{display:block;font-size:85%;color:#666}'
            /* .aa-stat-table td keeps every cell on one line; this one holds a list */
            . '.aa-ver-table td.aa-ver-changed{white-space:normal;max-width:26ch}'
            /* a tag may be a list of revisions a window swallowed, so it wraps */
            . '.aa-ver-tag{display:inline-block;background:var(--clr-basic-light,#eeebe3);padding:0 .3em;margin:0 .3em .15em 0}'
            /* A move past the noise is a filled chip, so it stands out in a column of
               greyed numbers - faster and slower alike. The colours are the admin diff
               pair (inserted / deleted), which read at 8:1 and more. The muted state
               stays legible on purpose: it is a measured number, only not a claim. */
            . '.aa-ver-delta{display:inline-block;margin-left:.5em;font-size:90%;padding:0 .3em;border-radius:2px}'
            . '.aa-ver-delta--good{color:var(--clr-diff-ins-text,#14532d);background:var(--clr-diff-ins-bg,#d6f5d6);font-weight:600}'
            . '.aa-ver-delta--bad{color:var(--clr-diff-del-text,#7f1d1d);background:var(--clr-diff-del-bg,#fbdada);font-weight:600}'
            . '.aa-ver-delta--flat{color:#555}'
            . '.aa-ver-agree{font-weight:600}'
            . '.aa-ver-cache{--bar:hsl(calc(var(--pct,0) * 1.2),60%,55%)}'
            . '.aa-ver-short td{color:#666}'
            . '.aa-ver-table td.aa-ver-early{text-align:left;font-style:italic}'
            /* the item link comes from the diagnostics panel, which keeps it on one line */
            . '.aa-ver-table td.aa-ver-item{text-align:left;white-space:normal;max-width:40ch}'
            . '.aa-ver-item a{white-space:normal}'
            . '.aa-ver-hits{white-space:nowrap;color:#555}'
            . '.aa-ver-trend{margin:1em 0}'
            . '.aa-ver-trend > summary{margin-bottom:.5em}'
            . '.aa-ver-axis{margin:.25em 0 .5em;font-size:90%;color:#555}'
            . '</style>';
    }
}
