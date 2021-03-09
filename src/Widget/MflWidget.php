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
use AA_Formatters;
use AA_Value;

/** Multiple Text Field widget */
class MflWidget extends Widget
{
    /** @var array  list of textareas for CodeMirror creation */
    protected $ids_4_editor = [];

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Multiple Text Field');   // widget name
    }

    /** @inheritDoc */
    public function description(): string { return ''; }

    // inherited from \AA\Widget\Widget
    function requireLibs($aa_property, $winfo)
    {
        return ['codemirror@5'];
    }

    /** script to run after the page DOM is loaded */
    function requireRun($aa_property, $winfo)
    {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_editor) {
           $ret .= "\n  document.querySelectorAll(\"". join(',',$this->ids_4_editor) .'").forEach( function(el) { CodeMirror.fromTextArea(el, { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" });});';

           // clean the list - all textareas are converted
           $this->ids_4_editor = [];
        }
        return $ret;
    }

    /** returns if the widget handles multivalue or just single value   */
    function multiple()
    {
        return true;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        $ret = Widget::propertiesShop(['show_buttons_txt', 'row_count', 'max_characters', 'width', 'rows']);
        $ret['rows']->setHelp(_m("if 1 (default), textfield used. for rows>1 textareas are used"))->setExample(1);
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_name_add = $base_name . '[mfl]';

        $base_id = FormArray::formName2Id($base_name);
        // $widget_add    = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        // $widget_add2   = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';

        $row_count = (int)$this->getProperty('row_count', 6);
        $def_rows = (int)$this->getProperty('rows', 1);
        //$show_buttons  = $this->getProperty('show_buttons', 'MDAC');

        $inputtype = ($def_rows > 1) ? 'textarea' : 'textfield';

        $value = $content->getAaValue($aa_property->getId());

        $widget_add      = '';
        $widget_add2     = '';
        $widget_onchange = '';

        if ($inputtype == 'textfield') {
            $attrs = ['type' => 'text'];
            $attrs = array_merge($attrs, $aa_property->getValidator()->getHtmlInputAttr());
            $attrs['size'] = get_if($this->getProperty('width'), $attrs['size'], 60);
            $attrs['maxlength'] = get_if($this->getProperty('max_characters'), $attrs['maxlength'], ($inputtype == 'textarea') ? '' : 255);
            $attr_string = join(' ', array_map(function ($k, $v) {
                return "$k=\"$v\"";
            }, array_keys($attrs), $attrs));
            $widget_add .= $attrs['size'] ? '' : ' style="width:100%"';
        }

        AA::$debug&2 && AA::$dbg->log('mfl', $aa_property, $attrs, $this->getProperty('width'));

        if ($type == 'live') {
            $widget_add      .= " class=live onkeypress=\"AA_StateChange('$base_id', 'dirty')\"";
            $widget_onchange = "onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"";
            $widget_add2     = '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0;">';
        }

        $widget = '';
        $widget .= ($type == 'normal') ? AA_Formatters::getFormattersRadio($base_name, $aa_property, $value) : AA_Formatters::getHiddenFormatters($base_name, $aa_property, $value); // ajax, live
        // display at least one option
        for ($i = 0, $ino = max(1, $row_count, $value->count()); $i < $ino; ++$i) {
            $input_name = $base_name_add . "[$i]";
            $input_id = FormArray::formName2Id($input_name);
            $input_value = myspecialchars($value->getValue($i));
            $rows = (($my_rows = substr_count($input_value, "\n")) > $def_rows) ? min($my_rows + 2, 30) : $def_rows;
            $required = ($aa_property->isRequired() AND ($i == 0)) ? 'required' : '';

            if ($inputtype == 'textarea') {
                if ($i > 0) {
                    $widget .= '<br>';
                }
                $widget .= "<div><textarea name=\"$input_name\" id=\"$input_id\" rows=\"$rows\" $required $widget_add $widget_onchange>$input_value</textarea>$widget_add2</div>";  // do not insert \n here - javascript for sorting tables sorttable do not work then
                // arr this textarea to the list for CodeMirror creation
                $this->ids_4_editor[] = '#'.$input_id;
            } else {
                $widget .= "<div><input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required $widget_add $widget_onchange>$widget_add2</div>";  // do not insert \n here - javascript for sorting tables sorttable do not work then
            }
            $widget_add2 = '';
            $widget_onchange = ($type == 'live') ? "onchange=\"document.getElementById('".FormArray::formName2Id($base_name_add . "[0]")."').onchange()\"" : '';     
        }
        $widget = "<div id=\"allrows$base_id\">$widget</div>";
        $img = GetAAImage('icon_new.gif', _m('new'), 17, 17);
        $attr_string     = str_replace('"', '&quot;', $attr_string);
        $widget_add      = str_replace(['"',"'"], ['&quot;',"\'"], $widget_add);
        $widget_onchange = str_replace(['"',"'"], ['&quot;',"\'"], $widget_onchange);
        if ($inputtype == 'textarea') {
            $widget .= "\n<a href=\"javascript:void(0)\" onclick=\"var el=document.createElement('div'); el.innerHTML='<br><textarea $attr_string name=&quot;$base_name" . "[mfl][]&quot; rows=&quot;$rows&quot; $widget_add $widget_onchange></textarea>'; document.getElementById('allrows$base_id').appendChild(el); CodeMirror.fromTextArea(el.querySelector('textarea'), { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: &quot;htmlmixed&quot; }); return false;\">$img</a>";
        } else {
            $widget .= "\n<a href=\"javascript:void(0)\" onclick=\"var el=document.createElement('div'); el.innerHTML='<input $attr_string name=&quot;$base_name" . "[mfl][]&quot; value=&quot;&quot; $widget_add $widget_onchange>'; document.getElementById('allrows$base_id').appendChild(el); return false;\">$img</a>";
        }

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
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
    public static function getValue($data4field): AA_Value
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = [];

        foreach ((array)$data4field as $key => $value) {
            if (ctype_digit((string)$key) AND strlen($value)) {
                $fld_value_arr[$key] = ['value' => $value, 'flag' => $flag];
            }
        }
        if (!count($fld_value_arr)) {
            $fld_value_arr[] = ['value' => '', 'flag' => $flag];
        }

        return new AA_Value($fld_value_arr, $flag);
    }
}