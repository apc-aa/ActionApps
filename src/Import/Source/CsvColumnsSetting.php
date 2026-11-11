<?php

declare(strict_types=1);

namespace AA\Import\Source;

use AA\Import\Mapping;
use AA_Content;
use AA_Field;
use AA_Validate;

/**
 * The "columns" setting of CsvurlSource: one line per column, "<CSV column> = <field id>"; a JSON
 * object {"<CSV column>": "<field id>"}, as the setting was stored before, reads the same.
 * A column name is only looked up among the column names of the file, never expanded, so it may
 * hold any text but:
 * - control and format characters (\p{Cc}, \p{Cf}: zero-width space, BOM, bidi controls) and spaces
 *   at its ends - invisible, the name would look right and match no column;
 * - more than MAX_NAME characters.
 * A target is an ordinary field: the fields kept in the item table (status, dates, author, ...)
 * would let the data of the file move items between bins or reach output unescaped.
 */
final class CsvColumnsSetting extends AA_Validate
{
    /** as many as a mapping may have rows */
    public const MAX_COLUMNS = 200;

    public const MAX_NAME = 100;

    /** Lets any text through: the source refuses the columns as a whole and names the line at fault
     *  (CsvurlSource::fromSettings()); a refusal here would only say which setting is wrong. */
    public function validate(?string &$var, ?AA_Content $content_context = null, string $var_id = ''): bool
    {
        return true;
    }

    /**
     * @param ?string $why set to what is wrong, in English, when not valid - with the line in the lines form
     * @return ?array<string, string> column => field id, null when not valid
     */
    public static function parse(string $text, ?string &$why = null): ?array
    {
        $why   = null;
        $pairs = str_starts_with(ltrim($text), '{') ? self::jsonPairs($text) : self::linePairs($text, $why);
        if ($pairs === null) {
            $why ??= 'not a JSON object {"column": "field id"}';
            return null;
        }
        if (($pairs === []) || (count($pairs) > self::MAX_COLUMNS)) {
            $why = ($pairs === []) ? 'no column' : 'more than ' . self::MAX_COLUMNS . ' columns';
            return null;
        }
        $system  = Mapping::systemFields();
        $columns = [];
        foreach ($pairs as [$line, $column, $field_id]) {
            $why = match (true) {
                !is_string($field_id) || !AA_Field::isValidFieldId($field_id) || isset($system[$field_id]) => 'not a field the import may fill',
                !self::isName($column)     => 'the column name is empty, too long or holds invisible characters',
                isset($columns[$column])   => 'the column is named twice',
                default                    => null,
            };
            if ($why !== null) {
                $why = ($line > 0) ? "line $line: $why" : $why;
                return null;
            }
            $columns[$column] = $field_id;
        }
        return $columns;
    }

    /** @return ?list<array{int, string, mixed}> line 0, column, field id */
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
                $why = 'line ' . ($i + 1) . ': expected "column = field id"';
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
