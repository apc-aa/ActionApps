<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\OptimizeTables;
use PHPUnit\Framework\TestCase;

/**
 * AA\Later\OptimizeTables replaces misc/optimize.php (weekly OPTIMIZE TABLE,
 * fetched over HTTP with a piece of the DB password as the key). The pure part
 * is the table order; what has to stay wired a certain way - weekly plan, no
 * password, resumable with a distinct selector - is anchored on the source.
 */
final class OptimizeTablesTest extends TestCase
{
    private const SOURCE   = __DIR__ . '/../../../src/Later/OptimizeTables.php';
    private const REGISTRY = __DIR__ . '/../../../src/Later/ScheduleAaTasks.php';

    public function test_only_schema_tables_that_exist_and_smallest_first(): void
    {
        $schema = ['pagecache', 'item', 'content', 'slice', 'tmp_never'];
        $sizes  = ['bck_item' => 5, 'item' => 300, 'pagecache' => 900_000, 'content' => 20_000, 'slice' => 300];

        $this->assertSame(['item', 'slice', 'content', 'pagecache'], OptimizeTables::order($schema, $sizes), 'bck_* is not in the schema, tmp_never is not in the DB; ties by name');
    }

    public function test_empty_database_gives_nothing_to_do(): void
    {
        $this->assertSame([], OptimizeTables::order(['item'], []));
    }

    public function test_is_planned_weekly_and_follow_up_uses_its_own_selector(): void
    {
        $registry = (string) file_get_contents(self::REGISTRY);
        $this->assertMatchesRegularExpression('/laterOnce\(new OptimizeTables\(\), \[\], \'OptimizeTables\', \d+, now\(\) \+ \(60 \* 60 \* 24 \* 7\)\)/', $registry);

        $this->assertNotSame('OptimizeTables', OptimizeTables::CONTINUE_SELECTOR, 'laterOnce() on the running task\'s selector would only move the claimed row, which is deleted when the task returns');
        $this->assertLessThan(59, OptimizeTables::BUDGET, 'a run must fit in the queue\'s allowed time (TOEXECUTE_ALLOWED_TIME 59 s)');
    }

    public function test_source_carries_no_password_and_no_http(): void
    {
        // php_strip_whitespace() drops comments on purpose: the class docblock itself
        // names DB_PASSWORD when describing what it replaced
        $code = php_strip_whitespace(self::SOURCE);
        $this->assertStringNotContainsString('DB_PASSWORD', $code);
        $this->assertStringNotContainsString('AA_INSTAL_URL', $code);
        $this->assertStringNotContainsString('fopen', $code);
    }
}
