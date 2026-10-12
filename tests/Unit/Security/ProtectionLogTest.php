<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\ProtectionLog;
use PHPUnit\Framework\TestCase;

/** The log rows of the measured protections, read back by State Check and the Security page. */
final class ProtectionLogTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // ParamImplode(), ParamExplode()
    }

    public function test_parse_readsTheSelectorWriteStores(): void
    {
        self::assertSame([true, 'als[ROK]', 'example.org/cz/rocnik'], ProtectionLog::parse(ParamImplode(['refused', 'als[ROK]', 'example.org/cz/rocnik'])));
        self::assertSame([false, 'ret_code', ''], ProtectionLog::parse(ParamImplode(['allowed', 'ret_code', ''])));
    }

    public function test_requested_isTheHostAndPathWithoutQuery(): void
    {
        $server = $_SERVER;
        $_SERVER['HTTP_HOST']   = 'example.org';
        $_SERVER['REQUEST_URI'] = '/aaa/view.php?vid=12&als[ROK]=%7Bx%7D';
        try {
            self::assertSame('example.org/aaa/view.php', ProtectionLog::requested());
        } finally {
            $_SERVER = $server;
        }
    }
}
