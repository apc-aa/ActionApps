<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:37
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Content;
use AA_Property;

/** Hidden field widget */
class HidWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Hidden field');
    }   // widget name

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return [];
    }

    /** Decorate widget in the standard AA form - start
     * @param AA_Property $aa_property
     * @return string
     */
    function stdFormStart($aa_property) {
        $ret  = "\n<tr class=\"formrow{formpart} fieldstart cont-".varname4form($aa_property->getId())."\" style=\"visibility:hidden; position:absolute\">";
        $ret .= "\n <td class=tabtxt><b>".$aa_property->getName()."</b>";
        if ( $aa_property->isRequired() ) {
            $ret .= "&nbsp;*";
        }
        $ret .= "</td>\n <td>";
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     * @param AA_Property $aa_property
     * @param AA_Content  $content
     * @param string      $type normal|live|ajax
     * @return array
     * @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type='normal') {
        $property_id = $aa_property->getId();
        $base_name   = FormArray::getName4Form($property_id, $content, $this->item_index);
        $base_id     = FormArray::formName2Id($base_name);
        $input_name  = $base_name . "[0]";
        $input_id    = FormArray::formName2Id($input_name);
        $input_value = myspecialchars($content->getValue($property_id));
        $widget      = "\n<input type=hidden name=\"$input_name\" id=\"$input_id\" value=\"$input_value\">";
        
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @param array $winfo
     * @param AA_Property $aa_property
     * @param bool $widget_only
     * @return string
     */
    function _finalizeHtml($winfo, $aa_property, $widget_only = false)
    {
        return $winfo['html'];
    }

    /** Creates all common ajax editing buttons to be used by different inputs
     * @param $winfo array
     * @param $aa_property AA_Property
     * @return string
     */
    function _finalizeAjaxHtml($winfo, $aa_property)
    {
        return $winfo['html'];
    }

    /* Decorates Live Widget. Prepared for overriding in subclasses */
    function _finalizeLiveHtml($winfo, $aa_property)
    {
        return $winfo['html'];
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}