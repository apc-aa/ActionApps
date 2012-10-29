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
 * GNU General Public License for more details
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


/** Collection of static functions used for aa[..][..] form variables handling */
class AA_Form_Array {
    /** ID of the field input - used for name atribute of input tag (or so)
    *   Format is:
    *       aa[u<long_item_id>][modified_field_id][]
    *   Note:
    *      first brackets contain
    *          'u'+long_item_id when item is edited or
    *          'n<number>_long_slice_id' if you want to add the item to slice_id
    *                                    <number> is used to add more than one
    *                                    item at the time
    *      modified_field_id is field_id, where all dots are replaced by '_'
    *      we always add [] at the end, so it becames array at the end
    *   Example:
    *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline_______1][]
    *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
    *   Format is:
    *       aa[u<long_item_id>][modified_field_id][]
    *   Note:
    *      first brackets contain
    *          'u'+long_item_id when item is edited (the field is rewriten, rest
    *                           of item is untouched)
    *          'i'+long_item_id when item is edited (the value is added to current
    *                           value of the field, rest of item is untouched)
    *          'n<number>_long_slice_id' if you want to add the item to slice_id
    *                                    <number> is used to add more than one
    *                                    item at the time
    *      modified_field_id is field_id, where all dots are replaced by '_'
    *      we always add [] at the end, so it becames array at the end
    *   Example:
    *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
    *       aa[i63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
    *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
    */
    static public function getName4Form($property_id, $content) {
        $form_field_id = self::getVarFromFieldId($property_id);

        $oid = $content->getId();
        if ( $oid ) {
            return "aa[u$oid][$form_field_id]";
        }

        $oowner = $content->getOwnerId();
        if ( !$oowner ) {
            throw new Exception('No owner specifield for '. $form_field_id);
        }
        return "aa[n1_$oowner][$form_field_id]";
    }

    static public function formName2Id($name) {
        return str_replace(array(']','['), array('','-'), $name);
    }

    /** Converts real field id into field id as used in the AA form, like:
     *  post_date......1  ==>  post_date______1
     */
    static public function getVarFromFieldId($field_id) {
        return str_replace('.','_', $field_id);
    }

    /** Converts field id as used in the AA form to real field id, like:
     *  post_date______1  ==>  post_date......1
     */
    static public function getFieldIdFromVar($dirty_field_id) {
        return str_replace('._', '..', str_replace('__', '..', $dirty_field_id));
    }

    /** returns array(item_id,field_id) from name of variable used on AA form */
    static public function parseId4Form($input_name) {
        // aa[u<item_id>][<field_id>][]
        $parsed   = explode(']', $input_name);
        $item_id  = substr($parsed[0],4);
        $field_id = self::getFieldIdFromVar(substr($parsed[1],1));
        return array($item_id,$field_id);
    }

    static public function getCharset($aa) {
        $module_id = self::getOwner($aa);
        if (!$module_id) {
            return '';
        }
        $slice = AA_Slices::getSlice($module_id);
        return $slice->getCharset();
    }

