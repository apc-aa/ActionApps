<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Body;

use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\Body\PdfTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\PdfText;

require_once __DIR__ . '/../fixtures/PdfText.php';

/** Tables of a letter as dompdf gets them: a frame of one cell a row runs over pages, a template fits a line */
final class PdfTablesTest extends TestCase
{
    /** a PNG of 8 x 8 pixels */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQI12NkiFrAgA0wMeAAg1MCAMfEAQqHEKHXAAAAAElFTkSuQmCC';

    /** the document of $body as Letter::of() makes it */
    private static function doc(string $body): string
    {
        return (new HtmlBody())->document('Pozvánka', $body, true, '', PdfBody::CSS);
    }

    /** what flow() made of $body, from <body> on */
    private static function flowed(string $body): string
    {
        $flowed = PdfTables::flow(self::doc($body))[0];
        return substr($flowed, (int) strpos($flowed, '<body>'));
    }

    public function test_flow_oneCellTable(): void
    {
        [$flowed, $kept] = PdfTables::flow(self::doc('<table><tr><td>Vážení</td></tr><tr><th>řádek</th></tr></table><p>konec</p>'));

        self::assertStringNotContainsString('<table', $flowed);
        self::assertStringNotContainsString('<t', substr($flowed, (int) strpos($flowed, '<body>')));
        self::assertStringContainsString('<div><div>Vážení</div></div>', $flowed);
        self::assertStringContainsString('<p>konec</p>', $flowed);
        self::assertSame([], $kept);
    }

    // its text is no row of a table any more: no more than a page holds is asked of it
    public function test_check_oneCellTableNotCounted(): void
    {
        $doc = self::doc('<table><tr><td>' . str_repeat('slovo ', 4000) . '</td></tr></table>');

        self::assertSame(['grid' => 0, 'rows' => 0, 'cells' => 0], PdfTables::check($doc));
        $this->expectExceptionMessage(PdfTables::at(PdfTables::LONG_CELL, [1, 1]));
        PdfTables::check(self::doc('<table><tr><td>x</td><td>' . str_repeat('slovo ', 4000) . '</td></tr></table>'));
    }

    public function test_flow_nestedOuterAndInner(): void
    {
        $inner = '<table><tr><td>Hlavička</td></tr><tr><td>Text</td></tr><tr><td>Patička</td></tr></table>';
        $data  = '<table><tr><td>a</td><td>b</td></tr></table>';

        self::assertSame([], PdfTables::flow(self::doc("<table><tr><td>$inner</td></tr></table>"))[1]);
        self::assertStringNotContainsString('<table', self::flowed("<table><tr><td>$inner</td></tr></table>"));
        self::assertSame([2], PdfTables::flow(self::doc("<table><tr><td>$data</td></tr></table>"))[1], 'the table of data inside');
        self::assertSame([1, 3], PdfTables::flow(self::doc("<table><tr><td>$inner</td><td>x</td></tr></table>$data"))[1], 'a frame inside one of data');
        self::assertStringContainsString('<table><tr><td><div style="clear: both"><div><div>Hlavička</div></div>', self::flowed("<table><tr><td>$inner</td><td>x</td></tr></table>"));
    }

    /**
     * @return array<string, array{string}> tables of more columns than one: a spanning cell, too
     */
    public static function columns(): array
    {
        return [
            'two cells'   => ['<table><tr><td>a</td></tr><tr><td>b</td><td>c</td></tr></table>'],
            'colspan'     => ['<table><tr><td colspan="2">a</td></tr><tr><td>b</td></tr></table>'],
            'rowspan'     => ['<table><tr><td rowspan="2">a</td></tr><tr><td>b</td></tr></table>'],
            'no row'      => ['<table><caption>a</caption></table>'],
        ];
    }

    #[DataProvider('columns')]
    public function test_flow_twoColumnsStay(string $body): void
    {
        $doc = self::doc($body);

        self::assertSame([$doc, [1]], PdfTables::flow($doc));
    }

