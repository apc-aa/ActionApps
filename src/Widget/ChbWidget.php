<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:32
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;

/** Check Box widget */
class ChbWidget extends Widget
{

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
        $base_id = FormArray::formName2Id($base_name);
        // we use extended version, because of ajax and live widget and the fact
        // the checbox do not send nothing if unslected (so we add [chb][def]
        // hidden field which is send all the time)
        $base_name_add = $base_name . '[chb]';
        $widget_add = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        $widget = '';
        $delim = '';
        $value = $content->getAaValue($aa_property->getId());
        $required = $aa_property->isRequired() ? ' required' : '';

        for ($i = 0, $ino = $value->count(); $i < $ino; ++$i) {
            $input_name = $base_name_add . "[$i]";
            $input_id = FormArray::formName2Id($input_name);
            $input_value = myspecialchars($value->getValue($i));
            $widget .= "$delim<input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"1\"$required" . ($input_value ? " checked" : '') . "$widget_add>";  // do not insert \n here - javascript for sorting tables sorttable do not work then
            $delim = '<br />';
            $required = '';
        }
        // no input was printed, we need to print one
        if (!$widget) {
            // do not put there [0] - we need to distinguish between single
            // checkbox and multiple checkboxes in AA_SendWidgetLive() function
            $input_name = $base_name_add . "[]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= "<input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"1\"$required $widget_add>";
        }
        // default value
        $input_name = $base_name_add . "[def]";
        $input_id = FormArray::formName2Id($input_name);
        $widget .= "<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"0\">";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Check Box');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return [];
    }

    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][chb][]=1
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][chb][def]=0
     * @param $data4field - array('def'=>val, '0'=>val)
     *   This method coverts such data to AA_Value.
     *
     */
    public static function getValue($data4field): AA_Value
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        $fld_value_arr = [];

        foreach ((array)$data4field as $key => $value) {
            if (ctype_digit((string)$key)) {
                $fld_value_arr[$key] = ['value' => $value, 'flag' => $flag];
            }
        }
        if (!count($fld_value_arr)) {
            $fld_value_arr[] = ['value' => $data4field['def'], 'flag' => $flag];
        }
        return new AA_Value($fld_value_arr, $flag);
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}