    static public function getOwner($aa) {
        if (!is_array($aa)) {
            return false;
        }
        foreach($aa as $key => $foo) {
            if ($key[0] == 'n') return substr($key, strpos($key, '_')+1);
        }

        $item_id = false;
        foreach($aa as $key => $foo) {
            if (($key[0] == 'u') OR ($key[0] == 'i')) {
                $item_id = substr($key, 1);
                break;
            }
        }
        if (!$item_id) {
            return false;
        }
        $item = AA_Items::getItem(new zids($item_id, 'l'));
        if (!$item) {
            return false;
        }
        return $item->getSliceID();
    }
}


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


    /** returns default widget for given property - it tries to identify,
     *  if it is multiple, uses constants, is bool, ...
     */
    static public function factoryFromProperty($aa_property) {
        if ($aa_property->isObject()) {
            throw new Exception('Can\'t generate widget for object property');
            return null;
        }

        $values = $aa_property->getConstants();
        if ($aa_property->isMulti()) {
            if (empty($values)) {
                return new AA_Widget_Mfl();
            }
            if (count($values) < 5) {
                return new AA_Widget_Mch(array('const_arr' => $values));
            }
            return new AA_Widget_Mse(array('const_arr' => $values));
        }

        if (!empty($values)) {
           return new AA_Widget_Sel(array('const_arr' => $values));
        }

        if ($aa_property->getType() == 'bool') {
            return new AA_Widget_Chb();
        }

        if ($aa_property->getType() == 'text') {
            return new AA_Widget_Txt();
        }
        return new AA_Widget_Fld();
    }

    // not used, yet
    //function assignConstants($arr) {
    //    $this->_const_arr = (array)$arr;
    //}

     /** Fills array used for list selection. Fill it from constant group or
      * slice.
      * It never refills the array (and we relly on this fact in the code)
      * This function is rewritten fill_const_arr().
      */
    private function _fillConstArr($content) {
        AA::$debug && AA::$dbg->log($this,$content );

        if ( isset($this->_const_arr) AND is_array($this->_const_arr) ) {  // already filled
            return;
        }

        // not filled, yet - so fill it
        $this->_const_arr = array();  // Initialize

        $values_array = $this->getProperty('const_arr');  // is asociative!
        if ( !empty($values_array) ) {          // values assigned directly
            $this->_const_arr = $values_array;  //  = array();
            return;
        }


        // commented out - used for Related Item Window values
        // $zids = $ids_arr ? new zids($ids_arr) : false;  // transforms content array to zids
        $zids    = false;
        $ids_arr = false;

        $constgroup   = $this->getProperty('const');
        $filter_conds = $this->getProperty('filter_conds');
        $sort_by      = $this->getProperty('sort_by');
        $slice_field  = $this->getProperty('slice_field');

        if ( !$this->getProperty('const')) {  // no constants or slice defined
            return;                           //  = array();
        }

        AA::$debug && AA::$dbg->log($filter_conds);

        // if variable is for some item, then we can use _#ALIASES_ in conds
        // and sort
        if ( is_object($content) ) {
            $filter_conds = $content->unalias($filter_conds);
            $sort_by      = $content->unalias($sort_by);
        }

        AA::$debug && AA::$dbg->log($filter_conds);

        // "#sLiCe-" prefix indicates select from items
        if ( substr($constgroup,0,7) == "#sLiCe-" ) {

            $bin_filter                   = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
            $tag_prefix                   = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used
            $crypted_additional_slice_pwd = AA_Credentials::encrypt($this->getProperty('additional_slice_pwd'));

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
            $format          = AA_Slices::getField($sid, $slice_field) ? '{substr:{'.$slice_field.'}:0:50}' : $slice_field;
            $set              = new AA_Set($sid, $filter_conds, $sort_by, $bin_filter);

            AA::$debug && AA::$dbg->log($set);

            $this->_const_arr = GetFormatedItems( $set, $format, $zids, $crypted_additional_slice_pwd, $tag_prefix);
            return;
        }
        else {
            $this->_const_arr = GetFormatedConstants($constgroup, $slice_field, $ids_arr, $filter_conds, $sort_by);
        }
        if ( !isset($this->_const_arr) OR !is_array($this->_const_arr) ) {
            $this->_const_arr = array();
        }
        return;
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
        $this->_selected = array();
        //if ( is_null($this->_selected) ) {  // not cached yet => create selected array
        if (is_object($aa_value)) {
            for ( $i=0, $ino=$aa_value->valuesCount(); $i<$ino; ++$i) {
                $val = $aa_value->getValue($i);
                if ( $val ) {
                    $this->_selected[(string)$val] = true;
                }
            }
        }
        //}
    }

    /** returns options array with marked selected options, missing options,...
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getOptions( $selected=null, $content=null, $use_name=false, $testval=false, $add_empty=false) {
        $selectedused  = false;

        $already_selected = array();     // array where we mark selected values
        $pair_used        = array();     // array where we mark used pairs
        $this->_fillSelected($selected); // fill selected array by all aa_values in order we can print invalid values later

        $ret = array();
        $this->_fillConstArr($content);
        if (is_array($this->_const_arr)) {
            foreach ( $this->_const_arr as $k => $v ) {
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
    function getInMatrix($records, $ncols, $move_right, $class='') {
        if (is_array($records)) {
            if (! $ncols) {
                return implode('', $records);
            }
            $nrows = ceil (count ($records) / $ncols);
            $class = $class ? "class=\"$class\"" : '';
            $ret = "<table border=0 cellspacing=0 $class>";
            for ($irow = 0; $irow < $nrows; ++$irow) {
                $ret .= '<tr>';
                for ($icol = 0; $icol < $ncols; ++$icol) {
                    $pos = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
                    $ret .= '<td>'. get_if($records[$pos], "&nbsp;") .'</td>';
                }
                $ret .= '</tr>';
            }
            $ret .= '</table>';
        }
        return $ret;
    }

    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name   = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id     = AA_Form_Array::formName2Id($base_name);
        $required    = $aa_property->isRequired() ? 'required' : '';
        $widget_add  = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\" style=\"padding-right:16px;\"" : '';
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';
        $widget      = '';
        //huhl("---", $aa_property->validator);

        // property uses constants or widget have the array assigned (preselect is special - the constants here are not crucial)
        if (($this->getProperty('const') OR $this->getProperty('const_arr')) AND (get_class($this) != 'AA_Widget_Pre')) {  // todo - make preselect with real preselecting (maybe using AJAX)
            // This widget uses constants - show selectbox!
            $input_name   = $base_name ."[]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $use_name     = $this->getProperty('use_name', false);
            $multiple     = $this->multiple() ? ' multiple' : '';

            $widget    = "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add>$widget_add2";
            $selected  = $content->getAaValue($aa_property->getId());
            // empty select option for not required fields and also for live selectbox,
            // because people thinks, that the first value is filled in the database (which is not)
            $add_empty = !$required OR ($type == 'live' AND $selected->isEmpty());
            $options   = $this->getOptions($selected, $content, $use_name, false, $add_empty);
            $widget   .= $this->getSelectOptions( $options );
            $widget   .= "</select>";
        } else {
            $delim          = '';
            $width          = $this->getProperty('width', 60);
            $max_characters = $this->getProperty('max_characters', 254);
            $value          = $content->getAaValue($aa_property->getId());

            $input_type     = 'type=text';
            if (is_object($aa_property->validator)) {
                $input_type = $aa_property->validator->getHtmlInputAttr();
            }
            if (!$input_type) {
                $input_type     = 'type=text';
            }

            for ( $i=0, $ino=$value->valuesCount(); $i<$ino; ++$i) {
                $input_name   = $base_name ."[$i]";
                $input_id     = AA_Form_Array::formName2Id($input_name);
                $input_value  = htmlspecialchars($value->getValue($i));
                $widget      .= "$delim\n<input $input_type size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required $widget_add>$widget_add2";
                $delim        = '<br />';
            }
            // no input was printed, we need to print one
            if ( !$widget ) {
                $input_name   = $base_name ."[0]";
                $input_id     = AA_Form_Array::formName2Id($input_name);
                $widget       = "\n<input $input_type size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"\" $required $widget_add>$widget_add2";
            }
        }

        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }

    /** @return widget HTML for using in form
     *  @param  $aa_property - the variable
     *  @param  $content     - contain the value of property to display
     *                       - never empty - it contain at least aa_owner for
     *                         new objects
     */
    function getHtml($aa_property, $content) {
        return $this->_finalizeHtml($this->_getRawHtml($aa_property, $content), $aa_property);
    }

    function _finalizeHtml($winfo, $aa_property) {
        $base_name   = $winfo['base_name'];
        $base_id     = AA_Form_Array::formName2Id($base_name);
        $required    = $winfo['required'];
        $help        = $aa_property->getHelp();

        $ret  = "<div class=\"aa-widget\"".($required ? ' data-aa-required':'')." id=\"widget-$base_id\">\n";
        $ret .= "  <label for=\"". AA_Form_Array::formName2Id($winfo['last_input_name']) ."\">".$aa_property->getName()."</label>\n";
        $ret .= "  <div class=\"aa-input\">\n";
        $ret .=      $winfo['html']. ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" :'');
        $ret .= "  </div>\n";
        $ret .= "</div>\n";

        return $ret;
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_property - the variable
     *  @param  $content        - contain the value of propertyu to display
     */
    function getAjaxHtml($aa_property, $content) {
        return $this->_finalizeAjaxHtml($this->_getRawHtml($aa_property, $content, 'ajax'), $aa_property);
    }

    /* Creates all common ajax editing buttons to be used by different inputs */
    function _finalizeAjaxHtml($winfo, $aa_property) {
        $base_name    = $winfo['base_name'];
        $base_id      = AA_Form_Array::formName2Id($base_name);
        $help         = $aa_property->getHelp();
        $widget_html  = $winfo['html']. ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" :'');
        $widget_html .= "\n<input class=\"save-button\" type=\"button\" value=\"". _m('SAVE CHANGE') ."\" onclick=\"AA_SendWidgetAjax('$base_id')\">"; //ULOŽIT ZMÌNU
        $widget_html .= "\n<input class=\"cancel-button\" type=\"button\" value=\"". _m('EXIT WITHOUT CHANGE') ."\" onclick=\"DisplayInputBack('$base_id');\">";
        return $widget_html;
    }

    /** @return widget HTML for using as Live component (in place editing)
     *  @param  $aa_property - the variable
     *  @param  $content        - contain the value of propertyu to display
     */
    function getLiveHtml($aa_property, $content, $function) {
        // add JS OK Function
        return str_replace('AA_LIVE_OK_FUNC', $function ? $function : "''", $this->_finalizeLiveHtml($this->_getRawHtml($aa_property, $content, 'live'), $aa_property));
    }

    /* Decorates Live Widget. Prepared for overriding in subclasses */
    function _finalizeLiveHtml($winfo, $aa_property) {
        $base_id      = $winfo['base_id'];
        $help         = $aa_property->getHelp();
        $widget_html  = $winfo['html']; //. ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" :'');
        $ret          = "<div class=\"aa-widget\"".($winfo['required'] ? ' data-aa-required':'')." id=\"widget-$base_id\" style=\"display:inline; position:relative;\">" . $widget_html. "</div>";
        return $ret;
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

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name    = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id      = AA_Form_Array::formName2Id($base_name);
        $widget_add  = ($type == 'live') ? "class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\" style=\"padding-right:16px;\"" : 'style="width:100%"';
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0;">' : '';

        $widget      = '';

        $delim       = '';
        $row_count   = $this->getProperty('row_count', 4);
        $required    = $aa_property->isRequired() ? 'required' : '';
        $value       = $content->getAaValue($aa_property->getId());

        $count       = max($value->valuesCount(),1);
        for ( $i = 0; $i < $count; ++$i ) {
            $input_name   = $base_name ."[$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $input_value  = htmlspecialchars($value->getValue($i));
            $widget      .= "$delim\n<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$row_count\" $required $widget_add>$input_value</textarea>$widget_add2";
            $delim        = '<br />';
        }

        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }
}

