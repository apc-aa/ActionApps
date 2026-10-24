<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Body;

use AA\Isds\Body\HtmlBody;
use AA\Isds\File;
use AA\Isds\Message;
use AA\Upload\UploadDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlBodyTest extends TestCase
{
    private const URL  = 'https://example.org/img_upload/abc';
    private const SITE = 'https://www.zdravamesta.cz/akce/2026/index.html';
    /** 1x1, transparent */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        // _m() of the reasons Imgfunc gives
        require_once __DIR__ . '/../../fixtures/util_shims.php';
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob("$this->dir/*") ?: []);
            rmdir($this->dir);
        }
    }

    private function uploads(): UploadDir
    {
        $this->dir = sys_get_temp_dir() . '/isds-htmlbody-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        return new UploadDir(self::URL, $this->dir);
    }

    private static function needsGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not loaded');
        }
    }

    /** an image of $w x $h; $alpha leaves it transparent */
    private static function image(int $w, int $h, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) ($alpha ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, 200, 30, 30)));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** a PNG of $w x $h that is only a header: sized without decoding, and it cannot be decoded */
    private static function header(int $w, int $h): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        return 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)));
    }

    /** what the letter keeps of $body, between <body> and </body> */
    private static function body(string $body, ?HtmlBody $letter = null, bool $html = true, string $letterhead = ''): string
    {
        $document = ($letter ?? new HtmlBody())->document('Pozvánka', $body, $html, $letterhead);
        $start    = strpos($document, "<body>\n") + strlen("<body>\n");
        return substr($document, $start, strrpos($document, "\n</body>") - $start);
    }

    public function test_render_mainFileOfTheMessage(): void
    {
        $file = (new HtmlBody())->render('Pozvánka na setkání', '<p>Vážený pane starosto,</p>');

        self::assertSame(['Dopis.html', 'text/html', File::MAIN], [$file->name, $file->mimeType, $file->metaType]);
    }

    public function test_document_wholeUtf8Document(): void
    {
        $document = (new HtmlBody())->document('Pozvánka <na> setkání', '<p>Žluťoučký kůň úpěl ďábelské ódy</p>');

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"cs\">\n<head>\n<meta charset=\"utf-8\">\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">\n", $document);
        self::assertStringContainsString('<title>Pozvánka &lt;na&gt; setkání</title>', $document);
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'">', $document);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $document);
        self::assertMatchesRegularExpression('~<style>[^<]+</style>~', $document);
        self::assertStringEndsWith("<body>\n<p>Žluťoučký kůň úpěl ďábelské ódy</p>\n</body>\n</html>\n", $document);
    }

    #[DataProvider('notUtf8')]
    public function test_document_refusesTextNotUtf8(string $body, bool $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not UTF-8 text');
        (new HtmlBody())->document('Pozvánka', $body, $html);
    }

    public static function notUtf8(): array
    {
        return [
            'latin 1'           => ["<p>Vá\xE9ený</p>", true],
            'latin 1 as text'   => ["Vá\xE9ený", false],
            // libxml reads on past it, or not, as its version goes
            'NUL in a tag'      => ["<p>a</p><p\0>b</p>", true],
            'NUL in an end tag' => ["<p>a</p></body\0><p>b</p>", true],
            'NUL as text'       => ["a\0b", false],
        ];
    }

    // refused before the text is split, escaped or parsed: each of them copies it
    #[DataProvider('modes')]
    public function test_document_refusesLetterOverMessageSize(bool $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than a data message may be');
        (new HtmlBody())->document('Pozvánka', str_repeat('a', Message::MAX_BYTES + 1), $html);
    }

    public static function modes(): array
    {
        return ['HTML' => [true], 'plain text' => [false]];
    }

    // lines of text above the body in either of its formats, escaped
    #[DataProvider('modes')]
    public function test_document_letterheadAboveTheBody(bool $html): void
    {
        self::assertSame(
            "<p>Zdravá města &amp; &lt;b&gt;obce&lt;/b&gt;<br>\nPalackého 1</p>\n<p>Vážení</p>" . ($html ? '' : "\n"),
            self::body($html ? '<p>Vážení</p>' : 'Vážení', null, $html, " Zdravá města & <b>obce</b>\nPalackého 1\n"),
        );
    }

    #[DataProvider('empty')]
    public function test_document_letterheadAloneIsNoLetter(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        (new HtmlBody())->document('Pozvánka', $html, true, "Zdravá města\nPalackého 1");
    }

    #[DataProvider('notUtf8Letterheads')]
    public function test_document_refusesLetterheadNotUtf8(string $letterhead): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The letterhead is not UTF-8 text');
        (new HtmlBody())->document('Pozvánka', '<p>Vážení</p>', true, $letterhead);
    }

    public static function notUtf8Letterheads(): array
    {
        return ['latin 1' => ["Zdrav\xE1 m\xECsta"], 'NUL' => ["NSZM\0"]];
    }

    // checked before the UTF-8 of the body, so the letterhead counts in the size
    public function test_document_refusesLetterheadAndLetterOverMessageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than a data message may be');
        (new HtmlBody())->document('Pozvánka', "\xE9", false, str_repeat('b', Message::MAX_BYTES));
    }

    // libxml builds its tree outside memory_limit: 20 MB of tags or of lines would take gigabytes
    #[DataProvider('tagged')]
    public function test_document_refusesTooManyTags(string $body, bool $html, string $letterhead): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tags or lines');
        (new HtmlBody())->document('Pozvánka', $body, $html, $letterhead);
    }

    public static function tagged(): array
    {
        $lines = str_repeat("a\n", 200_001);
        return [
            'HTML'       => [str_repeat('<br>', 200_000) . '<p>a</p>', true, ''],
            'plain text' => [$lines, false, ''],
            'letterhead' => ['<p>a</p>', true, $lines],
        ];
    }

    public function test_document_takesTagsUpToTheBound(): void
    {
        self::assertStringEndsWith('<br><p>a</p>', self::body(str_repeat('<br>', 199_998) . '<p>a</p>'));
    }

    /** $count attributes a0, a1, ... parted by $gap */
    private static function attributes(int $count, string $gap = ' '): string
    {
        return implode($gap, array_map(static fn(int $i): string => "a$i", range(0, $count - 1)));
    }

    // libxml compares each attribute of a tag with all before it, outside memory_limit
    #[DataProvider('manyAttributes')]
    public function test_document_tooManyAttributes(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a tag of more than 1000 attributes');
        (new HtmlBody())->document('Pozvánka', $html);
    }

    public static function manyAttributes(): array
    {
        $over = self::attributes(1001);
        // libxml up to 2.13 reads 100 bytes of a name, the rest is the next attribute
        $long = implode('', array_map(static fn(int $i): string => 'a' . str_pad((string) $i, 99, 'x', STR_PAD_LEFT), range(1, 1001)));
        return [
            'in a tag'                    => ["<p $over>a</p>"],
            'with values'                 => ['<p ' . self::attributes(1001, '="1" ') . '>a</p>'],
            'after a quoted ">"'          => ['<p title=">" ' . self::attributes(1000) . '>a</p>'],
            'unclosed'                    => ["<p>a</p><p $over"],
            // libxml 2.12 ends <!--> at once, 2.9 at the next -->
            'in a comment'                => ["<!--><p $over>a</p>-->"],
            'after a quote in a comment'  => ["<!-- <b x=\"--><p $over>a</p>\" -->"],
            'after a quote in a script'   => ["<script>x = a<b, y=\"</script><p $over>a</p>\"</script>"],
            'in a value of no tag'        => ["<_x a=\"<p $over>\">a"],
            // libxml up to 2.13 reads the name a, then the rest as attributes
            'quoted after a strange name' => ["<p a\"b=\"$over\">a</p>"],
            'a long name'                 => ["<p $long>a</p>"],
            'a long tag name'             => ['<p' . str_repeat('q', 99) . "$long>a"],
            // HTML5 and libxml 2.14
            'parted by slashes'           => ['<p ' . self::attributes(1001, '/') . '>a</p>'],
            'of an end tag'               => ["<p>a</p $over>"],
        ];
    }

    public function test_document_takesAttributesUpToTheBound(): void
    {
        self::assertSame('<p>a</p>', self::body('<p ' . self::attributes(1000) . '>a</p>'));
    }

    // one tag of a thousand attributes took libxml 2 ms, 6 000 of them 15 s
    public function test_document_refusesTooManyAttributesTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 200000 attributes');
        (new HtmlBody())->document('Pozvánka', str_repeat('<p ' . self::attributes(1000) . '>a</p>', 201));
    }

    // 100 000 attributes of one tag took libxml 22 s
    public function test_document_refusesManyAttributesFast(): void
    {
        $html  = '<p ' . self::attributes(100_000) . '>a</p>';
        $start = hrtime(true);
        try {
            (new HtmlBody())->document('Pozvánka', $html);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('a tag of more than 1000 attributes', $e->getMessage());
        }
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
    }

    #[DataProvider('notAttributes')]
    public function test_document_attributesInQuotesNotCounted(string $html, string $expected): void
    {
        self::assertSame($expected, self::body($html));
    }

    public static function notAttributes(): array
    {
        $words = self::attributes(2000);
        return [
            'double quotes'         => ["<p title=\"$words\">a</p>", "<p title=\"$words\">a</p>"],
            'single quotes'         => ["<p title='$words'>a</p>", "<p title=\"$words\">a</p>"],
            'spaces around ='       => ["<p title = \"$words\">a</p>", "<p title=\"$words\">a</p>"],
            'a ">" in quotes'       => ["<p title=\"> $words\">a</p>", "<p title=\"&gt; $words\">a</p>"],
            'a comment'             => ["<!-- $words --><p>a</p>", '<p>a</p>'],
            // what Word puts into a pasted letter
            'styles in a comment'   => ['<style><!-- ' . str_repeat('p.MsoNormal {margin: 0cm; font-size: 11pt; mso-style-unhide: no} ', 400) . '--></style><p>a</p>', '<p>a</p>'],
            'a conditional comment' => ['<!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/></o:OfficeDocumentSettings></xml><![endif]--><p>a</p>', '<p>a</p>'],
        ];
    }

    // what escape() and the line ends would make of it is refused before it is built: at most one copy of
    // the text, never the 6 times larger HTML
    #[DataProvider('growing')]
    public function test_document_refusesWhatGrowsPastMessageSize(string $text, string $place): void
    {
        memory_reset_peak_usage();
        $before = memory_get_usage();
        try {
            match ($place) {
                'letterhead' => (new HtmlBody())->document('Pozvánka', '<p>a</p>', true, $text),
                'body'       => (new HtmlBody())->document('Pozvánka', $text, false),
                'HTML'       => (new HtmlBody())->document('Pozvánka', $text),
            };
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('larger than a data message may be', $e->getMessage());
        }
        self::assertLessThan(2 * strlen($text), memory_get_peak_usage() - $before);
    }

    public static function growing(): array
    {
        $over = static fn(string $what, int $grows): string => str_repeat($what, intdiv(Message::MAX_BYTES, $grows) + 1);
        return [
            'quotes in the body'           => [$over('"', 6), 'body'],
            'apostrophes in the body'      => [$over("'", 6), 'body'],
            'ampersands in the body'       => [$over('&', 5), 'body'],
            'angle brackets in the body'   => [$over('<', 4), 'body'],
            'line ends in the body'        => [$over("a\n", 3), 'body'],
            'line tabulations in the body' => [$over("a\v", 3), 'body'],
            'quotes in the letterhead'     => [$over('"', 6), 'letterhead'],
            'line ends in the letterhead'  => [$over("a\n", 3), 'letterhead'],
            'quotes in a text of HTML'     => ['<p>' . $over('"', 6) . '</p>', 'HTML'],
        ];
    }

    // a text PCRE gives up on must not vanish from the letter
    #[DataProvider('places')]
    public function test_document_refusesTextPcreCannotSplit(bool $letterhead): void
    {
        $text  = "a\n" . str_repeat(' ', 10_000) . 'b';
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('cannot be split');
            $letterhead ? (new HtmlBody())->document('Pozvánka', '<p>a</p>', true, $text) : (new HtmlBody())->document('Pozvánka', $text, false);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public static function places(): array
    {
        return ['letterhead' => [true], 'body' => [false]];
    }

    public function test_document_refusesSubjectOverLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HtmlBody())->document(str_repeat('ř', Message::MAX_ANNOTATION + 1), '<p>x</p>');
    }

    #[DataProvider('empty')]
    public function test_document_refusesEmptyLetter(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        (new HtmlBody())->document('Pozvánka', $html);
    }

    public static function empty(): array
    {
        return [
            'nothing'           => [''],
            'spaces'            => [" \n\t "],
            'empty paragraphs'  => ['<p>&nbsp;</p><p> </p><br>'],
            'only a script'     => ['<script>alert(1)</script>'],
            'only a web image'  => ['<p><img src="https://example.org/logo.png"></p>'],
            // what rich-text editors leave behind, invisible
            'zero-width space'  => ["<p>\u{200B}</p>"],
            'byte order mark'   => ["\u{FEFF}"],
            'soft hyphen'       => ['<p>&shy;</p>'],
            'word joiner'       => ["\u{2060}\u{2060}"],
            'Hangul filler'     => ["\u{3164}"],
            'blank Braille'     => ["\u{2800}"],
        ];
    }

    #[DataProvider('selectors')]
    public function test_document_variationSelectorIsNoText(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        (new HtmlBody())->document('Pozvánka', $html);
    }

    public static function selectors(): array
    {
        return [
            'variation selector'            => ["<p>\u{FE0F}</p>"],
            'variation selector supplement' => ["<p>\u{E0100}\u{E01EF}</p>"],
            'grapheme joiner'               => ["<p>\u{34F}</p>"],
            'C1 control character'          => ["<p>\u{80}\u{9F}</p>"],
            'Mongolian variation selectors' => ["<p>\u{180B}\u{180D}\u{180F}</p>"],
            'Khmer inherent vowels'         => ["<p>\u{17B4}\u{17B5}</p>"],
            // the five codes Windows-1252 leaves free
            'C1 reference'                  => ['<p>&#129;&#x9D;</p>'],
        ];
    }

    // libxml would cut the rest of the letter off without a word
    #[DataProvider('cutOff')]
    public function test_document_refusesWhatLibxmlCutsOff(string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be read whole');
        (new HtmlBody())->document('Pozvánka', $html);
    }

    public static function cutOff(): array
    {
        return [
            'deep nesting'      => [str_repeat('<div>', 300) . 'x' . str_repeat('</div>', 300) . '<p>po</p>'],
            'unclosed elements' => [str_repeat('<b>', 1000) . 'x<p>po</p>'],
        ];
    }

    // made here, not in a data provider: PHPUnit keeps those for the whole run
    public function test_document_refusesHugeTextNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be read whole');
        (new HtmlBody())->document('Pozvánka', '<p>' . str_repeat('a', 11_000_000) . '</p><p>po</p>');
    }

    // libxml 2.12 gives up on about 10 MB, mostly of pasted images; earlier versions read them
    public function test_document_refusesWhatLibxmlCannotLookUp(): void
    {
        if (LIBXML_VERSION < 21200) {
            self::markTestSkipped('libxml ' . LIBXML_DOTTED_VERSION . ' has no such limit');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large to process - upload the images');
        (new HtmlBody())->document('Pozvánka', '<p>a</p>' . str_repeat('<p><img src="data:image/png;base64,' . str_repeat('A', 3_600_000) . '"></p>', 3));
    }

    // libxml leaves out everything after </body> or </html>
    #[DataProvider('afterTheEnd')]
    public function test_document_keepsWhatFollowsTheEnd(string $html, string $expected): void
    {
        self::assertSame($expected, self::body($html));
    }

    public static function afterTheEnd(): array
    {
        return [
            'after </html>'       => ['<p>a</p></body></html><p>b</p>konec', '<p>a</p><p>b</p>konec'],
            'end tags with more'  => ['<p>a</p></html lang="cs"><p>b</p></BODY/><p>c</p>', '<p>a</p><p>b</p><p>c</p>'],
            // libxml too drops what follows
            'end tag left open'   => ['<p>a</p></body text bez konce', '<p>a</p>'],
            'end tag at the end'  => ['<p>a</p></body', '<p>a</p>'],
            'second body'         => ['<p>a</p></body><body><p>b</p></body>', '<p>a</p><p>b</p>'],
            'whole document'      => ['<html><head><title>t</title></head><body><p>b</p></body></html><p>c</p>', '<p>b</p><p>c</p>'],
        ];
    }

    /** the letter markup survives as it was */
    public function test_document_keepsLetterMarkup(): void
    {
        $html = '<h1>Pozvánka</h1><h2 align="center">Program</h2><p>Text <strong>tučně</strong>, <em>kurzívou</em>, <u>podtrženě</u>, <b>b</b> <i>i</i> <s>s</s> H<sub>2</sub>O m<sup>2</sup><br>nový řádek</p>'
            . '<p>Cena <del>500 Kč</del> <ins>400 Kč</ins>, <mark>nově</mark></p>'
            . '<ul><li>jedna</li><li>dvě</li></ul><ol start="3" type="a"><li>tři</li></ol><blockquote>citace</blockquote><hr>'
            . '<table border="1" cellpadding="2" bgcolor="#eeeeee"><thead><tr><th colspan="2" scope="col">Hlavička</th></tr></thead><tbody><tr valign="top"><td rowspan="2" width="50%" align="justify">a</td><td>b</td></tr></tbody></table>'
            . '<p>Odkazy <a href="https://www.zdravamesta.cz/akce?id=1&amp;b=2" title="Akce">web</a>, <a href="HTTP://EXAMPLE.ORG/">http</a> a <a href="mailto:info@nszm.cz">e-mail</a></p>';

        $letter = new HtmlBody();

        self::assertSame($html, self::body($html, $letter));
        self::assertSame([], $letter->omitted());
    }

    #[DataProvider('dropped')]
    public function test_document_dropsActiveContent(string $html, string $expected, string $omitted): void
    {
        $letter = new HtmlBody();

        self::assertSame($expected, self::body($html, $letter));
        self::assertSame([$omitted], $letter->omitted());
    }

    public static function dropped(): array
    {
        return [
            'script'              => ['<p>a</p><script>alert(1)</script><p>b</p>', '<p>a</p><p>b</p>', '<script> and what it holds'],
            'script in capitals'  => ['<p>a<SCRIPT type="text/javascript">alert(1)</SCRIPT></p>', '<p>a</p>', '<script> and what it holds'],
            'iframe twice'        => ['<p>a</p><iframe src="https://evil.example/">x</iframe><iframe></iframe>', '<p>a</p>', '<iframe> and what it holds'],
            'object and embed'    => ['<object data="x.swf"><embed src="x.swf"></object><p>a</p>', '<p>a</p>', '<object> and what it holds'],
            'video'               => ['<video src="x.mp4"><source src="x.webm"></video><p>a</p>', '<p>a</p>', '<video> and what it holds'],
            'svg with a script'   => ['<svg><script>alert(1)</script><text>x</text></svg><p>a</p>', '<p>a</p>', '<svg> and what it holds'],
            'prefixed script'     => ['<p>a</p><svg:script>alert(1)</svg:script><p>b</p>', '<p>a</p><p>b</p>', '<script> and what it holds'],
            'button in a form'    => ['<form action="https://evil.example/"><p>a</p><button>x</button></form>', '<p>a</p>', '<button> and what it holds'],
            'unclosed script'     => ['<p>a</p><script>alert(1)', '<p>a</p>', '<script> and what it holds'],
            // libxml puts the rest of the letter into <embed> or <image>: left out, not lost
            'embed'               => ['<p>Úvod</p><embed src="v.swf"><p>Zbytek</p>', '<p>Úvod</p><p>Zbytek</p>', '<embed>'],
            'embed in a sentence' => ['<p>Úvod <embed src="v.swf"> věta</p><p>Podpis</p>', '<p>Úvod  věta</p><p>Podpis</p>', '<embed>'],
            'image'               => ['<p>a <image src="' . self::header(1, 1) . '"> b</p><p>c</p>', '<p>a  b</p><p>c</p>', '<image>'],
            'input'               => ['<p>Jméno: <input type="text" value="Jan"></p>', '<p>Jméno: </p>', '<input>'],
        ];
    }

    #[DataProvider('quiet')]
    public function test_document_dropsWithoutLoss(string $html, string $expected): void
    {
        $letter = new HtmlBody();

        self::assertSame($expected, self::body($html, $letter));
        self::assertSame([], $letter->omitted());
    }

    public static function quiet(): array
    {
        return [
            'comment'             => ['<p>a<!-- x --></p>', '<p>a</p>'],
            'conditional comment' => ['<!--[if IE]><script>alert(1)</script><![endif]--><p>a</p>', '<p>a</p>'],
            'meta refresh'        => ['<meta http-equiv="refresh" content="0;url=https://evil.example/"><p>a</p>', '<p>a</p>'],
            'base and link'       => ['<base href="https://evil.example/"><link rel="stylesheet" href="https://evil.example/x.css"><p>a</p>', '<p>a</p>'],
            // a pasted page has them for the browser, not the reader
            'style element'       => ['<style>p{background:url(https://evil.example/x)}</style><p>a</p>', '<p>a</p>'],
            'prefixed style'      => ['<svg:style>p{color:red}</svg:style><p>a</p>', '<p>a</p>'],
            'title and head'      => ['<head><title>Dopis</title><meta name="author" content="x"></head><p>a</p><title>t</title>', '<p>a</p>'],
            'onclick'             => ['<p onclick="alert(1)">a</p>', '<p>a</p>'],
            'onload on body'      => ['<body onload="alert(1)"><p>a</p></body>', '<p>a</p>'],
            'id and class'        => ['<p id="x" class="y" data-x="z">a</p>', '<p>a</p>'],
            'title kept'          => ['<p title="O &quot;nás&quot;">a</p>', '<p title="O &quot;nás&quot;">a</p>'],
            'lang and dir'        => ['<p lang="en-GB" dir="ltr">a</p><p lang="x y" dir="sideways">b</p>', '<p lang="en-GB" dir="ltr">a</p><p>b</p>'],
            'bad numbers'         => ['<table><tr><td colspan="2;x" rowspan="-1" width="50%" height="expression(1)">a</td></tr></table>', '<table><tr><td width="50%">a</td></tr></table>'],
            'bad legacy values'   => ['<p align="middle">a</p><table bgcolor="red;x"><tr valign="center"><td>b</td></tr></table>', '<p>a</p><table><tr><td>b</td></tr></table>'],
            'names in capitals'   => ['<P STYLE="COLOR: RED" TITLE="T">x</P>', '<p style="color: RED" title="T">x</p>'],
            'strike'              => ['<strike>x</strike>', '<s>x</s>'],
            'font'                => ['<p><font color="red" face="Arial">červeně</font></p>', '<p>červeně</p>'],
            'center'              => ['<center>na střed</center>', 'na střed'],
            'section'             => ['<section><article><p>a</p></article></section>', '<p>a</p>'],
            'custom element'      => ['<aa-richtext><p>a</p></aa-richtext>', '<p>a</p>'],
            'nbsp'                => ['<p>a&nbsp;b</p>', "<p>a\u{A0}b</p>"],
        ];
    }

    /** the text of an escaped tag stays text - never markup */
    public function test_document_escapesText(): void
    {
        self::assertSame('<p>&lt;script&gt;x(1)&lt;/script&gt; &amp; &quot;uvozovky&quot; &#039;apostrof&#039;</p>', self::body('<p>&lt;script&gt;x(1)&lt;/script&gt; &amp; "uvozovky" \'apostrof\'</p>'));
    }

    // the wrapper declares UTF-8 first: a charset the letter declares does not turn text into markup
    public function test_document_ignoresCharsetOfTheLetter(): void
    {
        $body = self::body('<meta charset="utf-7"><p>+ADw-b+AD4-x+ADw-/b+AD4- ž</p>');

        self::assertSame('<p>+ADw-b+AD4-x+ADw-/b+AD4- ž</p>', $body);
    }

    // Word paragraph marks: <o:p> is a <p> on libxml 2.9, an unknown element on later ones
    public function test_document_noParagraphInAParagraph(): void
    {
        self::assertSame("<p>Dobrý den</p><p>x\u{A0}</p>", self::body('<p class="MsoNormal">Dobrý den<o:p></o:p></p><p>x<o:p>&nbsp;</o:p></p>'));
    }

    public function test_document_windows1252References(): void
    {
        self::assertSame('<p>„uvozovky“ a–b €</p>', self::body('<p>&#132;uvozovky&#147; a&#150;b &#x80;</p>'));
    }

    public function test_document_plainText(): void
    {
        self::assertSame(
            "<p>Vážený pane starosto,</p>\n<p>řádek 1<br>\nřádek 2 &amp; &lt;3 &amp;nbsp; &lt;https://www.nszm.cz/&gt; &lt;b&gt;x&lt;/b&gt;</p>\n",
            self::body("Vážený pane starosto,\r\n\r\n  \nřádek 1\nřádek 2 & <3 &nbsp; <https://www.nszm.cz/> <b>x</b>\n", null, false),
        );
    }

    // only the caller knows which it is: text without a tag is HTML too, its blank lines no paragraphs
    public function test_document_textWithoutTagsIsHtml(): void
    {
        self::assertSame("Dobrý den,\n\na &lt;b&gt; zůstane", self::body("Dobrý den,\n\na &lt;b&gt; zůstane"));
    }

    #[DataProvider('links')]
    public function test_document_keepsWebAndMailLinks(string $html, string $expected, string $omitted): void
    {
        $letter = new HtmlBody();

        self::assertSame($expected, self::body($html, $letter));
        self::assertSame(($omitted === '') ? [] : [$omitted], $letter->omitted());
    }

    public static function links(): array
    {
        return [
            'javascript'            => ['<a href="javascript:alert(1)">a</a>', 'a', 'the link javascript:alert(1) is not to a web page or an e-mail address'],
            'javascript capitals'   => ['<a href="JaVaScRiPt:alert(1)">a</a>', 'a', 'the link JaVaScRiPt:alert(1) is not to a web page or an e-mail address'],
            'javascript entities'   => ['<a href="&#106;avascript:alert(1)">a</a>', 'a', 'the link javascript:alert(1) is not to a web page or an e-mail address'],
            // a browser drops the tab, a relative link may contain anything: both make no script
            'javascript with a tab' => ["<a href=\"java\tscript:alert(1)\">a</a>", 'a', "the link java\tscript:alert(1) is relative, and no web site is given to lead from"],
            'vbscript'              => ['<a href="vbscript:msgbox(1)">a</a>', 'a', 'the link vbscript:msgbox(1) is not to a web page or an e-mail address'],
            'data'                  => ['<a href="data:text/html;base64,PHNjcmlwdD4=">a</a>', 'a', 'the link data:text/html;base64,PHNjcmlwdD4= is not to a web page or an e-mail address'],
            'anchor'                => ['<a href="#a" name="a">a</a>', 'a', 'the link #a leads within a web page, the letter is none'],
            'relative'              => ['<a href="/kontakt">a</a>', 'a', 'the link /kontakt is relative, and no web site is given to lead from'],
            'https without a host'  => ['<a href="https:evil">a</a>', 'a', 'the link https:evil has no web site'],
            'user name'             => ['<a href="https://www.zdravamesta.cz@evil.example/">a</a>', 'a', 'the link https://www.zdravamesta.cz@evil.example/ hides its web site behind a name'],
            'empty'                 => ['<a href="">a</a>', 'a', ''],
            'https trimmed'         => ['<a href=" https://example.org/ ">a</a>', '<a href="https://example.org/">a</a>', ''],
            'target dropped'        => ['<a href="https://example.org/" target="_blank">a</a>', '<a href="https://example.org/">a</a>', ''],
        ];
    }

    #[DataProvider('relative')]
    public function test_document_resolvesRelativeLinks(string $href, string $expected): void
    {
        self::assertSame("<a href=\"$expected\">a</a>", self::body("<a href=\"$href\">a</a>", new HtmlBody(null, self::SITE)));
    }

    public static function relative(): array
    {
        return [
            'root'              => ['/kontakt', 'https://www.zdravamesta.cz/kontakt'],
            'sibling'           => ['program.html', 'https://www.zdravamesta.cz/akce/2026/program.html'],
            'parent'            => ['../2025/', 'https://www.zdravamesta.cz/akce/2025/'],
            'above the root'    => ['a/../../../../b', 'https://www.zdravamesta.cz/b'],
            'the directory'     => ['.', 'https://www.zdravamesta.cz/akce/2026/'],
            'query'             => ['?rok=2026', 'https://www.zdravamesta.cz/akce/2026/index.html?rok=2026'],
            'protocol relative' => ['//example.org/x', 'https://example.org/x'],
        ];
    }

    public function test_document_anchorStaysOutWithASite(): void
    {
        $letter = new HtmlBody(null, self::SITE);

        self::assertSame('a', self::body('<a href="#program">a</a>', $letter));
        self::assertSame(['the link #program leads within a web page, the letter is none'], $letter->omitted());
    }

    #[DataProvider('notSites')]
    public function test_construct_refusesBase(string $base): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HtmlBody(null, $base);
    }

    public static function notSites(): array
    {
        return [
            'relative' => ['/akce/'],
            'ftp'      => ['ftp://example.org/'],
            'no host'  => ['https:///akce'],
        ];
    }

    #[DataProvider('styles')]
    public function test_document_keepsPlainStylesOnly(string $style, string $expected): void
    {
        self::assertSame($expected, self::body("<p style=\"$style\">a</p>"));
    }

    public static function styles(): array
    {
        return [
            'alignment and colour'   => ['text-align: center; color: #ff0000', '<p style="text-align: center; color: #ff0000">a</p>'],
            'rgb'                    => ['color: rgb(255, 0, 0);', '<p style="color: rgb(255, 0, 0)">a</p>'],
            'indent from the editor' => ['PADDING-LEFT: 40px', '<p style="padding-left: 40px">a</p>'],
            'layout'                 => ['float: left; display: block; margin: 0 auto; margin-top: 1em; margin-bottom: 0', '<p style="float: left; display: block; margin: 0 auto; margin-top: 1em; margin-bottom: 0">a</p>'],
            'background colour'      => ['background: #eee', '<p style="background: #eee">a</p>'],
            'background image'       => ['background: #eee url(https://evil.example/x.png); color: red', '<p style="color: red">a</p>'],
            'url in a value'         => ['color: url(https://evil.example/x)', '<p>a</p>'],
            'expression'             => ['width: expression(alert(1))', '<p>a</p>'],
            'escape'                 => ['color: \\72 ed', '<p>a</p>'],
            'comment'                => ['color: red/**/; font-weight: bold', '<p style="font-weight: bold">a</p>'],
            'quotes'                 => ["font-size: '12px'", '<p>a</p>'],
            'position'               => ['position: fixed; top: 0', '<p>a</p>'],
            'import'                 => ['@import url(x.css); color: red', '<p style="color: red">a</p>'],
            'value of 100'           => ['margin: 10' . str_repeat(' 0', 49), '<p style="margin: 10' . str_repeat(' 0', 49) . '">a</p>'],
            'value over 100'         => ['margin: 100' . str_repeat(' 0', 49), '<p>a</p>'],
        ];
    }

    #[DataProvider('displays')]
    public function test_document_displayAllowList(string $display, string $expected): void
    {
        self::assertSame($expected, self::body("<p style=\"display: $display; color: red\">a</p>"));
    }

    public static function displays(): array
    {
        $kept    = static fn(string $display): string => "<p style=\"display: $display; color: red\">a</p>";
        $dropped = '<p style="color: red">a</p>';
        return [
            'block'         => ['block', $kept('block')],
            'inline'        => ['inline', $kept('inline')],
            'inline-block'  => ['inline-block', $kept('inline-block')],
            'list-item'     => ['list-item', $kept('list-item')],
            'none'          => ['NONE', $kept('none')],
            'table-cell'    => ['table-cell', $dropped],
            'table'         => ['table', $dropped],
            'flex'          => ['flex', $dropped],
            'of dompdf'     => ['-dompdf-image', $dropped],
            'two of them'   => ['block inline', $dropped],
        ];
    }

    #[DataProvider('tableParts')]
    public function test_document_displayOfATableOnlyNone(string $tag, string $display, string $expected): void
    {
        $html = "<$tag style=\"display: $display; color: red\"><td>a</td></$tag>";
        if ($tag !== 'table') {
            $html = "<table>$html</table>";
        }
        self::assertStringContainsString($expected, self::body($html));
    }

    public static function tableParts(): array
    {
        return [
            'table block'  => ['table', 'block', '<table style="color: red">'],
            'tbody inline' => ['tbody', 'inline', '<tbody style="color: red">'],
            'tr list-item' => ['tr', 'list-item', '<tr style="color: red">'],
            'tr none'      => ['tr', 'none', '<tr style="display: none; color: red">'],
        ];
    }

    #[DataProvider('spans')]
    public function test_document_spanClamped(string $html, string $expected): void
    {
        self::assertSame("<table>$expected<tr><td>a</td></tr></table>", self::body("<table>$html<tr><td>a</td></tr></table>"));
    }

    public static function spans(): array
    {
        return [
            'within'           => ['<tr><td colspan="3" rowspan="2">b</td></tr>', '<tr><td colspan="3" rowspan="2">b</td></tr>'],
            'too wide'         => ['<tr><td colspan="5000">b</td></tr>', '<tr><td colspan="1000">b</td></tr>'],
            'too high'         => ['<tr><th rowspan="70000">b</th></tr>', '<tr><th rowspan="65534">b</th></tr>'],
            'past an integer'  => ['<tr><td colspan="99999999999999999999999">b</td></tr>', '<tr><td colspan="1000">b</td></tr>'],
            'leading zeros'    => ['<tr><td colspan="007">b</td></tr>', '<tr><td colspan="7">b</td></tr>'],
            'zero'             => ['<tr><td colspan="0" rowspan="0">b</td></tr>', '<tr><td>b</td></tr>'],
            'not a number'     => ['<tr><td colspan="-2" rowspan="2x">b</td></tr>', '<tr><td>b</td></tr>'],
            'columns too wide' => ['<colgroup span="2000"><col span="1001"></colgroup>', '<colgroup span="1000"><col span="1000"></colgroup>'],
            'no columns'       => ['<col span="0">', '<col>'],
        ];
    }

    #[DataProvider('foreignImages')]
    public function test_document_leavesOutImagesFromElsewhere(string $src, string $omitted): void
    {
        $letter = new HtmlBody($this->uploads());

        self::assertSame('<p>a</p>', self::body("<p>a<img src=\"$src\" alt=\"x\"></p>", $letter));
        self::assertSame([$omitted], $letter->omitted());
    }

    public static function foreignImages(): array
    {
        return [
            'web'               => ['https://evil.example/pixel.png', 'the image https://evil.example/pixel.png is not stored with the letter'],
            'protocol relative' => ['//evil.example/pixel.png', 'the image //evil.example/pixel.png is not stored with the letter'],
            'svg not base64'    => ['data:image/svg+xml,%3Csvg%3E%3C/svg%3E', 'the image data:image/svg+xml,%3Csvg%3E%3C/svg%3E is not stored with the letter'],
            'not base64'        => ['data:image/png;base64,***', 'an image written into the letter is broken'],
            'svg'               => ['data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'an image written into the letter: This type of image cannot be uploaded here'],
            'text named png'    => ['data:image/png;base64,' . base64_encode('<script>alert(1)</script>'), 'an image written into the letter: This type of image cannot be uploaded here'],
        ];
    }

    public function test_document_withoutUploadsLeavesUploadedImageOut(): void
    {
        $letter = new HtmlBody();

        self::assertSame('<p>a</p>', self::body('<p>a<img src="' . self::URL . '/logo.png"></p>', $letter));
        self::assertSame(['the image ' . self::URL . '/logo.png is not stored with the letter'], $letter->omitted());
    }

    // a small file can declare a huge canvas: refused before anything is decoded
    public function test_document_leavesOutImageOfTooManyPixels(): void
    {
        $ihdr   = 'IHDR' . pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
        $bomb   = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
        $letter = new HtmlBody();

        self::assertSame('<p>a</p>', self::body('<p>a<img src="data:image/png;base64,' . base64_encode($bomb) . '"></p>', $letter));
        self::assertSame(['an image written into the letter: The image has too many pixels'], $letter->omitted());
    }

    // drawn again: of the letter's own bytes; written once however often shown
    public function test_document_drawsDataImageAgain(): void
    {
        self::needsGd();
        $png  = 'data:image/png;base64,' . self::PNG;
        $body = self::body("<p><img src=\"$png\" alt=\"\"><img src=\"data:image/gif;name=a.png;base64,\n" . chunk_split(self::PNG, 20, "\n") . '"></p>');

        self::assertSame(2, preg_match_all('~<img src="data:image/png;base64,([A-Za-z0-9+/=]+)"~', $body, $m));
        self::assertSame($m[1][0], $m[1][1]);
        self::assertStringContainsString(' alt=""', $body);
        self::assertSame([1, 1, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring(base64_decode($m[1][0])), 0, 3));
    }

    /** a 20 x 10 PNG: white at x 0-9, transparent at x 10-19 - a white logo cut out */
    private static function whiteLogo(): string
    {
        $img = imagecreatetruecolor(20, 10);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 0, 0, 9, 9, (int) imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    // alpha would cost a PDF seconds: the image is drawn on what shows behind it, so a white logo stays seen
    #[DataProvider('backgrounds')]
    public function test_document_transparentImageOnItsBackground(string $before, string $after, int $background): void
    {
        self::needsGd();
        $letter = new HtmlBody();

        $body = self::body($before . '<img src="' . self::whiteLogo() . '">' . $after, $letter);

        self::assertSame(1, preg_match('~<img src="data:image/png;base64,([A-Za-z0-9+/=]+)">~', $body, $m));
        $image = imagecreatefromstring(base64_decode($m[1]));
        self::assertNotFalse($image);
        self::assertSame([0xFFFFFF, $background], [imagecolorat($image, 2, 5), imagecolorat($image, 17, 5)]);
        self::assertSame([], $letter->omitted());
    }

    public static function backgrounds(): array
    {
        $cell = static fn(string $td): array => ["<table><tr>$td", '</td></tr></table>'];
        return [
            'the page'                        => ['<p>', '</p>', 0xFFFFFF],
            'bgcolor of a cell'               => [...$cell('<td bgcolor="#003366">'), 0x003366],
            'bgcolor by name'                 => [...$cell('<td bgcolor="Navy">'), 0x000080],
            'bgcolor of the table'            => ['<table bgcolor="#036"><tr><td>', '</td></tr></table>', 0x003366],
            'background-color as rgb()'       => ['<div style="background-color:rgb(0, 51, 102)">', '</div>', 0x003366],
            'colour in the shorthand'         => ['<p style="background:#003366 none">', '</p>', 0x003366],
            'style before bgcolor'            => [...$cell('<td bgcolor="#ff0000" style="background-color:#003366">'), 0x003366],
            'transparent shows the one above' => ['<div style="background:#003366"><p style="background-color:transparent">', '</p></div>', 0x003366],
            'unread colour shows the page'    => [...$cell('<td style="background-color:darkblue">'), 0xFFFFFF],
            'bgcolor a div has not'           => ['<div bgcolor="#003366">', '</div>', 0xFFFFFF],
            'tag the letter leaves out'       => ['<center style="background:#003366">', '</center>', 0xFFFFFF],
            'after a coloured block'          => ['<div style="background:#003366"><p>a</p></div><p>', '</p>', 0xFFFFFF],
        ];
    }

    public function test_document_drawsUploadedImageAgain(): void
    {
        self::needsGd();
        $uploads = $this->uploads();
        file_put_contents("$this->dir/foto.png", self::image(3000, 1500) . 'GPS 50.08N 14.42E');
        $letter = new HtmlBody($uploads);

        $body = self::body('<p><img src="' . self::URL . '/foto.png" alt="Foto"><img src="/img_upload/abc/foto.png"></p>', $letter);

        self::assertSame(2, preg_match_all('~<img src="data:image/jpeg;base64,([A-Za-z0-9+/=]+)" ?(?:alt="Foto")?>~', $body, $m));
        $image = base64_decode($m[1][0]);
        self::assertSame([1600, 800], array_slice((array) getimagesizefromstring($image), 0, 2));
        self::assertStringNotContainsString('GPS', $image);
        self::assertSame([], $letter->omitted());
    }

    // a stored image that cannot be read is a fault of the server, not of the letter
    public function test_document_failsOnUnreadableUpload(): void
    {
        $uploads = $this->uploads();
        mkdir("$this->dir/logo.png");

        try {
            $this->expectException(\RuntimeException::class);
            (new HtmlBody($uploads))->document('Pozvánka', '<p>a<img src="' . self::URL . '/logo.png"></p>');
        } finally {
            rmdir("$this->dir/logo.png");
        }
    }

    // one image used many times is decoded once: counted once towards MAX_PIXELS
    public function test_document_drawsAnImageOnce(): void
    {
        self::needsGd();
        $letter = new HtmlBody();

        self::assertSame('<p>a</p>', self::body('<p>a' . str_repeat('<img src="' . self::header(4000, 4000) . '">', 13) . '</p>', $letter));
        self::assertSame(['an image written into the letter: The image cannot be read'], $letter->omitted());
    }

    // every image is decoded whole before it is drawn smaller: counted from its header beforehand
    public function test_document_refusesImagesOfTooManyPixelsTogether(): void
    {
        self::needsGd();
        $count = intdiv(HtmlBody::MAX_PIXELS, 4000 * 3990) + 1;
        $html  = '<p>a</p>';
        for ($i = 0; $i < $count; $i++) {
            $html .= '<p><img src="' . self::header(4000, 3990 + $i) . '"></p>';
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('million pixels together');
        (new HtmlBody())->document('Pozvánka', $html);
    }

    public function test_document_countsPixelsOfEachLetterAlone(): void
    {
        self::needsGd();
        $html = '<p>a</p>';
        for ($i = 0; $i < intdiv(HtmlBody::MAX_PIXELS, 2 * 4000 * 4000) + 1; $i++) {
            $html .= '<p><img src="' . self::header(4000, 3990 + $i) . '"></p>';
        }
        $letter = new HtmlBody();
        self::body($html, $letter);

        self::assertStringStartsWith('<p>a</p>', self::body($html, $letter), 'the second letter is not refused');
    }

    // drawn or not, an image leaves no file behind
    public function test_document_leavesNoTemporaryFiles(): void
    {
        self::needsGd();
        $before = glob(sys_get_temp_dir() . '/aa-letter-*') ?: [];

        self::body('<p><img src="data:image/png;base64,' . self::PNG . '"><img src="' . self::header(20, 20) . '"></p>');

        self::assertSame($before, glob(sys_get_temp_dir() . '/aa-letter-*') ?: []);
    }

    public function test_document_refusesOverMessageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than a data message may be');
        // every quote is written as &quot;: six bytes of the letter for one of the input
        (new HtmlBody())->document('Pozvánka', str_repeat('<p>' . str_repeat('"', 1_000_000) . '</p>', 4));
    }

    // one image shown many times is drawn once, but written each time
    public function test_document_refusesImagesOverMessageSize(): void
    {
        self::needsGd();
        $uploads = $this->uploads();
        // noise does not compress: the image stays megabytes
        $img = imagecreatetruecolor(1000, 1000);
        for ($y = 0; $y < 1000; $y++) {
            for ($x = 0; $x < 1000; $x++) {
                imagesetpixel($img, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        imagepng($img, "$this->dir/sum.png");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than a data message may be');
        (new HtmlBody($uploads))->document('Pozvánka', str_repeat('<p><img src="' . self::URL . '/sum.png"></p>', 40));
    }

    public function test_omitted_onlyOfTheLastLetter(): void
    {
        $letter = new HtmlBody();
        self::body('<p>a</p><iframe></iframe>', $letter);

        self::body('<p>b</p>', $letter);

        self::assertSame([], $letter->omitted());
    }

    // the letter passes the check ISDS makes of every HTML file, whatever it was written from
    public function test_render_passesTheHtmlCheck(): void
    {
        $file = (new HtmlBody())->render('<script>', '<p>&lt;script&gt;</p><script>alert(1)</script><iframe></iframe><object></object>');

        foreach (['<script', '<iframe', '<object'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $file->content);
        }
    }
}
