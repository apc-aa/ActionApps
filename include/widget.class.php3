<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


// AA_Widget class should implement some interface (in php5), so it is possible
// to use AA_Components factory, ... methods
// used for easy ussage of factory, adding new user widgets, and selectbox
// AA_Widget should became abstract in php5
class AA_Widget extends AA_Components {

    /** array of possible values (for selectbox, two boxes, ...) */
    var $_const_arr = null;

    /** array(value => true) of all selected values - just for caching */
    var $_selected = null;

    /** $parameters - Array of AA_Property used for the widget
    *   inherited from AA_Components
    */
    /** name function
     *
     */
    function name()         {}
    //    function description()  {}

    function get($result_mode='expand', $item=null) { // copied from AA_Inputfield: $result_mode='expand', $item=null

    }


     /** Fills array used for list selection. Fill it from constant group or
      * slice.
      * It never refills the array (and we relly on this fact in the code)
      * This function is rewritten fill_const_arr().
      */
    function & getConstArr($aa_variable) {
        if ( isset($this->_const_arr) AND is_array($this->_const_arr) ) {  // already filled
            return $this->_const_arr;
        }

        // not filled, yet - so fill it
        $this->_const_arr = array();  // Initialize

        // commented out - used for Related Item Window values
        // $zids = $ids_arr ? new zids($ids_arr) : false;  // transforms content array to zids
        $zids    = false;
        $ids_arr = false;

        $constgroup   = $this->getProperty('const');
        $filter_conds = $this->getProperty('filter_conds');
        $sort_by      = $this->getProperty('sort_by');
        $slice_field  = $this->getProperty('slice_field');

        // if variable is for some item, then we can use _#ALIASES_ in conds
        // and sort
        $item_id = $aa_variable->getItemId();
        if ( $item_id ) {
            $item         = AA_Item::getItem($item_id);
            if ( $item ) {
                $filter_conds = $item->unalias($filter_conds);
                $sort_by      = $item->unalias($sort_by);
            }
        }

        if ( !$this->getProperty('const')) {  // no constants or slice defined
            return $this->_const_arr;         //  = array();
        }
        // "#sLiCe-" prefix indicates select from items
        elseif ( substr($constgroup,0,7) == "#sLiCe-" ) {

            $bin_filter       = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
            $tag_prefix       = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used

            $sid              = substr($constgroup, 7);
            /** Get format for which represents the id
             *  Could be field_id (then it is grabbed from item and truncated to 50
             *  characters, or normal AA format string.
             *  Headline is default (if empty "$slice_field" is passed)
             */
            if (!$slice_field) {
                $slice_field = GetHeadlineFieldID($sid, "headline.");
                if (!$slice_field) {
                    return $this->_const_arr; //  = array();
                }
            }
            $format           = AA_Fields::isField($slice_field) ? '{substr:{'.$slice_field.'}:0:50}' : $slice_field;

            $this->_const_arr = GetFormatedItems( $sid, $format, $zids, $bin_filter, $filter_conds, $sort_by, $tag_prefix);
            return $this->_const_arr;
        }
        else {
            $this->_const_arr = GetFormatedConstants($constgroup, $slice_field, $ids_arr, $filter_conds, $sort_by);
        }
        if ( !isset($this->_const_arr) OR !is_array($this->_const_arr) ) {
            $this->_const_arr = array();
        }
        return $this->_const_arr;
    }


    /** returns $ret_val if given $option is selected for current field
     *  This method is rewritten if_selected() method form formutil.php3
     */
    function ifSelected($option, $ret_val) {
        return $this->_selected[(string)$option] ? $ret_val : '';
    }

    /**
     *  This method is rewritten _fillSelected() method form formutil.php3
     */
    function _fillSelected($aa_value) {
        if ( is_null($this->_selected) ) {  // not cached yet => create selected array
            for ($i=0 ; $i < $aa_value->valuesCount(); $i++) {
                $val = $aa_value->getValue($i);
                if ( $val ) {
                    $this->_selected[(string)$val] = true;
                }
            }
        }
    }