/** Textarea with Presets widget */
class AA_Widget_Tpr extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'column_count'           => new AA_Property( 'column_count',           _m("Column count"),         'int',  false, true, 'int',  false, '', '', 70),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** Rich Edit Text Area widget */
class AA_Widget_Edt extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'area_type'              => new AA_Property( 'area_type',              _m("Type"),                 'string', false, true, array('enum',array('class'=>'class', 'iframe'=>'iframe')), false, _m("type: class (default) / iframe"), '', 'class')
            );
    }
}

/** Text Field widget */
class AA_Widget_Fld extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
    function __construct($params=array()) {
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
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'string', false, true, 'string', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete value,<br>A - Add new value<br>C - Change the value<br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."), '', 'MDAC'),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10)
            );
    }
}

/** Text Field with Presets widget */
class AA_Widget_Pre extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'max_characters'         => new AA_Property( 'max_characters',         _m("max characters"),       'int',  false, true, 'int',  false, _m("max count of characters entered (maxlength parameter)"), '', 254),
            'width'                  => new AA_Property( 'width',                  _m("width"),                'int',  false, true, 'int',  false, _m("width of the field in characters (size parameter)"),     '',  30),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'adding'                 => new AA_Property( 'adding',                 _m("Adding"),               'bool', false, true, 'bool', false, _m("adding the selected items to input field comma separated"), '', '0'),
            'second_field'           => new AA_Property( 'second_field',           _m("Second Field"),         'string', false, true, 'string', false, _m("field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"), '', "source_href....."),
            'add2constant'           => new AA_Property( 'add2constant',           _m("Add to Constant"),      'bool', false, true, 'bool', false, _m("if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"), '', "0"),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** Select Box widget */
class AA_Widget_Sel extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** Radio Button widget */
class AA_Widget_Rio extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }

    /** Returns one checkbox tag - Used in inputMultiChBox */
    function getRadioButtonTag($option, $input_name, $input_id, $add='') {
        $ret  = "\n<input type=radio name=\"$input_name\" id=\"$input_id\" value='". htmlspecialchars($option['k']) ."' $add";
        if ( $option['selected'] ) {
            $ret .= " checked";
        }
        $ret .= ">".htmlspecialchars($option['v']);
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name     = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id       = AA_Form_Array::formName2Id($base_name);

        $required    = $aa_property->isRequired() ? 'required' : '';
        $widget_add  = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\" style=\"padding-right:16px;\"" : '';
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';
        $widget      = '';

        $use_name     = $this->getProperty('use_name', false);

        $input_name   = $base_name ."[]";
        $input_id     = AA_Form_Array::formName2Id($input_name);
        $selected     = $content->getAaValue($aa_property->getId());
        $options      = $this->getOptions($selected, $content, $use_name);
        $htmlopt      = array();
        for ( $i=0, $ino=count($options); $i<$ino; ++$i) {
            $htmlopt[]  = $this->getRadioButtonTag($options[$i], $input_name, $input_id, "$widget_add $required");
        }

        $widget = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false), 'aa-tab-rio').$widget_add2;
        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }
}

