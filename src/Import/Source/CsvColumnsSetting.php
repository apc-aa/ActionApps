<?php

declare(strict_types=1);

namespace AA\Import\Source;

use AA\Import\Mapping;
use AA_Content;
use AA_Field;
use AA_Validate;

/**
 * The validator of the "columns" setting of CsvurlSource: a JSON object {"<CSV column>": "<field id>"}.
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

    public function validate(?string &$var, ?AA_Content $content_context = null, string $var_id = ''): bool
    {
        return (self::parse((string) $var) !== null) ? true : self::bad(VALIDATE_ERROR_BAD_TYPE, _m('Expected a JSON object {"column": "field id"}'), $var_id);
    }

    /** @return ?array<string, string> column => field id, null when not valid */
    public static function parse(string $json): ?array
    {
        $decoded = json_decode($json, false, 3);
        if (!($decoded instanceof \stdClass)) {
            return null;
        }
        $pairs = get_object_vars($decoded);
        if (($pairs === []) || (count($pairs) > self::MAX_COLUMNS)) {
            return null;
        }
        $system  = Mapping::systemFields();
        $columns = [];
        foreach ($pairs as $column => $field_id) {
            $column = (string) $column;   // a numeric name comes back as an int key
            if (!is_string($field_id) || !AA_Field::isValidFieldId($field_id) || isset($system[$field_id]) || !self::isName($column)) {
                return null;
            }
            $columns[$column] = $field_id;
        }
        return $columns;
    }

    private static function isName(string $column): bool
    {
        return ($column !== '') && (mb_strlen($column) <= self::MAX_NAME) && (preg_match('/[\p{Cc}\p{Cf}]|^[\s\p{Z}]|[\s\p{Z}]$/u', $column) === 0);
    }
}
