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
     * @param array<string, mixed> $over field => its value, or a list of values; $html: FLAG_HTML on the body
     * @param ?\Closure(int): string $makeRoom memory for the PDF, there always is by default
     */
    private function letter(array $over = [], string $letterhead = '', bool $html = true, ?\Closure $makeRoom = null): Letter
    {
        $fields  = $over + [Schema::CAMPAIGN_SUBJECT => ' Pozvánka ', Schema::CAMPAIGN_BODY => '<p>Vážení, <b>zveme</b> Vás</p>', Schema::CAMPAIGN_TO_HANDS => 'starosta', Schema::CAMPAIGN_FILES => self::URL . '/pozvanka.pdf'];
        $content = [];
        foreach ($fields as $field => $values) {
            foreach ((array) $values as $value) {
                $content[$field][] = ['value' => $value, 'flag' => (($field === Schema::CAMPAIGN_BODY) && $html) ? FLAG_HTML : 0];
            }
        }
        return Letter::of(new ItemContent($content), $letterhead, new UploadDir(self::URL, $this->dir), $makeRoom ?? static fn(int $bytes): string => '');
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

    // what a test message compares: the document the PDF was made of, not its bytes
    public function test_of_sourceOfTheDocument(): void
    {
        $document = (new HtmlBody(new UploadDir(self::URL, $this->dir)))->document('Pozvánka', '<p>Vážení, <b>zveme</b> Vás</p>', true, 'NSZM', PdfBody::CSS);

        self::assertSame(PdfBody::source($document), $this->letter([], 'NSZM')->source);
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

    // lines of text in either format of the body, escaped, above it
    public function test_of_letterheadAboveTheBody(): void
    {
        self::assertSame("Zdravá města & obce\nPalackého 1\nVážení, \nzveme\n Vás\n", self::body($this->letter([], "Zdravá města & obce\nPalackého 1")));
        self::assertSame("<b>NSZM</b>\nVážení,\nzveme Vás\n", self::body($this->letter([Schema::CAMPAIGN_BODY => "Vážení,\nzveme Vás"], '<b>NSZM</b>', false)));
    }

    #[DataProvider('flags')]
    public function test_of_bodyFormatByFlag(bool $html, string $body): void
    {
        self::assertSame($body, self::body($this->letter([Schema::CAMPAIGN_BODY => "Vážení,\n<b>zveme</b> Vás"], '', $html)));
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
    public function test_of_refusesOverLimits(array $over, string $message, string $letterhead = '', bool $html = true): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->letter($over, $letterhead, $html);
    }

    public static function limits(): array
    {
        return [
            'no subject'           => [[Schema::CAMPAIGN_SUBJECT => ' '], 'The subject is empty'],
            'invisible subject'    => [[Schema::CAMPAIGN_SUBJECT => "\u{200B}\u{2061}"], 'The subject is empty'],
            'long subject'         => [[Schema::CAMPAIGN_SUBJECT => str_repeat('ř', Message::MAX_ANNOTATION + 1)], 'The subject is longer'],
            'long to hands'        => [[Schema::CAMPAIGN_TO_HANDS => str_repeat('ž', Message::MAX_TO_HANDS + 1)], 'To hands is longer'],
            'no body'              => [[Schema::CAMPAIGN_BODY => '<p> </p>'], 'The letter is empty'],
            // the letterhead alone is no letter
            'no body, letterhead'  => [[Schema::CAMPAIGN_BODY => '<p> </p>'], 'The letter is empty', 'NSZM'],
            'no text, letterhead'  => [[Schema::CAMPAIGN_BODY => ''], 'The letter is empty', 'NSZM', false],
            'no field, letterhead' => [[Schema::CAMPAIGN_BODY => []], 'The letter is empty', 'NSZM'],
            'text not UTF-8'       => [[Schema::CAMPAIGN_BODY => "Vá\xE9ení"], 'The letter is not UTF-8', 'NSZM', false],
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