/** Date widget */
class AA_Widget_Dte extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name     = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id       = AA_Form_Array::formName2Id($base_name);
        $base_name_add = $base_name . '[dte]';
        $widget_add    = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $widget        = '';

        $delim         = '';
        $y_range_minus = $this->getProperty('start_year', 1);
        $y_range_plus  = $this->getProperty('end_year',  10);
        $from_now      = $this->getProperty('relative',   1);
        $display_time  = $this->getProperty('show_time',  1);

        $datectrl = new datectrl('', $y_range_minus, $y_range_plus, $from_now, $display_time);

        $row_count   = $this->getProperty('row_count', 4);
        $value       = $content->getAaValue($aa_property->getId());
        $count       = max($value->valuesCount(),1);
        for ( $i = 0; $i < $count; ++$i ) {
            $datectrl->setdate_int($value->getValue($i));
            $input_name   = $base_name_add. "[d][$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $widget      .= "$delim\n<select name=\"$input_name\" id=\"$input_id\"$widget_add>".$datectrl->getDayOptions()."</select>";
            $input_name   = $base_name_add. "[m][$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $widget      .= "$delim\n<select name=\"$input_name\" id=\"$input_id\"$widget_add>".$datectrl->getMonthOptions()."</select>";
            $input_name   = $base_name_add. "[y][$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $widget      .= "$delim\n<select name=\"$input_name\" id=\"$input_id\"$widget_add>".$datectrl->getYearOptions($aa_property->isRequired())."</select>";
            if ($datectrl->isTimeDisplayed()) {
                $input_name   = $base_name_add. "[t][$i]";
                $input_id     = AA_Form_Array::formName2Id($input_name);
                $widget      .= "$delim\n<input type=\"text\" size=\"8\" maxlength=\"8\" value=\"". $datectrl->getTimeString(). "\"name=\"$input_name\" id=\"$input_id\"$widget_add>";
            }
            $delim        = '<br />';
        }

        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
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

        for ($i=0 ; $i<$max; ++$i) {
            // no date
            if ( strlen($years[$i]) AND !(int)$years[$i]) {
                $values[] = 0;
                continue;
            }
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
    function __construct($params=array()) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name     = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id       = AA_Form_Array::formName2Id($base_name);
        // we use extended version, because of ajax and live widget and the fact
        // the checbox do not send nothing if unslected (so we add [chb][def]
        // hidden field which is send all the time)
        $base_name_add = $base_name . '[chb]';
        $widget_add    = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        $widget        = '';
        $delim         = '';
        $value         = $content->getAaValue($aa_property->getId());
        for ( $i=0, $ino=$value->valuesCount(); $i<$ino; ++$i) {
            $input_name   = $base_name_add ."[$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $input_value  = htmlspecialchars($value->getValue($i));
            $widget      .= "$delim<input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"1\"". ($input_value ? " checked" : '')."$widget_add>";  // do not insert \n here - javascript for sorting tables sorttable do not work then
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$widget ) {
            // do not put there [0] - we need to distinguish between single
            // checkbox and multiple checkboxes in AA_SendWidgetLive() function
            $input_name   = $base_name_add ."[]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $widget      .= "<input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"1\"$widget_add>";
        }
        // default value
        $input_name   = $base_name_add ."[def]";
        $input_id     = AA_Form_Array::formName2Id($input_name);
        $widget      .= "<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"0\">";

        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
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

    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][chb][]=1
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][chb][def]=0
     *  @param $data4field - array('def'=>val, '0'=>val)
     *   This method coverts such data to AA_Value.
     *
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
        }
        if (!count($fld_value_arr)) {
              $fld_value_arr[] = array('value'=>$data4field['def'], 'flag'=>$flag);
        }
        return new AA_Value($fld_value_arr, $flag);
    }
}

/** Multiple Checkboxes widget */
class AA_Widget_Mch extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }

    /** Returns one checkbox tag - Used in inputMultiChBox */
    function getOneChBoxTag($option, $input_name, $input_id, $add='') {
        $ret      = "\n<input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"". htmlspecialchars($option['k']) ."\" $add";
        if ( $option['selected'] ) {
            $ret .= " checked";
        }
        $ret .= ">".htmlspecialchars($option['v']);
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name     = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id       = AA_Form_Array::formName2Id($base_name);
        $base_name_add = $base_name . '[mch]';
        $widget_add    = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        $ret           = '';

        $use_name     = $this->getProperty('use_name', false);

        $selected     = $content->getAaValue($aa_property->getId());
        $options      = $this->getOptions($selected, $content, $use_name);
        $htmlopt      = array();
        for ( $i=0, $ino=count($options); $i<$ino; ++$i) {
            $input_name = $base_name_add ."[$i]";
            $input_id   = AA_Form_Array::formName2Id($input_name);
            $htmlopt[]  = $this->getOneChBoxTag($options[$i], $input_name, $input_id, $widget_add);
        }

        $widget = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false), 'aa-tab-mch');

        // default value - in order something is send when no chbox is checked
        $input_name   = $base_name_add ."[def]";
        $input_id     = AA_Form_Array::formName2Id($input_name);
        $widget      .= "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"\">";

        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }

    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][mch][0]=1
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][mch][def]=0
     *  @param $data4field - array('def'=>val, '0'=>val)
     *   This method coverts such data to AA_Value.
     *
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
        }
        if (!count($fld_value_arr)) {
              $fld_value_arr[] = array('value'=>$data4field['def'], 'flag'=>$flag);
        }
        return new AA_Value($fld_value_arr, $flag);
    }
}

