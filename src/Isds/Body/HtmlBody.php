<?php

declare(strict_types=1);

namespace AA\Isds\Body;

use AA\Isds\File;
use AA\Isds\Message;
use AA\Isds\Text;
use AA\Upload\UploadDir;
use AA\Upload\UploadPolicy;
use AA\Util\Imgfunc;
use AA\Util\Libxml;

/**
 * The letter of a data message as an HTML file: a whole UTF-8 document that loads nothing and runs
 * nothing. Its body comes from a rich-text field and goes to thousands of filing systems, so it is
 * rebuilt from an allow-list rather than cleaned of known dangers: an element not in ELEMENTS gives
 * only its own cleaned content (a DROPPED or UNSEEN one nothing), an attribute not listed is left out, every
 * text and value is escaped anew and every image is drawn again. What a reader would miss - an
 * image, a link, an embedded object - omitted() lists, for the sender to see before sending.
 * Entities only HTML5 knows (&colon;, &NewLine;) stay as they are written: libxml reads HTML 4.
 * ISDS refuses HTML containing "<object", "<iframe" or "<script" (WS_manipulace_s_datovymi_zpravami.pdf 3).
 */
final class HtmlBody
{
    public const NAME = 'Dopis.html';

    /** pixels of all images of a letter together: each is decoded whole before it is drawn smaller */
    public const MAX_PIXELS = 200_000_000;

    /** the longer side of an image - a page is narrower */
    private const IMAGE_PX = 1600;

    /** "<" of a letter: libxml builds its tree outside memory_limit, some 180 B a tag (5 M <br> took
     *  0.9 GB); a letter has a few hundred */
    private const MAX_TAGS = 200_000;

    /** attributes of one tag: libxml compares each with all before it, outside memory_limit - 100 000 in
     *  one <p> took 22 s */
    private const MAX_ATTRIBUTES = 1000;
    /** attributes of the whole letter: 6 000 tags of 1000 attributes each still took 15 s */
    private const MAX_ALL_ATTRIBUTES = 200_000;

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    /** the bytes of a name of libxml 2.9-2.13, and those it may begin with */
    private const NAME_BYTES = self::LETTERS . '0123456789_:.-';
    private const NAME_START = self::LETTERS . '_:.';
    private const BLANK      = " \t\n\r";
    private const WHITE      = " \t\n\r\f";
    /**
     * The states of checkAttributes(), each with the bytes it passes over: strspn() of them (true, at most
     * the third number of bytes) or strcspn() (false). "l" reads a tag as libxml 2.9-2.13 does, "h" as HTML5
     * and libxml 2.14: they part on "/", "\f" and the names the older one cannot read
     */
    private const SCAN = [
        // libxml 2.9-2.13 reads 100 bytes of a name, the rest is the next attribute
        'lTag'    => [true, self::NAME_BYTES, 100],
        'lBefore' => [true, self::BLANK],
        'lBogus'  => [false, self::BLANK . '>'],
        // the first byte was read on entering
        'lName'   => [true, self::NAME_BYTES, 99],
        'lAfter'  => [true, self::BLANK],
        'lEq'     => [true, self::BLANK],
        'lDq'     => [false, '"'],
        'lSq'     => [false, "'"],
        'lUnq'    => [false, self::BLANK . '>'],
        'hSlash'  => [true, ''],
        'hTag'    => [false, self::WHITE . '/>'],
        'hBefore' => [true, self::WHITE . '/'],
        'hName'   => [false, self::WHITE . '/>='],
        'hAfter'  => [true, self::WHITE],
        'hEq'     => [true, self::WHITE],
        'hDq'     => [false, '"'],
        'hSq'     => [false, "'"],
        'hUnq'    => [false, self::WHITE . '>'],
        'hQuoted' => [true, ''],
    ];

    /** what is no visible text: spaces and the invisible characters editors leave - format characters
     *  (zero-width space, BOM, soft hyphen, word joiner), the combining grapheme joiner, variation selectors
     *  (also the Mongolian ones), C1 control characters, the Khmer inherent vowels, the Hangul fillers and the
     *  blank Braille cell */
    private const INVISIBLE = '\s\x{A0}\p{Cf}\x{34F}\x{80}-\x{9F}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{180B}-\x{180D}\x{180F}\x{17B4}\x{17B5}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}';

    private const FORBIDDEN = ['<object', '<iframe', '<script'];

    private const ALIGN  = ['left', 'right', 'center', 'justify'];
    private const VALIGN = ['top', 'middle', 'bottom', 'baseline'];
    private const CELL   = ['align', 'valign', 'bgcolor'];

