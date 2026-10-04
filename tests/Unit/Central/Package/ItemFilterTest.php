<?php

declare(strict_types=1);

/**
 * ItemFilter: the `d-` conditions of views, split by AA_View_Commands and read as QueryZids() reads them.
 * Accepted is whatever AA reads; refused only what would silently select more items than written.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FilterInvalid;
use AA\Central\Package\ItemFilter;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemFilterTest extends TestCase
{
    private const F     = 'headline........';
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';     // AA_View_Commands, AA_Dbcondition
    }

    /** @return array<string, array{string, list<array{fields: list<string>, operator: string, value: string}>}> */
    public static function accepted(): array
    {
        $f = self::F;
        $c = static fn(string $operator, string $value, array $fields = [self::F]): array => ['fields' => $fields, 'operator' => $operator, 'value' => $value];
        return [
            'a relation'            => ["d-relation.......1-=-2697399804549ac092cfe17b5c7b758e", [$c('=', '2697399804549ac092cfe17b5c7b758e', ['relation.......1'])]],
            'a set'                 => ["d-$f-=-\"a\" OR \"b\"", [$c('=', '"a" OR "b"')]],
            'two conditions'        => ["  d-$f,abstract........-BEGIN-Bio-$f-<>-x ", [$c('BEGIN', 'Bio', [self::F, 'abstract........']), $c('<>', 'x')]],
            'an escaped dash'       => ["d-$f-=-a--b", [$c('=', 'a-b')]],
            'a URL in a value'      => ["d-$f-==-&conds[0][x]=1", [$c('==', '&conds[0][x]=1')]],
            'a phrase that narrows' => ["d-$f-CONTAIN-\"a\" OR \"b\"", [$c('CONTAIN', '"a" OR "b"')]],
            '<> of one quoted'      => ["d-$f-<>-\"a b\"", [$c('<>', '"a b"')]],
            '<> of an apostrophe'   => ["d-$f-<>-O'Brien", [$c('<>', "O'Brien")]],
            'encoded quotes, ='     => ["d-$f-=-%22Bio%22", [$c('=', '"Bio"')]],
            'encoded quotes, <>'    => ["d-$f-<>-%22Bio%22", [$c('<>', '"Bio"')]],
            '!= is <>'              => ["d-$f-!=-x", [$c('<>', 'x')]],
            'ne is <>'              => ["d-$f-ne-x", [$c('<>', 'x')]],
            'lt is <'               => ["d-number.........1-lt-5", [$c('<', '5', ['number.........1'])]],
            '&gt; is >'             => ["d-number.........1-&gt;-5", [$c('>', '5', ['number.........1'])]],
            'BETWEEN'               => ["d-number.........1-BETWEEN-1,5", [$c('BETWEEN', '1,5', ['number.........1'])]],
            'REGEXP'                => ["d-$f-REGEXP-^a", [$c('REGEXP', '^a')]],
            'XLIKE'                 => ["d-$f-XLIKE-a%", [$c('XLIKE', 'a%')]],
            'BITSET'                => ["d-highlight.......-BITSET-128", [$c('BITSET', '128', ['highlight.......'])]],
            '& of a bit'            => ["d-highlight.......-&-4", [$c('&', '4', ['highlight.......'])]],
            'ISNULL needs no value' => ["d-$f-ISNULL-", [$c('ISNULL', '')]],
            'NOTMAIL'               => ["d-$f-NOTMAIL-", [$c('NOTMAIL', '')]],
            'd: a readable date'    => ["d-publish_date....-d:>-1.1.2020", [$c('d>', '1.1.2020', ['publish_date....'])]],
            'd> without the colon'  => ["d-publish_date....-d>-1.1.2020", [$c('d>', '1.1.2020', ['publish_date....'])]],
            'd#:> escaped'          => ["d-publish_date....-d#:>-1.1.2020", [$c('d>', '1.1.2020', ['publish_date....'])]],
            'e: a european date'    => ["d-publish_date....-e:<=-24. 12. 2001", [$c('e<=', '24. 12. 2001', ['publish_date....'])]],
            'e>= without the colon' => ["d-publish_date....-e>=-24. 12. 2001", [$c('e>=', '24. 12. 2001', ['publish_date....'])]],
            'e:< before 1970'       => ["d-publish_date....-e:<-1. 1. 1960", [$c('e<', '1. 1. 1960', ['publish_date....'])]],
            'm: seconds ago'        => ["d-expiry_date.....-m:<- --432000", [$c('m<', ' -432000', ['expiry_date.....'])]],
            'all_fields'            => ["d-all_fields-=-x", [$c('=', 'x', ['all_fields'])]],
            'all_fields_numeric'    => ["d-ALL_FIELDS_NUMERIC-=-5", [$c('=', '5', ['ALL_FIELDS_NUMERIC'])]],
            'all_fields languages'  => ["d-all_fields|en|cz-=-x", [$c('=', 'x', ['all_fields|en|cz'])]],
            'a field language last' => ["d-abstract........,$f|en-=-x", [$c('=', 'x', ['abstract........', "$f|en"])]],
            'a related field'       => ["d-$f@relation.......1-=-x", [$c('=', 'x', ["$f@relation.......1"])]],
            'all related fields'    => ["d-all_fields@relation.......1-=-x", [$c('=', 'x', ['all_fields@relation.......1'])]],
            'a remote field'        => ['d-' . self::F . '@' . self::SLICE . '+/relation.......1-=-x', [$c('=', 'x', [self::F . '@' . self::SLICE . '+/relation.......1'])]],
            'ten conditions'        => ['d-' . implode('-', array_fill(0, ItemFilter::MAX_CONDITIONS, "$f-=-x")), array_fill(0, ItemFilter::MAX_CONDITIONS, $c('=', 'x'))],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepted(string $text, array $conditions): void
    {
        self::assertSame($conditions, ItemFilter::parse($text)->conditions());
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        $f = self::F;
        return [
            'too long'                => ["d-$f-=-" . str_repeat('x', 1000), 'too-long'],
            'eleven conditions'       => ['d-' . implode('-', array_fill(0, ItemFilter::MAX_CONDITIONS + 1, "$f-=-x")), 'too-many'],
            'a backslash'             => ["d-$f-=-a\\.b", 'characters'],
            'an encoded backslash'    => ["d-$f-=-%22a%5Cb%22", 'characters'],
            'a control character'     => ["d-$f-=-a\x01b", 'characters'],
            'a C1 control character'  => ["d-$f-=-a\u{85}b", 'characters'],
            'no UTF-8'                => ["d-$f-=-\xff", 'characters'],
            'the conds form'          => ["conds[0][$f]=1&conds[0][value]=x", 'form'],
            'another command'         => ["x-$f-=-x", 'form'],
            'nothing'                 => ['d-', 'triples'],
            'a short triple'          => ["d-$f-=", 'triples'],
            'an empty last value'     => ["d-$f-=-x-abstract........-=-", 'value'],
            'AAnoCONDITION'           => ["d-$f-=-AAnoCONDITION", 'value'],
            'no value'                => ["d-$f-LIKE-", 'value'],
            'BITSET of no bit'        => ["d-highlight.......-BITSET-x", 'value'],
            '| of anything'           => ["d-highlight.......-|-4", 'value'],
            'no field id'             => ['d-a.............-=-x-b.............-=-', 'field:a.............'],
            'a field id too short'    => ["d-headline-=-x", 'field:headline'],
            'the operator key'        => ['d-operator-=-x', 'field:operator'],
            'an unknown language'     => ["d-$f|EN-=-x", "field:$f|EN"],
            'a language not last'     => ["d-$f|en,abstract........-=-x", "field:$f|en"],
            'a relation of no field'  => ['d-all_fields@relation-=-x', 'field:all_fields@relation'],
            'all_fields of a remote'  => ['d-all_fields@' . self::SLICE . '/relation.......1-=-x', 'field:all_fields@' . self::SLICE . '/relation.......1'],
            'an upper case slice id'  => ["d-$f@" . strtoupper(self::SLICE) . '/relation.......1-=-x', "field:$f@" . strtoupper(self::SLICE) . '/relation.......1'],
            'an unknown operator'     => ["d-$f-like-x", 'operator:like'],
            'a date of a dash'        => ["d-$f-d:>-2020-01-01", 'triples'],
            'd: not a date'           => ["d-publish_date....-d:>-whenever", 'value'],
            'e: not a date'           => ["d-publish_date....-e:>-1/2/2020", 'value'],
            'e: a trailing dot'       => ["d-publish_date....-e:>-24. 12.", 'value'],
            'm: not seconds'          => ["d-expiry_date.....-m:<-a week", 'value'],
            'm: too many seconds'     => ["d-expiry_date.....-m:<-99999999999999999999", 'value'],
            'd:= before 1970'         => ["d-publish_date....-d:=-1.1.1960", 'value'],
            'e:= before 1970'         => ["d-publish_date....-e:=-1. 1. 1960", 'value'],
            '<> of a set'             => ["d-$f-<>-\"a\" OR \"b\"", 'phrase'],
            '<> of an encoded set'    => ["d-$f-<>-%22a%22 OR %22b%22", 'phrase'],
            '<> of words'             => ["d-$f-<>-a or b", 'phrase'],
            '<> of two quoted'        => ["d-$f-<>-'a' 'b'", 'phrase'],
            '<> of a plus'            => ["d-$f-<>-+a", 'phrase'],
            '<> of a leading minus'   => ["d-$f-<>- --a", 'phrase'],
            '<> of brackets'          => ["d-$f-<>-(a)", 'phrase'],
            '<> of an open quote'     => ["d-$f-<>-'a", 'phrase'],
            '<> of a closing quote'   => ["d-$f-<>-a\"", 'phrase'],
            '= of an open quote'      => ["d-$f-=-\"a", "syntax:$f"],
            '= of a lone OR'          => ["d-$f-=-a OR", "syntax:$f"],
            'LIKE of brackets'        => ["d-$f-LIKE-()", "syntax:$f"],
            'XLIKE of brackets'       => ["d-$f-XLIKE-()", "syntax:$f"],
            'a URL in a = value'      => ["d-$f-=-&conds[0][x]=1", "syntax:$f"],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused(string $text, string $reason): void
    {
        try {
            ItemFilter::parse($text);
            self::fail("accepted: $text");
        } catch (FilterInvalid $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /** a quote as the last character used to read past the end of the value (include/sql_parser.php) */
    public function test_a_quote_at_the_end_warns_of_nothing(): void
    {
        set_error_handler(static fn(int $no, string $message): never => throw new ErrorException($message, 0, $no));
        try {
            self::assertSame("a'", ItemFilter::parse('d-' . self::F . "-=-a'")->conditions()[0]['value']);
            $this->expectException(FilterInvalid::class);
            ItemFilter::parse('d-' . self::F . '-LIKE-"');
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{string, list<string>|null}> */
    public static function fieldForms(): array
    {
        return [
            'own'                => [self::F . '|cz', ['own', self::F]],
            'all fields'         => ['all_fields|en', []],
            'related'            => ['category.......1@relation.......1', ['related', 'relation.......1', 'category.......1']],
            'all related fields' => ['all_fields@relation.......1', ['related', 'relation.......1', 'all_fields']],
            'remote'             => ['abstract........@' . self::SLICE . '/relation.......2', ['remote', self::SLICE, 'abstract........', 'relation.......2']],
            'none'               => ['all_fields@' . self::SLICE . '/relation.......2', null],
        ];
    }

    /** @param list<string>|null $form */
    #[DataProvider('fieldForms')]
    public function test_the_field_forms_by_the_slice_they_belong_to(string $token, ?array $form): void
    {
        self::assertSame($form, ItemFilter::fieldForm($token));
    }

    public function test_the_values_go_into_the_charset_of_the_slice(): void
    {
        $filter = ItemFilter::parse('d-' . self::F . '-=-Žluťoučký kůň');

        self::assertSame("\x8Elu\x9Dou\xE8k\xFD k\xF9\xF2", $filter->inCharset('windows-1250')->conditions()[0]['value']);
        $this->expectExceptionObject(new FilterInvalid('charset'));
        ItemFilter::parse('d-' . self::F . '-=-日本')->inCharset('windows-1250');
    }

    public function test_a_call_takes_the_filters_of_its_slices(): void
    {
        $other = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

        self::assertSame([self::SLICE => 'd-x'], ItemFilter::ofSlices([strtoupper(self::SLICE) => 'd-x', $other => 'd-y'], [self::SLICE]));
        self::assertSame([], ItemFilter::ofSlices([$other => 'd-y'], [strtoupper(self::SLICE)]));
    }

    public function test_the_digest_is_canonical(): void
    {
        $a = self::SLICE;
        $b = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

        self::assertSame(ItemFilter::digest([$a => 'd-x', $b => 'd-ž/y']), ItemFilter::digest([$b => 'd-ž/y', strtoupper($a) => 'd-x']));
        self::assertNotSame(ItemFilter::digest([$a => 'd-x', $b => 'd-y']), ItemFilter::digest([$a => 'd-y', $b => 'd-x']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', ItemFilter::digest([$a => 'd-x']));
    }
}
