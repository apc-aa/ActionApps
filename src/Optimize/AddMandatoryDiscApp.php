<?php

namespace AA\Optimize;

use function _m;
use function quote;

/** Add mandtory fields to each slice where missing */
class AddMandatoryDiscApp extends \AA\Optimize\AddMandatoryStatusCode {
    public static function name(): string { return _m("Fixing missing %1 field definitions in slices", ['disc_app........']); }

    function _field() { return 'disc_app........'; }

    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_app........', '', '" . quote($sid) . "', 'Approved Comments Count', '5070', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1')"; }
}