    public function test_flow_attributesToStyle(): void
    {
        $flowed = self::flowed('<table width="300" align="center" bgcolor="#eeeeee" border="2" cellpadding="4" style="margin-top: 1em">'
            . '<caption>Nadpis</caption><colgroup><col width="50"></colgroup><thead><tr align="right"><th>b</th></tr></thead>'
            . '<tr><td align="left" bgcolor="#ffffff" width="200" height="40" valign="top" style="color: red; height: 30px; line-height: 1.5">a</td></tr></table>');

        self::assertStringContainsString('<div style="clear: both; border: 2px solid; margin-left: auto; margin-right: auto; background-color: #eeeeee; min-width: 300px; margin-top: 1em">', $flowed);
        self::assertStringContainsString('<div style="text-align: center">Nadpis</div>', $flowed);
        self::assertStringContainsString('<div style="text-align: right"><div style="font-weight: bold; text-align: center; border: 1px solid; padding: 4px">b</div>', $flowed);
        self::assertStringContainsString('<div style="border: 1px solid; padding: 4px; text-align: left; background-color: #ffffff; min-width: 200px; min-height: 40px; color: red; min-height: 30px; line-height: 1.5">a</div>', $flowed);
        self::assertStringNotContainsString('<col', $flowed);
        self::assertStringContainsString('<div style="clear: both; margin-left: auto">', self::flowed('<table align="right"><tr><td>a</td></tr></table>'));
        self::assertStringContainsString('<div style="clear: both"><div><div style="min-width: 50%">a</div>', self::flowed('<table width="0"><tr><td width="0%" style="width: 50%">a</td></tr></table>'), 'no width of 0');
    }

    // a block of the width would break the words of a narrow frame apart, a letter a line
    public function test_render_narrowFrameWhole(): void
    {
        $pdf = (new PdfBody())->render(self::doc('<table width="99999"><tr><td>a</td><td>b</td></tr></table>'
            . '<table width="1"><tr><td>Vážení přátelé</td></tr></table><table style="width: 30px"><tr><td>zveme Vás</td></tr></table>'))->content;

        self::assertStringContainsString("Vážení přátelé\nzveme Vás\n", PdfText::of($pdf));
    }

    /** @return array<string, array{string}> what libxml left of a table outside its rows and cells */
    public static function stray(): array
    {
        return [
            'cells outside a row'  => ['<table><tr><td>K1</td></tr><td>K2</td><td>K3</td></table>'],
            'a table in a row'     => ['<table><tr><td>K1</td><table><tr><td>K2</td><td>K3</td></tr></table></tr></table>'],
            'a table in a table'   => ['<table><table><tr><td>K1</td><td>K2</td></tr></table><tr><td>K3</td></tr></table>'],
            'an image in colgroup' => ['<table><colgroup><img src="' . self::PNG . '"></colgroup><tr><td>K1 K2 K3</td></tr></table>'],
        ];
    }

    // a table as dompdf lays it out already: no other blocks to make of it
    #[DataProvider('stray')]
    public function test_flow_strayPartsStayTable(string $body): void
    {
        if (str_contains($body, self::PNG) && !extension_loaded('gd')) {
            self::markTestSkipped('GD is not loaded');
        }
        $doc = self::doc($body);

        self::assertSame($doc, PdfTables::flow($doc)[0]);
        preg_match_all('~K\d~', PdfText::of((new PdfBody())->render($doc)->content), $m);
        self::assertSame(['K1', 'K2', 'K3'], array_values(array_unique($m[0])));
    }

    public function test_flow_emptyRowSkipped(): void
    {
        self::assertSame([], PdfTables::flow(self::doc('<table><tr></tr><tr><td>a</td></tr><tr></tr></table>'))[1]);
        self::assertSame([1], PdfTables::flow(self::doc('<table><tr></tr></table><p>a</p>'))[1], 'no cell, no frame');
    }

    // only the token of flow() is put back: a text that looks like one stays
    public function test_flow_imagesKept(): void
    {
        $uris = ['data:image/png;base64,' . str_repeat('QUFB', 300_000), 'data:image/jpeg;base64,QkJC', 'data:image/png;base64,Q0ND'];
        $doc  = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            . "<table><tr><td><img src=\"$uris[0]\"></td></tr></table><p><img src=\"$uris[1]\"></p>"
            . "<table><tr><td>src=&quot;data:,0&quot;</td><td><img src=\"$uris[2]\" alt=\"&quot; src=&quot;data:,1&quot;\" title=\" href=&quot;0&quot;\"></td></tr></table></body></html>";

        [$flowed, $kept] = PdfTables::flow($doc);

        foreach ($uris as $uri) {
            self::assertSame(1, substr_count($flowed, " src=\"$uri\""));
        }
        self::assertSame(3, substr_count($flowed, '<img'));
        self::assertStringContainsString('src="data:,0"</td>', $flowed);
        // libxml writes a value with " in apostrophes: a src of no token in it stays text
        self::assertStringContainsString("alt='\" src=\"data:,1\"' title=' href=\"0\"'", $flowed);
        self::assertSame([2], $kept);
    }

