<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\NightlyLog;
use AA_Logentry;
use PHPUnit\Framework\TestCase;

/** The storage decisions of the nightly push: per check type and spec the newest run wins,
 *  first seen / runs in a row carry over, and the rows fit the log columns. The database is
 *  replaced by the rows rows() produces and parse() reads back. */
final class NightlyLogTest extends TestCase
{
    private const DAY = 86400;
    private const T1  = 1_760_400_000;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), ParamImplode() for the log entries
    }

    /**
     * @param list<array{0: string, 1: string}> $ran       check, spec
     * @param list<array<string, mixed>>         $findings  check, spec, page + optional fields
     * @return array<string, mixed>
     */
    private static function push(string $run, int $started, array $ran, array $findings = [], string $mode = 'full'): array
    {
        $pairs = [];
        foreach ($ran as [$check, $spec]) {
            $pairs[NightlyLog::pairKey($check, $spec)] = ['check' => $check, 'spec' => $spec];
        }
        return [
            'run'      => $run,
            'mode'     => $mode,
            'started'  => $started,
            'finished' => $started + 600,
            'ran'      => $pairs,
            'findings' => array_map(static fn(array $f) => $f + ['severity' => 'warning', 'url' => '', 'evidence' => 'e', 'known' => false, 'reason' => ''], $findings),
        ];
    }

    /** @param array<string, mixed> $push
     *  @return array<string, mixed> */
    private static function stateAfter(?array $prev, array $push, ?int $now = null): array
    {
        return NightlyLog::merge($prev, $push, $now ?? $push['started'] + 3600)[0];
    }

    public function test_merge_first_push_starts_every_streak(): void
    {
        [$state, $stored, $taken] = NightlyLog::merge(null, self::push('r1', self::T1, [['php-error', '30-crawl']], [
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php'],
        ]), self::T1 + 3600);

        $this->assertSame([1, 1], [$stored, $taken]);
        $this->assertSame(['run' => 'r1', 'started' => self::T1], $state['full']);
        $found = $state['pairs']['php-error:30-crawl']['findings']['admin/a.php'];
        $this->assertSame([self::T1, 1, 1], [$found['first'], $found['runs'], $found['count']]);
    }

    public function test_merge_finding_that_stays_keeps_first_seen_and_counts_runs(): void
    {
        $ran   = [['php-error', '30-crawl']];
        $page  = [['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php']];
        $state = self::stateAfter(null, self::push('r1', self::T1, $ran, $page));
        $state = self::stateAfter($state, self::push('r2', self::T1 + self::DAY, $ran, $page));
        $state = self::stateAfter($state, self::push('r3', self::T1 + 2 * self::DAY, $ran, $page));

        $found = $state['pairs']['php-error:30-crawl']['findings']['admin/a.php'];
        $this->assertSame(self::T1, $found['first']);
        $this->assertSame(3, $found['runs']);
        $this->assertSame('r3', $state['pairs']['php-error:30-crawl']['run']);
    }

    public function test_merge_finding_that_came_back_starts_again(): void
    {
        $ran   = [['php-error', '30-crawl']];
        $page  = [['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php']];
        $state = self::stateAfter(null, self::push('r1', self::T1, $ran, $page));
        $state = self::stateAfter($state, self::push('r2', self::T1 + self::DAY, $ran));
        $this->assertSame([], $state['pairs']['php-error:30-crawl']['findings'], 'a clean run clears the pair');

        $state = self::stateAfter($state, self::push('r3', self::T1 + 2 * self::DAY, $ran, $page));
        $found = $state['pairs']['php-error:30-crawl']['findings']['admin/a.php'];
        $this->assertSame([self::T1 + 2 * self::DAY, 1], [$found['first'], $found['runs']]);
    }

    public function test_merge_only_run_replaces_just_the_pairs_it_ran(): void
    {
        $state = self::stateAfter(null, self::push('r1', self::T1, [['php-error', '30-crawl'], ['php-error', '50-expressions'], ['xss', '40-roundtrip']], [
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php'],
            ['check' => 'php-error', 'spec' => '50-expressions', 'page' => '/expression/x'],
            ['check' => 'xss', 'spec' => '40-roundtrip', 'page' => 'admin/b.php'],
        ]));
        [$state, $stored, $taken] = NightlyLog::merge($state, self::push('r1', self::T1 + 3600, [['php-error', '30-crawl']], [], 'only'), self::T1 + 7200);

        $this->assertSame([0, 1], [$stored, $taken]);
        $this->assertSame([], $state['pairs']['php-error:30-crawl']['findings'], 'fixed and verified by the rerun');
        $this->assertArrayHasKey('/expression/x', $state['pairs']['php-error:50-expressions']['findings'], 'the same check in a spec that did not run stays');
        $this->assertSame(self::T1, $state['pairs']['php-error:50-expressions']['ran']);
        $this->assertArrayHasKey('admin/b.php', $state['pairs']['xss:40-roundtrip']['findings']);
        $this->assertSame(['run' => 'r1', 'started' => self::T1], $state['full'], 'an only run is not a full run');
        $this->assertSame(['run' => 'r1', 'mode' => 'only', 'started' => self::T1 + 3600, 'finished' => self::T1 + 4200], $state['run']);
    }

    public function test_merge_older_run_does_not_overwrite_a_newer_one(): void
    {
        $ran   = [['php-error', '30-crawl']];
        $state = self::stateAfter(null, self::push('r2', self::T1 + self::DAY, $ran));
        [$after, $stored, $taken] = NightlyLog::merge($state, self::push('r1', self::T1, $ran, [
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php'],
        ]), self::T1 + 2 * self::DAY);

        $this->assertSame([0, 0], [$stored, $taken], 'nothing to write');
        $this->assertSame($state['pairs'], $after['pairs']);
        $this->assertSame('r2', $after['run']['run']);
        $this->assertSame('r2', $after['full']['run']);
    }

    public function test_merge_same_run_pushed_again_is_not_counted_twice(): void
    {
        $ran   = [['php-error', '30-crawl']];
        $page  = [['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php']];
        $state = self::stateAfter(null, self::push('r1', self::T1, $ran, $page));
        $state = self::stateAfter($state, self::push('r2', self::T1 + self::DAY, $ran, $page));
        $state = self::stateAfter($state, self::push('r2', self::T1 + self::DAY, $ran, $page));

        $this->assertSame(2, $state['pairs']['php-error:30-crawl']['findings']['admin/a.php']['runs']);
    }

    public function test_merge_pair_not_run_for_40_days_is_dropped(): void
    {
        $state = self::stateAfter(null, self::push('r1', self::T1, [['xss', '40-roundtrip'], ['php-error', '30-crawl']]));
        $later = self::T1 + NightlyLog::RETENTION + self::DAY;
        $state = self::stateAfter($state, self::push('r9', $later, [['php-error', '30-crawl']]), $later);

        $this->assertSame(['php-error:30-crawl'], array_keys($state['pairs']));
    }

    public function test_merge_caps_the_state_dropping_the_pairs_run_longest_ago(): void
    {
        $state = null;
        // three pushes of 200 check types each, every one with 5 pages: 600 pairs, 3000 findings
        foreach ([0, 1, 2] as $night) {
            $ran = $found = [];
            for ($c = 0; $c < 200; ++$c) {
                $ran[] = ["c$night-$c", '30-crawl'];
                for ($p = 0; $p < 5; ++$p) {
                    $found[] = ['check' => "c$night-$c", 'spec' => '30-crawl', 'page' => "admin/p$p.php"];
                }
            }
            $state = self::stateAfter($state, self::push("r$night", self::T1 + $night * self::DAY, $ran, $found));
        }
        $kept = array_values($state['pairs']);

        $this->assertCount(NightlyLog::MAX_FINDINGS / 5, $kept, 'at most MAX_FINDINGS findings');
        $this->assertLessThanOrEqual(NightlyLog::MAX_PAIRS, count($kept));
        $this->assertSame(self::T1 + self::DAY, min(array_column($kept, 'ran')), 'the night run longest ago goes first');
        $this->assertCount(200, array_filter($kept, static fn(array $p) => $p['ran'] === self::T1 + 2 * self::DAY), 'the newest push stays whole');
    }

    public function test_merge_caps_the_number_of_pairs(): void
    {
        $state = null;
        foreach ([0, 1, 2] as $night) {
            $state = self::stateAfter($state, self::push("r$night", self::T1 + $night * self::DAY, array_map(static fn(int $c) => ["c$night-$c", '30-crawl'], range(1, 200))));
        }

        $this->assertCount(NightlyLog::MAX_PAIRS, $state['pairs']);
        $this->assertCount(100, array_filter($state['pairs'], static fn(array $p) => $p['ran'] === self::T1), 'only the night run longest ago lost pairs');
    }

    public function test_merge_same_page_twice_is_one_finding(): void
    {
        $state = self::stateAfter(null, self::push('r1', self::T1, [['js-error', '30-crawl']], [
            ['check' => 'js-error', 'spec' => '30-crawl', 'page' => 'admin/a.php', 'severity' => 'warning', 'evidence' => 'first'],
            ['check' => 'js-error', 'spec' => '30-crawl', 'page' => 'admin/a.php', 'severity' => 'error', 'url' => 'https://x/a', 'evidence' => 'second'],
        ]));
        $found = $state['pairs']['js-error:30-crawl']['findings']['admin/a.php'];

        $this->assertSame([2, 'error', 'first', 'https://x/a'], [$found['count'], $found['severity'], $found['evidence'], $found['url']]);
    }

    public function test_merge_known_reason_kept_on_the_pair(): void
    {
        $state = self::stateAfter(null, self::push('r1', self::T1, [['charset', '20-flows-items']], [
            ['check' => 'charset', 'spec' => '20-flows-items', 'page' => 'admin/itemedit.php', 'known' => true, 'reason' => 'deferred, AA_TODO'],
        ]));

        $this->assertSame('deferred, AA_TODO', $state['pairs']['charset:20-flows-items']['reason']);
        $this->assertTrue($state['pairs']['charset:20-flows-items']['findings']['admin/itemedit.php']['known']);
    }

    public function test_rows_parse_round_trip(): void
    {
        $state = self::stateAfter(null, self::push('20261014-0330', self::T1, [['php-error', '30-crawl'], ['charset', '20-flows-items'], ['xss', '40-roundtrip']], [
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php?x=1:2', 'url' => 'https://actionapps.org/aadev/admin/a.php?x=1:2', 'evidence' => 'Warning: x in y.php on line 3', 'severity' => 'error'],
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/b.php', 'evidence' => '<b>markup</b> & "quotes" #'],
            ['check' => 'charset', 'spec' => '20-flows-items', 'page' => 'admin/itemedit.php', 'known' => true, 'reason' => 'aadev DB 1-byte columns: deferred'],
        ]));
        $rows = array_map(static fn(array $r) => ['selector' => $r[0], 'params' => $r[1]->serializeToString()], NightlyLog::rows($state));

        $this->assertSame($state, NightlyLog::parse($rows));
    }

    public function test_rows_fit_the_log_columns(): void
    {
        $long  = str_repeat('é:', 60);       // two bytes and an escaped colon each
        $state = self::stateAfter(null, self::push(str_repeat('r', 24), self::T1, [[str_repeat('c', 40), str_repeat('s', 40)]], [
            ['check' => str_repeat('c', 40), 'spec' => str_repeat('s', 40), 'page' => str_repeat('p', 200), 'url' => 'https://actionapps.org/' . str_repeat('u', 230), 'evidence' => $long, 'known' => true, 'reason' => $long],
        ]));

        foreach (NightlyLog::rows($state) as [$selector, $params]) {
            $this->assertLessThanOrEqual(255, strlen($selector));
            $this->assertLessThanOrEqual(AA_Logentry::PARAMS_MAX, strlen($params->serializeToString()), $params->serializeToString());
        }
    }

    public function test_parse_skips_rows_of_another_shape(): void
    {
        $state = self::stateAfter(null, self::push('r1', self::T1, [['php-error', '30-crawl']], [
            ['check' => 'php-error', 'spec' => '30-crawl', 'page' => 'admin/a.php'],
        ]));
        $rows   = array_map(static fn(array $r) => ['selector' => $r[0], 'params' => $r[1]->serializeToString()], NightlyLog::rows($state));
        $rows[] = ['selector' => 'x', 'params' => 'garbage'];
        $rows[] = ['selector' => 'admin/c.php', 'params' => 'Nf:9:7:error:0:1:1:1'];       // a pair that does not exist
        $rows[] = ['selector' => 'admin/d.php', 'params' => 'Nf:9:1:fatal:0:1:1:1'];       // not a severity
        $rows[] = ['selector' => null, 'params' => null];

        $this->assertSame($state, NightlyLog::parse($rows));
        $this->assertNull(NightlyLog::parse([['selector' => 'php-error:30-crawl', 'params' => 'Nran:1:r1:1:']]), 'no run row - no state');
    }
}
