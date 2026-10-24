<?php

declare(strict_types=1);

namespace AA\Isds\Body;

use AA\Isds\File;
use Composer\InstalledVersions;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Image\Cache;
use Dompdf\Options;

/**
 * Dopis.pdf, the letter as a data message carries it: dompdf lays out a document of HtmlBody on A4.
 * The document comes from a rich-text field, so dompdf may load nothing but data: images and its own
 * fonts, runs nothing and writes only into a directory of its own. A letter is measured before it is
 * laid out and its pages and time are counted while it is; what dompdf would draw off the page, where
 * no reader sees it, refuses the letter rather than send it short. Every refusal is a fixed English
 * text: what dompdf says may quote the letter.
 */
final class PdfBody
{
    public const NAME = 'Dopis.pdf';

    /** points of the highest image, with room for a line of text or the padding of a cell on its page */
    public const IMAGE_HEIGHT = 700;
    /** points of a line of A4 within the margins of CSS */
    public const LINE_WIDTH = 481;

    /**
     * For HtmlBody::document(): A4, fonts with Czech that dompdf has, and nothing wider or taller than a page -
     * a word longer than a line, a line of <pre> and an image are broken or made smaller to fit. Not
     * overflow-wrap:anywhere: it lets a table squeeze its narrow columns and break "Pondělí" or "9:00" in two;
     * a word too long for its cell is refused as off the page instead. An image on a line of text stands on its
     * top: on the baseline dompdf draws it over the lines above. A table starts below a floating image instead
     * of over it. A quote is indented on the left only: on both sides 8 levels of a reply left a word no room.
     */
    public const CSS = '@page{size:A4;margin:20mm}body{font-family:"DejaVu Sans";max-width:none;margin:0;padding:0;overflow-wrap:break-word}'
        . 'pre,code{font-family:"DejaVu Sans Mono"}pre{white-space:pre-wrap}table{clear:both}blockquote{margin-right:0}'
        . 'img{vertical-align:top !important;max-height:' . self::IMAGE_HEIGHT . 'pt}';

    /** raised with every change of how a letter looks - a test message of the same draft goes again */
    public const FORMAT = 1;

    public const MAX_PAGES   = 50;
    /** processor time, checked also while a line is measured: the slowest letter measured stopped at 5.1 s */
    public const MAX_SECONDS = 5.0;

    public const TOO_LONG     = 'The letter is too long for a PDF - shorten it or attach the rest as a file';
    public const OFF_PAGE     = 'A part of the letter does not fit on a page - make a table, an image or a margin smaller';
    public const NOT_LAID_OUT = 'The letter cannot be laid out as a PDF';

    /** bytes of the document without its images, and LINE_WEIGHT more for a line break: dompdf takes its time by lines */
    private const MAX_WEIGHT  = 200_000;
    private const LINE_WEIGHT = 80;
    private const LINES       = '~<(?:br|p|li|tr|h[1-6]|dt|dd|div|blockquote)\b~';
    private const MAX_TAGS    = 10_000;
    /** cells laid out: colspan × rowspan of each, and the span of columns */
    private const MAX_CELLS = 2_000;
    private const MAX_ROWS  = 500;
    /** fields of the cell maps dompdf keeps, rows × columns of each table: one colspan="1000" made 500 rows 230 MB */
    private const MAX_GRID = 100_000;

    /**
     * What dompdf took at most, memory_get_peak_usage(true), with a reserve: some 24 MB, 20 kB a tag (18 kB
     * for <b> and <i> in a line), 80 kB a cell, FIELD a field of a cell map and 5 times the images
     */
    private const MEMORY_BASE      = 24 * 1024 * 1024;
    private const MEMORY_PER_TAG   = 20 * 1024;
    private const MEMORY_PER_CELL  = 80 * 1024;
    private const MEMORY_PER_FIELD = 512;
    private const MEMORY_PER_BYTE  = 5;
    /** what dompdf may take between two looks at the memory, below memory_limit: a fatal error would say nothing */
    private const MEMORY_RESERVE = 8 * 1024 * 1024;

