<?php

declare(strict_types=1);

/**
 * Regression test for finding C1.10 (2026-09): admin/se_constant.php, the constants admin.
 *
 * The group id travels in the form as varname4form() - 'v' + hex - and the page packed it
 * back with no check, so any bytes reached the SQL. The "Constants used in slice" lookup put
 * them raw into a LIKE (UNION injection, the result rendered on the page), and "Delete whole
 * group" ran before the ownership check - any PS_FIELDS user deleted any slice's group, or,
 * with 'lt_groupNames', the list of all the groups.
 *
 * The page is a script, so what it decides is in include/constedit_util.php and tested here:
 * id validation, the escaped LIKE, the permission rules and the guarded delete. The DB is a
 * RecordingDb (answers scripted SELECTs, records every statement); no database or session.
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

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ConstantGroupAdminTest extends TestCase
    {
        private const MY_SLICE      = '11111111111111111111111111111111';
        private const FOREIGN_SLICE = '22222222222222222222222222222222';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/varset.php';
            require_once __DIR__ . '/../../../include/constedit_util.php';
            require_once __DIR__ . '/../../../include/auth.php';
            require_once __DIR__ . '/../../../include/mailman.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb(), new RecordingDb()];   // legacy code nests handles
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        /** PS_FIELDS in MY_SLICE only */
        private static function mayEdit(): callable
        {
            return static fn(string $slice_id): bool => $slice_id === self::MY_SLICE;
        }

        /** Scripts the DB: the owner of the group (null = nobody) and the slices using it. */
        private static function groupIn(?string $owner, array $using_slices = []): void
        {
            RecordingDb::$answer = static function (string $sql) use ($owner, $using_slices): array {
                if (str_contains($sql, 'FROM constant_slice')) {
                    return $owner ? [['owner_id' => $owner, 'name' => 'Owner slice', 'propagate' => 1]] : [];
                }
                if (str_contains($sql, 'FROM `module`, `field`')) {
                    return array_map(static fn($sid) => ['unpackid' => $sid, 'deleted' => 0, 'sname' => 'S', 'fid' => 'category........', 'fname' => 'Category'], $using_slices);
                }
                return [];
            };
        }

        private static function deletes(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, 'DELETE')));
        }

        /** @return array<string, array{string}> */
        public static function validIds(): array
        {
            return [
                'core languages' => ['lt_languages'],
                'core bins'      => ['AA_Core_Bins....'],
                'with space'     => ['My group 2'],
                'form charset'   => ['a-b+c.d_e'],
                'utf-8'          => ['kočky'],
                '16 bytes'       => [str_repeat('x', 16)],
                'single char'    => ['x'],
            ];
        }

        /** @return array<string, array{string}> */
        public static function invalidIds(): array
        {
            return [
                'empty'           => [''],
                '17 bytes'        => [str_repeat('x', 17)],
                'quote'           => ["a'b"],
                'union payload'   => ["' UNION SELECT"],
                'double quote'    => ['a"b'],
                'backslash'       => ['a\\b'],
                'backtick'        => ['a`b'],
                'percent'         => ['100%'],
                'colon separator' => ['sel:x'],
                'NUL byte'        => ["a\0b"],
                'newline'         => ["a\nb"],
                'hex garbage'     => ["\xff\xfe\x27\x00"],
                // '$' would accept these; 17 bytes are cut to 16 by the column - another group
                'trailing newline' => ["lt_languages\n"],
                '16 bytes + \\n'   => [str_repeat('x', 16) . "\n"],
            ];
        }

        #[DataProvider('validIds')]
        public function test_valid_group_id_is_accepted(string $group_id): void
        {
            $this->assertTrue(constant_group_id_valid($group_id));
            $this->assertSame($group_id, constant_group_from_form('v' . bin2hex($group_id)));
        }

        #[DataProvider('invalidIds')]
        public function test_invalid_group_id_is_rejected(string $group_id): void
        {
            $this->assertFalse(constant_group_id_valid($group_id));
            if ($group_id !== '') {
                $this->assertNull(constant_group_from_form('v' . bin2hex($group_id)));
            }
        }

        public function test_form_value_decoding(): void
        {
            $this->assertSame('', constant_group_from_form(''));
            $this->assertSame('', constant_group_from_form('v'));
            $this->assertNull(constant_group_from_form('vzz'), 'not hex');
            $this->assertNull(constant_group_from_form('v616'), 'odd number of hex digits');
            $this->assertNull(constant_group_from_form('v' . bin2hex("x' UNION SELECT password FROM perms #")));
        }

        /** constant_group_denied() as the page calls it, the owner read from the scripted DB */
        private static function denied(string $group_id, bool $delete = false, bool $superadmin = false, bool $change = true): string
        {
            return constant_group_denied($group_id, constant_group_owner($group_id), self::mayEdit(), $delete, $superadmin, $change);
        }

        public function test_used_in_slices_query_escapes_the_group_id(): void
        {
            // a valid id with LIKE wildcards: '_' must match itself only
            constant_group_fields('a_b');
            $this->assertStringContainsString("(field.input_show_func LIKE '%:a\\\\_b' OR field.input_show_func LIKE '%:a\\\\_b:%')", RecordingDb::$log[0]);

            // defence in depth - even an id that skipped validation stays inside the string
            RecordingDb::reset();
            constant_group_fields("x' UNION SELECT 1 #");
            $this->assertStringContainsString("LIKE '%:x\\' UNION SELECT 1 #'", RecordingDb::$log[0]);
        }

        public function test_core_groups_are_never_deleted(): void
        {
            foreach (AA_CONSTANT_GROUPS_CORE as $core) {
                $this->assertFalse(delete_constant_group($core), $core);
                $this->assertNotSame('', self::denied($core, true, true), "$core - not even by a superadmin");
            }
            $this->assertContains('lt_groupNames', AA_CONSTANT_GROUPS_CORE);
            $this->assertContains('lt_languages', AA_CONSTANT_GROUPS_CORE);
            $this->assertSame([], self::deletes());
        }

        public function test_invalid_group_id_is_never_deleted(): void
        {
            $this->assertFalse(delete_constant_group("x' OR '1'='1"));
            $this->assertSame([], self::deletes());
        }

        public function test_delete_of_a_foreign_group_is_refused(): void
        {
            self::groupIn(self::FOREIGN_SLICE, [self::FOREIGN_SLICE]);
            $this->assertNotSame('', self::denied('theirs', true));
        }

        public function test_delete_of_an_own_group_is_allowed(): void
        {
            self::groupIn(self::MY_SLICE, [self::MY_SLICE, self::FOREIGN_SLICE]);
            $this->assertSame('', self::denied('mine', true));
        }

        public function test_delete_of_an_unowned_group_used_by_a_foreign_slice_is_refused(): void
        {
            self::groupIn(null, [self::MY_SLICE, self::FOREIGN_SLICE]);
            $this->assertNotSame('', self::denied('shared', true));
        }

        public function test_delete_of_an_unowned_group_used_only_where_the_user_may_edit_is_allowed(): void
        {
            self::groupIn(null, [self::MY_SLICE]);
            $this->assertSame('', self::denied('orphan', true));
        }

        public function test_delete_removes_names_constants_and_ownership(): void
        {
            self::groupIn(self::MY_SLICE);
            $this->assertTrue(delete_constant_group('mine'));
            $deletes = self::deletes();
            $this->assertCount(2, $deletes);
            $this->assertStringContainsString("`group_id`='lt_groupNames' AND `value`='mine'", $deletes[0]);
            $this->assertStringContainsString("OR `group_id`='mine'", $deletes[0]);
            $this->assertStringStartsWith('DELETE FROM `constant_slice`', $deletes[1]);
            $this->assertStringContainsString("group_id = 'mine'", $deletes[1]);
        }

        public function test_delete_of_an_orphan_ownership_row_succeeds(): void
        {
            // no constants left, just the constant_slice row
            RecordingDb::$affected = static fn(string $sql) => str_contains($sql, 'FROM `constant` ') ? 0 : 1;
            $this->assertTrue(delete_constant_group('orphan'));
            $this->assertCount(2, self::deletes());
        }

        public function test_delete_for_a_slice_keeps_a_group_shared_with_another_slice(): void
        {
            // modules/alerts/synchro2.php deletes the groups of the fields it removes from $slice_id
            self::groupIn(null, [self::MY_SLICE, self::FOREIGN_SLICE]);
            $this->assertFalse(delete_constant_group('shared', self::MY_SLICE));
            $this->assertSame([], self::deletes());

            self::groupIn(null, [self::MY_SLICE]);
            $this->assertTrue(delete_constant_group('only_mine', self::MY_SLICE));
            $this->assertCount(2, self::deletes());
        }

        public function test_edit_rules(): void
        {
            self::groupIn(self::FOREIGN_SLICE);
            $this->assertNotSame('', self::denied('theirs'), 'owned by a slice without PS_FIELDS');

            self::groupIn(self::MY_SLICE);
            $this->assertSame('', self::denied('mine'));

            self::groupIn(null, [self::MY_SLICE]);
            $this->assertSame('', self::denied('free_group'), 'unowned - whoever saves it first becomes the owner');

            self::groupIn(null);
            $this->assertSame('', self::denied('unused_group'));

            // saving propagates the values into the items of every slice using the group
            self::groupIn(null, [self::MY_SLICE, self::FOREIGN_SLICE]);
            $this->assertNotSame('', self::denied('shared_group'), 'unowned, used also where the user may not');
            $this->assertSame('', self::denied('shared_group', change: false), 'but viewable');

            self::groupIn(self::FOREIGN_SLICE);
            $this->assertNotSame('', self::denied('theirs', change: false), 'a foreign group is not even viewed');

            self::groupIn(null);
            $this->assertNotSame('', self::denied('lt_groupNames', false, true), 'the list of groups is not edited here');
        }

        public function test_core_groups_are_edited_by_superadmin_only(): void
        {
            self::groupIn(null, [self::MY_SLICE]);
            $this->assertNotSame('', self::denied('lt_languages'));
            $this->assertSame('', self::denied('lt_languages', false, true));
            $this->assertSame('', self::denied('lt_languages', change: false), 'anybody may look (input form "Edit" button)');
            $this->assertSame('', self::denied('AA_Core_Bins....', change: false));

            self::groupIn(self::MY_SLICE, [self::MY_SLICE]);
            $this->assertNotSame('', self::denied('AA_Core_Bins....'), 'not even when a slice of the user took it over');
        }

        public function test_propagated_value_is_escaped(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM content, item, field')
                ? [['item_id' => pack_id('33333333333333333333333333333333'), 'field_id' => 'category........']]
                : [];

            $this->assertSame(1, constant_value_replace('a_b', "O'Brien", "x\\', flag=1 WHERE 1 #"));

            [$select, $update] = RecordingDb::$log;
            $this->assertStringContainsString("(field.input_show_func LIKE '___:a\\\\_b:%' OR field.input_show_func LIKE '___:a\\\\_b')", $select);
            $this->assertStringContainsString("content.text = 'O\\'Brien'", $select);
            $this->assertStringStartsWith("UPDATE content SET text='x\\\\\\', flag=1 WHERE 1 #' WHERE ", $update);
            $this->assertStringContainsString('item_id = 0x33333333333333333333333333333333', $update);
            $this->assertStringEndsWith("AND text='O\\'Brien'", $update);
        }

        public function test_add_and_refresh_refuse_invalid_group_ids(): void
        {
            $this->assertIsString(add_constant_group(str_repeat('x', 17), [['name' => 'a', 'value' => 'a']]));
            $this->assertIsString(add_constant_group("a'b", [['name' => 'a', 'value' => 'a']]));
            $this->assertFalse(refresh_constant_group("a'b", ['a' => 'A']));
            $this->assertFalse(refresh_constant_group('lt_groupNames', ['a' => 'A']));
            $this->assertSame([], RecordingDb::$log);
        }

        public function test_hierarchical_editor_calldata(): void
        {
            [$delete, $changes] = hcParseCalldata("12,x13, 14 :changes: :a\\:b\\~c~v\\'~10~d~7~:n~w~~~#1~,7,99");
            $this->assertSame([12, 14], $delete);
            $this->assertSame([['a:b~c', "v'", '10', 'd', '7', ''], ['n', 'w', '', '', '#1', ',7,99']], $changes);

            $this->assertSame([[5], null], hcParseCalldata('5'));
        }

        public function test_hierarchical_editor_stores_raw_values_in_the_group_only(): void
        {
            $mine = str_repeat('a', 32);
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'SELECT short_id')
                ? (str_contains($sql, "group_id = 'g'") ? [['short_id' => 7, 'id' => $mine]] : [['short_id' => 7, 'id' => $mine], ['short_id' => 99, 'id' => str_repeat('b', 32)]])
                : [];

            hcStoreChanges('g', [
                ["n\\", "x\\", '1', "d\\' OR 1=1 #", '7 OR 1=1', ''],  // backslash payloads, crafted short id
                ['child', 'c', '', '', '#1', ',7,99'],                  // 99 is a constant of another group
            ], false);

            $this->assertStringContainsString("group_id = 'g'", RecordingDb::$log[0], 'the short id map is read for the group only');
            $update = RecordingDb::$log[1];
            $this->assertStringStartsWith('UPDATE `constant` SET ', $update);
            $this->assertStringContainsString("`name`='n\\\\'", $update);
            $this->assertStringContainsString("`value`='x\\\\'", $update);
            $this->assertStringContainsString("`description`='d\\\\\\' OR 1=1 #'", $update);
            $this->assertStringEndsWith("WHERE short_id = 7 AND group_id = 'g'", $update);

            [$insert] = RecordingDb::inserts('constant');
            $this->assertSame('g', $insert['group_id']);
            $this->assertSame($mine, $insert['ancestors'], 'only the ancestor of this group');
        }

        public function test_reader_groups_handler_escapes_the_values(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'SELECT group_id FROM `constant`') ? [['group_id' => "o'k_1"]] : [];

            \AA_Mysqlauth::changeGroups(str_repeat('c', 32), 'old', "x\\' OR 1=1 #");

            $select = RecordingDb::$log[1];
            $this->assertStringContainsString("field.input_show_func LIKE '___:o\\'k\\\\_1:%'", $select);
            $this->assertStringContainsString("content.text = 'x\\\\\\' OR 1=1 #'", $select);
        }

        public function test_mailman_handler_escapes_the_group_id(): void
        {
            $GLOBALS['MAILMAN_SYNCHRO_DIR'] = sys_get_temp_dir() . '/';
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'SELECT group_id FROM `constant`') ? [['group_id' => "o'k_1"]] : [];
            try {
                \AA_Mailman::constantsChanged(str_repeat('c', 32));
            } finally {
                unset($GLOBALS['MAILMAN_SYNCHRO_DIR']);
            }
            $this->assertStringContainsString("field.input_show_func LIKE '___:o\\'k\\\\_1')", RecordingDb::$log[1]);
        }

        public function test_reader_group_rename_touches_only_the_old_group(): void
        {
            RecordingDb::$answer = static fn(string $sql) => match (true) {
                str_contains($sql, 'SELECT group_id FROM `constant`') => [['group_id' => 'readers']],
                str_contains($sql, 'SELECT username.text')            => [['text' => "o'bob"]],
                default                                               => [],
            };

            \AA_Mysqlauth::changeGroups(str_repeat('c', 32), "old'grp", "new'grp");

            $writes = array_values(array_filter(RecordingDb::$log, static fn($sql) => !str_starts_with($sql, 'SELECT')));
            $this->assertCount(2, $writes, 'the other groups of the reader stay');
            $this->assertStringStartsWith('DELETE FROM `auth_group`', $writes[0]);
            $this->assertStringContainsString("username = 'o\\'bob'", $writes[0]);
            $this->assertStringContainsString("`groups` = 'old\\'grp'", $writes[0]);
            $this->assertStringStartsWith('INSERT IGNORE INTO auth_group', $writes[1]);
            $this->assertStringContainsString("('o\\'bob', 'new\\'grp', ", $writes[1]);
        }

        public function test_reader_groups_are_stored_escaped(): void
        {
            \AA_Mysqlauth::updateGroups("o'bob", [['value' => 'g1;g2']]);
            $this->assertCount(3, RecordingDb::$log);
            $this->assertStringContainsString("('o\\'bob','g1',", RecordingDb::$log[1]);
            $this->assertStringContainsString("('o\\'bob','g2',", RecordingDb::$log[2]);
        }
    }
}
