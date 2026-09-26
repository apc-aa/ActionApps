<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA;
    use AA\IO\Grabber\Form;
    use AA\Optimize\CheckHtmlFlagRefusals;
    use AA\Util\LogOnce;
    use AA_Field;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeAuth;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** the Form grabber reading the stored items from a map instead of the database */
    final class StoredItemsForm extends Form
    {
        /** @var array<string, ItemContent> item id => the item as stored */
        public static array $stored = [];

        protected function storedItem(string $item_id): ItemContent
        {
            return self::$stored[$item_id] ?? new ItemContent();
        }
    }

    /** a permission system which must not be asked - anonymous input is decided without it */
    final class UnaskedPerm
    {
        public function cache($user_id): void
        {
            throw new \LogicException('permissions looked up for an anonymous visitor');
        }
    }

    /**
     * The aa[...] input (filler.php, {editable} / {ajax} on site pages, itemedit.php) takes the HTML flag
     * of a value from the request only for fields with the HTML/plain switch or the rich-text widget, or
     * from editors of the slice; anyone else gets the field default, so a visitor can not store HTML into
     * a plain-text field the aliases print raw.
     */
    final class FormHtmlFlagTest extends TestCase
    {
        private const SLICE = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
        private const ITEM  = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';
        private const USER  = 'c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2';
        private const OTHER_USER = 'd2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2';

        private const NEW  = 'n1_' . self::SLICE;
        private const HEAD = 'headline........';
        private const BODY = 'full_text.......';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            // the slice of the edited item (AA_Slice::getSliceIdFromItemId())
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM item') ? [['usid' => self::SLICE]] : [];
            StoredItemsForm::$stored = [];
            AA::$perm = new UnaskedPerm();
            unset($GLOBALS['auth'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            self::fields([self::HEAD => [], self::BODY => ['html_default' => 1]]);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_POST, $_REQUEST] = [[], []];
            AA::$perm = null;
            unset($GLOBALS['auth'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }

        // ------------------------------------------------------------ helpers

        /** @param array<string, array<string, mixed>> $fields field id => properties over a plain-text field */
        private static function fields(array $fields): void
        {
            $list = [];
            foreach ($fields as $id => $data) {
                $list[] = new AA_Field($data + [
                    'id'                => $id,
                    'text_stored'       => 1,
                    'in_item_tbl'       => '',
                    'input_show_func'   => 'fld',
                    'input_insert_func' => 'qte',
                    'input_default'     => '',
                    'html_show'         => 0,
                    'html_default'      => 0,
                    'multiple'          => 0,
                ]);
            }
            self::$slice->setFields(new FakeFields(self::SLICE, $list));
        }

        /** a logged-in user with the given permission letters in the slice */
        private static function loginAs(string $uid, string $slice_perms): void
        {
            $GLOBALS['auth']           = new FakeAuth($uid);
            $GLOBALS['permission_uid'] = $uid;
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => $slice_perms]];
        }

        /** the item as stored: headline with $head_flag, posted by $posted_by */
        private static function stored(int $head_flag, string $posted_by = self::OTHER_USER, bool $with_headline = true): void
        {
            $item = new ItemContent();
            $item->setItemID(self::ITEM);
            $item->setAaValue('u_slice_id......', new AA_Value(self::SLICE, 0));
            $item->setAaValue('posted_by.......', new AA_Value($posted_by, 0));
            if ($with_headline) {
                $item->setAaValue(self::HEAD, new AA_Value('stored', $head_flag));
            }
            StoredItemsForm::$stored[self::ITEM] = $item;
        }

        /**
         * Posts $aa through the grabber.
         * @return list<ItemContent> the grabbed items
         */
        private static function grab(array $aa): array
        {
            $_POST['aa'] = $aa;
            $form = new StoredItemsForm();
            $form->prepare();
            $items = [];
            while ($item = $form->getItem()) {
                $items[] = $item;
            }
            return $items;
        }

        /** the flag the only grabbed item has in $field */
        private static function flagOf(array $aa, string $field = self::HEAD): int
        {
            $items = self::grab($aa);
            self::assertCount(1, $items);
            return $items[0]->getAaValue($field)->getFlag();
        }

        /** @return list<array<string, mixed>> the refusals written to the log */
        private static function logged(): array
        {
            return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckHtmlFlagRefusals::EVENT));
        }

        // ------------------------------------------------------------ new items

        public function test_a_visitor_can_not_ask_for_html_in_a_plain_text_field(): void
        {
            $items = self::grab([self::NEW => ['headline________' => ['<script>x</script>', 'flag' => '1']]]);

            $value = $items[0]->getAaValue(self::HEAD);
            $this->assertSame(0, $value->getFlag(), 'stored as plain text');
            $this->assertSame(['<script>x</script>'], $value->getValues(), 'the text itself is kept');
        }

        public function test_a_visitor_gets_the_field_default_flag(): void
        {
            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['full_text_______' => ['<p>x</p>', 'flag' => '0']]], self::BODY), 'html_default=1 wins over the posted plain');
            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['full_text_______' => ['<p>x</p>']]], self::BODY), 'no flag posted');
        }

        public function test_a_field_with_the_html_switch_takes_the_posted_flag(): void
        {
            self::fields([self::HEAD => ['html_show' => 1], self::BODY => ['html_show' => 1, 'html_default' => 1]]);

            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
            $this->assertSame(0, self::flagOf([self::NEW => ['full_text_______' => ['x', 'flag' => '0']]], self::BODY));
            $this->assertSame([], self::logged());
        }

        public function test_an_editor_of_the_slice_may_ask_for_html(): void
        {
            self::loginAs(self::USER, PS_EDIT_ALL_ITEMS);

            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
            $this->assertSame([], self::logged());
        }

        public function test_an_editor_of_own_items_may_ask_for_html_in_a_new_item(): void
        {
            self::loginAs(self::USER, PS_EDIT_SELF_ITEMS);

            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        public function test_a_logged_in_user_without_edit_rights_is_a_visitor(): void
        {
            self::loginAs(self::USER, PS_FULLTEXT);

            $this->assertSame(0, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        public function test_an_editor_of_another_slice_is_a_visitor(): void
        {
            self::loginAs(self::USER, '');
            $GLOBALS['permission_to']['slice']['a9a9a9a9a9a9a9a9a9a9a9a9a9a9a9a9'] = PS_EDIT_ALL_ITEMS;

            $this->assertSame(0, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        // ------------------------------------------------------------ updates

        public function test_an_update_by_a_visitor_gets_the_field_default(): void
        {
            self::stored(0);
            self::fields([self::HEAD => ['html_default' => 1]]);

            $this->assertSame(FLAG_HTML, self::flagOf(['u' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '0']]]), 'stored plain, default HTML');
        }

        public function test_an_update_by_a_visitor_does_not_keep_the_stored_html_flag(): void
        {
            // the HTML was set by an editor - the visitor's text must not inherit it
            self::stored(FLAG_HTML);

            $this->assertSame(0, self::flagOf(['u' . self::ITEM => ['headline________' => ['<script>x</script>', 'flag' => '1']]]));
            // AA's own forms post the stored formatter as a hidden [flag] - a resubmission, not a forgery
            $this->assertSame([], self::logged(), 'the posted flag is the stored one');
        }

        public function test_an_update_asking_for_html_over_a_plain_value_is_logged(): void
        {
            self::stored(0);

            $this->assertSame(0, self::flagOf(['u' . self::ITEM => ['headline________' => ['<script>x</script>', 'flag' => '1']]]));
            $this->assertSame([ParamImplode(['', 'update'])], array_column(self::logged(), 'params'));
        }

        public function test_an_update_by_a_visitor_asking_for_plain_is_not_logged(): void
        {
            self::stored(FLAG_HTML);

            $this->assertSame(0, self::flagOf(['u' . self::ITEM => ['headline________' => ['x', 'flag' => '0']]]));
            $this->assertSame([], self::logged());
        }

        public function test_an_added_value_gets_the_field_default(): void
        {
            self::stored(0);

            $this->assertSame(0, self::flagOf(['i' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        public function test_a_visitor_can_not_add_a_value_to_an_html_value(): void
        {
            // the rows of a field are shown with the flag of the first one - the added text would be HTML
            self::stored(FLAG_HTML);

            $items = self::grab(['i' . self::ITEM => ['headline________' => ['<script>x</script>', 'flag' => '0']]]);

            $this->assertCount(1, $items);
            $this->assertTrue($items[0]->getAaValue(self::HEAD)->isEmpty(), 'nothing added');
            $this->assertSame([ParamImplode(['', 'insert'])], array_column(self::logged(), 'params'));
        }

        public function test_a_visitor_can_add_a_value_to_an_empty_field(): void
        {
            self::stored(FLAG_HTML, with_headline: false);

            $this->assertSame(0, self::flagOf(['i' . self::ITEM => ['headline________' => ['x']]]));
            $this->assertSame([], self::logged());
        }

        public function test_an_editor_may_add_a_value_to_an_html_value(): void
        {
            self::stored(FLAG_HTML);
            self::loginAs(self::USER, PS_EDIT_ALL_ITEMS);

            $this->assertSame(FLAG_HTML, self::flagOf(['i' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        public function test_removing_values_keeps_the_stored_flag(): void
        {
            self::stored(FLAG_HTML);

            $items = self::grab(['r' . self::ITEM => ['headline________' => ['other', 'flag' => '0']]]);

            $value = $items[0]->getAaValue(self::HEAD);
            $this->assertSame(['stored'], $value->getValues());
            $this->assertSame(FLAG_HTML, $value->getFlag());
        }

        public function test_an_update_of_an_empty_field_gets_the_field_default(): void
        {
            self::stored(0, with_headline: false);
            self::fields([self::HEAD => ['html_default' => 1]]);

            $this->assertSame(FLAG_HTML, self::flagOf(['u' . self::ITEM => ['headline________' => ['x', 'flag' => '0']]]));
        }

        public function test_an_editor_of_all_items_may_switch_a_stored_value_to_html(): void
        {
            self::stored(0);
            self::loginAs(self::USER, PS_EDIT_ALL_ITEMS);

            $this->assertSame(FLAG_HTML, self::flagOf(['u' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        public function test_an_editor_of_own_items_may_ask_for_html_only_in_his_items(): void
        {
            self::loginAs(self::USER, PS_EDIT_SELF_ITEMS);

            self::stored(0, self::USER);
            $this->assertSame(FLAG_HTML, self::flagOf(['u' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '1']]]), 'own item');

            self::stored(0, self::OTHER_USER);
            $this->assertSame(0, self::flagOf(['u' . self::ITEM => ['headline________' => ['<b>x</b>', 'flag' => '1']]]), 'item of another user');
        }

        public function test_the_nobody_user_is_a_visitor(): void
        {
            // pageOpen('nobody') of filler.php without a login - no permission look-up (UnaskedPerm)
            $GLOBALS['auth'] = new FakeAuth('nobody');

            $this->assertSame(0, self::flagOf([self::NEW => ['headline________' => ['<b>x</b>', 'flag' => '1']]]));
        }

        // ------------------------------------------------------------ the item's own ids

        private const ELSEWHERE = 'b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3';

        /** the ids of the item posted as fields - the grabber sets them itself, a request must not */
        private static function forgedIds(): array
        {
            return [
                'slice_id________' => ['x'],
                'slice_id........' => [pack_id(self::ELSEWHERE)],
                'u_slice_id______' => [self::ELSEWHERE],
                'id______________' => [pack_id(self::ELSEWHERE)],
                'unpacked_id.....' => [self::ELSEWHERE],
                'headline________' => ['<b>x</b>', 'flag' => '1'],
            ];
        }

        private static function withIdFields(): void
        {
            $ids = ['text_stored' => 0, 'in_item_tbl' => 'x', 'input_insert_func' => 'ids'];
            self::fields([self::HEAD => [], 'slice_id........' => $ids, 'u_slice_id......' => $ids, 'id..............' => $ids, 'unpacked_id.....' => $ids]);
        }

        public function test_a_new_item_stays_in_the_slice_of_its_key(): void
        {
            self::withIdFields();

            $items = self::grab([self::NEW => self::forgedIds()]);

            $this->assertSame(self::SLICE, $items[0]->getSliceID());
            $this->assertNotSame(self::ELSEWHERE, $items[0]->getItemID());
            $this->assertSame(0, $items[0]->getAaValue(self::HEAD)->getFlag(), 'decided for the slice it is stored in');
        }

        public function test_an_updated_item_stays_in_its_slice(): void
        {
            self::withIdFields();
            self::stored(0);

            foreach (['u', 'i', 'r'] as $mode) {
                $items = self::grab([$mode . self::ITEM => self::forgedIds()]);

                $this->assertSame(self::SLICE, $items[0]->getSliceID(), $mode);
                $this->assertSame(self::ITEM, $items[0]->getItemID(), $mode);
            }
        }

        // ------------------------------------------------------------ rich-text fields

        public function test_a_rich_text_field_takes_the_posted_flag(): void
        {
            self::fields([self::HEAD => ['input_show_func' => 'edt:8:60:class']]);

            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['headline________' => ['<p>x</p>', 'flag' => '1']]]));
            $this->assertSame([], self::logged());
        }

        /** @return array<string, array{0: array<string, mixed>}> */
        public static function subArrays(): array
        {
            return [
                'txt'      => [['<p>x</p>', 'txt' => '1', 'flag' => '1']],
                'file url' => [['fil' => ['var' => [], 'url' => ['<p>x</p>'], 'add' => '0']]],
            ];
        }

        /** the configured widget decides, not the widget the request names */
        #[DataProvider('subArrays')]
        public function test_the_configured_widget_decides_whether_html_is_allowed(array $input): void
        {
            self::fields([self::HEAD => ['input_show_func' => 'edt:8:60:class'], self::BODY => ['input_show_func' => 'txt:8']]);

            $this->assertSame(FLAG_HTML, self::flagOf([self::NEW => ['headline________' => $input]]), 'rich-text field');
            $this->assertSame(0, self::flagOf([self::NEW => ['full_text_______' => $input]], self::BODY), 'plain textarea');
        }

        // ------------------------------------------------------------ widgets and multiple values

        public function test_all_values_of_a_multiple_field_are_plain(): void
        {
            $items = self::grab([self::NEW => ['headline________' => ['<b>a</b>', '<b>b</b>', 'flag' => '1']]]);

            $value = $items[0]->getAaValue(self::HEAD);
            $this->assertSame(['<b>a</b>', '<b>b</b>'], $value->getValues());
            $this->assertSame([0, 0], array_column($value->getArray(), 'flag'));
        }

        /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> posted data, the grabbed values */
        public static function widgetInputs(): array
        {
            // providers run before setUpBeforeClass(), and this one needs ParamImplode()
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            $upload = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpA', 'error' => 0, 'size' => 1];
            return [
                'multiple checkboxes' => [['mch' => ['<b>a</b>', '<b>b</b>'], 'flag' => '1'], ['<b>a</b>', '<b>b</b>']],
                'checkbox'            => [['chb' => ['<b>a</b>'], 'flag' => '1'], ['<b>a</b>']],
                'multiple text'       => [['mfl' => ['<b>a</b>'], 'flag' => '1'], ['<b>a</b>']],
                'tags'                => [['tag' => ['<b>a</b>'], 'flag' => '1'], ['t<b>a</b>']],
                // no flag posted - these widgets mark their values HTML themselves
                'file url'            => [['fil' => ['var' => [], 'url' => ['"><script>x</script>'], 'add' => '0']], ['"><script>x</script>']],
                'file upload'         => [['fil' => ['var' => ['_0' => $upload], 'url' => [], 'add' => '0']], ['AA_UPLOAD:' . serialize($upload)]],
                'date'                => [['dte' => ['i' => ['2026-09-26']]], [(string) strtotime('2026-09-26')]],
                'password'            => [['pwd' => ['new1' => '<b>p</b>', 'new2' => '<b>p</b>', 'old' => '']], [ParamImplode(['AA_PASSWD', '<b>p</b>', '<b>p</b>', ''])]],
            ];
        }

        #[DataProvider('widgetInputs')]
        public function test_widget_sub_arrays_are_plain_for_a_visitor(array $input, array $values): void
        {
            $items = self::grab([self::NEW => ['headline________' => $input]]);

            $value = $items[0]->getAaValue(self::HEAD);
            $this->assertSame(0, $value->getFlag());
            $this->assertSame($values, $value->getValues(), 'the values are kept');
        }

        #[DataProvider('widgetInputs')]
        public function test_widget_sub_arrays_keep_the_flag_for_an_editor(array $input, array $values): void
        {
            self::loginAs(self::USER, PS_EDIT_ALL_ITEMS);

            $items = self::grab([self::NEW => ['headline________' => $input]]);

            $this->assertSame(FLAG_HTML, $items[0]->getAaValue(self::HEAD)->getFlag());
            $this->assertSame($values, $items[0]->getAaValue(self::HEAD)->getValues());
        }

        public function test_common_fields_are_plain_for_a_visitor(): void
        {
            $items = self::grab(['all' => ['headline________' => ['<b>x</b>', 'flag' => '1']], self::NEW => ['full_text_______' => ['y']], 'n2_' . self::SLICE => ['full_text_______' => ['z']]]);

            $this->assertSame([0, 0], array_map(static fn(ItemContent $item) => $item->getAaValue(self::HEAD)->getFlag(), $items));
        }

        // ------------------------------------------------------------ log

        public function test_a_refused_flag_is_logged_once_per_slice_and_field(): void
        {
            self::grab([
                self::NEW          => ['headline________' => ['a', 'flag' => '1']],
                'n2_' . self::SLICE => ['headline________' => ['b', 'flag' => '1'], 'full_text_______' => ['c', 'flag' => '1']],
            ]);

            $rows = self::logged();
            $this->assertCount(1, $rows, 'full_text has HTML by default - nothing refused there');
            $this->assertSame(ParamImplode([self::SLICE, self::HEAD]), $rows[0]['selector']);
            $this->assertSame(ParamImplode(['', 'new']), $rows[0]['params']);
        }

        public function test_the_log_names_the_user_and_an_update(): void
        {
            self::loginAs(self::USER, PS_FULLTEXT);
            self::stored(0);
            self::grab(['u' . self::ITEM => ['headline________' => ['a', 'flag' => '1']]]);

            $this->assertSame([ParamImplode([self::USER, 'update'])], array_column(self::logged(), 'params'));
        }

        public function test_nothing_is_logged_when_no_html_is_asked_for(): void
        {
            self::grab([self::NEW => ['headline________' => ['a', 'flag' => '0'], 'full_text_______' => ['b']]]);
            self::grab([self::NEW => ['headline________' => ['fil' => ['var' => [], 'url' => ['x'], 'add' => '0']]]]);

            $this->assertSame([], self::logged());
        }
    }
}
