<?php

declare(strict_types=1);

/**
 * TransferProgress: the requests of the progress page - the next batch read from the source and written
 * at the run's position, a stale request doing nothing, a failed batch putting the run in error, Continue
 * and End, and the finish after the last slice (caches, then the run is done).
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppSource;
use AA\Central\ExportRefused;
use AA\Central\SourceFailed;
use AA\Central\InstallRun;
use AA\Central\Package\AfterCommit;
use AA\Central\Package\BatchResult;
use AA\Central\Package\CollectOptions;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\IO\DB\DB_AA;
use Closure;
use AA\Central\TransferProgress;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/Package/fixtures/ImportFixture.php';
require_once __DIR__ . '/Package/fixtures/FakeSource.php';

final class TransferProgressTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    private const ITEM_A = '1234567890abcdef1234567890abcdef';
    private const ITEM_B = '2234567890abcdef1234567890abcdef';
    private const ITEM_C = '3234567890abcdef1234567890abcdef';
    private const SLICE2 = '7f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const SOURCE = ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'];

    private FakeSource $source;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
        $this->source = new FakeSource(
            ['module' => [['id' => self::SLICE, 'type' => 'S', 'deleted' => 0, 'name' => 'Články', 'lang_file' => 'cz-utf8_news_lang.php']]],
            [self::SLICE => [self::entry(self::ITEM_A, 'A'), self::entry(self::ITEM_B, 'B')]],
        );
    }

    private static function entry(string $id, string $headline): array
    {
        return ['id' => $id, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]], 'headline........' => [['value' => $headline, 'flag' => 0]]]];
    }

    /** a transfer run of the example with its definitions written; the calls after the import forgotten
     *  @param array<string, string> $filters source slice => ItemFilter text the preview chose */
    private function transferred(array $filters = []): string
    {
        $run          = $this->newRun(InstallRun::TRANSFER, '7', $filters);
        $confirmation = new ImportConfirmation('preview-session');
        $plan         = $this->plan(self::example(), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls = [];
        return $run;
    }

    /** @param list<string> $denied modules the user may not export */
    private function progress(string $user = self::USER, array $denied = []): TransferProgress
    {
        $export = new AppExport($this->source, self::SOURCE, static fn(string $module): bool => !in_array($module, $denied, true), true);
        return new TransferProgress($this->importer(), $export, $this->after, $user);
    }

    /** a transfer run of the example with a second slice after its own */
    private function transferredTwoSlices(): string
    {
        $package = self::example();
        $slice   = array_values(array_filter($package->modules, static fn(Module $m): bool => $m->id === self::SLICE))[0];
        $second  = new Module(Module::SLICE, self::SLICE2, [
            'module' => [array_merge($slice->rows['module'][0], ['name' => 'Druhá'])],
            'slice'  => [['name' => 'Druhá']],
            'field'  => [$slice->rows['field'][0]],
        ]);
        $run          = $this->newRun(InstallRun::TRANSFER);
        $confirmation = new ImportConfirmation('preview-session');
        $plan         = $this->plan(self::with($package, ['modules' => [...$package->modules, $second]]), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls                    = [];
        $this->source->tables['module'][]      = ['id' => self::SLICE2, 'type' => 'S', 'deleted' => 0, 'name' => 'Druhá', 'lang_file' => 'cz-utf8_news_lang.php'];
        $this->source->items[self::SLICE2]     = [self::entry(self::ITEM_C, 'C')];
        return $run;
    }

    /** @return list<string> ids of the items of the new slice */
    private static function itemsOfTheSlice(): array
    {
        return array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::id(2)), 'id'));
    }

    public function test_the_last_batch_is_written_then_caches_are_cleared_and_the_run_is_done(): void
    {
        $run = $this->transferred();

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame([self::SLICE => 2], $state['written']);
        self::assertSame(['slice' => self::SLICE, 'cursor' => self::ITEM_B], $state['position']);
        self::assertSame(['invalidatePagecache ' . self::id(1), 'invalidatePagecache ' . self::id(2), 'invalidatePagecache ' . self::id(3), 'constantsChanged'], $this->after->calls);
        self::assertSame([], $state['cleanup']);
    }

    public function test_the_finish_stores_the_report_of_the_check(): void
    {
        $run = $this->transferred();

        $this->progress()->next($run, [self::SLICE, '']);

        $report = InstallRun::get($run)->report();
        self::assertSame([['source' => self::SLICE, 'target' => self::id(2), 'written' => 2, 'sourceCount' => 2, 'targetCount' => 2]], $report['slices']);
        self::assertSame([], $report['findings']);
    }

    public function test_a_filtered_run_transfers_and_checks_only_the_matching_items(): void
    {
        $this->source->tables['field'] = [['id' => 'headline........', 'slice_id' => self::SLICE, 'input_show_func' => 'fld:']];
        $run = $this->transferred([self::SLICE => 'd-headline........-==-B']);

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([TransferProgress::DONE, [self::SLICE => 1]], [$state['status'], $state['written']]);
        self::assertSame([self::ITEM_B], self::itemsOfTheSlice());
        $report = InstallRun::get($run)->report();
        self::assertSame(1, $report['slices'][0]['sourceCount'], 'the source counts what the filter selects');
        self::assertSame([], $report['findings'], 'so no count-differs');
    }

    public function test_a_multi_slice_transfer_finishes_only_after_the_last_slice(): void
    {
        $run = $this->transferredTwoSlices();

        $first = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::WRITTEN, InstallRun::ITEMS], [$first['status'], $first['state']]);
        self::assertSame(['slice' => self::SLICE2, 'cursor' => ''], $first['position']);
        self::assertSame([], $this->after->calls, 'no finish after the first slice');

        $last = $this->progress()->next($run, [self::SLICE2, '']);

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$last['status'], $last['state']]);
        self::assertSame([self::SLICE => 2, self::SLICE2 => 1], $last['written']);
    }

    public function test_a_request_dying_between_the_last_batch_and_the_finish_recovers(): void
    {
        $run = $this->transferred();
        self::failAt("/'report'/", 'throw');          // the finish of the run dies
        try {
            $this->progress()->next($run, [self::SLICE, '']);
        } catch (\Throwable) {
        }
        TableDb::$fail = null;
        self::assertSame([InstallRun::ITEMS, [self::SLICE, self::ITEM_B]], [InstallRun::get($run)->state(), InstallRun::get($run)->position()], 'the items are written, the run not finished');

        $state = $this->progress()->next($run, [self::SLICE, self::ITEM_B]);

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice(), 'nothing twice');
        self::assertSame([self::SLICE => 2], $state['written']);
    }

    public function test_the_export_permission_is_checked_on_every_batch(): void
    {
        $run = $this->transferred();

        $state = $this->progress(denied: [self::SLICE])->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::FAILED, InstallRun::ERROR], [$state['status'], $state['state']]);
        self::assertSame(['code' => TransferProgress::SOURCE_DENIED, 'path' => Module::SLICE . '[' . self::SLICE . ']'], $state['error']);
        self::assertSame([], self::itemsOfTheSlice());
        self::assertSame([self::SLICE, ''], InstallRun::get($run)->position());
    }

    public function test_a_batch_that_does_not_finish_the_slice_moves_the_position_only(): void
    {
        $run = $this->transferred();
        $this->source->items[self::SLICE] = array_map(static fn(int $i): array => self::entry(sprintf('%032x', 0xb0000 + $i), "item $i"), range(1, 501));

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::WRITTEN, InstallRun::ITEMS], [$state['status'], $state['state']]);
        self::assertSame(['slice' => self::SLICE, 'cursor' => sprintf('%032x', 0xb0000 + 500)], $state['position']);
        self::assertSame([], $this->after->calls, 'no finish yet');
    }

    public function test_a_stale_request_does_nothing_and_tells_the_position(): void
    {
        $run = $this->transferred();
        [TableDb::$log, $before] = [[], TableDb::$tables];

        $state = $this->progress()->next($run, [self::SLICE, self::ITEM_A]);

        self::assertSame([BatchResult::STALE, InstallRun::ITEMS], [$state['status'], $state['state']]);
        self::assertSame(['slice' => self::SLICE, 'cursor' => ''], $state['position']);
        self::assertSame([], TableDb::writes());
        self::assertSame($before, TableDb::$tables);
    }

    public function test_a_second_request_of_a_finished_run_is_stale(): void
    {
        $run = $this->transferred();
        $this->progress()->next($run, [self::SLICE, '']);
        $this->after->calls = [];

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::STALE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame([], $this->after->calls);
    }

    public function test_a_failed_write_puts_the_run_in_error_and_continue_resumes_it(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`/');

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::FAILED, InstallRun::ERROR], [$state['status'], $state['state']]);
        self::assertNotSame('', $state['error']['code']);
        self::assertSame([], self::itemsOfTheSlice(), 'rolled back');

        TableDb::$fail = null;
        self::assertSame(BatchResult::STALE, $this->progress()->next($run, [self::SLICE, ''])['status'], 'no batch while in error');
        $resumed = $this->progress()->resume($run);
        self::assertSame([TransferProgress::RESUMED, InstallRun::ITEMS, null], [$resumed['status'], $resumed['state'], $resumed['error']]);
        self::assertSame(TransferProgress::DONE, $this->progress()->next($run, [self::SLICE, ''])['status']);
    }

    public function test_a_source_slice_gone_puts_the_run_in_error(): void
    {
        $run = $this->transferred();
        $this->source->tables['module'] = [];

        $log = ini_set('error_log', '/dev/null');
        try {
            $state = $this->progress()->next($run, [self::SLICE, '']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame([BatchResult::FAILED, InstallRun::ERROR], [$state['status'], $state['state']]);
        self::assertSame(['code' => TransferProgress::COLLECT_FAILED, 'path' => Module::SLICE . '[' . self::SLICE . ']'], $state['error']);
    }

    public function test_an_item_no_batch_can_take_stops_the_run_with_its_id(): void
    {
        $run = $this->transferred();
        $this->source->items[self::SLICE][0]['content']['fulltext........'] = [['value' => str_repeat('x', CollectOptions::MAX_BATCH_BYTES), 'flag' => 0]];

        $state = $this->progress()->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::FAILED, InstallRun::ERROR, 'ITEM_TOO_BIG'], [$state['status'], $state['state'], $state['error']['code']]);
        self::assertSame(self::ITEM_A, $state['findings'][0]['ref']);
        self::assertSame([], self::itemsOfTheSlice());
    }

    public function test_end_keeps_what_is_written_and_clears_the_caches(): void
    {
        $run = $this->transferred();

        $state = $this->progress()->end($run);

        self::assertSame([TransferProgress::ENDED, InstallRun::ENDED], [$state['status'], $state['state']]);
        self::assertContains('constantsChanged', $this->after->calls);
        self::assertSame(BatchResult::STALE, $this->progress()->end($run)['status'], 'a second End');
        self::assertSame(BatchResult::STALE, $this->progress()->resume($run)['status']);
    }

    public function test_only_the_user_of_the_run_drives_it(): void
    {
        $run = $this->transferred();

        $this->expectException(ImportFailed::class);
        $this->progress('another-uid')->next($run, [self::SLICE, '']);
    }

    /** a source whose batch throws $failure, after $meanwhile; it is asked for nothing else */
    private static function failingSource(\Throwable $failure, ?Closure $meanwhile = null): AppSource
    {
        return new class ($failure, $meanwhile) implements AppSource {
            public function __construct(private \Throwable $failure, private ?Closure $meanwhile) {}

            public function sameAa(): bool
            {
                return false;
            }

            public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
            {
                throw new \LogicException('not asked');
            }

            public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = []): array
            {
                $this->meanwhile && ($this->meanwhile)();
                throw $this->failure;
            }

            public function logins(array $slices, bool $allBins, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function leftOut(array $filters, array $slices, bool $allBins): array
            {
                throw new \LogicException('not asked');
            }
        };
    }

    public function test_an_export_error_of_a_batch_stops_the_run_with_its_code_and_findings(): void
    {
        $run      = $this->transferred();
        $path     = Module::SLICE . '[' . self::SLICE . ']/item';
        $refused  = ExportRefused::failed([
            [$path, new LintFinding(LintFinding::WARNING, 'item-field', 'x', 0)],
            [$path, new LintFinding(LintFinding::ERROR, 'item-too-big', self::ITEM_A, 0)],
            [$path . '[2]', new LintFinding(LintFinding::ERROR, 'item-charset', self::ITEM_B, 0)],
        ]);
        $progress = new TransferProgress($this->importer(), self::failingSource($refused), $this->after, self::USER);

        $state = $progress->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::FAILED, InstallRun::ERROR], [$state['status'], $state['state']]);
        self::assertSame(['code' => 'ITEM_TOO_BIG', 'path' => $path], $state['error'], 'the first error, its code upper case');
        self::assertSame(['item-field', 'item-too-big', 'item-charset'], array_column($state['findings'], 'code'), 'every finding of the source');
        self::assertSame([self::SLICE, ''], InstallRun::get($run)->position());
    }

    public function test_a_source_that_fails_puts_the_run_in_error_with_its_reason(): void
    {
        $run      = $this->transferred();
        $progress = new TransferProgress($this->importer(), self::failingSource(new SourceFailed(SourceFailed::UNREACHABLE, 'timeout')), $this->after, self::USER);

        $log = ini_set('error_log', '/dev/null');
        try {
            $state = $progress->next($run, [self::SLICE, '']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(['code' => SourceFailed::UNREACHABLE, 'path' => 'source'], $state['error']);
        self::assertSame([], $state['findings']);
    }

    /** the source is asked only by a batch: a stale request, Continue and End go without it */
    public function test_only_a_batch_asks_the_source(): void
    {
        $run      = $this->transferred();
        $gone     = static fn(): never => throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list');
        $progress = new TransferProgress($this->importer(), $gone, $this->after, self::USER);

        self::assertSame(BatchResult::STALE, $progress->next($run, [self::SLICE, self::ITEM_B])['status']);
        self::assertSame(BatchResult::STALE, $progress->resume($run)['status'], 'Continue of a run not in error');
        self::assertSame(InstallRun::ITEMS, InstallRun::get($run)->state());
        self::assertSame(TransferProgress::ENDED, $progress->end($run)['status']);
    }

    public function test_a_failing_cache_step_is_reported_and_the_others_still_run(): void
    {
        $run               = $this->transferred();
        $this->after->fail = ['invalidatePagecache ' . self::id(2)];

        $log = ini_set('error_log', '/dev/null');
        try {
            $state = $this->progress()->next($run, [self::SLICE, '']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame([['pagecache[' . self::id(2) . ']', \RuntimeException::class]], $state['cleanup'], 'the step and the class, never the message');
        self::assertSame(['invalidatePagecache ' . self::id(1), 'invalidatePagecache ' . self::id(2), 'invalidatePagecache ' . self::id(3), 'constantsChanged'], $this->after->calls);
    }

    public function test_no_user_drives_no_run(): void
    {
        $run = $this->transferred();
        foreach (TableDb::$tables['object_text'] as $i => $row) {
            if (($row['object_id'] === $run) && ($row['property'] === 'user')) {
                TableDb::$tables['object_text'][$i]['value'] = '';
            }
        }

        $this->expectException(ImportFailed::class);
        $this->progress('')->resume($run);
    }

    public function test_an_unknown_run_is_refused(): void
    {
        $this->expectException(ImportFailed::class);
        $this->progress()->next(str_repeat('9', 32), [self::SLICE, '']);
    }

    /** another request wrote the batch while this one waited for the source: its failure leaves the run alone */
    public function test_a_late_failure_of_a_batch_written_meanwhile_changes_nothing(): void
    {
        $run       = $this->transferred();
        $meanwhile = static fn() => DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run)->advance([self::SLICE, ''], [self::SLICE, self::ITEM_B], 2, []));
        $progress  = new TransferProgress($this->importer(), self::failingSource(new SourceFailed(SourceFailed::UNREACHABLE, 'timeout'), $meanwhile), $this->after, self::USER);

        $log = ini_set('error_log', '/dev/null');
        try {
            $state = $progress->next($run, [self::SLICE, '']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame([InstallRun::ITEMS, null, ['slice' => self::SLICE, 'cursor' => self::ITEM_B]], [$state['state'], $state['error'], $state['position']]);
    }

    /** another request finished the run while this one cleared the caches: this one reports stale, the report is the other's */
    public function test_a_finish_that_comes_second_is_stale(): void
    {
        $run   = $this->transferred();
        $after = new class ($run) implements AfterCommit {
            public function __construct(private string $run) {}

            public function permissions(string $module, string $user): void {}

            public function constantsChanged(): void
            {
                DB_AA::transaction(fn() => InstallRun::lockForUpdate($this->run)->finish(['by' => 'the other request']));
            }

            public function invalidatePagecache(string $module): void {}

            public function logInstall(Package $package, array $modules): void {}
        };
        $progress = new TransferProgress($this->importer(), new AppExport($this->source, self::SOURCE, static fn(): bool => true, true), $after, self::USER);

        $state = $progress->next($run, [self::SLICE, '']);

        self::assertSame([BatchResult::STALE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame(['by' => 'the other request'], InstallRun::get($run)->report());
    }

    public function test_end_after_an_error_clears_the_caches_and_a_second_end_does_not(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`/');
        $this->progress()->next($run, [self::SLICE, '']);
        TableDb::$fail = null;

        $state = $this->progress()->end($run);

        self::assertSame([TransferProgress::ENDED, InstallRun::ENDED], [$state['status'], $state['state']]);
        self::assertContains('constantsChanged', $this->after->calls);
        $this->after->calls = [];
        $this->progress()->end($run);
        self::assertSame([], $this->after->calls);
    }

    public function test_warnings_of_the_batches_are_counted_per_slice_and_told_by_each_batch(): void
    {
        $run = $this->transferredTwoSlices();
        $this->source->items[self::SLICE][0]['content']['nofield.........'] = [['value' => 'x', 'flag' => 0]];

        $first = $this->progress()->next($run, [self::SLICE, '']);
        $last  = $this->progress()->next($run, [self::SLICE2, '']);

        self::assertSame(['W item-field'], array_map(static fn(array $f): string => "{$f['level']} {$f['code']}", $first['findings']), 'a middle batch');
        self::assertSame([self::SLICE => ['item-field' => 1]], $first['warnings']);
        self::assertSame([TransferProgress::DONE, [self::SLICE => ['item-field' => 1]]], [$last['status'], $last['warnings']], 'the counts stay to the end');
    }

    public function test_an_install_run_has_no_progress(): void
    {
        $run = $this->newRun();
        $this->import($this->plan(), run: $run);

        $this->expectException(ImportFailed::class);
        $this->progress()->end($run);
    }

    /** AA's error handler keeps the arguments of every stack frame - a token or key must show as redacted */
    public function test_secret_parameters_are_kept_out_of_stack_traces(): void
    {
        foreach ([[TransferProgress::class, 'resume', 'token']] as [$class, $method, $name]) {
            $param = array_values(array_filter((new \ReflectionMethod($class, $method))->getParameters(), static fn(\ReflectionParameter $p): bool => $p->getName() === $name))[0] ?? null;
            $this->assertNotNull($param, "$class::$method \${$name}");
            $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class), "$class::$method \${$name}");
        }
    }
}