/** Multiple Selectbox widget */
class AA_Widget_Mse extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** Two Boxes widget */
class AA_Widget_Wi2 extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'offer_label'            => new AA_Property( 'offer_label',            _m("Title of \"Offer\" selectbox"), 'string', false, true, 'string', false, '','', _m("Our offer")),
            'selected_label'         => new AA_Property( 'selected_label',         _m("Title of \"Selected\" selectbox"), 'string', false, true, 'string', false, '','', _m("Selected")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'add_form'               => new AA_Property( 'add_form',               _m("Add Form"),             'string', false, true, 'string', false, _m("(for slices only) ID of the form for adding items into related slice"), '', '6f466be8fdf38d67ae8b4973f7c95761'),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** File Upload widget */
class AA_Widget_Fil extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'allowed_ftypes'         => new AA_Property( 'allowed_ftypes',         _m("Allowed file types"),   'string', false, true, 'string', false, '', '', "image/*"),
            'label'                  => new AA_Property( 'label',                  _m("Label"),                'string', false, true, 'string', false, _m("To be printed before the file upload field"), '', _m("File: ")),
            'hint'                   => new AA_Property( 'hint',                   _m("Hint"),                 'string', false, true, 'string', false, _m("appears beneath the file upload field"), '', _m("You can select a file ...")),
            'display_url'            => new AA_Property( 'display_url',            _m("Display URL"),          'int',    false, true, 'int',    false, _m("0 - show, 1 - show if not empty, 2 - do not show"), '', 0)
            );
    }

    /** @return AA_Value for the data send by the widget
     *   This is compound widgets, which consists from more than one input - filled
     *   URL of the file or name of input[type=file] for upload,
     *   so the inputs looks like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][img_upload______][fil][var][]  // varname of uploaded file
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][img_upload______][fil][url][]  // url
     *   Unfortunatey we can't use something like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][img_upload______][fil][up][]  // upoaded file
     *   since the array variabes in $_FILES array are mess in PHP (at least 5.2.5)
     *
     *   This method AA_Widget_Fil::getValue() is called to grab the value
     *   (or multivalues) from the submitted form. The function actually do not
     *   upload the file. The upload itself is done by insert_fnc_fil() later
     *   Here we just mark the uploaded file by prefix AA_UPLOAD:, so
     *   insert_fnc_fil() knows about the new file for upload
     *
     *  @param $data4field - array('var'=>array(), 'url'=>array())
     *  static class method
     */
    function getValue($data4field) {

        $uploads  = (array)$data4field['var'];
        $urls     = (array)$data4field['url'];

        // upload could be also multivalue
        $max = max(count($uploads), count($urls));

        $values = array();

        if (!empty($uploads)) {
            // the information about file is in array - array(name, type, tmp_name, error, size)
            $values[] = 'AA_UPLOAD:'. ParamImplode($uploads);
        }
        elseif ($urls[0]) {
            for ($i=0 ; $i<$max; ++$i) {
                $values[] = $urls[$i];
            }
        }
        return new AA_Value($values);
    }



    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name      = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id        = AA_Form_Array::formName2Id($base_name);
        $widget_add     = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $widget         = '';
        $delim          = '';
        $width          = $this->getProperty('width', 60);            // @todo - width is not property of file widget, yet
        $max_characters = $this->getProperty('max_characters', 254);  // @todo - width is not property of file widget, yet
        $display_url    = (int)$this->getProperty('display_url', 0);
        $value          = $content->getAaValue($aa_property->getId());

        for ( $i=0, $ino=$value->valuesCount(); $i<$ino; ++$i) {
            $input_name   = $base_name ."[fil][url][$i]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $input_value  = htmlspecialchars($value->getValue($i));
            if ($display_url < 2) {
                $widget      .= "$delim\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\"$widget_add>";
            } else {
                $widget      .= "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\">$input_value";
            }
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$widget AND ($display_url == 0)) {
            $input_name   = $base_name ."[fil][url][0]";
            $input_id     = AA_Form_Array::formName2Id($input_name);
            $widget       = "\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"\"$widget_add>";
        }
        $input_name = $base_name ."[fil][var][0]";

        if ($type=='normal') {
            $widget .= '    <input type="file" size="'.$width.'" maxlength="'.$max_characters.'" name="'.$input_name.'" id="'.$input_id.'">'. "<!--$type -->";
        } else {
            $url_params = array('inline'      => 1,
                                'ret_code_js' => 'parent.AA_ReloadAjaxResponse(\''.$base_id.'\', \'AA_ITEM_JSON\')'
                               );
            $widget .= '
                <form id="fuf'.$base_id.'" method="POST" enctype="multipart/form-data" action="'.htmlspecialchars(get_aa_url('filler.php3', $url_params)).'" target="iframe'.$base_id.'">
                <input type="file" size="'.$width.'" maxlength="'.$max_characters.'" name="'.$input_name.'" id="'.$input_id.'">
                <input type="hidden" name="ret_code_enc" id="ret_code_enc'.$base_id.'" value="">
                <input type="submit" name="action" value="'._m('Upload').'">
                </form>
                <iframe id="iframe'.$base_id.'" name="iframe'.$base_id.'" src="" style="width:0;height:0;border:0px solid #fff;visibility:hidden;"></iframe>
                <script language="JavaScript" type="text/javascript"> <!--
                  document.getElementById("ret_code_enc'.$base_id.'").value = document.getElementById("ajaxv_'.$base_id.'").getAttribute(\'data-aa-alias\');
                //-->
                </script>
            ';
        }
        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }

    /** @return widget HTML for using as Live component (in place editing)
     *  @param  $aa_property - the variable
     *  @param  $content        - contain the value of propertyu to display
     */
    function getLiveHtml($aa_property, $content, $function) {
        // this is not standard implementation - we reuse Ajax function instead of Live function, because it is more natural for file upload
        return AA_Stringexpand::unalias('{ajax:'.$content->getId().':'.$aa_property->getId().':<small>{item:'.$content->getId().':'.$aa_property->getId().'}</small><br><input type=button value='. _m('Subir') .'>}');
        // add JS OK Function
        //return str_replace('AA_LIVE_OK_FUNC', $function ? $function : "''", $this->_finalizeAjaxHtml($this->_getRawHtml($aa_property, $content)));
    }

    function _finalizeAjaxHtml($winfo, $aa_property) {
        // not standard - we do not show save button (the upload input works the same way here)
        $base_name    = $winfo['base_name'];
        $base_id      = AA_Form_Array::formName2Id($base_name);
        $help         = $aa_property->getHelp();
        $widget_html  = $winfo['html']. ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" :'');
        $widget_html .= "\n<input class=\"cancel-button\" type=\"button\" value=\"". _m('EXIT WITHOUT CHANGE') ."\" onclick=\"DisplayInputBack('$base_id');\">";
        return $widget_html;
    }
}

