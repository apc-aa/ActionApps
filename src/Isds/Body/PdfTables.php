<?php

declare(strict_types=1);

namespace AA\Isds\Body;

use AA\Util\Libxml;

/**
 * Tables of a letter before dompdf lays them out. dompdf never splits a table row between pages: what
 * does not fit is drawn below the page, where no reader sees it, in time growing with the square of
 * the text of the cell.
 */
final class PdfTables
{
    public const LONG_CELL  = 'A table cell is longer than a page - split it or take the text out of the table';
    public const SPAN_SPLIT = 'A merged table cell (rowspan) cannot cross a page - split the table there';
    /** the rows of a table, in the order they are written */
    public const ROWS = './tr|./thead/tr|./tbody/tr|./tfoot/tr';
    /** a width of a style in pixels or points, as HtmlBody writes it */
    private const WIDTH = '~((?<![\w-])width: )([0-9.]+)(px|pt)\b~';
    /** what HtmlBody lets libxml keep in a table, a part of it and a row, besides blank text */
    private const PARTS = [
        'table'    => ['caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr'],
        'thead'    => ['tr'],
        'tbody'    => ['tr'],
        'tfoot'    => ['tr'],
        'colgroup' => ['col'],
        'tr'       => ['td', 'th'],
    ];

    /**
     * characters a page holds at 6 pt and line-height 1, a row of cells beside each other too: a narrow cell
     * of them all took dompdf 1.9 s
     */
    private const ROW_CHARS = 16_000;

    /**
     * Refuses a row of more text than a page holds - at no font size a letter is readable in could it fit -,
     * and counts what dompdf lays out of the tables flow() leaves tables
     * @param string $markup a document of HtmlBody, best without the data: of its images
     * @return array{grid: int, rows: int, cells: int} grid = rows × columns of each table, the columns of a row
     *                                                 with the cells spanning it from above; cells = colspan × rowspan
     *                                                 of each, and the span of columns
     * @throws \InvalidArgumentException LONG_CELL
     */
    public static function check(string $markup): array
    {
        $count = ['grid' => 0, 'rows' => 0, 'cells' => 0];
        if (!str_contains($markup, '<table')) {
            return $count;
        }
        $xpath = Libxml::run(static function () use ($markup): \DOMXPath {
            $dom = new \DOMDocument();
            // HtmlBody writes the charset first
            $dom->loadHTML($markup);
            return new \DOMXPath($dom);
        });
        foreach (self::elements($xpath, '//table') as $t => $table) {
            // flow() makes blocks of it: its text runs over pages
            if (self::oneCell($table)) {
                continue;
            }
            $columns = 1;
            $rows    = self::elements($xpath, self::ROWS, $table);
            // [columns, rows still to come] of each cell spanning rows below its own
            $above = [];
            foreach ($rows as $r => $row) {
                $span  = array_sum(array_column($above, 0));
                $above = array_values(array_filter(array_map(static fn(array $a): array => [$a[0], $a[1] - 1], $above), static fn(array $a): bool => $a[1] > 0));
                $chars = 0;
                foreach (self::elements($xpath, './td|./th', $row) as $cell) {
                    $span   += self::colspan($cell);
                    $chars  += mb_strlen($cell->textContent, 'UTF-8');
                    $rowspan = max(1, (int) $cell->getAttribute('rowspan'));
                    $count['cells'] += self::colspan($cell) * $rowspan;
                    if ($rowspan > 1) {
                        $above[] = [self::colspan($cell), $rowspan - 1];
                    }
                }
                if ($chars > self::ROW_CHARS) {
                    throw new \InvalidArgumentException(self::at(self::LONG_CELL, [$t + 1, $r + 1]));
                }
                $columns = max($columns, $span);
            }
            foreach (self::elements($xpath, './col|./colgroup|./colgroup/col', $table) as $col) {
                $count['cells'] += max(1, (int) $col->getAttribute('span'));
            }
            $count['grid'] += count($rows) * $columns;
            $count['rows'] += count($rows);
        }
        return $count;
    }

