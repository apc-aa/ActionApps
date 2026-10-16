<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\ViewSlices;
use AA_Credentials;
use AA_Module;
use AA_Slice;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use zids;

final class ViewSlicesTest extends TestCase
{
    private const OWN     = '0123456789abcdef0123456789abcdef';
    private const LOCKED  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KNOWN   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OPEN    = 'cccccccccccccccccccccccccccccccc';
    private const UNKNOWN = 'dddddddddddddddddddddddddddddddd';

    /** @var list<string> selectors of the log rows written */
    private array $logged = [];

    /** @var list<list<string>> the ids each look-up of stored slices was asked about */
    private array $stored_asked = [];

    /** @var list<string> slices this test put into AA_Module's cache */
    private array $fake_slices = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // ParamImplode(), AA_Credentials
    }

    /** the module cache and the credentials are static - what a test put there must not reach the next one */
    protected function tearDown(): void
    {
        unset($GLOBALS['slice_pwd']);
        $ids = $this->fake_slices;
        Closure::bind(static function () use ($ids): void {
            foreach ($ids as $id) {
                unset(AA_Module::$_modules[$id]);
            }
        }, null, AA_Module::class)();
        Closure::bind(function (): void {
            $this->_pwd = [];
        }, AA_Credentials::singleton(), AA_Credentials::class)();
    }

    /** a slice in AA_Module's cache whose reading password is what AA_Slice keeps - the hash; no database */
    private function passwordSlice(string $slice_id, string $hashed): void
    {
        $this->fake_slices[] = $slice_id;
        new class ($slice_id, $hashed) extends AA_Slice {
            public function __construct(string $slice_id, private string $hashed)
            {
                $this->module_id = $slice_id;
                AA_Module::$_modules[$slice_id] = $this;    // what AA_Slice::getModule() returns
            }

            public function getProperty($prop)
            {
                return ($prop === 'reading_password') ? $this->hashed : null;
            }
        };
    }

    /**
     * OWN, LOCKED and KNOWN have a reading password, the request knows KNOWN's; OPEN has none; UNKNOWN is no slice.
     * @param array<int|string, string>|null $item_slices what the item look-up answers; null = it must not be asked
     * @param list<string>                   $protected
     */
    private function guard(?array $item_slices = null, array $protected = [self::OWN, self::LOCKED, self::KNOWN]): ViewSlices
    {
        return new ViewSlices(
            static fn(): array => $protected,
            static fn(string $slice_id): bool => $slice_id === self::KNOWN,
            function (array $ids): array {
                $this->stored_asked[] = $ids;
                return array_values(array_intersect($ids, [self::OPEN]));
            },
            static fn(zids $zids): array => $item_slices ?? throw new RuntimeException('items looked up'),
            function (string $selector): void {
                $this->logged[] = $selector;
            },
        );
    }

    public function test_slices_ownSliceWithPassword_passes(): void
    {
        $this->assertSame([self::OWN], $this->guard()->slices(7, self::OWN, [self::OWN]));
        $this->assertSame([], $this->logged);
    }

    public function test_slices_foreignLockedSlice_isLeftOutAndLogged(): void
    {
        $this->assertSame([], $this->guard()->slices(7, self::OWN, [self::LOCKED]));
        $this->assertSame(['7:' . self::LOCKED], $this->logged);
    }

    public function test_slices_foreignSliceWithKnownPassword_isKept(): void
    {
        $this->assertSame([self::KNOWN], $this->guard()->slices(7, self::OWN, [self::KNOWN]));
    }

    public function test_slices_foreignOpenSlice_isKept(): void
    {
        $this->assertSame([self::OPEN], $this->guard()->slices(7, self::OWN, [self::OPEN]));
    }

    public function test_slices_noSlice_isLeftOutWithoutLog(): void
    {
        $this->assertSame([], $this->guard()->slices(7, self::OWN, [self::UNKNOWN]));
        $this->assertSame([], $this->logged);
    }

    public function test_slices_mixedList_keepsTheOthersInOrderAndAsksOnceForStoredSlices(): void
    {
        $allowed = $this->guard()->slices(7, self::OWN, [self::OPEN, self::LOCKED, self::OWN, self::UNKNOWN, self::KNOWN, self::OPEN]);

        $this->assertSame([self::OPEN, self::OWN, self::KNOWN], $allowed);
        $this->assertSame(['7:' . self::LOCKED], $this->logged);
        $this->assertSame([[self::OPEN, self::UNKNOWN]], $this->stored_asked);
    }

    public function test_slices_upperCaseId_isComparedAndLoggedLowerCase(): void
    {
        $this->assertSame([], $this->guard()->slices('slice.php', self::OWN, [strtoupper(self::LOCKED)]));
        $this->assertSame([self::OWN], $this->guard()->slices(7, self::OWN, [strtoupper(self::OWN)]));
        $this->assertSame(['slice.php:' . self::LOCKED], $this->logged);
    }

    public function test_items_noProtectedSliceButTheOwn_returnsTheSameIdsWithoutLookup(): void
    {
        $zids = new zids(['12', '34'], 's');

        $this->assertSame($zids, $this->guard(null, [self::OWN])->items(7, self::OWN, $zids));
    }

    public function test_items_shortIdsOfLockedSlice_areLeftOutAndLoggedOnce(): void
    {
        $zids = new zids(['12', '34', '56', '78', '90'], 's');

        $left = $this->guard([12 => self::OWN, 34 => self::LOCKED, 56 => self::OPEN, 78 => self::LOCKED, 90 => self::KNOWN])->items(7, self::OWN, $zids);

        $this->assertSame(['12', '56', '90'], $left->getArray());
        $this->assertSame('s', $left->onetype());
        $this->assertSame(['7:' . self::LOCKED], $this->logged);
    }

    public function test_items_upperCaseLongIds_areMatched(): void
    {
        $item = 'ffffffffffffffffffffffffffffffff';

        $left = $this->guard([$item => self::LOCKED])->items(7, self::OWN, new zids([strtoupper($item)], 'l'));

        $this->assertTrue($left->isEmpty());
    }

    public function test_items_idOfNoItem_isLeftOutWithoutLog(): void
    {
        $left = $this->guard([12 => self::OPEN])->items(7, self::OWN, new zids(['12', '99'], 's'));

        $this->assertSame(['12'], $left->getArray());
        $this->assertSame([], $this->logged);
    }

    public function test_narrow_nothingAsked_changesNothingAndLooksNothingUp(): void
    {
        $guard = new ViewSlices(
            static fn(): array => throw new RuntimeException('passwords looked up'),
            static fn(string $slice_id): bool => throw new RuntimeException('credentials asked'),
            static fn(array $ids): array => throw new RuntimeException('slices looked up'),
            static fn(zids $zids): array => throw new RuntimeException('items looked up'),
            static fn(string $selector): bool => true,
        );

        $this->assertSame([self::OWN, null, null], $guard->narrow(7, self::OWN, '', null, null));
    }

    public function test_narrow_allowedSliceId_isShown(): void
    {
        $this->assertSame([self::OPEN, null, null], $this->guard()->narrow(7, self::OWN, self::OPEN, null, null));
    }

    /** the view's own slice with no items - the page looks the same for a protected and a made-up slice */
    public function test_narrow_refusedSliceId_showsTheOwnSliceWithNoItems(): void
    {
        foreach ([self::LOCKED, self::UNKNOWN] as $slice_id) {
            [$shown, $slices, $zids] = $this->guard()->narrow(7, self::OWN, $slice_id, null, new zids(['12'], 's'));

            $this->assertSame(self::OWN, $shown);
            $this->assertNull($slices);
            $this->assertInstanceOf(zids::class, $zids);
            $this->assertTrue($zids->isEmpty());
        }
        $this->assertSame(['7:' . self::LOCKED], $this->logged);
    }

    public function test_narrow_everySliceOfListRefused_givesNoItems(): void
    {
        [$shown, $slices, $zids] = $this->guard()->narrow(7, self::OWN, '', [self::LOCKED, self::UNKNOWN], null);

        $this->assertSame(self::OWN, $shown);
        $this->assertSame([], $slices);
        $this->assertTrue($zids->isEmpty());
    }

    public function test_narrow_itemIds_areFilteredToo(): void
    {
        [, $slices, $zids] = $this->guard([12 => self::LOCKED, 34 => self::OPEN])->narrow(7, self::OWN, '', [self::OPEN], new zids(['12', '34'], 's'));

        $this->assertSame([self::OPEN], $slices);
        $this->assertSame(['34'], $zids->getArray());
    }

    /** AA_Slice keeps the password hashed and AA_Credentials registers hashes - the column holds it plain */
    public function test_knownToRequest_comparesThePasswordAsAaSliceKeepsIt(): void
    {
        $this->passwordSlice('1111111111111111111111111111111a', AA_Credentials::encrypt('vs-registered'));
        $this->passwordSlice('1111111111111111111111111111111b', AA_Credentials::encrypt('vs-from-url'));
        $this->passwordSlice('1111111111111111111111111111111c', AA_Credentials::encrypt('vs-nobody-knows'));
        $this->passwordSlice('1111111111111111111111111111111d', '');

        AA_Credentials::singleton()->register(AA_Credentials::encrypt('vs-registered'));   // {credentials:vs-registered}
        $GLOBALS['slice_pwd'] = 'vs-from-url';

        $this->assertTrue(ViewSlices::knownToRequest('1111111111111111111111111111111a'));
        $this->assertTrue(ViewSlices::knownToRequest('1111111111111111111111111111111b'));
        $this->assertFalse(ViewSlices::knownToRequest('1111111111111111111111111111111c'));
        $this->assertTrue(ViewSlices::knownToRequest('1111111111111111111111111111111d'));
    }
}
