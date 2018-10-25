<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 19:48
 */

namespace AA\Widget;

use AA;
use AA_Components;
use AA_Condition;
use AA_Content;
use AA_Credentials;
use AA\FormArray;
use AA_Item;
use AA_Property;
use AA_Set;
use AA_Slice;
use AA_Value;
use Exception;
use zids;

class Widget extends AA_Components
{

    /** array of possible values (for selectbox, two boxes, ...) */
    var $_const_arr = null;

    /** array(value => true) of all selected values - just for caching */
    var $_selected = null;

    /** $parameters - Array of AA_Property used for the widget
     *   inherited from AA_Components
     */

    /** when widget is used for new item, this variable is used to identify number of the item - aa[n1_...], aa[n2_...]
     *  Good for inserting of multiple items in one form
     */
    var $item_index = 1;

    /** name function
     *
     */
    function name()
    {
    }

    /** necessary for parameter wizard */
    function description()
    {
    }

    /** returns if the widget handles multivalue or just single value   */
    function multiple()
    {
        return false;
    }

    /** array of libs needed for the widget - automaticaly inserted to the inputform */
    function requireLibs()
    {
        return array();
    }

    /** script to run after the page DOM is loaded */
    function requireRun()
    {
        return '';
    }

    /** register Requires */
    public function registerRequires()
    {
        foreach ($this->requireLibs() as $lib) {
            AA::Stringexpander()->addRequire($lib);
        }
        if ($script2run = $this->requireRun()) {
            AA::Stringexpander()->addRequire($script2run, 'AA_Req_Load');
        }
    }

    // not used, yet
    //function assignConstants($arr) {
    //    $this->_const_arr = (array)$arr;
    //}

    /** set index of the item in new-item form */
    public function setIndex($item_index = null)
    {
        $this->item_index = ctype_digit((string)$item_index) ? (int)$item_index : 1;
    }

    /** returns array(ids => formated text) for the current widget based on
     *  the widget settings
     * @param $content AA_Item
     * @param $restrict_zids zids[]
     * @param $searchterm
     * @param $ignore_filters bool
     */
    public function getFormattedOptions($content = null, $restrict_zids = false, $searchterm = '', $ignore_filters = false)
    {

        $values_array = $this->getProperty('const_arr');  // is asociative!
        if (!empty($values_array)) {          // values assigned directly
            return $values_array;               //  = array();
        }

        // commented out - used for Related Item Window values
        // $zids = $ids_arr ? new zids($ids_arr) : false;  // transforms content array to zids
        $ids_arr = false;

        $constgroup = $this->getProperty('const');
        if (!$constgroup) {  // no constants or slice defined
            return array();
        }

        // $restrict_zids could be removed from this check. Honza 2015-06-11
        $filter_conds = ($ignore_filters AND $restrict_zids) ? '' : $this->getProperty('filter_conds');
        // filter_conds_rw - changeable conditions - could come as paremeter widget_properties
        $filter_conds_rw = ($ignore_filters AND $restrict_zids) ? '' : $this->getProperty('filter_conds_rw');
        $sort_by = $this->getProperty('sort_by');
        $slice_field = $this->getProperty('slice_field');


        // AA::$debug && AA::$dbg->log($filter_conds);

        // if variable is for some item, then we can use _#ALIASES_ in conds
        // and sort
        if (is_object($content)) {
            $filter_conds = $content->unalias($filter_conds);
            $filter_conds_rw = $content->unalias($filter_conds_rw);
            $sort_by = $content->unalias($sort_by);
        }

        // "#sLiCe-" prefix indicates select from items
        if (substr($constgroup, 0, 7) == "#sLiCe-") {

            $bin_filter = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
            $tag_prefix = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used
            $crypted_additional_slice_pwd = AA_Credentials::encrypt($this->getProperty('additional_slice_pwd'));

            $sid = substr($constgroup, 7);
            /** Get format for which represents the id
             *  Could be field_id (then it is grabbed from item and truncated to 50
             *  characters, or normal AA format string.
             *  Headline is default (if empty "$slice_field" is passed)
             */
            if (!$slice_field) {
                $slice_field = GetHeadlineFieldID($sid, "headline.");
                if (!$slice_field) {
                    return array();
                }
            }
            $format = AA_Slice::getModule($sid)->getField($slice_field) ? '{substr:{' . $slice_field . '}:0:50}' : $slice_field;
            $set = new AA_Set($sid, $filter_conds, $sort_by, $bin_filter);
            if ($filter_conds_rw) {
                $set->addCondsFromString($filter_conds_rw);
            }

            if ($searchterm) {
                // $sf = AA_Slice::getModule($sid)->getField($slice_field) ? $slice_field : GetHeadlineFieldID($sid, "headline.");
                $set->addCondition(new AA_Condition('all_fields', 'LIKE', "\"$searchterm\""));
            }
            return GetFormatedItems($set->query($restrict_zids), $format, $crypted_additional_slice_pwd, $tag_prefix);
        }
        return GetFormatedConstants($constgroup, $slice_field, $ids_arr, $filter_conds, $sort_by);
    }

