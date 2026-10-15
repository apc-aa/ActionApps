<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use Cvarset;

/** Fix field table duplicate keys */
class FieldDuplicates extends \AA\Optimize\AbstractOptimize
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Fix field definitions duplicates");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("There should be only one slice_id - field_id pair in all slices, but sometimes there are more than one (mainly because of error in former sql_update.php script, where more than one display_count... fields were added).");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array
    {
        return ['sql_update', self::CHECK_BASIC, self::CHECK_BASIC];
    }

    /** the optimizers are ordered by priority (less first) when executed
     *  in chain (like for sql_update)
     *  Default value is 100
     *  Execute this first
     */
    public static function priority(): int
    {
        return 10;
    }  // big priority

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        $duplicates = $this->_check_table();

        if (!count($duplicates)) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        foreach ($duplicates as $dup) {
            $this->message(_m('Duplicate in slice - field: %1 - %2', [unpack_id($dup[0]), $dup[1]]));
        }
        return false;
    }

    public function repair(): bool
    {
        $varset = new Cvarset();
        // $varset->setDebug();

        $duplicates = $this->_check_table();
        if (!count($duplicates)) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        $fixed = [];
        foreach ($duplicates as $dup) {
            if ($fixed[$dup[0] . $dup[1]]) {
                // already fixed
                continue;
            }
            $fixed[$dup[0] . $dup[1]] = true;

            // a NULL column is copied as '' (0 in a number column), not left to its DEFAULT
            $row = array_map(static fn($v) => $v ?? '', $dup[2]);
            $row['slice_id'] = bin2hex($row['slice_id']);   // not unpack_id(): it turns a numeric-looking id into '0'
            DB_AA::transaction(static function () use ($varset, $dup, $row) {
                $varset->doDeleteWhere('field', "slice_id='" . quote($dup[0]) . "' AND id='" . quote($dup[1]) . "'");
                AA::Metabase()->doInsert('field', $row);
            });
            $this->message(_m('Field %2 in slice %1 fixed', [unpack_id($dup[0]), $dup[1]]));
        }
        return true;
    }

    public function _check_table(): array
    {
        // does the table exist at all?
        $field_exists = DB_AA::select([], "SHOW TABLES LIKE 'field'");
        if (!$field_exists) {
            return [];
        }
        $fields = DB_AA::select([], "SELECT * FROM field ORDER BY slice_id, id");

        $field_table = [];
        $duplicates = [];
        foreach ($fields as $field) {
            $sid = $field['slice_id'];
            $fid = $field['id'];
            if (!isset($field_table[$sid])) {
                $field_table[$sid] = [];
            }
            if (isset($field_table[$sid][$fid])) {
                $duplicates[] = [$sid, $fid, $field_table[$sid][$fid]];
            } else {
                $field_table[$sid][$fid] = $field;
            }
        }
        return $duplicates;
    }
}