    /** returns options array with marked selected oprtions, missing options,...
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getOptions( $aa_variable, $use_name=false, $testval=false, $add_empty=false) {
        $selectedused  = false;

        $already_selected = array();     // array where we mark selected values
        $pair_used        = array();     // array where we mark used pairs
        $this->_fillSelected($aa_variable->getAaValue()); // fill selected array by all values in order we can print invalid values later

        $ret = array();
        $arr = $this->getConstArr($aa_variable);
        if (is_array($arr)) {
            foreach ( $arr as $k => $v ) {
                if ($use_name) {
                    // special parameter to use values instead of keys
                    $k = $v;
                }

                // ignore pairs (key=>value) we already used
                if ($pair_used[$k."aa~$v"]) {
                    continue;
                }
                $pair_used[$k."aa~$v"] = true;   // mark this pair - do not use it again

                $select_val = $testval ? $v : $k;
                $selected   = $this->ifSelected($select_val, true);
                if ($selected) {
                    $selectedused = true;
                    $already_selected[(string)$select_val] = true;  // flag
                }
                $ret[] = array('k'=>$k, 'v'=>$v, 'selected' =>  ($selected ? true : false), 'mis' => false);
            }
        }

        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if ( isset( $this->_selected ) AND is_array( $this->_selected ) ) {
            foreach ( $this->_selected as $k =>$foo ) {
                if ( !$already_selected[$k] ) {
                    $ret[] = array('k'=>$k, 'v'=>$k, 'selected' => true, 'mis' => true);
                    $selectedused = true;
                }
            }
        }
        if ( $add_empty ) {
            // put empty option to the front
            array_unshift($ret, array('k'=>'', 'v'=>'', 'selected' => !$selectedused, 'mis' => false));
        }
        return $ret;
    }

    /** returns select options created from given array
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getSelectOptions( &$options, $restrict='all', $do_not_select=false) {

        $select_string = ( $do_not_select ? ' class="sel_on"' : ' selected class="sel_on"');

        $ret = '';
        foreach ( $options as $option ) {
            if ( ($restrict == 'selected')   AND !$option['selected'] ) {
                continue;  // do not print this option
            }
            if ( ($restrict == 'unselected') AND $option['selected'] ) {
                continue;  // do not print this option
            }
            $selected = $option['selected'] ? $select_string : '';
            $missing  = $option['mis']      ? 'class="sel_missing"' : '';
            $ret     .= "<option value=\"". htmlspecialchars($option['k']) ."\" $selected $missing>".htmlspecialchars($option['v'])."</option>";
        }
        return $ret;
    }

    /**
    * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
    * - for use internal use of FrmInputMultiChBox and FrmInputRadio
    */
    function getInMatrix($records, $ncols, $move_right) {
        if (is_array($records)) {
            if (! $ncols) {
                return implode('', $records);
            }
            $nrows = ceil (count ($records) / $ncols);
            $ret = '<table border="0" cellspacing="0">';
            for ($irow = 0; $irow < $nrows; $irow ++) {
                $ret .= '<tr>';
                for ($icol = 0; $icol < $ncols; $icol ++) {
                    $pos = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
                    $ret .= '<td>'. get_if($records[$pos], "&nbsp;") .'</td>';
                }
                $ret .= '</tr>';
            }
            $ret .= '</table>';
        }
        return $ret;
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {

        $input_id    = $aa_variable->getId();
        $ret   = '';

        // This widget uses constants - show selectbox!
        if ($this->getProperty('const')) {
            $input_name   = $input_id ."[]";
            $use_name     = $this->getProperty('use_name', false);
            $multiple     = $this->multiple() ? ' multiple' : '';
            $required     = $aa_variable->isRequired();

            $ret      = "<select name=\"$input_name\" id=\"$input_name\"$multiple>";
            $options  = $this->getOptions($aa_variable, $use_name, false, !$required);
            $ret     .= $this->getSelectOptions( $options );
            $ret     .= "</select>";
        } else {
            $delim = '';
            for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
                $input_name   = $input_id ."[$i]";
                $input_value  = htmlspecialchars($aa_variable->getValue($i));
                $ret         .= "$delim\n<input type=\"text\" size=\"80\" id=\"$input_name\" value=\"$input_value\">";
                $delim        = '<br />';
            }
            // no input was printed, we need to print one
            if ( !$ret ) {
                $ret         = "\n<input type=\"text\" size=\"80\" id=\"". $input_id ."[0]\" value=\"\">";
            }
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /* Creates all common ajax editing buttons to be used by different inputs */
    function _finalizaAjaxHtml($widget_html, $input_id, $repre_value) {
        $widget_html  .= "\n<input type=\"button\" value=\"". _m('SAVE CHANGE') ."\" onclick=\"DoChange('$input_id')\">"; //ULOŽIT ZMÌNU
        $widget_html  .= "\n<input type=\"button\" value=\"". _m('Cancel') ."\" onclick=\"$('ajaxv_$input_id').update(". '$F(\'ajaxh_'.$input_id.'\'))'."; $('ajaxv_$input_id').setAttribute('aaedit', '0');\">";
        $widget_html  .= "\n<input type=\"hidden\" id=\"ajaxh_$input_id\" value=\"".htmlspecialchars($repre_value)."\">";
        return $widget_html;
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
     *   where "dte" points to the AA_Widget_Dte. The method AA_Widget_Dte::getValue()
     *   is called to grab the value (or multivalues) from the submitted form
     *
     *  static class method
     */
    function getValue($data4field) {
        $flag          = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = array();

        foreach ( (array)$data4field as $key => $value ) {
            if (is_numeric($key)) {
                $fld_value_arr[] = array('value'=>$value, 'flag'=>$flag);
            }
            elseif (($key != 'flag') AND class_exists($class = AA_Object::constructClassName('AA_Widget_', $key))) {
                // call function like AA_Widget_Dte::getValue($data)
                // where $data depends on the widget - for example for
                // date it is array('d'=>array(), 'm'=>array(), 'y'=>array())
                $aa_value = call_user_func_array(array($class, 'getValue'), array($value));
                $aa_value->setFlag($flag);

                // there is no need to go through array - we do not expect more widgets for one variable
                return $aa_value;
            }
        }
        return new AA_Value($fld_value_arr, $flag);
    }
}

/** Textarea widget */
class AA_Widget_Txt extends AA_Widget {
    /** AA_Widget_Txt function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Txt($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
     /** name function
      *
      */
    function name() {
        return _m('Text Area');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;// returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties() {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int', false, '', '', 20)
            );

    }

    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id  = $aa_variable->getId();
        $row_count = $this->getProperty('row_count', 4);

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<textarea id=\"$input_name\" name=\"$input_name\" rows=\"$row_count\" style=\"width:100%\">$input_value</textarea>";
            $delim        = '<br />';
        }

        // no input was printed, we need to print one
        if ( !$ret ) {
            $input_name  = $input_id ."[0]";
            $ret         = "\n<textarea id=\"$input_name\" name=\"$input_name\" rows=\"$row_count\" style=\"width:100%\"></textarea>";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

}

/** Textarea with Presets widget */
class AA_Widget_Tpr extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Tpr($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }


    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Textarea with Presets');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     * Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'column_count'           => new AA_Property( 'column_count',           _m("Column count"),         'int',  false, true, 'int',  false, '', '', 70)
            );
    }
}

