<?php

declare(strict_types=1);

/**
 * ItemWriter: a batch of two items (a relation in and out of the transfer, a password hash, a number, a
 * translation, alerts fields) written into TableDb by Importer::writeBatch() - the field types of the
 * target slice, the rows AA_Field_Writer::contentRow() builds, references rewritten, the hash byte for
 * byte, nothing of storeItem(); a failure at any item statement rolls the batch back.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\InstallRun;
use AA\Central\Package\BatchResult;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class ItemWriterTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    private const ITEM_A  = '1234567890abcdef1234567890abcdef';
    private const ITEM_B  = 'fedcba0987654321fedcba0987654321';
    /** an item the transfer does not carry */
    private const OUTSIDE = '0123456789abcdef0123456789abcdef';
    /** a bcrypt hash - with the id of item B in it: a rewrite would change it */
    private const HASH = '$2y$10$' . self::ITEM_B . 'Zq9./abcdefghijklmn';

    /** @var array<string, list<array<string, mixed>>> the target's rows before the batch */
    private array $before;
    private string $run;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
    }

    /** @param array<string, mixed> $values field => value or [key => value] @return array<string, array<int, array{value: string, flag: int}>> */
    private static function content(array $values, int $flag = 0): array
    {
        $content = [];
        foreach ($values as $field => $list) {
            foreach (is_array($list) ? $list : [$list] as $key => $value) {
                $content[$field][$key] = ['value' => $value, 'flag' => $flag];
            }
        }
        return $content;
    }

    /** the example's slice with the fields of the batch */
    private static function definitions(?Package $package = null): Package
    {
        return self::rows($package ?? self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Related', 'input_insert_func' => 'ids:', 'text_stored' => '1'],
            ['id' => 'password.......1', 'type' => 'password', 'name' => 'Password', 'input_insert_func' => 'pwd:', 'text_stored' => '1'],
            ['id' => 'number.........1', 'type' => 'number', 'name' => 'Count', 'text_stored' => null],
            ['id' => 'alerts1....AbC12', 'type' => 'alerts', 'name' => 'How often'],       // of the package's collection AbC12
            ['id' => 'alerts2....AbC12', 'type' => 'alerts', 'name' => 'Filters'],
            ['id' => 'alerts2....Zz999', 'type' => 'alerts', 'name' => 'Filters of a collection not in the package'],
        ]);
    }

    /** @param list<array<string, mixed>>|null $items */
    private static function batch(?array $items = null): Package
    {
        $columns = ['status_code.....' => '1', 'post_date.......' => '1690000000', 'publish_date....' => '1700000000', 'expiry_date.....' => '2145913200',
            'highlight.......' => '0', 'last_edit.......' => '1700000001', 'flags...........' => '', 'moved2active....' => '1700000000'];
        $items ??= [
            ['id' => self::ITEM_A, 'content' => self::content($columns, 1) + self::content([
                'headline........' => [0 => 'Článek {view:12}', 1000000 => 'Article (en)'],
                'category.......1' => 'zpravy',
                'password.......1' => self::HASH,
            ]) + self::content(['number.........1' => '42'], 1)],
            ['id' => self::ITEM_B, 'content' => self::content(['status_code.....' => '2'] + $columns, 1) + self::content([
                'headline........' => 'B links ' . self::ITEM_A,
                'relation.......1' => [self::ITEM_A, self::OUTSIDE],
                'alerts1....AbC12' => 'daily',
                'alerts2....AbC12' => ['f3', 'f99'],          // filter 3 is the package's, 99 is not
                'alerts2....Zz999' => 'f3',                   // not the package's collection: the target's filter 3, if any
            ])],
        ];
        return new Package('Články', self::example()->source, [new Module(Module::SLICE, self::SLICE, [], items: $items)], withItems: true, batch: new Batch('', $items ? end($items)['id'] : '', true));
    }

    /** the definitions written for a transfer run, the statement log cleared; the batch written */
    private function write(?Package $definitions = null, ?Package $batch = null): BatchResult
    {
        $this->run = $this->newRun(InstallRun::TRANSFER);
        $this->import($this->plan(self::definitions($definitions), true), run: $this->run);
        [TableDb::$log, TableDb::$handles, $this->before] = [[], [], self::tables()];
        return $this->importer()->writeBatch($this->run, $batch ?? self::batch(), [self::SLICE, '']);
    }

    /** our rows of a table: not the target's */
    private static function ours(string $table, string $id_column): array
    {
        return array_values(array_filter(TableDb::rows($table), static fn(array $r): bool => in_array($r[$id_column], [self::ITEM_A, self::ITEM_B], true)));
    }

    // ---------------------------------------------------------------- the rows

    public function test_items_are_written_with_their_ids_and_their_content_as_stored(): void
    {
        $result = $this->write();
        [$s, $a, $b] = [self::id(2), self::ITEM_A, self::ITEM_B];

        self::assertSame(BatchResult::WRITTEN, $result->status);
        $item = ['short_id' => null, 'slice_id' => $s, 'status_code' => 1, 'post_date' => 1690000000, 'publish_date' => 1700000000, 'expiry_date' => 2145913200,
            'highlight' => 0, 'posted_by' => null, 'edited_by' => null, 'last_edit' => 1700000001, 'display_count' => 0, 'flags' => '', 'disc_count' => 0,
            'disc_app' => 0, 'externally_fed' => '', 'moved2active' => 1700000000];
        $sorted = static function (array $row): array {
            ksort($row);
            return $row;
        };
        self::assertSame(array_map($sorted, [
            ['id' => $a, 'short_id' => 100] + $item,
            ['id' => $b, 'short_id' => 101, 'status_code' => 2] + $item,
        ]), array_map($sorted, self::ours('item', 'id')), 'every column; no author the batch does not carry');

        self::assertSame([
            ['item_id' => $a, 'field_id' => 'headline........', 'number' => 0, 'text' => 'Článek {view:1000}', 'flag' => 64],
            ['item_id' => $a, 'field_id' => 'headline........', 'number' => 1000000, 'text' => 'Article (en)', 'flag' => 64],  // a translation
            ['item_id' => $a, 'field_id' => 'category.......1', 'number' => 0, 'text' => 'zpravy', 'flag' => 64],
            ['item_id' => $a, 'field_id' => 'password.......1', 'number' => 0, 'text' => self::HASH, 'flag' => 64],  // the hash as it is
            ['item_id' => $a, 'field_id' => 'number.........1', 'number' => 42, 'flag' => 1, 'text' => null],     // no FLAG_TEXT_STORED, `text` NULL (the DB's)
            ['item_id' => $b, 'field_id' => 'headline........', 'number' => 0, 'text' => "B links $a", 'flag' => 64],
            ['item_id' => $b, 'field_id' => 'relation.......1', 'number' => 0, 'text' => $a, 'flag' => 64],
            ['item_id' => $b, 'field_id' => 'relation.......1', 'number' => 1, 'text' => self::OUTSIDE, 'flag' => 64],
            ['item_id' => $b, 'field_id' => 'alerts1....K0001', 'number' => 0, 'text' => 'daily', 'flag' => 64],       // the new collection id
            ['item_id' => $b, 'field_id' => 'alerts2....K0001', 'number' => 0, 'text' => 'f3000', 'flag' => 64],       // the new filter number
            ['item_id' => $b, 'field_id' => 'alerts2....K0001', 'number' => 1, 'text' => 'f99', 'flag' => 64],         // outside: as it is (I)
            ['item_id' => $b, 'field_id' => 'alerts2....Zz999', 'number' => 0, 'text' => 'f3', 'flag' => 64],          // field and value as they are
        ], self::ours('content', 'item_id'));

        self::assertSame(array_values($this->before['item']), array_values(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['id'] === self::FITEM)));
        self::assertCount(1, array_unique(TableDb::$handles), 'every statement on the transaction handle');
    }

    /** the target's field rows decide how a value is stored - not the source's */
    public function test_the_field_types_are_the_target_slice_s(): void
    {
        $this->write(batch: self::batch([]));             // the definitions only
        foreach (TableDb::$tables['field'] as $k => $row) {
            if ($row['id'] === 'number.........1') {
                TableDb::$tables['field'][$k]['text_stored'] = 1;          // an admin changed the field since
            }
        }

        $this->importer()->writeBatch($this->run, self::batch([['id' => self::ITEM_A, 'content' => self::content(['status_code.....' => '1', 'number.........1' => '42'])]]), [self::SLICE, '']);

        self::assertSame([['item_id' => self::ITEM_A, 'field_id' => 'number.........1', 'number' => 0, 'text' => '42', 'flag' => 64]], self::ours('content', 'item_id'));
    }

    public function test_the_item_row_goes_before_its_content(): void
    {
        $this->write();

        $order = [];
        foreach (TableDb::$log as $q) {
            if (preg_match('/^INSERT INTO `(item|content)` \(`(?:id|item_id)`.*?VALUES \(0x([0-9a-f]{32})/', $q, $m)) {
                $order[] = "$m[1] $m[2]";
            }
        }
        [$a, $b] = [self::ITEM_A, self::ITEM_B];
        self::assertSame(["item $a", ...array_fill(0, 5, "content $a"), "item $b", ...array_fill(0, 7, "content $b")], $order);
    }

    /** storeItem() is not run: no log, history, feeding, hit counters, reverse relations, tag items - only the batch's tables */
    public function test_only_the_tables_of_the_batch_are_written(): void
    {
        $this->write();

        $tables = array_unique(array_map(static fn(string $q): string => preg_replace('/^(?:INSERT INTO|UPDATE|DELETE (?:LOW_PRIORITY )?FROM) `(\w+)`.*/s', '$1', $q), TableDb::writes()));
        sort($tables);
        self::assertSame(['content', 'item', 'object_float', 'object_integer', 'object_text'], $tables, 'object_*: the run');
        self::assertSame([], array_filter(TableDb::rows('content'), static fn(array $r): bool => ($r['item_id'] === self::ITEM_A) && ($r['field_id'] === 'relation.......1')), 'no reverse relation on item A');
    }

    public function test_a_slice_of_another_charset_gets_its_item_values_converted_but_the_hash(): void
    {
        $this->write(self::lang(self::example(), self::SLICE, 'cz_news_lang.php'));

        $text = array_column(array_filter(self::ours('content', 'item_id'), static fn(array $r): bool => ($r['item_id'] === self::ITEM_A) && ($r['number'] === 0)), 'text', 'field_id');
        self::assertSame(iconv('UTF-8', 'windows-1250', 'Článek {view:1000}'), $text['headline........']);
        self::assertSame(self::HASH, $text['password.......1']);
    }

    public function test_the_definitions_never_carry_items(): void
    {
        $plan   = $this->plan();
        $forced = new ImportPlan(...array_merge(get_object_vars($plan), ['package' => self::items($plan->package, [self::item(self::ITEM_A)])]));

        $this->expectException(\InvalidArgumentException::class);
        $this->import($forced);
    }

    /** item.flags is char(30) - never cut; content.text has no limit */
    public function test_an_item_column_too_long_stops_the_batch(): void
    {
        $result = $this->write(batch: self::batch([['id' => self::ITEM_A, 'content' => self::content(['status_code.....' => '1', 'flags...........' => str_repeat('x', 31)])]]));

        self::assertSame([BatchResult::FAILED, ['TOO_LONG', 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_A . ']/flags...........[0]']], [$result->status, $result->error]);
        self::assertSame($this->before, self::tables());
    }

    // ---------------------------------------------------------------- failures

    /** @return iterable<string, array{string, string}> */
    public static function failurePoints(): iterable
    {
        $points = [
            'first item'    => '/^INSERT INTO `item`/',
            'first content' => '/^INSERT INTO `content`/',
            'second item'   => '/^INSERT INTO `item` .*0x' . self::ITEM_B . '/',
            'last content'  => "/^INSERT INTO `content` .*'" . self::OUTSIDE . "'/",
        ];
        foreach ($points as $name => $statement) {
            foreach (['false', 'throw'] as $mode) {
                yield "$name, $mode" => [$statement, $mode];
            }
        }
    }

    #[DataProvider('failurePoints')]
    public function test_after_a_failure_at_an_item_nothing_of_the_batch_remains(string $statement, string $mode): void
    {
        $log = ini_set('error_log', '/dev/null');
        self::failAt($statement, $mode);

        try {
            $result = $this->write();
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(BatchResult::FAILED, $result->status);
        self::assertSame([], self::ours('item', 'id'));
        self::assertSame([], self::ours('content', 'item_id'));
        self::assertSame(array_values(array_diff_key($this->before, ['object_text' => 1])), array_values(array_diff_key(self::tables(), ['object_text' => 1])), 'the rows of the target - its item and content too');
    }
}
