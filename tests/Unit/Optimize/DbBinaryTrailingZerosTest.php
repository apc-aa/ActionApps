<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\DbBinaryTrailingZeros;
use PHPUnit\Framework\TestCase;

/** repair() redefines each column it cleans; a width other than the schema's cuts the stored
 *  values - the object_* ids were once cut to 16 bytes this way, breaking every stored setting */
final class DbBinaryTrailingZerosTest extends TestCase
{
    public function test_repair_redefinesEveryColumnAsTheSchemaHasIt(): void
    {
        $repair = new class extends DbBinaryTrailingZeros {
            /** @var list<array{string, string, string}> */
            public array $fixed = [];

            public function _fixTable($table, $field, $definition): void
            {
                $this->fixed[] = [$table, $field, $definition];
            }
        };
        $repair->repair();
        $schema = require dirname(__DIR__, 3) . '/include/metabase_schema.php';

        self::assertNotEmpty($repair->fixed);
        foreach ($repair->fixed as [$table, $field, $definition]) {
            $type = strtolower((string) strtok($definition, ' '));
            self::assertSame(strtolower($schema[$table]['c'][$field][1] ?? 'missing'), $type, "$table.$field");
        }
    }
}