    /**
     * The document as dompdf is to lay it out. A table of one cell a row, the frame of a letter from an e-mail
     * or Word, becomes blocks, so that its text runs over pages; display:block would not do, dompdf adds a
     * <tbody> of its own to it and fails. A template wider than a line, like e-mails of 700-800 px, is made
     * narrower in its proportions: a table clamped alone would still be widened by the widths of its cells.
     * @param string $document of HtmlBody::document()
     * @return array{string, list<int>} the document and the number of each table it keeps, as check() counts them
     *                                  in $document - a refusal names the table the editor sees
     * @throws ServerException PCRE or libxml failed, or a link or image did not come back
     */
    public static function flow(string $document): array
    {
        if (!str_contains($document, '<table')) {
            return [$document, []];
        }
        // links and images aside, as HtmlBody wrote them: libxml would copy megabytes of images and write
        // a link of its own (%-escaped); a text of the letter cannot guess the token
        $token  = bin2hex(random_bytes(8));
        $values = [];
        $markup = preg_replace_callback('~ (src|href)="([^"]*+)"~', static function (array $m) use (&$values, $token): string {
            $values[] = $m[2];
            return " $m[1]=\"$token" . (count($values) - 1) . '"';
        }, $document);
        if ($markup === null) {
            throw new ServerException('The letter cannot be measured for a PDF');
        }
        [$dom, $xpath] = Libxml::run(static function () use ($markup): array {
            $dom = new \DOMDocument();
            $dom->loadHTML($markup);
            return [$dom, new \DOMXPath($dom)];
        });
        $tables  = self::elements($xpath, '//table');
        $changed = self::narrow($xpath);
        $kept    = [];
        // the innermost first: a table in a cell is decided by its own rows
        foreach (array_reverse($tables, true) as $t => $table) {
            if (self::oneCell($table)) {
                self::blocks($dom, $xpath, $table);
                $changed = true;
            } else {
                $kept[] = $t + 1;
            }
        }
        if (!$changed) {
            return [$document, array_reverse($kept)];
        }
        $html   = $dom->saveHTML();
        $flowed = ($html === false) ? null : preg_replace_callback('~ (src|href)="' . $token . '(\d+)"~', static fn(array $m): string => " $m[1]=\"" . $values[(int) $m[2]] . '"', $html, -1, $count);
        if (($flowed === null) || ($count !== count($values))) {
            throw new ServerException('The links and images of the letter cannot be laid out as a PDF');
        }
        return [$flowed, array_reverse($kept)];
    }

    /**
     * $message with the place it is about: numbers only, never a word of the letter
     * @param array{}|array{int, int} $place table and row, counted from 1 in the order they are written
     */
    public static function at(string $message, array $place): string
    {
        return ($place === []) ? $message : sprintf('%s (table %d, row %d)', $message, ...$place);
    }

