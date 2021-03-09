<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:34
 */

namespace AA\Widget;

/** Related Item Window widget */
class IsoWidget extends MchWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Related Item Window');   // widget name
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
        $ret = Widget::propertiesShop(['const', 'row_count', 'show_actions', 'admin_design', 'tag_prefix', 'show_buttons', 'bin_filter', 'filter_conds', 'filter_conds_rw', 'slice_field', 'sort_by', 'additional_slice_pwd', 'const_arr']);
        $ret['filter_conds_rw']->setHelp(_m("Conditions for filtering items in related items window. This conds user can change."));
        return $ret;
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}