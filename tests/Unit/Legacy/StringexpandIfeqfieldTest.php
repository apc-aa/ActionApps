<?php

declare(strict_types=1);

/**
 * {ifeqfield::<field>:<var>:<text>:<else>} without an item id reads the item being printed. Its
 * parameters are then the same in every row of a listing, so the result must not be kept for the
 * next row - each item chooses its own branch.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandIfeqfieldTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        } finally {
            restore_error_handler();
        }
    }

    public function test_expand_currentItem_choosesByItsValue(): void
    {
        self::assertSame('green', self::expand(self::item('a', ['Garden', 'Nature'])));
        self::assertSame('red', self::expand(self::item('b', ['Town'])));
    }

    public function test_expand_listing_eachRowChoosesItsOwnBranch(): void
    {
        $rows = [self::item('1', ['Nature']), self::item('2', ['Town']), self::item('3', ['Nature']), self::item('4', [])];
        self::assertSame(['green', 'red', 'green', 'red'], array_map(self::expand(...), $rows));
    }

    public function test_expand_varInTheBranch(): void
    {
        self::assertSame('is Nature', self::expand(self::item('c', ['Nature']), 'is _#1'));
    }

    private static function expand(object $item, string $text = 'green'): string
    {
        return StrExpand('AA_Stringexpand_Ifeqfield', ['', 'category.......1', 'Nature', $text, 'red'], ['item' => $item]);
    }

    /** @param string[] $values */
    private static function item(string $id, array $values): object
    {
        return new class ($id, $values) {
            /** @param string[] $values */
            public function __construct(private string $id, private array $values) {}

            public function getID(): string
            {
                return $this->id;
            }

            public function isField(string $field): bool
            {
                return $field === 'category.......1';
            }

            /** @return string[] */
            public function getValuesArray(string $field): array
            {
                return $this->values;
            }
        };
    }
}
