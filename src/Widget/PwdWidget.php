<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:37
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;

/** Password and Change password widget */
class PwdWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Password and Change password');
    }   // widget name

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

        $required = $aa_property->isRequired() ? 'required' : '';
        $value = $content->getAaValue($aa_property->getId());

        $widget = '';
        if (!$value->isEmpty()) {
            $input_name = $base_name . "[pwd][old]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Current password') . "\">";
//            $widget .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Current password') . "\" required  checkValidity=\"function () {alert('no'); this.setCustomValidity('jaj'); return false;}\">";
        }
        $input_name = $base_name . "[pwd][new1]";
        $input_id = FormArray::formName2Id($input_name);
        $widget .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Password') . "\" $required>";
        $input_name = $base_name . "[pwd][new2]";
        $input_id = FormArray::formName2Id($input_name);
//        $widget .= "\n<input type=\"password\" id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Retype New Password') . "\" $required checkValidity=\"function () {return false;}\">";
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }


    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][password________][pwd][new]=MyPassword
     * @param $data4field - array('pwd'=>MyPassword)
     *   This method coverts such data to AA_Value.
     */
    public static function getValue($data4field): AA_Value
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        if (is_array($data4field) AND isset($data4field['new1'])) {
            return new AA_Value(ParamImplode(['AA_PASSWD', $data4field['new1'], $data4field['new2'], $data4field['old']]), $flag);
        }
        // older version without the possibility to provide old passwords
        return new AA_Value(ParamImplode(['AA_PASSWD', reset($data4field)]), $flag);
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['width', 'change_label', 'retype_label', 'delete_label', 'change_hint', 'retype_hint']);
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}