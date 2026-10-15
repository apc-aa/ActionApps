<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA\IO\Grabber\Form;
    use AA\Optimize\CheckAnonymousEditRefusals;
    use AA\Optimize\CheckItemEditsWithoutPermission;
    use AA\Optimize\Finding;
    use AA\Util\LogOnce;
    use AA_Content;
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
    final class AnonymousEditForm extends Form
    {
        /** @var array<string, ItemContent> item id => the item as stored */
        public static array $stored = [];

        protected function storedItem(string $item_id): ItemContent
        {
            return self::$stored[$item_id] ?? new ItemContent();
        }
    }

    /**
     * The aa[...] input (filler.php, {editable} / {ajax} on site pages, itemedit.php) with no user logged
     * in must not change the flags or the author of an existing item, nor the password, 2FA secret or
     * login of a reader - else anyone could take over a reader's account. The rest of the item is stored.
     */
    final class FormAnonymousEditTest extends TestCase
    {
        private const SLICE  = 'a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7';
        private const ITEM   = 'b7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b7';
        private const USER   = 'c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7';
        private const READER = 'd7d7d7d7d7d7d7d7d7d7d7d7d7d7d7d7';

        private const BODY = 'full_text.......';
        private const HEAD = 'headline........';

        /** protected fields of the reader slice => their posted name */
        private const PROTECTED = [
            'flags...........' => 'flags___________',
            'posted_by.......' => 'posted_by_______',
            'password........' => 'password________',
            'secret..........' => 'secret__________',
            'headline........' => 'headline________',
            'con_email.......' => 'con_email_______',   // where the password reset goes
            'text...........1' => 'text___________1',   // pwd inserter
            'text...........2' => 'text___________2',   // pwd widget
            'text...........3' => 'text___________3',   // 2FA widget
            'text...........4' => 'text___________4',   // 2FA inserter
            'password.......1' => 'password_______1',   // a password field of another number, plain widget
            'text...........5' => 'text___________5',   // pwd widget of a fieldslice
        ];

        /** another slice the item could be moved to */
        private const OTHER = 'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7';
        /** a slice whose related-item field edits the items of SLICE */
        private const SOURCE = 'f7f7f7f7f7f7f7f7f7f7f7f7f7f7f7f7';

        private static FakeSlice $slice;
        private static ?string $script_name;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);
            self::fields([
                'flags...........' => [],
                'posted_by.......' => [],
                'password........' => ['input_show_func' => 'pwd:', 'input_insert_func' => 'pwd:'],
                'secret..........' => [],
                'headline........' => [],
                'con_email.......' => [],
                'full_text.......' => [],
                'text...........1' => ['input_insert_func' => 'pwd:'],
                'text...........2' => ['input_show_func' => 'pwd:'],
                'text...........3' => ['input_show_func' => 'tfa:'],
                'text...........4' => ['input_insert_func' => '2fa:'],
                'password.......1' => [],
                'text...........5' => ['input_show_func' => '', 'widget_class' => 'AA\\Widget\\PwdWidget'],
                'slice_id........' => [],
            ]);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            // the slice of the edited item (AA_Slice::getSliceIdFromItemId())
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM item') ? [['usid' => self::SLICE]] : [];
            self::$slice->type   = 'ReaderManagement';
            self::$script_name   = $_SERVER['SCRIPT_NAME'] ?? null;
            $_SERVER['SCRIPT_NAME'] = '/aa/filler.php';
            unset($GLOBALS['auth']);
            self::stored();
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            $_POST = [];
            $_SERVER['SCRIPT_NAME'] = self::$script_name;
            unset($GLOBALS['auth'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }

        // ------------------------------------------------------------ helpers

        /** @param array<string, array<string, mixed>> $fields field id => properties over a plain-text field */
        private static function fields(array $fields): void
        {
            $list = [];
            foreach ($fields as $id => $data) {
                $widget_class = $data['widget_class'] ?? null;
                unset($data['widget_class']);
                $data += [
                    'id'                => $id,
                    'text_stored'       => 1,
                    'in_item_tbl'       => '',
                    'input_show_func'   => 'fld',
                    'input_insert_func' => 'qte',
                    'input_default'     => '',
                    'html_show'         => 0,
                    'html_default'      => 0,
                    'multiple'          => 0,
                ];
                // a field of a fieldslice: its widget is in widget_params, input_show_func is empty
                $list[] = ($widget_class === null) ? new AA_Field($data) : new class ($data, $widget_class) extends AA_Field {
                    public function __construct(array $data, string $widget_class)
                    {
                        parent::__construct($data);
                        $this->widget_params = ['class' => $widget_class];
                    }
                };
            }
            self::$slice->setFields(new FakeFields(self::SLICE, $list));
        }

        /** the reader item as stored */
        private static function stored(): void
        {
            $item = new ItemContent();
            $item->setItemID(self::ITEM);
            $item->setAaValue('u_slice_id......', new AA_Value(self::SLICE, 0));
            $item->setAaValue('posted_by.......', new AA_Value(self::READER, 0));
            $item->setAaValue('password........', new AA_Value('$2y$10$stored', 0));
            AnonymousEditForm::$stored[self::ITEM] = $item;
        }

        /**
         * Posts $aa through the grabber.
         * @return list<AA_Content> the grabbed items
         */
        private static function grab(array $aa): array
        {
            $_POST['aa'] = $aa;
            $form = new AnonymousEditForm();
            $form->prepare();
            $items = [];
            while ($item = $form->getItem()) {
                $items[] = $item;
            }
            return $items;
        }

        /** the only grabbed item */
        private static function grabOne(array $aa): AA_Content
        {
            $items = self::grab($aa);
            self::assertCount(1, $items);
            return $items[0];
        }

        /** @return list<array<string, mixed>> the refusals written to the log */
        private static function logged(): array
        {
            return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckAnonymousEditRefusals::EVENT));
        }

        /** @return array<string, array{0: string, 1: string}> field id, posted name */
        public static function protectedFields(): array
        {
            $ret = [];
            foreach (self::PROTECTED as $field_id => $posted) {
                $ret[$field_id] = [$field_id, $posted];
            }
            return $ret;
        }

        /** @return array<string, array{0: string}> */
        public static function editModes(): array
        {
            return ['replace' => ['u'], 'add' => ['i'], 'remove' => ['r']];
        }

        // ------------------------------------------------------------ anonymous edits

        #[DataProvider('protectedFields')]
        public function test_an_anonymous_edit_leaves_the_field_as_stored(string $field_id, string $posted): void
        {
            $item = self::grabOne(['u' . self::ITEM => [$posted => ['AA_PASSWD_CRYPTED:$2y$10$forged'], 'full_text_______' => ['new text']]]);

            $this->assertFalse($item->isSet($field_id), 'left out - the stored value stays');
            $this->assertSame(['new text'], $item->getAaValue(self::BODY)->getValues(), 'the rest of the item is stored');
            $this->assertSame([ParamImplode([self::SLICE, $field_id])], array_column(self::logged(), 'selector'));
        }

        #[DataProvider('editModes')]
        public function test_adding_and_removing_values_is_refused_too(string $mode): void
        {
            $item = self::grabOne([$mode . self::ITEM => ['password________' => ['x'], 'posted_by_______' => [self::USER], 'full_text_______' => ['y']]]);

            $this->assertFalse($item->isSet('password........'));
            $this->assertFalse($item->isSet('posted_by.......'));
            $this->assertTrue($item->isSet(self::BODY));
            $this->assertSame([ParamImplode([$mode, 'filler.php'])], array_unique(array_column(self::logged(), 'params')));
        }

        public function test_the_stored_email_posted_back_is_kept_and_not_logged(): void
        {
            AnonymousEditForm::$stored[self::ITEM]->setAaValue('con_email.......', new AA_Value('Reader@Example.org', 0));

            $item = self::grabOne(['u' . self::ITEM => ['con_email_______' => [' reader@example.org'], 'full_text_______' => ['y']]]);

            $this->assertFalse($item->isSet('con_email.......'), 'the stored spelling stays');
            $this->assertTrue($item->isSet(self::BODY));
            $this->assertSame([], self::logged(), 'the whole record sent back is no attempt');
        }

        public function test_an_empty_password_is_not_set_on_a_reader_without_one(): void
        {
            AnonymousEditForm::$stored[self::ITEM]->setAaValue('password........', new AA_Value('', 0));

            $this->assertFalse(self::grabOne(['u' . self::ITEM => ['password________' => ['']]])->isSet('password........'), 'an empty password would let anyone log in');
        }

        public function test_another_email_is_refused(): void
        {
            AnonymousEditForm::$stored[self::ITEM]->setAaValue('con_email.......', new AA_Value('reader@example.org', 0));

            $this->assertFalse(self::grabOne(['u' . self::ITEM => ['con_email_______' => ['evil@example.org']]])->isSet('con_email.......'));
        }

        public function test_a_field_id_spelled_with_dots_is_refused(): void
        {
            $item = self::grabOne(['u' . self::ITEM => ['password........' => ['x'], 'flags...........' => ['0']]]);

            $this->assertFalse($item->isSet('password........'));
            $this->assertFalse($item->isSet('flags...........'));
        }

        // ------------------------------------------------------------ the old-style form (v<field> inputs)

        public function test_the_old_style_form_leaves_the_account_fields_out(): void
        {
            $request  = $_REQUEST;
            $_REQUEST = ['v' . unpack_id('con_email.......') => 'evil@example.org', 'v' . unpack_id('password........') . 'a' => 'x'];
            $content  = array_fill_keys(['con_email.......', 'password........', 'flags...........', 'posted_by.......', self::BODY], [['value' => 'v', 'flag' => 0]]);
            try {
                $kept = Form::withoutAccountFields(self::$slice, $content);
            } finally {
                $_REQUEST = $request;
            }

            $this->assertSame([self::BODY], array_keys($kept), 'the stored account fields stay');
            $this->assertSame([ParamImplode([self::SLICE, 'con_email.......']), ParamImplode([self::SLICE, 'password........'])], array_column(self::logged(), 'selector'), 'only the fields the form sent are logged');
        }

        public function test_the_old_style_form_posting_the_stored_email_back_is_not_logged(): void
        {
            $request  = $_REQUEST;
            $_REQUEST = ['v' . unpack_id('con_email.......') => 'READER@example.org'];
            $stored   = new ItemContent();
            $stored->setAaValue('con_email.......', new AA_Value('reader@example.org', 0));
            $content  = ['con_email.......' => [['value' => 'READER@example.org', 'flag' => 0]], self::BODY => [['value' => 'v', 'flag' => 0]]];
            try {
                $kept = Form::withoutAccountFields(self::$slice, $content, $stored);
            } finally {
                $_REQUEST = $request;
            }

            $this->assertSame([self::BODY], array_keys($kept), 'the stored spelling stays');
            $this->assertSame([], self::logged());
        }

        public function test_the_old_style_form_leaves_out_an_empty_password_and_a_stored_value_from_notshown(): void
        {
            $request  = $_REQUEST;
            $_REQUEST = ['v' . unpack_id('password........') . 'a' => '', 'v' . unpack_id('posted_by.......') => self::READER];
            $stored   = new ItemContent();
            $stored->setAaValue('posted_by.......', new AA_Value(self::READER, 0));
            // filler.php copies a field listed in notshown as the stored AA_Value
            $content  = ['password........' => [['value' => '', 'flag' => 0]], 'posted_by.......' => new AA_Value(self::READER, 0), self::BODY => [['value' => 'v', 'flag' => 0]]];
            try {
                $kept = Form::withoutAccountFields(self::$slice, $content, $stored);
            } finally {
                $_REQUEST = $request;
            }

            $this->assertSame([self::BODY], array_keys($kept));
            $this->assertSame([], self::logged(), 'neither changes what is stored');
        }

        public function test_the_old_style_form_of_a_logged_in_user_is_unchanged(): void
        {
            self::login(self::USER, '');
            $content = array_fill_keys(['con_email.......', 'password........', self::BODY], [['value' => 'v', 'flag' => 0]]);

            $this->assertSame($content, Form::withoutAccountFields(self::$slice, $content));
        }

        public function test_the_nobody_user_is_anonymous(): void
        {
            // pageOpen('nobody') of filler.php and site.php without a login
            $GLOBALS['auth'] = new FakeAuth('nobody');

            $this->assertFalse(self::grabOne(['u' . self::ITEM => ['password________' => ['x']]])->isSet('password........'));
        }

        public function test_common_fields_can_not_smuggle_a_protected_field(): void
        {
            [$edited, $new] = self::grab([
                'all'                => ['password________' => ['x'], 'flags___________' => ['0']],
                'u' . self::ITEM     => ['full_text_______' => ['y']],
                'n1_' . self::SLICE  => ['full_text_______' => ['z']],
            ]);

            $this->assertFalse($edited->isSet('password........'));
            $this->assertFalse($edited->isSet('flags...........'));
            $this->assertSame(['y'], $edited->getAaValue(self::BODY)->getValues());
            $this->assertSame(['x'], $new->getAaValue('password........')->getValues(), 'a new item takes them as ever');
            $this->assertSame(['0'], $new->getAaValue('flags...........')->getValues());
        }

        public function test_new_items_are_unchanged(): void
        {
            $posted = array_fill_keys(array_values(self::PROTECTED), ['v']);

            $item = self::grabOne(['n1_' . self::SLICE => $posted]);

            foreach (array_keys(self::PROTECTED) as $field_id) {
                $this->assertSame(['v'], $item->getAaValue($field_id)->getValues(), $field_id);
            }
            $this->assertSame([], self::logged());
        }

        public function test_the_headline_and_email_of_an_ordinary_slice_stay_editable(): void
        {
            self::$slice->type = 'News_EN_tmpl....';

            $item = self::grabOne(['u' . self::ITEM => ['headline________' => ['A title'], 'con_email_______' => ['a@b.cz'], 'flags___________' => ['0']]]);

            $this->assertSame(['A title'], $item->getAaValue(self::HEAD)->getValues());
            $this->assertSame(['a@b.cz'], $item->getAaValue('con_email.......')->getValues());
            $this->assertFalse($item->isSet('flags...........'), 'flags are protected in any slice');
            $this->assertSame([ParamImplode([self::SLICE, 'flags...........'])], array_column(self::logged(), 'selector'));
        }

        public function test_an_anonymous_edit_does_not_move_the_item_to_another_slice(): void
        {
            // in a slice of plain fields the login and the password would be open to the next request; the
            // grabber sets slice_id________ in place, a later spelling of the same field would win
            $item = self::grabOne(['u' . self::ITEM => ['slice_id________' => ['x'], 'full_text_______' => ['y'], 'slice_id..______' => [pack_id(self::OTHER)]]]);

            $this->assertSame([pack_id(self::SLICE)], $item->getAaValue('slice_id........')->getValues());
            $this->assertSame(['y'], $item->getAaValue(self::BODY)->getValues());
            $this->assertSame([ParamImplode([self::SLICE, 'slice_id........'])], array_column(self::logged(), 'selector'));
        }

        public function test_a_logged_in_user_moves_the_item_as_before(): void
        {
            self::login(self::USER, '');

            $item = self::grabOne(['u' . self::ITEM => ['slice_id________' => ['x'], 'slice_id..______' => [pack_id(self::OTHER)]]]);

            $this->assertSame([pack_id(self::OTHER)], $item->getAaValue('slice_id........')->getValues());
            $this->assertSame([], self::logged());
        }

        // ------------------------------------------------------------ logged-in users

        /** @return array<string, array{0: string}> */
        public static function loggedInUsers(): array
        {
            return ['user' => [self::USER], 'reader' => [self::READER]];
        }

        #[DataProvider('loggedInUsers')]
        public function test_a_logged_in_user_may_change_the_fields(string $uid): void
        {
            self::login($uid, '');
            $posted = array_fill_keys(array_values(self::PROTECTED), ['v']);

            foreach (['u', 'i'] as $mode) {
                $item = self::grabOne([$mode . self::ITEM => $posted]);
                foreach (array_keys(self::PROTECTED) as $field_id) {
                    $this->assertTrue($item->isSet($field_id), "$mode $field_id");
                }
            }
            $this->assertSame([], self::logged());
        }

        // ------------------------------------------------------------ edits without the edit permission

        /** logs in $uid with the slice permission letters $perms (CheckPerms() reads the cached ones) */
        private static function login(string $uid, string $perms): void
        {
            $GLOBALS['auth']           = new FakeAuth($uid);
            $GLOBALS['permission_uid'] = $uid;
            $GLOBALS['permission_to']  = ['slice' => [self::SLICE => $perms], 'aa' => [AA_ID => '']];
        }

        /** @return list<array<string, mixed>> the rows of the edits without permission */
        private static function noPermLogged(): array
        {
            return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckItemEditsWithoutPermission::EVENT));
        }

        /** @return array<string, array{0: ?string, 1: string, 2: string}> uid (null: no login), slice perms (a PS_EDIT_SELF_ITEMS, k PS_EDIT_ALL_ITEMS - the constants come with the boot), logged editor ('' none) */
        public static function editors(): array
        {
            return [
                'anonymous'                   => [null, '', 'anonymous'],
                'nobody'                      => ['nobody', '', 'anonymous'],
                'user without permission'     => [self::USER, '', 'user'],
                'author without permission'   => [self::READER, '', 'own'],    // posted_by of the stored item
                'the reader of the record'    => [self::ITEM, '', 'own'],
                'author with self-edit'       => [self::READER, 'a', ''],
                'other user with self-edit'   => [self::USER, 'a', 'user'],
                'editor'                      => [self::USER, 'ak', ''],
            ];
        }

        #[DataProvider('editors')]
        public function test_an_edit_without_the_edit_permission_is_logged_and_stored(?string $uid, string $perms, string $editor): void
        {
            if ($uid !== null) {
                self::login($uid, $perms);
            }

            $item = self::grabOne(['u' . self::ITEM => ['full_text_______' => ['new'], 'headline________' => ['x']]]);

            $this->assertSame(['new'], $item->getAaValue(self::BODY)->getValues(), 'nothing is refused yet');
            $rows = self::noPermLogged();
            if ($editor === '') {
                $this->assertSame([], $rows);
                return;
            }
            // headline........ of the reader slice is refused for anonymous visitors - not part of the edit
            $fields = ($editor === 'anonymous') ? [self::BODY] : [self::BODY, self::HEAD];
            $this->assertSame(array_map(static fn($f) => ParamImplode([self::SLICE, $editor, $f]), $fields), array_column($rows, 'selector'));
            $this->assertSame(ParamImplode(['u', 'filler.php']), $rows[0]['params']);
            $this->assertStringNotContainsString('new', $rows[0]['selector'] . $rows[0]['params']);
        }

        public function test_an_editor_of_the_slice_whose_gallery_edits_the_item_is_not_logged(): void
        {
            // photos live in a slice the editors of the article slice have no role in; its mng field edits them
            $source = new FakeSlice(self::SOURCE);
            $source->setFields(new FakeFields(self::SOURCE, [new AA_Field(['id' => 'relation........', 'input_show_func' => 'mng:#sLiCe-' . self::SLICE, 'text_stored' => 1, 'in_item_tbl' => '', 'input_insert_func' => 'ids', 'input_default' => '', 'html_show' => 0, 'html_default' => 0, 'multiple' => 1])]));
            RecordingDb::$answer = static fn(string $sql) => match (true) {
                str_contains($sql, 'FROM item')     => [['usid' => self::SLICE]],
                str_contains($sql, 'FROM `field`')  => [['sid' => self::SOURCE, 'id' => 'relation........']],
                str_contains($sql, 'FROM `module`') => [['type' => 'S']],
                default                             => [],
            };
            \AA::Contentcache()->clear();   // the sources of the slice are kept for the request
            self::login(self::USER, '');
            $GLOBALS['permission_to']['slice'][self::SOURCE] = 'ak';
            try {
                self::grabOne(['u' . self::ITEM => ['full_text_______' => ['new']]]);

                $this->assertSame([], self::noPermLogged());
            } finally {
                \AA::Contentcache()->clear();
            }
        }

        public function test_new_items_are_not_logged_as_edits(): void
        {
            self::grab(['n1_' . self::SLICE => ['full_text_______' => ['new']]]);

            $this->assertSame([], self::noPermLogged());
        }

        public function test_the_state_check_lists_an_edit_without_permission(): void
        {
            self::grab(['u' . self::ITEM => ['full_text_______' => ['new']]]);
            [$row] = self::noPermLogged();

            [$finding] = CheckItemEditsWithoutPermission::findingsFor(
                [['selector' => $row['selector'], 'days' => '2', 'first' => (string) $row['time'], 'last' => (string) $row['time'], 'params' => $row['params']]],
                [self::SLICE => 'Readers'],
            );

            $this->assertSame(Finding::INFO, $finding->severity);
            $this->assertStringContainsString('Readers', $finding->title);
            $this->assertSame(self::BODY, $finding->evidence[0]['Field']);
            $this->assertSame(2, $finding->count);
        }

        // ------------------------------------------------------------ log

        public function test_the_log_names_the_mode_and_the_script_never_the_value(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/apc-aa/modules/site/site.php';

            self::grab(['u' . self::ITEM => ['password________' => ['AA_PASSWD_CRYPTED:$2y$10$forged']]]);

            $rows = self::logged();
            $this->assertCount(1, $rows);
            $this->assertSame(ParamImplode(['u', 'site.php']), $rows[0]['params']);
            $this->assertStringNotContainsString('forged', serialize(RecordingDb::$log));
        }

        public function test_a_refusal_is_logged_once_a_day_per_slice_and_field(): void
        {
            self::grab(['u' . self::ITEM => ['password________' => ['a']], 'i' . self::ITEM => ['password________' => ['b']]]);

            $this->assertCount(1, self::logged());
        }

        public function test_the_state_check_lists_a_logged_refusal(): void
        {
            self::grab(['u' . self::ITEM => ['secret__________' => ['x']]]);
            [$row] = self::logged();

            [$finding] = CheckAnonymousEditRefusals::findingsFor(
                [['selector' => $row['selector'], 'days' => '1', 'first' => (string) $row['time'], 'last' => (string) $row['time'], 'params' => $row['params']]],
                [self::SLICE => 'Readers'],
            );

            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString('secret..........', $finding->title);
            $this->assertStringContainsString('Readers', $finding->title);
            $this->assertSame('filler.php', $finding->evidence[0]['Script']);
        }
    }
}