    /** what the callbacks found of the letter being laid out */
    private int $pages        = 0;
    private float $pageRoom   = INF;
    private float $tallestRow = 0.0;
    private bool $offPage     = false;
    /**
     * @var array<int, array{\DOMElement, int, int}> each row as written - its node, held for its id to stay -
     *                                               with the number of its table and its own, by spl_object_id()
     */
    private array $rows = [];
    /**
     * @var list<array{array{}|array{int, int}, list<\DOMElement>}> a cell spanning rows and the rows it spans, as
     *      written, with its table and row: dompdf moves the rows behind a page break into a copy of the table,
     *      the nodes themselves stay - the cell it may take out of its row
     */
    private array $groups = [];
    /** @var list<int> the number each table laid out has in the letter: flow() made blocks of some */
    private array $numbers = [];
    /** @var array<int, int> the page a row or a cell of $groups was drawn on, by spl_object_id() of its node */
    private array $drawnOn = [];
    /** @var array{}|array{int, int} table and row of the tallest row, of what lies off the page first, of a split group */
    private array $tallestAt = [];
    /** @var array{}|array{int, int} */
    private array $offPageAt = [];
    /** @var array{}|array{int, int}|null null = no group split */
    private ?array $spanAt = null;

    /** @param float $maxSeconds processor time of dompdf, whatever else the server does; a test makes both limits smaller */
    public function __construct(private readonly int $maxPages = self::MAX_PAGES, private readonly float $maxSeconds = self::MAX_SECONDS) {}

