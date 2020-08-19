<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:38
 */

namespace AA\Widget;

/** Local URL Picker widget */
class LupWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Local URL Picker');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(['url']);
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}