/** Rich Edit Text Area widget */
class AA_Widget_Edt extends AA_Widget {
    /** AA_Widget_Edt function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Edt($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Rich Edit Text Area');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'column_count'           => new AA_Property( 'column_count',           _m("Column count"),         'int',  false, true, 'int',  false, '', '', 70),
            'area_type'              => new AA_Property( 'area_type',              _m("Type"),                 'text', false, true, array('enum',array('class'=>'class', 'iframe'=>'iframe')), false, _m("type: class (default) / iframe"), '', 'class')
            );
    }
}

/** Text Field widget */
class AA_Widget_Fld extends AA_Widget {
    /** AA_Widget_Fld function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Fld($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id    = $aa_variable->getId();

        $max_characters = $this->getProperty('max_characters', 254);
        $width          = $this->getProperty('width', 60);

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" id=\"$input_name\" value=\"$input_value\">";
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$ret ) {
            $ret         = "\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" id=\"". $input_id ."[0]\" value=\"\">";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Text Field');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;    // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'max_characters'         => new AA_Property( 'max_characters',         _m("Max characters"),       'int',  false, true, 'int',  false, _m("max count of characters entered (maxlength parameter)"), '', 254),
            'width'                  => new AA_Property( 'width',                  _m("Width"),                'int',  false, true, 'int',  false, _m("width of the field in characters (size parameter)"),     '',  30)
            );
    }
}

/** Multiple Text Field widget */
class AA_Widget_Mfl extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mfl($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Text Field');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'text', false, true, 'text', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete value,<br>A - Add new value<br>C - Change the value<br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."), '', 'MDAC'),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10)
            );
    }
}

