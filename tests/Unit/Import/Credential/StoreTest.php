<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Credential;

use AA\Import\Credential\Secret;
use AA\Import\Credential\Store;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Constants cannot be undefined, so the vendor names are unique to this test and the values are
 * made up - another test defining the same name would silently change what this one sees.
 */
final class StoreTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';   // _m()
        if (!defined('AA_APIKEY_IMPORTCREDTEST')) {
            define('AA_APIKEY_IMPORTCREDTEST', 'vendor-value-9876');
        }
        if (!defined('AA_APIKEY_IMPORTCREDTEST_LOGIN')) {
            define('AA_APIKEY_IMPORTCREDTEST_LOGIN', 'login-value-1234');
        }
        if (!defined('AA_APIKEY_IMPORTCREDEMPTY')) {
            define('AA_APIKEY_IMPORTCREDEMPTY', '');
        }
    }

    public function test_names_contextFirstThenVendor(): void
    {
        self::assertSame(['AA_APIKEY_NZIS_LOGIN', 'AA_APIKEY_NZIS'], Store::names('nzis', 'login'));
        self::assertSame(['AA_APIKEY_DATA_FOR_SEO_SERP_LIVE', 'AA_APIKEY_DATA_FOR_SEO'], Store::names('data-for-seo', 'serp.live'));
        self::assertSame(['AA_APIKEY_CSU'], Store::names('csu'));
        self::assertSame([], Store::names(''));
    }

    public function test_resolve_missingIsNull(): void
    {
        self::assertNull(Store::resolve('importcrednosuch'));
        self::assertNull(Store::resolve('importcrednosuch', 'login'));
        self::assertFalse(Store::has('importcrednosuch'));
        self::assertTrue(Store::has('importcredtest'));
        self::assertSame('not set', Store::describe('importcrednosuch'));
    }

    public function test_resolve_emptyConstantIsNull(): void
    {
        self::assertNull(Store::resolve('importcredempty'));
        self::assertFalse(Store::has('importcredempty'));
    }

    public function test_resolve_contextWins(): void
    {
        self::assertSame('login-value-1234', Store::resolve('importcredtest', 'login')?->reveal());
        self::assertSame('vendor-value-9876', Store::resolve('importcredtest')?->reveal());
        self::assertSame('vendor-value-9876', Store::resolve('importcredtest', 'password')?->reveal(), 'no own key for the context - the vendor key');
    }

    public function test_secret_notSerializable(): void
    {
        // serialize() is how AA hashes memoized calls and stores sessions - the value would land there in clear
        $this->expectException(LogicException::class);
        serialize(new Secret('made-up-value-1234'));
    }

    public function test_secret_notUnserializable(): void
    {
        $this->expectException(LogicException::class);
        unserialize('O:27:"AA\\Import\\Credential\\Secret":1:{s:5:"value";s:3:"abc";}');
    }

    public function test_secret_jsonShowsStars(): void
    {
        $secret = new Secret('made-up-value-1234');

        self::assertFalse((new \ReflectionClass(Secret::class))->hasMethod('__toString'), 'no accidental concatenation');
        self::assertSame('"***"', json_encode($secret));
        self::assertStringNotContainsString('made-up', print_r($secret, true));
        self::assertSame('made-up-value-1234', $secret->reveal());
    }

    public function test_secret_hintShowsLastFour(): void
    {
        self::assertSame('…1234', (new Secret('made-up-value-1234'))->hint());
        self::assertSame('…ijkl', (new Secret('abcdefghijkl'))->hint(), 'twelve characters are long enough');
        self::assertStringContainsString('…1234', Store::describe('importcredtest', 'login'));
        self::assertStringNotContainsString('login-value', Store::describe('importcredtest', 'login'));
    }

    public function test_secret_hintHidesShortKeyAndItsLength(): void
    {
        // four characters of a short PIN or password are a big part of it
        self::assertSame('***', (new Secret('abcdefghijk'))->hint());
        self::assertSame('***', (new Secret('abcd'))->hint());
        self::assertSame('***', (new Secret(''))->hint());
        self::assertTrue((new Secret(''))->isEmpty());
    }

    public function test_secret_dumpsShowNoValue(): void
    {
        $secret = new Secret('made-up-value-1234');

        ob_start();
        var_dump($secret);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('made-up', $dump);
        self::assertStringNotContainsString('made-up', var_export($secret, true));
        self::assertStringNotContainsString('made-up', print_r((array) $secret, true));
        self::assertStringNotContainsString('made-up', print_r(get_object_vars($secret), true));
    }

    public function test_secret_cloneRefused(): void
    {
        $this->expectException(LogicException::class);
        clone new Secret('made-up-value-1234');
    }
}
