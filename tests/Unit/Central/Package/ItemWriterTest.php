<?php

declare(strict_types=1);

/**
 * ItemWriter: two items (a relation in and out of the package, a password hash, a number, a translation)
 * written into TableDb - new ids, the rows AA_Field_Writer::contentRow() builds, references rewritten,
 * the hash byte for byte, nothing of storeItem(); a failure at any item statement rolls everything back.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\Package;
use AA\Central\Package\WriteError;
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
    /** an item the package does not carry */
    private const OUTSIDE = '0123456789abcdef0123456789abcdef';
    /** a bcrypt hash - with the id of item B in it: a rewrite would change it */
    private const HASH = '$2y$10$' . self::ITEM_B . 'Zq9./abcdefghijklmn';

    /** @var array<string, list<array<string, mixed>>> the target's rows before the import */
    private array $before;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $this->before = self::foreign();
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

    private static function withItems(?Package $package = null): Package
    {
        $package = self::rows($package ?? self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Related', 'input_insert_func' => 'ids:', 'text_stored' => '1'],
            ['id' => 'password.......1', 'type' => 'password', 'name' => 'Password', 'input_insert_func' => 'pwd:', 'text_stored' => '1'],
            ['id' => 'number.........1', 'type' => 'number', 'name' => 'Count', 'text_stored' => null],
            ['id' => 'alerts1....AbC12', 'type' => 'alerts', 'name' => 'How often'],       // of the package's collection AbC12
            ['id' => 'alerts2....AbC12', 'type' => 'alerts', 'name' => 'Filters'],
            ['id' => 'alerts2....Zz999', 'type' => 'alerts', 'name' => 'Filters of a collection not in the package'],
        ]);
        $columns = ['status_code.....' => '1', 'post_date.......' => '1690000000', 'publish_date....' => '1700000000', 'expiry_date.....' => '2145913200',
            'highlight.......' => '0', 'last_edit.......' => '1700000001', 'flags...........' => '', 'moved2active....' => '1700000000'];
        return self::items($package, [
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
        ]);
    }

    /** our rows of a table: not the target's */
    private static function ours(string $table, string $id_column): array
    {
        return array_values(array_filter(TableDb::rows($table), static fn(array $r): bool => in_array($r[$id_column], [self::id(8), self::id(9)], true)));
    }

    // ---------------------------------------------------------------- the rows

    public function test_items_are_written_with_new_ids_and_their_content_as_stored(): void
    {
        $report = $this->importer()->write($this->plan(self::withItems()));
        [$s, $a, $b] = [self::id(2), self::id(8), self::id(9)];

        $item = ['short_id' => null, 'slice_id' => $s, 'status_code' => 1, 'post_date' => 1690000000, 'publish_date' => 1700000000, 'expiry_date' => 2145913200,
            'highlight' => 0, 'posted_by' => 'importer-uid', 'edited_by' => null, 'last_edit' => 1700000001, 'display_count' => 0, 'flags' => '', 'disc_count' => 0,
            'disc_app' => 0, 'externally_fed' => '', 'moved2active' => 1700000000];
        $sorted = static function (array $row): array {
            ksort($row);
            return $row;
        };
        self::assertSame(array_map($sorted, [
            ['id' => $a, 'short_id' => 100] + $item,
            ['id' => $b, 'short_id' => 101, 'status_code' => 2] + $item,
        ]), array_map($sorted, self::ours('item', 'id')), 'every column');

        self::assertSame([
            ['item_id' => $a, 'field_id' => 'headline........', 'number' => 0, 'text' => 'Článek {view:1000}', 'flag' => 64],
            ['item_id' => $a, 'field_id' => 'headline........', 'number' => 1000000, 'text' => 'Article (en)', 'flag' => 64],  // a translation
            ['item_id' => $a, 'field_id' => 'category.......1', 'number' => 0, 'text' => 'zpravy', 'flag' => 64],
            ['item_id' => $a, 'field_id' => 'password.......1', 'number' => 0, 'text' => self::HASH, 'flag' => 64],  // the hash as it is
            ['item_id' => $a, 'field_id' => 'number.........1', 'number' => 42, 'flag' => 1, 'text' => null],     // no FLAG_TEXT_STORED, `text` NULL (the DB's)
            ['item_id' => $b, 'field_id' => 'headline........', 'number' => 0, 'text' => "B links $a", 'flag' => 64],
            ['item_id' => $b, 'field_id' => 'relation.......1', 'number' => 0, 'text' => $a, 'flag' => 64],          // a package item: its new id
            ['item_id' => $b, 'field_id' => 'relation.......1', 'number' => 1, 'text' => self::OUTSIDE, 'flag' => 64], // outside: as it is
            ['item_id' => $b, 'field_id' => 'alerts1....K0001', 'number' => 0, 'text' => 'daily', 'flag' => 64],       // the new collection id
            ['item_id' => $b, 'field_id' => 'alerts2....K0001', 'number' => 0, 'text' => 'f3000', 'flag' => 64],       // the new filter number
            ['item_id' => $b, 'field_id' => 'alerts2....K0001', 'number' => 1, 'text' => 'f99', 'flag' => 64],         // outside: as it is (plan: I)
            ['item_id' => $b, 'field_id' => 'alerts2....Zz999', 'number' => 0, 'text' => 'f3', 'flag' => 64],          // field and value as they are
        ], self::ours('content', 'item_id'));

        self::assertSame([$s => 2], $report->maps['items']);
        self::assertSame([2, 12], [$report->rows['item'], $report->rows['content']], 'the report counts what was written');
        self::assertSame(array_values($this->before['item']), array_values(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['id'] === self::FITEM)));
        self::assertCount(1, array_unique(TableDb::$handles), 'every statement on the transaction handle');
    }

    public function test_the_item_row_goes_before_its_content(): void
    {
        $this->importer()->write($this->plan(self::withItems()));

        $order = [];
        foreach (TableDb::$log as $q) {
            if (preg_match('/^INSERT INTO `(item|content)` \(`(?:id|item_id)`.*?VALUES \(0x([0-9a-f]{32})/', $q, $m)) {
                $order[] = "$m[1] $m[2]";
            }
        }
        [$a, $b] = [self::id(8), self::id(9)];
        self::assertSame(["item $a", ...array_fill(0, 5, "content $a"), "item $b", ...array_fill(0, 7, "content $b")], $order);
    }

    /** storeItem() is not run: no log, history, feeding, hit counters, reverse relations, tag items - only the import's tables */
    public function test_only_the_tables_of_the_import_are_written(): void
    {
        $this->importer()->write($this->plan(self::withItems()));

        $tables = array_unique(array_map(static fn(string $q): string => preg_replace('/^(?:INSERT INTO|UPDATE) `(\w+)`.*/s', '$1', $q), TableDb::writes()));
        sort($tables);
        self::assertSame(['alerts_collection', 'alerts_collection_filter', 'alerts_collection_howoften', 'alerts_filter', 'constant', 'constant_slice', 'content', 'email',
            'field', 'item', 'module', 'object_text', 'profile', 'relation', 'site', 'site_spot', 'slice', 'view'], $tables);
        self::assertSame(2, count(self::ours('item', 'id')));
        self::assertSame([], array_filter(TableDb::rows('content'), static fn(array $r): bool => ($r['item_id'] === self::id(8)) && ($r['field_id'] === 'relation.......1')), 'no reverse relation on item A');
    }

    public function test_a_module_of_another_charset_gets_its_item_values_converted_but_the_hash(): void
    {
        $plan = $this->plan(self::withItems(self::lang(self::example(), self::SLICE, 'cz_news_lang.php')));

        $this->importer()->write($plan);

        $text = array_column(array_filter(self::ours('content', 'item_id'), static fn(array $r): bool => ($r['item_id'] === self::id(8)) && ($r['number'] === 0)), 'text', 'field_id');
        self::assertSame(iconv('UTF-8', 'windows-1250', 'Článek {view:1000}'), $text['headline........']);
        self::assertSame(self::HASH, $text['password.......1']);
    }

    public function test_a_package_without_items_writes_no_item(): void
    {
        $report = $this->importer()->write($this->plan());

        self::assertSame([], $report->maps['items']);
        self::assertSame($this->before['item'], TableDb::rows('item'));
        self::assertSame($this->before['content'], TableDb::rows('content'));
    }

    /** the plan's E (ItemLintTest); a plan forced past it: the writer refuses to cut item.flags (char(30)) */
    public function test_an_item_column_too_long_throws_before_the_commit(): void
    {
        $plan   = $this->plan(self::withItems());
        $long   = self::items($plan->package, array_map(static function (array $item): array {
            $item['content']['flags...........'] = [0 => ['value' => str_repeat('x', 31), 'flag' => 1]];
            return $item;
        }, array_values(array_filter($plan->package->modules, static fn($m): bool => $m->id === self::SLICE))[0]->items));
        $forced = new ImportPlan(...array_merge(get_object_vars($plan), ['package' => $long]));

        $e = $this->failure($forced);

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame([WriteError::TOO_LONG, 'slice-module[' . self::SLICE . ']/item[' . self::ITEM_A . ']/flags'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertSame($this->before, array_filter(TableDb::$tables));
    }

    // ---------------------------------------------------------------- failures

    /** @return iterable<string, array{string, string}> */
    public static function failurePoints(): iterable
    {
        $points = [
            'first item'    => '/^INSERT INTO `item`/',
            'first content' => '/^INSERT INTO `content`/',
            'second item'   => '/^INSERT INTO `item` .*0x0{28}a009/',
            'last content'  => "/^INSERT INTO `content` .*'" . self::OUTSIDE . "'/",
        ];
        foreach ($points as $name => $statement) {
            foreach (['false', 'throw'] as $mode) {
                yield "$name, $mode" => [$statement, $mode];
            }
        }
    }

    #[DataProvider('failurePoints')]
    public function test_after_a_failure_at_an_item_nothing_of_the_import_remains(string $statement, string $mode): void
    {
        $plan = $this->plan(self::withItems());
        self::failAt($statement, $mode);

        $this->failure($plan);

        self::assertSame('ROLLBACK', end(TableDb::$log));
        self::assertSame($this->before, array_filter(TableDb::$tables), 'the rows of the target - its item and content too');
    }
}
