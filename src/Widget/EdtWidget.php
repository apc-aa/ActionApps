<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:28
 */

namespace AA\Widget;

/** Rich Edit Text Area widget */
class EdtWidget extends \AA\Widget\TxtWidget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Rich Edit Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('rows', 'cols', 'area_type'));
    }

    function requireLibs($aa_property, $winfo)
    {
        return array('ckeditor');
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     * @param $aa_property AA_Property
     * @param $content AA_Item
     * @param string $type
     * @return array
     * @throws Exception
     */
//    function _getRawHtml($aa_property, $content, $type = 'normal') {
//        huhl(__FUNCTION__);
//        parent::_getRawHtml($aa_property, $content, $type);
//    }

}