    /** Fills array used for list selection. Fill it from constant group or
     * slice.
     * It never refills the array (and we relly on this fact in the code)
     * This function is rewritten fill_const_arr().
     */
    protected function _fillConstArr($content)
    {
        if (!is_array($this->_const_arr)) {  // already filled
            // not filled, yet - so fill it
            $this->_const_arr = $this->getFormattedOptions($content);  // Initialize
        }
    }

    /** returns $ret_val if given $option is selected for current field
     *  This method is rewritten if_selected() method form formutil.php3
     */
    function ifSelected($option, $ret_val)
    {
        return (strlen($option) AND $this->_selected[(string)$option]) ? $ret_val : '';
    }

    /**
     *  This method is rewritten _fillSelected() method form formutil.php3
     * @param $aa_value AA_Value
     */
    function _fillSelected($aa_value)
    {
        $this->_selected = array();
        //if ( is_null($this->_selected) ) {  // not cached yet => create selected array
        if (is_object($aa_value)) {
            for ($i = 0, $ino = $aa_value->count(); $i < $ino; ++$i) {
                $val = $aa_value->getValue($i);
                if (strlen($val)) {
                    $this->_selected[(string)$val] = true;
                }
            }
        }
        //}
    }

    /** returns options array with marked selected options, missing options,...
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getOptions($selected = null, $content = null, $use_name = false, $testval = false, $add_empty = false)
    {
        $selectedused = false;

        $already_selected = array();     // array where we mark selected values
        $pair_used = array();     // array where we mark used pairs
        $this->_fillSelected($selected); // fill selected array by all aa_values in order we can print invalid values later

        $ret = array();
        $this->_fillConstArr($content);
        if (is_array($this->_const_arr)) {
            foreach ($this->_const_arr as $k => $v) {
                if ($use_name) {
                    // special parameter to use values instead of keys
                    $k = $v;
                }

                // ignore pairs (key=>value) we already used
                if ($pair_used[$k . "aa~$v"]) {
                    continue;
                }
                $pair_used[$k . "aa~$v"] = true;   // mark this pair - do not use it again

                $select_val = $testval ? $v : $k;
                $selected = $this->ifSelected($select_val, true);
                if ($selected) {
                    $selectedused = true;
                    $already_selected[(string)$select_val] = true;  // flag
                }
                $ret[] = array('k' => $k, 'v' => $v, 'selected' => ($selected ? true : false), 'mis' => false);
            }
        }

        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if (isset($this->_selected) AND is_array($this->_selected)) {
            foreach ($this->_selected as $k => $foo) {
                if (!$already_selected[$k]) {
                    $val_txt = $k;
                    if ($this->getProperty('const') AND (guesstype($k) == 'l')) {
                        $opt = $this->getFormattedOptions(null, new zids($k, 'l'), '', true);
                        if (!strlen($val_txt = $opt[$k])) {
                            $val_txt = $k;
                        }
                    }
                    $ret[] = array('k' => $k, 'v' => $val_txt, 'selected' => true, 'mis' => true);
                    $selectedused = true;
                }
            }
        }

        if ($add_empty == 1) {
            // put empty option to the front
            array_unshift($ret, array('k' => '', 'v' => '', 'selected' => !$selectedused, 'mis' => false));
        } elseif (($add_empty == 2) AND !$selectedused) {
            array_unshift($ret, array('k' => '', 'v' => '', 'selected' => true, 'mis' => false, 'justdefault' => true));
        }
        return $ret;
    }

    /** returns select options created from given array
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getSelectOptions(&$options, $restrict = 'all', $do_not_select = false)
    {

        $select_string = ($do_not_select ? ' class="sel_on"' : ' selected class="sel_on"');

        $ret = '';
        foreach ($options as $option) {
            if (($restrict == 'selected') AND !$option['selected']) {
                continue;  // do not print this option
            }
            if (($restrict == 'unselected') AND $option['selected']) {
                continue;  // do not print this option
            }
            $selected = $option['selected'] ? $select_string : '';
            $missing = $option['mis'] ? ' class="sel_missing"' : '';
            $default = $option['justdefault'] ? ' hidden disabled' : '';
            $ret .= "<option value=\"" . myspecialchars($option['k']) . "\"$selected$missing$default>" . myspecialchars($option['v']) . "</option>";
        }
        return $ret;
    }

    /**
     * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
     * - for use internal use of FrmInputMultiChBox and FrmInputRadio
     */
    function getInMatrix($records, $ncols, $move_right, $class = '')
    {
        if (is_array($records)) {
            if (!$ncols) {
                return implode('', $records);
            }
            $nrows = ceil(count($records) / $ncols);
            $class = $class ? "class=\"$class\"" : '';
            $ret = "<table border=0 cellspacing=0 $class>";
            for ($irow = 0; $irow < $nrows; ++$irow) {
                $ret .= '<tr>';
                for ($icol = 0; $icol < $ncols; ++$icol) {
                    $pos = ($move_right ? $ncols * $irow + $icol : $nrows * $icol + $irow);
                    $ret .= '<td>' . get_if($records[$pos], "&nbsp;") . '</td>';
                }
                $ret .= '</tr>';
            }
            $ret .= '</table>';
        }
        return $ret;
    }

