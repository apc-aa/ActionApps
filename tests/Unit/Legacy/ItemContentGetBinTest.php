<?php

declare(strict_types=1);

/**
 * ItemContent::getBin() - which bin (AA_BIN_*) the item sits in, with the slice settings that
 * switch the expired/pending check off taken into account the same way isActive() takes them.
 * The slice is a fake registered in AA_Module's cache; no database is involved.
 */

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

    use AA_Item;
    use AA_Module;
    use AA_Slice;
    use ItemContent;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** Slice fake with readable properties only. */
    final class BinSlice extends AA_Slice
    {
        public function __construct(string $slice_id, private array $props)
        {
            $this->module_id = $slice_id;
            AA_Module::$_modules[$slice_id] = $this;    // what AA_Slice::getModule() returns
        }

        public function getProperty($prop)
        {
            return $this->props[$prop] ?? null;
        }

        /** the registry is global - other tests use the same slice id */
        public static function forget(string $slice_id): void
        {
            unset(AA_Module::$_modules[$slice_id]);
        }
    }

    final class ItemContentGetBinTest extends TestCase
    {
        private const SLICE = '22222222222222222222222222222222';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function tearDown(): void
        {
            BinSlice::forget(self::SLICE);
        }

        /** the item under test, with the slice registered unless $props is null (then the slice id is unknown) */
        private function item(?array $props, int $status, int $publish, int $expiry): ItemContent
        {
            if ($props !== null) {
                new BinSlice(self::SLICE, $props);
            }
            $ic = new ItemContent();
            $ic->setSliceID(($props === null) ? 'no-such-slice' : self::SLICE);
            $ic->setValue('status_code.....', $status);
            $ic->setValue('publish_date....', time() + $publish);
            $ic->setValue('expiry_date.....', time() + $expiry);
            return $ic;
        }

        public static function bins(): array
        {
            $day = 86400;
            // [slice props, status_code, publish offset, expiry offset, expected bin]
            return [
                'active'                         => [[], 1, -$day, $day, AA_BIN_ACTIVE],
                'expired'                        => [[], 1, -2 * $day, -$day, AA_BIN_EXPIRED],
                'pending'                        => [[], 1, $day, 2 * $day, AA_BIN_PENDING],
                'pending and expired = expired'  => [[], 1, $day, -$day, AA_BIN_EXPIRED],   // as CreateBinCondition() sorts it
                'holding'                        => [[], 2, -$day, $day, AA_BIN_HOLDING],
                'trash'                          => [[], 3, -$day, $day, AA_BIN_TRASH],
                'trash wins over expired'        => [[], 3, -2 * $day, -$day, AA_BIN_TRASH],
                'expired, nocheck_expired'       => [['nocheck_expired' => '1'], 1, -2 * $day, -$day, AA_BIN_ACTIVE],
                'pending, nocheck_pending'       => [['nocheck_pending' => '1'], 1, $day, 2 * $day, AA_BIN_ACTIVE],
                'expired, allow expired flag'    => [['flag' => SLICE_ALLOW_EXPIRED_CONTENT], 1, -2 * $day, -$day, AA_BIN_ACTIVE],
                'pending, allow pending flag'    => [['flag' => SLICE_ALLOW_PENDING_CONTENT], 1, $day, 2 * $day, AA_BIN_ACTIVE],
                'expired, only pending switched' => [['nocheck_pending' => '1'], 1, -2 * $day, -$day, AA_BIN_EXPIRED],
                'pending, only expired switched' => [['nocheck_expired' => '1'], 1, $day, -$day, AA_BIN_PENDING],
                'status 0 (column default)'      => [[], 0, -$day, $day, 0],   // no bin at all - must never show
                'unknown status'                 => [[], 4, -$day, $day, 0],
                // slice id AA does not know: getModule() returns null, so nothing is switched off (the ?-> branch)
                'expired, unknown slice'         => [null, 1, -2 * $day, -$day, AA_BIN_EXPIRED],
                'pending, unknown slice'         => [null, 1, $day, 2 * $day, AA_BIN_PENDING],
            ];
        }

        #[DataProvider('bins')]
        public function test_getBin(?array $props, int $status, int $publish, int $expiry, int $expected): void
        {
            $ic = $this->item($props, $status, $publish, $expiry);

            $this->assertSame($expected, $ic->getBin());
            $this->assertSame($expected === AA_BIN_ACTIVE, $ic->isActive());   // the two must never disagree
        }

        /** isActive($bin) answers for the other bins too, now that getBin() is the only classifier */
        public function test_isActive_answers_for_every_bin(): void
        {
            $day = 86400;
            $this->assertTrue($this->item([], 1, -$day, -$day)->isActive(AA_BIN_EXPIRED));
            $this->assertTrue($this->item([], 3, -$day, $day)->isActive(AA_BIN_TRASH));
            $this->assertTrue($this->item([], 2, -$day, $day)->isActive(AA_BIN_HOLDING));
            $this->assertFalse($this->item([], 2, -$day, $day)->isActive());          // holding is not active
            $this->assertFalse($this->item([], 0, -$day, $day)->isActive(AA_BIN_ALL));  // status 0 is in no bin
        }

        /** AA_Item delegates to the content object */
        public function test_AA_Item_getBin_delegates(): void
        {
            $day = 86400;
            $this->assertSame(AA_BIN_EXPIRED, (new AA_Item($this->item([], 1, -2 * $day, -$day)))->getBin());
        }
    }
}
