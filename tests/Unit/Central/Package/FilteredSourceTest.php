<?php

declare(strict_types=1);

/**
 * FilteredSource over FakeSource: the items of a filtered slice are pages of the inner ids narrowed by
 * matching() - a page without a match is passed over, a short page is the last, the limit stops within a
 * page; counts, logins and the links left out cover the selected items only; a slice without a filter is
 * the inner source's; a filter naming a field the slices lack, or a value the slice's charset cannot hold,
 * is refused with its slice.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\AppSource;
use AA\Central\Package\CollectOptions;
use AA\Central\Package\Collector;
use AA\Central\Package\FilteredSource;
use AA\Central\Package\FilterInvalid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;

require_once __DIR__ . '/fixtures/FakeSource.php';

final class FilteredSourceTest extends TestCase
{
    private const ARTICLES = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const LINKS    = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const READERS  = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    private const CP1250   = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';
    private const YES      = 'd-category........-=-yes';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';     // AA_View_Commands, AA_Dbcondition
    }

    /** an item id: 32 hex digits ending with the number */
    private static function id(int $n): string
    {
        return sprintf('%032x', $n);
    }

    /** @param array<string, list<string>|string> $values field => values */
    private static function item(int|string $id, array $values, string $status = '1', int $lastEdit = 0): array
    {
        $content = ['status_code.....' => [['value' => $status, 'flag' => 0]]];
        foreach ($values as $field => $list) {
            $content[$field] = array_map(static fn(string $v): array => ['value' => $v, 'flag' => 0], (array) $list);
        }
        return ['id' => is_int($id) ? self::id($id) : $id, 'content' => $content, 'last_edit' => $lastEdit];
    }

    /** @param array<string, list<array<string, mixed>>> $items slice => items, their values the content table too */
    private static function source(array $items): FakeSource
    {
        $field   = static fn(string $slice, string $id, string $widget = 'fld:'): array => ['id' => $id, 'slice_id' => $slice, 'input_show_func' => $widget];
        $module  = static fn(string $id, string $lang): array => ['id' => $id, 'type' => 'S', 'deleted' => 0, 'lang_file' => $lang];
        $content = [];
        foreach (array_merge([], ...array_values($items)) as $item) {
            foreach ($item['content'] as $fieldId => $values) {
                foreach ($values as $i => $v) {
                    $content[] = ['item_id' => $item['id'], 'field_id' => $fieldId, 'number' => $i, 'text' => $v['value'], 'flag' => 0];
                }
            }
        }
        return new FakeSource([
            'module'  => [$module(self::ARTICLES, 'cz-utf8_news_lang.php'), $module(self::LINKS, 'en-utf8_news_lang.php'), $module(self::READERS, 'en-utf8_news_lang.php'), $module(self::CP1250, 'cz_news_lang.php')],
            'field'   => [
                $field(self::ARTICLES, 'headline........'), $field(self::ARTICLES, 'category........'), $field(self::ARTICLES, 'status_code.....'),
                $field(self::ARTICLES, 'relation.......2', 'mse:#sLiCe-' . self::ARTICLES . ':'),
                $field(self::LINKS, 'headline........'), $field(self::LINKS, 'relation.......1', 'mse:#sLiCe-' . self::ARTICLES . ':'),
                $field(self::READERS, 'headline........'), $field(self::READERS, 'category........'),
                $field(self::CP1250, 'headline........'), $field(self::CP1250, 'relation.......1', 'sel:#sLiCe-' . self::ARTICLES),
            ],
            'content' => $content,
        ], $items);
    }

    /** @return list<array<mixed>> the calls of one kind */
    private static function calls(FakeSource $source, string $kind): array
    {
        return array_values(array_map(static fn(array $call): array => $call[1], array_filter($source->log, static fn(array $call): bool => $call[0] === $kind)));
    }

    public function test_a_page_without_a_match_is_passed_over(): void
    {
        $items  = array_map(static fn(int $i): array => self::item($i, ['category........' => 'no']), range(1, AppSource::ID_PAGE));
        $source = self::source([self::ARTICLES => [...$items, self::item(AppSource::ID_PAGE + 1, ['category........' => 'yes']), self::item(AppSource::ID_PAGE + 2, ['category........' => 'yes'])]]);

        $ids = FilteredSource::of($source, [self::ARTICLES => self::YES])->itemIds(self::ARTICLES, false, '', 10);

        self::assertSame([self::id(AppSource::ID_PAGE + 1), self::id(AppSource::ID_PAGE + 2)], $ids);
        self::assertSame([[self::ARTICLES, false, '', AppSource::ID_PAGE], [self::ARTICLES, false, self::id(AppSource::ID_PAGE), AppSource::ID_PAGE]], self::calls($source, 'itemIds'), 'the inner cursor is the last candidate');
        self::assertCount(2, self::calls($source, 'matching'));
    }

    public function test_a_short_page_is_the_last_and_the_order_is_the_inner_one(): void
    {
        $source = self::source([self::ARTICLES => [self::item(3, ['category........' => 'yes']), self::item(1, ['category........' => 'yes']), self::item(2, ['category........' => 'no'])]]);

        self::assertSame([self::id(1), self::id(3)], FilteredSource::of($source, [self::ARTICLES => self::YES])->itemIds(self::ARTICLES, false, '', 10));
        self::assertCount(1, self::calls($source, 'itemIds'));
    }

    public function test_the_limit_stops_within_a_page_and_the_cursor_goes_on(): void
    {
        $source   = self::source([self::ARTICLES => array_map(static fn(int $i): array => self::item($i, ['category........' => ($i % 2) ? 'yes' : 'no']), range(1, 20))]);
        $filtered = FilteredSource::of($source, [self::ARTICLES => self::YES]);

        self::assertSame([self::id(1), self::id(3), self::id(5)], $filtered->itemIds(self::ARTICLES, false, '', 3));
        self::assertSame([self::id(7), self::id(9), self::id(11)], $filtered->itemIds(self::ARTICLES, false, self::id(5), 3));
        self::assertSame([[self::ARTICLES, false, '', AppSource::ID_PAGE], [self::ARTICLES, false, self::id(5), AppSource::ID_PAGE]], self::calls($source, 'itemIds'));
        self::assertSame([], $filtered->itemIds(self::ARTICLES, false, '', 0));
    }

    public function test_the_bins_are_the_inner_ones(): void
    {
        $source   = self::source([self::ARTICLES => [self::item(1, ['category........' => 'yes']), self::item(2, ['category........' => 'yes'], '3')]]);
        $filtered = FilteredSource::of($source, [self::ARTICLES => self::YES]);

        self::assertSame([self::id(1)], $filtered->itemIds(self::ARTICLES, false, '', 10));
        self::assertSame([self::id(1), self::id(2)], $filtered->itemIds(self::ARTICLES, true, '', 10));
    }

    public function test_the_count_is_of_the_selected_items(): void
    {
        $source   = self::source([self::ARTICLES => [
            self::item(1, ['category........' => 'yes'], '1', 100), self::item(2, ['category........' => 'yes'], '1', 300),
            self::item(3, ['category........' => 'no'], '1', 300), self::item(4, ['category........' => 'yes'], '3', 300),
        ]]);
        $filtered = FilteredSource::of($source, [self::ARTICLES => self::YES]);

        self::assertSame(2, $filtered->itemCount(self::ARTICLES, false));
        self::assertSame(3, $filtered->itemCount(self::ARTICLES, true));
        self::assertSame(1, $filtered->itemCount(self::ARTICLES, false, 200), 'edited after 200');
        self::assertSame([], self::calls($source, 'itemCount'));
    }

    public function test_a_slice_without_a_filter_is_the_inner_sources(): void
    {
        $source   = self::source([self::LINKS => [self::item(1, ['headline........' => 'a'], '1', 10)], self::ARTICLES => []]);
        $filtered = FilteredSource::of($source, [self::ARTICLES => self::YES]);

        self::assertSame([self::id(1)], $filtered->itemIds(self::LINKS, false, '', 7));
        self::assertSame(1, $filtered->itemCount(self::LINKS, false, 5));
        self::assertSame([[self::LINKS, false, '', 7]], self::calls($source, 'itemIds'));
        self::assertSame([[self::LINKS, false, 5]], self::calls($source, 'itemCount'));
        self::assertSame([], self::calls($source, 'matching'));
    }

    public function test_the_logins_are_of_the_selected_readers(): void
    {
        $source = self::source([self::READERS => [
            self::item(1, ['headline........' => 'alice', 'category........' => 'staff']),
            self::item(2, ['headline........' => 'bob', 'category........' => 'guest']),
            self::item(3, ['headline........' => 'carol', 'category........' => 'staff'], '2'),
        ]]);
        $filtered = FilteredSource::of($source, [self::READERS => 'd-category........-=-staff']);

        self::assertSame(['alice'], $filtered->logins(self::READERS, false));
        self::assertSame(['alice', 'carol'], $filtered->logins(self::READERS, true));
        self::assertSame([], self::calls($source, 'logins'));
    }

    public function test_the_links_left_out_are_relation_values_naming_items_not_transferred(): void
    {
        $articles = [self::item(1, ['category........' => 'yes']), self::item(2, ['category........' => 'no']), self::item(3, ['category........' => 'yes'], '3')];
        $links    = [
            self::item(11, ['relation.......1' => [self::id(1), self::id(2)]]),
            self::item(12, ['relation.......1' => [strtoupper(self::id(3)), 'ffffffffffffffffffffffffffffffff']]),
            self::item(13, ['relation.......1' => [self::id(2)]], '3'),
        ];
        $filtered = FilteredSource::of(self::source([self::ARTICLES => $articles, self::LINKS => $links]), [self::ARTICLES => self::YES]);

        self::assertSame([self::LINKS => 2, self::ARTICLES => 0], $filtered->leftOut([self::LINKS, self::ARTICLES], false), '2 (no) and 3 (the trash), not an id of no item, not the link in the trash');
        self::assertSame([self::LINKS => 2, self::ARTICLES => 0], $filtered->leftOut([self::LINKS, self::ARTICLES], true), 'all bins: 3 goes along, the link in the trash too');
        self::assertSame([self::LINKS => 0], $filtered->leftOut([self::LINKS], false), 'the articles are not transferred - their links are the check of the end');
    }

    /** a filtered slice linking to itself: only the links of its selected items count */
    public function test_the_links_of_a_filtered_slice_to_itself(): void
    {
        $articles = [
            self::item(1, ['category........' => 'yes', 'relation.......2' => [self::id(2)]]),
            self::item(2, ['category........' => 'no', 'relation.......2' => [self::id(1)]]),
            self::item(3, ['category........' => 'yes', 'relation.......2' => [self::id(1), self::id(2)]]),
        ];
        $source   = self::source([self::ARTICLES => $articles]);

        self::assertSame([self::ARTICLES => 2], FilteredSource::of($source, [self::ARTICLES => self::YES])->leftOut([self::ARTICLES], false), '1 => 2 and 3 => 2; not 2 => 1');
        self::assertCount(1, self::calls($source, 'matching'), 'the selected items of the slice are read once');
    }

    public function test_nothing_left_out_reads_no_values(): void
    {
        $source = self::source([self::ARTICLES => [self::item(1, ['category........' => 'yes'])], self::LINKS => [self::item(11, ['relation.......1' => [self::id(1)]])]]);

        self::assertSame([self::LINKS => 0, self::ARTICLES => 0], FilteredSource::of($source, [self::ARTICLES => self::YES])->leftOut([self::LINKS, self::ARTICLES], true));
        self::assertSame([], array_filter($source->log, static fn(array $call): bool => ($call[0] === 'content')));
    }

    /** the batches of Collector over the filtered ids: its cursor, its MAX_ITEMS and its end as for all the items */
    public function test_item_batches_go_over_the_selected_items_across_pages(): void
    {
        $items  = array_map(static fn(int $i): array => self::item($i, ['headline........' => "h$i", 'category........' => ($i > AppSource::ID_PAGE - 200) ? 'yes' : 'no']), range(1, AppSource::ID_PAGE + 400));
        $source = self::source([self::ARTICLES => $items]);
        $source->tables['module'][0] += ['name' => 'Articles'];
        $collector = new Collector(FilteredSource::of($source, [self::ARTICLES => self::YES]));
        $options   = new CollectOptions(['url' => 'https://source.example/aa/'], CollectOptions::TRANSFER);

        $first = $collector->itemBatch(self::ARTICLES, '', $options)->package;
        self::assertCount(CollectOptions::MAX_ITEMS, $first->modules[0]->items);
        self::assertSame([self::id(AppSource::ID_PAGE - 199), self::id(AppSource::ID_PAGE + 300)], [$first->modules[0]->items[0]['id'], $first->batch->next]);
        self::assertFalse($first->batch->finished);

        $last = $collector->itemBatch(self::ARTICLES, $first->batch->next, $options)->package;
        self::assertSame(array_map(self::id(...), range(AppSource::ID_PAGE + 301, AppSource::ID_PAGE + 400)), array_column($last->modules[0]->items, 'id'));
        self::assertTrue($last->batch->finished);
    }

    public function test_the_values_are_compared_in_the_charset_of_the_slice(): void
    {
        $source = self::source([self::CP1250 => [self::item(1, ['headline........' => "\x8Elu\x9D"]), self::item(2, ['headline........' => 'Zlut'])]]);

        self::assertSame([self::id(1)], FilteredSource::of($source, [self::CP1250 => 'd-headline........-=-Žluť'])->itemIds(self::CP1250, false, '', 10));
    }

    /** @return array<string, array{string, string, string}> */
    public static function refused(): array
    {
        return [
            'no field of the slice'         => [self::ARTICLES, 'd-relation.......1-=-x', 'field:relation.......1'],
            'a related field it lacks'      => [self::LINKS, 'd-relation.......1@relation.......1-=-x', 'field:relation.......1@relation.......1'],
            'a relation that is none'       => [self::LINKS, 'd-headline........@headline........-=-x', 'field:headline........@headline........'],
            'a remote field of no slice'    => [self::ARTICLES, 'd-headline........@e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5/relation.......1-=-x', 'field:headline........@e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5/relation.......1'],
            'a remote relation it lacks'    => [self::ARTICLES, 'd-headline........@' . self::LINKS . '/category........-=-x', 'field:headline........@' . self::LINKS . '/category........'],
            'a value the charset lacks'     => [self::CP1250, 'd-headline........-=-日本', 'charset'],
            'a related slice of another charset' => [self::CP1250, 'd-headline........@relation.......1-=-Žluť', 'charset'],
            'a remote slice of another charset'  => [self::CP1250, 'd-headline........@' . self::ARTICLES . '/category........-=-Žluť', 'charset'],
            'a form the parser refuses'     => [self::ARTICLES, 'd-headline........-=-a\\b', 'characters'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused_with_the_slice(string $slice, string $text, string $reason): void
    {
        try {
            FilteredSource::of(self::source([]), [strtoupper($slice) => $text]);
            self::fail("accepted: $text");
        } catch (FilterInvalid $e) {
            self::assertSame([$slice, $reason], [$e->slice, $e->reason]);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function fieldForms(): array
    {
        return [
            'all fields'              => [self::ARTICLES, 'd-all_fields|en-=-x'],
            'a related field'         => [self::LINKS, 'd-category........@relation.......1-=-x'],
            'all related fields'      => [self::LINKS, 'd-all_fields@relation.......1-=-x'],
            'a field of a remote one' => [self::ARTICLES, 'd-headline........@' . self::LINKS . '+/relation.......1-=-x'],
            'ASCII of another charset' => [self::CP1250, 'd-headline........@relation.......1-=-Zlut'],
        ];
    }

    #[DataProvider('fieldForms')]
    public function test_the_field_forms_of_QueryZids_are_accepted(string $slice, string $text): void
    {
        self::assertInstanceOf(FilteredSource::class, FilteredSource::of(self::source([]), [$slice => $text]));
    }
}