/** Text Field with Presets widget */
class AA_Widget_Pre extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Pre($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Text Field with Presets');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     * Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'max_characters'         => new AA_Property( 'max_characters',         _m("max characters"),       'int',  false, true, 'int',  false, _m("max count of characters entered (maxlength parameter)"), '', 254),
            'width'                  => new AA_Property( 'width',                  _m("width"),                'int',  false, true, 'int',  false, _m("width of the field in characters (size parameter)"),     '',  30),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'adding'                 => new AA_Property( 'adding',                 _m("Adding"),               'bool', false, true, 'bool', false, _m("adding the selected items to input field comma separated"), '', '0'),
            'second_field'           => new AA_Property( 'second_field',           _m("Second Field"),         'text', false, true, 'text', false, _m("field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"), '', "source_href....."),
            'add2constant'           => new AA_Property( 'add2constant',           _m("Add to Constant"),      'bool', false, true, 'bool', false, _m("if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"), '', "0"),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            );
    }
}

/** Select Box widget */
class AA_Widget_Sel extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Sel($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Select Box');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            );
    }
}

/** Radio Button widget */
class AA_Widget_Rio extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Rio($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Radio Button');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** Date widget */
class AA_Widget_Dte extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Dte($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Date');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'start_year'             => new AA_Property( 'start_year',             _m("Starting Year"),        'int',  false, true, 'int',  false, _m("The (relative) start of the year interval"), '', "1"),
            'end_year'               => new AA_Property( 'end_year',               _m("Ending Year"),          'int',  false, true, 'int',  false, _m("The (relative) end of the year interval"), '', "10"),
            'relative'               => new AA_Property( 'relative',               _m("Relative"),             'bool', false, true, 'bool', false, _m("If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."), '', "1"),
            'show_time'              => new AA_Property( 'show_time',              _m("Show time"),            'bool', false, true, 'bool', false, _m("show the time box? (1 means Yes, undefined means No)"), '', "1")
            );
    }

    /** @return AA_Value for the data send by the widget
     *   This is compound widgets, which consists from more than one input, so
     *   the inputs looks like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
     *   where "dte" points to the AA_Widget_Dte.
     *
     *   This method AA_Widget_Dte::getValue() is called to grab the value
     *   (or multivalues) from the submitted form
     *
     *  @param $data4field - array('y'=>array(), 'm'=>array(), 'd'=>array(), 't'=>array())
     *  static class method
     */
    function getValue($data4field) {

        $years  = (array)$data4field['y'];
        $months = (array)$data4field['m'];
        $days   = (array)$data4field['d'];
        $times  = (array)$data4field['t'];

        // date could be also multivalue
        $max = max(count($years), count($months), count($days), count($times));

        $values = array();

        for ($i=0 ; $i<$max; $i++) {
            // check if anything is filled
            if ( !(int)$years[$i] AND !(int)$months[$i] AND !(int)$days[$i] AND !$time[$i]) {
                continue;
            }
            $year  = $years[$i]  ? $years[$i]  : date('Y'); // specified year or current
            $month = $months[$i] ? $months[$i] : 1;         // specified month or January
            $day   = $days[$i]   ? $days[$i]   : 1;         // specified day or 1st
            $time  = explode( ':', $times[$i] ?  $times[$i] : "0:0:0");         // specified time or midnight

            $values[] = mktime($time[0],$time[1],$time[2],(int)$month,(int)$day,(int)$year);
        }

        return new AA_Value($values);
    }
}

