<?php

declare(strict_types=1);

namespace AA\Optimize;

/** What the nightly test (test4update) found, from the state it pushes (NightlyLog): one
 *  finding per check type that found something, with the pages in its links. The test cannot
 *  be run from here, so no Recheck - the next nightly run verifies, or a one-spec rerun by hand.
 *  Without a push in the last 40 days there is no finding at all.
 *
 *  @phpstan-import-type State from NightlyLog
 *  @phpstan-import-type Pair from NightlyLog
 *  @phpstan-import-type Found from NightlyLog
 */
class CheckNightly extends AbstractOptimize
{
    /** a full run older than this means the test missed a night - nothing it says is current */
    public const STALE_AFTER = 36 * 3600;
    /** pages listed per finding */
    private const ROWS_MAX  = 50;
    private const LINKS_MAX = 20;
    /** carries the totals of the run, not a problem */
    private const REPORT_ONLY = ['expression-gate'];
    /** run only as a whole, never by AA_NIGHTLY_ONLY */
    private const GATE = ['expression', 'expression-gate'];

    public static function name(): string
    {
        return _m('Nightly test');
    }

    public static function description(): string
    {
        return _m('What the nightly test (test4update) found in its last run of each check: PHP errors, broken or unescaped output, missing pages, failing Expression Reference examples. Shown only on installations the nightly test reports to.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'nightly';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $state = NightlyLog::load();
        return $state ? self::fromState($state, now()) : [];
    }

    /** @param State $state
     *  @return Finding[] */
    public static function fromState(array $state, int $now): array
    {
        $full    = $state['full']['started'];
        $current = self::isCurrent($full, $now);
        $types   = [];
        foreach ($state['pairs'] as $pair) {
            $types[$pair['check']][] = $pair;
        }
        ksort($types);

        $findings = [];
        $passed   = [];
        foreach ($types as $check => $pairs) {
            $found = ['problem' => [], 'known' => []];
            foreach ($pairs as $pair) {
                foreach ($pair['findings'] as $page) {
                    $found[$page['known'] ? 'known' : 'problem'][] = [$pair, $page];
                }
            }
            if (in_array($check, self::REPORT_ONLY, true)) {
                $found = ['problem' => [], 'known' => [], 'report' => array_merge($found['problem'], $found['known'])];
            }
            $label = self::describe($check)[0];
            if (!$found['problem'] && !$found['known'] && !($found['report'] ?? [])) {
                $checked = self::checked($pairs, $current, $full);
                if ($checked) {         // its last known state - clean, but not from the latest full run
                    $findings[] = new Finding(Finding::OK, _m('%1: nothing found', [$label]), key: $check, last: $checked, checked: $checked, recheckable: false, verify: self::verify($check, $pairs));
                } else {
                    $passed[] = [_m('Check') => safe($label), _m('Ran') => date('Y-m-d H:i', max(array_column($pairs, 'ran')))];
                }
                continue;
            }
            foreach ($found as $group => $pages) {
                if ($pages) {
                    $findings[] = self::finding($check, $pages, $group, $current, $full);
                }
            }
        }
        if ($passed) {
            $findings[] = new Finding(Finding::OK, _m('Nightly test: %1 checks found nothing', [count($passed)]), evidence: $passed, recheckable: false);
        }
        return $findings;
    }

    /** the latest full run is recent enough to verify the types it ran
     *  @param int $full  when it started, 0 = none in the last 40 days */
    public static function isCurrent(int $full, int $now): bool
    {
        return ($full > 0) && ($full >= $now - self::STALE_AFTER);
    }

    /** One finding of one check type: its pages, newest first.
     * @param list<array{0: Pair, 1: Found}> $found
     * @param string $group  problem, known (muted by the test itself) or report (not a problem) */
    private static function finding(string $check, array $found, string $group, bool $current, int $full): Finding
    {
        [$label, $advice] = self::describe($check);
        usort($found, static fn(array $a, array $b) => [$b[1]['first'], $b[1]['count']] <=> [$a[1]['first'], $a[1]['count']]);

        $worst   = Finding::INFO;
        $count   = 0;
        $since   = PHP_INT_MAX;
        $rows    = [];
        $links   = [];
        $pairs   = [];
        $reasons = [];
        foreach ($found as $i => [$pair, $page]) {
            $worst = (Finding::rank($page['severity']) < Finding::rank($worst)) ? $page['severity'] : $worst;
            // read back from the log: only a web address may become a link
            $url    = preg_match('~^https?://~i', $page['url']) ? $page['url'] : '';
            $count += $page['count'];
            $since  = min($since, $page['first']);
            $pairs[NightlyLog::pairKey($pair['check'], $pair['spec'])] = $pair;
            if ($page['known'] && ($pair['reason'] !== '')) {
                $reasons[$pair['reason']] = $pair['reason'];
            }
            if (($url !== '') && (count($links) < self::LINKS_MAX)) {
                $links[$page['page']] = $url;
            }
            if ($i < self::ROWS_MAX) {
                // crawled text: the page, the evidence and the url may all hold markup
                $where  = safe($page['page']);
                $rows[] = [
                    _m('Page')          => ($url !== '') ? '<a href="' . safe($url) . '">' . $where . '</a>' : $where,
                    _m('Found')         => safe($page['evidence']),
                    _m('Count')         => (string) $page['count'],
                    _m('First seen')    => date('Y-m-d H:i', $page['first']),
                    _m('Runs in a row') => (string) $page['runs'],
                ];
            }
        }
        if (count($found) > self::ROWS_MAX) {
            $rows[] = [_m('Page') => _m('%1 more pages', [count($found) - self::ROWS_MAX]), _m('Found') => '', _m('Count') => '', _m('First seen') => '', _m('Runs in a row') => ''];
        }
        $known = ($group === 'known');
        $muted = !$known ? '' : ($reasons ? _m('Known issue: %1', [implode('; ', $reasons)]) : _m('Known issue of the nightly test.'));
        $pairs = array_values($pairs);
        return new Finding(
            severity: ($group === 'report') ? Finding::OK : $worst,
            title: $label,
            count: $count,
            since: $since,
            evidence: $rows,
            advice: $advice,
            links: $links,
            key: $known ? "$check:known" : $check,
            last: max(array_column($pairs, 'ran')),
            checked: self::checked($pairs, $current, $full),
            recheckable: false,
            verify: self::verify($check, $pairs),
            muted: $muted,
        );
    }

    /** 0 when one of the pairs ran in the latest full run (or a rerun after it) and that run is
     *  current; otherwise when they last ran - the state is the last known one
     * @param list<Pair> $pairs */
    private static function checked(array $pairs, bool $current, int $full): int
    {
        $ran = max(array_column($pairs, 'ran'));
        return ($current && ($ran >= $full)) ? 0 : $ran;
    }

    /** how to verify after a fix - the specs that found it, rerun alone against their run
     * @param list<Pair> $pairs */
    private static function verify(string $check, array $pairs): string
    {
        if (in_array($check, self::GATE, true)) {
            return _m('Verified by the next nightly run; to verify now run ./run-tests.sh in aa/doc.');
        }
        $commands = [];
        foreach ($pairs as $pair) {
            // 10-fixtures is a project of its own, AA_NIGHTLY_ONLY does not run it
            if (preg_match('/^(?!10-)\d\d-[a-z0-9-]+$/', $pair['spec'])) {
                $commands[] = "AA_NIGHTLY_ONLY={$pair['spec']} AA_NIGHTLY_RUNID={$pair['run']} ./scripts/nightly.sh";
            }
        }
        return $commands
            ? _m('Verified by the next nightly run; to verify now run %1 in test4update.', [implode(' or ', array_unique($commands))])
            : _m('Verified by the next nightly run.');
    }

    /** Name and advice of a check type of the nightly test (test4update/tests/nightly/checks.ts
     *  and the specs): what it means, why it matters, how to see it by eye.
     *  @return array{0: string, 1: string} */
    public static function describe(string $check): array
    {
        return match ($check) {
            'unexpanded-alias' => [_m('Unexpanded aliases on public pages'), _m('A public page shows an alias such as _#HEADLINE as plain text. The template uses an alias the slice does not define (a typo, or the alias was renamed), so visitors see the code instead of the value. Open the page, find the alias from the evidence and compare it with the aliases on the Fields page of the slice.')],
            'raw-id-leak'      => [_m('Raw item ids in the text of a page'), _m('A 32-character hexadecimal id appears in the visible text of an Expression Reference page, outside a code sample. Usually a relation field or an expression prints the id instead of the item it points to. Open the page and look for the id from the evidence.')],
            'mojibake'         => [_m('Garbled characters (mojibake)'), _m('The page contains sequences like Ã, Å or â€: UTF-8 text decoded as Latin-1 or Windows-1252 on its way. Accented letters and typographic quotes then show as garbage. Open the page and look for such characters; they usually come from one field or one part of a template.')],
            'double-escape'    => [_m('Double-escaped HTML'), _m('A value was HTML-escaped twice, so the reader sees the code of a character (an ampersand followed by lt;) instead of the character itself, here the < sign. Open the page and look for such codes in the visible text; the template or the field output escapes a value that was already escaped.')],
            'deferred-exec'    => [_m('Expressions printed instead of run'), _m('The visible text of an Expression Reference page contains {( outside a code sample: an expression that should have run on the server was printed instead. Open the page and search its text for {(.')],
            'php-error'        => [_m('PHP errors in page output'), _m('The page printed a PHP error, warning or notice with its file and line, or a database error (SQLSTATE). Visitors see the message and the rest of the page may be missing. Open the page; the message names the file and line to fix.')],
            'http-status'      => [_m('Pages not answering 200 OK'), _m('The page answered with an error status (404, 500 and the like) or did not answer at all, so the link to it is broken or the script fails. Open the page and look at the status in the Network tab of the browser developer tools.')],
            'soft-404'         => [_m('Missing page served as a full page (soft 404)'), _m('The address answers 404 Not Found but still sends a full page of content. Search engines and link checkers get a mixed answer and the old content stays reachable. Open the address: it should show either a real page with status 200 or a short "not found" page.')],
            'charset'          => [_m('Characters lost when saving (charset)'), _m('Characters outside Latin-1 (ě, č, ř, emoji, Chinese) came back as ? after an item was saved: the database columns hold fewer bytes per character than the connection sends. Such text is damaged for good once saved. Save an item with ěščřžýáíé in its text and open it again in the editor.')],
            'truncated'        => [_m('Admin pages cut off before the end'), _m('The HTML of the admin page does not end with </body></html>: the script stopped while printing it, usually after a fatal error or a timeout, and the rest of the page (buttons, the footer) is missing. Open the page and scroll to the bottom; look for a PHP fatal error of that page too.')],
            'asset-status'     => [_m('Missing images, scripts or styles'), _m('The page loads a file (an image, a script, a style sheet) that answers with an error status. The page may look broken or a feature may not work. Open the page with the Network tab of the browser developer tools and look for the files named in the evidence.')],
            'slow-page'        => [_m('Slow admin pages'), _m('The admin page took more than 3 seconds to load during the nightly crawl. Editors wait for it, and a page slow at night is slower by day. Open the page with &debug=150 added to its address to see which queries and functions take the time.')],
            'overflow'         => [_m('Admin pages wider than the window'), _m('In a 1280 px wide window the admin page scrolls sideways: a long value or a wide table does not wrap, and controls on the right are easy to miss. Open the page in a window of that width and look for the horizontal scroll bar.')],
            'js-error'         => [_m('JavaScript errors'), _m('A script on the page threw an uncaught error, so the feature it belongs to (a widget, a button, live editing) may not work. Open the page with the browser developer console open and reload; the error from the evidence appears there.')],
            'console-error'    => [_m('Errors in the browser console'), _m('The page logged an error to the browser console, often a failed request or a blocked resource; a feature may fail without a visible sign. Open the browser developer console on the page and reload.')],
            'xss'              => [_m('Unescaped text in page output (XSS)'), _m('A test text with quotes and angle brackets was saved and then printed into the page without HTML escaping. This is a security problem: the value must be printed through safe(). In the page source around the test text, the < and " signs must appear as HTML entities.')],
            'nav-error'        => [_m('Pages the test could not open'), _m('The browser could not open or finish the page: a timeout, a download instead of a page, or a redirect while the page was being checked. Nothing else could be checked there. Open the page and watch whether it loads.')],
            'form-harvest'     => [_m('Admin forms the test could not read'), _m('The nightly test did not find the controls it expects on an admin form (a field, the save button, the template slice), or the form did not keep what was sent. Either the form changed or it is broken. Open the form and check that it shows and saves the fields named in the evidence.')],
            'roundtrip'        => [_m('Item edits that did not save'), _m('The test changed the headline of an item in the item editor, saved it and read it back, and got a different value. Changes made by editors may be lost. Edit an item, save it and open it again to see whether the change stayed.')],
            'canary-missing'   => [_m('Saved text missing from the public listing'), _m('The text of the test items (abstract and full text) did not show on the public listing of the slice, or not in the abstract paragraph. An alias probably expands to nothing, so visitors see empty places. Open the listing and compare it with the content of the items.')],
            'not-listed'       => [_m('Items missing from the public listing'), _m('A published test item did not appear on the public listing of its slice, so visitors miss items that should be there. Check the conditions, sorting and item count of the listing; open it and look for the item from the evidence.')],
            'not-persisted'    => [_m('Field values that did not save'), _m('The test wrote a value into a field in the item editor, saved it and read it back, and the field kept its old value or came back empty. Editors may lose what they type into that field. Edit an item, change the field named in the evidence, save it and open it again.')],
            'stripslashes'     => [_m('Backslashes lost when saving'), _m('A value with backslashes came back with characters missing from its middle, typically because the input went through stripslashes() once too often. Paths, regular expressions and code samples get damaged when saved. Save an item with C:\\temp\\x in a text field and open it again.')],
            'body-mismatch'    => [_m('Saved text changed by saving'), _m('The text of a test item came back different from what was saved, not just shorter or with characters lost: an input function, a field filter or the editor rewrote it. Save an item with the text from the evidence and compare it with what the editor shows after saving.')],
            'expression'       => [_m('Failing Expression Reference examples'), _m('An example of the Expression Reference gave a different result than the documented one: either the expression changed its behaviour or the example is wrong. Open the test view of the Expression Reference and compare the actual and the expected result of the example from the evidence.')],
            'expression-gate'  => [_m('Expression Reference examples'), _m('The run of all Expression Reference examples; the totals are in the evidence and each failing example is listed on its own.')],
            default            => [_m('Nightly check %1', [$check]), _m('The nightly test reported this on the pages below. The report of the nightly test (test4update) explains the check.')],
        };
    }
}
