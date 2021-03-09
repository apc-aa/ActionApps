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

/** Multiple Checkboxes widget */
class MchWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Multiple Checkboxes');   // widget name
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
        return Widget::propertiesShop(['const', 'columns', 'move_right', 'slice_field', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'height']);
    }

    /** Returns one checkbox tag - Used in removed inputMultiChBox */
    function getOneChBoxTag($option, $input_name, $input_id, $add = '')
    {
        $ret = "\n<label class=\"aa-chb\"><input type=\"checkbox\" name=\"$input_name\" id=\"$input_id\" value=\"" . myspecialchars($option['k']) . "\" $add";
        if ($option['selected']) {
            $ret .= " checked";
        }
        $ret .= ">" . myspecialchars($option['v']) . '</label>';
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
        $base_id = FormArray::formName2Id($base_name);
        $base_name_add = $base_name . '[mch]';
        $widget_add = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $use_name = $this->getProperty('use_name', false);
        $height = (int)$this->getProperty('height');
        if ($height < 1) {
            $height = 400;
        }

        $selected = $content->getAaValue($aa_property->getId());
        $options = $this->getOptions($selected, $content, $use_name);
        $htmlopt = [];
        for ($i = 0, $ino = count($options); $i < $ino; ++$i) {
            $input_name = $base_name_add . "[$i]";
            $input_id = FormArray::formName2Id($input_name);
            $htmlopt[] = $this->getOneChBoxTag($options[$i], $input_name, $input_id, $widget_add);
        }

        $selection = [];
        foreach ($options as $o) {
            if ($o['selected']) {
                $selection[] = '<span data-aa-mchval="' . myspecialchars($o['k']) . '" onclick="var el=document.querySelector(\'#widget-' . $base_id . ' input[type=checkbox][value=&quot;' . $o['k'] . '&quot;]\');el.checked=!el.checked;this.style.opacity=el.checked?1:.3;el.dispatchEvent(new Event(\'change\', { \'bubbles\': true }));">' . $o['v'] . '</span>';
            }
        }

        // default value - in order something is send when no chbox is checked
        $input_name = $base_name_add . "[def]";
        $input_id = FormArray::formName2Id($input_name);
        $widget = count($selection) ? ('<div class="aa-mch-selected"><strong>' . _m('Selected') . ':</strong><div class="aa-mch-tags">' . join(' ', $selection)) . '</div></div>' : '';
        $widget .= '<div class="aa-mch-list" style="max-height:' . $height . 'px; overflow:auto;">';
        $widget .= "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"\">";
        $widget .= $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false), 'aa-tab-mch');
        $widget .= '</div>';

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][switch__________][mch][0]=1
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][headline________][mch][def]=0
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

    /** @inheritDoc */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}