    static function _saveIcon($base_id, $cond = true, $pos = 'right', $offset = -12)
    {
        return !$cond ? '' : '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . "images/px.gif\" style=\"position:absolute; $pos:$offset" . "px; top:-3px;\">";
    }

    /**
     * @param $aa_property AA_Property
     * @param $content AA_Item
     * @param string $type
     * @return array
     * @throws Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    protected function _getOneInput($input_name, $i, $val, $attr_string, $autofocus, $base_id, $type, $widget_add, $required)
    {
        $input_id = FormArray::formName2Id($input_name);
        $input_value = myspecialchars($val);
        $link = ((substr($input_value, 0, 7) === 'http://') OR (substr($input_value, 0, 8) === 'https://')) ? '&nbsp;' . a_href($input_value, GetAAImage('external-link.png', _m('Show'), 16, 16)) : '';
        $ret = "<input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required $widget_add $autofocus>$link" . Widget::_saveIcon($base_id, $type == 'live'); //, 'right', $link ? 16 : 0);
        if ($lang = AA_Content::getLangId($i)) {
            $ret = "<span class=\"aa-langtrans $lang\"><strong>$lang</strong>$ret</span>";
        }
        return $ret;
    }

    /** @return string widget HTML for using in form
     * @param  $aa_property - the variable
     * @param  $content - contain the value of property to display
     *                       - never empty - it contain at least aa_owner for
     *                         new objects
     */
    function getHtml($aa_property, $content, $function = null)
    {
        return $this->_finalizeHtml($this->_getRawHtml($aa_property, $content), $aa_property);
    }

    /** @return string - just widget HTML for using in form - without label...
     * @param  $aa_property - the variable
     * @param  $content - contain the value of property to display
     *                       - never empty - it contain at least aa_owner for
     *                         new objects
     */
    function getEditHtml($aa_property, $content, $function = null)
    {
        return $this->_finalizeHtml($this->_getRawHtml($aa_property, $content), $aa_property, true);
    }

    /** @return string - just widget HTML for using in form - without label... */
    function getEditfullHtml($aa_property, $content, $function = null)
    {
        return $this->_finalizeHtml($this->_getRawHtml($aa_property, $content), $aa_property);
    }

