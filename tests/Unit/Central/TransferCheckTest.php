<?php

declare(strict_types=1);

/**
 * TransferCheck: what the end of a transfer reports - items on the source and on the target per slice,
 * items the source changed after the run started and relations of the new items that point nowhere.
 */

namespace Tests\Unit\Central;

use AA\Central\InstallRun;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\Model\Module;
use AA\Central\TransferCheck;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/Package/fixtures/ImportFixture.php';
require_once __DIR__ . '/Package/fixtures/FakeSource.php';

final class TransferCheckTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    private FakeSource $source;
    private string $run;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
        $this->source = new FakeSource([], [self::SLICE => [self::entry('a', 1, self::NOW - 10), self::entry('b', 1, self::NOW + 10), self::entry('c', 3, self::NOW + 20)]]);
        $this->run    = $this->newRun(InstallRun::TRANSFER);
        $confirmation = new ImportConfirmation('preview-session');
        $plan         = $this->plan(self::example(), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $this->run);
    }

    /** a source item of the bin $status, last edited at $edited */
    private static function entry(string $n, int $status, int $edited): array
    {
        return ['id' => str_repeat($n, 32), 'last_edit' => $edited, 'content' => ['status_code.....' => [['value' => (string) $status, 'flag' => 0]]]];
    }

    /** $n items in the new slice of the target */
    private static function targetItems(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            TableDb::$tables['item'][] = ['id' => sprintf('%032x', 0xc000 + $i), 'slice_id' => self::id(2), 'status_code' => 1];
        }
    }

    /** "<level> <code> <path> <ref>" @return list<string> */
    private function findings(): array
    {
        return array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", (new TransferCheck($this->source))->check(InstallRun::get($this->run))['findings']);
    }

    public function test_equal_counts_and_no_edits_are_no_finding(): void
    {
        self::targetItems(2);
        $this->source->items[self::SLICE][1]['last_edit'] = self::NOW - 5;     // the active bin: a and b

        $check = (new TransferCheck($this->source))->check(InstallRun::get($this->run));

        self::assertSame([['source' => self::SLICE, 'target' => self::id(2), 'written' => 0, 'sourceCount' => 2, 'targetCount' => 2]], $check['slices']);
        self::assertSame([], $check['findings']);
    }

    public function test_another_count_on_the_target_is_a_warning(): void
    {
        self::targetItems(1);
        $this->source->items[self::SLICE][1]['last_edit'] = self::NOW - 5;

        self::assertSame(['W ' . TransferCheck::COUNT_DIFFERS . ' ' . Module::SLICE . '[' . self::SLICE . '] 2 => 1'], $this->findings());
    }

    public function test_items_edited_on_the_source_after_the_start_are_counted_in_the_bins_of_the_run(): void
    {
        self::targetItems(2);

        self::assertSame(['W ' . TransferCheck::EDITED_DURING . ' source 1'], $this->findings(), 'b only - c is in another bin');
    }

    public function test_dangling_relations_are_values_of_relation_fields_of_the_new_items_naming_no_item(): void
    {
        self::targetItems(2);
        $this->source->items[self::SLICE][1]['last_edit'] = self::NOW - 5;
        [$one, $two] = [sprintf('%032x', 0xc001), sprintf('%032x', 0xc002)];
        $missing     = str_repeat('9', 32);
        array_push(
            TableDb::$tables['field'],
            ['id' => 'relation.......1', 'slice_id' => self::id(2), 'input_show_func' => 'mse:#sLiCe-' . self::FS . ':'],
            ['id' => 'text...........1', 'slice_id' => self::id(2), 'input_show_func' => 'txt:'],
            ['id' => 'relation.......1', 'slice_id' => self::FS, 'input_show_func' => 'mse:#sLiCe-' . self::FS . ':'],
        );
        array_push(
            TableDb::$tables['content'],
            ['item_id' => $one, 'field_id' => 'relation.......1', 'number' => 0, 'text' => self::FITEM, 'flag' => 64],      // an item of this AA
            ['item_id' => $one, 'field_id' => 'relation.......1', 'number' => 1, 'text' => $missing, 'flag' => 64],         // dangling
            ['item_id' => $two, 'field_id' => 'relation.......1', 'number' => 0, 'text' => 'no id', 'flag' => 64],          // dangling
            ['item_id' => $two, 'field_id' => 'relation.......1', 'number' => 1, 'text' => '', 'flag' => 64],               // empty
            ['item_id' => $two, 'field_id' => 'text...........1', 'number' => 0, 'text' => $missing, 'flag' => 64],         // no relation field
            ['item_id' => self::FITEM, 'field_id' => 'relation.......1', 'number' => 0, 'text' => $missing, 'flag' => 64],  // another slice
        );

        self::assertSame(['W ' . TransferCheck::DANGLING . ' target 2'], $this->findings());
        self::assertSame(0, TransferCheck::dangling([]), 'no slice, no query');
    }
}
