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
        // the charset of the Trim inserter (Langs::isUTF())
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
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

        public function test_trim_inserter_trims_unicode_whitespace_and_composes_accents(): void
        {
            $this->store(
                [$this->field('headline........', ['input_insert_func' => 'trm:', 'multiple' => 1])],
                ['headline........' => new AA_Value([" \tICO 123\r\n", "\u{00A0}Praha\u{3000}", "Cafe\u{0301}", ' '])],
            );

            $this->assertContentTable([
                $this->row(self::ITEM, 'headline........', 0, 'ICO 123', 64),
                $this->row(self::ITEM, 'headline........', 1, 'Praha', 64),
                $this->row(self::ITEM, 'headline........', 2, "Caf\u{00E9}", 64),
                $this->row(self::ITEM, 'headline........', 3, '', 64),
            ]);
        }

        public function test_trim_inserter_keeps_text_that_is_not_valid_utf8(): void
        {
            $this->store(
                [$this->field('headline........', ['input_insert_func' => 'trm'])],
                ['headline........' => new AA_Value(" Br\xFCnn \n")],
            );

            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, "Br\xFCnn", 64)]);
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

        // ------------------------------------------------------------ seq - number on insert

        private function seqField(int $text_stored = 0, string $id = 'number..........'): AA_Field
        {
            return $this->field($id, ['input_insert_func' => 'seq', 'text_stored' => $text_stored]);
        }

        /** The value a seq field reaches _store_fields() with is stored as it is - number or text column. */
        public function test_seq_stores_the_value_it_gets_as_is(): void
        {
            $this->store([$this->seqField(0), $this->seqField(1, 'text............')], ['number..........' => '7', 'text............' => '8']);

            $this->assertContentTable([
                $this->row(self::ITEM, 'number..........', 7, null, 0),
                $this->row(self::ITEM, 'text............', 0, '8', 64),
            ]);
        }

        /** _store_fields() runs no inserter for a field the content does not have, so a number
         *  for an empty field must be in the content before it runs. */
        public function test_field_missing_in_the_content_reaches_no_inserter(): void
        {
            $this->store([$this->seqField(), $this->field('headline........')], ['headline........' => 'x']);

            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, 'x', 64)]);
        }

        /** Answers the store's look-ups: the short_id, the maximum of a seq field and - when
         *  $exists - the stored item, read from the `content` table as it is at that moment.
         *  @param array<string, ?string> $max field id => what MAX() returns (null = no number yet) */
        private function answerSeq(array $max, bool $exists = false): void
        {
            RecordingDb::$answer = static function (string $sql) use ($max, $exists): array {
                if (str_starts_with($sql, 'SELECT short_id')) {
                    return [['short_id' => '4711']];
                }
                if (str_starts_with($sql, 'SELECT MAX(')) {
                    foreach ($max as $fid => $value) {
                        if (str_contains($sql, "'$fid'")) {
                            return [['seq' => $value]];
                        }
                    }
                }
                if (str_starts_with($sql, 'SELECT count(*) as cnt FROM `item`')) {
                    return [['cnt' => $exists ? 1 : 0]];           // 'overwrite' asks before it writes the row (pdo_mysql gives an int)
                }
                if ($exists && str_starts_with($sql, 'SELECT * FROM item WHERE')) {
                    return [['id' => pack_id(self::ITEM), 'slice_id' => pack_id(self::SLICE)] + array_fill_keys(AA::Metabase()->getColumnNames('item'), '1')];
                }
                if ($exists && str_starts_with($sql, 'SELECT * FROM content WHERE')) {
                    return array_map(static fn(array $r) => ['item_id' => pack_id($r['item']), 'field_id' => $r['field'], 'number' => $r['number'], 'text' => $r['text'], 'flag' => $r['flag']], RecordingDb::$content);
                }
                return [];
            };
        }

        /** the statements of one kind sent so far */
        private function statements(string $start): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, $start)));
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_numbers_the_first_item_of_an_empty_slice_1(): void
        {
            $this->answerSeq(['number..........' => null]);
            $this->storeItem('insert', [$this->seqField(), $this->field('headline........')], ['headline........' => 'first']);

            $this->assertContentTable([
                $this->row(self::ITEM, 'headline........', 0, 'first', 64),
                $this->row(self::ITEM, 'number..........', 1, null, 0),
            ]);
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_takes_the_maximum_plus_one_into_the_number_or_the_text_column(): void
        {
            $this->answerSeq(['number..........' => '41', 'text............' => '9']);
            $this->storeItem('insert', [$this->seqField(0), $this->seqField(1, 'text............')], []);

            $this->assertContentTable([
                $this->row(self::ITEM, 'number..........', 42, null, 0),
                $this->row(self::ITEM, 'text............', 0, '10', 64),
            ]);
        }

        /** Uniqueness counts every item the slice has: no condition on the bin (status_code,
         *  publish/expiry date), and a text-stored value counts only when it is a whole number. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_maximum_covers_all_bins_and_skips_non_numeric_text(): void
        {
            $this->answerSeq(['number..........' => '5']);
            $this->storeItem('insert', [$this->seqField()], []);

            [$max] = $this->statements('SELECT MAX(');
            $this->assertStringContainsString('item.slice_id = 0x' . self::SLICE, $max);
            $this->assertStringContainsString("content.field_id = 'number..........'", $max);
            $this->assertStringContainsString('content.item_id = item.id', $max);
            $this->assertMatchesRegularExpression('/content\.flag & 64.*REGEXP \'\^\[0-9\]\{1,18\}\$\'.*content\.number/s', $max, 'text rows count only when numeric, number rows always');
            foreach (['status_code', 'publish_date', 'expiry_date'] as $bin) {
                $this->assertStringNotContainsString($bin, $max, 'no bin is left out');
            }
        }

        /** A copied number ({newitem} of a template, "Insert as new") would repeat the original's,
         *  so a value the new item brings is replaced as well. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_replaces_a_value_the_inserted_item_brings(): void
        {
            $this->answerSeq(['number..........' => '41']);
            $this->storeItem('insert', [$this->seqField()], ['number..........' => new AA_Value(['7', '8'], 0)]);

            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 42, null, 0)]);
        }

        /** A negative maximum does not make the next number 0 or less. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_starts_at_1_above_negative_numbers(): void
        {
            $this->answerSeq(['number..........' => '-3']);
            $this->storeItem('insert', [$this->seqField()], []);

            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 1, null, 0)]);
        }

        /** Two inserts must not read the same maximum: the slice row is locked first in the
         *  transaction storeItem() opens, so the plain read after it already sees the last commit. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_reads_the_maximum_under_the_slice_lock_inside_the_transaction(): void
        {
            $this->answerSeq(['number..........' => '1', 'text............' => '1']);
            $this->storeItem('insert', [$this->seqField(0), $this->seqField(1, 'text............')], []);

            $body = $this->transactionBody();
            $this->assertSame('SELECT id FROM `slice` WHERE id = 0x' . self::SLICE . ' FOR UPDATE', $body[0], 'the lock first');
            $this->assertCount(1, $this->statements('SELECT id FROM `slice`'), 'one lock for all seq fields');
            $this->assertStringStartsWith('SELECT MAX(', $body[1]);
            $this->assertStringNotContainsString('LOCK IN SHARE MODE', $body[1], 'no row locks over the whole slice');
            $this->assertStringStartsWith('SELECT MAX(', $body[2]);
            $this->assertStringStartsWith('INSERT INTO `item`', $body[3], 'before the item row');
        }

        /** Joined to an outer transaction, whose snapshot may predate the lock, the read must lock. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_reads_the_maximum_with_a_locking_read_inside_an_outer_transaction(): void
        {
            $this->answerSeq(['number..........' => '41']);
            \AA\IO\DB\DB_AA::transaction(fn() => $this->storeItem('insert', [$this->seqField()], []));

            $body = $this->transactionBody();
            $this->assertStringEndsWith(' FOR UPDATE', $body[0], 'the lock first');
            $this->assertStringStartsWith('SELECT MAX(', $body[1]);
            $this->assertStringEndsWith(' LOCK IN SHARE MODE', $body[1]);
            $this->assertSame(1, RecordingDb::count('BEGIN'));
            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 42, null, 0)]);
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_slice_without_seq_field_takes_no_lock_and_runs_no_extra_sql(): void
        {
            $this->answerSeq([]);
            $this->storeItem('insert', [$this->field('headline........')], ['headline........' => 'x']);

            $this->assertSame('BEGIN', RecordingDb::$log[0], 'nothing before the transaction');
            $this->assertSame([], $this->statements('SELECT id FROM `slice`'));
            $this->assertSame([], $this->statements('SELECT MAX('));
        }

        /** 'overwrite' and 'update/insert' of an id not in the database create the item - it is new. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_numbers_an_item_overwrite_or_update_insert_creates(): void
        {
            foreach (['overwrite', 'update/insert'] as $mode) {
                RecordingDb::reset();
                $this->answerSeq(['number..........' => '41']);
                $this->storeItem($mode, [$this->seqField()], ['number..........' => '7']);

                $this->assertContentTable([$this->row(self::ITEM, 'number..........', 42, null, 0)], $mode);
            }
        }

        /** An existing item keeps its number: an empty value (cleared form field), a field missing in
         *  'overwrite' (which deletes all rows) and a feed's value (the source's number) do not replace it. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_keeps_the_number_of_an_existing_item(): void
        {
            $cases = [
                'update, cleared'   => ['update', ['number..........' => ''], ''],
                'overwrite, absent' => ['overwrite', ['headline........' => 'x'], ''],
                'update from feed'  => ['update', ['number..........' => '99'], 'feed'],
            ];
            foreach ($cases as $case => [$mode, $content, $context]) {
                RecordingDb::reset();
                RecordingDb::$content = [$this->row(self::ITEM, 'number..........', 3, null, 0)];
                $this->answerSeq(['number..........' => '41'], true);
                self::$slice->setFields(new FakeFields(self::SLICE, [$this->seqField(), $this->field('headline........')]));
                $ic = new ItemContent();
                $ic->setSliceID(self::SLICE);
                $ic->setItemID(self::ITEM);
                foreach ($content as $fid => $value) {
                    $ic->setAaValue($fid, new AA_Value($value, 0));
                }
                $ic->storeItem($mode, [false, false, false], $context);

                $this->assertSame([], $this->statements('SELECT MAX('), $case);
                $numbers = array_values(array_filter(RecordingDb::$content, static fn($r) => $r['field'] === 'number..........'));
                $this->assertSame([$this->row(self::ITEM, 'number..........', 3, null, 0)], $numbers, $case);
            }
        }

        /** A feed must not bring the source's number - an item without one gets the next number. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_numbers_a_fed_item_that_has_no_number_yet(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'headline........', 0, 'x', 64)];
            $this->answerSeq(['number..........' => '41'], true);
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->seqField(), $this->field('headline........')]));
            $ic = new ItemContent();
            $ic->setSliceID(self::SLICE);
            $ic->setItemID(self::ITEM);
            $ic->setAaValue('number..........', new AA_Value('99', 0));
            $ic->storeItem('update', [false, false, false], 'feed');

            $this->assertStringEndsWith(' FOR UPDATE', $this->transactionBody()[0]);
            $this->assertContentTable([$this->row(self::ITEM, 'headline........', 0, 'x', 64), $this->row(self::ITEM, 'number..........', 42, null, 0)]);
        }

        /** A value typed into the edit form, or a field an update does not bring, is not touched. */
        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the full legacy storeItem() trips notices on unset keys
        public function test_seq_update_stores_a_given_number_and_leaves_a_missing_field_alone(): void
        {
            RecordingDb::$content = [$this->row(self::ITEM, 'number..........', 3, null, 0)];
            $this->answerSeq(['number..........' => '41'], true);
            $this->storeItem('update', [$this->seqField(), $this->field('headline........')], ['number..........' => '5']);
            $before = array_slice(RecordingDb::$log, 0, array_search('BEGIN', RecordingDb::$log, true));
            $this->assertCount(1, array_filter($before, static fn($s) => str_starts_with($s, 'SELECT * FROM item')), 'only the old content storeItem() reads anyway - the number needs no stored item');
            $this->assertSame([], $this->statements('SELECT id FROM `slice`'));
            $this->assertSame([], $this->statements('SELECT MAX('));
            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 5, null, 0)]);

            RecordingDb::reset();
            RecordingDb::$content = [$this->row(self::ITEM, 'number..........', 3, null, 0)];
            $this->answerSeq([], true);
            $this->storeItem('update', [$this->seqField(), $this->field('headline........')], ['headline........' => 'x']);
            $this->assertContentTable([$this->row(self::ITEM, 'number..........', 3, null, 0), $this->row(self::ITEM, 'headline........', 0, 'x', 64)]);
        }

        public function test_seq_field_may_be_left_empty_in_the_form(): void
        {
            $this->assertTrue(AA_Inserter::factoryByField($this->seqField())->isContentSelfGenerated());
        }
    }
}