/** Check Box widget */
class AA_Widget_Chb extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Chb($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id    = $aa_variable->getId();

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<input type=\"checkbox\" name=\"$input_name\" id=\"$input_name\" value=\"1\"". ($input_value ? " checked" : '').">";
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$ret ) {
            $input_name   = $input_id ."[0]";
            $ret         .= "$delim\n<input type=\"checkbox\" name=\"$input_name\" id=\"$input_name\" value=\"1\">";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Check Box');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array ();
    }
}

/** Multiple Checkboxes widget */
class AA_Widget_Mch extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mch($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Checkboxes');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }

    /** Returns one checkbox tag - Used in inputMultiChBox */
    function getOneChBoxTag($option, $name, $add='') {
        $ret = "\n<nobr><input type=\"checkbox\" name=\"$name\" id=\"$name\" value=\"".
                   htmlspecialchars($option['k']) ."\" $add";
        if ( $option['selected'] ) {
            $ret .= " checked";
        }
        $ret .= ">".htmlspecialchars($option['v'])."</nobr>";
        return $ret;
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {

        $input_id    = $aa_variable->getId();
        $ret   = '';

        $use_name     = $this->getProperty('use_name', false);
        $required     = $aa_variable->isRequired();

        $options      = $this->getOptions($aa_variable, $use_name);
        $htmlopt      = array();
        for ( $i=0 ; $i < count($options); $i++) {
            $htmlopt[]  = $this->getOneChBoxTag($options[$i], $input_id ."[$i]");
        }

        $ret = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false));
        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

}

