<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\File;
use AA\Isds\Letter;
use AA\Isds\Message;
use AA\Isds\Schema;
use AA\Upload\UploadDir;
use ItemContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\PdfText;

require_once __DIR__ . '/fixtures/PdfText.php';

/** The letter of a campaign as a data message carries it - refused here when ISDS would refuse it for everyone */
final class LetterTest extends TestCase
{
    private const URL = 'https://example.org/img_upload/abc';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        // FLAG_HTML: util.php brings constants.php
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/isds-letter-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents("$this->dir/pozvanka.pdf", self::PDF);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $over field => its value, or a list of values; $html: FLAG_HTML on the body and the header
     * @param ?\Closure(int): string $makeRoom memory for the PDF, there always is by default
     */
    private function letter(array $over = [], bool $html = true, ?\Closure $makeRoom = null): Letter
    {
        $fields  = $over + [Schema::CAMPAIGN_SUBJECT => ' Pozvánka ', Schema::CAMPAIGN_BODY => '<p>Vážení, <b>zveme</b> Vás</p>', Schema::CAMPAIGN_TO_HANDS => 'starosta', Schema::CAMPAIGN_FILES => self::URL . '/pozvanka.pdf'];
        $content = [];
        foreach ($fields as $field => $values) {
            foreach ((array) $values as $value) {
                $content[$field][] = ['value' => $value, 'flag' => (in_array($field, [Schema::CAMPAIGN_BODY, Schema::CAMPAIGN_HEADER], true) && $html) ? FLAG_HTML : 0];
            }
        }
        return Letter::of(new ItemContent($content), new UploadDir(self::URL, $this->dir), $makeRoom ?? static fn(int $bytes): string => '');
    }

    /** the text of the letter as its PDF shows it, a line of each run of text */
    private static function body(Letter $letter): string
    {
        return PdfText::of($letter->files[0]->content);
    }

    public function test_of_letterThenAttachments(): void
    {
        $letter = $this->letter();

        self::assertSame(['Pozvánka', 'starosta'], [$letter->annotation, $letter->toHands]);
        self::assertSame([[PdfBody::NAME, File::MAIN], ['pozvanka.pdf', File::ENCLOSURE]], array_map(static fn(File $f): array => [$f->name, $f->metaType], $letter->files));
        self::assertSame('application/pdf', $letter->files[0]->mimeType);
        self::assertSame(self::PDF, $letter->files[1]->content);
        self::assertSame("Vážení, \nzveme\n Vás\n", self::body($letter));
    }

    // what a test message compares: the document the PDF was made of and the footer, not its bytes
    public function test_of_sourceOfTheDocument(): void
    {
        $footer   = ['NSZM, Praha'];
        $document = (new HtmlBody(new UploadDir(self::URL, $this->dir)))->document('Pozvánka', '<p>Vážení, <b>zveme</b> Vás</p>', true, '<p>NSZM</p>', PdfBody::css($footer));

        self::assertSame(PdfBody::source($document, $footer), $this->letter([Schema::CAMPAIGN_HEADER => '<p>NSZM</p>', Schema::CAMPAIGN_FOOTER => ' NSZM, Praha '])->source);
        self::assertNotSame(PdfBody::source($document, $footer), $this->letter([Schema::CAMPAIGN_HEADER => '<p>NSZM</p>', Schema::CAMPAIGN_FOOTER => 'NSZM, Brno'])->source, 'another footer');
    }

    // the memory dompdf will take is asked for before it starts, from the document
    public function test_of_memoryAskedBeforeThePdf(): void
    {
        $asked = [];

        try {
            $this->letter(makeRoom: static function (int $bytes) use (&$asked): string {
                $asked[] = $bytes;
                return 'mb:300';
            });
            self::fail('No letter without memory');
        } catch (\OverflowException $e) {
            self::assertSame('mb:300', $e->getMessage());
        }
        self::assertCount(1, $asked);
        self::assertGreaterThan(20 * 1024 * 1024, $asked[0]);
    }

    #[DataProvider('blanks')]
    public function test_of_skipsBlankAttachment(string $blank): void
    {
        $files = $this->letter([Schema::CAMPAIGN_FILES => [$blank, self::URL . '/pozvanka.pdf', $blank]])->files;

        self::assertSame([PdfBody::NAME, 'pozvanka.pdf'], array_map(static fn(File $f): string => $f->name, $files));
    }

    public static function blanks(): array
    {
        return ['empty' => [''], 'space' => [' ']];
    }

    // the header above the body in its format, the footer below it on the page
    public function test_of_headerAboveFooterBelowTheBody(): void
    {
        $over = [Schema::CAMPAIGN_HEADER => '<p><b>Zdravá města</b> &amp; obce</p>', Schema::CAMPAIGN_FOOTER => "Palackého 1\nPraha"];

        self::assertSame("Zdravá města\n & obce\nVážení, \nzveme\n Vás\nPalackého 1\nPraha\n", self::body($this->letter($over)));
        self::assertSame("<b>NSZM</b>\nVážení,\nzveme Vás\n", self::body($this->letter([Schema::CAMPAIGN_HEADER => '<b>NSZM</b>', Schema::CAMPAIGN_BODY => "Vážení,\nzveme Vás"], false)), 'plain text');
    }

