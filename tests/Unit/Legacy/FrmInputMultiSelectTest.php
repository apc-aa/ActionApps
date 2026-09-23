<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * getFrmInputMultiSelect() (include/formutil.php) re-selects what the user picked - all of it.
 * Central Copy Slice used to pass only the first posted value (reset($ids)), because a plain
 * list of values ended in a TypeError inside AA_Inputfield, which wants [['value' => ...], ...].
 */
final class FrmInputMultiSelectTest extends TestCase
{
    private const OPTIONS = ['a' => 'A', 'b' => 'B', 'c' => 'C'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/formutil.php';
    }

    /** @return string[] values of the selected options */
    private static function selected(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"\s+selected/', $html, $m);
        return $m[1];
    }

    public function test_single_value_selects_that_option(): void
    {
        $this->assertSame(['a'], self::selected(getFrmInputMultiSelect('x[]', 'X', self::OPTIONS, 'a')));
    }

    public function test_list_of_values_selects_every_one(): void
    {
        $this->assertSame(['a', 'c'], self::selected(getFrmInputMultiSelect('x[]', 'X', self::OPTIONS, ['a', 'c'])));
    }

    public function test_nothing_selected_selects_the_empty_option(): void
    {
        $this->assertSame([''], self::selected(getFrmInputMultiSelect('x[]', 'X', self::OPTIONS, [])));
        $this->assertSame([''], self::selected(getFrmInputMultiSelect('x[]', 'X', self::OPTIONS, '')));
    }

    public function test_value_array_is_still_accepted(): void
    {
        $this->assertSame(['b'], self::selected(getFrmInputMultiSelect('x[]', 'X', self::OPTIONS, [['value' => 'b']])));
    }
}