    /**
     * @param string $document of HtmlBody::document() with CSS
     * @throws \InvalidArgumentException a letter that is no PDF whole; the message says what to change
     * @throws \RuntimeException no directory for dompdf, PCRE failed to measure the letter or lost an image of it
     */
    public function render(string $document): File
    {
        $size = self::measure($document);
        if (self::tooLong($size) || self::tooMany(PdfTables::check($size['markup']))) {
            throw new \InvalidArgumentException(self::TOO_LONG);
        }
        unset($size);
        [$document, $this->numbers] = PdfTables::flow($document);
        $dir = sys_get_temp_dir() . '/aa-pdf-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0o700)) {
            throw new \RuntimeException('There is no temporary directory for the PDF letter');
        }
        // a fatal error - memory, time - skips finally
        register_shutdown_function(self::remove(...), $dir);
        // thrown by the callbacks, known by itself: anything else thrown inside dompdf says only that it failed
        $limit            = new \LengthException();
        $this->pages      = 0;
        $this->pageRoom   = INF;
        $this->tallestRow = 0.0;
        $this->offPage    = false;
        $this->rows       = [];
        $this->groups     = [];
        $this->drawnOn    = [];
        $this->tallestAt  = [];
        $this->offPageAt  = [];
        $this->spanAt     = null;
        // dompdf sets them for itself and puts them back only when it ends well
        $encoding = mb_internal_encoding();
        $jit      = ini_get('pcre.jit');
        $numeric  = setlocale(LC_NUMERIC, '0');
        // dompdf echoes its warnings and frames when these are on; the warnings may quote the letter
        $GLOBALS['_dompdf_warnings']      = [];
        $GLOBALS['_dompdf_show_warnings'] = false;
        $GLOBALS['_dompdf_debug']         = false;
        $dompdf = null;
        try {
            $dompdf = new Dompdf(self::options($dir));
            $clock  = $this->clock($limit);
            $dompdf->setFontMetrics(self::metrics($dompdf, $clock));
            $dompdf->setCallbacks($this->callbacks($limit, $clock));
            $dompdf->loadHtml($document, 'UTF-8');
            $dompdf->render();
            $pdf          = (string) $dompdf->output();
            $warnings     = self::warnings($GLOBALS['_dompdf_warnings'] ?? []);
            $this->spanAt = $this->spanSplit();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(($e === $limit) ? self::TOO_LONG : self::NOT_LAID_OUT);
        } finally {
            $this->rows    = [];
            $this->groups  = [];
            $this->drawnOn = [];
            mb_internal_encoding($encoding);
            ini_set('pcre.jit', (string) $jit);
            setlocale(LC_NUMERIC, (string) $numeric);
            $GLOBALS['_dompdf_warnings'] = [];
            // its static cache would point the next letter of the process to images of the removed directory
            Cache::clear();
            unset($dompdf);
            gc_collect_cycles();
            self::remove($dir);
        }
        $refusal = $this->refusal($warnings);
        if ($refusal !== null) {
            throw new \InvalidArgumentException($refusal);
        }
        return new File(self::NAME, 'application/pdf', File::MAIN, $pdf);
    }

    /** what the letter laid out would lose, after dompdf ended - its exceptions say only that it failed */
    private function refusal(int $warnings): ?string
    {
        return match (true) {
            // first: a split group of rows comes out as one row of the height of many pages
            $this->spanAt !== null                    => PdfTables::at(PdfTables::SPAN_SPLIT, $this->spanAt),
            // a row higher than a page is not split: what does not fit on it is lost
            $this->tallestRow > $this->pageRoom + 0.5 => PdfTables::at(PdfTables::LONG_CELL, $this->tallestAt),
            $this->offPage                            => PdfTables::at(self::OFF_PAGE, $this->offPageAt),
            $warnings > 0                             => self::NOT_LAID_OUT,
            default                                   => null,
        };
    }

    /**
     * What a test message compares instead of the bytes, which differ each time: the document and all
     * else that changes the PDF of it
     * @return string 32 hexadecimal digits
     */
    public static function source(string $document): string
    {
        $dompdf = InstalledVersions::getPrettyVersion('dompdf/dompdf') . '@' . InstalledVersions::getReference('dompdf/dompdf');
        return hash('xxh128', self::FORMAT . "\0$dompdf\0$document");
    }

    /**
     * Bytes render() may take of the document, at most
     * @throws \RuntimeException PCRE failed to measure the letter
     */
    public static function memory(string $document): int
    {
        $size = self::measure($document);
        try {
            $tables = self::tooLong($size) ? null : PdfTables::check($size['markup']);
        } catch (\InvalidArgumentException) {
            $tables = null;
        }
        // refused before dompdf starts: a span of 65534 rows would ask for terabytes
        if (($tables === null) || self::tooMany($tables)) {
            return self::MEMORY_BASE;
        }
        return self::MEMORY_BASE + self::MEMORY_PER_TAG * $size['tags'] + self::MEMORY_PER_CELL * $tables['cells'] + self::MEMORY_PER_FIELD * $tables['grid']
            + self::MEMORY_PER_BYTE * (strlen($document) - strlen($size['markup']));
    }

    /**
     * @return array{markup: string, weight: int, tags: int, cells: int} counted without the data: of images -
     *         HtmlBody writes every value in double quotes and every "<" of a text escaped; cells of all tables,
     *         before check() reads them
     */
    private static function measure(string $document): array
    {
        $markup = preg_replace('~ src="data:[^"]*+"~', ' src=""', $document) ?? '';
        $lines  = preg_match_all(self::LINES, $markup);
        $found  = preg_match_all('~<(td|th|col|colgroup)\b([^>]*+)>~', $markup, $tags, PREG_SET_ORDER);
        if (($markup === '') || ($lines === false) || ($found === false)) {
            // a limit of PCRE: a letter not measured would pass every limit
            throw new \RuntimeException('The letter cannot be measured for a PDF');
        }
        $cells = 0;
        foreach ($tags as [, $tag, $attributes]) {
            $cells += in_array($tag, ['td', 'th'], true) ? self::number($attributes, 'colspan') * self::number($attributes, 'rowspan') : self::number($attributes, 'span');
        }
        return [
            'markup' => $markup,
            'weight' => strlen($markup) + self::LINE_WEIGHT * (substr_count($markup, "\n") + $lines),
            'tags'   => substr_count($markup, '<'),
            'cells'  => $cells,
        ];
    }

    /**
     * The cells of every table bound the work of check(); a frame of one cell a row, which becomes text, of so
     * many cells would be too long as text, too
     * @param array{weight: int, tags: int, cells: int} $size of measure()
     */
    private static function tooLong(array $size): bool
    {
        return ($size['weight'] > self::MAX_WEIGHT) || ($size['tags'] > self::MAX_TAGS) || ($size['cells'] > self::MAX_CELLS);
    }

    /** @param array{grid: int, rows: int, cells: int} $tables of PdfTables::check(): the tables dompdf lays out */
    private static function tooMany(array $tables): bool
    {
        return ($tables['grid'] > self::MAX_GRID) || ($tables['rows'] > self::MAX_ROWS) || ($tables['cells'] > self::MAX_CELLS);
    }

    private static function number(string $attributes, string $name): int
    {
        return (preg_match("~ $name=\"(\\d+)\"~", $attributes, $m) === 1) ? max(1, (int) $m[1]) : 1;
    }

    /**
     * Throws $limit past the time of a letter or close to memory_limit. dompdf lays out a cell, or a page, as
     * a whole: looked at only between pages and frames, a paragraph ran to 6.3 s
     * @return \Closure(): void
     */
    private function clock(\LengthException $limit): \Closure
    {
        $end     = self::cpu() + $this->maxSeconds;
        $bytes   = ini_parse_quantity((string) ini_get('memory_limit'));
        $ceiling = ($bytes > 0) ? $bytes - self::MEMORY_RESERVE : PHP_INT_MAX;
        $calls   = 0;
        return static function () use ($end, $ceiling, $limit, &$calls): void {
            // the processor time only now and then: it asks the kernel
            if ((memory_get_usage(true) > $ceiling) || ((++$calls % 64 === 0) && (self::cpu() > $end))) {
                throw $limit;
            }
        };
    }

    /**
     * The font metrics of $dompdf, that run $clock whenever a text is measured: within a page there is no
     * other way to stop it
     * @param \Closure(): void $clock
     */
    private static function metrics(Dompdf $dompdf, \Closure $clock): FontMetrics
    {
        return new class ($dompdf->getCanvas(), $dompdf->getOptions(), $clock) extends FontMetrics {
            /** @param \Closure(): void $clock */
            public function __construct(Canvas $canvas, Options $options, private readonly \Closure $clock)
            {
                parent::__construct($canvas, $options);
            }

            public function getTextWidth(string $text, $font, float $size, float $wordSpacing = 0.0, float $charSpacing = 0.0): float
            {
                ($this->clock)();
                return parent::getTextWidth($text, $font, $size, $wordSpacing, $charSpacing);
            }
        };
    }

    /**
     * Pages counted, and where dompdf leaves what it draws
     * @param \Closure(): void $clock
     * @return list<array{event: string, f: \Closure}>
     */
    private function callbacks(\LengthException $limit, \Closure $clock): array
    {
        return [
            ['event' => 'begin_page_reflow', 'f' => function (Frame $frame) use ($limit, $clock): void {
                if (++$this->pages > $this->maxPages) {
                    throw $limit;
                }
                if ($this->pages === 1) {
                    [$this->rows, $this->groups] = self::tables($frame->get_node(), $this->numbers);
                }
                // the frame of <html>, within the margins of its page
                $this->pageRoom = (float) $frame->get_containing_block()['h'];
                $clock();
            }],
            ['event' => 'begin_frame', 'f' => function (Frame $frame, Canvas $canvas) use ($clock): void {
                $clock();
                $this->frame($frame, $canvas);
            }],
        ];
    }

    /**
     * dompdf keeps only the text of what PHP reported while it read the letter: a deprecation is no fault of
     * the letter, it comes with the next PHP for each of them. PHP says "... is deprecated" with no prefix; a
     * message that names a data: or other URI is about a resource, whatever its base64 spells.
     * @param array<mixed> $messages
     */
    private static function warnings(array $messages): int
    {
        return count(array_filter($messages, static fn(mixed $message): bool => !is_string($message) || (preg_match('~\bdeprecated\b~i', $message) !== 1)
            || str_contains($message, 'data:') || str_contains($message, '://')));
    }

    /** seconds of processor time of the process */
    private static function cpu(): float
    {
        $usage = getrusage();
        return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
    }

    /**
     * A row as high as it came out and on which page, text, images and list numbers where they lie on the
     * page. dompdf neither splits a row nor a cell spanning rows: one over a page break it gives a height of
     * thousands of points, and the next row loses its column to it
     */
    private function frame(Frame $frame, Canvas $canvas): void
    {
        $node = $frame->get_node();
        $name = $node->nodeName;
        [$x, $y, $width, $height] = array_map('floatval', $frame->get_border_box());
        $spanning = (($name === 'td') || ($name === 'th')) && ($node instanceof \DOMElement) && ((int) $node->getAttribute('rowspan') > 1);
        if (($name === 'tr') || $spanning) {
            $this->drawnOn[spl_object_id($node)] = $this->pages;
        }
        if ((($name === 'tr') || ($name === 'td') || ($name === 'th')) && !$spanning && ($height > $this->tallestRow)) {
            $this->tallestRow = $height;
            $this->tallestAt  = $this->place($node);
        } elseif (!$this->offPage && ((($node instanceof \DOMText) && (trim($node->data) !== '')) || ($name === 'img') || ($name === 'bullet'))
            && (($x < -0.5) || ($y < -0.5) || ($x + $width > $canvas->get_width() + 0.5) || ($y + $height > $canvas->get_height() + 0.5))) {
            $this->offPage   = true;
            $this->offPageAt = $this->place($node);
        }
    }

    /** @return array{}|array{int, int} table and row of the letter $node lies in, as written */
    private function place(\DOMNode $node): array
    {
        for (; $node !== null; $node = $node->parentNode) {
            if (isset($this->rows[spl_object_id($node)])) {
                return array_slice($this->rows[spl_object_id($node)], 1);
            }
        }
        return [];
    }

    /**
     * Before the first page is laid out, while each table is whole: its rows numbered as PdfTables does, and
     * the cells spanning rows with the rows they span
     * @param list<int> $numbers of the tables in the letter, by their order here
     * @return array{array<int, array{\DOMElement, int, int}>, list<array{array{}|array{int, int}, list<\DOMElement>}>}
     */
    private static function tables(\DOMNode $html, array $numbers): array
    {
        if ($html->ownerDocument === null) {
            return [[], []];
        }
        $xpath = new \DOMXPath($html->ownerDocument);
        $rows  = [];
        foreach (PdfTables::elements($xpath, '//table') as $t => $table) {
            foreach (PdfTables::elements($xpath, PdfTables::ROWS, $table) as $r => $row) {
                $rows[spl_object_id($row)] = [$row, $numbers[$t] ?? $t + 1, $r + 1];
            }
        }
        $groups = [];
        foreach (PdfTables::elements($xpath, '//td[@rowspan > 1] | //th[@rowspan > 1]') as $cell) {
            $group = [$cell];
            $span  = (int) $cell->getAttribute('rowspan');
            for ($row = $cell->parentNode?->nextSibling; ($row !== null) && (count($group) < $span); $row = $row->nextSibling) {
                if ($row instanceof \DOMElement && ($row->nodeName === 'tr')) {
                    $group[] = $row;
                }
            }
            $place    = ($cell->parentNode === null) ? null : ($rows[spl_object_id($cell->parentNode)] ?? null);
            $groups[] = [($place === null) ? [] : [$place[1], $place[2]], $group];
        }
        return [$rows, $groups];
    }

    /**
     * a cell spanning rows drawn on another page than one of its rows, or a row lost
     * @return array{}|array{int, int}|null where the cell is, null = none such
     */
    private function spanSplit(): ?array
    {
        foreach ($this->groups as [$place, $group]) {
            $pages = array_map(fn(\DOMElement $node): ?int => $this->drawnOn[spl_object_id($node)] ?? null, $group);
            if (count(array_unique($pages, SORT_REGULAR)) > 1) {
                return $place;
            }
        }
        return null;
    }

    private static function options(string $dir): Options
    {
        $options = new Options();
        $fonts   = $options->getFontDir();
        return $options->set([
            'isRemoteEnabled'         => false,
            'isPhpEnabled'            => false,
            'isJavascriptEnabled'     => false,
            'isPdfAEnabled'           => false,
            'isFontSubsettingEnabled' => true,
            // HtmlBody gives no other; a font of data: would be installed into fontDir
            'allowedProtocols'        => ['data://' => ['rules' => [static fn(string $uri): array => [preg_match('~^data:image/(?:jpeg|png);base64,~i', $uri) === 1, 'Not an image']]]],
            'chroot'                  => [$fonts],
            'fontDir'                 => $fonts,
            'fontCache'               => $dir,
            'tempDir'                 => $dir,
            'defaultFont'             => 'DejaVu Sans',
            // besides @page: without it dompdf took 5 MB more for a letter
            'defaultPaperSize'        => 'a4',
            'logOutputFile'           => '',
            'debugKeepTemp'           => false,
        ]);
    }

    /** $dir and the files dompdf left in it, never what a link leads to */
    private static function remove(string $dir): void
    {
        clearstatcache(true, $dir);
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (($name !== '.') && ($name !== '..')) {
                @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