    /**
     * @param array $winfo
     * @param AA_Property $aa_property
     * @param bool $widget_only
     * @return string
     */
    function _finalizeHtml($winfo, $aa_property, $widget_only = false)
    {
        $base_name = $winfo['base_name'];
        $base_id = FormArray::formName2Id($base_name);
        $required = $winfo['required'];
        $help = $aa_property->getHelp();
        $class = $aa_property->getTranslations() ? 'aa-widget aa-langdiv' : 'aa-widget';

        $ret = "<div class=\"$class\"" . ($required ? ' data-aa-required' : '') . " id=\"widget-$base_id\" data-aa-basename=\"$base_name\">\n";
        $ret .= $widget_only ? '' : "  <label for=\"" . FormArray::formName2Id($winfo['last_input_name']) . "\">" . $aa_property->getName() . "</label>\n";
        $ret .= "  <div class=\"aa-input\">\n";
        $ret .= $winfo['html'] . (($help AND !$widget_only) ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" : '');
        $ret .= "  </div>\n";
        $ret .= "</div>\n";

        return $ret;
    }


    /** @return string - widget HTML for using as AJAX component
     * @param  $aa_property - the variable
     * @param  $content - contain the value of propertyu to display
     */
    function getAjaxHtml($aa_property, $content, $function = null)
    {
        return $this->_finalizeAjaxHtml($this->_getRawHtml($aa_property, $content, 'ajax'), $aa_property);
    }

    /** Creates all common ajax editing buttons to be used by different inputs
     * @param $winfo array
     * @param $aa_property AA_Property
     * @return string
     */
    function _finalizeAjaxHtml($winfo, $aa_property)
    {
        $base_name = $winfo['base_name'];
        $base_id = FormArray::formName2Id($base_name);
        $help = $aa_property->getHelp();
        $class = ($this->getProperty('ajaxtype', '') == 'inline') ? 'aa-ajax-inline' : 'aa-ajax-open';
        $widget_html = "<div class=\"ajax_widget $class\">";
        $widget_html .= "\n" . $winfo['html'] . ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" : '');
        $widget_html .= "\n<div class=ajax_buttons>";
        $widget_html .= "\n<input class=\"save-button\" type=\"submit\" value=\"" . _m('SAVE CHANGE') . "\" onclick=\"AA_SendWidgetAjax('$base_id'); return false;\">"; //Save change
        $widget_html .= "\n<input class=\"cancel-button\" type=\"button\" value=\"" . _m('EXIT WITHOUT CHANGE') . "\" onclick=\"DisplayInputBack('$base_id');\">";
        $widget_html .= "\n</div>";
        $widget_html .= "\n</div>";
        return $widget_html;
    }

    /** @return string - widget HTML for using as Live component (in place editing)
     * @param  $aa_property AA_Property - the variable
     * @param  $content - contain the value of propertyu to display
     */
    function getLiveHtml($aa_property, $content, $function = null)
    {
        // add JS OK Function
        return str_replace('AA_LIVE_OK_FUNC', $function ? $function : "''", $this->_finalizeLiveHtml($this->_getRawHtml($aa_property, $content, 'live'), $aa_property));
    }

    /* Decorates Live Widget. Prepared for overriding in subclasses */
    function _finalizeLiveHtml($winfo, $aa_property)
    {
        $base_id = $winfo['base_id'];
        $help = $aa_property->getHelp();
        $widget_html = $winfo['html']; //. ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" :'');
        $ret = "<div class=\"aa-widget\"" . ($winfo['required'] ? ' data-aa-required' : '') . " id=\"widget-$base_id\" style=\"display:inline; position:relative;\">" . $widget_html . "</div>";
        return $ret;
    }

    /** function which offers filtered selections for current widget */
    function getFilterSelection($searchstring)
    {
        return '';
    }

    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][]=Hi
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][flag]=1
     *   The $data4field is just the last array(0=>Hi, flag=>1)
     *   This method coverts such data to AA_Value.
     *
     *   There could be also compound widgets, which consists from more than one
     *   input - just like date selector. In such case we use following syntax:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
     *   where "dte" points to the AA\Widget\DteWidget. The method AA\Widget\DteWidget::getValue()
     *   is called to grab the value (or multivalues) from the submitted form
     *
     */
    static function getValue($data4field)
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = array();

        foreach ((array)$data4field as $key => $value) {
            if (ctype_digit((string)$key)) {
                $fld_value_arr[$key] = array('value' => $value, 'flag' => $flag);
            } elseif (($key != 'flag') AND class_exists($class = Widget::constructClassName($key))) {
                // call function like AA\Widget\DteWidget::getValue($data)
                // where $data depends on the widget - for example for
                // date it is array('d'=>array(), 'm'=>array(), 'y'=>array())
                $aa_value = $class::getValue($value);
                $aa_value->setFlag($flag);

                // there is no need to go through array - we do not expect more widgets for one variable
                return $aa_value;
            }
        }
        return new AA_Value($fld_value_arr, $flag);
    }

    /** propertiesShop - repository of all used properties of Widgets
     *  ussage:  return AA\Widget\Widget::propertiesShop(array('rows','max_characters'));
     *  most of widgets uses this shop (to coordinate labels and helps), but there is no problem, if you use your own properties in widget, however
     */
    static function propertiesShop($props = array())
    {
        $ret = array();
        foreach ($props as $pid) {
            switch ($pid) {  //  id                                                    name                                    type   multi  persist validator, required, help, morehelp, example
                case 'rows':
                    $ret[$pid] = new AA_Property($pid, _m("Rows"), 'int', false, true, 'int', false, '', '', 10);
                    break;
                case 'max_characters':
                    $ret[$pid] = new AA_Property($pid, _m("Max characters"), 'int', false, true, 'int', false, _m("max count of characters entered (maxlength parameter)"), '', 254);
                    break;
                case 'cols':
                    $ret[$pid] = new AA_Property($pid, _m("Columns"), 'int', false, true, 'int', false, '', '', 70);
                    break;
                case 'const':
                    $ret[$pid] = new AA_Property($pid, _m("Constants or slice"), 'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection"));
                    break;
                case 'const_arr':
                    $ret[$pid] = new AA_Property($pid, _m("Values array"), 'string', true, true, 'string', false, _m("Directly specified array of values like [\"1\",\"2\",\"3\"] or {\"1\":\"Yes\",\"0\":\"No\"}. (do not use Constants, if filled)"), '', '{"1"#:"Yes","0"#:"No"}');
                    break;
                case 'area_type':
                    $ret[$pid] = new AA_Property($pid, _m("Type"), 'string', false, true, array('enum', array('class' => 'class', 'iframe' => 'iframe')), false, _m("type: class (default) / iframe"), '', 'class');
                    break;
                case 'width':
                    $ret[$pid] = new AA_Property($pid, _m("Width"), 'int', false, true, 'int', false, _m("width of the field in characters (size parameter)"), '', 60);
                    break;
                case 'show_buttons_txt':
                    $ret[$pid] = new AA_Property($pid, _m("Buttons to show"), 'string', false, true, 'string', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete value,<br>A - Add new value<br>C - Change the value<br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."), '', 'MDAC');
                    break;
                case 'row_count':
                    $ret[$pid] = new AA_Property($pid, _m("Row count"), 'int', false, true, 'int', false, '', '', 10);
                    break;
                case 'slice_field':
                    $ret[$pid] = new AA_Property($pid, _m("Slice field"), 'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........');
                    break;
                case 'use_name':
                    $ret[$pid] = new AA_Property($pid, _m("Use name"), 'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0');
                    break;
                case 'adding':
                    $ret[$pid] = new AA_Property($pid, _m("Adding"), 'bool', false, true, 'bool', false, _m("adding the selected items to input field comma separated"), '', '0');
                    break;
                case 'second_field':
                    $ret[$pid] = new AA_Property($pid, _m("Second Field"), 'string', false, true, 'string', false, _m("field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"), '', "source_href.....");
                    break;
                case 'add2constant':
                    $ret[$pid] = new AA_Property($pid, _m("Add to Constant"), 'bool', false, true, 'bool', false, _m("if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"), '', "0");
                    break;
                case 'bin_filter':
                    $ret[$pid] = new AA_Property($pid, _m("Show items from bins"), 'int', false, true, 'int', false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3');
                    break;
                case 'filter_conds':
                    $ret[$pid] = new AA_Property($pid, _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1");
                    break;
                case 'sort_by':
                    $ret[$pid] = new AA_Property($pid, _m("Sort by"), 'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d");
                    break;
                case 'additional_slice_pwd':
                    $ret[$pid] = new AA_Property($pid, _m("Slice password"), 'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure');
                    break;
                case 'filter_conds_rw':
                    $ret[$pid] = new AA_Property($pid, _m("Filtering conditions - changeable"), 'string', false, true, 'string', false, _m("Conditions for filtering items. This conds site admin change through widget_property parameter."), '', "d-source..........-RLIKE-Econnect");
                    break;
                case 'columns':
                    $ret[$pid] = new AA_Property($pid, _m("Columns"), 'int', false, true, 'int', false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3);
                    break;
                case 'move_right':
                    $ret[$pid] = new AA_Property($pid, _m("Move right"), 'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1");
                    break;
                case 'start_year':
                    $ret[$pid] = new AA_Property($pid, _m("Starting Year"), 'int', false, true, 'int', false, _m("The (relative) start of the year interval"), '', "1");
                    break;
                case 'end_year':
                    $ret[$pid] = new AA_Property($pid, _m("Ending Year"), 'int', false, true, 'int', false, _m("The (relative) end of the year interval"), '', "10");
                    break;
                case 'relative':
                    $ret[$pid] = new AA_Property($pid, _m("Relative"), 'bool', false, true, 'bool', false, _m("If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."), '', "1");
                    break;
                case 'show_time':
                    $ret[$pid] = new AA_Property($pid, _m("Show time"), 'bool', false, true, 'bool', false, _m("show the time box? (1 means Yes, undefined means No)"), '', "1");
                    break;
                case 'height':
                    $ret[$pid] = new AA_Property($pid, _m("Height"), 'int', false, true, 'int', false, _m("Max height of the widget in pixels"));
                    break;
                case 'offer_label':
                    $ret[$pid] = new AA_Property($pid, _m("Title of \"Offer\" selectbox"), 'string', false, true, 'string', false, '', '', _m("Our offer"));
                    break;
                case 'selected_label':
                    $ret[$pid] = new AA_Property($pid, _m("Title of \"Selected\" selectbox"), 'string', false, true, 'string', false, '', '', _m("Selected"));
                    break;
                case 'add_form':
                    $ret[$pid] = new AA_Property($pid, _m("Add Form"), 'string', false, true, 'string', false, _m("(for slices only) ID of the form for adding items into related slice"), '', '6f466be8fdf38d67ae8b4973f7c95761');
                    break;
                case 'allowed_ftypes':
                    $ret[$pid] = new AA_Property($pid, _m("Allowed file types"), 'string', false, true, 'string', false, '', '', "image/*");
                    break;
                case 'label':
                    $ret[$pid] = new AA_Property($pid, _m("Label"), 'string', false, true, 'string', false, _m("To be printed before the file upload field"), '', _m("File: "));
                    break;
                case 'hint':
                    $ret[$pid] = new AA_Property($pid, _m("Hint"), 'string', false, true, 'string', false, _m("appears beneath the file upload field"), '', _m("You can select a file ..."));
                    break;
                case 'display_url':
                    $ret[$pid] = new AA_Property($pid, _m("Display URL"), 'int', false, true, 'int', false, _m("0 - show, 1 - show if not empty, 2 - do not show"), '', 0);
                    break;
                case 'multiple':
                    $ret[$pid] = new AA_Property($pid, _m("Multiple"), 'bool', false, true, 'bool', false, _m("Allows to upload multiple files in the field"), '', 0);
                    break;
                case 'show_actions':
                    $ret[$pid] = new AA_Property($pid, _m("Actions to show"), 'string', false, true, 'string', false, _m("Defines, which buttons to show in item selection:<br>A - Add<br>M - add Mutual<br>B - Backward<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."), '', 'AMB');
                    break;
                case 'admin_design':
                    $ret[$pid] = new AA_Property($pid, _m("Admin design"), 'bool', false, true, 'bool', false, _m("If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."), '', '0');
                    break;
                case 'tag_prefix':
                    $ret[$pid] = new AA_Property($pid, _m("Tag Prefix"), 'string', false, true, 'string', false, _m("Deprecated: selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."), '', 'AMB');
                    break;
                case 'show_buttons':
                    $ret[$pid] = new AA_Property($pid, _m("Buttons to show"), 'string', false, true, 'string', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete relation,<br>R - add Relation to existing item<br>N - insert new item in related slice and make it related<br>E - Edit related item<br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."), '', 'MDR');
                    break;
                case 'level_count':
                    $ret[$pid] = new AA_Property($pid, _m("Level count"), 'int', false, true, 'int', false, _m("Count of level boxes"), '', "3");
                    break;
                case 'box_width':
                    $ret[$pid] = new AA_Property($pid, _m("Box width"), 'int', false, true, 'int', false, _m("Width in characters"), '', "60");
                    break;
                case 'target_size':
                    $ret[$pid] = new AA_Property($pid, _m("Size of target"), 'int', false, true, 'int', false, _m("Lines in the target select box"), '', '5');
                    break;
                case 'horizontal':
                    $ret[$pid] = new AA_Property($pid, _m("Horizontal"), 'bool', false, true, 'bool', false, _m("Show levels horizontally"), '', '1');
                    break;
                case 'first_selectable_level':
                    $ret[$pid] = new AA_Property($pid, _m("First selectable"), 'int', false, true, 'int', false, _m("First level which will have a Select button"), '', '0');
                    break;
                case 'level_names':
                    $ret[$pid] = new AA_Property($pid, _m("Level names"), 'string', false, true, 'string', false, _m("Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."), '', _m("Top level~Second level~Keyword"));
                    break;
                case 'change_label':
                    $ret[$pid] = new AA_Property($pid, _m("Label for Change Password"), 'string', false, true, 'string', false, _m("Replaces the default 'Change Password'"), '', _m("Change your password"));
                    break;
                case 'retype_label':
                    $ret[$pid] = new AA_Property($pid, _m("Label for Retype New Password"), 'string', false, true, 'string', false, _m("Replaces the default \"Retype New Password\""), '', _m("Retype the new password"));
                    break;
                case 'delete_label':
                    $ret[$pid] = new AA_Property($pid, _m("Label for Delete Password"), 'string', false, true, 'string', false, _m("Replaces the default \"Delete Password\""), '', _m("Delete password (set to empty)"));
                    break;
                case 'change_hint':
                    $ret[$pid] = new AA_Property($pid, _m("Help for Change Password"), 'string', false, true, 'string', false, _m("Help text under the Change Password box (default: no text)"), '', _m("To change password, enter the new password here and below"));
                    break;
                case 'retype_hint':
                    $ret[$pid] = new AA_Property($pid, _m("Help for Retype New Password"), 'string', false, true, 'string', false, _m("Help text under the Retype New Password box (default: no text)"), '', _m("Retype the new password exactly the same as you entered into \"Change Password\"."));
                    break;
                case 'url':
                    $ret[$pid] = new AA_Property($pid, _m("URL"), 'string', false, true, 'string', false, _m("The URL of your local web server from where you want to start browsing for a particular URL."), '', _m("http#://www.ecn.cz/articles/solar.shtml"));
                    break;
                case 'ajaxtype':
                    $ret[$pid] = new AA_Property($pid, _m("Ajax inline"), 'string', false, true, 'string', false, _m("If called as {ajax...} it modifies the visual - inline: old-way (not in window, but inline)"), '', 'inline');
                    break;
                case 'item_template':
                    $ret[$pid] = new AA_Property($pid, _m("New item template ID"), 'string', false, true, 'string', false, _m("The ID of item in destination slice used as Template for new items (probably in Holding bin)"), '', '3e86c0be745dd7b35f406d6997c46b8e');
                    break;
                case 'code':
                    $ret[$pid] = new AA_Property($pid, _m("Code to show"), 'string', false, true, 'string', false, _m("HTML code with possible AA expressions to show. If not filled, the {_#this} will be displayed"), '', '_#HEADLINE');
                    break;
            }
        }
        return $ret;
    }

    /** getParamWizardArr - get array for ParamWizard from widgets
     *  (replaces array in constants_param_wizard.php3)
     *  called as AA\Widget\Widget::getParamWizardArr('fld');
     */
    static function getParamWizardArr($type)
    {
        $ret = array();
        if (class_exists($widget_class = Widget::constructClassName($type))) {
            $ret['name'] = $widget_class::name();
            $ret['desc'] = $widget_class::description();
            $ret['params'] = array();

            $props = $widget_class::getClassProperties();
            if (key($props) == 'const') { // const or slice is not presented in popup
                array_shift($props);
            }
            foreach ($props as $prop) {
                $ret['params'][] = array(
                    'name' => $prop->getName(),
                    'desc' => $prop->getHelp(),
                    'type' => $prop->getType(),
                    'example' => $prop->getExample()
                );
            }
        }
        return $ret;
    }
}