    /** @return list<\DOMElement> */
    public static function elements(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): array
    {
        $found = [];
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $node;
            }
        }
        return $found;
    }

    /**
     * Each row one cell, spanning no other, and nothing else in the table: a frame around the letter, not a
     * table of data. An empty row has no column; a cell or a table libxml left outside a row keeps it a table.
     */
    private static function oneCell(\DOMElement $table): bool
    {
        $cells = 0;
        $parts = [$table];
        while (($part = array_pop($parts)) !== null) {
            $found = 0;
            foreach ($part->childNodes as $child) {
                if (($child instanceof \DOMText) && (trim($child->data) === '')) {
                    continue;
                }
                if (!($child instanceof \DOMElement) || !in_array($child->nodeName, self::PARTS[$part->nodeName], true)) {
                    return false;
                }
                if (isset(self::PARTS[$child->nodeName])) {
                    $parts[] = $child;
                } elseif (($part->nodeName === 'tr') && ((++$found > 1) || (self::colspan($child) > 1) || ((int) $child->getAttribute('rowspan') > 1))) {
                    return false;
                }
            }
            $cells += ($part->nodeName === 'tr') ? $found : 0;
        }
        return $cells > 0;
    }

    /** $table and its own parts as <div>, what their attributes did as a style; nested tables are left as they are */
    private static function blocks(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $table): void
    {
        $border  = (int) $table->getAttribute('border');
        $padding = $table->getAttribute('cellpadding');
        // nothing in them, oneCell() made sure
        foreach (self::elements($xpath, './col|./colgroup', $table) as $col) {
            $col->parentNode?->removeChild($col);
        }
        foreach ([$table, ...self::elements($xpath, './caption|./thead|./tbody|./tfoot|' . self::ROWS . '|./tr/td|./tr/th|./*/tr/td|./*/tr/th', $table)] as $element) {
            $name  = $element->nodeName;
            $cell  = ($name === 'td') || ($name === 'th');
            $style = match ($name) {
                // a table starts below a floating image (PdfBody::CSS)
                'table'   => ['clear: both', ($border > 0) ? "border: {$border}px solid" : ''],
                'caption' => ['text-align: center'],
                'th'      => ['font-weight: bold', 'text-align: center'],
                default   => [],
            };
            $style[] = ($cell && ($border > 0)) ? 'border: 1px solid' : '';
            $style[] = ($cell && ctype_digit($padding)) ? "padding: {$padding}px" : '';
            $align   = $element->getAttribute('align');
            $style[] = match (($name === 'table') ? $align : '') {
                'center' => 'margin-left: auto; margin-right: auto',
                'right'  => 'margin-left: auto',
                default  => (($name !== 'table') && ($align !== '')) ? "text-align: $align" : '',
            };
            $style[] = ($element->getAttribute('bgcolor') !== '') ? 'background-color: ' . $element->getAttribute('bgcolor') : '';
            // the size of a table and its parts is the least they take, a block of the size would break its words
            // apart; in pixels or % since narrow()
            foreach (['width', 'height'] as $size) {
                $value   = $element->getAttribute($size);
                $style[] = ((float) $value > 0) ? "min-$size: $value" : '';
            }
            $style[] = (string) preg_replace('~(?<![\w-])(width|height):~', 'min-$1:', $element->getAttribute('style'));
            $div     = $dom->createElement('div');
            $css     = implode('; ', array_filter($style, static fn(string $declaration): bool => $declaration !== ''));
            if ($css !== '') {
                $div->setAttribute('style', $css);
            }
            while ($element->firstChild !== null) {
                $div->appendChild($element->firstChild);
            }
            $element->parentNode?->replaceChild($div, $element);
        }
    }

    /**
     * A width or height of a number alone in pixels, as the editor shows it - dompdf would read it in points. In a
     * table wider than a line of the page, by itself, a table or cell in it or the cells of a row, all absolute
     * widths made smaller in one proportion. A <col> only gives its width to dompdf, which does not read it.
     * @return bool whether anything was changed
     */
    private static function narrow(\DOMXPath $xpath): bool
    {
        $changed = false;
        foreach (self::elements($xpath, '//table[not(ancestor::table)]') as $outer) {
            $sized  = self::elements($xpath, 'descendant-or-self::table|.//td|.//th', $outer);
            $widest = max([0.0, ...array_map(self::points(...), $sized)]);
            foreach (self::elements($xpath, './/tr', $outer) as $row) {
                $widest = max($widest, array_sum(array_map(self::points(...), self::elements($xpath, './td|./th', $row))));
            }
            $scale = ($widest > PdfBody::LINE_WIDTH) ? PdfBody::LINE_WIDTH / $widest : 1.0;
            foreach ($sized as $element) {
                foreach (['width' => $scale, 'height' => 1.0] as $name => $by) {
                    $value = $element->getAttribute($name);
                    if (ctype_digit($value)) {
                        $element->setAttribute($name, (((int) $value === 0) ? 0 : max(1, (int) floor((int) $value * $by))) . 'px');
                        $changed = true;
                    }
                }
                if (($scale < 1) && $element->hasAttribute('style')) {
                    $element->setAttribute('style', (string) preg_replace_callback(
                        self::WIDTH,
                        static fn(array $m): string => $m[1] . max(1, (int) floor((float) $m[2] * $scale)) . $m[3],
                        $element->getAttribute('style'),
                    ));
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /** points of the absolute width of $element, 0 = none: its last width of the style, else its attribute in pixels */
    private static function points(\DOMElement $element): float
    {
        if (preg_match_all('~(?<![\w-])width: ([^;]*)~', $element->getAttribute('style'), $widths) > 0) {
            return (preg_match('~^([0-9.]+)(px|pt)$~', (string) end($widths[1]), $m) === 1) ? (float) $m[1] * (($m[2] === 'px') ? 0.75 : 1) : 0.0;
        }
        $width = $element->getAttribute('width');
        return ctype_digit($width) ? (int) $width * 0.75 : 0.0;
    }

    private static function colspan(\DOMElement $cell): int
    {
        return max(1, (int) $cell->getAttribute('colspan'));
    }
}