/** Related Item Window widget */
class AA_Widget_Iso extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count in the list"),'int',  false, true, 'int',  false, '', '', 15),
            'show_actions'           => new AA_Property( 'show_actions',           _m("Actions to show"),      'string', false, true, 'string', false, _m("Defines, which buttons to show in item selection:<br>A - Add<br>M - add Mutual<br>B - Backward<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."), '', 'AMB'),
            'admin_design'           => new AA_Property( 'admin_design',           _m("Admin design"),         'bool', false, true, 'bool', false, _m("If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."), '' , '0'),
            'tag_prefix'             => new AA_Property( 'tag_prefix',             _m("Tag Prefix"),           'string', false, true, 'string', false, _m("Deprecated: selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."), '', 'AMB'),
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'string', false, true, 'string', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete relation,<br>R - add Relation to existing item<br>N - insert new item in related slice and make it related<br>E - Edit related item<br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."), '', 'MDR'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'filter_conds_changeable'=> new AA_Property( 'filter_conds_changeable',_m("Filtering conditions - changeable"), 'string', false, true, 'string', false, _m("Conditions for filtering items in related items window. This conds user can change."), '', "conds[0][source..........]=Econnect"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }
}

/** Do not show widget */
class AA_Widget_Nul extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
    function __construct($params=array()) {
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
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'string', false, true, 'string', false, _m("Constants (or slice) which is used for value selection")),
            'level_count'            => new AA_Property( 'level_count',            _m("Level count"),          'int',  false, true, 'int',  false, _m("Count of level boxes"), '', "3"),
            'box_width'              => new AA_Property( 'box_width',              _m("Box width"),            'int',  false, true, 'int',  false, _m("Width in characters"), '', "60"),
            'target_size'            => new AA_Property( 'target_size',            _m("Size of target"),       'int',  false, true, 'int',  false, _m("Lines in the target select box"), '', '5'),
            'horizontal'             => new AA_Property( 'horizontal',             _m("Horizontal"),           'bool', false, true, 'bool', false, _m("Show levels horizontally"), '', '1'),
            'first_selectable_level' => new AA_Property( 'first_selectable_level', _m("First selectable"),     'int',  false, true, 'int',  false, _m("First level which will have a Select button"), '', '0'),
            'level_names'            => new AA_Property( 'level_names',            _m("Level names"),          'string', false, true, 'string', false, _m("Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."), '', _m("Top level~Second level~Keyword")),
    //      'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'string', false, true, 'string', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
    //      'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'string', false, true, 'string', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
    //      'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'string', false, true, 'string', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
    //      'additional_slice_pwd'   => new AA_Property( 'additional_slice_pwd',   _m("Slice password"),       'string', false, true, 'string', false, _m("(for slices only) If the related slice is protected by 'Slice Password', fill it here"), '', 'ExtraSecure'),
    //      'relation_field'         => new AA_Property( 'relation_field',         _m("Relation field"),       'string', false, true, 'string', false, _m("(for slices only) Field id of the field which defines the relations in the slice. relation........ is default"), '', 'relation........'),
            'const_arr'              => new AA_Property( 'const_arr',              _m("Values array"),         'string', true,  true, 'string', false, _m("Directly specified array of values (do not use Constants, if filled)")),
            );
    }


    //function _getRawHtml($aa_property, $content, $type='normal') {
    //    $base_name   = AA_Form_Array::getName4Form($aa_property->getId(), $content);
    //    $base_id     = AA_Form_Array::formName2Id($base_name);
    //    $required    = $aa_property->isRequired() ? 'required' : '';
    //    $widget_add  = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_HcoDisplaySub(); AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\" style=\"padding-right:16px;\"" : '';
    //    $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';
    //    $widget      = '';
    //
    //    $base_name_add = $base_name . '[hco]';
    //
    //
    //
    //    // property uses constants or widget have the array assigned (preselect is special - the constants here are not crucial)
    //    if ($this->getProperty('const') OR $this->getProperty('const_arr')) {  // todo - make preselect with real preselecting (maybe using AJAX)
    //        // This widget uses constants - show selectbox!
    //        $input_name   = $base_name_add. "[lev0][]";
    //
    //        $input_id     = AA_Form_Array::formName2Id($input_name);
    //        $use_name     = $this->getProperty('use_name', false);
    //        $multiple     = $this->multiple() ? ' multiple' : '';
    //
    //        $widget    = "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add>$widget_add2";
    //        $selected  = $content->getAaValue($aa_property->getId());
    //        $options   = $this->getOptions($selected, $content, $use_name, false, !$required);
    //        $widget   .= $this->getSelectOptions( $options );
    //        $widget   .= "</select><div id=\"sub$input_id\"></div>";
    //    }
    //
    //    return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    //}
    //
    //function getOptions4Value($slice_id, $value) {
    //    $set     = new AA_Set(array($slice_id), new AA_Condition($this->getProperty('relation_field','relation........'), '=', $value), $this->getProperty('sort_by'));
    //    $options = GetFormatedItems( $set, $this->getProperty('slice_field','_#HEADLINE'), null,  $this->getProperty('additional_slice_pwd'));
    //    return "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add>". $this->getSelectOptions( $options ). "</select>";
    //}
}

