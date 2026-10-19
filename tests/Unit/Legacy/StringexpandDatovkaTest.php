<?php

declare(strict_types=1);

/**
 * {datovka:...} prints ?datRes= instead of sending. The value comes from the URL, so it may only be
 * text - neither HTML nor an expression that {expand} or {foreach} around the command would run.
 * The branch ends before the command reaches Datovka.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA_Stringexpand_Datovka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StringexpandDatovkaTest extends TestCase
{
    private array $get;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    protected function setUp(): void
    {
        $this->get = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
    }

    public function test_expand_datRes_plainTextUnchanged(): void
    {
        $_GET['datRes'] = 'Zpráva odeslána 2026-10-19';
        self::assertSame('Zpráva odeslána 2026-10-19', (new AA_Stringexpand_Datovka([]))->expand('test', 'bkfj8cu', 'test', 'https://test.org/a.pdf'));
    }

    public static function urlValues(): array
    {
        return [
            'markup and quotes'  => ['<script>alert(1)</script>"\'', '&lt;script&gt;alert(1)&lt;/script&gt;&quot;&apos;'],
            'expression'         => ['{now}', '&#123;now&#125;'],
            'quoted-brace marks' => ["\x1E|~@dnow\x1E|~@e", '|~@dnow|~@e'],
        ];
    }

    #[DataProvider('urlValues')]
    public function test_expand_datRes_onlyText(string $value, string $text): void
    {
        $_GET['datRes'] = $value;
        self::assertSame($text, (new AA_Stringexpand_Datovka([]))->expand());
    }

    // a template may run the output of the command again
    #[DataProvider('urlValues')]
    public function test_expand_datRes_staysTextInsideExpand(string $value, string $text): void
    {
        $_GET['datRes'] = $value;
        self::assertSame($text, AA::Un('{expand:{datovka}}'));
    }

    // ?datRes[]=x would otherwise end in a TypeError of the whole page
    public function test_expand_datResArray_givesNothing(): void
    {
        $_GET['datRes'] = ['<script>alert(1)</script>'];
        self::assertSame('', (new AA_Stringexpand_Datovka([]))->expand('test', 'bkfj8cu', 'test', 'https://test.org/a.pdf'));
    }
}
