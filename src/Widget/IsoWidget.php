<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:34
 */

namespace AA\Widget;

use AA_Validate;

/** Related Item Window widget */
class IsoWidget extends MchWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Related Item Window');   // widget name
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
        $ret = Widget::propertiesShop(['const', 'row_count', 'show_actions', 'admin_design', 'tag_prefix', 'show_buttons', 'bin_filter', 'filter_conds', 'filter_conds_rw', 'slice_field', 'sort_by', 'additional_slice_pwd', 'const_arr', 'listlen']);
        $ret['filter_conds_rw']->setHelp(_m("Conditions for filtering items in related items window. This conds user can change."));
        return $ret;
    }

    /**
     * @inheritDoc
     */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }

    /** In most cases returns the Validator from the field definition string. In some cases (like Tag or ISO) it returns upgraded validator (should allow) more than IDs in both cases
     * @param string $validator_string
     * @return AA_Validate|null
     */
    public function getUpgradedValidator(string $validator_string): ?AA_Validate {
        if ( (substr($validator_string,0,2)=='id') ) {
            $validator_string = 'markedid';  // we should allow marked ids
        }
        return AA_Validate::factoryByString($validator_string);
    }
}