/** Password and Change password widget */
class AA_Widget_Pwd extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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


    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $base_name   = AA_Form_Array::getName4Form($aa_property->getId(), $content);
        $base_id     = AA_Form_Array::formName2Id($base_name);

        $required    = $aa_property->isRequired() ? 'required' : '';
        $value       = $content->getAaValue($aa_property->getId());

        $widget = '';
        if (!$value->isEmpty()) {
            $input_name  = $base_name ."[pwd][old]";
            $input_id    = AA_Form_Array::formName2Id($input_name);
            $widget     .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\""._m('Current password')."\">";
        }
        $input_name  = $base_name ."[pwd][new1]";
        $input_id    = AA_Form_Array::formName2Id($input_name);
        $widget     .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\""._m('Password')."\" $required>";
        $input_name  = $base_name ."[pwd][new2]";
        $input_id    = AA_Form_Array::formName2Id($input_name);
        $widget     .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\""._m('Retype New Password')."\" $required>";
        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }


    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][password________][pwd][new]=MyPassword
     *  @param $data4field - array('pwd'=>MyPassword)
     *   This method coverts such data to AA_Value.
     *
     *  static class method
     */
    function getValue($data4field) {
        $flag          = $data4field['flag'] & FLAG_HTML;
        if (is_array($data4field) AND isset($data4field['new'])) {
            return new AA_Value(ParamImplode(array('AA_PASSWD',$data4field['new1'],$data4field['new2'],$data4field['old'])), $flag);
        }
        // older version without the possibility to provide old passwords
        return new AA_Value(ParamImplode(array('AA_PASSWD',reset($data4field))), $flag);
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'width'                  => new AA_Property( 'width',                  _m("Width"),                         'int',  false, true, 'int',  false, _m("width of the three fields in characters (size parameter)"),     '',  60),
            'change_label'           => new AA_Property( 'change_label',           _m("Label for Change Password"),     'string', false, true, 'string', false, _m("Replaces the default 'Change Password'"), '', _m("Change your password")),
            'retype_label'           => new AA_Property( 'retype_label',           _m("Label for Retype New Password"), 'string', false, true, 'string', false, _m("Replaces the default \"Retype New Password\""), '', _m("Retype the new password")),
            'delete_label'           => new AA_Property( 'delete_label',           _m("Label for Delete Password"),     'string', false, true, 'string', false, _m("Replaces the default \"Delete Password\""), '', _m("Delete password (set to empty)")),
            'change_hint'            => new AA_Property( 'change_hint',            _m("Help for Change Password"),      'string', false, true, 'string', false, _m("Help text under the Change Password box (default: no text)"), '', _m("To change password, enter the new password here and below")),
            'retype_hint'            => new AA_Property( 'retype_hint',            _m("Help for Retype New Password"),  'string', false, true, 'string', false, _m("Help text under the Retype New Password box (default: no text)"), '', _m("Retype the new password exactly the same as you entered into \"Change Password\".")),
            );
    }
}

/** Hidden field widget */
class AA_Widget_Hid extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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

    function getHtml($aa_property, $content) {
        $property_id  = $aa_property->getId();
        $input_name   = AA_Form_Array::getName4Form($property_id, $content)."[0]";
        $input_id     = AA_Form_Array::formName2Id($input_name);
        $input_value  = htmlspecialchars($content->getValue($property_id));
        return        "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\">";
    }
}

/** Info text - just Output */
class AA_Widget_Inf extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
        return _m('Info text - output');
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

    function _getRawHtml($aa_property, $content, $type='normal') {
        $property_id  = $aa_property->getId();
        $widget       = htmlspecialchars($content->getValue($property_id));
        $base_name    = AA_Form_Array::getName4Form($property_id, $content);
        $base_id      = AA_Form_Array::formName2Id($base_name);
        $input_name   = $base_name."[0]";
        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    }
}

/** Local URL Picker widget */
class AA_Widget_Lup extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function __construct($params=array()) {
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
            'url'                    => new AA_Property( 'url',                    _m("URL"),                  'string', false, true, 'string', false, _m("The URL of your local web server from where you want to start browsing for a particular URL."), '', _m("http#://www.ecn.cz/articles/solar.shtml"))
            );
    }
}


/** AA_Property class defines one variable in AA. It is describes the datatype,
 *  (numeric, date, string), constraints (range of values, length, if it is
 *  required, ...), name, and some description of the variable. It do not hold
 *  the information, how the value is presented to the user and how it could
 *  be entered. It also do not contain the value of the variable.
 *  For displaying the AA_Variable we choose some AA_Widget and pass
 *  the AA_Value there.
 *  Used also for definition of components's parameters
 *  (like AA_Transofrmations, ...)
 *
 *  This approach AA_Property/AA_Widget/AA_Value should replace the old - all
 *  in one AA_Inputfield approach. It should be used not only for AA Fields,
 *  but also for parameters of functions/widgets...
 */
class AA_Property extends AA_Storable {

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

    /** AA_Value - default value for the field. If value for store not matching the validation, the default is used*/
    var $default;

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
    *   (FLAG_HTML at this moment)
    */
    var $content_type_switch_default;

    /** @todo some kind of perms - who can edit/change, ... - not defined, yet */
    var $perms;

    /** array of constants used for selections (selectbox, radio, ...) */
    var $const_arr = array();


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
    function AA_Property($id='', $name='', $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perms=null, $default=null) {  // default values are needed for AA_Storable's construction
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
        $this->perm                        = $perms;
        $this->const_arr                   = (is_array($validator) AND ($validator[0]=='enum')) ? $validator[1] : array();
        $this->default                     = $default;
    }