/** Multiple Selectbox widget */
class AA_Widget_Mse extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mse($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Selectbox');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** Two Boxes widget */
class AA_Widget_Wi2 extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Wi2($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Two Boxes');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'offer_label'            => new AA_Property( 'offer_label',            _m("Title of \"Offer\" selectbox"), 'text', false, true, 'text', false, '','', _m("Our offer")),
            'selected_label'         => new AA_Property( 'selected_label',         _m("Title of \"Selected\" selectbox"), 'text', false, true, 'text', false, '','', _m("Selected")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** File Upload widget */
class AA_Widget_Fil extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Fil($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('File Upload');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'allowed_ftypes'         => new AA_Property( 'allowed_ftypes',         _m("Allowed file types"),   'text', false, true, 'text', false, '', '', "image/*"),
            'label'                  => new AA_Property( 'label',                  _m("Label"),                'text', false, true, 'text', false, _m("To be printed before the file upload field"), '', _m("File: ")),
            'hint'                   => new AA_Property( 'hint',                   _m("Hint"),                 'text', false, true, 'text', false, _m("appears beneath the file upload field"), '', _m("You can select a file ..."))
            );
    }

    /** @return AA_Value for the data send by the widget
     *   This is compound widgets, which consists from more than one input - filled
     *   URL of the file or name of input[type=file] for upload,
     *   so the inputs looks like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][fil][var][]  // varname of uploaded file
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][fil][url][]  // url
     *   Unfortunatey we can't use something like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][fil][up][]  // upoaded file
     *   since the array variabes in $_FILES array are mess in PHP (at least 5.2.5)
     *
     *   This method AA_Widget_Fil::getValue() is called to grab the value
     *   (or multivalues) from the submitted form. The function actually do not
     *   upload the file. The upload itself is done by insert_fnc_fil() later
     *   Here we just mar the uploaded file by prefix AA_UPLOAD:, so
     *   insert_fnc_fil() knows aboutr the new file for upload
     *
     *  @param $data4field - array('var'=>array(), 'url'=>array())
     *  static class method
     */
    function getValue($data4field) {

        $uploads  = (array)$data4field['var'];
        $urls     = (array)$data4field['url'];

        // date could be also multivalue
        $max = max(count($uploads), count($urls));

        $values = array();

        for ($i=0 ; $i<$max; $i++) {
            if ($uploads[$i]) {
                $values[] = 'AA_UPLOAD:'.$uploads[$i];
            }
            elseif ($urls[$i]) {
                $values[] = $urls[$i];
            }
        }
        return new AA_Value($values);
    }


}

/** Related Item Window widget */
class AA_Widget_Iso extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Iso($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Related Item Window');   // widget name
    }
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count in the list"),'int',  false, true, 'int',  false, '', '', 15),
            'show_actions'           => new AA_Property( 'show_actions',           _m("Actions to show"),      'text', false, true, 'text', false, _m("Defines, which buttons to show in item selection:<br>A - Add<br>M - add Mutual<br>B - Backward<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."), '', 'AMB'),
            'admin_design'           => new AA_Property( 'admin_design',           _m("Admin design"),         'bool', false, true, 'bool', false, _m("If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."), '' , '0'),
            'tag_prefix'             => new AA_Property( 'tag_prefix',             _m("Tag Prefix"),           'text', false, true, 'text', false, _m("Deprecated: selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."), '', 'AMB'),
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'text', false, true, 'text', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete relation,<br>R - add Relation to existing item<br>N - insert new item in related slice and make it related<br>E - Edit related item<br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."), '', 'MDR'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'filter_conds_changeable'=> new AA_Property( 'filter_conds_changeable',_m("Filtering conditions - changeable"), 'text', false, true, 'text', false, _m("Conditions for filtering items in related items window. This conds user can change."), '', "conds[0][source..........]=Econnect"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........')
            );
    }
}

/** Do not show widget */
class AA_Widget_Nul extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Nul($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Do not show');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array ();
    }
}

/** Hierachical constants widget */
class AA_Widget_Hco extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Hco($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Hierachical constants');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'level_count'            => new AA_Property( 'level_count',            _m("Level count"),          'int',  false, true, 'int',  false, _m("Count of level boxes"), '', "3"),
            'box_width'              => new AA_Property( 'box_width',              _m("Box width"),            'int',  false, true, 'int',  false, _m("Width in characters"), '', "60"),
            'target_size'            => new AA_Property( 'target_size',            _m("Size of target"),       'int',  false, true, 'int',  false, _m("Lines in the target select box"), '', '5'),
            'horizontal'             => new AA_Property( 'horizontal',             _m("Horizontal"),           'bool', false, true, 'bool', false, _m("Show levels horizontally"), '', '1'),
            'first_selectable_level' => new AA_Property( 'first_selectable_level', _m("First selectable"),     'int',  false, true, 'int',  false, _m("First level which will have a Select button"), '', '0'),
            'level_names'            => new AA_Property( 'level_names',            _m("Level names"),          'text', false, true, 'text', false, _m("Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."), '', _m("Top level~Second level~Keyword"))
            );
    }
}

