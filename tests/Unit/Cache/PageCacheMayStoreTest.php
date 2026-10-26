<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA;
use AA\Cache\PageCache;
use AA\Util\DebugAccess;
use PHPUnit\Framework\TestCase;

/** A debug render the request was granted is never stored; AA::$debug alone ({debug:N} in a template) does not stop it */
final class PageCacheMayStoreTest extends TestCase
{
    private const KEY = '0123456789abcdef0123';

    private mixed $debug = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';   // locsess.php: the AA class
    }

    protected function setUp(): void
    {
        $this->debug = AA::$debug;
    }

    protected function tearDown(): void
    {
        AA::$debug = $this->debug;
        DebugAccess::fromRequest(null, null, '', '');
    }

    public function test_mayStore_ordinaryRequestStores(): void
    {
        DebugAccess::fromRequest(null, null, '', '');

        $this->assertTrue((new PageCache())->mayStore(true));
    }

    public function test_mayStore_grantedByKeyNeverStores(): void
    {
        DebugAccess::fromRequest('2', null, self::KEY, self::KEY);

        $this->assertFalse((new PageCache())->mayStore(true));
    }

    public function test_mayStore_grantedToSuperadminNeverStores(): void
    {
        DebugAccess::fromRequest('2', null, '', '');
        DebugAccess::grant(0, static fn(): bool => true);

        $this->assertFalse((new PageCache())->mayStore(true));
    }

    public function test_mayStore_grantedStaysAfterTemplateSwitchesDebugOff(): void
    {
        AA::$debug = DebugAccess::fromRequest('2', null, self::KEY, self::KEY);
        AA::$debug = 0;   // {debug:0} mid-render

        $this->assertFalse((new PageCache())->mayStore(true));
    }

    public function test_mayStore_debugFromTemplateOnlyStores(): void
    {
        DebugAccess::fromRequest(null, null, '', '');
        AA::$debug = 6;   // {debug:6} left in a spot

        $this->assertTrue((new PageCache())->mayStore(true));
    }

    public function test_mayStore_refusedDebugStores(): void
    {
        DebugAccess::fromRequest('2', null, 'wrong-key-wrong-key', self::KEY);

        $this->assertTrue((new PageCache())->mayStore(true));
    }
}
