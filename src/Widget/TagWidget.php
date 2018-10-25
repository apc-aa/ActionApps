<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:34
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;
use zids;

/** Tag input - in fact the result is very similar to related item window - it adds related items */
class TagWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Tags');   // widget name
    }

    /** returns if the widget handles multivalue or just single value   */
    function multiple()
    {
        return true;
    }

    function requireLibs()
    {
        return array('aa-jslib@2');
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('const', 'bin_filter', 'filter_conds', 'slice_field', 'sort_by', 'additional_slice_pwd'));
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);

        $input_name = $base_name . '[tag][]';
        $input_id = FormArray::formName2Id($input_name);

        // $widget_add    = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        // $widget_add2   = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';

        // $show_buttons  = $this->getProperty('show_buttons', 'MDAC');

        // we send to responder the slice and field id of the field with the DEFINITION
        // I do not want to transport all the settings over GET parameter

        // $def_field_id = $aa_property->getId();
        // $def_slice_id = $content->getOwnerId();

        $opts = $this->getFormattedOptions(null, new zids($content->getValuesArray($aa_property->getId()), 'l'));
        $json_val = array();
        foreach ($opts as $id => $text) {
            $json_val[] = array('id' => $id, 'text' => $text);
        }

        $prefill = json_encode($json_val);     //'[{id:"CA", text:"Califoria"}, {id:"CERVENA", text:"Red"}]';

        $widget = "<input type=hidden id=\"$input_id\" name=\"$input_name\" style=\"width:300px;\">
        <script>
          AA_LoadCss('" . AA_INSTAL_PATH . "javascript/select2/select2.css');
          AA_LoadJs( window.Select2 !== undefined, function() {aa_maketags('$input_id', $prefill, '$def_slice_id', '$def_field_id','" . AA_INSTAL_PATH . "');}, '" . AA_INSTAL_PATH . "javascript/select2/select2.min.js');
        </script>
        ";
        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }

    /** @return AA_Value for the data send by the widget
     *   We use it, because we want to remove all the empty values
     *
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][tag][]=bc9032bb4bd0751086ccc773a36ab936|||5893020f01ddaeedecc02588109daf8d|||test
     * @param $data4field - array('0'=>values separated by |||)
     *   This method coverts such data to AA_Value.
     *
     */
    static function getValue($data4field)
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = array();

        foreach ((array)$data4field as $key => $value) {
            if (ctype_digit((string)$key) AND strlen($value)) {
                $vals = explode("|||", $value);
                foreach ($vals as $v) {
                    if (strlen(trim($v))) {
                        $fld_value_arr[] = array('value' => 't' . $v, 'flag' => $flag);
                    }
                }
            }
        }
        return new AA_Value($fld_value_arr, $flag);
    }
}