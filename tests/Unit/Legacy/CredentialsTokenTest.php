<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA\Cache\PageCache;
use AA_Credentials;
use AA_Module;
use AA_Slice;
use AA_Stringexpand_Encrypt;
use Closure;
use ItemContent;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The reading passwords a request knows, and the token {ajax} and AJAX widgets carry them in to
 * the next request (responder.php Get_Widget, filler.php).
 */
final class CredentialsTokenTest extends TestCase
{
    private const LOCKED = 'c7ed0000000000000000000000000001';
    private const OTHER  = 'c7ed0000000000000000000000000002';
    private const OPEN   = 'c7ed0000000000000000000000000003';
    private const TWIN   = 'c7ed0000000000000000000000000004';   // the same password as LOCKED

    /** @var list<string> slices this test put into AA_Module's cache */
    private array $fake_slices = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // locsess.php: AA_Stringexpand_Encrypt for the token
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                                 // AA::Pagecache()
        }
    }

    /** @var array<string, bool> the globals get_dynamic_setting_content() reads, as they were */
    private array $globals = [];

    protected function setUp(): void
    {
        foreach (['errcheck', 'debug'] as $name) {
            if (!array_key_exists($name, $GLOBALS)) {
                $this->globals[$name] = true;
                $GLOBALS[$name] = false;
            }
        }
        $this->passwordSlice(self::LOCKED, AA_Credentials::encrypt('ct-locked'));
        $this->passwordSlice(self::OTHER, AA_Credentials::encrypt('ct-other'));
        $this->passwordSlice(self::OPEN, '');
    }

    /** the module cache and the credentials are static - what a test put there must not reach the next one */
    protected function tearDown(): void
    {
        foreach (array_keys($this->globals) as $name) {
            unset($GLOBALS[$name]);
        }
        $ids = $this->fake_slices;
        Closure::bind(static function () use ($ids): void {
            foreach ($ids as $id) {
                unset(AA_Module::$_modules[$id]);
            }
        }, null, AA_Module::class)();
        self::forgetAll();
        (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
    }

    /** a next request knows nothing of what this one learnt */
    private static function forgetAll(): void
    {
        Closure::bind(function (): void {
            foreach (get_object_vars($this) as $name => $value) {
                if (is_array($value)) {
                    $this->$name = [];
                }
            }
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
                AA_Module::$_modules[$slice_id] = $this;
            }

            public function getProperty($prop)
            {
                return ($prop === 'reading_password') ? $this->hashed : null;
            }

            public function loadsettingfields($force = false): void
            {
                $this->dynamic_setting = new ItemContent();
            }
        };
    }

    /** @return list<string> the password hashes the token carries */
    private function carried(string $token): array
    {
        $data = AA_Stringexpand_Encrypt::decrypt_time_token($token, 'slice-pwd');
        $this->assertIsArray($data);

        return $data['spwd'];
    }

    public function test_checkSlice_withoutPassword_isOpen(): void
    {
        $this->assertTrue(AA_Credentials::singleton()->checkSlice(self::OPEN));
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::LOCKED));
    }

    public function test_loadFromSlice_opensTheSlice(): void
    {
        AA_Credentials::singleton()->loadFromSlice(self::LOCKED);

        $this->assertTrue(AA_Credentials::singleton()->checkSlice(self::LOCKED));
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::OTHER));
    }

    public function test_getAsToken_carriesTheRegisteredPasswordsToTheNextRequest(): void
    {
        AA_Credentials::singleton()->register(AA_Credentials::encrypt('ct-other'));   // {credentials:ct-other}
        $token = AA_Credentials::singleton()->getAsToken();

        self::forgetAll();
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::OTHER));
        AA_Credentials::singleton()->importFromToken($token);

        $this->assertTrue(AA_Credentials::singleton()->checkSlice(self::OTHER));
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::LOCKED));
    }

    public function test_getAsToken_carriesWhatLoadFromSliceRegistered(): void
    {
        AA_Credentials::singleton()->loadFromSlice(self::LOCKED);

        $this->assertSame([AA_Credentials::encrypt('ct-locked')], $this->carried(AA_Credentials::singleton()->getAsToken()));
    }

    public function test_register_emptyPassword_isNothing(): void
    {
        AA_Credentials::singleton()->register('');
        AA_Credentials::singleton()->loadFromSlice(self::OPEN);

        $this->assertSame([], $this->carried(AA_Credentials::singleton()->getAsToken()));
    }

    public function test_importFromToken_foreignToken_opensNothing(): void
    {
        AA_Credentials::singleton()->importFromToken('not-a-token');

        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::LOCKED));
    }

    public function test_getDynamicSettingContent_followsTheReadingPassword(): void
    {
        $this->assertNull(AA_Slice::getModule(self::LOCKED)->get_dynamic_setting_content());
        $this->assertNotNull(AA_Slice::getModule(self::LOCKED)->get_dynamic_setting_content(true));
        $this->assertNotNull(AA_Slice::getModule(self::OPEN)->get_dynamic_setting_content());

        AA_Credentials::singleton()->loadFromSlice(self::LOCKED);
        $this->assertNotNull(AA_Slice::getModule(self::LOCKED)->get_dynamic_setting_content());
    }

    public function test_openForRequest_opensTheSliceItsSettingsAndNothingElse(): void
    {
        $this->passwordSlice(self::TWIN, AA_Credentials::encrypt('ct-locked'));
        // checkSlice() of an id in capitals that missed the opened slices would ask the database otherwise
        $this->passwordSlice(strtoupper(self::LOCKED), AA_Credentials::encrypt('ct-locked'));

        AA_Credentials::singleton()->openForRequest(strtoupper(self::LOCKED));

        $this->assertTrue(AA_Credentials::singleton()->checkSlice(self::LOCKED));
        $this->assertTrue(AA_Credentials::singleton()->checkSlice(strtoupper(self::LOCKED)));
        $this->assertNotNull(AA_Slice::getModule(self::LOCKED)->get_dynamic_setting_content());
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::TWIN), 'the same password opens no other slice');
        $this->assertNull(AA_Slice::getModule(self::TWIN)->get_dynamic_setting_content());
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::OTHER));
    }

    /** a token {ajax} puts into the page would let anyone who copies it read the slice */
    public function test_openForRequest_isNotCarriedToTheNextRequest(): void
    {
        $this->passwordSlice(self::TWIN, AA_Credentials::encrypt('ct-locked'));
        AA_Credentials::singleton()->register(AA_Credentials::encrypt('ct-other'));
        AA_Credentials::singleton()->openForRequest(self::LOCKED);
        $token = AA_Credentials::singleton()->getAsToken();

        $this->assertSame([AA_Credentials::encrypt('ct-other')], $this->carried($token));
        self::forgetAll();
        AA_Credentials::singleton()->importFromToken($token);
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::LOCKED));
        $this->assertFalse(AA_Credentials::singleton()->checkSlice(self::TWIN));
        $this->assertTrue(AA_Credentials::singleton()->checkSlice(self::OTHER));
    }

    /** the template itself gave the password ({credentials}) - it may travel as before */
    public function test_getAsToken_openedAndRegistered_isCarried(): void
    {
        AA_Credentials::singleton()->openForRequest(self::LOCKED);
        AA_Credentials::singleton()->register(AA_Credentials::encrypt('ct-locked'));

        $this->assertSame([AA_Credentials::encrypt('ct-locked')], $this->carried(AA_Credentials::singleton()->getAsToken()));
    }

    /** whoever the check refused must not get the page another user's request rendered */
    public function test_openForRequest_keepsThePageOutOfThePageCache(): void
    {
        $this->assertFalse($this->noStore());

        AA_Credentials::singleton()->openForRequest(self::LOCKED);

        $this->assertTrue($this->noStore());
    }

    private function noStore(): bool
    {
        return (new ReflectionProperty(PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache());
    }
}
