<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Body;

use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\Body\PdfTables;
use AA\Isds\File;
use Composer\InstalledVersions;
use Dompdf\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\PdfText;

require_once __DIR__ . '/../fixtures/PdfText.php';

/** The letter as dompdf lays it out: whole, or refused with what to change - never sent short */
final class PdfBodyTest extends TestCase
{
    /** the document of $body as Letter::of() makes it */
    private static function doc(string $body, string $subject = 'Pozvánka'): string
    {
        return (new HtmlBody())->document($subject, $body, true, '', PdfBody::CSS);
    }

    /** a document HtmlBody would never write: what dompdf does with it */
    private static function raw(string $body, string $head = ''): string
    {
        return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><style>" . PdfBody::CSS . "</style>$head</head><body>$body</body></html>";
    }

    private static function render(string $document, int $pages = PdfBody::MAX_PAGES, float $seconds = PdfBody::MAX_SECONDS): File
    {
        return (new PdfBody($pages, $seconds))->render($document);
    }

    /** the message render() refuses $document with, '' = none */
    private static function refusal(string $document, int $pages = PdfBody::MAX_PAGES, float $seconds = PdfBody::MAX_SECONDS): string
    {
        try {
            self::render($document, $pages, $seconds);
            return '';
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /** $n numbered paragraphs of $words words */
    private static function paragraphs(int $n, int $words = 6): string
    {
        return implode('', array_map(static fn(int $i): string => "<p>Odstavec$i " . str_repeat('slovo dopisu ř ', $words) . '</p>', range(1, $n)));
    }

    private static function numbered(string $pdf): int
    {
        preg_match_all('~Odstavec\d+~', PdfText::of($pdf), $m);
        return count(array_unique($m[0]));
    }

    /** an <img> of $w x $h pixels: JPEG, PNG, or PNG of a see-through background */
    private static function img(int $w, int $h, string $type = 'jpeg'): string
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not loaded');
        }
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) (($type === 'alpha') ? imagecolorallocatealpha($img, 0, 0, 0, 100) : imagecolorallocate($img, 200, 30, 30)));
        imagefilledrectangle($img, 0, 0, intdiv($w, 2), intdiv($h, 2), (int) imagecolorallocate($img, 30, 30, 200));
        ob_start();
        ($type === 'jpeg') ? imagejpeg($img) : imagepng($img);
        return '<img src="data:image/' . (($type === 'jpeg') ? 'jpeg' : 'png') . ';base64,' . base64_encode((string) ob_get_clean()) . '">';
    }

    /** aa-pdf-* directories in the temporary directory */
    private static function tempDirs(): array
    {
        return glob(sys_get_temp_dir() . '/aa-pdf-*') ?: [];
    }

    public function test_render_mainPdf(): void
    {
        $file = self::render(self::doc('<p>Vážený pane starosto,</p>'));

        self::assertSame([PdfBody::NAME, 'application/pdf', File::MAIN], [$file->name, $file->mimeType, $file->metaType]);
        self::assertStringStartsWith('%PDF-', $file->content);
        self::assertStringEndsWith("%%EOF\n", $file->content);
    }

    // Czech in fonts the PDF carries: no reader needs a font of its own
    public function test_render_czechTextInDejaVu(): void
    {
        $pdf = self::render(self::doc('<p>Žluťoučký kůň úpěl ďábelské ódy</p><pre>Kód: ěščř</pre>'))->content;

        self::assertStringContainsString("Žluťoučký kůň úpěl ďábelské ódy\n", PdfText::of($pdf));
        self::assertStringContainsString('Kód: ěščř', PdfText::of($pdf));
        preg_match_all('~/BaseFont /(\S+)~', $pdf, $fonts);
        self::assertSame(['DejaVuSans', 'DejaVuSansMono'], array_values(array_unique(array_map(static fn(string $f): string => (string) preg_replace('~^[A-Z]{6}\+~', '', $f), $fonts[1]))));
        self::assertSame(2, substr_count($pdf, '/FontFile2'), 'both embedded');
    }

    public function test_render_imagesEmbedded(): void
    {
        $pdf = self::render(self::doc('<p>Logo ' . self::img(40, 20) . ' a ' . self::img(30, 30, 'png') . '</p>'))->content;

        self::assertSame(2, preg_match_all('~/Subtype /Image~', $pdf));
        self::assertSame(1, substr_count($pdf, '/DCTDecode'), 'the JPEG as it is');
        self::assertCount(2, PdfText::images($pdf));
    }

    // what a test message compares: the same for the same document, whatever bytes dompdf writes
    public function test_source_stableAndVersioned(): void
    {
        $doc = self::doc('<p>Vážení,</p>');

        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', PdfBody::source($doc));
        self::assertSame(PdfBody::source($doc), PdfBody::source(self::doc('<p>Vážení,</p>')));
        self::assertNotSame(PdfBody::source($doc), PdfBody::source(self::doc('<p>Vážení!</p>')));
        $dompdf = InstalledVersions::getPrettyVersion('dompdf/dompdf') . '@' . InstalledVersions::getReference('dompdf/dompdf');
        self::assertSame(hash('xxh128', PdfBody::FORMAT . "\0$dompdf\0$doc"), PdfBody::source($doc), 'the look and the dompdf in it');
    }

    /** @param string $body refused before dompdf lays it out */
    #[DataProvider('tooLong')]
    public function test_render_tooLong(string $body): void
    {
        $doc   = self::doc($body);
        $start = hrtime(true);

        self::assertSame(PdfBody::TOO_LONG, self::refusal($doc));
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'measured, not laid out');
    }

    public static function tooLong(): array
    {
        return [
            'bytes'       => [str_repeat('<p>' . str_repeat('slovo ', 200) . '</p>', 170)],
            // a line costs dompdf more than its bytes: 9 500 lines took it 5 s
            'br as lines' => ['<p>' . str_repeat('x<br>', 2500) . '</p>'],
            'tags'        => [str_repeat('<b>x</b> ', 5001)],
            'rows'        => ['<table>' . str_repeat('<tr><td>x</td></tr>', 501) . '</table>'],
            'span area'   => ['<table><tr><td colspan="1000" rowspan="65534">x</td></tr></table>'],
            'span rows'   => ['<table>' . str_repeat('<tr><td colspan="300">x</td></tr>', 7) . '</table>'],
            'column span' => ['<table><colgroup span="1000"><col span="1000"></colgroup><tr><td>x</td><td>y</td></tr></table>'],
            // rows x columns: each place of the grid costs dompdf memory, a cell or not
            'grid'        => ['<table><tr><td colspan="201">x</td></tr>' . str_repeat('<tr><td>x</td></tr>', 499) . '</table>'],
        ];
    }

    // in a process of its own, as each test whose letter takes dompdf 30 MB or more: the memory stays split among
    // what later tests keep, and one of 70 MB found no room in 128 MB
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_render_justBelowLimitsLaidOut(): void
    {
        self::assertSame('', self::refusal(self::doc('<p>' . str_repeat('řádek<br>', 600) . '</p>')), 'a page of lines');
        self::assertSame('', self::refusal(self::doc('<table>' . str_repeat('<tr><td>x</td></tr>', 450) . '</table>')));
    }

    /**
     * @param string $body a page of dompdf's work for little of the letter: lines of no height, a column of a letter;
     *                     stopped at 0.5 s of the processor, which a busy machine does not stretch as the clock on the wall
     */
    #[DataProvider('amplified')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_render_amplificationStops(string $body): void
    {
        $doc   = self::doc($body);
        $cpu   = static function (): float {
            $usage = getrusage();
            return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
        };
        $start = $cpu();

        try {
            self::render($doc, seconds: 0.5);
            $refusal = '';
        } catch (\InvalidArgumentException $e) {
            $refusal = $e->getMessage();
        }

        self::assertContains(preg_replace('~ \(table \d+, row \d+\)$~', '', $refusal), ['', PdfBody::TOO_LONG, PdfBody::OFF_PAGE]);
        self::assertLessThan(1.5, $cpu() - $start);
    }

    public static function amplified(): array
    {
        return [
            'pages of margin'         => [str_repeat('<p style="margin-top: 3000px">x</p>', 60)],
            'no line height'          => ['<p style="width: 1px; line-height: 0">' . str_repeat('x', 16000) . '</p>'],
            'no size'                 => ['<p style="font-size: 0.01px; width: 0.001px">' . str_repeat('x', 8000) . '</p>'],
            'small words'             => ['<p style="font-size: 1px; line-height: 0">' . str_repeat('ab ', 50000) . '</p>'],
            'nested small'            => [str_repeat('<small>', 30) . str_repeat('x', 16000) . str_repeat('</small>', 30)],
            'a cell of a letter wide' => ['<table><tr><td width="1" style="font-size: 0.1px">' . str_repeat('x', 8000) . '</td></tr></table>'],
        ];
    }

    public function test_render_timeStopsBetweenPages(): void
    {
        $this->expectExceptionMessage(PdfBody::TOO_LONG);
        self::render(self::doc(self::paragraphs(150)), seconds: 0.01);
    }

    // a cell is laid out whole before its page: the time is looked at while its words are measured too
    public function test_render_timeStopsWithinAPage(): void
    {
        $doc   = self::doc('<table><tr><td>' . str_repeat('slovo ', 2600) . '</td></tr></table>');
        $start = hrtime(true);
        self::assertSame(PdfTables::at(PdfTables::LONG_CELL, [1, 1]), self::refusal($doc));
        $whole = (hrtime(true) - $start) / 1e9;
        $start = hrtime(true);

        try {
            self::render($doc, seconds: 0.01);
            self::fail('No PDF expected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(PdfBody::TOO_LONG, $e->getMessage());
        }
        self::assertLessThan($whole / 2, (hrtime(true) - $start) / 1e9);
    }

    // dompdf stops at memory_limit with a fatal error: the clock it calls throws first, when little is left
    public function test_render_memoryStopsBeforeTheLimit(): void
    {
        $clock  = new \ReflectionMethod(PdfBody::class, 'clock');
        $memory = (string) ini_get('memory_limit');
        $limit  = new \LengthException();
        try {
            ini_set('memory_limit', '-1');
            $unlimited = $clock->invoke(new PdfBody(), $limit);
            // 4 MB left, less than the reserve
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));
            $short = $clock->invoke(new PdfBody(), $limit);
        } finally {
            ini_set('memory_limit', $memory);
        }
        self::assertInstanceOf(\Closure::class, $unlimited);
        self::assertInstanceOf(\Closure::class, $short);

        $unlimited();
        try {
            $short();
            self::fail('The limit expected');
        } catch (\LengthException $e) {
            self::assertSame($limit, $e);
        }
    }

    // drawn on white by HtmlBody: no soft mask for dompdf to blend, which took it 17 s for 30 images
    public function test_render_alphaImagesBounded(): void
    {
        $doc   = self::doc(str_repeat('<p>' . self::img(400, 300, 'alpha') . '</p>', 30));
        $start = hrtime(true);

        $pdf = self::render($doc)->content;

        self::assertLessThan(3.0, (hrtime(true) - $start) / 1e9);
        self::assertStringNotContainsString('/SMask', $pdf);
        self::assertCount(30, PdfText::images($pdf));
    }

    public function test_render_longCellRefused(): void
    {
        $five = self::render(self::doc('<table><tr><td>x</td><td>' . self::paragraphs(5, 30) . '</td></tr></table>'))->content;

        self::assertSame(5, self::numbered($five), 'a row as high as a page');
        self::assertSame(PdfTables::at(PdfTables::LONG_CELL, [1, 1]), self::refusal(self::doc('<table><tr><td>x</td><td>' . self::paragraphs(6, 30) . '</td></tr></table>')));
        self::assertSame(PdfTables::at(PdfTables::LONG_CELL, [1, 1]), self::refusal(self::doc('<table><tr><td>' . self::paragraphs(60, 8) . '</td></tr></table>')), 'one cell of a layout too');
    }

    // more text than a page holds at 6 pt: refused before dompdf, which took 41 s for it
    public function test_render_cellTextCapRefusedEarly(): void
    {
        $doc   = self::doc('<table><tr>' . str_repeat('<td>' . self::paragraphs(1, 1000) . '</td>', 11) . '</tr></table>');
        $start = hrtime(true);

        self::assertSame(PdfTables::at(PdfTables::LONG_CELL, [1, 1]), self::refusal($doc));
        self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
    }

    // the cells of a row share the page: one with the text of the row may take the room the others leave
    public function test_render_cellTextOfTheRow(): void
    {
        $pdf = self::render(self::doc('<table border="1"><tr>' . str_repeat('<td>1</td>', 9) . '<td>' . str_repeat('slovo ', 300) . 'KONEC</td></tr></table>'))->content;

        self::assertStringContainsString('KONEC', PdfText::of($pdf));
    }

    // where in the letter, by numbers only: a word of it could be of anything, also in a refusal shown elsewhere
    public function test_render_refusalSaysTheTableAndRow(): void
    {
        $first = '<table border="1"><tr><td>a</td></tr></table>';
        $row   = static fn(string $cell): string => "<tr><td>b</td></tr><tr><td>c</td></tr><tr><td>x</td><td>$cell</td></tr>";

        self::assertSame('A table cell is longer than a page - split it or take the text out of the table (table 2, row 3)', self::refusal(self::doc($first . '<table>' . $row(self::paragraphs(6, 30)) . '</table>')), 'laid out');
        self::assertSame(PdfTables::at(PdfTables::LONG_CELL, [2, 3]), self::refusal(self::doc($first . '<table>' . $row(str_repeat('slovo ', 3000)) . '</table>')), 'counted before');
        self::assertSame(PdfTables::at(PdfBody::OFF_PAGE, [2, 3]), self::refusal(self::doc($first . '<table>' . $row(str_repeat('x', 300)) . '</table>')), 'off the page');
        self::assertSame(PdfBody::OFF_PAGE, self::refusal(self::doc($first . '<p style="margin-left: 900px">KONEC</p>')), 'outside a table, no place');
    }

    public function test_render_longLetterComplete(): void
    {
        $pdf = self::render(self::doc(self::paragraphs(150)))->content;

        self::assertSame(150, self::numbered($pdf));
        self::assertSame(11, preg_match_all('~/Type /Page\b~', $pdf));
    }

    // a line dompdf would draw past the right edge of the page
    public function test_render_wideTableRefused(): void
    {
        $table = static fn(int $width): string => self::doc("<table width=\"$width\" border=\"1\"><tr><td>Odstavec1 začátek</td><td>střed</td><td>KONEC</td></tr></table>");

        self::assertStringContainsString('KONEC', PdfText::of(self::render($table(640))->content));
        self::assertSame(PdfTables::at(PdfBody::OFF_PAGE, [1, 1]), self::refusal($table(700)));
        self::assertSame(PdfBody::OFF_PAGE, self::refusal(self::doc('<p style="margin-left: 900px">KONEC</p>')));
        self::assertSame(PdfBody::OFF_PAGE, self::refusal(self::doc('<p style="text-indent: -9999px">KONEC</p>')));
        self::assertSame(PdfBody::OFF_PAGE, self::refusal(self::doc('<ol style="margin-left: -70px; padding-left: 0"><li>položka</li></ol>')), 'its number');
    }

    // an image higher than a page is made smaller, not drawn below it
    public function test_render_tallImageWhole(): void
    {
        $images = PdfText::images(self::render(self::doc(self::paragraphs(3) . '<p>' . self::img(400, 1600) . '</p>'))->content);

        self::assertCount(1, $images);
        [$w, $h, $x, $y] = $images[0];
        self::assertGreaterThanOrEqual(56.0, $y, 'above the bottom margin');
        self::assertLessThanOrEqual(PdfBody::IMAGE_HEIGHT + 0.5, $h);
        self::assertEqualsWithDelta(0.25, $w / $h, 0.01, 'in its proportions');
        self::assertGreaterThanOrEqual(0.0, $x);
    }

    /** @param string $html the start of an <img> of 400 x 1600 pixels, its width wider than a page holds it */
    #[DataProvider('tallImages')]
    public function test_render_tallImageInItsProportions(string $html): void
    {
        $pdf    = self::render(self::doc(str_replace('<img', $html, self::img(400, 1600))))->content;
        $images = PdfText::images($pdf);

        self::assertCount(1, $images);
        [$w, $h] = $images[0];
        self::assertLessThanOrEqual(PdfBody::IMAGE_HEIGHT + 0.5, $h);
        self::assertEqualsWithDelta(0.25, $w / $h, 0.01);
    }

    public static function tallImages(): array
    {
        return [
            'width'       => ['<p><img width="300"'],
            'width in %'  => ['<p><img width="100%"'],
            'style'       => ['<p><img style="width: 2000px"'],
            'style in %'  => ['<p><img style="width: 100%"'],
            'style in em' => ['<p><img style="width: 40em"'],
            'in a cell'   => ['<table border="1"><tr><td>Příloha</td><td><img style="width: 100%"'],
        ];
    }

    // dompdf draws an image on a line from its baseline up, over the lines above
    public function test_render_inlineImageCoversNoText(): void
    {
        $pdf = self::render(self::doc('<h2>Nadpis pozvánky</h2><p>' . str_replace('<img', '<img width="300"', self::img(800, 600)) . '&nbsp;</p>'))->content;

        [[, , $baseline]] = PdfText::shown($pdf);
        [[, $h, , $y]]    = PdfText::images($pdf);

        self::assertLessThan($baseline - 4, $y + $h, 'below the descenders of the heading');
    }

    // a reply in a reply: the text of 14 levels in whole words, deeper is off the page
    public function test_render_deepQuoteReadable(): void
    {
        $quote = static fn(int $levels): string => self::doc(str_repeat('<blockquote>', $levels) . 'začátek ' . str_repeat('slovo ', 30) . 'konec' . str_repeat('</blockquote>', $levels));

        $words = array_count_values(preg_split('~\s+~', trim(PdfText::of(self::render($quote(14))->content))) ?: []);

        self::assertSame(['začátek' => 1, 'slovo' => 30, 'konec' => 1], $words);
        self::assertSame(PdfBody::OFF_PAGE, self::refusal($quote(20)));
    }

    // dompdf lays a table over a floating image, not beside it: it starts below
    public function test_render_tableBelowAFloatingImage(): void
    {
        $pdf = self::render(self::doc('<p>' . str_replace('<img', '<img style="float: left" width="300"', self::img(800, 600)) . 'Vážení,</p><table border="1"><tr><td>KONEC</td></tr></table>'))->content;

        [[, , , $bottom]] = PdfText::images($pdf);
        $konec            = array_values(array_filter(PdfText::shown($pdf), static fn(array $shown): bool => $shown[0] === 'KONEC'));
        self::assertCount(1, $konec);
        self::assertLessThan($bottom, $konec[0][2]);
    }

    // the table moved down the page: the break falls between the groups of rows under a cell and into them
    public function test_render_rowSpanOverAPageRefused(): void
    {
        $rows = '';
        foreach (range(0, 3) as $group) {
            $rows .= sprintf('<tr><td rowspan="3">Den%d</td><td>R%02d</td><td>%s</td></tr>', $group, 3 * $group + 1, str_repeat('slovo ', 25));
            $rows .= sprintf('<tr><td>R%02d</td><td>%s</td></tr><tr><td>R%02d</td><td>diskuse</td></tr>', 3 * $group + 2, str_repeat('slovo ', 25), 3 * $group + 3);
        }
        $outcomes = [];

        foreach (range(4, 10) as $before) {
            $doc = self::doc(self::paragraphs($before) . "<table border=\"1\">$rows</table>");
            try {
                preg_match_all('~R\d\d~', PdfText::of(self::render($doc)->content), $shown);
                self::assertCount(12, array_unique($shown[0]), "no row lost after $before paragraphs");
                $outcomes[] = 'PDF';
            } catch (\InvalidArgumentException $e) {
                $groups = array_map(static fn(int $row): string => PdfTables::at(PdfTables::SPAN_SPLIT, [1, $row]), [1, 4, 7, 10]);
                self::assertContains($e->getMessage(), $groups, "after $before paragraphs: not a cell longer than a page");
                $outcomes[] = 'split';
            }
        }

        self::assertSame(['PDF', 'split'], array_values(array_unique($outcomes)), 'a break between the groups and in one');
    }

    public function test_render_preLongLineWraps(): void
    {
        $text = PdfText::of(self::render(self::doc('<pre>' . str_repeat('sloupec ', 40) . "KONEC\n" . str_repeat('x', 300) . 'KONEC2</pre>'))->content);

        self::assertStringContainsString('KONEC', $text);
        self::assertStringContainsString('KONEC2', $text, 'a word longer than the line broken too');
    }

    // display values dompdf throws or warns on, as a letter would bring them
    #[DataProvider('displays')]
    public function test_render_dompdfDisplayValues(string $display): void
    {
        $pdf = self::render(self::doc("<p>a <span style=\"display: $display\">x</span> b</p><div style=\"display: $display\">y</div>"))->content;

        self::assertStringContainsString('y', PdfText::of($pdf));
    }

    public static function displays(): array
    {
        $values = ['table', 'table-cell', 'table-row', 'inline-table', 'table-row-group', '-dompdf-image', '-dompdf-page', '-dompdf-br', '-dompdf-list-bullet', 'flex', 'grid', 'contents'];
        return array_combine($values, array_map(static fn(string $v): array => [$v], $values));
    }

    // the text of an exception may quote the letter, its data: URIs, paths of the server
    #[DataProvider('failing')]
    public function test_render_dompdfExceptionWrapped(string $body): void
    {
        // what a library throws, warnings of PHP made so
        set_error_handler(static fn(int $no, string $text): never => throw new \InvalidArgumentException("library: $text"));
        try {
            self::render(self::raw($body));
            self::fail('No PDF expected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(PdfBody::NOT_LAID_OUT, $e->getMessage());
            self::assertNull($e->getPrevious());
        } finally {
            restore_error_handler();
        }
    }

    public static function failing(): array
    {
        return [
            'Dompdf\Exception'           => ['<p>a <span style="display: table-cell">x</span> b</p>'],
            'InvalidArgumentException'   => ['<p>a <img src="data:image/png;base64,AAAA"> b</p>'],
            'an image of another type'   => ['<p>a <img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="> b</p>'],
            'a file'                     => ['<p>a <img src="file:///etc/passwd"> b</p>'],
            'a remote image'             => ['<p>a <img src="http://127.0.0.1:1/x.png"> b</p>'],
        ];
    }

    /** @param string $document what HtmlBody would keep, or a document it never writes */
    #[DataProvider('active')]
    public function test_render_noActiveContent(string $document): void
    {
        $mark = sys_get_temp_dir() . '/aa-pdf-php-' . bin2hex(random_bytes(6));

        $pdf = self::render(str_replace('MARK', $mark, $document))->content;

        self::assertFileDoesNotExist($mark);
        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/AA', '/RichMedia', '/AcroForm', '/SubmitForm', '/GoToR', '/ImportData', '/Widget'] as $name) {
            self::assertDoesNotMatchRegularExpression('~' . preg_quote($name, '~') . '\b~', $pdf, $name);
        }
        preg_match_all('~/S /(\w+)~', $pdf, $actions);
        self::assertSame([], array_values(array_diff($actions[1], ['URI'])), 'no action but a link');
        preg_match_all('~/URI \((.*?)(?<!\\\\)\)~s', $pdf, $uris);
        foreach ($uris[1] as $uri) {
            self::assertMatchesRegularExpression('~^(?:https?://|mailto:)~i', $uri);
        }
        // <meta name="dompdf.view"> opens the PDF at a view of a page, which is no action
        preg_match('~/OpenAction (\d+) 0 R~', $pdf, $open);
        if ($open !== []) {
            self::assertMatchesRegularExpression('~\n' . $open[1] . ' 0 obj\s*\[\d+ 0 R /Fit\]~', $pdf);
        }
    }

    public static function active(): array
    {
        $links = ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'vbscript:x', 'file:///etc/passwd', 'data:text/html,<b>x</b>',
            'http:javascript:alert(1)', 'launch:x', 'smb://server/x', '\\\\server\\share\\x.exe', 'C:\\Windows\\calc.exe', 'x.exe', '../../etc/passwd',
            'https://example.org/a', 'mailto:obec@example.org', 'HTTP://EXAMPLE.ORG/'];
        return [
            'scripts'  => [self::raw("<p>x</p><script>app.alert(1)</script><script type=\"text/php\">touch('MARK');</script>")],
            'links'    => [self::doc(implode('', array_map(static fn(string $href): string => '<p><a href="' . htmlspecialchars($href) . '">odkaz</a></p>', $links)))],
            'raw head' => [self::raw('<form action="https://example.org/"><input name="a"><select name="b"><option>1</option></select><button>x</button></form><p>x</p>', '<meta name="dompdf.view" content="Fit"><base href="file:///etc/">')],
        ];
    }

    // a link and the subject are strings of the PDF: a ")" cannot end one
    public function test_render_uriAndTitleEscaped(): void
    {
        $pdf = self::render(self::doc('<p><a href="https://example.org/a)/S/JavaScript/JS(app.alert(1))">odkaz</a></p>', 'Pozvánka) /S /JavaScript /JS (app.alert(1)'))->content;

        self::assertStringContainsString('/URI (https://example.org/a\)/S/JavaScript/JS\(app.alert\(1\)\))', $pdf);
        self::assertSame(1, substr_count($pdf, 'JavaScript'), 'only in the link');
        self::assertMatchesRegularExpression('~/Title \(\xFE\xFF.*\\\\\)~s', $pdf, 'the title in UTF-16, its ")" escaped');
    }

    public function test_render_optionsLocked(): void
    {
        $options = (new \ReflectionMethod(PdfBody::class, 'options'))->invoke(null, '/nonexistent/aa-pdf-x');
        self::assertInstanceOf(Options::class, $options);
        $fonts = (new Options())->getFontDir();

        self::assertSame([false, false, false, false, true], [$options->getIsRemoteEnabled(), $options->getIsPhpEnabled(), $options->getIsJavascriptEnabled(), $options->getIsPdfAEnabled(), $options->getIsFontSubsettingEnabled()]);
        self::assertSame(['data://'], array_keys($options->getAllowedProtocols()));
        self::assertSame([[$fonts], $fonts, '/nonexistent/aa-pdf-x', '/nonexistent/aa-pdf-x', 'DejaVu Sans', '', false], [$options->getChroot(), $options->getFontDir(), $options->getFontCache(), $options->getTempDir(), $options->getDefaultFont(), $options->getLogOutputFile(), $options->getDebugKeepTemp()]);
        $rule = $options->getAllowedProtocols()['data://']['rules'][0];
        self::assertSame([true, false, false], [$rule('data:image/png;base64,AAAA')[0], $rule('data:font/ttf;base64,AAAA')[0], $rule('data:image/svg+xml;base64,AAAA')[0]]);
    }

    // a font of data: was installed into fontDir - its .ttf, .ufm and installed-fonts.json
    public function test_render_fontFaceWritesNothing(): void
    {
        $fonts  = (new Options())->getFontDir();
        $before = scandir($fonts);
        // a real font, small: the subset of DejaVu Sans dompdf embeds for "x"
        $pdf = self::render(self::doc('<p>x</p>'))->content;
        preg_match('~/FontFile2 (\d+) 0 R~', $pdf, $ref);
        preg_match('~\n' . $ref[1] . ' 0 obj\s*<<[^>]*>>\s*stream\r?\n(.*?)\r?\nendstream~s', $pdf, $stream);
        $font = 'data:font/ttf;base64,' . base64_encode((string) gzuncompress($stream[1]));

        self::refusal(self::raw('<p style="font-family: Evil">x</p>', "<style>@font-face{font-family:\"Evil\";src:url($font) format(\"truetype\")}</style>"));

        self::assertSame($before, scandir($fonts));
        self::assertFileDoesNotExist("$fonts/installed-fonts.json");
    }

    // the directory of fonts and images of one letter is its own while dompdf runs, and gone after it; after a fatal
    // error the shutdown function removes it
    public function test_render_leavesNoTempFiles(): void
    {
        $before = self::tempDirs();
        $modes  = [];
        // a warning of PHP within dompdf, while the directory is there
        set_error_handler(static function () use ($before, &$modes): bool {
            foreach (array_diff(self::tempDirs(), $before) as $dir) {
                $modes[] = fileperms($dir) & 0o777;
            }
            return true;
        });
        try {
            self::refusal(self::raw('<p>a <img src="data:image/png;base64,AAAA"> b</p>'));
        } finally {
            restore_error_handler();
        }
        self::render(self::doc('<p>Vážení</p>'));
        self::refusal(self::raw('<p>a <span style="display: table-cell">x</span></p>'));
        self::refusal(self::doc(self::paragraphs(150)), 1);
        self::assertSame(PdfBody::TOO_LONG, self::refusal(self::doc(self::paragraphs(150)), seconds: 0.01));

        self::assertNotSame([], $modes);
        self::assertSame([0o700], array_values(array_unique($modes)));
        self::assertSame($before, self::tempDirs());
    }

    // dompdf sets them for itself and puts them back only after a PDF
    public function test_render_phpSettingsRestored(): void
    {
        $encoding = mb_internal_encoding();
        $jit      = ini_get('pcre.jit');
        $numeric  = setlocale(LC_NUMERIC, '0');
        mb_internal_encoding('ISO-8859-1');
        ini_set('pcre.jit', '1');
        // a decimal comma, if the system has it
        $locale = setlocale(LC_NUMERIC, 'cs_CZ.UTF-8', 'cs_CZ.utf8', 'de_DE.UTF-8', 'de_DE.utf8') ?: (string) $numeric;
        try {
            self::assertSame(PdfBody::TOO_LONG, self::refusal(self::doc(self::paragraphs(150)), 1));
            self::assertSame('ISO-8859-1', mb_internal_encoding());
            self::assertSame('1', ini_get('pcre.jit'));
            self::assertSame($locale, setlocale(LC_NUMERIC, '0'));
        } finally {
            mb_internal_encoding($encoding);
            ini_set('pcre.jit', (string) $jit);
            setlocale(LC_NUMERIC, (string) $numeric);
        }
    }

    // what dompdf records while it reads the letter: a deprecation of the next PHP refuses no letter
    public function test_render_deprecationNoWarning(): void
    {
        $count = (new \ReflectionMethod(PdfBody::class, 'warnings'))->invoke(null, [
            'Creation of dynamic property Dompdf\\Frame::$x is deprecated',
            'str_contains(): Passing null to parameter #1 ($haystack) of type string is deprecated',
            'Permission denied on file:///etc/passwd',
            // what base64 of an image may spell
            'Error loading data:image/png;base64,AAAAdeprecatedAAAA: broken',
            'Permission denied on file:///tmp/deprecated',
            7,
        ]);

        self::assertSame(4, $count);
    }

    // dompdf's static cache of images kept the files of a letter that failed, removed with its directory
    public function test_render_imageAfterFailedRender(): void
    {
        $img = self::img(40, 20);

        self::assertSame(PdfBody::TOO_LONG, self::refusal(self::doc("<p>$img</p>" . str_repeat('<p style="margin-top: 3000px">x</p>', 5)), 1));
        $pdf = self::render(self::doc("<p>$img</p>"))->content;

        self::assertSame(1, substr_count($pdf, '/DCTDecode'));
    }

    // dompdf adds to a global of the process: one letter's warnings are no other one's, and they go nowhere
    public function test_render_warningsReset(): void
    {
        $GLOBALS['_dompdf_warnings'] = ['of another letter'];

        self::render(self::doc('<p>Vážení</p>'));
        self::assertSame([], $GLOBALS['_dompdf_warnings']);

        self::assertSame(PdfBody::NOT_LAID_OUT, self::refusal(self::raw('<p><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="></p>')));
        self::assertSame([], $GLOBALS['_dompdf_warnings']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_render_memoryReleased(): void
    {
        $doc = self::doc('<table>' . str_repeat('<tr><td>a</td><td>b</td><td>c</td></tr>', 200) . '</table>');
        self::render(self::doc('<p>x</p>'));
        gc_collect_cycles();
        $before = memory_get_usage();

        self::render($doc);

        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before);
    }

    /** @param string $body as the measurements of the limits had it; alone, as a request makes one letter: the static caches of dompdf
     *                     grow with every letter of a process */
    #[DataProvider('measured')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_memory_estimateAboveMeasured(string $body): void
    {
        $doc = self::doc($body);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        self::render($doc);

        self::assertLessThan(PdfBody::memory($doc), memory_get_peak_usage(true) - $before);
    }

    public static function measured(): array
    {
        return [
            'short'            => ['<p>Vážení,</p><p>zveme Vás.</p>'],
            'all faces'        => ['<h1>a</h1><p><b>b</b> <i>c</i> <b><i>d</i></b></p><pre>e <b>f</b> <i>g</i> <b><i>h</i></b></pre>'],
            '200 x 10 table'   => ['<table border="1">' . str_repeat('<tr>' . str_repeat('<td>buňka</td>', 10) . '</tr>', 200) . '</table>'],
            '4 900 inline'     => ['<p>' . str_repeat('<b>a</b> <i>b</i> ', 1225) . '</p>'],
            '42 pages of text' => [self::paragraphs(160, 40)],
            'wide grid'        => ['<table border="1"><tr><td colspan="199">a</td><td>b</td></tr>' . str_repeat('<tr><td>a</td><td>b</td></tr>', 499) . '</table>'],
        ];
    }

    // what dompdf keeps of a table is its grid, rows x columns, however few cells it has
    public function test_memory_ofTheGrid(): void
    {
        $wide = '<tr><td colspan="199">a</td><td>b</td></tr>';
        $rows = str_repeat('<tr><td>a</td><td>b</td></tr>', 399);

        $apart    = PdfBody::memory(self::doc("<table>$wide</table><table>$rows</table>"));
        $together = PdfBody::memory(self::doc("<table>$wide$rows</table>"));

        self::assertGreaterThan($apart + 399 * 198 * 256, $together, 'the same cells, 399 rows of 200 columns');
    }

    // a letter render() refuses unread asks for no memory_limit of terabytes first
    public function test_memory_ofRefusedLetterSmall(): void
    {
        $doc = self::doc('<table><tr><td colspan="1000" rowspan="65534">x</td></tr></table>');

        self::assertLessThanOrEqual(PdfBody::memory(self::doc('<p>x</p>')), PdfBody::memory($doc));
        self::assertSame(PdfBody::TOO_LONG, self::refusal($doc));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_memory_estimateOfImages(): void
    {
        $doc = self::doc(str_repeat('<p>' . self::img(1200, 900) . '</p>', 10));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        self::render($doc);

        self::assertLessThan(PdfBody::memory($doc), memory_get_peak_usage(true) - $before);
    }
}
