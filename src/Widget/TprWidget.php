<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:27
 */

namespace AA\Widget;

/** Textarea with Presets widget */
class TprWidget extends \AA\Widget\TxtWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Textarea with Presets');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     * Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(['rows', 'cols', 'const', 'const_arr']);
    }
}