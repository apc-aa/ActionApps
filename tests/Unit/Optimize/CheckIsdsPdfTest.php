<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\DatovkaConf;
use AA\Isds\File;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsPdf;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/../Isds/fixtures/Sending.php';

/** CheckIsdsPdf: a real letter made into a PDF where an ISDS account sends; a failure never quotes the server */
final class CheckIsdsPdfTest extends TestCase
{
    /** what a failing engine says: a path of the server, never to be shown */
    private const SECRET = '/data/www/secret/vendor/dompdf';

    /** what the check looks for in a PDF of its letter */
    private const GOOD = "%PDF-1.7\n/BaseFont /ABCDEF+DejaVuSans /BaseFont /ABCDEG+DejaVuSansMono /Subtype /Image";

    private const COMPOSER = 'Until then no campaign can be sent to data boxes. Run composer install in the directory of AA - it brings dompdf and its fonts - and check that PHP may write to its temporary directory (sys_temp_dir).';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_types_extendedOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsdsPdf::types());
    }

    /** the real HtmlBody and PdfBody */
    public function test_findings_ok(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not loaded');
        }
        $check    = self::check(Sending::bound());
        $findings = $check->findings();
        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
        self::assertSame('AA makes letters to data boxes into a PDF', $findings[0]->title);
        self::assertSame(1, $check->rendered);
    }

    /** @return iterable<string, array{\Closure(string): File}> */
    public static function failures(): iterable
    {
        $pdf = static fn(string $content): \Closure => static fn(string $document): File => new File('Dopis.pdf', 'application/pdf', File::MAIN, $content);
        yield 'thrown' => [static fn(string $document): File => throw new \RuntimeException('Cannot write ' . self::SECRET)];
        yield 'missing class' => [static fn(string $document): File => throw new \Error('Class "Dompdf\Dompdf" not found in ' . self::SECRET)];
        yield 'not a PDF' => [$pdf('<html>' . substr(self::GOOD, 8))];
        // dompdf without its fonts: Helvetica has no Czech
        yield 'no fonts' => [$pdf(str_replace('DejaVuSans', 'Helvetica', self::GOOD))];
        yield 'no mono' => [$pdf(str_replace('DejaVuSansMono', 'Courier', self::GOOD))];
        yield 'no image' => [$pdf(str_replace('/Subtype /Image', '', self::GOOD))];
    }

    /** @param \Closure(string): File $engine */
    #[DataProvider('failures')]
    public function test_findings_engineFails(\Closure $engine): void
    {
        $findings = self::check(Sending::bound(), $engine)->findings();
        self::assertCount(1, $findings);
        self::assertSame(Finding::ERROR, $findings[0]->severity);
        self::assertSame('A letter to a data box cannot be made into a PDF', $findings[0]->title);
        // GD drawing the image of the letter, so a server without it says so instead
        if (extension_loaded('gd')) {
            self::assertSame(self::COMPOSER, $findings[0]->advice);
        }
        self::assertStringNotContainsString(self::SECRET, serialize($findings));
    }

    /** a PDF that would pass, of a letter that lost its image */
    public function test_findings_imageLeftOut(): void
    {
        $check = self::check(Sending::bound(), static fn(string $document): File => new File('Dopis.pdf', 'application/pdf', File::MAIN, self::GOOD), body: '<p>Dopis</p>');
        self::assertSame(Finding::OK, $check->findings()[0]->severity);
        $check->body = '<p>Dopis <img src="https://example.org/obrazek.png" alt=""></p>';
        self::assertSame(Finding::ERROR, $check->findings()[0]->severity);
    }

    /** @return iterable<string, array{string, string}> */
    public static function lacking(): iterable
    {
        yield 'dompdf' => ['dompdf', 'Until then no campaign can be sent to data boxes. dompdf, which makes the PDF, is missing: run composer install in the directory of AA.'];
        yield 'GD' => ['gd', 'Until then no campaign can be sent to data boxes. PHP lacks the GD extension, which draws the images of a letter: install it and restart PHP.'];
        yield 'neither' => ['', self::COMPOSER];
    }

    #[DataProvider('lacking')]
    public function test_findings_adviceOfWhatIsMissing(string $missing, string $advice): void
    {
        $check          = self::check(Sending::bound(), static fn(string $document): File => throw new \Error('Class not found'));
        $check->missing = $missing;
        self::assertSame($advice, $check->findings()[0]->advice);
    }

    /**
     * No account, one of another database only, or one of no site read whose slices are of another database:
     * nothing made, nothing shown. One of no site read with a slice here is tried.
     */
    public function test_findings_noAccountNoCheck(): void
    {
        $siteless = Sending::conf(['NSZM' => Sending::account(over: ['sites' => null])]);
        foreach ([Sending::conf([]), Sending::bound(Sending::SITE2), $siteless] as $conf) {
            $check = self::check($conf, here: [Sending::SITE]);
            self::assertSame([], $check->findings());
            self::assertSame(0, $check->rendered);
        }
        $check = self::check($siteless, static fn(string $document): File => new File('Dopis.pdf', 'application/pdf', File::MAIN, self::GOOD), here: [Sending::CAMPAIGN], body: '<p>Dopis</p>');
        self::assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $check->findings()));
        self::assertSame(1, $check->rendered);
    }

    /**
     * @param ?\Closure(string): File $engine null = PdfBody
     * @param ?list<string>           $here   the modules of this database; null = all asked
     * @param ?string                 $body   of the letter instead of its own; without an image GD has nothing to draw
     */
    private static function check(DatovkaConf $conf, ?\Closure $engine = null, ?array $here = null, ?string $body = null): CheckIsdsPdf
    {
        return new class ($conf, $engine, $here, $body) extends CheckIsdsPdf {
            public int $rendered = 0;

            /** what missing() says; null = what the server lacks */
            public ?string $missing = null;

            /**
             * @param ?\Closure(string): File $engine
             * @param ?list<string>           $here
             */
            public function __construct(private readonly DatovkaConf $settings, private readonly ?\Closure $engine, private readonly ?array $here, public ?string $body) {}

            protected function conf(): DatovkaConf
            {
                return $this->settings;
            }

            protected function modulesHere(array $ids): array
            {
                return is_null($this->here) ? $ids : array_values(array_intersect($ids, $this->here));
            }

            protected function body(): string
            {
                return $this->body ?? parent::body();
            }

            protected function render(string $document): File
            {
                ++$this->rendered;
                return is_null($this->engine) ? parent::render($document) : ($this->engine)($document);
            }

            protected function missing(): string
            {
                return $this->missing ?? parent::missing();
            }
        };
    }
}
