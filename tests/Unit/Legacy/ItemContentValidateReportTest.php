<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA_Field;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;

    /** ItemContent::validateReport() - the required-field check admin/itemedit.php runs before storing. */
    final class ItemContentValidateReportTest extends TestCase
    {
        private const SLICE    = 'a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7';
        private const REQUIRED = 'start_date......';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            $slice = new FakeSlice(self::SLICE);
            $list  = [];
            foreach (['headline........' => 0, self::REQUIRED => 1] as $id => $required) {
                $list[] = new AA_Field([
                    'id'                => $id,
                    'name'              => $id,
                    'slice_id'          => pack_id(self::SLICE),
                    'required'          => $required,
                    'input_show'        => 1,
                    'input_show_func'   => 'fld',
                    'input_insert_func' => 'qte',
                    'input_validate'    => 'text',
                    'text_stored'       => 1,
                    'content_edit'      => 0,
                ] + array_fill_keys(['multiple', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
            }
            $slice->setFields(new FakeFields(self::SLICE, $list));
        }

        private static function content(string $start): ItemContent
        {
            $content = new ItemContent();
            $content->setAaValue('headline........', new AA_Value('Headline'));
            $content->setAaValue(self::REQUIRED, new AA_Value($start));
            $content->setSliceID(self::SLICE);
            return $content;
        }

        public function test_validateReport_reports_empty_required_field(): void
        {
            foreach (['visible', 'all'] as $type) {
                $report = self::content('')->validateReport($type);
                $this->assertSame([self::REQUIRED], array_keys($report), $type);
                $this->assertSame(VALIDATE_ERROR_NOT_FILLED, $report[self::REQUIRED][0], $type);
                $this->assertSame([], self::content('2026')->validateReport($type), $type);
            }
        }

        public function test_validateReport_skips_ignored_fields(): void
        {
            $this->assertSame([], self::content('')->validateReport('visible', [self::REQUIRED]));
            $this->assertSame([self::REQUIRED], array_keys(self::content('')->validateReport('visible', ['headline........'])));
        }
    }
}