    #[DataProvider('flags')]
    public function test_of_bodyFormatByFlag(bool $html, string $body): void
    {
        self::assertSame($body, self::body($this->letter([Schema::CAMPAIGN_BODY => "Vážení,\n<b>zveme</b> Vás"], $html)));
    }

    public static function flags(): array
    {
        return [
            'HTML'       => [true, "Vážení, \nzveme\n Vás\n"],
            'plain text' => [false, "Vážení,\n<b>zveme</b> Vás\n"],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_of_refusesAttachment(string $name, string $message): void
    {
        file_put_contents("$this->dir/$name", self::PDF);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->letter([Schema::CAMPAIGN_FILES => self::URL . '/' . rawurlencode($name)]);
    }

    public static function forbidden(): array
    {
        return [
            'program'           => ['setup.exe', '"setup.exe" is not of a type'],
            'page'              => ['stranka.html', 'HTML file "stranka.html"'],
            'archive'           => ['prilohy.zip', 'archive "prilohy.zip"'],
            'not what it says'  => ['dopis.docx', 'not .docx'],
        ];
    }

    #[DataProvider('limits')]
    public function test_of_refusesOverLimits(array $over, string $message, bool $html = true): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->letter($over, $html);
    }

    public static function limits(): array
    {
        return [
            'no subject'           => [[Schema::CAMPAIGN_SUBJECT => ' '], 'The subject is empty'],
            'invisible subject'    => [[Schema::CAMPAIGN_SUBJECT => "\u{200B}\u{2061}"], 'The subject is empty'],
            'long subject'         => [[Schema::CAMPAIGN_SUBJECT => str_repeat('ř', Message::MAX_ANNOTATION + 1)], 'The subject is longer'],
            'long to hands'        => [[Schema::CAMPAIGN_TO_HANDS => str_repeat('ž', Message::MAX_TO_HANDS + 1)], 'To hands is longer'],
            'no body'              => [[Schema::CAMPAIGN_BODY => '<p> </p>'], 'The letter is empty'],
            // the header alone is no letter
            'no body, header'      => [[Schema::CAMPAIGN_BODY => '<p> </p>', Schema::CAMPAIGN_HEADER => '<p>NSZM</p>'], 'The letter is empty'],
            'no text, header'      => [[Schema::CAMPAIGN_BODY => '', Schema::CAMPAIGN_HEADER => 'NSZM'], 'The letter is empty', false],
            'no field, header'     => [[Schema::CAMPAIGN_BODY => [], Schema::CAMPAIGN_HEADER => '<p>NSZM</p>'], 'The letter is empty'],
            'text not UTF-8'       => [[Schema::CAMPAIGN_BODY => "Vá\xE9ení", Schema::CAMPAIGN_HEADER => 'NSZM'], 'The letter is not UTF-8', false],
            'header not UTF-8'     => [[Schema::CAMPAIGN_HEADER => "<p>Vá\xE9ení</p>"], 'The header is not UTF-8'],
            'footer of 5 lines'    => [[Schema::CAMPAIGN_FOOTER => "a\nb\nc\nd\ne"], PdfBody::FOOTER_TOO_LONG],
            'footer too wide'      => [[Schema::CAMPAIGN_FOOTER => str_repeat('Národní síť ', 15)], PdfBody::FOOTER_TOO_WIDE],
            'footer of control'    => [[Schema::CAMPAIGN_FOOTER => "NSZM\x01"], 'A line of the footer is not plain UTF-8 text'],
            'too many files'       => [[Schema::CAMPAIGN_FILES => array_fill(0, Message::MAX_FILES, self::URL . '/pozvanka.pdf')], 'A message has 1 to'],
        ];
    }

    // a file past what one message holds is not read: up to 20 MB each would exhaust the memory first
    #[DataProvider('beyond')]
    public function test_of_readsNoFileBeyondAMessage(int $count, int $bytes, string $refusal): void
    {
        file_put_contents("$this->dir/velka.pdf", str_pad(self::PDF, $bytes, ' '));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($refusal);
        $this->letter([Schema::CAMPAIGN_FILES => [...array_fill(0, $count, self::URL . '/velka.pdf'), self::URL . '/chybi.pdf']]);
    }

    public static function beyond(): array
    {
        return [
            'files' => [Message::MAX_FILES, 100, 'A message has 1 to'],
            'bytes' => [1, Message::MAX_BYTES, 'larger than'],
        ];
    }

    public function test_construct_refusesLargerThanAMessage(): void
    {
        $main = new File(PdfBody::NAME, 'application/pdf', File::MAIN, self::PDF);

        $this->expectException(\InvalidArgumentException::class);
        new Letter('Pozvánka', [$main, new File('a.pdf', 'application/pdf', File::ENCLOSURE, str_repeat('x', Message::MAX_BYTES))]);
    }
}
