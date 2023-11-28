<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:34
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Validate;
use AA_Value;
use zids;

/** Tag input - in fact the result is very similar to related item window - it adds related items */
class TagWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Tags');   // widget name
    }

    /** returns if the widget handles multivalue or just single value   */
    public static function multiple(): bool {
        return true;
    }

    /** array of libs needed for the widget - automatically inserted to the inputform */
    protected function requireLibs($aa_property, $winfo): array {
        return ['select2@4','aa-jslib@3'];
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'bin_filter', 'filter_conds', 'slice_field', 'sort_by', 'additional_slice_pwd', 'max_values', 'disable_new', 'filter_conds_rw']);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal'): array {
        $def_field_id = $aa_property->getId();
        $def_slice_id = $content->getOwnerId();
        $def_item_id  = $content->getId() ?: '';

    //    $disable_new = (int)$this->getProperty('disable_new', 0);
    //
    //    $base_name = FormArray::getName4Form($def_field_id, $content, $this->item_index);
    //    $base_id = FormArray::formName2Id($base_name);
    //
    //    $input_name = $base_name . '[tag][]';
    //    $input_id = FormArray::formName2Id($input_name);
    //
    //    // $widget_add    = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
    //    // $widget_add2   = ($type == 'live') ? '<img width=16 height=16 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';
    //
    //    // $show_buttons  = $this->getProperty('show_buttons', 'MDAC');
    //
    //    // we send to responder the slice and field id of the field with the DEFINITION
    //    // I do not want to transport all the settings over GET parameter
    //
    //    $opts = $this->getFormattedOptions(null, new zids($content->getValuesArray($def_field_id), 'l'));
    //    $json_val = array();
    //    foreach ($opts as $id => $text) {
    //        $json_val[] = array('id' => $id, 'text' => $text);
    //    }
    //
    //    $prefill = json_encode($json_val);     //'[{id:"CA", text:"Califoria"}, {id:"CERVENA", text:"Red"}]';
    //
    //    $widget = "<input type=hidden id=\"$input_id\" name=\"$input_name\" style=\"width:300px;\">
    //    <script>
    //      //AA_LoadCss('" . AA_INSTAL_PATH . "javascript/select2/select2.css');
    //      AA_LoadJs( true, function() {aa_maketags('$input_id', $prefill, '$def_slice_id', '$def_field_id','" . AA_INSTAL_PATH . "', $disable_new);}, '');
    //    </script>
    //    ";
    //    return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());

        // $disable_new = (int)$this->getProperty('disable_new', 0); // this parameter is maintained in responder

        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $required = $aa_property->isRequired() ? 'required' : '';
        //$widget_add = '';
        //$widget_add = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-right:16px;\"" : '';
        //$widget_adds = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-left:16px;\"" : '';

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);
        $attrs['class'] = trim(($attrs['class'] ?? '') . ' aa-w-full');
        $widget_add = htmlattr($attrs);

        $autofocus = ($type == 'ajax') ? 'autofocus' : '';



        // This widget uses constants - show selectbox!
        $input_name = $base_name . "[tag][]";
        $input_id = FormArray::formName2Id($input_name);

        $max_values = (int)$this->getProperty('max_values', 0); // Limit the number of values entered. If 0 or empty, the number is unlimitted

        if (($max_values == 1) AND $required) {
            $multiple   = '';
            $max_values = 0;  // if I do not null it, the select2 widget will not allow changing the value
        } else {
            $multiple = ' multiple';
        }

        // send empty string always - used for sending value, even if the select is empty, so we know, the widget is in use
        $widget =  "<input type=hidden name=\"$input_name\" value=\"\">";

        //$widget    = AA\Widget\Widget::_saveIcon($base_id, $type == 'live', 'left')."<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_adds $autofocus>";
        $widget .= Widget::_saveIcon($base_id, $type == 'live') . "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $autofocus $widget_add>";
        //$selected = $content->getAaValue($aa_property->getId());
        // empty select option for not required fields and also for live selectbox,
        // because people thinks, that the first value is filled in the database (which is not)
        //$options = $this->getOptions($selected, $content);
        $sel_options_plain = $this->getFormattedOptions(null, new zids($content->getValuesArray($aa_property->getId()), 'l'));
        $sel_options = [];
        foreach ($sel_options_plain as $k => $v) {
            $sel_options[] = ['k'=>$k, 'v'=>$v, 'selected'=>true];
        }
        $widget .= $this->getSelectOptions($sel_options);
        $widget .= "</select>";

        // AA_LoadCss('" . AA_INSTAL_PATH . "javascript/select2/select2.css');
        // AA_LoadJs( true, function() {aa_maketags('$input_id', '$def_slice_id', '$def_field_id','" . AA_INSTAL_PATH . "');}, '');
        // when called by Ajax, the requireLibs() in called in responder is not enough for some reason
        // maybe we can change it to async / await / Promises
        $widget .="
            <script> 
              AA_LoadJs( jQuery.fn.select2, function() {aa_maketags('$input_id', '$def_slice_id', '$def_field_id', $max_values, '$def_item_id');}, '".\AA_Requires::getUrl4Lib('select2@4')."');
            </script>";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
        

    }

    /** @param $data4field - ['tag'=>['0'=>values separated by |||]]
     *   This method coverts such data to AA_Value.
     *
     * @return AA_Value for the data send by the widget
     *
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][tag][]=bc9032bb4bd0751086ccc773a36ab936
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][tag][]=test
     */
    public static function getWidgetValue(array $data4field): AA_Value
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $data4field = $data4field['tag'];
        $fld_value_arr = [];

        foreach ((array)$data4field as $key => $value) {
            $value = trim($value);
            if (strlen($value) AND ctype_digit((string)$key)) {
                $fld_value_arr[] = ['value' => (is_long_id($value) ? '':'t') .$value, 'flag' => $flag];
            }
        }
        return new AA_Value($fld_value_arr, $flag);
    }

    /**
     * @inheritDoc
     */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }

    /** In most cases returns the Validator from the field definition string. In some cases (like Tag or ISO) it returns upgraded validator (should allow) more than IDs in both cases
     * @param string $validator_string
     * @return AA_Validate|null
     */
    public function getUpgradedValidator(string $validator_string): ?AA_Validate {
        if ( (substr($validator_string,0,2)=='id') AND ((int)$this->getProperty('disable_new', 0) != 1)) {
            $validator_string = 'text';  // we should allow adding new text here
        }
        return AA_Validate::factoryByString($validator_string);
    }
}