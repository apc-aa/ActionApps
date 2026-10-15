<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * AA_Metabase::doUpdate() builds its WHERE from the primary key of the schema alone, so it may only
 * run when that WHERE names the whole key: one row, never a part of the table. Checked for every
 * table of include/metabase_schema.php, not for a sample - a hand-edited table would break it.
 */
final class MetabaseUpdateKeyTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../../../include/varset.php';
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    /** isKeysFilled() checks the table's key list, the WHERE takes the columns flagged PRI - they must agree */
    public function test_key_list_column_flags_and_primary_index_name_the_same_columns(): void
    {
        foreach (\AA::Metabase()->tables as $name => $table) {
            $keys    = $table->getKeys();
            $flagged = array_keys(array_filter($table->getColumns(), static fn(\AA_MbC $c) => $c->isKey()));
            $primary = array_map(static fn($c) => is_array($c) ? $c[0] : $c, array_values($table->toArray()['i']['PRIMARY']['c'] ?? []));
            sort($keys);
            sort($flagged);
            sort($primary);
            $this->assertSame($keys, $flagged, "$name: key list vs PRI columns");
            $this->assertSame($keys, $primary, "$name: key list vs PRIMARY index");
        }
    }

    public function test_where_is_exactly_the_primary_key_in_every_table(): void
    {
        $checked = 0;
        foreach ($this->updatableTables() as $name => $keys) {
            RecordingDb::reset();
            $this->assertTrue(\AA::Metabase()->doUpdate($name, $this->fullRow($name)), $name);
            $this->assertCount(1, RecordingDb::$log, $name);
            $this->assertSame(1, preg_match('/^UPDATE `(\w+)` SET (.+) WHERE (.+)$/s', RecordingDb::$log[0], $m), $name);
            preg_match_all('/`(\w+)`=/', $m[2], $set);
            $this->assertSame($name, $m[1]);
            $this->assertEqualsCanonicalizing($this->keyConditions($name, $keys), explode(' AND ', $m[3]), "$name: WHERE is every key column with its typed value, nothing else");
            $this->assertSame([], array_intersect($keys, $set[1]), "$name: no key column in SET");
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);
    }

    /** a key column that is missing or empty must stop the statement - a shorter WHERE would hit more rows */
    public function test_nothing_is_sent_without_a_value_for_every_key_column(): void
    {
        $bad = ['missing' => null, 'null' => null, 'empty' => '', 'zero string' => '0', 'zero' => 0];
        foreach ($this->updatableTables() as $name => $keys) {
            foreach ($keys as $key) {
                foreach ($bad as $label => $value) {
                    $row = $this->fullRow($name);
                    if ($label === 'missing') {
                        unset($row[$key]);
                    } else {
                        $row[$key] = $value;
                    }
                    RecordingDb::reset();
                    // @ - Cvarset::get() warns about a key the row lacks: the only trace of the refused write
                    $this->assertFalse(@\AA::Metabase()->doUpdate($name, $row), "$name.$key $label");
                    $this->assertSame([], RecordingDb::$log, "$name.$key $label");
                }
            }
        }
    }

    public function test_nothing_is_sent_to_a_table_without_primary_key(): void
    {
        $this->assertSame([], \AA::Metabase()->getKeys('content'));
        $this->assertFalse(\AA::Metabase()->doUpdate('content', ['item_id' => self::ID, 'field_id' => 'headline........', 'text' => 'x']));
        $this->assertSame([], RecordingDb::$log);
    }

    /** @return array<string, string[]> tables with a primary key and at least one column outside it => key columns */
    private function updatableTables(): array
    {
        $tables = [];
        foreach (\AA::Metabase()->tables as $name => $table) {
            $keys = $table->getKeys();
            if ($keys && array_diff($table->getColumnNames(), $keys)) {
                $tables[$name] = $keys;
            }
        }
        return $tables;
    }

    /** @return string[] the WHERE conditions fullRow() must give: a packed id as 0x literal, the rest quoted */
    private function keyConditions(string $name, array $keys): array
    {
        $row = $this->fullRow($name);
        return array_map(static fn($k) => \AA_Metabase::isPacked($name, $k) ? "$k=0x{$row[$k]}" : "$k='{$row[$k]}'", $keys);
    }

    /** every column of the table set to a valid value of its type */
    private function fullRow(string $name): array
    {
        $row = [];
        foreach (\AA::Metabase()->tables[$name]->getColumns() as $col => $def) {
            $row[$col] = match (true) {
                \AA_Metabase::isPacked($name, $col) => self::ID,
                $def->getFieldType() === 'int'      => 7,
                default                             => 'v',
            };
        }
        return $row;
    }
}
