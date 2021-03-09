<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:31
 */

namespace AA\Widget;
use AA\FormArray;

/** Select Box widget */
class SelWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Select Box');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'slice_field', 'use_name', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'filter_conds_rw']);
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
        $required = $aa_property->isRequired() ? 'required' : '';
        $widget_add = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-right:16px;\"" : '';
        //$widget_adds = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-left:16px;\"" : '';
        $widget = '';
        $autofocus = ($type == 'ajax') ? 'autofocus' : '';

        // This widget uses constants - show selectbox!
        $input_name = $base_name . "[]";
        $input_id = FormArray::formName2Id($input_name);
        $use_name = $this->getProperty('use_name', false);
        $multiple = $this->multiple() ? ' multiple' : '';

        //$widget    = AA\Widget\Widget::_saveIcon($base_id, $type == 'live', 'left')."<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_adds $autofocus>";
        $widget = Widget::_saveIcon($base_id, $type == 'live') . "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add $autofocus>";
        $selected = $content->getAaValue($aa_property->getId());
        // empty select option for not required fields and also for live selectbox,
        // because people thinks, that the first value is filled in the database (which is not)
        $add_empty = (!$required ? 1 : 2);
        $options = $this->getOptions($selected, $content, $use_name, false, $add_empty);
        $widget .= $this->getSelectOptions($options);
        $widget .= "</select>";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }


    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}