<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\SeoDuplicates;
use PHPUnit\Framework\TestCase;

/** duplicatesSql() is the DB-free piece; test() walks the site modules of a live install.
 *  The assertions pin what keeps the scan safe and correct: packed ids only,
 *  trash excluded, empty SEO names excluded, a cap on the result. */
final class SeoDuplicatesTest extends TestCase
{
    private const SLICE_A = '48ef01ee3c8b3e992cb45134ed8c5770';
    private const SLICE_B = '5d56f1faa24d5d723bb4c921289a0654';

    public function test_two_slices_go_into_an_in_list_as_packed_ids(): void
    {
        $sql = SeoDuplicates::duplicatesSql([self::SLICE_A, self::SLICE_B]);
        $this->assertStringContainsString('i.slice_id IN (0x' . self::SLICE_A . ',0x' . self::SLICE_B . ')', $sql);
    }

    public function test_one_slice_is_an_equality(): void
    {
        $this->assertStringContainsString('i.slice_id = 0x' . self::SLICE_A, SeoDuplicates::duplicatesSql([self::SLICE_A]));
    }

    public function test_only_the_seo_field_is_compared(): void
    {
        $this->assertStringContainsString("`c`.`field_id` = 'seo.............'", SeoDuplicates::duplicatesSql([self::SLICE_A]));
    }

    /** status_code=1 is what CreateBinCondition() makes of the ACTIVE|EXPIRED|PENDING
     *  bins {seo2ids} resolves - the holding bin (2) and the trash (3) are out */
    public function test_only_items_that_can_own_a_url_are_compared_and_the_result_is_capped(): void
    {
        $sql = SeoDuplicates::duplicatesSql([self::SLICE_A]);
        $this->assertStringContainsString('i.status_code = 1', $sql);
        $this->assertStringContainsString("c.text > ''", $sql);
        $this->assertMatchesRegularExpression('/GROUP BY `c`\.`text` HAVING cnt > 1 ORDER BY cnt DESC, seo LIMIT 0,\d+$/', $sql);
    }

    /** a multi-value field stores one content row per value, so counting rows would
     *  report a single item as a duplicate of itself */
    public function test_items_are_counted_and_listed_distinctly(): void
    {
        $sql = SeoDuplicates::duplicatesSql([self::SLICE_A]);
        $this->assertStringContainsString('COUNT(DISTINCT `i`.`id`) AS cnt', $sql);
        $this->assertStringContainsString('GROUP_CONCAT(DISTINCT LOWER(HEX(`i`.`id`)) ORDER BY LOWER(HEX(`i`.`id`)))', $sql);
    }

    public function test_non_hex_slice_id_cannot_reach_the_sql(): void
    {
        $sql = SeoDuplicates::duplicatesSql(["' OR 1=1 --", self::SLICE_A]);
        $this->assertStringNotContainsString('OR 1=1', $sql);
        $this->assertStringContainsString('IN (0x00,0x' . self::SLICE_A . ')', $sql);
    }
}