/** Password and Change password widget */
class AA_Widget_Pwd extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Pwd($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Password and Change password');
    }   // widget name
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'width'                  => new AA_Property( 'width',                  _m("Width"),                         'int',  false, true, 'int',  false, _m("width of the three fields in characters (size parameter)"),     '',  60),
            'change_label'           => new AA_Property( 'change_label',           _m("Label for Change Password"),     'text', false, true, 'text', false, _m("Replaces the default 'Change Password'"), '', _m("Change your password")),
            'retype_label'           => new AA_Property( 'retype_label',           _m("Label for Retype New Password"), 'text', false, true, 'text', false, _m("Replaces the default \"Retype New Password\""), '', _m("Retype the new password")),
            'delete_label'           => new AA_Property( 'delete_label',           _m("Label for Delete Password"),     'text', false, true, 'text', false, _m("Replaces the default \"Delete Password\""), '', _m("Delete password (set to empty)")),
            'change_hint'            => new AA_Property( 'change_hint',            _m("Help for Change Password"),      'text', false, true, 'text', false, _m("Help text under the Change Password box (default: no text)"), '', _m("To change password, enter the new password here and below")),
            'retype_hint'            => new AA_Property( 'retype_hint',            _m("Help for Retype New Password"),  'text', false, true, 'text', false, _m("Help text under the Retype New Password box (default: no text)"), '', _m("Retype the new password exactly the same as you entered into \"Change Password\".")),
            );
    }
}

/** Hidden field widget */
class AA_Widget_Hid extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Hid($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Hidden field');
    }   // widget name
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array ();
    }
}

/** Local URL Picker widget */
class AA_Widget_Lup extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Lup($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Local URL Picker');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'url'                    => new AA_Property( 'url',                    _m("URL"),                  'text', false, true, 'text', false, _m("The URL of your local web server from where you want to start browsing for a particular URL."), '', _m("http#://www.ecn.cz/articles/solar.shtml"))
            );
    }
}


/** AA_Property
*  Used also for definition of components's parameters
*   Components are AA_Widgets, AA_Transofrmations, ...
*/
class AA_Property {

    /** Id of property - like: new_flag */
    var $id;

    /** Property name - like: _m('Mark as') */
    var $name;

    /** Property type - text | int | bool | float | <class_name>
     *  If the type is <class_name>, then it should support getAaValue() method.
     */
    var $type;

    /** Contain one or multiple values (numbered array) - bool (default is false)  */
    var $multi;

    /** should be stored, when we are storing the state of the object */
    var $persistent;

    /** validate - standard validators are
     *  text | bool | int | float | email | alpha | long_id | short_id | alias | filename | login | password | unique | e_unique | url | all | enum
     */
    var $validator;

    /** boolean - is it required? - like: true */
    var $required;

    /** Help text for the property */
    var $input_help;

    /** Url, where user can get more informations about the property */
    var $input_morehlp;

    /** Value example */
    var $example;

    /** show_content_type_switch is used instead of $html_show or $html_rb_show.
     *  It is more generalized, so we can use more formaters in the future (not
     *  only HTML / Plain text, but also Wiki, Texy or whatever.
     *  The value is flagged 0 - do not show, FLAG_HTML | FLAG_PLAIN (1+2=3)
     *  means HTML / Plain text switch. There is an idea to use constant like
     *  CONTENT_SWITCH_STANDARD = FLAG_HTML | FLAG_PLAIN | .... = 1+2+4+8+16+...
     *  = 65535, so first 16 formaters will be standard (displayed after we add
     *  it to AA) and the rest (above 16) will be used for special purposes.
     *  However, it is just an idea right now (we still have just HTML and
     *  plain text)
     */
    var $show_content_type_switch;

    /** Default value for content type switch
    *   (FLAG_HTML or FLAG_PLAIN at this moment)
    */
    var $content_type_switch_default;

