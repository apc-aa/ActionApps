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
class InfWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Info text - output');
    }   // widget name

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['code']);
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
        $property_id = $aa_property->getId();
        $item_id     = $content->getId();
        $code = $this->getProperty('code', '{' . $property_id . '}');
        if ($item_id) {
            $widget = \AA_Items::getItem(new \zids($item_id))->unalias($code);
        } else {
            $widget = $content->unalias($code);
        }
        $base_name = FormArray::getName4Form($property_id, $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $input_name = $base_name . "[0]";
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}