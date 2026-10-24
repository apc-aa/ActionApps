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

    /**
     * characters a page holds at 6 pt and line-height 1, a row of cells beside each other too: a narrow cell
     * of them all took dompdf 1.9 s
     */
    private const ROW_CHARS = 16_000;

    /**
     * Refuses a row of more text than a page holds - at no font size a letter is readable in could it fit -,
     * and counts the fields of the cell maps dompdf keeps
     * @param string $markup a document of HtmlBody, best without the data: of its images
     * @return int rows × columns of all tables, the columns of a row with the cells spanning it from above
     * @throws \InvalidArgumentException LONG_CELL
     */
    public static function check(string $markup): int
    {
        if (!str_contains($markup, '<table')) {
            return 0;
        }
        $xpath = Libxml::run(static function () use ($markup): \DOMXPath {
            $dom = new \DOMDocument();
            // HtmlBody writes the charset first
            $dom->loadHTML($markup);
            return new \DOMXPath($dom);
        });
        $grid = 0;
        foreach (self::elements($xpath, '//table') as $t => $table) {
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
                    $rowspan = (int) $cell->getAttribute('rowspan');
                    if ($rowspan > 1) {
                        $above[] = [self::colspan($cell), $rowspan - 1];
                    }
                }
                if ($chars > self::ROW_CHARS) {
                    throw new \InvalidArgumentException(self::at(self::LONG_CELL, [$t + 1, $r + 1]));
                }
                $columns = max($columns, $span);
            }
            $grid += count($rows) * $columns;
        }
        return $grid;
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

    private static function colspan(\DOMElement $cell): int
    {
        return max(1, (int) $cell->getAttribute('colspan'));
    }
}
