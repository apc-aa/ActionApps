<?php

/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:28
 */

namespace AA\Widget;

/** Rich Edit Text Area widget */
class EdtWidget extends TxtWidget
{
    protected $cols;
    protected $area_type;

    /** name function  */
    public static function name(): string
    {
        return _m('Rich Edit Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array
    {
        return Widget::propertiesShop(['rows', 'cols', 'area_type']);
    }

    protected function requireLibs($aa_property, $winfo): array
    {
        return array_merge(['aa-richtext@1'], parent::requireLibs($aa_property, $winfo));
    }
}
