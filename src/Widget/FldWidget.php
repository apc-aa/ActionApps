<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:29
 */

namespace AA\Widget;
use AA\FormArray;

/** Text Field widget */
class FldWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Text Field');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('max_characters', 'width'));
    }


    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $required = $aa_property->isRequired() ? 'required' : '';
        $widget_add = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-right:16px;\"" : '';
        //$widget_adds = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-left:16px;\"" : '';
        $widget = '';
        $autofocus = ($type == 'ajax') ? 'autofocus' : '';

        $delim = '';
        $value = $content->getAaValue($aa_property->getId());

        $attrs = array('type' => 'text');
        $attrs = array_merge($attrs, $aa_property->getValidator()->getHtmlInputAttr());

        $attrs['size'] = get_if($this->getProperty('width'), $attrs['size']);
        if (!$attrs['size']) {
            $attrs['style'] = 'width:95%';
        }

        $attrs['maxlength'] = get_if($this->getProperty('max_characters'), $attrs['maxlength'], 255);

        // type number do not support size parameter, so ve have to set max and min (make no sence to do it for numbers > 11 characters - use default)
        if (($attrs['type'] == 'number') AND !isset($attrs['max']) AND ($nchars = $attrs['maxlength'] ?: $attrs['size'] ?: 0) AND ($nchars < 11)) {
            $attrs['max'] = pow(10, $nchars) - 1;
            $attrs['min'] = $attrs['min'] ?: '0';
        }

        $attr_string = join(' ', array_map(function ($k, $v) {
            return "$k=\"$v\"";
        }, array_keys($attrs), $attrs));

        if ($value->isEmpty()) {
            $value->addValue('');   // display empty field at least
        }
        $value->fixTranslations($aa_property->getTranslations());  // modify for tranclations if needed
        foreach ($value as $i => $val) {
            $input_name = $base_name . "[$i]";
            $widget .= $delim . $this->_getOneInput($input_name, $i, $val, $attr_string, $autofocus, $base_id, $type, $widget_add, $required);
            $delim = "\n<br />";
            $required = ''; // only one is required
            $autofocus = '';
        }

        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }
}