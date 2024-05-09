<?php

namespace AA\Optimize;

use function _m;
use function GetTable2Array;
use function quote;

/** Add mandtory fields to each slice where missing */
class AddMandatoryStatusCode extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Fixing missing %1 field definitions in slices", ['status_code.....']);
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("Adds fields definitions for slices, where missing");
    }

    function _field() {
        return 'status_code.....';
    }

    function _fieldDefinition($sid) {
        return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', '" . quote($sid) . "', 'Status', '5020', '', '', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')";
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }

    /** Test function @return string message */
    public function test(): bool {
        $ret = true;

        $row_count = GetTable2Array("SELECT count(*) as count FROM slice LEFT JOIN field ON (slice.id=field.slice_id AND field.id='" . $this->_field() . "') WHERE field.id IS NULL AND slice.id <> 'AA_Core_Fields..'", "aa_first", 'count');
        if ($row_count > 0) {
            $this->message(_m('Missing %1 field definition in some slices - need to repair', [$this->_field()]));
            $ret = false;
        }
        return $ret;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair(): bool {
        $this->message(_m('Fixing missing %1 fields in slices', [$this->_field()]));

        $slices_to_fix = GetTable2Array("SELECT slice.id as sid FROM slice LEFT JOIN field ON (slice.id=field.slice_id AND field.id='" . $this->_field() . "') WHERE field.id IS NULL", "", 'sid');

        foreach ($slices_to_fix as $sid) {
            if ($sid == 'AA_Core_Fields..') {
                continue;
            }
            $this->query($this->_fieldDefinition($sid));
        }

        // UPDATE ALSO AA_Optimize_Redefine_Field_Templates !!!

        $this->message(_m('Mandatory field %1 added to all slices', [$this->_field()]));
        return true;
    }
}