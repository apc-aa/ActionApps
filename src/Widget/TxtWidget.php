<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:17
 */

namespace AA\Widget;
use AA_Content;
use AA\FormArray;
use AA_Formatters;
use AA_Item;
use AA_Property;
use Exception;

/** Textarea widget */
class TxtWidget extends \AA\Widget\Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name()
    {
        return _m('Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('rows', 'max_characters', 'ajaxtype'));
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     * @param $aa_property AA_Property
     * @param $content AA_Item
     * @param string $type
     * @return array
     * @throws Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $widget_add = ($type == 'live') ? "class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : 'style="width:99%"';
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0;">' : '';

        $widget = '';

        $delim = '';
        $value = $content->getAaValue($aa_property->getId());
        $def_rows = $this->getProperty('rows', 4);

        $max_characters = (int)$this->getProperty('max_characters', 0);
        $required = $aa_property->isRequired() ? 'required' : '';

        $widget .= ($type == 'normal') ? AA_Formatters::getFormattersRadio($base_name, $aa_property, $value) : AA_Formatters::getHiddenFormatters($base_name, $aa_property, $value); // ajax, live

        if ($value->isEmpty()) {
            $value->addValue('');   // display empty textarea at least
        }
        $value->fixTranslations($aa_property->getTranslations());
        foreach ($value as $i => $val) {
            if (($my_rows = substr_count($val, "\n")) > $def_rows) {
                $rows = min($my_rows + 2, 30);
            } else {
                $rows = $def_rows;
            }
            // $rows         = max( $def_rows, min($my_rows>$def_rowssubstr_count($val,"\n")+4, 30));

            $input_name = $base_name . "[$i]";
            $widget .= $delim . $this->_getOneInput($input_name, $i, $val, $rows, $max_characters, $widget_add, $widget_add2, $required, !$aa_property->getContentTypeSwitches());
            $delim = "\n<br />";
            $required = ''; // only one is required
        }

        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     */
    protected function _getOneInput($input_name, $i, $val, $rows, $max_characters, $widget_add, $widget_add2, $required, $no_htmleditor)
    {

        $showhtmlarea = strpos(get_class($this), 'AA\Widget\EdtWidget') !== false;
        //if ( $showhtmlarea ) {
        // $this->html_radio($showhtmlarea ? false : 'convertors');

        // fix for IE - where the textarea icons are too big so there is
        // no space for the text
        // if ($showhtmlarea) {
        //    $rows += 8;
        //}
        //}

        $input_id = FormArray::formName2Id($input_name);
        $input_value = myspecialchars($val);
        $maxlength = $max_characters ? " maxlength=\"$max_characters\"" : '';

        $areaclass = '';
        $htmlareaedit = '';

        if ($showhtmlarea) {
            $areaclass = ' class=ckeditor';
        } elseif (!$no_htmleditor) {
            $htmlareaedit = " &nbsp; <a href=\"javascript:;\" class=\"aa-arealink\" style=\"display:none\" onclick=\"this.parentNode.parentNode.querySelector('input[value=\'1\']').checked=true;this.parentNode.parentNode.querySelector('.aa-formatters').style.display='none'; this.style.display='none';CKEDITOR.replace('$input_id');\">" . _m("Edit in HTMLArea") . "</a>"; // used for HTMLArea
            // conversions menu
            //$convertor   = $convert ? $this->get_convertors() : false;
            //if ( $convertor ) {
            //    $this->echoo('  <table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="' . COLOR_TABBG . "\">\n   <tr><td>");
            //}
        }

        $ret = "$htmlareaedit<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$rows\"$maxlength $required $widget_add $areaclass>$input_value</textarea>$widget_add2";
        if ($lang = AA_Content::getLangId($i)) {
            $ret = "<span class=\"aa-langtrans $lang\"><strong>$lang</strong>$ret</span>";
        }
        return $ret;
    }
}