<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:29
 */

namespace AA\Widget;
use AA;
use AA\FormArray;
use AA_Value;

/** Multiple Text Field widget */
class MflWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Multiple Text Field');   // widget name
    }

    /** returns if the widget handles multivalue or just single value   */
    function multiple()
    {
        return true;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        $ret = \AA\Widget\Widget::propertiesShop(array('show_buttons_txt', 'row_count', 'max_characters', 'width', 'rows'));
        $ret['rows']->setHelp(_m("if 1 (default), textfield used. for rows>1 textareas are used"))->setExample(1);
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_name_add = $base_name . '[mfl]';

        $base_id = FormArray::formName2Id($base_name);
        // $widget_add    = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        // $widget_add2   = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';

        $row_count = (int)$this->getProperty('row_count', 6);
        $rows = (int)$this->getProperty('rows', 1);
        //$show_buttons  = $this->getProperty('show_buttons', 'MDAC');

        $value = $content->getAaValue($aa_property->getId());

        $attrs = array('type' => 'text');
        $attrs = array_merge($attrs, $aa_property->getValidator()->getHtmlInputAttr());

        AA::$debug && AA::$dbg->log('mfl', $aa_property, $attrs, $this->getProperty('width'));

        $attrs['size'] = get_if($this->getProperty('width'), $attrs['size'], 60);
        $attrs['maxlength'] = get_if($this->getProperty('max_characters'), $attrs['maxlength'], 255);
        $attr_string = join(' ', array_map(function ($k, $v) {
            return "$k=\"$v\"";
        }, array_keys($attrs), $attrs));

        $widget_add2 = '';
        $widget_add = $attrs['size'] ? '' : 'style="width:100%"';
        if ($type == 'live') {
            $widget_add = "class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"";
            $widget_add2 = '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0;">';
        }


        AA::$debug && AA::$dbg->log($attrs, $attr_string, $rows);

        $widget = '';
        // display at least one option
        for ($i = 0, $ino = max(1, $row_count, $value->count()); $i < $ino; ++$i) {
            $input_name = $base_name_add . "[$i]";
            $input_id = FormArray::formName2Id($input_name);
            $input_value = myspecialchars($value->getValue($i));
            $required = ($aa_property->isRequired() AND ($i == 0)) ? 'required' : '';

            if ($rows > 1) {
                $widget .= "<div><textarea name=\"$input_name\" id=\"$input_id\" rows=\"$rows\" $required $widget_add>$input_value</textarea>$widget_add2</div>";  // do not insert \n here - javascript for sorting tables sorttable do not work then
            } else {
                $widget .= "<div><input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required $widget_add></div>$widget_add2";  // do not insert \n here - javascript for sorting tables sorttable do not work then
            }
        }
        $widget = "<div id=\"allrows$base_id\">$widget</div>";
        $img = GetAAImage('icon_new.gif', _m('new'), 17, 17);
        if ($rows > 1) {
            $widget .= "\n<a href=\"javascript:void(0)\" onclick=\"var el=document.createElement('div'); el.innerHTML='<textarea name=&quot;$base_name" . "[mfl][]&quot; rows=&quot;$rows&quot;></textarea>'; document.getElementById('allrows$base_id').appendChild(el); return false;\">$img</a>";
        } else {
            $attr_string = str_replace('"', '&quot;', $attr_string);
            $widget .= "\n<a href=\"javascript:void(0)\" onclick=\"var el=document.createElement('div'); el.innerHTML='<input $attr_string name=&quot;$base_name" . "[mfl][]&quot; value=&quot;&quot;>'; document.getElementById('allrows$base_id').appendChild(el); return false;\">$img</a>";
        }

        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }

    /** @return AA_Value for the data send by the widget
     *   We use it, because we want to remove all the empty values
     *
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][mfl][]=1
     * @param $data4field - array('0'=>val1, '1'=>val)
     *   This method coverts such data to AA_Value.
     *
     *
     *  static class method
     */
    static function getValue($data4field)
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = array();

        foreach ((array)$data4field as $key => $value) {
            if (ctype_digit((string)$key) AND strlen($value)) {
                $fld_value_arr[$key] = array('value' => $value, 'flag' => $flag);
            }
        }
        if (!count($fld_value_arr)) {
            $fld_value_arr[] = array('value' => '', 'flag' => $flag);
        }

        return new AA_Value($fld_value_arr, $flag);
    }


}