    /** array of constants used for selections (selectbox, radio, ...) */
    var $const_arr;
    /** AA_Property function
     * @param $id
     * @param $name
     * @param $type
     * @param $multi
     * @param $persistent
     * @param $validator
     * @param $required
     * @param $input_help
     * @param $input_morehlp
     * @param $example
     * @param $show_content_type_switch
     * @param $content_type_switch_default
     */
    function AA_Property($id, $name='', $type, $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_PLAIN) {
        $this->id                          = $id;
        $this->name                        = $name;
        $this->type                        = $type;
        $this->multi                       = $multi;
        $this->persistent                  = $persistent;
        $this->validator                   = is_object($validator) ? $validator : AA_Validate::factory($validator ? $validator : $type);
        $this->required                    = $required;
        $this->input_help                  = $input_help;
        $this->input_morehlp               = $input_morehlp;
        $this->example                     = $example;
        $this->show_content_type_switch    = $show_content_type_switch;
        $this->content_type_switch_default = $content_type_switch_default;
        $this->const_arr                   = (is_array($validator) AND ($validator[0]=='enum')) ? $validator[1] : array();
    }

    /** getId function */
    function getId() {
        return $this->id;
    }

    /** getName function */
    function getName() {
        return $this->name;
    }

    /** getType function */
    function getType() {
        return $this->type;
    }

    /** isObject function */
    function isObject() {
        return !in_array($this->type, array('text', 'int', 'bool', 'float'));
    }

    /** isMulti function */
    function isMulti() {
        return $this->multi;
    }

    /** isRequired function */
    function isRequired() {
        return $this->required;
    }

    /** isPersistent function */
    function isPersistent() {
        return $this->persistent;
    }
}

/** AA_Variable class defines one variable in AA. It is describes the datatype,
 *  (numeric, date, string), constraints (range of values, length, if it is
 *  required, ...), name, and some description of the variable. It do not hold
 *  the information, how the value is presented to the user and how it could
 *  be entered. For displaying the AA_Variable we choose some AA_Widget.
 *
 *  This approach AA_Variable/AA_Widget/AA_Value should replace the old - all
 *  in one AA_Inputfield approach. It should be used not only for AA Fields,
 *  but also for parameters of functions/widgets...
 *
 */
class AA_Variable extends AA_Property {

    /** Current value of $type. The value must be convertable to AA_Value - @see type */
    var $value=null;

    /** id of item, for which is this variable (used for some unaliasing...) */
    var $item_id=null;

    /** setValue function
     * @param $value
     */
    function AA_Variable($id, $name='', $type, $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_PLAIN, $aa_value=null, $item_id=null) {
        $this->value   = $aa_value;
        $this->item_id = $item_id;
        parent::AA_Property($id, $name, $type, $multi, $persistent, $validator, $required, $input_help, $input_morehlp, $example, $show_content_type_switch, $content_type_switch_default);
    }

    function setValue($value) {
        if ( !is_null($this->validator) AND $this->validator->validate($value)) {
            $this->value = new AA_Value($value);
        }
    }

    function getValue($i) {
        return $this->value->getValue($i);
    }

    function getAaValue() {
        return $this->value;
    }

    function valuesCount() {
        return (is_null($this->value) ? 0 : $this->value->valuesCount());
    }

    function getItemId() {
        return $this->item_id;
    }
}


/** Base class for formatters (like HTML/Plain text/wiki/Texy/...)
*   Currently we use just HTML and Plain text
*/
class AA_Formatter {

    /// Static ///

    /** getStandardFormattersBitfield function
     * @param $html_show
     *  @return bit field representig, which formatters we want to show. 65535
     *   means "all standard formatters", which means all 16 standard formatters.
     *   We use just two, at this moment - HTML (=1) and PLAIN (=2)
     *   (we will continue on bit basis, so next formatter would be xxx (=4))
     */
    function getStandardFormattersBitfield($html_show) {
        // @todo move to const in php5
        return 65535;
    }

    /** no formaters */
    function getNoneFormattersBitfield() {
        return 0;
    }

    /** getFlag function
     *  @param $formatter_type
     *  @return (bit) id of the formatter_type (HTML or PLAIN, at this moment)
     */
    function getFlag($formatter_type) {
        return ($formatter_type == 'HTML') ? 1 : 2;
    }
}

?>
