<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA_Field;
    use AA_Profile;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;

    /**
     * ItemContent::setFromForm() with the old-style form (v<hex field id> inputs), as admin/itemedit.php
     * and filler.php post it. The item id comes from the page, which checked the rights to that item -
     * a posted id.............. field would make the store write over another item.
     */
    final class ItemContentFromFormTest extends TestCase
    {
        private const SLICE  = 'a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8a8';
        private const ITEM   = 'b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8';   // the item the page checked
        private const VICTIM = 'c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8';
        private const OTHER  = 'd8d8d8d8d8d8d8d8d8d8d8d8d8d8d8d8';   // another slice

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);
            $list = [];
            // some slices show the id field (with the "nul" widget, so no form renders an input for it)
            foreach (['id..............', 'slice_id........', 'headline........'] as $id) {
                $list[] = new AA_Field([
                    'id'                => $id,
                    'input_show'        => 1,
                    'input_show_func'   => ($id === 'headline........') ? 'fld' : 'nul',
                    'input_insert_func' => 'qte',
                    'input_validate'    => 'text',
                    'input_default'     => '',
                    'text_stored'       => 1,
                    'in_item_tbl'       => '',
                    'html_show'         => 0,
                    'html_default'      => 0,
                    'multiple'          => 0,
                ]);
            }
            self::$slice->setFields(new FakeFields(self::SLICE, $list));
            // the current user's settings for the slice - none, and no database to ask
            AA_Profile::getProfile('', self::SLICE)->properties = [];
        }

        protected function setUp(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST    = ['update' => 1, 'aa' => null];
            $_FILES   = ['aa' => null];
            $_REQUEST = [
                'v' . unpack_id('headline........') => 'New headline',
                'v' . unpack_id('id..............') => pack_id(self::VICTIM),   // stored packed, so 16 raw bytes
                'v' . unpack_id('slice_id........') => self::OTHER,
            ];
            unset($GLOBALS['auth']);
        }

        protected function tearDown(): void
        {
            $_POST = $_FILES = $_REQUEST = [];
            unset($_SERVER['REQUEST_METHOD']);
        }

        private static function stored(): ItemContent
        {
            $old = new ItemContent();
            $old->setAaValue('id..............', new AA_Value(self::ITEM, 0));
            $old->setAaValue('slice_id........', new AA_Value(self::SLICE, 0));
            $old->setAaValue('headline........', new AA_Value('Old headline', 0));
            $old->setAaValue('status_code.....', new AA_Value(1, 0));
            return $old;
        }

        public function test_the_shown_fields_are_read_from_the_form(): void
        {
            $content = new ItemContent();
            $content->setFromForm(self::$slice, self::ITEM, self::stored(), false);

            self::assertSame('New headline', $content->getValue('headline........'));
        }

        public function test_a_posted_item_id_does_not_change_which_item_is_stored(): void
        {
            $content = new ItemContent();
            $content->setFromForm(self::$slice, self::ITEM, self::stored(), false);

            self::assertSame(self::ITEM, $content->getItemID());
        }

        public function test_a_posted_slice_id_does_not_move_the_item(): void
        {
            $content = new ItemContent();
            $content->setFromForm(self::$slice, self::ITEM, self::stored(), false);

            self::assertSame(self::SLICE, $content->getSliceID());
        }
    }
}
