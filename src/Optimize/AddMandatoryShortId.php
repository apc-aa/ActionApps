<?php

namespace AA\Optimize;

use function _m;
use function quote;

/** Add mandtory fields to each slice where missing */
class AddMandatoryShortId extends \AA\Optimize\AddMandatoryStatusCode {
    public static function name(): string { return _m("Fixing missing %1 field definitions in slices", ['short_id........']); }

    function _field() { return 'short_id........'; }

    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('short_id........', '', '" . quote($sid) . "', 'Short ID', 5090, 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)"; }
}