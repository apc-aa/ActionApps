<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * Metabase writes store what hand-written SQL stores:
 *  - a packed column takes any value q_pack_id() took ('0' and shorter ids exist in production data);
 *  - text is not cut in PHP - MySQL in the non-strict sql_mode of DB_PDO cuts varchar by characters;
 *  - doInsertMulti() rows equal the one-row doInsert(): a missing column gets its DEFAULT.
 */
final class MetabaseWriteValuesTest extends TestCase
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

    /** @return array<string, array{string}> */
    public static function oddPackedIds(): array
    {
        return [
            'zero id'      => ['0'],
            '15-byte id'   => [substr(self::ID, 0, 30)],
            'empty'        => [''],
            'injection'    => ["x' OR '1'='1"],
        ];
    }

    #[DataProvider('oddPackedIds')]
    public function test_doInsert_stores_an_odd_packed_id_as_q_pack_id_did(string $id): void
    {
        \AA::Metabase()->doInsert('relation', ['source_id' => self::ID, 'destination_id' => $id, 'flag' => 2]);

        $this->assertSame(["INSERT INTO `relation` (`source_id`,`destination_id`,`flag`) VALUES (0x" . self::ID . ",'" . q_pack_id($id) . "',2)"], RecordingDb::$log);
    }

    #[DataProvider('oddPackedIds')]
    public function test_getSQLData_stores_an_odd_packed_id_as_q_pack_id_did(string $id): void
    {
        $sql = \AA::Metabase()->tables['relation']->getSQLData(['source_id' => self::ID, 'destination_id' => $id, 'flag' => 2]);

        $this->assertSame("'" . q_pack_id($id) . "'", $sql['destination_id']);
    }

    public function test_text_longer_than_the_column_is_not_cut_in_php(): void
    {
        $name = str_repeat('č', 101);   // varchar(100): 101 characters, 202 bytes - MySQL keeps 100 characters

        \AA::Metabase()->doInsert('module', ['id' => self::ID, 'name' => $name]);
        $sql = \AA::Metabase()->tables['module']->getSQLData(['id' => self::ID, 'name' => $name]);

        $this->assertStringContainsString("'$name'", RecordingDb::$log[0]);
        $this->assertSame("'$name'", $sql['name']);
    }

    public function test_getSQLData_gives_a_missing_or_null_column_its_default(): void
    {
        $sql = \AA::Metabase()->tables['hit_archive']->getSQLData(['id' => 5, 'time' => 7, 'duration' => null]);

        $this->assertSame(['id' => 5, 'time' => 7, 'hits' => 'DEFAULT', 'duration' => 'DEFAULT'], $sql, 'hits is NOT NULL DEFAULT 1');
        $sql = \AA::Metabase()->tables['relation']->getSQLData(['source_id' => self::ID, 'destination_id' => self::ID]);
        $this->assertSame('NULL', $sql['flag'], 'DEFAULT NULL - the same');
    }

    public function test_doInsertMulti_writes_the_values_doInsert_writes(): void
    {
        $id   = self::ID;
        $rows = [
            ['id' => $id, 'group_id' => "a'b\\c", 'name' => 'Č', 'value' => '1.5', 'class' => '', 'pri' => '7', 'ancestors' => $id . $id, 'description' => null],
            ['id' => $id, 'group_id' => 'g', 'name' => 'n', 'value' => 'v', 'pri' => 3],
        ];
        foreach ($rows as $row) {
            \AA::Metabase()->doInsert('constant', $row);
        }
        \AA::Metabase()->doInsertMulti('constant', $rows);

        $this->assertSame([
            "INSERT INTO `constant` (`id`,`group_id`,`name`,`value`,`class`,`pri`,`ancestors`) VALUES (0x$id,'a\\'b\\\\c','Č','1.5','',7,0x$id$id)",
            "INSERT INTO `constant` (`id`,`group_id`,`name`,`value`,`pri`) VALUES (0x$id,'g','n','v',3)",
            "INSERT INTO `constant` (`id`,`group_id`,`name`,`value`,`class`,`pri`,`ancestors`,`description`,`short_id`) VALUES "
            . "(0x$id,'a\\'b\\\\c','Č','1.5','',7,0x$id$id,NULL,NULL), (0x$id,'g','n','v',NULL,3,NULL,NULL,NULL)",
        ], RecordingDb::$log);
    }
}
