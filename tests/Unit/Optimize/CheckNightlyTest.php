<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckNightly;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/** How the stored nightly state becomes State Check findings. */
final class CheckNightlyTest extends TestCase
{
    private const FULL = 1_760_400_000;
    private const NOW  = self::FULL + 3600;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @param array<string, mixed> $change */
    private static function found(string $page, array $change = []): array
    {
        return $change + ['page' => $page, 'url' => '', 'severity' => 'warning', 'known' => false, 'count' => 1, 'first' => self::FULL, 'runs' => 1, 'evidence' => 'e'];
    }

    /** @param list<array<string, mixed>> $found */
    private static function pair(string $check, string $spec, array $found = [], int $ran = self::FULL, string $reason = '', string $run = '20261014-0330'): array
    {
        return ['check' => $check, 'spec' => $spec, 'run' => $run, 'ran' => $ran, 'reason' => $reason, 'findings' => array_column($found, null, 'page')];
    }

    /** @param list<array<string, mixed>> $pairs */
    private static function state(array $pairs, int $full = self::FULL): array
    {
        $keyed = [];
        foreach ($pairs as $pair) {
            $keyed["{$pair['check']}:{$pair['spec']}"] = $pair;
        }
        return ['run' => ['run' => '20261014-0330', 'mode' => 'full', 'started' => $full, 'finished' => $full + 600], 'full' => ['run' => '20261014-0330', 'started' => $full], 'pairs' => $keyed];
    }

    /** @param Finding[] $findings */
    private static function byKey(array $findings): array
    {
        return array_column(array_map(static fn(Finding $f) => ['key' => $f->key, 'f' => $f], $findings), 'f', 'key');
    }

    public function test_problem_type_is_one_finding_with_its_pages(): void
    {
        $findings = CheckNightly::fromState(self::state([
            self::pair('php-error', '30-crawl', [
                self::found('admin/a.php', ['severity' => 'error', 'count' => 2, 'first' => self::FULL - 86400, 'runs' => 2, 'url' => 'https://actionapps.org/aadev/admin/a.php']),
                self::found('admin/b.php', ['severity' => 'warning']),
            ]),
            self::pair('php-error', '50-expressions', [self::found('/expression/x', ['url' => 'https://actionapps.org/expression/x'])]),
        ]), self::NOW);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame(Finding::ERROR, $f->severity, 'the worst of its pages');
        $this->assertSame('php-error', $f->key);
        $this->assertSame(CheckNightly::describe('php-error')[0], $f->title);
        $this->assertSame(4, $f->count);
        $this->assertSame(self::FULL - 86400, $f->since);
        $this->assertSame(self::FULL, $f->last);
        $this->assertSame(0, $f->checked, 'ran in the latest full run');
        $this->assertFalse($f->recheckable);
        $this->assertSame('', $f->muted);
        $this->assertSame(['/expression/x' => 'https://actionapps.org/expression/x', 'admin/a.php' => 'https://actionapps.org/aadev/admin/a.php'], $f->links, 'newest first');
        $this->assertCount(3, $f->evidence);
        $this->assertStringContainsString('AA_NIGHTLY_ONLY=30-crawl AA_NIGHTLY_RUNID=20261014-0330 ./scripts/nightly.sh', $f->verify);
        $this->assertStringContainsString('AA_NIGHTLY_ONLY=50-expressions AA_NIGHTLY_RUNID=20261014-0330 ./scripts/nightly.sh', $f->verify);
    }

    public function test_crawled_text_is_escaped(): void
    {
        $findings = CheckNightly::fromState(self::state([self::pair('xss', '40-roundtrip', [
            self::found('admin/<img src=x>.php', ['evidence' => '<script>alert(1)</script>', 'url' => 'https://actionapps.org/a?"><script>']),
        ])]), self::NOW);
        $row = $findings[0]->evidence[0];

        foreach ($row as $cell) {
            $this->assertStringNotContainsString('<script', $cell);
            $this->assertStringNotContainsString('<img', $cell);
        }
        $this->assertStringContainsString('&lt;script&gt;', implode(' ', $row));
    }

    public function test_a_link_read_back_from_the_log_must_be_a_web_address(): void
    {
        $f = CheckNightly::fromState(self::state([self::pair('xss', '40-roundtrip', [self::found('admin/a.php', ['url' => 'javascript:alert(1)'])])]), self::NOW)[0];

        $this->assertSame([], $f->links);
        $this->assertStringNotContainsString('href', $f->evidence[0]['Page']);
    }

    public function test_known_issue_is_muted_apart_from_new_problems(): void
    {
        $findings = self::byKey(CheckNightly::fromState(self::state([
            self::pair('js-error', '50-expressions', [self::found('/expression/a', ['known' => true, 'severity' => 'info'])], reason: 'shared inline-script bug'),
            self::pair('js-error', '30-crawl', [self::found('admin/x.php', ['severity' => 'error'])]),
        ]), self::NOW));

        $this->assertSame(['js-error', 'js-error:known'], array_keys($findings));
        $this->assertSame('', $findings['js-error']->muted);
        $this->assertCount(1, $findings['js-error']->evidence);
        $this->assertStringContainsString('admin/x.php', implode(' ', $findings['js-error']->evidence[0]));
        $this->assertStringContainsString('shared inline-script bug', $findings['js-error:known']->muted);
        $this->assertSame(Finding::INFO, $findings['js-error:known']->severity);
    }

