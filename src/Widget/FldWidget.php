<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:29
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Content;
use AA_Langs;


/** Text Field widget */
class FldWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Text Field');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['max_characters', 'width']);
    }

    /** libs to run after the page DOM is loaded */
    function requireLibs($aa_property, $winfo)
    {
        return ( count($aa_property->getTranslations())>1 ) ? ['aa-jslib@3'] : [];
    }


    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param AA_Content $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $required = $aa_property->isRequired() ? 'required' : '';
        $widget_add = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-right:16px;\"" : '';
        //$widget_adds = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-left:16px;\"" : '';
        $widget = '';
        $autofocus = ($type == 'ajax') ? 'autofocus' : '';

        $delim = '';
        $value = $content->getAaValue($aa_property->getId());

        $translations = $aa_property->getTranslations();

        $attrs = ['type' => 'text'];
        $attrs = array_merge($attrs, $aa_property->getValidator()->getHtmlInputAttr());

        $attrs['size'] = get_if($this->getProperty('width'), $attrs['size']);
        if (!$attrs['size']) {
            unset($attrs['size']);
            $attrs['style'] = (count($translations)>1) ? 'width:85%' : 'width:95%'; // for translations we need more space for buttons
        }

        $attrs['maxlength'] = get_if($this->getProperty('max_characters'), $attrs['maxlength'], 255);

        // type number do not support size parameter, so ve have to set max and min (make no sence to do it for numbers > 11 characters - use default)
        if (($attrs['type'] == 'number') AND !isset($attrs['max']) AND ($nchars = $attrs['maxlength'] ?: $attrs['size'] ?: 0) AND ($nchars < 11)) {
            $attrs['max'] = pow(10, $nchars) - 1;
            $attrs['min'] = $attrs['min'] ?: '0';
        }

        $attr_string = join(' ', array_map(function ($k, $v) {
            return "$k=\"$v\"";
        }, array_keys($attrs), $attrs));

        if ($value->isEmpty()) {
            $value->addValue('');   // display empty field at least
        }
        $value->fixTranslations($translations);  // modify for tranclations if needed

        $base = [];
        foreach ($value as $i => $val) {
            $input_name = $base_name . "[$i]";
            if ( !$base ) {
                $base['id']       = FormArray::formName2Id($input_name);
                $base['langcode'] = AA_Langs::getLangName2Code(AA_Langs::getLangNum2Name($i));
            }
            $widget .= $delim . $this->getOneInput($input_name, $i, $val, $attr_string, $autofocus, $base_id, $type, $widget_add, $required, $base);
            $delim = "\n<br />";
            $required = ''; // only one is required
            $autofocus = '';
        }

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @param $input_name
     * @param $i
     * @param $val
     * @param $attr_string
     * @param $autofocus
     * @param $base_id
     * @param $type
     * @param $widget_add
     * @param $required
     * @param $base
     * @return string
     */
    protected function getOneInput($input_name, $i, $val, $attr_string, $autofocus, $base_id, $type, $widget_add, $required, $base)
    {
        $input_id = FormArray::formName2Id($input_name);
        $input_value = myspecialchars($val);
        $link = ((substr($input_value, 0, 7) === 'http://') OR (substr($input_value, 0, 8) === 'https://')) ? '&nbsp;' . a_href($input_value, GetAAImage('external-link.png', _m('Show'), 16, 16)) : '';
        $ret = "<input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required $widget_add $autofocus>$link" . Widget::_saveIcon($base_id, $type == 'live'); //, 'right', $link ? 16 : 0);
        if ($lang = AA_Langs::getLangNum2Name($i)) {
            $langcode  = AA_Langs::getLangName2Code($lang);
            $translate =  ($langcode == $base['langcode']) ? '' :  "<span class=\"aa-langtrans-tbtn\" onclick=\"AA_Translate('$input_id', document.getElementById('". $base['id'] ."').value, '".$base['langcode']."', '$langcode');\">". _m("translate from"). ' '. AA_Langs::getLangCode2Name($base['langcode']) . "</span>";
            $ret = "<span class=\"aa-langtrans $lang\"><strong class=\"aa-langtrans-label\">$lang</strong>$ret $translate</span>";
        }
        return $ret;
    }

    /** @inheritDoc */
    public function description(): string {
return ''; // TODO: Implement description() method.
    }
}