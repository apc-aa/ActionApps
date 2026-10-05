<?php

declare(strict_types=1);

/**
 * A local transfer through the task queue: the definitions queue the first step, each step reads one
 * batch from the source and writes it with the run's new position, a failed source is asked again later,
 * a failed batch stops the run, the last step checks and finishes it. The progress page
 * (TransferProgress) only shows the run, Continue and End steer it.
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppSource;
use AA\Central\ExportRefused;
use AA\Central\InstallRun;
use AA\Central\Package\CollectOptions;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\SourceFailed;
use AA\Central\TransferCheck;
use AA\Central\TransferProgress;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use Closure;
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
        tearDown as private fixtureTearDown;
    }

    private const ITEM_A = '1234567890abcdef1234567890abcdef';
    private const ITEM_B = '2234567890abcdef1234567890abcdef';
    private const ITEM_C = '3234567890abcdef1234567890abcdef';
    private const SLICE2 = '7f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const SOURCE = ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'];
    /** another superadmin of this AA, and a user who is none */
    private const ADMIN  = 'admin-2';
    private const NOBODY = 'nobody';

    private FakeSource $source;
    /** [slice, after, filters, limit] of every batch the steps asked for */
    private array $batches = [];
    /** the seconds each source of a step was given */
    private array $timeouts = [];
    /** @var list<\Throwable> what the next batches throw, one each */
    private array $failures = [];
    /** @var Closure(int): list<array{string, LintFinding}> findings the source adds to the batch of each call (1, 2, ...) */
    private ?Closure $found = null;
    /** @var list<string> modules the user may not export */
    private array $denied = [];
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
        $this->source = new FakeSource(
            ['module' => [['id' => self::SLICE, 'type' => 'S', 'deleted' => 0, 'name' => 'Články', 'lang_file' => 'cz-utf8_news_lang.php']]],
            [self::SLICE => [self::entry(self::ITEM_A, 'A'), self::entry(self::ITEM_B, 'B')]],
        );
        $this->superadmins->superadmins = [self::USER, self::ADMIN];
        InstallRun::setTestDoubles($this->stepSource(...), $this->after);
        // a failed source and a failed write are logged - not into the test output
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        $this->fixtureTearDown();
    }

    private static function entry(string $id, string $headline): array
    {
        return ['id' => $id, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]], 'headline........' => [['value' => $headline, 'flag' => 0]]]];
    }

    /** the source of a step: the export of FakeSource, every batch logged; a failure queued in
     *  $failures is thrown instead of the batch */
    private function stepSource(InstallRun $run, int $timeout): AppSource
    {
        $this->timeouts[] = $timeout;
        $export           = new AppExport($this->source, self::SOURCE, fn(string $module): bool => !in_array($module, $this->denied, true), true);
        return new class ($export, $this->batchAsked(...)) implements AppSource {
            public function __construct(private AppExport $export, private Closure $asked) {}

            public function sameAa(): bool
            {
                return $this->export->sameAa();
            }

            public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
            {
                return $this->export->itemIds($slice, $allBins, $after, $filters);
            }

            public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
            {
                return $this->export->definitions($modules, $transfer, $allBins, $started);
            }

            public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = 500): array
            {
                $more          = ($this->asked)($slice, $after, $filters, $limit);
                [$xml, $found] = $this->export->batch($slice, $after, $allBins, $started, $filters, $limit);
                return [$xml, [...$found, ...$more]];
            }

            public function logins(array $slices, bool $allBins, array $filters = []): array
            {
                return $this->export->logins($slices, $allBins, $filters);
            }

            public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
            {
                return $this->export->itemCounts($slices, $allBins, $editedAfter, $filters);
            }

            public function leftOut(array $filters, array $slices, bool $allBins): array
            {
                return $this->export->leftOut($filters, $slices, $allBins);
            }
        };
    }

    /** @return list<array{string, LintFinding}> the findings to add to the batch */
    private function batchAsked(string $slice, string $after, array $filters, int $limit): array
    {
        $this->batches[] = [$slice, $after, $filters, $limit];
        $failure         = array_shift($this->failures);
        if ($failure) {
            throw $failure;
        }
        return $this->found ? ($this->found)(count($this->batches)) : [];
    }

    /** a transfer run of the example with its definitions written and its first step queued
     *  @param array<string, string> $filters source slice => ItemFilter text the preview chose
     *  @param array<string, int>    $counts  source slice => its items the preview counted on the source */
    private function transferred(array $filters = [], array $counts = []): string
    {
        $run = $this->newRun(InstallRun::TRANSFER, '7', $filters);
        if ($counts) {
            DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run)->previewed('', self::NOW, false, [], $filters ? new ImportChoices(filters: $filters) : null, $counts));
        }
        $confirmation = new ImportConfirmation('preview-session');
        $plan         = $this->plan(self::example(), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls = [];
        return $run;
    }

    /** a transfer run of the example with a second slice after its own
     *  @param array<string, int> $counts */
    private function transferredTwoSlices(array $counts = []): string
    {
        $package = self::example();
        $slice   = array_values(array_filter($package->modules, static fn(Module $m): bool => $m->id === self::SLICE))[0];
        $second  = new Module(Module::SLICE, self::SLICE2, [
            'module' => [array_merge($slice->rows['module'][0], ['name' => 'Druhá'])],
            'slice'  => [['name' => 'Druhá']],
            'field'  => [$slice->rows['field'][0]],
        ]);
        $run = $this->newRun(InstallRun::TRANSFER);
        if ($counts) {
            DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run)->previewed('', self::NOW, false, [], null, $counts));
        }
        $confirmation = new ImportConfirmation('preview-session');
        $plan         = $this->plan(self::with($package, ['modules' => [...$package->modules, $second]]), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls                = [];
        $this->source->tables['module'][]  = ['id' => self::SLICE2, 'type' => 'S', 'deleted' => 0, 'name' => 'Druhá', 'lang_file' => 'cz-utf8_news_lang.php'];
        $this->source->items[self::SLICE2] = [self::entry(self::ITEM_C, 'C')];
        return $run;
    }

    /** @return list<string> ids of the items of the new slice */
    private static function itemsOfTheSlice(string $target = ''): array
    {
        $target = ($target === '') ? self::id(2) : $target;
        return array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === $target), 'id'));
    }

    /** @param array<string, mixed> $props the page's view of the run, only these properties */
    private static function seen(string $run, array $props, string $uid = self::USER): array
    {
        $state = TransferProgress::state($run, $uid);
        return array_combine($props, array_map(static fn(string $p): mixed => array_key_exists($p, $state) ? $state[$p] : throw new \LogicException("No $p"), $props));
    }

    private static function record(string $run): InstallRun
    {
        return InstallRun::get($run) ?? throw new \LogicException("No run $run");
    }

    /** changes a stored property behind the run's back, as another process would */
    private static function storedSet(string $id, string $property, string $value): void
    {
        foreach (TableDb::$tables['object_text'] as $i => $row) {
            if (($row['object_id'] === $id) && ($row['property'] === $property)) {
                TableDb::$tables['object_text'][$i]['value'] = $value;
                return;
            }
        }
        TableDb::$tables['object_text'][] = ['id' => 999_999, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
    }

    /** the BatchFailed reason of $call, '' when it passed */
    private static function refused(Closure $call): string
    {
        try {
            $call();
        } catch (BatchFailed $e) {
            return $e->reason;
        }
        return '';
    }

    public function test_the_definitions_queue_the_first_step_and_the_page_only_shows_it(): void
    {
        $run = $this->transferred();

        self::assertSame(['Install_' . $run], array_column(self::queue(), 'selector'));
        self::assertSame(
            ['status' => '', 'state' => InstallRun::ITEMS, 'running' => false, 'waiting' => false, 'stuck' => false, 'done' => 0, 'total' => 0, 'written' => [],
                'error' => null, 'findings' => [], 'afterClose' => false, 'mayContinue' => false, 'mayEnd' => true],
            self::seen($run, ['status', 'state', 'running', 'waiting', 'stuck', 'done', 'total', 'written', 'error', 'findings', 'afterClose', 'mayContinue', 'mayEnd']),
        );
        self::assertSame(InstallRun::at(self::SLICE, ''), self::record($run)->position());
        self::assertSame([[], []], [$this->batches, $this->timeouts], 'the page asks no source');
    }

    public function test_the_last_batch_finishes_the_run_with_the_report_and_clears_the_caches(): void
    {
        $run = $this->transferred([], [self::SLICE => 2]);

        self::assertSame('done', self::runQueued());

        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame([[self::SLICE, '', [], CollectOptions::MAX_ITEMS]], $this->batches);
        self::assertSame(['state' => InstallRun::DONE, 'done' => 2, 'total' => 2, 'written' => [self::SLICE => 2], 'mayEnd' => false], self::seen($run, ['state', 'done', 'total', 'written', 'mayEnd']));
        $report = self::record($run)->report();
        self::assertSame([['source' => self::SLICE, 'target' => self::id(2), 'written' => 2, 'sourceCount' => 2, 'targetCount' => 2]], $report['slices']);
        self::assertSame([], $report['findings']);
        self::assertSame(['invalidatePagecache ' . self::id(1), 'invalidatePagecache ' . self::id(2), 'invalidatePagecache ' . self::id(3), 'constantsChanged'], $this->after->calls);
        self::assertSame('', self::record($run)->token(), 'a finished run keeps no token');
        self::assertSame(['stale'], self::runAll(), 'the safety row of the final phase finds the run done');
    }

    /** a step keeps to the queue's round: the source answers within it, the final check in its own short time */
    public function test_the_source_gets_its_timeout_from_the_step(): void
    {
        $this->transferred();

        self::runQueued();

        self::assertCount(2, $this->timeouts, 'the batch and the final check');
        self::assertGreaterThanOrEqual(20, $this->timeouts[0]);
        self::assertLessThanOrEqual(27, $this->timeouts[0], 'within the 30 s of a round, less its margin');
        self::assertSame(10, $this->timeouts[1]);
    }

    public function test_a_slice_over_one_batch_moves_the_position_and_the_progress(): void
    {
        $this->source->items[self::SLICE] = array_map(static fn(int $i): array => self::entry(sprintf('%032x', 0xb0000 + $i), "item $i"), range(1, 501));
        $run                              = $this->transferred([], [self::SLICE => 501]);
        self::assertSame(['done' => 0, 'total' => 501], self::seen($run, ['done', 'total']), 'the total of the preview before the first step');

        self::assertSame('step 2', self::runQueued());

        self::assertSame(InstallRun::at(self::SLICE, sprintf('%032x', 0xb0000 + 500)), self::record($run)->position());
        self::assertSame(['state' => InstallRun::ITEMS, 'done' => 500, 'total' => 501, 'written' => [self::SLICE => 500]], self::seen($run, ['state', 'done', 'total', 'written']));
        self::assertSame([], $this->after->calls, 'no finish yet');

        self::assertSame('done', self::runQueued());
        self::assertSame([[self::SLICE, '', [], 500], [self::SLICE, sprintf('%032x', 0xb0000 + 500), [], 500]], $this->batches);
        self::assertSame(['done' => 501, 'written' => [self::SLICE => 501]], self::seen($run, ['done', 'written']));
    }

    public function test_a_multi_slice_transfer_finishes_only_after_the_last_slice(): void
    {
        $run = $this->transferredTwoSlices([self::SLICE => 2, self::SLICE2 => 1]);

        self::runQueued();

        self::assertSame(InstallRun::at(self::SLICE2, ''), self::record($run)->position());
        self::assertSame(['state' => InstallRun::ITEMS, 'done' => 2, 'total' => 3], self::seen($run, ['state', 'done', 'total']));
        self::assertSame([], $this->after->calls, 'no finish after the first slice');

        self::assertSame('done', self::runQueued());
        self::assertSame(['state' => InstallRun::DONE, 'done' => 3, 'written' => [self::SLICE => 2, self::SLICE2 => 1]], self::seen($run, ['state', 'done', 'written']));
        self::assertSame([self::SLICE, self::SLICE2], array_column($this->batches, 0));
    }

    /** a sum missing a slice the source did not count would promise too little */
    public function test_the_total_is_unknown_while_a_slice_was_not_counted(): void
    {
        $run = $this->transferredTwoSlices([self::SLICE => 2]);

        self::runQueued();

        self::assertSame(['done' => 2, 'total' => 0], self::seen($run, ['done', 'total']));
    }

    public function test_a_filtered_run_transfers_and_checks_only_the_matching_items(): void
    {
        $this->source->tables['field'] = [['id' => 'headline........', 'slice_id' => self::SLICE, 'input_show_func' => 'fld:']];
        $filters                       = [self::SLICE => 'd-headline........-==-B'];
        $run                           = $this->transferred($filters);

        self::assertSame('done', self::runQueued());

        self::assertSame([[self::SLICE, '', $filters, 500]], $this->batches, 'the source gets the filter of the slice');
        self::assertSame([self::ITEM_B], self::itemsOfTheSlice());
        $report = self::record($run)->report();
        self::assertSame(1, $report['slices'][0]['sourceCount'], 'the source counts what the filter selects');
        self::assertSame([], $report['findings'], 'so no count-differs');
    }

    public function test_a_failed_source_is_asked_again_later_for_as_many_items_then_stops_the_run(): void
    {
        $run            = $this->transferred();
        $this->failures = array_fill(0, 3, new SourceFailed(SourceFailed::UNREACHABLE, 'down'));

        $before = time();
        self::assertSame('step 2', self::runQueued());
        $first = TransferProgress::state($run, self::USER);
        self::assertSame(
            ['state' => InstallRun::ITEMS, 'waiting' => true, 'failures' => 1, 'error' => null, 'mayContinue' => true],
            array_intersect_key($first, array_flip(['state', 'waiting', 'failures', 'error', 'mayContinue'])),
        );
        self::assertGreaterThanOrEqual($before + 60, $first['nextAt'], 'the source gets time to recover');

        self::runQueued();
        $second = TransferProgress::state($run, self::USER);
        self::assertSame([true, 2], [$second['waiting'], $second['failures']]);
        self::assertGreaterThan($first['nextAt'], $second['nextAt'], 'a longer wait each time');

        self::assertSame('error ' . SourceFailed::UNREACHABLE, self::runQueued());
        self::assertSame(
            ['state' => InstallRun::ERROR, 'waiting' => false, 'error' => ['code' => SourceFailed::UNREACHABLE, 'where' => ''], 'mayContinue' => true],
            self::seen($run, ['state', 'waiting', 'error', 'mayContinue']),
        );
        self::assertSame([500, 500, 500], array_column($this->batches, 3), 'an outage tells nothing of the batch size');
        self::assertSame([], self::itemsOfTheSlice());
        self::assertSame([], self::queue(), 'a run in error waits for Continue');
    }

    public function test_a_source_that_recovers_goes_on_from_the_position(): void
    {
        $run            = $this->transferred();
        $this->failures = [new SourceFailed(SourceFailed::FAILED, 'a broken answer')];

        self::assertSame(['step 2', 'done', 'stale'], self::runAll());

        self::assertSame([[self::SLICE, ''], [self::SLICE, '']], array_map(static fn(array $b): array => [$b[0], $b[1]], $this->batches));
        self::assertSame([500, 500], array_column($this->batches, 3));
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(InstallRun::DONE, self::record($run)->state());
    }

    public function test_a_refused_login_stops_the_run_at_once_and_asks_for_a_new_one(): void
    {
        $run = $this->transferred();
        DB_AA::transaction(static fn() => InstallRun::lockForUpdate($run)->seal('{"u":"https://source.example/aa/","t":"the-token"}', time() + 3600));
        self::assertSame([false, false], [TransferProgress::needsLogin(self::record($run)), TransferProgress::state($run, self::USER)['login']], 'the run keeps a token');
        $this->failures = [new SourceFailed(SourceFailed::LOGIN, 'refused')];

        self::assertSame('error ' . SourceFailed::LOGIN, self::runQueued());

        self::assertSame(
            ['state' => InstallRun::ERROR, 'error' => ['code' => SourceFailed::LOGIN, 'where' => 'source'], 'login' => true, 'mayContinue' => true],
            self::seen($run, ['state', 'error', 'login', 'mayContinue']),
        );
        self::assertTrue(TransferProgress::needsLogin(self::record($run)));
    }

    public function test_a_run_of_another_aa_without_its_token_asks_for_the_login(): void
    {
        $run = $this->transferred();

        self::assertTrue(TransferProgress::needsLogin(self::record($run)), 'no token kept - expired or never had one');
        self::assertTrue(TransferProgress::state($run, self::USER)['login']);

        self::runQueued();
        self::assertFalse(TransferProgress::state($run, self::USER)['login'], 'a finished run asks for nothing');
    }

    public function test_a_new_login_of_continue_is_sealed_in_the_run(): void
    {
        $run            = $this->transferred();
        $this->failures = [new SourceFailed(SourceFailed::LOGIN, 'refused')];
        self::runQueued();
        $sealed = '{"u":"https://source.example/aa/","t":"a-new-token"}';

        self::assertSame(TransferProgress::RESUMED, TransferProgress::resume($run, self::USER, $sealed));

        self::assertSame($sealed, self::record($run)->token());
        self::assertStringNotContainsString('a-new-token', (string) json_encode(TableDb::rows('object_text')), 'kept only encrypted');
        self::assertSame(['state' => InstallRun::ITEMS, 'error' => null, 'login' => false], self::seen($run, ['state', 'error', 'login']));
    }

    /** the clocks of the two AAs differ: a token of the source may be past its time here already */
    public function test_a_new_login_past_its_time_here_is_asked_for_again(): void
    {
        $run            = $this->transferred();
        $this->failures = [new SourceFailed(SourceFailed::LOGIN, 'refused')];
        self::runQueued();
        $b64    = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $sealed = '{"u":"https://source.example/aa/","t":"' . $b64('{"alg":"HS256"}') . '.' . $b64((string) json_encode(['exp' => time() - 1])) . '.sig"}';

        self::assertSame(TransferProgress::LOGIN, TransferProgress::resume($run, self::USER, $sealed));

        self::assertSame(['state' => InstallRun::ERROR, 'login' => true], self::seen($run, ['state', 'login']));
        self::assertSame([], self::queue());
    }

    public function test_an_export_error_of_a_batch_stops_the_run_with_its_code_and_place(): void
    {
        $run            = $this->transferred();
        $path           = Module::SLICE . '[' . self::SLICE . ']/item[' . self::ITEM_A . ']';
        $this->failures = [ExportRefused::failed([
            [$path, new LintFinding(LintFinding::WARNING, 'item-field', 'x', 0)],
            [$path, new LintFinding(LintFinding::ERROR, 'item-too-big', self::ITEM_A, 0)],
            [$path . '/headline........[0]', new LintFinding(LintFinding::ERROR, 'item-charset', self::ITEM_B, 0)],
        ])];

        self::assertSame('error ITEM_TOO_BIG', self::runQueued());

        self::assertSame(['code' => 'ITEM_TOO_BIG', 'where' => 'slice-module:' . self::SLICE . '/item:' . self::ITEM_A], TransferProgress::state($run, self::USER)['error'], 'the first error, its code upper case');
        self::assertSame(InstallRun::at(self::SLICE, ''), self::record($run)->position());
        self::assertSame([], self::itemsOfTheSlice());
    }

    public function test_a_slice_the_user_may_not_export_stops_the_run_as_denied(): void
    {
        $run          = $this->transferred();
        $this->denied = [self::SLICE];

        self::assertSame('error ' . InstallRun::SOURCE_DENIED, self::runQueued());

        self::assertSame(['code' => InstallRun::SOURCE_DENIED, 'where' => 'slice-module:' . self::SLICE], TransferProgress::state($run, self::USER)['error']);
    }

    public function test_a_source_slice_gone_stops_the_run(): void
    {
        $run                            = $this->transferred();
        $this->source->tables['module'] = [];

        self::assertSame('error ' . InstallRun::COLLECT_FAILED, self::runQueued());

        self::assertSame(['code' => InstallRun::COLLECT_FAILED, 'where' => 'slice-module:' . self::SLICE], TransferProgress::state($run, self::USER)['error']);
    }

    public function test_an_item_no_batch_can_take_stops_the_run_with_its_id(): void
    {
        $run = $this->transferred();
        $this->source->items[self::SLICE][0]['content']['fulltext........'] = [['value' => str_repeat('x', CollectOptions::MAX_BATCH_BYTES), 'flag' => 0]];

        self::assertSame('error ITEM_TOO_BIG', self::runQueued());

        self::assertStringContainsString(self::ITEM_A, TransferProgress::state($run, self::USER)['error']['where']);
        self::assertSame([], self::itemsOfTheSlice());
    }

    public function test_an_item_id_taken_on_this_aa_stops_the_batch_and_rolls_it_back(): void
    {
        $run                       = $this->transferred();
        TableDb::$tables['item'][] = ['id' => self::ITEM_B, 'short_id' => 2, 'slice_id' => self::FS, 'status_code' => 1];

        self::assertSame('error ITEM_ID_EXISTS', self::runQueued());

        self::assertSame(InstallRun::ERROR, self::record($run)->state());
        self::assertStringContainsString(self::ITEM_B, TransferProgress::state($run, self::USER)['error']['where']);
        self::assertSame([], self::itemsOfTheSlice(), 'not even the free item A');
        self::assertSame(InstallRun::at(self::SLICE, ''), self::record($run)->position());
    }

    /** content of no item names the id already - the lint stops the batch before its first write */
    public function test_an_item_id_in_use_by_orphan_content_stops_the_batch_before_any_write(): void
    {
        $run                          = $this->transferred();
        TableDb::$tables['content'][] = ['item_id' => self::ITEM_B, 'field_id' => 'headline........', 'number' => 0, 'text' => 'left over', 'flag' => 64];
        TableDb::$log                 = [];

        self::assertSame('error ITEM_ID_IN_USE', self::runQueued());

        self::assertSame(['code' => 'ITEM_ID_IN_USE', 'where' => 'slice-module:' . self::SLICE . '/item:' . self::ITEM_B], TransferProgress::state($run, self::USER)['error']);
        self::assertSame([], array_filter(TableDb::$log, static fn(string $q): bool => str_starts_with($q, 'INSERT INTO `item`')));
    }

    public function test_a_write_failing_in_the_middle_rolls_the_whole_batch_back_with_its_position(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`.*' . substr(self::ITEM_B, 0, 16) . '/i', 'throw');

        TableDb::$log = [];

        self::assertSame('error ' . InstallRun::WRITE_FAILED, self::runQueued());

        self::assertSame(['code' => InstallRun::WRITE_FAILED, 'where' => 'batch'], TransferProgress::state($run, self::USER)['error'], 'the DB message stays in the log');
        self::assertCount(1, array_filter(TableDb::$log, static fn(string $q): bool => str_starts_with($q, 'INSERT INTO `item`') && str_contains($q, '0x' . self::ITEM_A)), 'item A was written');
        self::assertSame([], self::itemsOfTheSlice(), 'item A rolled back with item B');
        self::assertSame([], array_values(array_filter(TableDb::rows('content'), static fn(array $r): bool => $r['item_id'] === self::ITEM_A)));
        self::assertSame(InstallRun::at(self::SLICE, ''), self::record($run)->position());
        self::assertSame([], self::record($run)->written());
    }

    public function test_continue_after_an_error_goes_on_from_the_position(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`/', 'throw');
        self::runQueued();
        TableDb::$fail = null;

        self::assertSame(TransferProgress::RESUMED, TransferProgress::resume($run, self::USER));

        self::assertSame(['state' => InstallRun::ITEMS, 'error' => null, 'mayContinue' => false], self::seen($run, ['state', 'error', 'mayContinue']));
        self::assertSame(['done', 'stale'], self::runAll());
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice(), 'each item once');
    }

    public function test_a_user_no_longer_superadmin_stops_the_run_without_asking_the_source(): void
    {
        $run                            = $this->transferred();
        $this->superadmins->superadmins = [self::ADMIN];

        self::assertSame('error ' . InstallRun::DENIED, self::runQueued());

        self::assertSame(['code' => InstallRun::DENIED, 'where' => 'user'], TransferProgress::state($run, self::USER)['error']);
        self::assertSame([], $this->batches, 'the source is not asked with lost rights');
        self::assertContains(self::USER, $this->superadmins->asked, 'asked about the user of the run - not the user of the request');
    }

    public function test_a_user_demoted_during_the_last_batch_gets_a_report_without_the_source(): void
    {
        $run         = $this->transferred([], [self::SLICE => 2]);
        $this->found = function (): array {
            $this->superadmins->superadmins = [self::ADMIN];
            return [];
        };

        self::assertSame('done', self::runQueued());

        self::assertCount(1, $this->timeouts, 'the final check built no source');
        $report = self::record($run)->report();
        self::assertNull($report['slices'][0]['sourceCount']);
        self::assertSame([['level' => LintFinding::WARNING, 'code' => TransferCheck::SOURCE_UNKNOWN, 'path' => 'source', 'ref' => SourceFailed::UNREACHABLE]], $report['findings']);
    }

    public function test_only_the_user_of_the_run_continues_it_any_superadmin_sees_and_ends_it(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`/', 'throw');
        self::runQueued();
        TableDb::$fail = null;

        self::assertSame(['mayContinue' => false, 'mayEnd' => true], self::seen($run, ['mayContinue', 'mayEnd'], self::ADMIN));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::resume($run, self::ADMIN)), 'Continue goes on with the rights of the user of the run');
        self::assertSame(InstallRun::ERROR, self::record($run)->state());

        self::assertSame(TransferProgress::ENDED, TransferProgress::end($run, self::ADMIN));
        self::assertSame(InstallRun::ENDED, self::record($run)->state());
    }

    public function test_others_neither_see_nor_steer_a_run(): void
    {
        $run = $this->transferred();

        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::state($run, self::NOBODY)));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::state($run, '')));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::end($run, self::NOBODY)));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::resume($run, self::NOBODY)));
        self::assertSame(InstallRun::ITEMS, self::record($run)->state());
    }

    public function test_no_run_has_no_progress(): void
    {
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::state(str_repeat('9', 32), self::USER)));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::end(str_repeat('9', 32), self::USER)));
    }

    /** a written install whose finish the queue never ran - the app works, End closes the run */
    public function test_an_install_waiting_for_its_finish_may_be_ended_without_a_login(): void
    {
        $install = $this->newRun(InstallRun::INSTALL, '7');
        $this->import($this->plan(), run: $install);
        $this->after->calls = [];

        self::assertSame(['state' => InstallRun::ITEMS, 'login' => false, 'mayEnd' => true], self::seen($install, ['state', 'login', 'mayEnd']), 'no batch asks the source');
        self::assertSame(TransferProgress::ENDED, TransferProgress::end($install, self::ADMIN));

        self::runAll();
        self::assertSame([], $this->after->calls, 'an install cleared its caches with its write');
        self::assertSame(InstallRun::ENDED, self::record($install)->state());
    }

    public function test_a_run_without_a_user_is_nobody_s(): void
    {
        $run = $this->transferred();
        self::storedSet($run, 'user', '');

        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::resume($run, '')));
        self::assertSame('FORBIDDEN', self::refused(static fn() => TransferProgress::state($run, '')));
    }

    public function test_continue_waits_while_a_step_may_still_work(): void
    {
        $run = $this->transferred();
        self::storedSet($run, 'lease_tok', 'a-step-works');
        self::storedSet($run, 'lease', (string) (time() + 100));
        $queue = self::queue();

        self::assertSame(TransferProgress::BUSY, TransferProgress::resume($run, self::USER));

        self::assertSame($queue, self::queue(), 'no second step beside it');
        self::assertTrue(TransferProgress::state($run, self::USER)['running']);
    }

    public function test_continue_of_a_run_on_its_way_or_finished_is_stale(): void
    {
        $run   = $this->transferred();
        $queue = self::queue();

        self::assertSame(TransferProgress::STALE, TransferProgress::resume($run, self::USER), 'its step is queued to run now');
        self::assertSame($queue, self::queue());

        self::runQueued();
        self::assertSame(TransferProgress::STALE, TransferProgress::resume($run, self::USER));
        self::assertSame(InstallRun::DONE, self::record($run)->state());
    }

    public function test_end_keeps_what_is_written_and_clears_the_caches_from_the_queue(): void
    {
        $run = $this->transferredTwoSlices();
        self::runQueued();

        self::assertSame(TransferProgress::ENDED, TransferProgress::end($run, self::USER));

        self::assertSame(['state' => InstallRun::ENDED, 'mayEnd' => false, 'mayContinue' => false], self::seen($run, ['state', 'mayEnd', 'mayContinue']));
        self::assertSame(TransferProgress::STALE, TransferProgress::end($run, self::USER), 'a second End');
        self::assertSame(TransferProgress::STALE, TransferProgress::resume($run, self::USER));
        self::assertSame([], $this->after->calls, 'after the commit of the end, from the queue');

        self::assertSame(['closed'], self::runAll(), 'the step of the second slice is cancelled');
        self::assertSame(['invalidatePagecache ' . self::id(1), 'invalidatePagecache ' . self::id(2), 'invalidatePagecache ' . self::id(3), 'invalidatePagecache ' . self::id(4), 'constantsChanged'], $this->after->calls);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame([self::SLICE], array_column($this->batches, 0), 'the second slice never asked for');
    }

    public function test_end_of_a_preview_clears_no_caches(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER);

        self::assertSame(TransferProgress::ENDED, TransferProgress::end($run, self::USER));
        self::runAll();

        self::assertSame([], $this->after->calls, 'nothing written');
        self::assertSame(['state' => InstallRun::ENDED, 'afterClose' => false], self::seen($run, ['state', 'afterClose']));
    }

    public function test_a_failing_cache_clear_is_marked_and_the_others_still_run(): void
    {
        $run               = $this->transferred();
        $this->after->fail = ['invalidatePagecache ' . self::id(2)];

        self::assertSame('done', self::runQueued());

        self::assertSame(['state' => InstallRun::DONE, 'afterClose' => true], self::seen($run, ['state', 'afterClose']));
        self::assertSame(['invalidatePagecache ' . self::id(1), 'invalidatePagecache ' . self::id(2), 'invalidatePagecache ' . self::id(3), 'constantsChanged'], $this->after->calls);
    }

    public function test_the_last_findings_of_the_batches_are_kept_and_their_warnings_counted(): void
    {
        $this->source->items[self::SLICE] = array_map(static fn(int $i): array => self::entry(sprintf('%032x', 0xb0000 + $i), "item $i"), range(1, 501));
        $run                              = $this->transferred();
        $path                             = Module::SLICE . '[' . self::SLICE . ']';
        // three codes of 40 each - FindingList keeps 50 of a code, so the batch keeps all of them
        $this->found = static fn(int $batch): array => [
            [$path, new LintFinding(LintFinding::SECURITY, 'test-security', "b$batch", 0)],
            [$path, new LintFinding(LintFinding::WARNING, 'test-warning', "b$batch", 0)],
            ...array_map(static fn(int $i): array => [$path, new LintFinding(LintFinding::INFO, 'test-info-' . ($i % 3), "b$batch-$i", 0)], range(0, 119)),
        ];

        self::assertSame(['step 2', 'done', 'stale'], self::runAll());

        $kept = self::record($run)->findings();
        self::assertCount(200, $kept, 'the last 200 of 242');
        self::assertSame(['b1-41', 'b2-119'], [$kept[0]['ref'], $kept[199]['ref']], 'the oldest dropped');
        self::assertSame([['level' => LintFinding::SECURITY, 'code' => 'test-security', 'path' => $path, 'ref' => 'b2']], array_values(array_filter($kept, static fn(array $f): bool => $f['code'] === 'test-security')), 'the S of the first batch dropped');
        self::assertNotContains('test-warning', array_column($kept, 'code'), 'a warning is counted, not kept');
        self::assertSame(['test-warning' => 2], array_intersect_key(self::record($run)->warnings()[self::SLICE] ?? [], ['test-warning' => 0]));
        self::assertSame(TransferProgress::state($run, self::USER)['findings'], $kept);
    }

    /** the source is asked only by a step: the page, Continue and End go without it */
    public function test_the_page_continue_and_end_do_not_ask_the_source(): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `item`/', 'throw');
        self::runQueued();
        TableDb::$fail  = null;
        $this->timeouts = [];
        InstallRun::setTestDoubles(static fn(): never => throw new \LogicException('the source was asked'), $this->after);

        TransferProgress::state($run, self::USER);
        TransferProgress::resume($run, self::USER);
        TransferProgress::end($run, self::USER);

        self::assertSame(InstallRun::ENDED, self::record($run)->state());
    }

    /** AA's error handler keeps the arguments of every stack frame - a token must show as redacted */
    public function test_secret_parameters_are_kept_out_of_stack_traces(): void
    {
        foreach ([[TransferProgress::class, 'resume', 'sealed'], [InstallRun::class, 'preview', 'token'], [InstallRun::class, 'sealedUntil', 'sealed']] as [$class, $method, $name]) {
            $param = array_values(array_filter((new \ReflectionMethod($class, $method))->getParameters(), static fn(\ReflectionParameter $p): bool => $p->getName() === $name))[0] ?? null;
            $this->assertNotNull($param, "$class::$method \${$name}");
            $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class), "$class::$method \${$name}");
        }
    }
}
