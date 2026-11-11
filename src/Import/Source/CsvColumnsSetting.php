<?php

declare(strict_types=1);

namespace AA\Import\Source;

use AA\Import\EditedAsText;
use AA\Import\Mapping;
use AA_Content;
use AA_Field;
use AA_Validate;

/**
 * The "columns" setting of CsvurlSource: typed one line per column, "<CSV column> = <field id>",
 * stored as a JSON object {"<CSV column>": "<field id>"} - one form in the database, which an older
 * version of the import reads too. A JSON object typed or stored reads the same.
 * A column name is only looked up among the column names of the file, never expanded, so it may
 * hold any text but:
 * - control and format characters (\p{Cc}, \p{Cf}: zero-width space, BOM, bidi controls) and spaces
 *   at its ends - invisible, the name would look right and match no column;
 * - more than MAX_NAME characters.
 * A target is an ordinary field: the fields kept in the item table (status, dates, author, ...)
 * would let the data of the file move items between bins or reach output unescaped.
 */
final class CsvColumnsSetting extends AA_Validate implements EditedAsText
{
    /** as many as a mapping may have rows */
    public const MAX_COLUMNS = 200;

    public const MAX_NAME = 100;

    /** Refuses the columns naming the line at fault; valid ones are turned into the stored JSON */
    public function validate(?string &$var, ?AA_Content $content_context = null, string $var_id = ''): bool
    {
        $columns = self::parse((string) $var, $why);
        if ($columns === null) {
            return self::bad(VALIDATE_ERROR_BAD_TYPE, (string) $why, $var_id);
        }
        // an object even for numeric column names, which would make a list
        $var = json_encode((object) $columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return true;
    }

    /** the stored JSON as the lines the form shows; anything else as it is */
    public function formText(string $stored): string
    {
        $pairs = self::jsonPairs($stored);
        if ($pairs === null) {
            return $stored;
        }
        return implode("\n", array_map(static fn(array $pair): string => $pair[1] . ' = ' . (is_scalar($pair[2]) ? (string) $pair[2] : ''), $pairs));
    }

    /**
     * @param ?string $why set to what is wrong, in the language of the page, when not valid
     * @return ?array<string, string> column => field id, null when not valid
     */
    public static function parse(string $text, ?string &$why = null): ?array
    {
        $why   = null;
        $pairs = self::jsonPairs($text) ?? self::linePairs($text, $why);
        if ($pairs === null) {
            return null;
        }
        if (($pairs === []) || (count($pairs) > self::MAX_COLUMNS)) {
            $why = ($pairs === []) ? _m('no column') : _m('more than %1 columns', [self::MAX_COLUMNS]);
            return null;
        }
        $system  = Mapping::systemFields();
        $columns = [];
        foreach ($pairs as [$line, $column, $field_id]) {
            $why = match (true) {
                !is_string($field_id) || !AA_Field::isValidFieldId($field_id) || isset($system[$field_id]) => _m('not a field the import may fill'),
                !self::isName($column)     => _m('the column name is empty, too long or holds invisible characters'),
                isset($columns[$column])   => _m('the column is named twice'),
                default                    => null,
            };
            if ($why !== null) {
                $why = ($line > 0) ? self::onLine($line, $why) : $why;
                return null;
            }
            $columns[$column] = $field_id;
        }
        return $columns;
    }

    private static function onLine(int $line, string $why): string
    {
        return _m('Line %1: %2', [$line, $why]);
    }

    /** a JSON object as the pairs, line 0; null for anything else - such text is read as lines
     *  @return ?list<array{int, string, mixed}> */
    private static function jsonPairs(string $json): ?array
    {
        $decoded = json_decode($json, false, 3);
        if (!($decoded instanceof \stdClass)) {
            return null;
        }
        $pairs = [];
        foreach (get_object_vars($decoded) as $column => $field_id) {
            // a numeric name comes back as an int key
            $pairs[] = [0, (string) $column, $field_id];
        }
        return $pairs;
    }

    /** "column = field id" per line, blank lines skipped; the last = splits, as a field id has none
     *  @return ?list<array{int, string, string}> line, column, field id */
    private static function linePairs(string $text, ?string &$why): ?array
    {
        $pairs = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $at = strrpos($line, '=');
            if ($at === false) {
                $why = self::onLine($i + 1, _m('expected "column = field id"'));
                return null;
            }
            $pairs[] = [$i + 1, trim(substr($line, 0, $at)), trim(substr($line, $at + 1))];
        }
        return $pairs;
    }

    private static function isName(string $column): bool
    {
        return ($column !== '') && (mb_strlen($column) <= self::MAX_NAME) && (preg_match('/[\p{Cc}\p{Cf}]|^[\s\p{Z}]|[\s\p{Z}]$/u', $column) === 0);
    }
}
