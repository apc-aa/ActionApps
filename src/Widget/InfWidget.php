<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:38
 */

namespace AA\Widget;
use AA\FormArray;

/** Info text - just Output */
class InfWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Info text - output');
    }   // widget name

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('code'));
    }

    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $property_id = $aa_property->getId();
        $code = $this->getProperty('code', '{' . $property_id . '}');
        $widget = myspecialchars($content->unalias($code));
        $base_name = FormArray::getName4Form($property_id, $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $input_name = $base_name . "[0]";
        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }
}