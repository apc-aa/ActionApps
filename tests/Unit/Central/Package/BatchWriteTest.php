<?php

declare(strict_types=1);

/**
 * A transfer on the target: the definitions write puts the run to its items, its first step queued, and
 * brings the state of the source installation along. Importer::writeItems() writes one batch inside the
 * transaction of a step and tells the position after it - a failure throws, so the transaction rolls the
 * batch back. A step of the run reads the batch, writes it with the run's new position; a failed step
 * leaves the run in error at its position. Item ids stay on another AA and are derived from the run on
 * the same AA.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\AppExport;
use AA\Central\InstallRun;
use AA\Central\Package\Collector;
use AA\Central\Package\LintFinding;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportTarget;
use AA\Central\Package\ItemLint;
use AA\Central\Package\Planner;
use AA\Central\Package\TargetCharset;
use AA\Central\Package\Validator;
use AA\Central\Package\WriteError;
use AA_Perm;
use LogicException;
use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Central\Package\Fixtures\TableDbTarget;

require_once __DIR__ . '/fixtures/ImportFixture.php';
require_once __DIR__ . '/fixtures/FakeSource.php';

final class BatchWriteTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    /** items of the example's slice, in id order - C comes in a later batch */
    private const ITEM_A  = '1234567890abcdef1234567890abcdef';
    private const ITEM_B  = '2234567890abcdef1234567890abcdef';
    private const ITEM_C  = '3234567890abcdef1234567890abcdef';
    private const ITEM_D  = '4234567890abcdef1234567890abcdef';
    /** an id of no item */
    private const OUTSIDE = '0123456789abcdef0123456789abcdef';
    /** a second slice of the definitions (twoSlices()) */
    private const SLICE2 = '7f1e2d3c4b5a69788796a5b4c3d2e1f0';
    /** meta/source of the batches a step reads */
    private const SOURCE = ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'];

    /** the target the importer asks - null: the fake */
    private ?ImportTarget $db = null;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
    }

    /** the example with a relation field and the fields of readers; a Reader Management slice with $readers */
    private static function definitions(bool $readers = false): Package
    {
        $package = self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Related', 'input_insert_func' => 'ids:', 'text_stored' => '1'],
            ['id' => 'con_email.......', 'type' => 'con_email', 'name' => 'E-mail'],
            ['id' => 'password........', 'type' => 'password', 'name' => 'Password', 'input_insert_func' => 'pwd:'],
        ]);
        return $readers ? self::cell($package, self::SLICE, 'slice', 0, ['type' => ItemLint::READER_SLICE]) : $package;
    }

    /** a transfer run with its definitions written; its id */
    private function transferred(string $source = '7', ?Package $definitions = null): string
    {
        $run = $this->newRun(InstallRun::TRANSFER, $source);
        $this->import(...$this->confirmed($this->plan($definitions ?? self::definitions(), true)), run: $run);
        return $run;
    }

    /** [plan, importer, token]: the S findings (the domains) confirmed in the preview
     *  @return array{ImportPlan, Importer, string|null} */
    private function confirmed(ImportPlan $plan): array
    {
        $confirmation = new ImportConfirmation('preview-session');
        return [$plan, $this->importer($this->db, $confirmation), $confirmation->token($plan, self::USER)];
    }

    /** @param array<string, string|list<string>> $values field => value(s) */
    private static function entry(string $id, array $values): array
    {
        $content = ['status_code.....' => [0 => ['value' => '1', 'flag' => 0]]];
        foreach ($values as $field => $list) {
            foreach ((array) $list as $i => $value) {
                $content[$field][$i] = ['value' => $value, 'flag' => 0];
            }
        }
        return ['id' => $id, 'content' => $content];
    }

    /** a batch of the example's slice after $after @param list<array<string, mixed>> $items in id order */
    private static function batch(array $items, string $after = '', bool $finished = false, string $slice = self::SLICE): Package
    {
        $next = $items ? end($items)['id'] : $after;
        return new Package('Články', self::example()->source, [new Module(Module::SLICE, $slice, [], items: $items)], withItems: true, batch: new Batch($after, $next, $finished));
    }

    /** the definitions with a second slice after the example's */
    private static function twoSlices(): Package
    {
        $package = self::definitions();
        $slice   = array_values(array_filter($package->modules, static fn(Module $m): bool => $m->id === self::SLICE))[0];
        $second  = new Module(Module::SLICE, self::SLICE2, [
            'module' => [array_merge($slice->rows['module'][0], ['name' => 'Druhá'])],
            'slice'  => [['name' => 'Druhá']],
            'field'  => [$slice->rows['field'][0]],
        ]);
        return self::with($package, ['modules' => [...$package->modules, $second]]);
    }

    /** the definitions with the site's domains */
    private static function withDomains(string ...$urls): Package
    {
        return self::module(self::definitions(), self::SITE, static fn(Module $m): Module => self::with($m, ['transfer' => ['alternate-url' => $urls]]));
    }

    /** the site settings' alternate_url rows on the target @return list<string> */
    private static function domains(): array
    {
        return array_values(array_column(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'alternate_url'), 'value'));
    }

    /** The batch written as a step of the run writes it - in a transaction, on the locked run - read
     *  from its own cursor or from $at
     *  @param list<array{string, LintFinding}> $found what the source found making it
     *  @return array<string, mixed> writeItems() */
    private function write(string $run, Package $batch, ?array $at = null, array $found = []): array
    {
        return DB_AA::transaction(fn(): array => $this->importer($this->db)->writeItems(InstallRun::lockForUpdate($run), $batch, $at ?? InstallRun::at(self::SLICE, $batch->batch->after), $found));
    }

    /** the WriteError of write() */
    private function refused(string $run, Package $batch, ?array $at = null, array $found = []): WriteError
    {
        try {
            $this->write($run, $batch, $at, $found);
        } catch (WriteError $e) {
            return $e;
        }
        self::fail('the batch was written');
    }

    /** The steps of the runs read their batches of the example's slice from a source AA with $items -
     *  through AppExport, as from this AA - and write them asking the DB as they do live.
     *  @param list<array<string, mixed>> $items in id order */
    private function steps(array $items): FakeSource
    {
        $source = new FakeSource(['module' => [['id' => self::SLICE, 'type' => 'S', 'deleted' => 0, 'name' => 'Články', 'lang_file' => 'cz-utf8_news_lang.php']]], [self::SLICE => $items]);
        InstallRun::setTestDoubles(static fn(): AppExport => new AppExport($source, self::SOURCE, static fn(): bool => true, true), $this->after);
        return $source;
    }

    /** the transaction (BEGIN ... COMMIT of the log) with the first statement matching $statement @return list<string> */
    private static function transactionOf(string $statement): array
    {
        $begin = null;
        foreach (TableDb::$log as $i => $q) {
            if ($q === 'BEGIN') {
                $begin = $i;
            } elseif (($begin !== null) && preg_match($statement, $q)) {
                $end = array_search('COMMIT', array_slice(TableDb::$log, $i, null, true), true);
                return array_slice(TableDb::$log, $begin, (int) $end - $begin + 1);
            }
        }
        self::fail("no transaction with $statement");
    }

    private static function locked(string $run, callable $change): void
    {
        DB_AA::transaction(static fn() => $change(InstallRun::lockForUpdate($run)));
    }

    /** the texts of an item's field on the target @return list<string> */
    private static function texts(string $item, string $field): array
    {
        return array_values(array_column(array_filter(TableDb::rows('content'), static fn(array $r): bool => ($r['item_id'] === $item) && ($r['field_id'] === $field)), 'text'));
    }

    /** @return list<string> ids of the items of the new slice */
    private static function itemsOfTheSlice(): array
    {
        return array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::id(2)), 'id'));
    }

    /** "<level> <code> <path> <ref>" - of LintFindings, or of the plain findings of writeItems()
     *  @param list<array{string, LintFinding}>|list<array{level: string, code: string, path: string, ref: string}> $findings
     *  @return list<string> */
    private static function codes(array $findings): array
    {
        $list = array_map(static fn(array $f): string => isset($f['code']) ? "{$f['level']} {$f['code']} {$f['path']} {$f['ref']}" : "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $findings);
        sort($list);
        return $list;
    }

    // ---------------------------------------------------------------- the definitions

    public function test_the_definitions_write_puts_the_run_to_its_items_and_queues_its_first_step(): void
    {
        $run = $this->transferred();

        $back = InstallRun::get($run);
        self::assertSame(InstallRun::ITEMS, $back->state());
        self::assertSame([self::SLICE], $back->slices(), 'the slices whose items follow');
        self::assertSame(InstallRun::at(self::SLICE, ''), $back->position());
        self::assertSame(['Install_' . $run], array_column(self::queue(), 'selector'), 'committed with the definitions');
        self::assertSame(hash('sha256', self::DEFINITIONS), $back->fingerprint());
        self::assertSame(self::id(2), $back->idMap()->module(self::SLICE));
        self::assertSame([12 => 1000, 13 => 1001], $back->idMap()->views(), 'the numbers the rows got');
    }

    public function test_an_install_run_has_no_items_and_its_only_step_finishes_it(): void
    {
        $run = $this->newRun();
        $this->import($this->plan(), run: $run);
        $calls = $this->after->calls;

        self::assertSame([], InstallRun::get($run)->slices());
        self::assertSame(InstallRun::DONE, self::runQueued());
        self::assertSame([InstallRun::DONE, []], [InstallRun::get($run)->state(), InstallRun::get($run)->report()], 'no check of items');
        self::assertSame($calls, $this->after->calls, 'the write cleared the caches of an install');
        self::assertSame([], self::queue());
    }

    public function test_the_definitions_go_to_a_run_only_once(): void
    {
        $run    = $this->transferred();
        $before = self::tables();

        try {
            $this->import($this->plan(self::definitions(), true), run: $run);
            self::fail('a second definitions write');
        } catch (ImportFailed) {
        }

        self::assertSame($before, self::tables());
    }

    /** another preview of the run while the write planned (another session): its filters would go to the batches */
    public function test_choices_changed_since_the_plan_refuse_the_write(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER, '7', [self::SLICE => 'd-headline........-==-B']);
        $sum = InstallRun::get($run)->choicesSum();
        self::locked($run, static fn(InstallRun $r) => $r->previewed('', self::NOW, false, [], new ImportChoices()));
        $before = self::tables();
        [$plan, $importer, $token] = $this->confirmed($this->plan(self::definitions(), true));

        try {
            $importer->write($plan, $run, self::DEFINITIONS, $token, $sum);
            self::fail('a write of choices no longer in the run');
        } catch (ImportFailed) {
        }

        self::assertSame($before, self::tables());
        self::assertSame([InstallRun::PREVIEW, []], [InstallRun::get($run)->state(), InstallRun::get($run)->filters()]);
        $importer->write($plan, $run, self::DEFINITIONS, $token, InstallRun::get($run)->choicesSum());
        self::assertSame(InstallRun::ITEMS, InstallRun::get($run)->state(), 'the sum of the run as it is now');
    }

    /** the owner found by name and e-mail or created, the reading password, the domains of the site */
    public function test_a_transfer_brings_the_state_of_the_source_installation(): void
    {
        $known = str_repeat('ab', 16);
        TableDb::$tables['slice_owner'] = [['id' => $known, 'name' => 'Škola', 'email' => 'office@source.example']];
        $state = static fn(Module $m): array => match ($m->type) {
            Module::SITE  => ['owner' => ['name' => 'Škola', 'email' => 'office@source.example'], 'alternate-url' => ['www.source.example', 'source.example']],
            Module::SLICE => ['owner' => ['name' => 'Nová', 'email' => 'nova@source.example'], 'reading-password' => 'tajné'],
            default       => [],
        };
        $package = self::with(self::definitions(), ['modules' => array_map(static fn(Module $m): Module => self::with($m, ['transfer' => $state($m)]), self::definitions()->modules)]);

        $this->transferred(definitions: $package);

        $owners = array_column(TableDb::rows('module'), 'owner', 'id');
        $new    = array_values(array_filter(TableDb::rows('slice_owner'), static fn(array $r): bool => $r['id'] !== $known));
        self::assertSame([['name' => 'Nová', 'email' => 'nova@source.example']], array_map(static fn(array $r): array => ['name' => $r['name'], 'email' => $r['email']], $new), 'one owner created');
        self::assertSame([$known, $new[0]['id'], ''], [$owners[self::id(1)], $owners[self::id(2)], $owners[self::id(3)]], 'the site: found; the slice: created; the Alerts module: none');
        self::assertSame('tajné', array_column(TableDb::rows('slice'), 'reading_password', 'id')[self::id(2)]);
        $settings = string2id('AA_Modulesettings_Site' . self::id(1));
        $domains  = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] === $settings) && ($r['property'] === 'alternate_url'));
        self::assertSame([0 => 'www.source.example', 1 => 'source.example'], array_column($domains, 'value', 'priority'));
    }

    /** the owner of the source, in module and slice alike - the owners of this AA, which an install
     *  offers, play no part in a transfer */
    public function test_a_transfer_gives_the_new_slice_the_owner_of_the_source(): void
    {
        $this->target->owners = [str_repeat('ab', 16) => 'Owner of this AA'];     // ignored
        $package = self::module(self::definitions(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['owner' => ['name' => 'Nová', 'email' => 'nova@source.example']]]));

        $this->transferred(definitions: $package);

        $created = array_column(TableDb::rows('slice_owner'), 'id', 'name')['Nová'] ?? null;
        self::assertNotNull($created, 'the source owner is created here');
        self::assertSame([$created, $created], [array_column(TableDb::rows('module'), 'owner', 'id')[self::id(2)], array_column(TableDb::rows('slice'), 'owner', 'id')[self::id(2)]]);
        self::assertSame(['', ''], [array_column(TableDb::rows('module'), 'owner', 'id')[self::id(1)], array_column(TableDb::rows('module'), 'owner', 'id')[self::id(3)]], 'a module of no source owner gets none');
    }

    /** the settings fields' values: content of the new slice id, their texts rewritten as any definition */
    public function test_a_transfer_brings_the_values_of_the_settings_fields(): void
    {
        $values  = ['_email..........' => [['value' => 'office@example.org {view:12}', 'flag' => 64], ['value' => '42', 'flag' => 1], 1000001 => ['value' => 'second', 'flag' => 64]]];
        $package = self::module(self::definitions(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['setting-values' => $values]]));
        $run     = $this->newRun(InstallRun::TRANSFER);

        $report = $this->import(...$this->confirmed($this->plan($package, true)), run: $run);

        $rows = array_values(array_filter(TableDb::rows('content'), static fn(array $r): bool => $r['item_id'] === self::id(2)));
        self::assertSame(
            // a number value in the number column, a translation under its number
            [['_email..........', 0, 'office@example.org {view:1000}', 64], ['_email..........', 42, null, 1], ['_email..........', 1000001, 'second', 64]],
            array_map(static fn(array $r): array => [$r['field_id'], $r['number'], $r['text'] ?? null, $r['flag']], $rows),
        );
        self::assertSame(0, $report->settingsToFill(), 'nothing left to fill');
    }

    // ---------------------------------------------------------------- batches

    public function test_a_batch_is_written_and_tells_the_position_after_it(): void
    {
        $run = $this->transferred();

        $result = $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A']), self::entry(self::ITEM_B, ['headline........' => 'B'])]));

        self::assertSame(['position' => InstallRun::at(self::SLICE, self::ITEM_B), 'finished' => false, 'written' => 2, 'warnings' => [], 'findings' => []], $result);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertCount(1, array_unique(TableDb::$handles), 'one handle - the run and the items in one transaction');
        // the step stores the position and the counts - written twice they would count twice
        self::assertSame([InstallRun::at(self::SLICE, ''), []], [InstallRun::get($run)->position(), InstallRun::get($run)->written()]);
    }

    public function test_the_warnings_the_source_found_are_counted_and_its_error_stops_the_batch(): void
    {
        $run  = $this->transferred();
        $path = 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_A . ']';

        $result = $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]), null, [[$path, new LintFinding(LintFinding::WARNING, ValueCodec::INVALID_BYTES, 'x', 0)]]);

        self::assertSame([ValueCodec::INVALID_BYTES => 1], $result['warnings']);
        self::assertSame(['W ' . ValueCodec::INVALID_BYTES . " $path x"], self::codes($result['findings']), 'plain data for the run');

        $e = $this->refused($run, self::batch([self::entry(self::ITEM_B, ['headline........' => 'B'])], self::ITEM_A), null, [[$path, new LintFinding(LintFinding::ERROR, Collector::ITEM_TOO_BIG, self::ITEM_B, 0)]]);

        self::assertSame([Collector::ITEM_TOO_BIG, $path], [$e->reason, $e->path]);
        self::assertSame([self::ITEM_A], self::itemsOfTheSlice());
    }

    public function test_the_last_batch_of_the_last_slice_finishes_the_items(): void
    {
        $run = $this->transferred();
        $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]));

        $result = $this->write($run, self::batch([self::entry(self::ITEM_B, ['headline........' => 'B'])], self::ITEM_A, true));

        self::assertSame([InstallRun::at(self::SLICE, self::ITEM_B), true, 1], [$result['position'], $result['finished'], $result['written']]);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
    }

    /** @return array<string, array{Package, array{slice: string, cursor: string}}> the batch, the position it is written at */
    public static function misplacedBatches(): array
    {
        // InstallRun::at() not here: a data provider runs before the AA boots
        $b = [self::entry(self::ITEM_B, ['headline........' => 'B'])];
        return [
            'another cursor' => [self::batch($b, self::ITEM_A), ['slice' => self::SLICE, 'cursor' => '']],
            'another slice'  => [self::batch($b, '', false, self::SLICE2), ['slice' => self::SLICE, 'cursor' => '']],
        ];
    }

    /** a source that answered for another position than it was asked */
    #[DataProvider('misplacedBatches')]
    public function test_a_batch_read_from_another_position_is_refused(Package $batch, array $at): void
    {
        $run = $this->transferred(definitions: self::twoSlices());
        [TableDb::$log, $before] = [[], self::tables()];

        $e = $this->refused($run, $batch, $at);

        self::assertSame([Validator::BATCH, 'meta/batch'], [$e->reason, $e->path]);
        self::assertSame([], TableDb::writes());
        self::assertSame($before, self::tables());
    }

    /** @return array<string, array{string, class-string, ?array{string, string}}> the failing mode, what writeItems() throws, its reason and path */
    public static function failures(): array
    {
        $at = 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_B . ']/headline........[0]';
        return [
            'refused statement' => ['false', WriteError::class, [WriteError::DB, $at]],
            'lost connection'   => ['throw', \RuntimeException::class, null],
        ];
    }

    /** @param class-string $class @param ?array{string, string} $error */
    #[DataProvider('failures')]
    public function test_a_failure_mid_batch_rolls_it_back(string $mode, string $class, ?array $error): void
    {
        $run = $this->transferred();
        self::failAt('/^INSERT INTO `content` .*0x' . self::ITEM_B . '/', $mode);

        try {
            $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A']), self::entry(self::ITEM_B, ['headline........' => 'B'])]));
            self::fail('the batch was written');
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
        }

        if ($error) {
            self::assertSame($error, [$e->reason, $e->path]);
        }
        self::assertSame([], self::itemsOfTheSlice(), 'item A rolled back too');
    }

    public function test_a_value_the_slice_charset_cannot_hold_stops_the_batch(): void
    {
        $run = $this->transferred(definitions: self::lang(self::definitions(), self::SLICE, 'cz_news_lang.php'));

        $e = $this->refused($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'Čína 中国'])]));

        self::assertSame([TargetCharset::CHARSET, 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_A . ']/headline........[0]'], [$e->reason, $e->path]);
        self::assertSame('CHARSET', Importer::errorCode($e->reason), 'the code the run keeps');
        self::assertSame([], self::itemsOfTheSlice());
    }

    public function test_a_transfer_keeps_the_authors_of_the_items(): void
    {
        $run = $this->transferred();

        $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A', 'posted_by.......' => 'uid-17', 'edited_by.......' => 'uid-18'])]));

        $item = array_values(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['id'] === self::ITEM_A))[0];
        self::assertSame(['uid-17', 'uid-18'], [$item['posted_by'], $item['edited_by']], 'not the importing user');
    }

    public function test_a_finished_slice_hands_over_to_the_next_and_the_last_finishes_the_items(): void
    {
        $run = $this->transferred(definitions: self::twoSlices());

        $first = $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])], '', true));
        self::assertSame([InstallRun::at(self::SLICE2, ''), false], [$first['position'], $first['finished']]);

        $last = $this->write($run, self::batch([self::entry(self::ITEM_D, ['headline........' => 'D'])], '', true, self::SLICE2), InstallRun::at(self::SLICE2, ''));
        self::assertSame([InstallRun::at(self::SLICE2, self::ITEM_D), true], [$last['position'], $last['finished']], 'the last slice: no next one');
    }

    /** @return array<string, array{\Closure(self): void, Package, array{string, string}}> what goes wrong, the batch, the error code the run keeps and the path */
    public static function refusedBatches(): array
    {
        $S = 'slice-module[' . self::SLICE . ']';
        return [
            'the slice deleted since'  => [static function (): void {
                TableDb::$tables['slice'] = array_values(array_filter(TableDb::rows('slice'), static fn(array $r): bool => $r['id'] !== self::id(2)));
            }, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]), ['GONE', $S]],
            'a table not InnoDB'       => [static fn(self $t) => $t->target->engines = ['content' => 'MyISAM'], self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]), ['ENGINE', 'batch']],
            'the source stopped short' => [static fn(): null => null, self::batch([], '', false), ['ITEM_TOO_BIG', "$S/item"]],
            'an id with rights'        => [static fn(self $t) => $t->target->inUse = [self::ITEM_A], self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]), ['ITEM_ID_IN_USE', "$S/item[" . self::ITEM_A . ']']],
        ];
    }

    /** @param \Closure(self): void $change @param array{string, string} $error */
    #[DataProvider('refusedBatches')]
    public function test_a_refused_batch_writes_nothing(\Closure $change, Package $batch, array $error): void
    {
        $run = $this->transferred();
        $change($this);

        $e = $this->refused($run, $batch);

        self::assertSame($error, [Importer::errorCode($e->reason), $e->path]);
        self::assertSame([], self::itemsOfTheSlice());
    }

    /** a step writes only in the transaction that moves the run on - outside one, half a batch would stay */
    public function test_no_batch_outside_a_transaction(): void
    {
        $run = $this->transferred();
        TableDb::$log = [];

        try {
            $this->importer()->writeItems(InstallRun::get($run), self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]), InstallRun::at(self::SLICE, ''));
            self::fail('a batch outside a transaction');
        } catch (WriteError $e) {
            self::assertSame([WriteError::DB, 'batch'], [$e->reason, $e->path]);
        }
        self::assertSame([], TableDb::writes());
    }

    public function test_an_install_run_takes_no_items(): void
    {
        $run = $this->newRun();
        $this->import($this->plan(), run: $run);

        $this->expectException(LogicException::class);
        $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]));
    }

    // ---------------------------------------------------------------- the steps of the run

    /** the items commit with the run's new position or not at all */
    public function test_a_step_writes_its_batch_with_the_new_position_in_one_transaction(): void
    {
        $run = $this->transferred(definitions: self::twoSlices());
        $this->steps([self::entry(self::ITEM_A, ['headline........' => 'A']), self::entry(self::ITEM_B, ['headline........' => 'B'])]);
        TableDb::$log = [];

        self::assertSame('step 2', self::runQueued());

        $back = InstallRun::get($run);
        self::assertSame([InstallRun::ITEMS, InstallRun::at(self::SLICE2, ''), [self::SLICE => 2]], [$back->state(), $back->position(), $back->written()]);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        $transaction = self::transactionOf('/^INSERT INTO `item`/');
        self::assertNotEmpty(preg_grep('/^INSERT INTO `object_text`.*' . self::SLICE2 . '/', $transaction), 'the position of the next slice');
        self::assertSame(['Install_' . $run], array_column(self::queue(), 'selector'), 'the next step');
    }

    /** @return array<string, array{string, array{string, string}}> the failing mode, the error the run keeps */
    public static function stepFailures(): array
    {
        return [
            'refused statement' => ['false', ['DB', 'slice-module:' . self::SLICE . '/item:' . self::ITEM_B . '/headline........:0']],
            'lost connection'   => ['throw', ['WRITE_FAILED', 'batch']],       // no raw DB message in the run
        ];
    }

    /** @param array{string, string} $error */
    #[DataProvider('stepFailures')]
    public function test_a_failed_step_leaves_the_run_in_error_at_its_position(string $mode, array $error): void
    {
        $run = $this->transferred();
        $this->steps([self::entry(self::ITEM_A, ['headline........' => 'A']), self::entry(self::ITEM_B, ['headline........' => 'B'])]);
        $log = ini_set('error_log', '/dev/null');
        self::failAt('/^INSERT INTO `content` .*0x' . self::ITEM_B . '/', $mode);

        try {
            self::runQueued();
        } finally {
            ini_set('error_log', (string) $log);
        }

        $back = InstallRun::get($run);
        self::assertSame([InstallRun::ERROR, $error, InstallRun::at(self::SLICE, ''), []], [$back->state(), $back->error(), $back->position(), $back->written()]);
        self::assertSame([], self::itemsOfTheSlice(), 'item A rolled back too');
        self::assertSame([], self::queue(), 'no step without Continue');
    }

    public function test_continue_after_an_error_writes_the_batch_again(): void
    {
        $run = $this->transferred();
        $this->steps([self::entry(self::ITEM_A, ['headline........' => 'A'])]);
        $log = ini_set('error_log', '/dev/null');
        self::failAt('/^INSERT INTO `item`/');
        try {
            self::runQueued();
        } finally {
            ini_set('error_log', (string) $log);
        }
        TableDb::$fail = null;

        self::locked($run, static fn(InstallRun $r) => $r->resume(self::USER));
        self::runQueued();

        self::assertSame([self::ITEM_A], self::itemsOfTheSlice());
        self::assertSame([InstallRun::DONE, [self::SLICE => 1]], [InstallRun::get($run)->state(), InstallRun::get($run)->written()]);
    }

    /** the queue may hand a step out twice (a runner that died, a hand-run Execute) */
    public function test_a_step_queued_twice_writes_its_batch_once(): void
    {
        $run = $this->transferred(definitions: self::twoSlices());
        $this->steps([self::entry(self::ITEM_A, ['headline........' => 'A'])]);
        TableDb::$tables['toexecute'][] = ['id' => 99_999] + self::queue()[0];

        self::runQueued();
        self::assertSame('stale', self::runQueued());

        self::assertSame([self::ITEM_A], self::itemsOfTheSlice());
        self::assertSame([self::SLICE => 1], InstallRun::get($run)->written());
    }

    /** the rights are checked again by every step - the batches carry password hashes */
    public function test_a_step_of_a_user_no_longer_a_superadmin_stops_the_run(): void
    {
        $run = $this->transferred();
        $this->steps([self::entry(self::ITEM_A, ['headline........' => 'A'])]);
        $this->superadmins->superadmins = [];

        self::assertSame('error ' . InstallRun::DENIED, self::runQueued());

        self::assertSame([InstallRun::ERROR, [InstallRun::DENIED, 'user']], [InstallRun::get($run)->state(), InstallRun::get($run)->error()]);
        self::assertSame([], self::itemsOfTheSlice());
        self::assertContains(self::USER, $this->superadmins->asked);
    }

    public function test_the_definitions_of_a_run_are_its_user_s(): void
    {
        $run   = $this->newRun(InstallRun::TRANSFER);
        $other = new Importer($this->target, 'another-admin', null, self::NOW, mayInstall: $this->authority->mayInstall(...), afterCommit: $this->after);

        try {
            $this->import($this->plan(self::definitions(), true), $other, run: $run);
            self::fail('another user wrote the definitions');
        } catch (ImportFailed $e) {
            self::assertSame('denied', $e->getPrevious()->reason);
        }
        self::assertSame(InstallRun::PREVIEW, InstallRun::get($run)->state());
        self::assertSame([], TableDb::writes());
    }

    public function test_an_install_run_takes_no_state_of_the_source_installation(): void
    {
        $plan = $this->plan(self::module(self::definitions(), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['reading-password' => 'x']])), true);

        $this->expectException(LogicException::class);
        $this->import($plan, run: $this->newRun());
    }

    // ---------------------------------------------------------------- the site's domains

    /** a copy in the same AA: the domains are the source site's, still in use here */
    public function test_a_copy_in_the_same_aa_leaves_the_domains_out(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER, InstallRun::LOCAL);

        $report = $this->import(...$this->confirmed($this->plan(self::withDomains('https://www.source.example/'), true)), run: $run);

        self::assertSame([], self::domains());
        self::assertContains('W ' . Planner::DOMAIN_DROPPED . ' site-module[' . self::SITE . ']/alternate-url[0] https://www.source.example/', self::codes($report->findings));
        self::assertNotContains(Planner::DOMAIN_KEPT, array_map(static fn(array $f): string => $f[1]->code, $report->findings));
    }

    /** a Central row that names this AA: the source is this installation, whatever the transport */
    public function test_a_remote_source_that_is_this_aa_leaves_the_domains_out_and_derives_the_item_ids(): void
    {
        $run = $this->newRun(InstallRun::TRANSFER, '7');
        self::locked($run, static fn(InstallRun $r) => $r->previewed(hash('sha256', 'definitions'), self::NOW, true));

        $this->import(...$this->confirmed($this->plan(self::withDomains('https://www.source.example/'), true)), run: $run);
        $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]));

        self::assertSame([], self::domains());
        self::assertSame([string2id($run . self::ITEM_A)], self::itemsOfTheSlice(), 'the source item stays - the copy gets another id');
    }

    public function test_a_domain_a_site_took_since_the_plan_stops_the_write(): void
    {
        $run  = $this->newRun(InstallRun::TRANSFER);
        $plan = $this->confirmed($this->plan(self::withDomains('https://www.source.example/'), true));
        $this->target->domains[] = 'source.example';

        try {
            $this->import(...$plan, run: $run);
            self::fail('a taken domain written');
        } catch (ImportFailed $e) {
            self::assertSame(['taken', 'site-module[' . self::SITE . ']/alternate-url[0]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        }
        self::assertSame([], self::domains());
    }

    /** the URLs AA_Module_Site::getIdFromUrl() matches: a site's URL or its alternate_url, http(s), www. or not */
    public function test_the_db_target_finds_a_domain_in_use_in_every_form(): void
    {
        TableDb::$tables['module'][] = ['id' => str_repeat('9', 32), 'type' => 'W', 'name' => 'Taken', 'slice_url' => 'https://www.taken.example/'];
        TableDb::$tables['object_text'][] = ['id' => 50, 'object_id' => str_repeat('8', 32), 'property' => 'alternate_url', 'priority' => 0, 'value' => 'http://alt.example'];
        $target = new \AA\Central\Package\DbImportTarget();

        self::assertSame([true, true, true, true, false], array_map($target->domainTaken(...), ['taken.example', 'WWW.taken.example', 'www.alt.example', 'alt.example', 'free.example']));
    }

    // ---------------------------------------------------------------- item ids

    public function test_item_ids_stay_on_another_aa(): void
    {
        $run = $this->transferred();

        $this->write($run, self::batch([
            self::entry(self::ITEM_A, ['headline........' => 'A']),
            self::entry(self::ITEM_B, ['headline........' => 'B links ' . self::ITEM_A, 'relation.......1' => [self::ITEM_A, self::ITEM_C, self::FITEM]]),
        ]));

        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(['B links ' . self::ITEM_A], self::texts(self::ITEM_B, 'headline........'));
        self::assertSame([self::ITEM_A, self::ITEM_C, self::FITEM], self::texts(self::ITEM_B, 'relation.......1'));
    }

    /** the source slice and its items are in this AA: items of the package's slices get ids derived from the
     *  run - in the item rows, relations and texts of the items, and in the texts of the definitions */
    public function test_a_copy_in_the_same_aa_derives_the_ids_of_the_package_items(): void
    {
        foreach ([self::ITEM_A, self::ITEM_B, self::ITEM_C] as $i => $id) {
            TableDb::$tables['item'][] = ['id' => $id, 'short_id' => 10 + $i, 'slice_id' => self::SLICE, 'status_code' => 1];
        }
        $definitions = self::cell(self::definitions(), self::SLICE, 'view', 0, ['before' => 'Top: {item:' . self::ITEM_C . ':_#HEADLINE} {item:' . self::FITEM . ':_#HEADLINE}']);
        $run         = $this->transferred(InstallRun::LOCAL, $definitions);
        $new         = static fn(string $id): string => string2id($run . $id);

        $this->write($run, self::batch([
            self::entry(self::ITEM_A, ['headline........' => 'A']),
            self::entry(self::ITEM_B, ['headline........' => 'B links ' . strtoupper(self::ITEM_A), 'relation.......1' => [self::ITEM_A, self::ITEM_C, self::FITEM, self::OUTSIDE]]),
        ]));

        self::assertSame([$new(self::ITEM_A), $new(self::ITEM_B)], self::itemsOfTheSlice());
        self::assertSame(['B links ' . strtoupper($new(self::ITEM_A))], self::texts($new(self::ITEM_B), 'headline........'));
        self::assertSame([$new(self::ITEM_A), $new(self::ITEM_C), self::FITEM, self::OUTSIDE], self::texts($new(self::ITEM_B), 'relation.......1'), 'C: of the slice, in a later batch; the foreign item and no item: as they are');
        $view = array_values(array_filter(TableDb::rows('view'), static fn(array $r): bool => $r['slice_id'] === self::id(2)))[0];
        self::assertSame('Top: {item:' . $new(self::ITEM_C) . ':_#HEADLINE} {item:' . self::FITEM . ':_#HEADLINE}', $view['before']);
        self::assertCount(1 + 3 + 2, TableDb::rows('item'), 'the foreign item and the source items stay beside the copies');
    }

    /** another run wrote the same id between the checks and the INSERT: the run tells so, not "DB" */
    public function test_an_item_id_taken_during_the_batch_is_its_own_error(): void
    {
        $run = $this->transferred();
        TableDb::$fail = static function (string $sql): bool {
            if (str_starts_with($sql, 'INSERT INTO `item`') && !array_filter(TableDb::$tables['item'] ?? [], static fn(array $r): bool => $r['id'] === self::ITEM_A)) {
                TableDb::$tables['item'][] = ['id' => self::ITEM_A, 'short_id' => 90, 'slice_id' => self::FS, 'status_code' => 1];
            }
            return false;                   // the statement runs - into the PRIMARY key
        };

        $e = $this->refused($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'A'])]));

        self::assertSame([WriteError::ITEM_ID_EXISTS, 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_A . ']'], [$e->reason, $e->path]);
    }

    // ---------------------------------------------------------------- readers

    /** DbImportTarget asks TableDb: the SQL it cannot parse (joins, UNION, the folding of the users'
     *  logins) is answered from its tables here; the rest it reads itself */
    private function dbTarget(): void
    {
        $this->db = new TableDbTarget($this->target);
        unset(TableDb::$tables['constant'], TableDb::$tables['constant_slice']);     // the plan's fake target knows no group
        class_exists(AA_Perm::class);
        \AA::$perm  = new AA_Perm(['sql', 'Reader']);           // ImportFixture puts its own back
        $readers     = static fn(): array => array_column(array_filter(TableDb::rows('slice'), static fn(array $r): bool => ($r['type'] ?? '') === ItemLint::READER_SLICE), 'id');
        $items       = static fn(): array => array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => in_array($r['slice_id'], $readers(), true)), 'id');
        TableDb::$answer = static function (string $sql) use ($items): ?array {
            if (str_contains($sql, 'FROM `users`')) {
                preg_match("/\\b(uid|mail)\\b.*= '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m);          // the folded column = the folded login
                return array_values(array_filter(TableDb::rows('users'), static fn(array $u): bool => ItemLint::loginKey((string) $u[$m[1]]) === stripslashes($m[2])));
            }
            if (str_contains($sql, 'INNER JOIN `slice`')) {
                preg_match("/content.field_id = '([^']+)'/", $sql, $m);
                $perms = array_column(TableDb::rows('perms'), 'userid');
                $rows  = array_filter(TableDb::rows('content'), static fn(array $r): bool => ($r['field_id'] === $m[1]) && in_array($r['item_id'], $items(), true));
                return array_values(array_map(static fn(array $r): array => ['login' => $r['text'], 'perm' => in_array($r['item_id'], $perms, true) ? $r['item_id'] : null], $rows));
            }
            return self::idsInUse($sql);
        };
    }

    /** the readers of this AA are asked in its DB, on the handle of the batch's transaction: of earlier
     *  batches (W), holding AA permissions (E), the users' logins in any case (E), rights kept for an id (E) */
    public function test_a_reader_slice_batch_asks_the_db_on_the_transaction_handle(): void
    {
        $this->dbTarget();
        TableDb::$tables['slice'][0]['type'] = ItemLint::READER_SLICE;         // the foreign slice: its item a reader holding rights
        TableDb::$tables['perms'][]          = ['object_type' => 'slice', 'objectid' => self::FS, 'userid' => self::FITEM, 'perm' => '2'];
        TableDb::$tables['users'][]          = ['id' => 7, 'type' => 'User', 'uid' => 'Editor', 'mail' => 'boss@target.example'];
        $run = $this->transferred(definitions: self::definitions(true));
        $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'jana', 'password........' => '$2y$10$hash1'])]));
        TableDb::$handles = [];

        $taken = $this->write($run, self::batch([self::entry(self::ITEM_B, ['headline........' => 'JANA'])], self::ITEM_A));

        self::assertSame(1, $taken['written']);
        self::assertContains('W reader-taken slice-module[' . self::SLICE . ']/item[' . self::ITEM_B . ']/headline........[0] JANA', self::codes($taken['findings']));
        self::assertSame(['reader-taken' => 1], $taken['warnings']);
        self::assertCount(1, array_unique(TableDb::$handles), 'one DB handle');

        $at = static fn(string $id): string => 'slice-module[' . self::SLICE . "]/item[$id]";
        foreach ([
            ['Foreign item', self::ITEM_C, ['READER_PERMS', $at(self::ITEM_C) . '/headline........[0]']],
            ['editor ', self::ITEM_C, ['READER_ADMIN', $at(self::ITEM_C) . '/headline........[0]']],
            ['BOSS@target.example', self::ITEM_C, ['READER_ADMIN', $at(self::ITEM_C) . '/headline........[0]']],
            ['nova', self::FITEM, ['ITEM_ID_IN_USE', $at(self::FITEM)]],
        ] as [$login, $id, $error]) {
            $e = $this->refused($run, self::batch([self::entry($id, ['headline........' => $login])], self::ITEM_B));
            self::assertSame($error, [Importer::errorCode($e->reason), $e->path], $login);
        }
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
    }

    /** restrictPermsTo() leaves the readers only - no answer is no "free" */
    public function test_the_admin_check_needs_a_system_of_users(): void
    {
        $this->expectException(LogicException::class);
        (new AA_Perm(['Reader']))->isAdminUsernameFree('editor');
    }

    public function test_a_reader_login_of_an_admin_stops_the_batch(): void
    {
        $this->target->admins = ['editor'];
        $run                  = $this->transferred(definitions: self::definitions(true));

        $e = $this->refused($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'jana']), self::entry(self::ITEM_B, ['headline........' => 'editor'])]));

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_B . ']/headline........[0]';
        self::assertSame([ItemLint::READER_ADMIN, $at], [$e->reason, $e->path]);
        self::assertSame([], self::itemsOfTheSlice(), 'the admin keeps the login');
    }

    /** the batch has no slice row - the target's slice says whether the headlines are logins */
    public function test_reader_checks_follow_the_type_of_the_target_slice(): void
    {
        $this->target->admins = ['editor'];
        $run                  = $this->transferred();

        $result = $this->write($run, self::batch([self::entry(self::ITEM_A, ['headline........' => 'editor', 'password........' => 'x'])]));

        self::assertSame([1, []], [$result['written'], self::codes($result['findings'])], 'a headline of an article is no login');
    }
}
