<?php

declare(strict_types=1);

// include/import_util.php and its item classes need the constants include/config.php normally
// provides (through include/locsess.php, loaded by the store fixtures). Safe test values.

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

    use Actions;
    use AA_Fields;
    use ItemContent;
    use PHPUnit\Framework\TestCase;

    /**
     * The CSV import turns each row into an item by the actions posted from its mapping form, one
     * action per field of the slice (admin/se_csv_import2.php). A field can come without one: added
     * to the slice after the form was shown, or cut off when the form posts more inputs than PHP's
     * max_input_vars (four per field - three slices in the production databases have 241-983 fields).
     */
    final class ImportUtilActionsTest extends TestCase
    {
        private const ID = 'id..............';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/import_util.php';
        }

        /**
         * One CSV row ['Hello', 'World'] in the columns Title and Note, through the actions.
         *
         * @param array<string, string> $actions field => action
         * @param array<string, string> $from    field => CSV column ('__empty__' for none)
         * @param array<string, string> $params  field => parameter of the action
         * @param list<string>          $fields  the fields the slice has now
         * @return array{?string, ItemContent} the error, the item
         */
        private static function import(array $actions, array $from, array $params, array $fields): array
        {
            $all     = array_keys($actions);
            $from   += array_fill_keys($all, '__empty__');
            $params += array_fill_keys($all, '');
            $slice   = new class ('11111111111111111111111111111111', $fields) extends AA_Fields {
                /** @param list<string> $ids */
                public function __construct(string $slice_id, private array $ids)
                {
                    parent::__construct($slice_id);
                }

                public function getPriorityArray(): array
                {
                    return $this->ids;
                }
            };
            $item = null;
            $err  = convertCSV2Items(['Hello', 'World'], ['Title' => 0, 'Note' => 1], new Actions($actions, $from, array_fill_keys($all, 0), $params), $slice, $item);

            return [$err, $item];
        }

        public function test_store_copiesTheColumn(): void
        {
            [$err, $item] = self::import(['headline........' => 'store', self::ID => 'new'], ['headline........' => 'Title'], [], ['headline........']);

            self::assertNull($err);
            self::assertSame('Hello', $item->getValue('headline........'));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $item->getItemID(), 'new gives the item a new id');
        }

        public function test_default_withAColumnStoresIt(): void
        {
            [, $item] = self::import(['abstract........' => 'default', self::ID => 'new'], ['abstract........' => 'Note'], [], ['abstract........']);

            self::assertSame('World', $item->getValue('abstract........'));
        }

        public function test_default_withoutAColumnTakesTheParameter(): void
        {
            [, $item] = self::import(['abstract........' => 'default', self::ID => 'new'], [], ['abstract........' => 'fixed'], ['abstract........']);

            self::assertSame('fixed', $item->getValue('abstract........'));
        }

        public function test_default_withNothingLeavesTheFieldOut(): void
        {
            [$err, $item] = self::import(['abstract........' => 'default', self::ID => 'new'], [], [], ['abstract........']);

            self::assertNull($err);
            self::assertFalse($item->isSet('abstract........'));
        }

        // used to end in "Call to a member function getParams() on null" - the whole import stopped;
        // the field comes first, so the fields after it (and the id, added last) must still be filled
        public function test_aFieldWithoutAnActionIsLeftOut(): void
        {
            [$err, $item] = self::import(['headline........' => 'store', self::ID => 'new'], ['headline........' => 'Title'], [], ['abstract........', 'headline........']);

            self::assertNull($err);
            self::assertSame('Hello', $item->getValue('headline........'));
            self::assertFalse($item->isSet('abstract........'));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $item->getItemID());
        }
    }
}