    /** element => its attributes besides GLOBAL */
    private const ELEMENTS = [
        'a'          => ['href'],
        'b'          => [],
        'blockquote' => [],
        'br'         => [],
        'caption'    => [],
        'code'       => [],
        'col'        => ['span', 'width', 'align', 'valign'],
        'colgroup'   => ['span', 'width', 'align', 'valign'],
        'dd'         => [],
        'del'        => [],
        'div'        => ['align'],
        'dl'         => [],
        'dt'         => [],
        'em'         => [],
        'figcaption' => [],
        'figure'     => [],
        'h1'         => ['align'],
        'h2'         => ['align'],
        'h3'         => ['align'],
        'h4'         => ['align'],
        'h5'         => ['align'],
        'h6'         => ['align'],
        'hr'         => ['align'],
        'i'          => [],
        'img'        => ['src', 'alt', 'width', 'height'],
        'ins'        => [],
        'li'         => [],
        'mark'       => [],
        'ol'         => ['start', 'type'],
        'p'          => ['align'],
        'pre'        => [],
        's'          => [],
        'small'      => [],
        'span'       => [],
        'strong'     => [],
        'sub'        => [],
        'sup'        => [],
        'table'      => ['border', 'cellpadding', 'cellspacing', 'width', 'align', 'bgcolor'],
        'tbody'      => ['align', 'valign'],
        'td'         => ['colspan', 'rowspan', 'width', 'height', ...self::CELL],
        'tfoot'      => ['align', 'valign'],
        'th'         => ['colspan', 'rowspan', 'scope', 'width', 'height', ...self::CELL],
        'thead'      => ['align', 'valign'],
        'tr'         => self::CELL,
        'u'          => [],
        'ul'         => [],
    ];
    /** written as the element HTML5 still has */
    private const RENAMED = ['strike' => 's'];
    private const GLOBAL  = ['style', 'title', 'lang', 'dir'];
    private const VOID    = ['br', 'col', 'hr', 'img'];

    /** left out with all they hold: code, embedded content, form controls */
    private const DROPPED = [
        'applet', 'audio', 'button', 'canvas', 'datalist', 'frameset', 'iframe', 'map', 'math', 'noembed', 'noframes',
        'noscript', 'object', 'plaintext', 'script', 'select', 'svg', 'template', 'textarea', 'video', 'xmp',
    ];
    /** left out with all they hold, without a word: a pasted page has them for the browser, not the reader */
    private const UNSEEN = ['head', 'link', 'meta', 'style', 'title'];
    /** left out, but what they hold stays: libxml puts the rest of the letter into an <embed> or <image> */
    private const UNWRAPPED = ['embed', 'image', 'input'];

    /** CSS properties an inline style may keep */
    private const STYLES = [
        'background', 'background-color', 'border', 'border-collapse', 'border-color', 'border-style', 'border-width',
        'color', 'display', 'float', 'font-size', 'font-style', 'font-weight', 'height', 'line-height', 'list-style-type',
        'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top', 'padding', 'padding-left', 'text-align',
        'text-decoration', 'text-indent', 'vertical-align', 'width',
    ];
    /** the display a letter needs: table-cell and the like would make a table cell of a <p>, dompdf throws on that */
    private const DISPLAY = ['block', 'inline', 'inline-block', 'list-item', 'none'];
    /** a table and its rows: dompdf throws on any display of them but none */
    private const TABLE_PARTS = ['table', 'thead', 'tbody', 'tfoot', 'tr'];
    /** words, numbers with units, #colours and rgb()/hsl() - no url(), quotes, escapes or comments */
    private const STYLE_VALUE = '~^(?:[a-z0-9#%.,\s-]|(?:rgba?|hsla?)\([0-9.,%\s/]*\))+$~i';
    private const COLOUR      = '~^(?:#[0-9a-f]{3}|#[0-9a-f]{6}|[a-z]{3,20})$~i';
    /** the colours HTML 4 named; a background of another name is looked for further up */
    private const COLOUR_NAMES = [
        'black' => 0x000000, 'silver' => 0xC0C0C0, 'gray' => 0x808080, 'white' => 0xFFFFFF, 'maroon' => 0x800000,
        'red' => 0xFF0000, 'purple' => 0x800080, 'fuchsia' => 0xFF00FF, 'green' => 0x008000, 'lime' => 0x00FF00,
        'olive' => 0x808000, 'yellow' => 0xFFFF00, 'navy' => 0x000080, 'blue' => 0x0000FF, 'teal' => 0x008080,
        'aqua' => 0x00FFFF,
    ];

    /**
     * libxml errors after which the rest of the letter is missing: 1 (internal) is the depth limit on
     * libxml 2.9, 2 (no memory) a huge text node; later versions report the depth as fatal
     */
    private const CUT_OFF = [1, 2];

