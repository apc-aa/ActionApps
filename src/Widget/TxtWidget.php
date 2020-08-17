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
    /** @var array  list of textareas for CodeMirror creation */
    protected $ids_4_editor = [];

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(['rows', 'max_characters', 'ajaxtype']);
    }

    /** libs to run after the page DOM is loaded */
    function requireLibs($aa_property, $winfo)
    {
        return ( count($aa_property->getTranslations())>1 ) ? ['aa-jslib@3'] : [];
    }

    /** script to run after the page DOM is loaded */
    function requireRun($aa_property, $winfo)
    {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_editor) {
            $ret .= "\n  document.querySelectorAll(\"". join(',',$this->ids_4_editor) .'").forEach( function(el) { CKEDITOR.replace( el );} );';

            // clean the list - all textareas are converted
            $this->ids_4_editor = [];
        }
        return $ret;
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
        $widget_add = ($type == 'live') ? "class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';  // : 'style="width:99%"' - duplicate with $attrs[style]
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0;">' : '';

        $widget = '';

        $delim = '';
        $value = $content->getAaValue($aa_property->getId());
        $def_rows = $this->getProperty('rows', 4);

        $attrs = [];
        if ($maxlength = (int)$this->getProperty('max_characters', 0)) {
            $attrs['maxlength'] = $maxlength;
        }
        $attrs['style'] = 'width:95%';

        $required = $aa_property->isRequired() ? 'required' : '';

        $widget .= ($type == 'normal') ? AA_Formatters::getFormattersRadio($base_name, $aa_property, $value) : AA_Formatters::getHiddenFormatters($base_name, $aa_property, $value); // ajax, live

        if ($value->isEmpty()) {
            $value->addValue('');   // display empty textarea at least
        }
        $value->fixTranslations($aa_property->getTranslations());
        $base = [];
        foreach ($value as $i => $val) {
            $attrs['rows'] = (($my_rows = substr_count($val, "\n")) > $def_rows) ? min($my_rows + 2, 30) : $def_rows;
            // $rows         = max( $def_rows, min($my_rows>$def_rowssubstr_count($val,"\n")+4, 30));

            $input_name = $base_name . "[$i]";
            if ( !$base ) {
                $base['id']       = FormArray::formName2Id($input_name);
                $base['langcode'] =  AA_Content::getLangName2Code( AA_Content::getLangNum2Name($i));
            }
            $widget .= $delim. $this->getOneInput($input_name, $i, $val, $attrs, $widget_add, $widget_add2, $required, !$aa_property->getContentTypeSwitches() OR ($type == 'live'), $base);
            $delim = "\n<br />";
            $required = ''; // only one is required
        }

        // if CKEditor called outside AA - in sitemodule, we have to tell, which slice to use for image uploads
        $_SESSION['r_last_module_id']=$content->getOwnerID();

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @param $input_name
     * @param $i
     * @param $val
     * @param $attrs
     * @param $widget_add
     * @param $widget_add2
     * @param $required
     * @param $no_htmleditor
     * @param $base
     * @return string
     */
    protected function getOneInput($input_name, $i, $val, $attrs, $widget_add, $widget_add2, $required, $no_htmleditor, $base)
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

        $areaclass = '';
        $htmlareaedit = '';

        if ($showhtmlarea) {
            // arr this textarea to the list for CKEditor creation
            $this->ids_4_editor[] = '#'.$input_id;
            // $attrs['class'] = 'ckeditor';
        } elseif (!$no_htmleditor) {
            $htmlareaedit = " &nbsp; <a href=\"javascript:;\" class=\"aa-arealink\" style=\"display:none\" onclick=\"this.parentNode.parentNode.querySelector('input[value=\'1\']').checked=true;this.parentNode.parentNode.querySelector('.aa-formatters').style.display='none'; this.style.display='none';CKEDITOR.replace('$input_id');\">" . _m("Edit in HTMLArea") . "</a>"; // used for HTMLArea
            // conversions menu
            //$convertor   = $convert ? $this->get_convertors() : false;
            //if ( $convertor ) {
            //    $this->echoo('  <table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="' . COLOR_TABBG . "\">\n   <tr><td>");
            //}
        }

        $attr_string = join(' ', array_map(function ($k, $v) {
            return "$k=\"$v\"";
        }, array_keys($attrs), $attrs));

        $ret = "$htmlareaedit<textarea id=\"$input_id\" name=\"$input_name\" $attr_string $required $widget_add $areaclass>$input_value</textarea>$widget_add2";
        if ($lang = AA_Content::getLangNum2Name($i)) {
            $langcode  =  AA_Content::getLangName2Code( $lang );
            $translate =  ($langcode == $base['langcode']) ? '' :  "<span class=\"aa-langtrans-tbtn\" onclick=\"AA_Translate('$input_id', document.getElementById('". $base['id'] ."').value, '".$base['langcode']."', '$langcode');\">". _m("translate from"). ' '.AA_Content::getLangCode2Name($base['langcode']). "</span>";
            $ret = "<span class=\"aa-langtrans $lang\"><strong class=\"aa-langtrans-label\">$lang</strong>$translate $ret</span>";
        }
        return $ret;
    }

    /** @inheritDoc */
    public function description(): string {
        // TODO: Implement description() method.
    }
}