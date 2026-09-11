<?php

declare(strict_types=1);

// The inserter chain lives in include/itemfunc.php, whose require chain (item_content →
// locsess) needs the constants include/config.php normally provides. Safe test values,
// defined before the autoloader resolves the first legacy class.

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

    use AA;
    use AA_Field;
    use AA_Field_Writer;
    use AA_Inserter;
    use AA_Value;
    use Cvarset;
    use ItemContent;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeAuth;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Characterization of what one item store does to the `content` table:
     * ItemContent::_store_fields() → AA_Inserter::executeMulti() → execute() →
     * StoreToContent() (include/item_content.php, include/itemfunc.php, include/util.php).
     *
     * Written against the statement-per-value code and kept as the contract for the
     * batched AA_Field_Writer (T3): fewer statements, but the table must end up the
     * same - rows, values, order - for every input, including the inserters that read
     * the table back in the middle of a store (mutual relations, 2FA). The fake DB
     * therefore keeps an in-memory `content` table, applies every statement to it and
     * answers the look-ups from it (fixtures/store_fixtures.php); the tests compare
     * that end state, and count statements where the count is the point.
     */
    final class ItemStoreFieldsTest extends TestCase
    {
        private const SLICE = '11111111111111111111111111111111';
        private const ITEM  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        private const USER  = 'cccccccccccccccccccccccccccccccc';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            // AA_Field_Writer::getSlice() memoizes the first slice it sees in a static
            // local for the rest of the process - so there is exactly one fake slice,
            // and each test swaps its fields.
            self::$slice = new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            // ONE handle on purpose: a store must never hold one while asking for another
            // (getDB() on an empty pool throws here) - the condition for wrapping it in a
            // transaction later
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['err']      = [];
            self::$slice->deleted = false;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AA::$perm            = null;
            unset($GLOBALS['auth']);
        }

        // ------------------------------------------------------------ helpers

        private function field(string $id, array $data = []): AA_Field
        {
            return new AA_Field($data + [
                'id'                => $id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => 'qte',
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]);
        }

        /** Runs the real _store_fields() over the given fields and content. */
        private function store(array $fields, array $content, string $item_id = self::ITEM): Cvarset
        {
            self::$slice->setFields($fake_fields = new FakeFields(self::SLICE, $fields));
            $ic = new ItemContent();
            // as setByItemID() leaves them (AA_Value, so the iteration has a flag to read)
            $ic->setAaValue('slice_id........', new AA_Value(pack_id(self::SLICE), 0));
            $ic->setAaValue('u_slice_id......', new AA_Value(self::SLICE, 0));
            foreach ($content as $fid => $value) {
                $ic->setAaValue($fid, $value instanceof AA_Value ? $value : new AA_Value($value, 0));
            }
            return $ic->_store_fields($item_id, $fake_fields);
        }

        /** one row of the `content` table as RecordingDb keeps it */
        private function row(string $item, string $field, ?int $number, ?string $text, int $flag): array
        {
            return ['item' => $item, 'field' => $field, 'number' => $number, 'text' => $text, 'flag' => $flag];
        }

        private function assertContentTable(array $rows, string $message = ''): void
        {
            $this->assertSame($rows, RecordingDb::$content, $message);
        }

        private function assertStatements(int $inserts, int $deletes, int $selects = 0): void
        {
            $this->assertSame(
                ['INSERT' => $inserts, 'DELETE' => $deletes, 'SELECT' => $selects],
                ['INSERT' => RecordingDb::count('INSERT'), 'DELETE' => RecordingDb::count('DELETE'), 'SELECT' => RecordingDb::count('SELECT')],
                "statements sent:\n" . implode("\n", RecordingDb::$log),
            );
        }

        // ------------------------------------------------------------ plain rows

        public function test_text_field_gets_text_stored_flag_added_and_is_escaped(): void
        {
            $this->store([$this->field('headline........')], ['headline........' => new AA_Value("Hel'lo", FLAG_HTML)]);

            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, "Hel'lo", FLAG_HTML | FLAG_TEXT_STORED)]);
            $this->assertStringContainsString("'Hel\\'lo'", RecordingDb::$log[0], 'escaped on the way out');
            $this->assertStatements(1, 0);
        }

        public function test_number_field_stores_number_column_and_clears_text_stored_flag(): void
        {
            $this->store(
                [$this->field('number..........', ['text_stored' => 0, 'input_insert_func' => 'num'])],
                ['number..........' => new AA_Value('42', FLAG_TEXT_STORED | FLAG_FREEZE)],
            );

            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 42, null, FLAG_FREEZE)]);
        }

        public function test_multivalue_is_numbered_by_position(): void
        {
            // AA_Value renumbers whatever keys it is given, so `number` is the position
            $this->store(
                [$this->field('category........', ['multiple' => 1])],
                ['category........' => new AA_Value([0 => 'a', 1 => 'b', 5 => 'c', AA_Value::MAX_INDEX + 1 => 'd'])],
            );

            $this->assertContentTable([
                $this->row(self::ITEM, 'category........', 0, 'a', 64),
                $this->row(self::ITEM, 'category........', 1, 'b', 64),
                $this->row(self::ITEM, 'category........', 2, 'c', 64),
                $this->row(self::ITEM, 'category........', 3, 'd', 64),
            ]);
            $this->assertStatements(1, 0, 0);   // one multi-row INSERT
        }

        public function test_boolean_inserter_normalizes_to_1_or_0(): void
        {
            $this->store(
                [$this->field('yes_no..........', ['text_stored' => 0, 'input_insert_func' => 'boo'])],
                ['yes_no..........' => new AA_Value(['on', ''])],
            );

            $this->assertContentTable([
                $this->row(self::ITEM, 'yes_no..........', 1, null, 0),
                $this->row(self::ITEM, 'yes_no..........', 0, null, 0),
            ]);
        }

        public function test_fields_are_written_in_content_order(): void
        {
            $this->store(
                [$this->field('headline........'), $this->field('full_text.......')],
                ['full_text.......' => 'body', 'headline........' => 'title'],
            );

            $this->assertContentTable([
                $this->row(self::ITEM, 'full_text.......', 0, 'body', 64),
                $this->row(self::ITEM, 'headline........', 0, 'title', 64),
            ]);
            $this->assertStatements(1, 0, 0);   // all fields of the store in one INSERT
        }

        // ------------------------------------------------------------ nothing written

        public function test_field_not_in_slice_is_skipped(): void
        {
            $this->store([$this->field('headline........')], ['unknown.........' => 'x']);

            $this->assertSame([], RecordingDb::$log);
        }

        public function test_nul_inserter_writes_nothing(): void
        {
            $this->store([$this->field('headline........', ['input_insert_func' => 'nul'])], ['headline........' => 'x']);

            $this->assertSame([], RecordingDb::$log);
        }

        public function test_item_table_field_goes_to_the_varset_not_to_content(): void
        {
            $midnight = mktime(0, 0, 0, 6, 15, 2026);
            $varset   = $this->store(
                [
                    $this->field('publish_date....', ['in_item_tbl' => 'publish_date', 'input_insert_func' => 'dte']),
                    $this->field('expiry_date.....', ['in_item_tbl' => 'expiry_date', 'input_insert_func' => 'dte']),
                    $this->field('edited_by.......', ['in_item_tbl' => 'edited_by']),
                ],
                ['publish_date....' => (string) $midnight, 'expiry_date.....' => (string) $midnight, 'edited_by.......' => 'honza'],
            );

            $this->assertSame([], RecordingDb::$log, 'item-table fields never touch content');
            $this->assertSame((string) $midnight, $varset->get('publish_date'));
            $this->assertSame((string) ($midnight + 86399), $varset->get('expiry_date'), 'expiry at midnight means end of that day');
            $this->assertSame('honza', $varset->get('edited_by'));
        }

        public function test_invalid_item_id_writes_nothing(): void
        {
            $this->store([$this->field('headline........')], ['headline........' => 'x'], 'not-a-long-id');

            $this->assertSame([], RecordingDb::$log);
        }

        /** A soft-deleted slice is still editable (its trash is reachable in the admin,
         *  the slice can be undeleted) - the inserters must not consult isValid(): when
         *  they did, they skipped every field and the store wiped the item's content. */
        public function test_soft_deleted_slice_stores_like_a_live_one(): void
        {
            self::$slice->deleted = true;
            $this->store([$this->field('headline........')], ['headline........' => 'x']);

            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, 'x', 64)]);
            $this->assertStatements(1, 0);
        }

        // ------------------------------------------------------------ safe-stored fields

        /** Safe-stored fields are not cleared up front (AA_Field_Cleaner skips them), so
         *  the inserter deletes right before writing - for EVERY value, which is why a
         *  multivalue safe-stored field ends up holding only its last value. */
        public function test_safe_stored_field_is_cleared_before_each_value_so_only_the_last_survives(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'secret..........', 0, 'old', 64)];
            $this->store([$this->field('secret..........')], ['secret..........' => new AA_Value(['first', 'second'])]);

            $this->assertContentTable([$this->row(self::ITEM, 'secret..........', 1, 'second', 64)]);
            $this->assertStatements(1, 1, 0);   // the clear drops 'first' from the queue before it is ever sent
        }

        public function test_safe_stored_field_of_another_item_is_left_alone(): void
        {
            RecordingDb::$content = [$this->row(self::OTHER, 'secret..........', 0, 'theirs', 64)];
            $this->store([$this->field('secret..........')], ['secret..........' => 'mine']);

            $this->assertContentTable([
                $this->row(self::OTHER, 'secret..........', 0, 'theirs', 64),
                $this->row(self::ITEM, 'secret..........', 0, 'mine', 64),
            ]);
        }

        // ------------------------------------------------------------ add2constant (select with presets)

        /** A `pre` (select with presets) field of $group with add2constant on - the 8th input_show_func param. */
        private function presetField(string $id, string $group, array $extra = []): AA_Field
        {
            return $this->field($id, $extra + ['input_show_func' => "pre:$group:::::::1"]);
        }

        public function test_add2constant_looks_the_value_up_and_inserts_a_new_constant_first(): void
        {
            // an aggregate always returns a row - for an empty group it holds NULL
            RecordingDb::$answer = static fn(string $sql) => str_starts_with($sql, 'SELECT max(pri)') ? [['max_pri' => null]] : [];
            $this->store(
                [$this->presetField('category........', 'mygroup')],
                ['category........' => 'Fresh'],
            );

            $this->assertSame("SELECT value FROM `constant` WHERE group_id = 'mygroup' AND value = 'Fresh'", RecordingDb::$log[0]);
            $this->assertSame("SELECT max(pri) as max_pri FROM `constant` WHERE group_id = 'mygroup' LIMIT  1", RecordingDb::$log[1]);
            $this->assertMatchesRegularExpression(
                "/^INSERT INTO `constant` \(`id`,`group_id`,`name`,`value`,`pri`\) VALUES \(0x[0-9a-f]{32},'mygroup','Fresh','Fresh',10\)$/",
                RecordingDb::$log[2],
                'first constant of an empty group gets NULL + 10 = 10, as before',
            );
            $this->assertContentTable([$this->row(self::ITEM, 'category........', 0, 'Fresh', 64)]);
            $this->assertStatements(2, 0, 2);

            // no handle is held across the look-ups any more - everything runs on one connection
            $this->assertCount(1, array_unique(RecordingDb::$handles), 'one handle for the whole store');
        }

        public function test_add2constant_continues_the_priority_sequence_of_the_group(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_starts_with($sql, 'SELECT max(pri)') ? [['max_pri' => '1030']] : [];
            $this->store(
                [$this->presetField('category........', 'mygroup', ['multiple' => 1])],
                ['category........' => new AA_Value(['One', 'Two', 'One'])],
            );

            $inserts = array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'INSERT INTO `constant`')));
            $this->assertCount(2, $inserts, 'the same value asked for twice becomes one constant');
            $this->assertStringEndsWith("'mygroup','One','One',1040)", $inserts[0]);
            $this->assertStringEndsWith("'mygroup','Two','Two',1050)", $inserts[1]);
            $this->assertStatements(3, 0, 2);
        }

        public function test_add2constant_skips_the_insert_when_the_constant_exists(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, "value = 'Known'") ? [['value' => 'Known']] : [];
            $this->store(
                [$this->presetField('category........', 'mygroup')],
                ['category........' => 'Known'],
            );

            $this->assertSame("SELECT value FROM `constant` WHERE group_id = 'mygroup' AND value = 'Known'", RecordingDb::$log[0]);
            $this->assertContentTable([$this->row(self::ITEM, 'category........', 0, 'Known', 64)]);
            $this->assertStatements(1, 0, 1);
        }

        public function test_add2constant_is_ignored_for_slice_groups_and_empty_values(): void
        {
            $this->store(
                [
                    $this->presetField('category........', '#sLiCe-' . self::OTHER),
                    $this->presetField('category.......2', 'mygroup'),
                ],
                ['category........' => 'x', 'category.......2' => '  '],
            );

            $this->assertContentTable([
                $this->row(self::ITEM, 'category........', 0, 'x', 64),
                $this->row(self::ITEM, 'category.......2', 0, '  ', 64),
            ]);
            $this->assertStatements(1, 0, 0);
        }

        // ------------------------------------------------------------ relations (ids inserter)

        private function relationField(int $text_stored = 1): AA_Field
        {
            return $this->field('relation........', ['input_insert_func' => 'ids', 'text_stored' => $text_stored, 'multiple' => 1]);
        }

        public function test_plain_and_x_prefixed_ids_are_stored_as_given(): void
        {
            $this->store([$this->relationField()], ['relation........' => new AA_Value([self::OTHER, 'x' . self::OTHER, 't' . self::OTHER])]);

            $this->assertContentTable([
                $this->row(self::ITEM, 'relation........', 0, self::OTHER, 64),
                $this->row(self::ITEM, 'relation........', 1, self::OTHER, 64),
                $this->row(self::ITEM, 'relation........', 2, self::OTHER, 64),
            ]);
        }

        /** 'y' = mutual: write our side, then look at the OTHER item and add the backward row if missing. */
        public function test_mutual_relation_writes_both_sides_and_drops_the_other_items_empty_placeholder(): void
        {
            RecordingDb::$content = [$this->row(self::OTHER, 'relation........', 0, '', 64)];
            $this->store([$this->relationField()], ['relation........' => 'y' . self::OTHER]);

            $this->assertContentTable([
                $this->row(self::ITEM, 'relation........', 0, self::OTHER, 64),
                $this->row(self::OTHER, 'relation........', 0, self::ITEM, 64),
            ]);
            $this->assertStatements(2, 1, 1);
            $log = RecordingDb::$log;
            $this->assertSame(
                'SELECT item_id FROM "content" WHERE item_id = 0x' . self::OTHER
                    . " AND field_id = 'relation........' AND text = '" . self::ITEM . "' LIMIT  1",
                $log[count($log) - 2],
                'the backward check comes right before the backward insert',
            );
            $this->assertStringStartsWith('INSERT INTO `content`', $log[0], "the item's own rows go first, the other item's side last");
        }

        /** The same backward row asked for twice in one store (mutual + backward to one target)
         *  must still give the other item one row - the check ran between the two writes before. */
        public function test_backward_row_asked_for_twice_is_written_once(): void
        {
            $this->store([$this->relationField()], ['relation........' => new AA_Value(['y' . self::OTHER, 'z' . self::OTHER])]);

            $this->assertContentTable([
                $this->row(self::ITEM, 'relation........', 0, self::OTHER, 64),
                $this->row(self::OTHER, 'relation........', 0, self::ITEM, 64),
            ]);
        }

        public function test_mutual_relation_does_not_duplicate_an_existing_backward_row(): void
        {
            RecordingDb::$content = [$this->row(self::OTHER, 'relation........', 0, self::ITEM, 64)];
            $this->store([$this->relationField()], ['relation........' => 'y' . self::OTHER]);

            $this->assertContentTable([
                $this->row(self::OTHER, 'relation........', 0, self::ITEM, 64),
                $this->row(self::ITEM, 'relation........', 0, self::OTHER, 64),
            ]);
            $this->assertStatements(1, 1, 1);
        }

        /** 'z' = backward only: nothing on our side, only the other item's row. */
        public function test_backward_relation_writes_only_the_other_item(): void
        {
            $this->store([$this->relationField(0)], ['relation........' => 'z' . self::OTHER]);

            // number-stored relation: no empty-text cleanup, and the id is cast to int on insert
            $this->assertContentTable([$this->row(self::OTHER, 'relation........', 0, null, 0)]);
            $this->assertStatements(1, 0, 1);
        }

        /**
         * 't' + text = "new tag" (the Tags widget offers the typed text as a new option):
         * AA_Inserter_Ids stores a NEW item in the tag slice right away (a nested storeItem,
         * not deferred) and relates to its id - in use on live installs. Pinned here on its
         * failure path: the tag slice is unknown to the fake DB, so the nested store gives
         * up - and then NO relation may be written (it used to point at the never-created
         * item) and the field reports the error.
         */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the aborted nested store trips legacy notices
        public function test_new_tag_is_stored_as_a_nested_item_before_the_relation(): void
        {
            $tag_slice = 'dddddddddddddddddddddddddddddddd';
            $this->loginAs(self::USER);
            $this->store(
                [$this->field('relation........', ['input_insert_func' => 'ids', 'input_show_func' => "tag:#sLiCe-$tag_slice:", 'multiple' => 1])],
                ['relation........' => 'tFresh <b>tag</b>'],
            );

            $nested = array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT') && str_contains($s, $tag_slice));
            $writes = array_filter(RecordingDb::$log, static fn($s) => !str_starts_with($s, 'SELECT'));
            $this->assertNotEmpty($nested, 'the tag slice is looked up for the nested insert');
            $this->assertSame([], $writes, 'the failed nested store must not leave a relation behind');
            $this->assertSame([], RecordingDb::$content);
            $this->assertStringContainsString("'Fresh &lt;b&gt;tag&lt;/b&gt;'", $GLOBALS['err']['relation........'], 'the message is printed as HTML - the typed text must be escaped');
        }

        /**
         * The case batching must not break: a mutual relation to ITSELF. The backward
         * check reads the row this very store just wrote, so the DB has to already
         * contain it - otherwise a second, duplicate row appears.
         */
        public function test_mutual_self_relation_sees_its_own_freshly_written_row(): void
        {
            $this->store([$this->relationField()], ['relation........' => 'y' . self::ITEM]);

            $this->assertContentTable([$this->row(self::ITEM, 'relation........', 0, self::ITEM, 64)], 'exactly one row');
        }

        // ------------------------------------------------------------ password with backup field

        /**
         * The backup copy is written through executeMulti()'s change_fields: the backup
         * field is cleared and re-inserted AFTER the password row - even when the same
         * store already wrote that backup field earlier (here: it comes first in content
         * order). Batching must keep "later clear wins over earlier insert".
         */
        public function test_password_backup_field_is_cleared_and_rewritten_after_the_password(): void
        {
            $this->store(
                [
                    $this->field('password.......1'),
                    $this->field('password........', ['input_insert_func' => 'pwd:password.......1:']),
                ],
                ['password.......1' => 'stale-copy', 'password........' => 'AA_PASSWD:s3cret'],
            );

            $this->assertCount(2, RecordingDb::$content);
            [$pwd, $copy] = RecordingDb::$content;
            $this->assertSame([self::ITEM, 'password........', 0, 64], [$pwd['item'], $pwd['field'], $pwd['number'], $pwd['flag']]);
            $this->assertStringStartsWith('$2y$', $pwd['text'], 'the password itself is stored hashed');
            $this->assertSame($this->row(self::ITEM, 'password.......1', 0, 's3cret', 64), $copy, 'plaintext copy replaces the stale one - no encrypt phrase given');
            $this->assertStatements(1, 1, 0);   // the stale copy never leaves the queue
        }

        public function test_password_backup_is_encrypted_when_a_phrase_is_given(): void
        {
            $this->store(
                [
                    $this->field('password.......1'),
                    $this->field('password........', ['input_insert_func' => 'pwd:password.......1:Phrase']),
                ],
                ['password........' => 'AA_PASSWD:s3cret'],
            );

            $this->assertCount(2, RecordingDb::$content);
            $copy = RecordingDb::$content[1];
            $this->assertSame('password.......1', $copy['field']);
            $this->assertNotSame('s3cret', $copy['text']);
            $this->assertNotEmpty($copy['text']);
        }

        public function test_password_backup_is_skipped_when_the_backup_field_is_not_in_the_slice(): void
        {
            $this->store(
                [$this->field('password........', ['input_insert_func' => 'pwd:password.......1:'])],
                ['password........' => 'AA_PASSWD:s3cret'],
            );

            $this->assertCount(1, RecordingDb::$content);
            $this->assertStatements(1, 0);
        }

        public function test_already_crypted_password_is_stored_as_is(): void
        {
            $this->store(
                [$this->field('password........', ['input_insert_func' => 'pwd'])],
                ['password........' => 'AA_PASSWD_CRYPTED:$2y$10$hash'],
            );

            $this->assertContentTable([$this->row(self::ITEM, 'password........', 0, '$2y$10$hash', 64)]);
        }

        // ------------------------------------------------------------ 2FA secret

        private function loginAs(string $uid): void
        {
            $auth            = new FakeAuth($uid);
            AA::$perm        = $auth;
            $GLOBALS['auth'] = $auth;
        }

        public function test_2fa_stores_a_generated_secret_for_the_authenticated_user(): void
        {
            $this->loginAs(self::USER);
            RecordingDb::$content = [$this->row(self::ITEM, 'secret..........', 0, 'OLDSECRET', 64)];
            $this->store(
                [$this->field('secret..........', ['input_insert_func' => '2fa'])],
                ['secret..........' => 'AA_2FASECRET:honza:pwd:'],
            );

            $this->assertCount(1, RecordingDb::$content, 'safe-stored: the old secret is replaced');
            $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/', RecordingDb::$content[0]['text']);
            $this->assertStatements(1, 1);
        }

        public function test_2fa_writes_nothing_when_the_password_is_wrong(): void
        {
            $this->loginAs(self::USER);
            RecordingDb::$content = [$this->row(self::ITEM, 'secret..........', 0, 'OLDSECRET', 64)];
            $this->store(
                [$this->field('secret..........', ['input_insert_func' => '2fa'])],
                ['secret..........' => 'AA_2FASECRET:honza:wrong:'],
            );

            $this->assertSame([], RecordingDb::$log, 'safe-stored field keeps its old value');
            $this->assertContentTable([$this->row(self::ITEM, 'secret..........', 0, 'OLDSECRET', 64)]);
        }

        /** With an encrypt phrase the inserter reads the user's item back from the DB before writing. */
        public function test_2fa_with_phrase_reads_the_user_item_before_writing_the_secret(): void
        {
            $this->loginAs(self::USER);
            $this->store(
                [$this->field('secret..........', ['input_insert_func' => '2fa:Phrase'])],
                ['secret..........' => 'AA_2FASECRET:honza:pwd:'],
            );

            $reads  = array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT'));
            $writes = array_filter(RecordingDb::$log, static fn($s) => !str_starts_with($s, 'SELECT'));
            $this->assertNotEmpty($reads, 'the user item is looked up');
            $this->assertStringContainsString('0x' . self::USER, implode("\n", $reads));
            $this->assertLessThan(min(array_keys($writes)), max(array_keys($reads)), 'every read precedes the first write');
            $this->assertCount(1, RecordingDb::$content);
            $this->assertSame('secret..........', RecordingDb::$content[0]['field']);
            $this->assertDoesNotMatchRegularExpression('/^[A-Z2-7]{16}$/', RecordingDb::$content[0]['text'], 'stored encrypted, not as the bare secret');
        }

        // ------------------------------------------------------------ the whole storeItem()

        /** Runs the real storeItem() - no cache invalidation, feeding or events (the flags). */
        private function storeItem(string $mode, array $fields, array $content, string $item_id = self::ITEM): string|false
        {
            self::$slice->setFields(new FakeFields(self::SLICE, $fields));
            $ic = new ItemContent();
            $ic->setSliceID(self::SLICE);
            $ic->setItemID($item_id);
            foreach ($content as $fid => $value) {
                $ic->setAaValue($fid, $value instanceof AA_Value ? $value : new AA_Value($value, 0));
            }
            return $ic->storeItem($mode, [false, false, false]);
        }

        /** Every statement between BEGIN and COMMIT, in order - the transaction's body. */
        private function transactionBody(): array
        {
            $log   = RecordingDb::$log;
            $begin = array_search('BEGIN', $log, true);
            $end   = array_search('COMMIT', $log, true);
            $this->assertNotFalse($begin, 'a transaction starts');
            $this->assertNotFalse($end, 'and commits');
            $this->assertSame(1, RecordingDb::count('BEGIN'), 'exactly one transaction per store');
            return array_slice($log, $begin + 1, $end - $begin - 1);
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_update_writes_old_rows_new_rows_and_item_in_one_transaction(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'headline........', 0, 'old title', 64)];
            $ret = $this->storeItem('update', [$this->field('headline........'), $this->field('full_text.......')], ['headline........' => 'new title', 'full_text.......' => 'body']);

            $this->assertSame(self::ITEM, $ret);
            $body = $this->transactionBody();
            $this->assertCount(3, $body, "the body:\n" . implode("\n", $body));
            $this->assertStringStartsWith('DELETE FROM `content`', $body[0], 'the old rows of the updated fields');
            $this->assertStringStartsWith('INSERT INTO `content`', $body[1], 'all new rows at once');
            $this->assertStringStartsWith('UPDATE `item`', $body[2]);
            $this->assertContentTable([
                $this->row(self::ITEM, 'headline........', 0, 'new title', 64),
                $this->row(self::ITEM, 'full_text.......', 0, 'body', 64),
            ]);
            $this->assertSame(0, RecordingDb::count('ROLLBACK'));
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_insert_creates_the_item_row_inside_the_same_transaction(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_starts_with($sql, 'SELECT short_id') ? [['short_id' => '4711']] : [];
            $ret = $this->storeItem('insert', [$this->field('headline........')], ['headline........' => 'brand new']);

            $this->assertSame(self::ITEM, $ret);
            $body = $this->transactionBody();
            $this->assertCount(4, $body, "the body:\n" . implode("\n", $body));
            $this->assertStringStartsWith('INSERT INTO `item`', $body[0], 'the skeleton row first, to get a short_id');
            $this->assertStringStartsWith('SELECT short_id', $body[1]);
            $this->assertStringStartsWith('INSERT INTO `content`', $body[2]);
            $this->assertStringStartsWith('UPDATE `item`', $body[3]);
            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, 'brand new', 64)]);
        }

        /** An update is all or nothing: the old rows go first, so a write that fails after
         *  them must roll the whole store back - the item keeps its old content. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_update_rolls_back_the_deleted_old_rows_when_a_later_write_fails(): void
        {
            $old = [$this->row(self::ITEM, 'headline........', 0, 'old title', 64), $this->row(self::ITEM, 'full_text.......', 0, 'old body', 64)];
            RecordingDb::$content = $old;
            RecordingDb::$fail_on = 'INSERT INTO `content`';

            try {
                $this->storeItem('update', [$this->field('headline........'), $this->field('full_text.......')], ['headline........' => 'new title', 'full_text.......' => 'body']);
                $this->fail('the failed INSERT was swallowed');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('simulated failure', $e->getMessage());
            }
            $this->assertContentTable($old, 'the old rows survive');
            $this->assertSame(1, RecordingDb::count('ROLLBACK'));
            $this->assertSame(0, RecordingDb::count('COMMIT'));
        }

        /** The case that bit us on a live install: an item in the trash of a soft-deleted
         *  slice, saved from itemedit - it must store just like in a live slice. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_update_in_soft_deleted_slice_stores_the_new_rows(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'headline........', 0, 'old title', 64)];
            self::$slice->deleted = true;
            $ret = $this->storeItem('update', [$this->field('headline........')], ['headline........' => 'new title']);

            $this->assertSame(self::ITEM, $ret);
            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, 'new title', 64)]);
            $this->assertSame(0, RecordingDb::count('ROLLBACK'));
        }

        /** What comes after the commit reads the item back and must not open a second transaction. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_nothing_after_the_commit_writes_content(): void
        {
            $this->storeItem('update', [$this->field('headline........')], ['headline........' => 'x']);

            $after = array_slice(RecordingDb::$log, array_search('COMMIT', RecordingDb::$log, true) + 1);
            $this->assertNotEmpty($after, 'the stored item is read back');
            $this->assertSame([], array_filter($after, static fn($s) => !str_starts_with($s, 'SELECT')), 'only reads after the commit');
        }

        // ------------------------------------------------------------ low-level entry point

        /** StoreToContent() is also called directly (src/Later/HitcounterStats.php) with $delete = true. */
        public function test_store_to_content_with_delete_flag_replaces_the_row(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'hit_counter.....', 3, null, 0)];
            \StoreToContent(self::ITEM, 'hit_counter.....', false, 17, 0, 0, true);

            $this->assertContentTable([$this->row(self::ITEM, 'hit_counter.....', 17, null, 0)]);
            $this->assertStatements(1, 1);
        }

        public function test_store_to_content_rejects_bad_ids_silently(): void
        {
            \StoreToContent('short', 'hit_counter.....', false, 17, 0, 0);
            \StoreToContent(self::ITEM, 'too-short', false, 17, 0, 0);

            $this->assertSame([], RecordingDb::$log);
        }

        /** executeMulti() + flush(), as updateComputedFields() drives it (qte over a computed value). */
        public function test_execute_multi_queues_until_the_caller_flushes(): void
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field('seo.............')]));
            $writer = new AA_Field_Writer(self::SLICE);
            AA_Inserter::factoryByString('qte')->setField($this->field('seo.............'))
                ->executeMulti($writer, self::ITEM, new AA_Value(['one', 'two'], FLAG_HTML));

            $this->assertSame([], RecordingDb::$log, 'nothing is sent before flush()');
            $writer->flush();

            $this->assertContentTable([
                $this->row(self::ITEM, 'seo.............', 0, 'one', 65),
                $this->row(self::ITEM, 'seo.............', 1, 'two', 65),
            ]);
            $this->assertTrue($writer->getItemVarset()->isEmpty());
        }
    }
}
