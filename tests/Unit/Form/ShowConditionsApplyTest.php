<?php

declare(strict_types=1);

// The constants the legacy boot needs come with Legacy/fixtures/object_form_fixtures.php.

namespace AA\Tests\Unit\Form {

    use AA\Form\ShowConditions;
    use AA\Util\ScriptTag;
    use AA_Field;
    use AA_Profile;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;

    /** ShowConditions over a real item content: which fields the admin form hides, and what saving then stores. */
    final class ShowConditionsApplyTest extends TestCase
    {
        private const SLICE    = 'a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6';
        private const CAT      = 'category........';
        private const START    = 'start_date......';
        private const SWITCH   = 'switch..........';
        private const PASSWORD = 'password.......1';
        private const KEY      = '3fa94c1e';
        private const EXPIRY   = 'expiry_date.....';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);   // isProtected() looks for password copies among its fields
            // the current user's settings for the slice - none, and no database to ask
            AA_Profile::getProfile('', self::SLICE)->properties = [];
        }

        /** @param array<string, string> $defaults field id => input_default */
        /** @param array<string, array{string, string}> $extra field id => [widget, condition key] */
        private static function fields(array $defaults = [], string $switch_key = '', array $extra = []): FakeFields
        {
            $def = [
                self::CAT      => ['sel', ''],
                self::START    => ['fld', self::KEY],
                self::SWITCH   => ['chb', $switch_key],
                self::PASSWORD => ['pwd', self::KEY],
            ] + $extra;
            $list = [];
            foreach ($def as $id => [$widget, $key]) {
                $list[] = new AA_Field([
                    'id'                => $id,
                    'name'              => $id,
                    'slice_id'          => pack_id(self::SLICE),
                    'required'          => ($id === self::START) ? 1 : 0,
                    'input_show'        => 1,
                    'input_show_func'   => $widget,
                    'input_insert_func' => ($widget === 'pwd') ? 'pwd' : 'qte',
                    'input_validate'    => 'text',
                    'input_default'     => $defaults[$id] ?? '',
                    'text_stored'       => 1,
                    'in_item_tbl'       => ($id === self::EXPIRY) ? 'expiry_date' : '',
                    'content_edit'      => 0,
                    'aditional'         => ShowConditions::fieldJson('', $key),
                ] + array_fill_keys(['multiple', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
            }
            self::$slice->setFields($fields = new FakeFields(self::SLICE, $list));
            return $fields;
        }

        /** @param string $conds the rules, by default "category is Akce" */
        private static function conds(bool $clear = true, string $expr = '', string $conds = ''): ShowConditions
        {
            return ShowConditions::fromJson(json_encode(['showif' => [self::KEY => [
                'name'  => 'Akce',
                'conds' => $conds ?: 'd-' . self::CAT . '-=-"Akce"',
                'expr'  => $expr,
                'clear' => $clear,
            ]]]));
        }

        /** @param array<string, string> $values */
        private static function content(array $values): ItemContent
        {
            $content = new ItemContent();
            foreach ($values as $fid => $v) {
                $content->setAaValue($fid, new AA_Value($v));
            }
            return $content;
        }

        public function test_hiddenFor_uses_fallback_for_fields_missing_in_content(): void
        {
            $form = self::content([self::START => '999']);
            $this->assertArrayHasKey(self::START, self::conds()->hiddenFor(self::fields(), $form, self::content([self::CAT => 'Novinka'])));
            $this->assertArrayNotHasKey(self::START, self::conds()->hiddenFor(self::fields(), $form, self::content([self::CAT => 'Akce'])));
        }

        public function test_hiddenFor_uses_default_on_insert(): void
        {
            $fields = self::fields([self::CAT => 'txt:Akce']);
            $form   = self::content([self::START => '999']);
            $this->assertArrayNotHasKey(self::START, self::conds()->hiddenFor($fields, $form, null, true));
            $this->assertArrayHasKey(self::START, self::conds()->hiddenFor($fields, $form));
        }

        public function test_apply_clear_empties_rule_hidden_field(): void
        {
            $old = self::content([self::CAT => 'Novinka', self::START => '500']);
            foreach ([false, true] as $insert) {
                $content = self::content([self::CAT => 'Novinka', self::START => '999']);
                $hidden  = self::conds()->apply($content, self::fields([self::START => 'txt:abc']), $insert ? null : $old, $insert);
                $this->assertSame([''], $content->getValuesArray(self::START), $insert ? 'insert' : 'update');
                $this->assertSame([self::START, self::PASSWORD], $hidden);
            }
        }

        public function test_apply_clear_leaves_already_empty_field_out(): void
        {
            $content = self::content([self::CAT => 'Novinka', self::START => '999']);
            self::conds()->apply($content, self::fields(), self::content([self::CAT => 'Novinka', self::START => '']), false);
            $this->assertFalse($content->isSet(self::START));
        }

        public function test_apply_keep_removes_hidden_field_from_content(): void
        {
            $content = self::content([self::CAT => 'Novinka', self::START => '999']);
            self::conds(false)->apply($content, self::fields(), self::content([self::CAT => 'Novinka', self::START => '500']), false);
            $this->assertFalse($content->isSet(self::START));
        }

        public function test_apply_keep_insert_gives_default(): void
        {
            $content = self::content([self::CAT => 'Novinka', self::START => '999']);
            self::conds(false)->apply($content, self::fields([self::START => 'txt:abc']), null, true);
            $this->assertSame(['abc'], $content->getValuesArray(self::START));

            $content = self::content([self::CAT => 'Novinka', self::START => '999']);
            self::conds(false)->apply($content, self::fields(), null, true);
            $this->assertFalse($content->isSet(self::START));
        }

        public function test_apply_static_hidden_field_is_never_cleared(): void
        {
            $content = self::content([self::CAT => 'Akce', self::START => '999']);
            $hidden  = self::conds(true, '0')->apply($content, self::fields([self::START => 'txt:abc']), self::content([self::CAT => 'Akce', self::START => '500']), false);
            $this->assertFalse($content->isSet(self::START));
            $this->assertContains(self::START, $hidden);

            $content = self::content([self::CAT => 'Akce', self::START => '999']);
            self::conds(true, '0')->apply($content, self::fields([self::START => 'txt:abc']), null, true);
            $this->assertSame(['abc'], $content->getValuesArray(self::START));
        }

        public function test_apply_never_touches_password(): void
        {
            foreach ([true, false] as $clear) {
                $content = self::content([self::CAT => 'Novinka', self::PASSWORD => 'secret']);
                self::conds($clear)->apply($content, self::fields(), self::content([self::CAT => 'Novinka', self::PASSWORD => '$2y$10$hash']), false);
                $this->assertFalse($content->isSet(self::PASSWORD), $clear ? 'clear' : 'keep');
            }
        }

        public function test_apply_keeps_visible_field(): void
        {
            $content = self::content([self::CAT => 'Akce', self::START => '999']);
            $hidden  = self::conds()->apply($content, self::fields(), self::content([self::CAT => 'Novinka', self::START => '500']), false);
            $this->assertSame(['999'], $content->getValuesArray(self::START));
            $this->assertSame([], $hidden);
        }

        public function test_apply_checkbox_on_counts_as_checked(): void
        {
            $conds   = self::conds(true, '', 'd-' . self::SWITCH . '-=-"1"');
            $content = self::content([self::SWITCH => 'on', self::START => '999']);
            $this->assertSame([], $conds->apply($content, self::fields(), self::content([self::SWITCH => '0']), false));
            $this->assertSame(['999'], $content->getValuesArray(self::START));
        }

        /** as getFieldValues() reads the form: '' is no value, so a field with another value is not empty */
        public function test_hiddenFor_empty_string_is_no_value(): void
        {
            $conds   = self::conds(true, '', 'd-' . self::CAT . '-ISNULL-1');
            $content = self::content([self::START => '999']);
            $content->setAaValue(self::CAT, new AA_Value(['', 'Akce']));
            $this->assertArrayHasKey(self::START, $conds->hiddenFor(self::fields(), $content));
            $content->setAaValue(self::CAT, new AA_Value(['']));
            $this->assertSame([], $conds->hiddenFor(self::fields(), $content));
        }

        public function test_formConfig_shape(): void
        {
            // nofield: the slice has no such field - the rule holds
            $conds = self::conds(true, '', 'd-' . self::CAT . '-=-"Akce" OR "Kurz"-nofield.........-NOTNULL-1-' . self::CAT . '-<>-"x"-' . self::EXPIRY . '-ISNULL-1');
            $cfg   = $conds->formConfig(self::fields([], 'ffffffff', [self::EXPIRY => ['dte', '']]), [self::START => ShowConditions::LIVE, self::PASSWORD => ShowConditions::STATIC]);

            $rules = [
                ['fid' => self::CAT, 'op' => '=', 'v' => ['Akce', 'Kurz'], 'n' => false],
                ['fid' => self::CAT, 'op' => '<>', 'v' => ['x'], 'n' => false],
                ['fid' => self::EXPIRY, 'op' => 'ISNULL', 'v' => [], 'n' => true],   // an int column of the item table
            ];
            $this->assertSame([self::START => $rules, self::PASSWORD => $rules], $cfg['fields']);
            $this->assertSame([self::START], $cfg['hidden']);
            $this->assertSame([self::PASSWORD], $cfg['off']);
        }

        public function test_formConfig_json_is_script_safe(): void
        {
            $conds = self::conds(true, '', 'd-' . self::CAT . '-=-' . \AA_Condition::orExpression(['</script><b>', "a'b&"]));
            $json  = json_encode($conds->formConfig(self::fields(), []), ScriptTag::JS_JSON);
            $this->assertStringNotContainsString('<', $json);
            $this->assertStringNotContainsString("'", $json);
            $this->assertSame(['</script><b>', "a'b&"], json_decode($json, true)['fields'][self::START][0]['v']);
        }

        /** a field the user may not edit (profile hide&fill, frozen by feeding) keeps its stored value */
        public function test_apply_leaves_field_the_user_cannot_edit(): void
        {
            $profile             = AA_Profile::getProfile('', self::SLICE);
            $profile->properties = ['hide&fill' => [self::START => ['0:txt:Praha', 0], self::PASSWORD => ['', 0]]];
            try {
                $content = self::content([self::CAT => 'Novinka']);   // setFieldsFromForm() skipped the field
                self::conds()->apply($content, self::fields(), self::content([self::CAT => 'Novinka', self::START => '500']), false);
                $this->assertFalse($content->isSet(self::START), 'update');

                $content = self::content([self::CAT => 'Novinka', self::START => 'Praha']);   // the profile filled it
                self::conds()->apply($content, self::fields(), null, true);
                $this->assertSame(['Praha'], $content->getValuesArray(self::START), 'insert');
            } finally {
                $profile->properties = [];
            }

            $old = self::content([self::CAT => 'Novinka']);
            $old->setAaValue(self::START, new AA_Value('500', FLAG_FREEZE));
            $content = self::content([self::CAT => 'Novinka']);
            self::conds()->apply($content, self::fields(), $old, false);
            $this->assertFalse($content->isSet(self::START), 'frozen');
        }

        /** a column of the item table (expiry date, status) would turn an empty value into 0 - expired, trashed */
        public function test_apply_never_clears_item_table_field(): void
        {
            $fields  = self::fields([], '', [self::EXPIRY => ['dte', self::KEY]]);
            $content = self::content([self::CAT => 'Novinka', self::EXPIRY => '1900000000']);
            $hidden  = self::conds()->apply($content, $fields, self::content([self::CAT => 'Novinka', self::EXPIRY => '1800000000']), false);
            $this->assertContains(self::EXPIRY, $hidden);
            $this->assertFalse($content->isSet(self::EXPIRY));
        }

        /** the expression sees what the form was drawn from - the stored item - so drawing and saving agree */
        public function test_hiddenFor_expression_uses_stored_item_on_update(): void
        {
            $conds = self::conds(true, '{start_date......}', 'd-' . self::SWITCH . '-<>-"1"');
            $form  = self::content([self::START => '0']);
            $this->assertArrayNotHasKey(self::START, $conds->hiddenFor(self::fields(), $form, self::content([self::START => '1'])));
            $this->assertSame(ShowConditions::STATIC, $conds->hiddenFor(self::fields(), $form, self::content([self::START => '0']))[self::START] ?? null);
        }

        /** a new item: the expression sees the defaults, as when the empty form was drawn */
        public function test_hiddenFor_expression_uses_defaults_on_insert(): void
        {
            $conds  = self::conds(true, '{switch..........}', 'd-' . self::CAT . '-NOTNULL-1');
            $fields = self::fields([self::SWITCH => 'txt:1']);
            $this->assertArrayNotHasKey(self::START, $conds->hiddenFor($fields, self::content([self::SWITCH => '0', self::CAT => 'x']), null, true));
        }

        /** a new item: a field the profile fills has that value, not the field's default - as setFromForm() stores it */
        public function test_hiddenFor_profile_fill_wins_over_default_on_insert(): void
        {
            $profile             = AA_Profile::getProfile('', self::SLICE);
            $profile->properties = ['hide&fill' => [self::CAT => ['0:txt:Akce', 0], self::START => ['', 0], self::PASSWORD => ['', 0], self::SWITCH => ['', 0]]];
            try {
                $this->assertArrayNotHasKey(self::START, self::conds()->hiddenFor(self::fields([self::CAT => 'txt:Novinka']), self::content([]), null, true));
            } finally {
                $profile->properties = [];
            }
        }

        public function test_apply_slice_without_conditions_changes_nothing(): void
        {
            $content = self::content([self::CAT => 'Novinka', self::START => '999']);
            $before  = $content->getContent();
            $this->assertSame([], ShowConditions::fromJson('')->apply($content, self::fields(), self::content([self::START => '500']), false));
            $this->assertSame($before, $content->getContent());
        }
    }
}
