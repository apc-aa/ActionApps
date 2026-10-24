<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AA::getHeaders(): the headers a page of the site module is sent and cached with
 */
final class LocsessHeadersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/store_fixtures.php';
    }

    protected function setUp(): void
    {
        // no language files read for the charset
        AA::$headers = ['type' => 'text/html', 'encoding' => 'utf-8'];
    }

    protected function tearDown(): void
    {
        AA::$headers = [];
    }

    public function test_getHeaders_xfoPassed(): void
    {
        AA::$headers['XFO'] = 'X-Frame-Options: SAMEORIGIN';

        self::assertSame(['type' => 'Content-Type: text/html; charset=utf-8', 'XFO' => 'X-Frame-Options: SAMEORIGIN'], AA::getHeaders());
    }

    public function test_getHeaders_noFrameAncestorsUnasked(): void
    {
        AA::$headers['CSP'] = 'Content-Security-Policy: frame-ancestors *';

        self::assertSame('Content-Security-Policy: frame-ancestors *', AA::getHeaders()['CSP']);
    }

    public function test_getHeaders_frameAncestorsAloneWithoutCsp(): void
    {
        AA::$headers['frame-ancestors'] = "'self'";

        self::assertSame("Content-Security-Policy: frame-ancestors 'self'", AA::getHeaders()['CSP']);
    }

    public function test_getHeaders_frameAncestorsInTheCspOfTheTemplate(): void
    {
        AA::$headers['frame-ancestors'] = "'self'";
        AA::$headers['CSP']             = "Content-Security-Policy: default-src 'self' ;frame-ancestors\t*; ; img-src data:";

        self::assertSame("Content-Security-Policy: default-src 'self'; img-src data:; frame-ancestors 'self'", AA::getHeaders()['CSP']);
    }

    /** @return array<string, array{string, string}> the CSP of the template, the one sent */
    public static function policies(): array
    {
        return [
            // two policies: the frame-ancestors of the second would let any frame in
            'a comma'                 => ["default-src 'self', frame-ancestors *", "default-src 'self'; frame-ancestors 'self', frame-ancestors 'self'"],
            'a form feed'             => ["default-src 'self';\fframe-ancestors\f*", "default-src 'self'; frame-ancestors 'self'"],
            'a line end, capitals'    => ["img-src *;\r\n FRAME-ANCESTORS https://cizi.example", "img-src *; frame-ancestors 'self'"],
            'none is stricter'        => ["frame-ancestors 'none'; default-src 'self'", "default-src 'self'; frame-ancestors 'none'"],
            'none of NONE'            => ["frame-ancestors 'NONE'", "frame-ancestors 'none'"],
            'none only after another' => ["frame-ancestors *; frame-ancestors 'none'", "frame-ancestors 'self'"],
            'none in one policy'      => ["frame-ancestors 'none', script-src 'self'", "frame-ancestors 'none', script-src 'self'; frame-ancestors 'self'"],
            'an empty policy'         => ["script-src 'self', ", "script-src 'self'; frame-ancestors 'self'"],
        ];
    }

    #[DataProvider('policies')]
    public function test_getHeaders_frameAncestorsInEachPolicy(string $template, string $sent): void
    {
        AA::$headers['frame-ancestors'] = "'self'";
        AA::$headers['CSP']             = "Content-Security-Policy: $template";

        self::assertSame("Content-Security-Policy: $sent", AA::getHeaders()['CSP']);
    }

    // the tokenizer of the caller is left as it was
    public function test_getHeaders_strtokOfTheCallerKept(): void
    {
        AA::$headers['frame-ancestors'] = "'self'";
        AA::$headers['CSP']             = "Content-Security-Policy: default-src 'self'";
        self::assertSame('a', strtok('a b c', ' '));

        AA::getHeaders();

        self::assertSame('b', strtok(' '));
    }
}