    public function test_type_missing_from_latest_full_run_keeps_its_last_state(): void
    {
        $earlier  = self::FULL - 2 * 86400;
        $findings = self::byKey(CheckNightly::fromState(self::state([
            self::pair('soft-404', '50-expressions', [self::found('/expression-tests')], $earlier, run: '20261012-0330'),
            self::pair('overflow', '30-crawl', [], $earlier),
            self::pair('php-error', '30-crawl'),
        ]), self::NOW));

        $this->assertSame($earlier, $findings['soft-404']->checked, 'a problem - known when');
        $this->assertStringContainsString('AA_NIGHTLY_RUNID=20261012-0330', $findings['soft-404']->verify);
        $this->assertSame(Finding::OK, $findings['overflow']->severity, 'clean, but not verified now');
        $this->assertSame($earlier, $findings['overflow']->checked);
        $this->assertSame(Finding::OK, $findings['']->severity, 'php-error ran clean in the full run');
        $this->assertSame(0, $findings['']->checked);
    }

    public function test_clean_types_are_one_passed_finding(): void
    {
        $findings = CheckNightly::fromState(self::state([self::pair('xss', '40-roundtrip'), self::pair('php-error', '30-crawl')]), self::NOW);

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
        $this->assertSame('', $findings[0]->key, 'CheckRunner names it after the check');
        $this->assertSame(0, $findings[0]->checked);
        $this->assertCount(2, $findings[0]->evidence);
    }

    public function test_stale_full_run_verifies_nothing(): void
    {
        $findings = CheckNightly::fromState(self::state([self::pair('php-error', '30-crawl', [self::found('admin/a.php')])]), self::FULL + 2 * 86400);

        $this->assertSame(self::FULL, $findings[0]->checked, 'the test missed a night');
    }

    public function test_only_rerun_after_the_full_run_is_current(): void
    {
        $findings = CheckNightly::fromState(self::state([self::pair('php-error', '30-crawl', [self::found('admin/a.php')], self::FULL + 1800)]), self::NOW);

        $this->assertSame(0, $findings[0]->checked);
    }

    public function test_gate_totals_are_not_a_problem(): void
    {
        $findings = CheckNightly::fromState(self::state([
            self::pair('expression-gate', 'expression-gate', [self::found('view.php?vid=143', ['severity' => 'info', 'evidence' => '512/516 assertions green'])]),
            self::pair('expression', 'expression-gate', [self::found('view.php?vid=143', ['severity' => 'error'])]),
        ]), self::NOW);
        $by = self::byKey($findings);

        $this->assertSame(Finding::OK, $by['expression-gate']->severity);
        $this->assertSame(Finding::ERROR, $by['expression']->severity);
        $this->assertStringContainsString('run-tests.sh', $by['expression']->verify);
        $this->assertStringNotContainsString('AA_NIGHTLY_ONLY', $by['expression']->verify, 'the gate does not run in a one-spec rerun');
    }

    public function test_fixture_spec_has_no_rerun_command(): void
    {
        $findings = CheckNightly::fromState(self::state([self::pair('form-harvest', '10-fixtures', [self::found('admin/sliceadd.php')])]), self::NOW);

        $this->assertStringNotContainsString('AA_NIGHTLY_ONLY', $findings[0]->verify);
        $this->assertNotSame('', $findings[0]->verify);
    }

    public function test_long_lists_are_capped(): void
    {
        $pages = [];
        for ($i = 0; $i < 70; ++$i) {
            $pages[] = self::found("admin/p$i.php", ['url' => "https://actionapps.org/aadev/admin/p$i.php"]);
        }
        $f = CheckNightly::fromState(self::state([self::pair('slow-page', '30-crawl', $pages)]), self::NOW)[0];

        $this->assertSame(70, $f->count);
        $this->assertCount(20, $f->links);
        $this->assertCount(51, $f->evidence, '50 pages and a row saying how many more');
    }

    public function test_every_pushed_type_has_its_own_text(): void
    {
        $generic = CheckNightly::describe('no-such-check')[1];
        foreach (['unexpanded-alias', 'raw-id-leak', 'mojibake', 'double-escape', 'deferred-exec', 'php-error', 'http-status', 'soft-404', 'charset', 'truncated', 'asset-status', 'slow-page', 'overflow', 'js-error', 'console-error', 'xss', 'nav-error', 'form-harvest', 'roundtrip', 'canary-missing', 'not-listed', 'expression', 'expression-gate'] as $check) {
            [$title, $advice] = CheckNightly::describe($check);
            $this->assertNotSame('', $title, $check);
            $this->assertNotSame($generic, $advice, $check);
        }
        $this->assertStringContainsString('no-such-check', CheckNightly::describe('no-such-check')[0]);
    }
}
