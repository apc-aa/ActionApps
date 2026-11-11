<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA\Form\ShowConditions;
    use AA_Field;
    use PHPUnit\Framework\TestCase;

    /** The display condition in the Show column of the Fields page (AA_Field::showFieldAdminRow()). */
    final class FieldAdminRowShowifTest extends TestCase
    {
        private const SLICE = 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            require_once __DIR__ . '/../../../include/formutil.php';
        }

        private static function field(string $aditional): AA_Field
        {
            return new AA_Field([
                'id'              => 'start_date......',
                'name'            => 'Start',
                'slice_id'        => pack_id(self::SLICE),
                'input_show'      => 1,
                'input_show_func' => 'fld',
                'aditional'       => $aditional,
            ] + array_fill_keys(['multiple', 'required', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed', 'content_edit'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        private static function conds(): ShowConditions
        {
            return ShowConditions::fromJson(json_encode(['showif' => [
                'aaaaaaaa' => ['name' => '<b>Akce</b> "x"', 'expr' => '1'],
                'bbbbbbbb' => ['name' => 'Kurz', 'expr' => '1'],
            ]]));
        }

        public function test_names_are_escaped_and_the_field_condition_is_selected(): void
        {
            $html = self::field('{"showif":"bbbbbbbb"}')->showFieldAdminRow(false, self::conds());
            $this->assertStringNotContainsString('<b>Akce', $html);
            $this->assertStringContainsString('Akce &quot;x&quot;', $html);
            $this->assertMatchesRegularExpression('~<option value="bbbbbbbb" selected~', $html);
            $this->assertStringContainsString('name="shwif[start_date......]"', $html);
        }

        public function test_missing_condition_is_offered_and_selected(): void
        {
            $html = self::field('{"showif":"cccccccc"}')->showFieldAdminRow(false, self::conds());
            $this->assertMatchesRegularExpression('~<option value="cccccccc" selected[^>]*>\(missing condition\)~', $html);
        }

        public function test_no_choice_without_conditions_object(): void
        {
            $this->assertStringNotContainsString('shwif', self::field('')->showFieldAdminRow(false));
        }
    }
}
