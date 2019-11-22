<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:37
 */

namespace AA\Widget;
use AA\FormArray;

/** Hidden field widget */
class HidWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Hidden field');
    }   // widget name

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return array();
    }

    /** Get widget HTML for using in form
     * @param \AA_Property $aa_property  - the variable
     * @param \AA_Content  $content      - contain the value of property to display
     *                                   - never empty - it contain at least aa_owner for new objects
     * @param null $function
     * @return string - widget HTML for using in form
     * @throws \Exception
     */
    function getHtml($aa_property, $content, $function = null)
    {
        $property_id = $aa_property->getId();
        $input_name = FormArray::getName4Form($property_id, $content, $this->item_index) . "[0]";
        $input_id = FormArray::formName2Id($input_name);
        $input_value = myspecialchars($content->getValue($property_id));
        return "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\">";
    }
}