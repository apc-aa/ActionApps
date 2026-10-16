<?php

declare(strict_types=1);

/**
 * IsItemEditable() (include/perm_core.php): who may change a stored item.
 *
 * init_page.php checks only the module named in the request - or the first module of the user
 * when the request names none, as the related-item "edit" button does - so admin/itemedit.php has
 * to ask about the item's own slice, else a user with any right to any module could save over any
 * item. Images and attachments live in related slices the editors often have no role in, and they
 * must keep editing them from the parent slice's form: a right in a slice whose related-item field
 * points to the item's slice counts as well.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'AA_ID'           => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Widget\IsoWidget;
    use AA\Widget\MchWidget;
    use AA\Widget\MngWidget;
    use AA\Widget\RimWidget;
    use AA\Widget\SelWidget;
    use AA\Widget\Widget;
    use AA_Object;
    use AA_Perm;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ItemEditPermTest extends TestCase
    {
        private const UID     = '42';
        private const OWN     = '11111111111111111111111111111111';   // the slice this user works in
        private const OTHER   = '22222222222222222222222222222222';   // somebody else's slice
        private const PHOTOS  = '33333333333333333333333333333333';   // related slice of OWN, no role in it

        public static function setUpBeforeClass(): void
        {
            class_exists(AA_Perm::class);   // loads include/perm_core.php with CheckPerms() and PS_*
        }

        protected function setUp(): void
        {
            // what AA_Perm::cache() leaves behind; CheckPerms() reads it instead of asking again
            $GLOBALS['permission_uid'] = self::UID;
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => []];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }

        private static function holding(string $slice_id, string $letters): void
        {
            $GLOBALS['permission_to']['slice'][$slice_id] = $letters;
        }

        public function test_anItemOfASliceTheUserHasNoRightToIsNotEditable(): void
        {
            self::holding(self::OWN, AA_Perm::ROLES['EDITOR']['perm']);

            self::assertFalse(IsItemEditable(self::UID, self::OTHER, self::UID, []), 'rights to one slice say nothing about another');
        }

        public function test_anEditorEditsEveryItemOfTheSlice(): void
        {
            self::holding(self::OWN, AA_Perm::ROLES['EDITOR']['perm']);

            self::assertTrue(IsItemEditable(self::UID, self::OWN, '7', []));
        }

        public function test_anAuthorEditsOnlyTheirOwnItems(): void
        {
            self::holding(self::OWN, AA_Perm::ROLES['AUTHOR']['perm']);

            self::assertTrue(IsItemEditable(self::UID, self::OWN, self::UID, []));
            self::assertFalse(IsItemEditable(self::UID, self::OWN, '7', []));
        }

        public function test_anItemNobodyPostedIsNobodysOwn(): void
        {
            // only a user without an id can tell: for them '' === '' would make every such item "their own"
            $GLOBALS['permission_uid'] = '';
            self::holding(self::OWN, AA_Perm::ROLES['AUTHOR']['perm']);

            self::assertFalse(IsItemEditable('', self::OWN, '', []));
        }

        public function test_anEditorOfTheParentSliceEditsItsRelatedItems(): void
        {
            // photos of articles: no role in the photo slice, the article slice points to it
            self::holding(self::OWN, AA_Perm::ROLES['EDITOR']['perm']);

            self::assertTrue(IsItemEditable(self::UID, self::PHOTOS, '7', [self::OWN]));
            self::assertFalse(IsItemEditable(self::UID, self::PHOTOS, '7', [self::OTHER]), 'a parent slice the user has no right to gives nothing');
        }

        public function test_anAuthorOfTheParentSliceEditsOnlyTheRelatedItemsTheyPosted(): void
        {
            self::holding(self::OWN, AA_Perm::ROLES['AUTHOR']['perm']);

            self::assertTrue(IsItemEditable(self::UID, self::PHOTOS, self::UID, [self::OWN]));
            self::assertFalse(IsItemEditable(self::UID, self::PHOTOS, '7', [self::OWN]));
        }

        public function test_aUserWithNoEntryForTheWholeAaIsJudgedBySliceRightsQuietly(): void
        {
            // the usual shape of the cache; a missing entry must not reach str_replace() in
            // AA_Perm::_resolve() as null - a deprecation on every check
            $GLOBALS['permission_to'] = ['slice' => [self::OWN => AA_Perm::ROLES['EDITOR']['perm']]];
            set_error_handler(static function (int $errno, string $errstr): never {
                throw new \ErrorException($errstr, 0, $errno);
            });
            try {
                self::assertTrue(IsItemEditable(self::UID, self::OWN, '7', []));
                self::assertFalse(IsItemEditable(self::UID, self::OTHER, '7', []));
            } finally {
                restore_error_handler();
            }
        }

        public function test_aSuperadminEditsAnyItem(): void
        {
            $GLOBALS['permission_to']['aa'][AA_ID] = AA_Perm::ROLES['SUPER']['perm'];

            self::assertTrue(IsItemEditable(self::UID, self::OTHER, '7', []));
        }

        #[DataProvider('relatedWidgets')]
        public function test_onlyWidgetsThatEditRelatedItemsOpenTheirSlice(string $class, string $buttons, bool $opens): void
        {
            $widget = new $class(['const' => '#sLiCe-' . self::PHOTOS] + ($buttons === '' ? [] : ['show_buttons' => $buttons]));

            self::assertSame($opens ? self::PHOTOS : '', RelatedItemEditTarget($widget));
        }

        /** se_inputform.php judges a field by its settings string, the way AA_Field::getWidget() builds the widget */
        #[DataProvider('storedSettings')]
        public function test_theEditTargetIsReadFromTheStoredSettings(string $settings, bool $opens): void
        {
            $params = Widget::parseClassProperties($settings);

            self::assertSame($opens ? self::PHOTOS : '', RelatedItemEditTarget(AA_Object::factory($params['class'], $params)));
        }

        public static function storedSettings(): array
        {
            return [
                'rim with the default buttons' => ['rim:#sLiCe-' . self::PHOTOS . ':headline........:MRND', false],
                'rim with the edit button'     => ['rim:#sLiCe-' . self::PHOTOS . ':headline........:MRNDE', true],
                'rim with no buttons given'    => ['rim:#sLiCe-' . self::PHOTOS . ':', false],
                'mng'                          => ['mng:#sLiCe-' . self::PHOTOS . ':relation........', true],
            ];
        }

        public static function relatedWidgets(): array
        {
            return [
                'rim with the edit button'                   => [RimWidget::class, 'MDRNE', true],
                'rim with the default buttons cannot edit'   => [RimWidget::class, '', false],
                'mng - gallery edits its items'              => [MngWidget::class, '', true],
                'iso - its popups want a role in the slice'  => [IsoWidget::class, 'MDRNE', false],
                'mch - checkboxes only choose'               => [MchWidget::class, '', false],
                'sel - a select only chooses'                => [SelWidget::class, '', false],
            ];
        }
    }
}