    private const HEAD = "<!DOCTYPE html>\n<html lang=\"cs\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">\n"
        // the browser's own guard, should anything active get through
        . "<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; img-src data:; style-src 'unsafe-inline'\">\n"
        // a link followed from the letter does not tell the site where it came from
        . "<meta name=\"referrer\" content=\"no-referrer\">\n";
    private const CSS = 'body{font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.45;color:#000;background:#fff;max-width:45em;margin:2em auto;padding:0 1em}'
        . 'table{border-collapse:collapse}td,th{padding:.2em .5em;vertical-align:top}img{max-width:100%;height:auto}';

    /** scheme://host[:port] of the web site relative links lead to, null = none */
    private ?string $origin = null;
    private string $basePath = '/';

    private string $out     = '';
    private int $paragraphs = 0;
    private bool $visible   = false;
    private int $pixels     = 0;
    /** @var array<string, ?string> the data: URI of an image by the hash of its background and src, null = left out */
    private array $images = [];
    /** @var non-empty-list<int> 0xRRGGBB behind the element being written and each around it; the page is white */
    private array $backgrounds = [0xFFFFFF];
    /** @var array<string, true> */
    private array $omitted = [];

    /**
     * @param ?UploadDir $uploads the images stored there are drawn into the letter; data: images always
     *                            are, an image from anywhere else is left out
     * @param ?string    $base    the web page relative links lead from; without it they are left out
     * @throws \InvalidArgumentException $base is not an http(s) URL with a host
     */
    public function __construct(private readonly ?UploadDir $uploads = null, ?string $base = null)
    {
        if ($base === null) {
            return;
        }
        $parts  = parse_url(trim($base));
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || (($parts['host'] ?? '') === '')) {
            throw new \InvalidArgumentException("The web site \"$base\" is not like https://example.org/");
        }
        $this->origin   = "$scheme://" . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = (($parts['path'] ?? '') === '') ? '/' : $parts['path'];
    }

    /**
     * Dopis.html, the main file of a message
     * @throws \InvalidArgumentException see document()
     * @throws \RuntimeException an image among the uploads cannot be read
     */
    public function render(string $subject, string $body, bool $html = true, string $letterhead = ''): File
    {
        $document = $this->document($subject, $body, $html, $letterhead);
        foreach (self::FORBIDDEN as $tag) {
            // every "<" of the letter is escaped, so only a bug here could write one
            if (stripos($document, $tag) !== false) {
                throw new \LogicException("The letter came out with $tag");
            }
        }
        return new File(self::NAME, 'text/html', File::MAIN, $document);
    }

    /**
     * The whole HTML document of the letter
     * @param bool   $html       false: $body is plain text - a blank line parts paragraphs, a line end breaks a line
     * @param string $letterhead plain text above the body, in the same way - the sender, an address
     * @throws \InvalidArgumentException not UTF-8, cut off by libxml, empty, larger than a message may be, or
     *                                   of too many pixels; a message may quote the letter - escape it when shown
     * @throws \RuntimeException an image among the uploads cannot be read; the message names it
     */
    public function document(string $subject, string $body, bool $html = true, string $letterhead = ''): string
    {
        // before anything copies or splits them
        if (strlen($letterhead) + strlen($body) > Message::MAX_BYTES) {
            throw new \InvalidArgumentException('The letter is larger than a data message may be');
        }
        foreach (['The letterhead' => $letterhead, 'The letter' => $body] as $what => $text) {
            if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                throw new \InvalidArgumentException("$what is not UTF-8 text");
            }
        }
        $title            = Text::check('The subject', $subject, Message::MAX_ANNOTATION);
        $this->out        = '';
        $this->paragraphs = 0;
        $this->visible    = false;
        $this->pixels     = 0;
        $this->images      = [];
        $this->backgrounds = [0xFFFFFF];
        $this->omitted     = [];
        $this->append(self::HEAD . '<title>' . self::escape($title) . "</title>\n<style>" . self::CSS . "</style>\n</head>\n<body>\n");
        if (trim($letterhead) !== '') {
            $this->children(self::parse(self::plainText($letterhead)));
            // a letterhead alone is no letter
            $this->visible = false;
        }
        $this->children(self::parse($html ? $body : self::plainText($body)));
        if (!$this->visible) {
            throw new \InvalidArgumentException('The letter is empty');
        }
        $this->append("\n</body>\n</html>\n");
        $document  = $this->out;
        $this->out = '';
        return $document;
    }

    /** @return list<string> what the last letter lost of its HTML, one short reason each; reasons quote the
     *                       letter (links, image names) - escape them when shown */
    public function omitted(): array
    {
        return array_keys($this->omitted);
    }

    /**
     * The <html> of $html as the HTML parser of libxml reads it
     * @throws \InvalidArgumentException
     */
    private static function parse(string $html): \DOMElement
    {
        // libxml leaves out everything after a </body> or </html>, wherever it stands - after one without
        // ">" there is nothing left anyway. *+ and \z: without PCRE JIT, which AA switches off, [^>]*> would
        // search the rest of the letter for ">" from every "</body", minutes for a few MB
        $html = (string) preg_replace('~</(?:body|html)\b[^>]*+(?:>|\z)~i', '', self::windows1252($html));
        if (substr_count($html, '<') > self::MAX_TAGS) {
            throw new \InvalidArgumentException('The letter has more than ' . self::MAX_TAGS . ' tags or lines - attach a long table as a file');
        }
        self::checkAttributes($html);
        return Libxml::run(static function () use ($html): \DOMElement {
            $dom = new \DOMDocument();
            // the charset first: a <meta> in the letter declaring another one comes too late
            $dom->loadHTML("<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body>$html</body></html>");
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                // libxml 2.12 stops at about 10 MB, mostly of images pasted as data: URIs
                if (str_contains($error->message, 'Huge input lookup')) {
                    throw new \InvalidArgumentException('The letter is too large to process - upload the images instead of pasting them into the text');
                }
            }
            foreach ($errors as $error) {
                if (($error->level === LIBXML_ERR_FATAL) || in_array($error->code, self::CUT_OFF, true)) {
                    throw new \InvalidArgumentException('The letter cannot be read whole: ' . Libxml::cut(trim($error->message), 200));
                }
            }
            return $dom->documentElement ?? throw new \LogicException('libxml made no <html>');
        });
    }

    /**
     * Refuses too many attributes before libxml reads them. A tag is taken to begin at every "<" before
     * a letter (for older libxml also before "_", ":" or "."), also in a comment, a <script> or a quoted value: what libxml reads as text there differs
     * among its versions, so nothing may hide a tag from the scan - and no letter has a tag of a thousand
     * attributes in a comment. The count is an upper bound: a lone quote in a comment followed by a paragraph
     * of over 1000 words without a tag refuses a letter libxml would read. Of the tags in one state of SCAN
     * only the one of the most attributes is followed, so every byte is read a bounded number of times.
     * @throws \InvalidArgumentException
     */
    private static function checkAttributes(string $html): void
    {
        $length = strlen($html);
        $states = [];   // state => attributes of the tag read through it
        $stops  = [];   // state => the next byte it reads
        $all   = ['l' => 0, 'h' => 0];
        $lt    = strpos($html, '<');
        while (($i = min([...$stops, ($lt === false) ? $length : $lt])) < $length) {
            $byte    = $html[$i];
            $next    = $html[$i + 1] ?? '';
            $entered = [];
            foreach (array_keys($stops, $i, true) as $state) {
                $count = $states[$state];
                $model = $state[0];
                unset($states[$state], $stops[$state]);
                do {
                    [$state, $begun, $again] = self::step($state, $byte, $next);
                    $count += $begun;
                } while ($again);
                if ($state === null) {
                    $all[$model] += $count;
                } else {
                    $entered[$state] = max($entered[$state] ?? 0, $count);
                }
            }
            if ($i === $lt) {
                if (strspn($html, self::LETTERS, $i + 1, 1) === 1) {
                    $entered += ['lTag' => 0, 'hTag' => 0];
                } elseif (strspn($html, self::NAME_START, $i + 1, 1) === 1) {
                    // libxml 2.9.14 reads these as text, an older one may begin a tag
                    $entered += ['lTag' => 0];
                } elseif (($next === '/') && (strspn($html, self::LETTERS, $i + 2, 1) === 1)) {
                    $entered += ['hSlash' => 0];
                }
                $lt = strpos($html, '<', $i + 1);
            }
            foreach ($entered as $state => $count) {
                if ($count > self::MAX_ATTRIBUTES) {
                    throw new \InvalidArgumentException('The letter has a tag of more than ' . self::MAX_ATTRIBUTES . ' attributes - paste the text without its formatting');
                }
                $states[$state] = max($states[$state] ?? 0, $count);
                [$spn, $bytes, $max] = self::SCAN[$state] + [2 => null];
                // a state already followed passes over the same bytes from here
                $stops[$state] ??= $i + 1 + ($spn ? strspn($html, $bytes, $i + 1, $max) : strcspn($html, $bytes, $i + 1));
            }
            if (max($all) > self::MAX_ALL_ATTRIBUTES) {
                break;
            }
        }
        foreach ($states as $state => $count) {
            $all[$state[0]] += $count;
        }
        if (max($all) > self::MAX_ALL_ATTRIBUTES) {
            throw new \InvalidArgumentException('The letter has more than ' . self::MAX_ALL_ATTRIBUTES . ' attributes - paste the text without its formatting');
        }
    }

    /**
     * $byte read in $state of SCAN
     * @return array{?string, int, bool} the next state (null: the tag ends), attributes begun, whether the
     *                                   next state reads $byte again
     */
    private static function step(string $state, string $byte, string $next): array
    {
        $white = str_contains(self::WHITE, $byte);
        $blank = $white && ($byte !== "\f");
        $end   = [null, 0, false];
        return match ($state) {
            'lTag'           => ['lBefore', 0, true],
            // a word libxml 2.9-2.13 cannot read as a name is no attribute there, here it is one: a tag of
            // such words alone would otherwise cost a step each without a bound
            'lBefore'        => match (true) {
                $blank                                                  => ['lBefore', 0, false],
                ($byte === '>') || (($byte === '/') && ($next === '>')) => $end,
                str_contains(self::NAME_START, $byte)                  => ['lName', 1, false],
                default                                                => ['lBogus', 1, false],
            },
            'lBogus', 'lUnq' => match (true) {
                $byte === '>' => $end,
                $blank        => ['lBefore', 0, false],
                default       => [$state, 0, false],
            },
            'lName'          => match (true) {
                $blank        => ['lAfter', 0, false],
                $byte === '=' => ['lEq', 0, false],
                default       => ['lBefore', 0, true],
            },
            'lAfter'         => ($byte === '=') ? ['lEq', 0, false] : ['lBefore', 0, true],
            'lEq'            => match ($byte) {
                '"'     => ['lDq', 0, false],
                "'"     => ['lSq', 0, false],
                default => ['lUnq', 0, true],
            },
            'lDq', 'lSq'     => ['lBefore', 0, false],
            'hSlash'         => ['hTag', 0, false],
            'hTag'           => ($byte === '>') ? $end : ['hBefore', 0, false],
            // HTML5: "/" outside a value parts attributes, or ends the tag before ">"
            'hBefore'        => match (true) {
                $byte === '>'              => $end,
                $white || ($byte === '/') => ['hBefore', 0, false],
                default                    => ['hName', 1, false],
            },
            'hName'          => match (true) {
                $byte === '>' => $end,
                $byte === '=' => ['hEq', 0, false],
                $byte === '/' => ['hBefore', 0, false],
                default       => ['hAfter', 0, false],
            },
            'hAfter'         => match ($byte) {
                '>'     => $end,
                '='     => ['hEq', 0, false],
                '/'     => ['hBefore', 0, false],
                default => ['hName', 1, false],
            },
            'hEq'            => match ($byte) {
                '"'     => ['hDq', 0, false],
                "'"     => ['hSq', 0, false],
                '>'     => $end,
                default => ['hUnq', 0, true],
            },
            'hDq', 'hSq'     => ['hQuoted', 0, false],
            'hUnq'           => match (true) {
                $byte === '>' => $end,
                $white        => ['hBefore', 0, false],
                default       => ['hUnq', 0, false],
            },
            'hQuoted'        => ($byte === '>') ? $end : ['hBefore', 0, true],
            default          => throw new \LogicException("No state $state of the scan"),
        };
    }

    /**
     * plain text as HTML: a blank line parts paragraphs, a line end breaks a line
     * @throws \InvalidArgumentException as HTML, larger than a message may be; or PCRE gave up on it
     */
    private static function plainText(string $text): string
    {
        // a line end becomes <br>\n or </p>\n<p>: refused before they are built
        if (8 + 4 * (int) preg_match_all('~\R~u', $text) + self::escapedLength($text) > Message::MAX_BYTES) {
            throw new \InvalidArgumentException('The letter is larger than a data message may be');
        }
        $html   = '';
        $blocks = preg_split('~\R\s*\R~u', trim($text));
        foreach (($blocks === false) ? self::pcreFailed() : $blocks as $block) {
            $lines = preg_replace('~\R~u', "\n", trim($block)) ?? self::pcreFailed();
            $html .= '<p>' . str_replace("\n", "<br>\n", self::escape($lines)) . "</p>\n";
        }
        return $html;
    }

    /** a text PCRE cannot split - a run of a million spaces - would vanish from the letter without a word */
    private static function pcreFailed(): never
    {
        throw new \InvalidArgumentException('The text of the letter cannot be split into lines: ' . preg_last_error_msg());
    }

    /** &#128;-&#159; as browsers read them: the Windows-1252 characters Word writes there („ “ –), not control characters */
    private static function windows1252(string $html): string
    {
        return (string) preg_replace_callback('~&#(?:([0-9]{1,7})|[xX]([0-9a-fA-F]{1,6}));?~', static function (array $m): string {
            $code = ($m[1] !== '') ? (int) $m[1] : (int) hexdec($m[2]);
            // the five codes Windows-1252 leaves free stay the control characters they are, as in browsers
            return (($code >= 0x80) && ($code <= 0x9F)) ? (string) mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252') : $m[0];
        }, $html);
    }

    private function node(\DOMNode $node): void
    {
        if ($node instanceof \DOMText) {
            $this->visible = $this->visible || (preg_match('/[^' . self::INVISIBLE . ']/u', $node->data) === 1);
            // a text node of up to 10 MB may escape to 60
            if (strlen($this->out) + self::escapedLength($node->data) > Message::MAX_BYTES) {
                throw new \InvalidArgumentException('The letter is larger than a data message may be');
            }
            $this->append(self::escape($node->data));
        } elseif ($node instanceof \DOMElement) {
            $this->element($node);
        }
    }

    private function children(\DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            $this->node($child);
        }
    }

    private function element(\DOMElement $element): void
    {
        $tag    = $element->nodeName;
        $suffix = strrchr($tag, ':');
        // libxml 2.9 reads <svg:script> as <script>, later versions keep the prefix
        if (in_array($suffix, [':script', ':style'], true)) {
            $tag = substr($suffix, 1);
        }
        $tag = self::RENAMED[$tag] ?? $tag;
        if (in_array($tag, self::UNSEEN, true)) {
            return;
        }
        if (in_array($tag, self::DROPPED, true)) {
            $this->omit("<$tag> and what it holds");
            return;
        }
        if (in_array($tag, self::UNWRAPPED, true)) {
            $this->omit("<$tag>");
        }
        // a paragraph in a paragraph (Word's <o:p> on libxml 2.9) is no HTML a browser keeps
        if (!isset(self::ELEMENTS[$tag]) || (($tag === 'p') && ($this->paragraphs > 0))) {
            $this->children($element);
            return;
        }
        $attributes = $this->attributes($element, $tag);
        if (($tag === 'img') && !isset($attributes['src'])) {
            return;
        }
        if (($tag === 'a') && !isset($attributes['href'])) {
            $this->children($element);
            return;
        }
        $this->append("<$tag" . implode('', array_map(static fn(string $name, string $value): string => " $name=\"$value\"", array_keys($attributes), $attributes)) . '>');
        if ($tag === 'img') {
            $this->visible = true;
        }
        if (in_array($tag, self::VOID, true)) {
            return;
        }
        $this->backgrounds[] = self::background($attributes) ?? $this->backgrounds[array_key_last($this->backgrounds)];
        $this->paragraphs += ($tag === 'p') ? 1 : 0;
        $this->children($element);
        $this->paragraphs -= ($tag === 'p') ? 1 : 0;
        array_pop($this->backgrounds);
        $this->append("</$tag>");
    }

    /** @return array<string, string> name => value, escaped */
    private function attributes(\DOMElement $element, string $tag): array
    {
        $kept = [];
        foreach ($element->attributes as $attribute) {
            $name = $attribute->nodeName;
            if (in_array($name, self::ELEMENTS[$tag], true) || in_array($name, self::GLOBAL, true)) {
                $value = $this->value($name, trim((string) $attribute->nodeValue), $tag);
                if ($value !== null) {
                    // the data: URI of an image is made here, of base64 only
                    $kept[$name] = ($name === 'src') ? $value : self::escape($value);
                }
            }
        }
        return $kept;
    }

    /** $value of the attribute $name of a <$tag> as it may stay, null = left out */
    private function value(string $name, string $value, string $tag): ?string
    {
        return match ($name) {
            'href'            => $this->href($value),
            'src'             => $this->image($value),
            'style'           => self::style($value, $tag),
            // an empty alt marks an image that only decorates
            'alt'             => $value,
            'title'           => ($value === '') ? null : $value,
            'lang'            => (preg_match('~^[a-z]{2,3}(?:-[a-z0-9]{1,8})*$~i', $value) === 1) ? $value : null,
            'dir'             => self::oneOf($value, ['ltr', 'rtl', 'auto']),
            'type'            => in_array($value, ['1', 'a', 'A', 'i', 'I'], true) ? $value : null,
            'scope'           => self::oneOf($value, ['row', 'col', 'rowgroup', 'colgroup']),
            'align'           => self::oneOf($value, self::ALIGN),
            'valign'          => self::oneOf($value, self::VALIGN),
            'bgcolor'         => (preg_match(self::COLOUR, $value) === 1) ? $value : null,
            'width', 'height' => (preg_match('~^\d{1,5}%?$~', $value) === 1) ? $value : null,
            'colspan', 'span' => self::span($value, 1000),
            'rowspan'         => self::span($value, 65534),
            default           => (preg_match('~^\d{1,5}$~', $value) === 1) ? $value : null,
        };
    }

    /** a span of cells within the bounds of HTML, 1 to $max; 0 (a rowspan to the end of the table) is left out */
    private static function span(string $value, int $max): ?string
    {
        return ((preg_match('~^\d+$~', $value) === 1) && ((int) $value > 0)) ? (string) min((int) $value, $max) : null;
    }

    /**
     * A link to the web or an e-mail address; a relative one is resolved against the web site
     * @return ?string null = left out
     */
    private function href(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        $parts  = parse_url($href);
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        $short  = self::short($href);
        return match (true) {
            !is_array($parts)                               => $this->omit("the link $short cannot be read"),
            $scheme === 'mailto'                            => $href,
            in_array($scheme, ['http', 'https'], true)      => match (true) {
                ($parts['host'] ?? '') === ''                   => $this->omit("the link $short has no web site"),
                isset($parts['user']) || isset($parts['pass'])  => $this->omit("the link $short hides its web site behind a name"),
                default                                         => $href,
            },
            $scheme !== ''                                  => $this->omit("the link $short is not to a web page or an e-mail address"),
            str_starts_with($href, '#')                     => $this->omit("the link $short leads within a web page, the letter is none"),
            $this->origin === null                          => $this->omit("the link $short is relative, and no web site is given to lead from"),
            default                                         => $this->href($this->resolve($href)),
        };
    }

    /** $href (relative, without a scheme) as an absolute URL on the web site (RFC 3986 5.2) */
    private function resolve(string $href): string
    {
        $origin = (string) $this->origin;
        if (str_starts_with($href, '//')) {
            return strstr($origin, '://', true) . ":$href";
        }
        $cut  = strcspn($href, '?#');
        $path = substr($href, 0, $cut);
        if ($path === '') {
            $path = $this->basePath;
        } elseif ($path[0] !== '/') {
            $path = substr($this->basePath, 0, (int) strrpos($this->basePath, '/') + 1) . $path;
        }
        $segments = explode('/', $path);
        $kept     = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                if (count($kept) > 1) {
                    array_pop($kept);
                }
            } elseif ($segment !== '.') {
                $kept[] = $segment;
            }
        }
        // "a/.." and "a/." name the directory: the slash stays
        if (in_array(end($segments), ['.', '..'], true)) {
            $kept[] = '';
        }
        return $origin . (implode('/', $kept) ?: '/') . substr($href, $cut);
    }

    /** the image as a data: URI, drawn once on each background however often the letter shows it; null = left out */
    private function image(string $src): ?string
    {
        $background = $this->backgrounds[array_key_last($this->backgrounds)];
        $key        = hash('sha256', "$background $src");
        if (!array_key_exists($key, $this->images)) {
            $this->images[$key] = $this->inline($src, $background);
        }
        return $this->images[$key];
    }

    /** @throws \RuntimeException an image among the uploads cannot be read */
    private function inline(string $src, int $background): ?string
    {
        if (preg_match('~^data:[^,]*;base64,~i', $src, $m) === 1) {
            $bytes = base64_decode(substr($src, strlen($m[0])), true);
            return ($bytes === false) ? $this->omit('an image written into the letter is broken') : $this->drawn($bytes, 'an image written into the letter', $background);
        }
        if (($this->uploads === null) || !$this->uploads->contains($src)) {
            return $this->omit('the image ' . self::short($src) . ' is not stored with the letter');
        }
        return $this->drawn($this->uploads->read($src, Message::MAX_BYTES), 'the image ' . self::short($src), $background);
    }

    /**
     * @param array<string, string> $attributes those an element keeps
     * @return ?int 0xRRGGBB of its background; null = see-through or a colour not read, the one around shows
     */
    private static function background(array $attributes): ?int
    {
        $colour = isset($attributes['bgcolor']) ? self::rgb($attributes['bgcolor']) : null;
        // a style wins over bgcolor and its last background counts, as in CSS; a shorthand without a colour is transparent
        foreach (explode('; ', $attributes['style'] ?? '') as $declaration) {
            [$property, $value] = explode(': ', $declaration, 2) + [1 => ''];
            if (in_array($property, ['background', 'background-color'], true)) {
                $colour = self::rgb($value);
            }
        }
        return $colour;
    }

    /** the first colour among the words of a CSS value as 0xRRGGBB: #rgb, #rrggbb, rgb() of numbers or a name of COLOUR_NAMES */
    private static function rgb(string $value): ?int
    {
        preg_match_all('~(?:rgba?|hsla?)\([^)]*\)|[^\s,]+~i', strtolower($value), $words);
        foreach ($words[0] as $word) {
            if (preg_match('~^#(?:[0-9a-f]{3}){1,2}$~', $word) === 1) {
                $hex = substr($word, 1);
                return (int) hexdec((strlen($hex) === 3) ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
            }
            if ((preg_match('~^rgb\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\s*\)$~', $word, $m) === 1) && (max($m[1], $m[2], $m[3]) <= 255)) {
                return ((int) $m[1] << 16) | ((int) $m[2] << 8) | (int) $m[3];
            }
            if (isset(self::COLOUR_NAMES[$word])) {
                return self::COLOUR_NAMES[$word];
            }
        }
        return null;
    }

    /**
     * $bytes drawn again (Imgfunc::reencode()), on $background, at most IMAGE_PX on the longer side: the EXIF of
     * a phone photo (its GPS position) and anything hidden after the image do not reach thousands of offices, and
     * a PDF of the letter does not wait for the transparency of a PNG
     * @throws \InvalidArgumentException the images of the letter have more than MAX_PIXELS together
     */
    private function drawn(string $bytes, string $what, int $background): ?string
    {
        $size          = @getimagesizefromstring($bytes);
        $this->pixels += is_array($size) ? $size[0] * $size[1] : 0;
        if ($this->pixels > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('The images of the letter have more than ' . (self::MAX_PIXELS / 1_000_000) . ' million pixels together - make them smaller or leave some out');
        }
        $dir = sys_get_temp_dir() . '/aa-letter-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0o700)) {
            throw new \RuntimeException('There is no temporary directory for the images of the letter');
        }
        try {
            if (file_put_contents("$dir/in", $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('The images of the letter cannot be stored for a while');
            }
            $error = Imgfunc::reencode("$dir/in", "$dir/out", self::IMAGE_PX, UploadPolicy::ANON_MAX_PIXELS, $background);
            if ($error !== null) {
                return $this->omit("$what: $error");
            }
            $image = (string) file_get_contents("$dir/out");
            $info  = getimagesizefromstring($image);
            return 'data:' . (is_array($info) ? $info['mime'] : 'image/jpeg') . ';base64,' . base64_encode($image);
        } finally {
            @unlink("$dir/in");
            @unlink("$dir/out");
            @rmdir($dir);
        }
    }

    /** declarations of STYLES with plain values only, for a <$tag> */
    private static function style(string $css, string $tag): ?string
    {
        $kept = [];
        foreach (explode(';', $css) as $declaration) {
            [$property, $value] = array_map('trim', explode(':', $declaration, 2)) + [1 => ''];
            $property           = strtolower($property);
            if (in_array($property, self::STYLES, true) && (strlen($value) <= 100) && (preg_match(self::STYLE_VALUE, $value) === 1)) {
                if ($property === 'display') {
                    $value = self::oneOf($value, in_array($tag, self::TABLE_PARTS, true) ? ['none'] : self::DISPLAY);
                }
                if ($value !== null) {
                    $kept[] = "$property: $value";
                }
            }
        }
        return ($kept === []) ? null : implode('; ', $kept);
    }

    /** @param list<string> $allowed lower case */
    private static function oneOf(string $value, array $allowed): ?string
    {
        return in_array(strtolower($value), $allowed, true) ? strtolower($value) : null;
    }

    /** the output grows only here: past the size of a message it stops, long before memory_limit */
    private function append(string $html): void
    {
        if (strlen($this->out) + strlen($html) > Message::MAX_BYTES) {
            throw new \InvalidArgumentException('The letter is larger than a data message may be');
        }
        $this->out .= $html;
    }

    /** null, for the attribute left out */
    private function omit(string $reason): null
    {
        $this->omitted[$reason] = true;
        return null;
    }

    private static function short(string $url): string
    {
        return mb_strimwidth($url, 0, 80, '…', 'UTF-8');
    }

    /** strlen(escape($text)), without building it: up to 6 bytes of one (' is &#039;) */
    private static function escapedLength(string $text): int
    {
        return strlen($text) + 5 * (substr_count($text, '"') + substr_count($text, "'")) + 4 * substr_count($text, '&') + 3 * (substr_count($text, '<') + substr_count($text, '>'));
    }

    /** like safe(), but &#039; for an apostrophe: the HTML 4 viewers of some filing systems show &apos; as it is */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }
}
