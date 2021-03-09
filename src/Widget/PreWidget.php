<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:30
 */

namespace AA\Widget;

/** Text Field with Presets widget */
class PreWidget extends FldWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Text Field with Presets');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     * Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'max_characters', 'width', 'slice_field', 'use_name', 'adding', 'second_field', 'add2constant', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr']);
    }

    /** @inheritDoc */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}