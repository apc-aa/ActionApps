<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\Widget;
use AA_Value;
use PHPUnit\Framework\TestCase;
use zids;

/**
 * Option lists of select-like widgets: which options are marked selected, which values are kept
 * although no option offers them, and how the list is printed.
 */
final class WidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_ifSelected_onlyTheSelectedOption(): void
    {
        $widget = new Widget([]);
        $widget->_fillSelected(new AA_Value(['b']));

        self::assertSame('yes', $widget->ifSelected('b', 'yes'));
        self::assertSame('', $widget->ifSelected('a', 'yes'));
        self::assertSame('', $widget->ifSelected('', 'yes'), 'empty option loses when something is selected');
    }

    public function test_ifSelected_emptyOptionWinsWhenNothingIsSelected(): void
    {
        $widget = new Widget([]);
        $widget->_fillSelected(new AA_Value([]));

        self::assertSame('yes', $widget->ifSelected('', 'yes'));
        self::assertSame('', $widget->ifSelected('a', 'yes'));
    }

    public function test_getOptions_marksSelectedAndKeepsMissingValues(): void
    {
        $widget = self::widget(['a' => 'A', 'b' => 'B']);

        self::assertSame([
            ['k' => 'a', 'v' => 'A', 'selected' => false, 'mis' => false],
            ['k' => 'b', 'v' => 'B', 'selected' => true, 'mis' => false],
            ['k' => 'x', 'v' => 'x', 'selected' => true, 'mis' => true],
        ], $widget->getOptions(new AA_Value(['b', 'x'])));
    }

    public function test_getOptions_missingLongIdShowsItsNameWhenKnown(): void
    {
        $id = '0123456789abcdef0123456789abcdef';

        self::assertSame('Name', self::constWidget([$id => 'Name'])->getOptions(new AA_Value([$id]))[1]['v']);
        self::assertSame($id, self::constWidget([])->getOptions(new AA_Value([$id]))[1]['v']);
    }

    public function test_getOptions_samePairOfferedOnce(): void
    {
        $widget = self::widget(['a' => 'Same', 'b' => 'Same', 'c' => 'Other']);

        self::assertSame([
            ['k' => 'Same', 'v' => 'Same', 'selected' => false, 'mis' => false],
            ['k' => 'Other', 'v' => 'Other', 'selected' => false, 'mis' => false],
        ], $widget->getOptions(new AA_Value([]), null, true));
    }

    public function test_getOptions_hiddenDefaultOnlyWhenNothingIsSelected(): void
    {
        $default = ['k' => '', 'v' => '', 'selected' => true, 'mis' => false, 'justdefault' => true];

        self::assertSame($default, self::widget(['a' => 'A'])->getOptions(new AA_Value([]), null, false, false, 2)[0]);
        self::assertSame('a', self::widget(['a' => 'A'])->getOptions(new AA_Value(['a']), null, false, false, 2)[0]['k']);
    }

    public function test_getSelectOptions_printsFlagsOfEachOption(): void
    {
        $options = [
            ['k' => 'a', 'v' => 'A'],
            ['k' => 'b', 'v' => 'B', 'selected' => true],
            ['k' => 'x', 'v' => 'x', 'selected' => true, 'mis' => true],
            ['k' => '', 'v' => '', 'selected' => true, 'mis' => false, 'justdefault' => true],
        ];
        $widget = new Widget([]);

        self::assertSame(
            '<option value="a">A</option>'
            . '<option value="b" selected class="sel_on">B</option>'
            . '<option value="x" selected class="sel_on" class="sel_missing">x</option>'
            . '<option value="" selected class="sel_on" hidden disabled></option>',
            $widget->getSelectOptions($options),
        );
        self::assertSame('<option value="a">A</option>', $widget->getSelectOptions($options, 'unselected'));
        self::assertSame('<option value="b" class="sel_on">B</option>', $widget->getSelectOptions(array_slice($options, 0, 2), 'selected', true));
    }

    /** @param array<string, string> $names what the constant group says about the missing value */
    private static function constWidget(array $names): Widget
    {
        $widget = new class ([]) extends Widget {
            public $const = 'group';
            /** @var array<string, string> */
            public array $names = [];

            public function getFormattedOptions(string $for_id = '', ?zids $restrict_zids = null, string $searchterm = '', bool $ignore_filters = false, int $limit = 0): array
            {
                return $this->names;
            }
        };
        $widget->names = $names;
        $widget->_const_arr = ['a' => 'A'];
        return $widget;
    }

    /** @param array<string, string> $const_arr */
    private static function widget(array $const_arr): Widget
    {
        // options given directly, not read from a constant group or slice
        $widget = new class ([]) extends Widget {
            public $const = '';
        };
        $widget->_const_arr = $const_arr;
        return $widget;
    }
}
