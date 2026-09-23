<?php

declare(strict_types=1);

/**
 * CopyTableRows() (include/varset.php): what reaches the SQL for set_columns and id_columns.
 *
 * Copied and set_columns values go through a Cvariable of type "text", which escapes them
 * (quote() = addslashes). So set_columns must be RAW: an escaped value is escaped twice and
 * MySQL stores the backslashes - for a packed 16-byte id with a \0 ' " or \ byte, a longer,
 * wrong id. The first two tests pin that contract. The one caller that passes a packed id,
 * the view copy of "New slice from template" (SliceEditProcess() in include/slicedit.php,
 * too entangled to run here), is covered only by reading the code: it must pass pack_id().
 *
 * id_columns get a fresh id per row, sent as an "unpacked" 0x… literal with nothing to escape;
 * the third test is the regression test for those (CopyConstants() in
 * include/constedit_util.php used to store about 12 % of them broken).
 *
 * The DB is a RecordingDb: it lists the table's columns, answers the SELECT of the rows to
 * copy and records the INSERTs; no database or session is involved.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class CopyTableRowsTest extends TestCase
    {
        private const TEMPLATE = '0123456789abcdef0123456789abcdef';
        /** packs to ' \0 \ " and twelve 0x11 bytes - every byte addslashes() escapes */
        private const NEW_SLICE = '27005c22111111111111111111111111';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';     // new_id()
            require_once __DIR__ . '/../../../include/varset.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        /** Copies the template's views to another slice id, as slicedit.php does. */
        private function copyViews(string $new_slice_value): bool
        {
            RecordingDb::$metadata['view'] = [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'slice_id', 'type' => 'varbinary'],
                ['name' => 'name', 'type' => 'varchar'],
                ['name' => 'type', 'type' => 'varbinary'],
            ];
            RecordingDb::$answer = static fn(string $sql) => [
                ['id' => '7', 'slice_id' => pack_id(self::TEMPLATE), 'name' => "Bob's list", 'type' => 'full'],
            ];
            return CopyTableRows('view', 'slice_id=' . xpack_id(self::TEMPLATE), ['slice_id' => $new_slice_value], ['id']);
        }

        /** Characterization: set_columns values are escaped here - a raw packed id is stored byte-exact. */
        public function test_set_columns_escaped_here_so_raw_packed_id_is_stored_byte_exact(): void
        {
            $this->assertTrue($this->copyViews(pack_id(self::NEW_SLICE)));

            $this->assertSame('SELECT * FROM view WHERE slice_id=0x' . self::TEMPLATE, RecordingDb::$log[0]);
            // escaped once: \' \0 \\ \" then the twelve plain bytes
            $this->assertStringContainsString("'\\'\\0\\\\\\\"" . str_repeat("\x11", 12) . "'", RecordingDb::$log[1]);
            $inserts = RecordingDb::inserts('view');
            $this->assertCount(1, $inserts);
            $this->assertSame(pack_id(self::NEW_SLICE), $inserts[0]['slice_id']);
            // the copied columns are escaped once too; the auto-increment id is left out
            $this->assertSame("Bob's list", $inserts[0]['name']);
            $this->assertArrayNotHasKey('id', $inserts[0]);
        }

        /**
         * Characterization of the footgun: an already quoted value (q_pack_id()) is escaped a
         * second time and stored WITH its backslashes - 20 bytes instead of the 16-byte id.
         */
        public function test_set_columns_escaped_here_so_quoted_value_is_escaped_twice(): void
        {
            $this->assertTrue($this->copyViews(q_pack_id(self::NEW_SLICE)));

            $stored = RecordingDb::inserts('view')[0]['slice_id'];
            $this->assertSame(addslashes(pack_id(self::NEW_SLICE)), $stored);
            $this->assertSame(20, strlen($stored));
        }

        /** Regression (CopyConstants()): each copied row gets a fresh id, stored as the 16 bytes it is. */
        public function test_id_columns_get_fresh_ids_stored_byte_exact(): void
        {
            RecordingDb::$metadata['constant'] = [
                ['name' => 'id', 'type' => 'varbinary'],
                ['name' => 'group_id', 'type' => 'varbinary'],
                ['name' => 'name', 'type' => 'char'],
                ['name' => 'value', 'type' => 'char'],
                ['name' => 'pri', 'type' => 'smallint'],
                ['name' => 'short_id', 'type' => 'int'],
            ];
            $source = [];
            for ($i = 0; $i < 200; ++$i) {
                $source[] = ['id' => pack_id(sprintf('%032x', $i + 1)), 'group_id' => 'old_group', 'name' => "O'Neil $i", 'value' => "v$i", 'pri' => '100', 'short_id' => (string) ($i + 1)];
            }
            RecordingDb::$answer = static fn(string $sql) => $source;

            $this->assertTrue(CopyTableRows('constant', "group_id='old_group'", ['group_id' => 'new_group'], ['short_id'], ['id']));

            $inserts = RecordingDb::inserts('constant');
            $this->assertCount(200, $inserts);
            // each id as the SQL carries it: a bare 0x… literal of 16 bytes - RecordingDb::inserts()
            // decodes '…' strings of hex digits to the same text, so only the log shows the form
            $sql = array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'INSERT INTO `constant`')));
            $ids = [];
            foreach ($sql as $i => $statement) {
                $this->assertMatchesRegularExpression('/^INSERT INTO `constant` \(`id`,.*\) VALUES \(0x[0-9a-f]{32},/s', $statement, "id of row $i");
                $ids[] = hex2bin(substr($statement, strpos($statement, 'VALUES (0x') + 10, 32));
            }
            $this->assertCount(200, array_unique($ids), 'fresh ids are unique');
            $this->assertEmpty(array_intersect($ids, array_column($source, 'id')), 'no id is reused');

            $this->assertSame('new_group', $inserts[0]['group_id']);
            $this->assertSame("O'Neil 0", $inserts[0]['name']);
            $this->assertArrayNotHasKey('short_id', $inserts[0]);
        }
    }
}
