<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:32
 */

namespace AA\Widget;

/** Multiple Selectbox widget */
class MseWidget extends MchWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Multiple Selectbox');   // widget name
    }

    /** @inheritDoc */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }

    /** returns if the widget handles multivalue or just single value   */
    public static function multiple(): bool {
        return true;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'row_count', 'slice_field', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr']);
    }
}