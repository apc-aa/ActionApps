<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Item;
use AA_Items;
use PHPUnit\Framework\TestCase;
use zids;

/**
 * AA_Items::getFormatted() over items already in the request's cache - preload() then asks the
 * database nothing. The items expand to "<id>:<expression>", so no alias or slice is needed.
 */
final class ItemsGetFormattedTest extends TestCase
{
    private const A = 'af000000000000000000000000000001';
    private const B = 'af000000000000000000000000000002';
    private const C = 'af000000000000000000000000000003';
    private const TRASHED = 'af000000000000000000000000000004';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    protected function setUp(): void
    {
        AA_Items::singleton()->doInvalidate();
        foreach ([self::A => 1, self::B => 1, self::C => 1, self::TRASHED => 3] as $id => $status) {
            AA_Items::singleton()->_store(self::item($id, $status));
        }
    }

    protected function tearDown(): void
    {
        AA_Items::singleton()->doInvalidate();
    }

    /** an item active now (status 1) or in the trash (3), whose expansion may run $during first */
    private static function item(string $id, int $status, ?\Closure $during = null): AA_Item
    {
        $short = (int) substr($id, -1);
        return new class ([
            'short_id........' => [['value' => $short]],
            'unpacked_id.....' => [['value' => $id]],
            'status_code.....' => [['value' => $status]],
            'publish_date....' => [['value' => time() - 86400]],
            'expiry_date.....' => [['value' => time() + 86400]],
        ], $during) extends AA_Item {
            public function __construct(array $content, private ?\Closure $during)
            {
                parent::__construct($content, []);
            }

            public function subst_alias($text): string
            {
                if ($this->during) {
                    ($this->during)();
                }
                return $this->getItemId() . ':' . $text;
            }
        };
    }

    public function test_getFormatted_expands_each_item_in_the_order_asked(): void
    {
        $formatted = AA_Items::getFormatted(new zids([self::C, self::A, self::B], 'l'), 'x');

        $this->assertSame([self::C => self::C . ':x', self::A => self::A . ':x', self::B => self::B . ':x'], $formatted);
    }

    public function test_getFormatted_leaves_out_items_of_other_bins(): void
    {
        $ids = new zids([self::A, self::TRASHED], 'l');

        $this->assertSame([self::A], array_keys(AA_Items::getFormatted($ids, 'x')));
        $this->assertSame([self::A, self::TRASHED], array_keys(AA_Items::getFormatted($ids, 'x', AA_BIN_ALL)));
        $this->assertSame([self::TRASHED], array_keys(AA_Items::getFormatted($ids, 'x', AA_BIN_TRASH)));
    }

    /** a gate in the row opens a slice, which empties the cache while the first item expands */
    public function test_getFormatted_keeps_all_rows_when_an_expansion_empties_the_cache(): void
    {
        AA_Items::singleton()->_store(self::item(self::A, 1, static fn() => AA_Items::singleton()->doInvalidate()));

        $formatted = AA_Items::getFormatted(new zids([self::A, self::B, self::C], 'l'), 'x');

        $this->assertSame([self::A, self::B, self::C], array_keys($formatted));
    }

    public function test_getFormatted_empty_expression_expands_nothing(): void
    {
        $this->assertSame([self::A => '', self::B => ''], AA_Items::getFormatted(new zids([self::A, self::B], 'l'), ''));
    }
}