    /** getClassProperties function
     * Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (
            //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'id'                          => new AA_Property( 'id'                         , _m('id'                         ), 'string',   false),
            'name'                        => new AA_Property( 'name'                       , _m('name'                       ), 'string',   false),
            'type'                        => new AA_Property( 'type'                       , _m('type'                       ), 'string',   false),
            'multi'                       => new AA_Property( 'multi'                      , _m('multi'                      ), 'bool',     false),
            'persistent'                  => new AA_Property( 'persistent'                 , _m('persistent'                 ), 'bool',     false),
            'validator'                   => new AA_Property( 'validator'                  , _m('validator'                  ), 'string',   false),
            'required'                    => new AA_Property( 'required'                   , _m('required'                   ), 'bool',     false),
            'input_help'                  => new AA_Property( 'input_help'                 , _m('input_help'                 ), 'string',   false),
            'input_morehlp'               => new AA_Property( 'input_morehlp'              , _m('input_morehlp'              ), 'string',   false),
            'example'                     => new AA_Property( 'example'                    , _m('example'                    ), 'string',   false),
            'show_content_type_switch'    => new AA_Property( 'show_content_type_switch'   , _m('show_content_type_switch'   ), 'bool',     false),
            'content_type_switch_default' => new AA_Property( 'content_type_switch_default', _m('content_type_switch_default'), 'string',   false),
            'perm'                        => new AA_Property( 'perm'                       , _m('perm'                       ), 'string',   false),
            'const_arr'                   => new AA_Property( 'const_arr'                  , _m('const_arr'                  ), 'string',   true),
            'default'                     => new AA_Property( 'default'                   , _m('default'                     ), 'AA_Value', true)
            );
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

    /** getHelp function */
    function getHelp() {
        return $this->input_help;
    }

    /** getHelp function */
    function getConstants() {
        return $this->const_arr;
    }

    /** set the Values array and also the validator */
    function setConstants($arr) {
        $this->const_arr = (array) $arr;
        $this->validator = new AA_Validate_Enum($this->const_arr);
    }

    /** called before StoreItem to fill the field with correct data */
    function  complete4Insert($new_value, $profile) {
        $fid           = $this->getId();
        $profile_value = $profile->getProperty('hide&fill',$fid) || $profile->getProperty('fill',$fid);
        if ($profile_value) {
            $new_value = $profile->parseContentProperty($profile_value);
        }
//        if ($profile->getProperty('hide',$fid) || !$this->validate($new_value) || $new_value->isEmpty()) {
        if ($profile->getProperty('hide',$fid) || !$this->validate($new_value->getValues())) {
            return $this->default;
        }
        return $new_value;
    }

    /** Converts AA_Value to real property value (scallar, Array, ...) */
    function toValue($aa_value) {
        if ($this->isMulti()) {
            $val = $aa_value->getValues();
            foreach($val as &$value) {
                $this->validator->validate($value);
            }
        } else {
            $val = $aa_value->getValue();
            $this->validator->validate($val);
        }
        return $val;
    }

    function validate($value_arr) {
        $valid  = true;
        foreach ( $value_arr as $v) {
            $valid = $this->validator->validate($v);
            if ( !$valid ) {
                break;
            }
        }
        return $valid;
    }

    /** isObject function */
    function isObject() {
        return !in_array($this->type, array('text', 'string', 'int', 'bool', 'float'));
    }

    /** @return the table, where the property would be stored */
    static private function _storageType($type) {
        switch ($type) {
        case 'string':
        case 'text':   return 'object_text';
        case 'int':
        case 'bool':   return 'object_integer';
        case 'float':  return 'object_float';
        }
        // object_text for serialized object data ...
        return 'object_text';
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

    /** save property to the database
     * @param $value
     * @param $priority
     */
    function save($value, $object_id, $owner_id='') {
        $ret = true;
        if ($this->isMulti()) {
            if ( is_array($value) ) {
                // all keys are numeric
                foreach($value as $k => $v) {
                    $ret = $ret & $this->_saveSingle($v, $object_id, $k, $owner_id);
                }
//            } elseif (!empty($value)) {
//                throw new Exception('Property marked as multi but do not contain array value');
//            not necessary - we must call validate before object saving,
//            so this king of thing is already spotted
            }
        } else {
            $ret = $this->_saveSingle($value, $object_id, 0, $owner_id);
        }
        return $ret;
    }

    /** _saveRow function
     * @param $property_id
     * @param $value
     * @param $type
     * @param $priority
     */
    private function _saveSingle($value, $object_id, $priority=0, $owner_id) {
//      not necessary - we must call validate before objest saving,
//      so this king of thig is already spotted
//      if ( is_array($value) ) {
//          throw new Exception('Property marked as scalar (not multi) but contain array value');
//      }

        // Property type - text | int | bool | float | <class_name>
        if ( !$this->isObject()) {
            AA_Property::_saveRow($this->id, $value, $this->type, $object_id, $priority);
            return true;
        }

        if (empty($value)) {
            return true;
        }

        if (is_subclass_of($value, 'AA_Object')) {
            //  this property is object - so save it (the id of the object is returned)
            $value->setOwnerId($owner_id);
            $sub_object_id = $value->save();
            // if not saved, then it returns null
            if (!$sub_object_id) {
                return false;
            }
            AA_Property::_saveRow($this->id, $sub_object_id, 'text', $object_id, $priority);
            return true;
        } elseif (is_subclass_of($value, 'AA_Storable')) {
            AA_Property::_saveRow($this->id, serialize($value->getState()), $this->type, $object_id, $priority);
            return true;
        }
        throw new Exception('object is not AA_Storable - ', $this->id);
        return false;
    }

    /** _saveRow function
     * @param $property_id
     * @param $value
     * @param $type
     * @param $priority
     */
    static private function _saveRow($property_id, $value, $type, $object_id, $priority=0) {
        $varset = new CVarset();
        $varset->add('object_id', 'text',   $object_id);
        $varset->add('priority',  'number', $priority);
        $varset->add('property',  'text',   $property_id);
        $varset->add('value',      $type,   $value);        // Property type - text | int | bool | float | <class_name>
        $varset->doInsert(AA_Property::_storageType($type));
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
    function getStandardFormattersBitfield() {
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