    // libxml would write a link of its own, %-escaped: the PDF links to the same as without a table made blocks
    public function test_flow_linksAsWritten(): void
    {
        $link = '<p><a href="https://x.cz/žluť?a=b c&amp;lt=2&amp;copy=&quot;3&quot;#{x}">odkaz</a></p>';
        $doc  = self::doc('<table><tr><td>a</td></tr></table>' . $link);
        $uri  = static fn(string $doc): string => preg_match('~/URI \((.*?)\)\s*>>~s', (new PdfBody())->render($doc)->content, $m) ? $m[1] : '';

        preg_match('~<a href="[^"]*"~', $doc, $href);
        self::assertStringContainsString($href[0], PdfTables::flow($doc)[0]);
        self::assertSame($uri(self::doc($link)), $uri($doc));
        self::assertStringContainsString('žluť', $uri($doc));
    }

    // e-mails of 700-800 px: each width in one proportion, the cells of a row and the tables in a cell still fit
    public function test_flow_wideTemplateFits(): void
    {
        $text = static fn(int $from): string => implode('', array_map(static fn(int $i): string => "<p>Odstavec$i Vážení přátelé, zveme Vás na jednání rady města.</p>", range($from, $from + 2)));
        foreach ([700, 800] as $width) {
            $half     = intdiv($width, 2);
            $template = "<table width=\"$width\" align=\"center\" bgcolor=\"#eeeeee\"><tr><td>"
                . "<table width=\"$width\"><tr><td width=\"$half\">{$text(1)}</td><td width=\"$half\">{$text(4)}</td></tr></table></td></tr>"
                . '<tr><td><table width="' . ($width - 40) . "\"><tr><td>{$text(7)}</td><td>{$text(10)}</td><td>{$text(13)}</td></tr></table></td></tr>"
                . '<tr><td><table style="width: ' . ($width * 0.75) . "pt\"><tr><td>{$text(16)}</td><td style=\"width: {$half}px\">{$text(19)}</td></tr></table></td></tr></table>";

            $pdf = (new PdfBody())->render(self::doc($template))->content;

            preg_match_all('~Odstavec\d+~', PdfText::of($pdf), $m);
            self::assertCount(21, array_unique($m[0]), "$width px");
        }
        // 800 px is 600 pt: 481/600 of each
        self::assertStringContainsString('<table width="641px"><tr><td width="320px">', self::flowed('<table width="800"><tr><td width="400">a</td><td>b</td></tr></table>'));
        self::assertStringContainsString('<table style="width: 240pt"><tr><td style="width: 160px">a</td><td width="481px">', self::flowed('<table style="width: 300pt"><tr><td style="width: 200px">a</td><td width="600">b</td></tr></table>'), 'the cells of a row');
    }

    // what dompdf does not read, and a table that fits beside a wide one, make nothing narrower
    public function test_flow_narrowOnlyWhatIsWide(): void
    {
        self::assertStringContainsString('<td width="300px">', self::flowed('<table><colgroup><col width="2000"></colgroup><tr><td width="300">a</td><td>b</td></tr></table>'), 'a column');
        self::assertStringContainsString('<td width="500px">', self::flowed('<table style="width: 900px; width: 50%"><tr><td width="500">a</td><td>b</td></tr></table>'), 'the last width counts');
        self::assertStringContainsString('<table width="641px"><tr><td>a</td><td>b</td></tr></table><table width="400px">', self::flowed('<table width="800"><tr><td>a</td><td>b</td></tr></table><table width="400"><tr><td>c</td><td>d</td></tr></table>'));
    }

    // dompdf reads a number alone in points: as the editor shows it, the table is narrower than a line
    public function test_flow_widthInPixels(): void
    {
        self::assertStringContainsString('<table width="600px"><tr><td width="300px" height="20px">', self::flowed('<table width="600"><tr><td width="300" height="20">a</td><td>b</td></tr></table>'));
        self::assertStringContainsString('<table width="50%">', self::flowed('<table width="50%"><tr><td width="300">a</td><td>b</td></tr></table>'));
    }
}
