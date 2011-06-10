<?php
/**
 * Form displayed in popup window allowing search and replace item content
 * for specified items
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH. "formutil.php3";
require_once AA_INC_PATH. "searchbar.class.php3";
require_once AA_INC_PATH. "varset.php3";

define('SEARCH_REPLACE_PREFIX', 'transform');

/** AA_Transformation - parent class for all Transformations, which is used for
 *  changing Item values - typically when we import item. The Input item has
 *  some fields, the return item should has some fields, so we call
 *  Transformations for all fields of destination item and construct it this way
 *
 *  Main method is transform, which do the all work.
 *
 *  @todo this class should be abstract after we switch to PHP5
 */
class AA_Transformation {
    var $messages   = array();
    var $parameters = array();

    /** name function
     *
     */
    function name()         {}

    /** description function
     *
     */
    function description()  {}

    /** transform function
     *
     */
    function transform()    {}

    /** parameters function
     * @return array
     */
    function parameters()   { return array(); }

    /** _getVarname function
     *  Construct name of input form variable
     *  It consist of classname, so we are able to guess which variable
     *  is used for which class (child of AA_Transformation). It also contain
     *  $input_id, so we are able to have more than one instance of the class
     *  in one form
     * @param $name
     * @param $input_id
     * @param $classname
     * @return string
     */
    function _getVarname($name, $input_id, $classname) {
        return $input_id. substr($classname,18).$name;
    }

    /** getRequestVariables function
     * Grabs only variables, which is intended for class $classname
     * @param $input_id
     * @param $classname
     * @return string
     */
    function getRequestVariables($input_id, $classname) {
        $ret = array();
        if ( strtolower(substr($classname,0,18)) != 'aa_transformation_' ) {  // to lower - php4 fix
            return $ret;
        }
        $prefix = $input_id. substr($classname,18);
        foreach ($_REQUEST as $varname => $varvalue) {
            if (strpos($varname, $prefix) === 0) {
                // filter out the prefix
                $ret[substr($varname,strlen($prefix))] = magic_strip($varvalue);
            }
        }
        return $ret;
    }

    /** message function
     * Records Error/Information messaage
     * @param $text
     */
    function message($text) {
        $this->messages[] = $text;
    }

    /** report function
     * Print Error/Information messaages
     */
    function report()       {
        return join('<br>', $this->messages);
    }
    /** clear_report function
     *
     */
    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }
    /** getParam function
     * @param $param_name
     * @return $this->$param_name
     */
    function getParam($param_name) {
//        return $this->parameters[$param_name]->getValue();
        return $this->$param_name;
    }

    /** Reads new flag value from user's HTML form
     *  Common function for many transformations
     **/
    function getFlagFromForm($old_flag) {
        switch ($this->getParam('new_flag')) {
                case 'u': return $old_flag;
                case 'h': return $old_flag | FLAG_HTML;
                case 't': return $old_flag & ~FLAG_HTML;
        }
        return 0;  // should never happen
    }


    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        foreach (self::parameters() as $parameter_id => $setting) {
            $varname = AA_Transformation::_getVarname($parameter_id, $input_prefix, __CLASS__);
            $aainput = new AA_Inputfield(magic_strip($_GET[$varname]));
            $aainput->setFromField($setting);
            echo $aainput->get();
        }

        FrmTabEnd();
        return ob_get_clean();
    }
}

/** The result is single-value (not multivalue), which is created as result of
 *  normal AA expression using source item. You can use
 *  {switch({category.......1})Bio:...} and such expressions as well as normal
 *  text.
 */
/*  New approach - not fully functional, yet
class AA_Transformation_Value extends AA_Transformation {

    function AA_Transformation_Value($param) {
        $this->parameters    = array();
        $this->parameters['new_flag']    = getAAField(array('varname'=>'new_flag',    'name'=>_m('Mark as'),     'required'=>true, 'value' => array( 0 => array( 'value' => $param['new_flag']))));
        $this->parameters['new_content'] = getAAField(array('varname'=>'new_content', 'name'=>_m('New content'), 'value' => array( 0 => array( 'value' => $param['new_content'])), 'input_help' => _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK')));
    }

    function name() {
        return _m("Fill by value");
    }

    function description() {
        return _m("Returns single value (not multivalue) which is created as result of AA expression specified in Expression. You can use any AA expressions like {switch()...}, ...");
    }

    function parameters() {
        return array ('new_flag' =>
                          array ( 'id'              => 'new_flag',
                                  'name'            => _m('Mark as'),
                                  'required'        => true,
                                  'input_help'      => '',
                                  'input_morehlp'   => '',
                                  'input_before'    => '',
                                  'input_show_func' => '',
                                  'html_show'       => '',
                                  'const_arr'       => array('h' => _m('HTML'), 't' => _m('Plain text'), 'u' => _m('Unchanged'))
                                ),
                      'new_content' =>
                          array ( 'id'       => 'new_content',
                                  'name'     => _m('New content'),
                                  'required' => false,
                                  'input_help' => _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK'),
                                  'input_morehlp' => '',
                                  'input_before' => '',
                                  'input_show_func' => '',
                                  'html_show' => ''
                                )
                     );
    }

    function transform($field_id, &$content4id) {
        $slice = AA_Slices::getSlice($content4id->getSliceID());
        $item = new AA_Item($content4id->getContent(),$slice->aliases());

        $text = $item->subst_alias($this->getParam('new_content'));

        switch ($this->getParam('new_flag')) {
            case 'u': $flag = $item->getFlag($field_id); break;
            case 'h': $flag = $item->getFlag($field_id) | FLAG_HTML; break;
            case 't': $flag = $item->getFlag($field_id) & ~FLAG_HTML; break;
        }

        return new AA_Value($text,$flag);
    }
}
*/
class AA_Transformation_Value extends AA_Transformation {

    var $new_flag;
    var $new_content;

    /** AA_Transformation_Value function
     * @param $param
     */
    function AA_Transformation_Value($param) {
        $this->new_flag    = $param['new_flag'];
        $this->new_content = $param['new_content'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Fill by value");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m("Returns single value (not multivalue) which is created as result of AA expression specified in Expression. You can use any AA expressions like {switch()...}, ...");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     * @return array
     */
    function transform($field_id, &$content4id) {
        $item = GetItemFromContent($content4id);
        $text = $item->subst_alias($this->getParam('new_content'));
        $flag = $this->getFlagFromForm($item->getFlag($field_id));
        return new AA_Value($text,$flag);
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        $flag_options = array('h' => _m('HTML'),
                              't' => _m('Plain text'),
                              'u' => _m('As for other values of this field'));
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_new_flag    = AA_Transformation::_getVarname('new_flag', $input_prefix, __CLASS__);
        $varname_new_content = AA_Transformation::_getVarname('new_content', $input_prefix, __CLASS__);

        FrmInputRadio($varname_new_flag, _m('Mark as'), $flag_options, get_if($_GET[$varname_new_flag],'u'));
        FrmTextarea(  $varname_new_content, _m('New content'),       $_GET[$varname_new_content],  12, 80, true,
               _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK'));

        FrmTabEnd();
        return ob_get_clean();
    }
}

class AA_Transformation_Setflag extends AA_Transformation {

    var $new_flag;

    /** AA_Transformation_Value function
     * @param $param
     */
    function AA_Transformation_Setflag($param) {
        $this->new_flag    = $param['new_flag'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Set HTML/Plain-text flag");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m("Changes the HTML or Plain-text flag for the field.");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     * @return array
     */
    function transform($field_id, &$content4id) {
        $item = GetItemFromContent($content4id);
        $flag = $this->getFlagFromForm($item->getFlag($field_id));
        $ret  = $content4id->getAaValue($field_id);
        $ret->setFlag($flag);
        return $ret;
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        $flag_options = array('h' => _m('HTML'),
                              't' => _m('Plain text'));
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_new_flag    = AA_Transformation::_getVarname('new_flag', $input_prefix, __CLASS__);
        FrmInputRadio($varname_new_flag, _m('Mark as'), $flag_options, get_if($_GET[$varname_new_flag],'h'));

        FrmTabEnd();
        return ob_get_clean();
    }
}

/** The result is single-value (not multivalue), which is created as result of
 *  normal AA expression using source item. You can use
 *  {switch({category.......1})Bio:...} and such expressions as well as normal
 *  text.
 */
class AA_Transformation_AddValue extends AA_Transformation {

    var $new_flag;
    var $new_content;

    /** AA_Transformation_AddValue function
     * @param $param
     */
    function AA_Transformation_AddValue($param) {
        $this->new_flag    = $param['new_flag'];
        $this->new_content = $param['new_content'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Add value to field");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m("Add new value to current content of field, so the field becames multivalue.<br>You can use any AA expressions like {switch()...}, ... for new value.");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     */
    function transform($field_id, &$content4id) {
        $item = GetItemFromContent($content4id);
        $flag = $this->getFlagFromForm($item->getFlag($field_id));

        $ret  = $content4id->getAaValue($field_id);
        $ret->setFlag($flag);
        $ret->addValue($item->subst_alias($this->new_content));

        return $ret;
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        $flag_options = array('h' => _m('HTML'),
                              't' => _m('Plain text'),
                              'u' => _m('As for other values of this field'));
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_new_flag    = AA_Transformation::_getVarname('new_flag', $input_prefix, __CLASS__);
        $varname_new_content = AA_Transformation::_getVarname('new_content', $input_prefix, __CLASS__);

        FrmInputRadio($varname_new_flag, _m('Mark as'), $flag_options, get_if($_GET[$varname_new_flag],'u'));
        FrmTextarea(  $varname_new_content, _m('New content'),       $_GET[$varname_new_content],  12, 80, true,
               _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK'));

        FrmTabEnd();
        return ob_get_clean();
    }
}

/** Parses the input text and looks for the delimiter. Separates the parts
 *  and store them as multiple values to destination field
 */
class AA_Transformation_ParseMulti extends AA_Transformation {

    var $new_flag;
    var $source;
    var $delimiter;

    /** AA_Transformation_AddValue function
     * @param $param
     */
    function AA_Transformation_ParseMulti($param) {
        $this->new_flag       = $param['new_flag'];
        $this->source         = $param['source'];
        $this->delimiter      = $param['delimiter'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Divide the text to multiple values");
    }

    /** description function
     * @return message
     */

    function description() {
        return _m("Parses the input text and looks for the delimiter. Separates the parts and store them as multiple values to destination field");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     */
    function transform($field_id, &$content4id) {
        $item = GetItemFromContent($content4id);
        $flag = $this->getFlagFromForm($item->getFlag($field_id));
        return new AA_Value(explode($this->delimiter, $item->subst_alias($this->source)), $flag);
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        $flag_options = array('h' => _m('HTML'),
                              't' => _m('Plain text'),
                              'u' => _m('As for other values of this field'));
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_new_flag  = AA_Transformation::_getVarname('new_flag',  $input_prefix, __CLASS__);
        $varname_source    = AA_Transformation::_getVarname('source',    $input_prefix, __CLASS__);
        $varname_delimiter = AA_Transformation::_getVarname('delimiter', $input_prefix, __CLASS__);

        FrmInputRadio($varname_new_flag, _m('Mark as'), $flag_options, get_if($_GET[$varname_new_flag],'u'));
        FrmTextarea(  $varname_source, _m('Source text'),  $_GET[$varname_source],  12, 80, true,
               _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK'));
        FrmInputText( $varname_delimiter, _m('Delimiter'), $_GET[$varname_delimiter], 255, 25, true);

        FrmTabEnd();
        return ob_get_clean();
    }
}


/** Helper class to handle sting replacements for AA_Transformation_Translate
 *  class
 */
class AA_Strreplace {
    var $method;
    var $pattern;
    var $replacements; // array

    /** AA_Strreplace function
     * @param $method
     * @param $pattern
     * @param $replacements
     */
    function AA_Strreplace( $method, $pattern, $replacements ) {
        $this->method       = $method;
        $this->pattern      = $pattern;
        $this->replacements = $replacements;
    }

    /** matches function
     * @param $text
     * @return string/false
     */
    function matches($text) {
        switch ($this->method) {
            case 'regexp':  return (preg_match($this->pattern, $text) > 0);
            case 'replace': return ($text == $this->pattern);
        }
        return false;
    }

    /** replace function
     * @param $value
     * @param $flag
     * @param $item (by link)
     * @return array
     */
    function replace($value, &$item) {
        $ret = array();
        foreach ( $this->replacements as $replacement) {
            $replacement = str_replace('_#0', $value, $replacement);
            $text = $item->subst_alias($replacement);
            if ( $text != 'AA_NULL' ) {
                $ret[] = $text;
            }
        }
        return $ret;
    }
}

class AA_Transformation_Translate extends AA_Transformation {
    var $new_flag;
    var $translation;

    /** AA_Transformation_Translate function
     * @param $param
     */
    function AA_Transformation_Translate($param) {
        $this->new_flag    = $param['new_flag'];
        $this->translation = $param['translation'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Translate");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m("Translates one value after other according to translation table. The result is multivalue, since each value of multivalue field is translated seperately.");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     * @return array/false
     */
    function transform($field_id, &$content4id) {
        if (!$this->translation) {
            $this->message(_m('No translations specified.'));
            return false;
        }

        $item = GetItemFromContent($content4id);
        $flag = $this->getFlagFromForm($item->getFlag($field_id));

        $translations = $this->_parseTranslation();
        $ret = new AA_Value;
        $ret->setFlag($flag);
        foreach ( $content4id->getValues($field_id) as $source ) {
            // if not found any match, use the old value
            $new_value = array($source['value']);
            foreach ($translations as $strreplace) {
                if ( $strreplace->matches($source['value']) ) {
                    // matches - add all translations to the resulting array
                    // stop searching - go to next value
                    $new_value = $strreplace->replace($source['value'], $item);
                    break;
                }
            }
            $ret->addValue($new_value);
        }
        return $ret;
    }

    /** _parseTranslation function
     * @return $translations array
     */
    function & _parseTranslation() {
        $translations = array();
        foreach (explode("\n",$this->translation) as $row) {
            // explode do not eat possible \r at the end - we trim it
            $row = rtrim($row, "\r");
            if (strpos($row, ':regexp:') === 0) {
                // regular expressions
                $parts = ParamExplode(substr($row,8));
                $regexp = array_shift($parts);
                $translations[] = new AA_Strreplace('regexp', $regexp, $parts);
            } else {
                $parts = ParamExplode($row);
                $replace = array_shift($parts);
                $translations[] = new AA_Strreplace('replace', $replace, $parts);
            }
        }
        return $translations;
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        $flag_options = array('h' => _m('HTML'),
                              't' => _m('Plain text'),
                              'u' => _m('Unchanged'));
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_new_flag    = AA_Transformation::_getVarname('new_flag', $input_prefix, __CLASS__);
        $varname_translation = AA_Transformation::_getVarname('translation', $input_prefix, __CLASS__);

        FrmInputRadio($varname_new_flag, _m('Mark as'), $flag_options, get_if($_GET[$varname_new_flag],'u'));
        FrmTextarea(  $varname_translation, _m('Translations'),       $_GET[$varname_translation],  12, 80, true,
        _m('Each translation on new line, translations separated by colon : (escape character for colon is #:).<br>You can use also aliases in the translation. There is also special alias _#0, which contain matching text - following translation is perfectly OK:<br><code> Bio:&lt;img src="_#0.jpg"&gt; ({publish_date....})</code><br>You can also use Regular Expressions - in such case the line would be "<code>:regexp:<regular expression>:<output></code>". You can use _#0 alias in <output>, which contains whole matching text.<br>Sometimes you want to remove specific value. In such case use <code>AA_NULL</code> text as translated text:<br> <code>Bio:AA_NULL</code><br>You may want also create more than one value from a value. Then separate the values by colon:<br> <code>Bio:Environment:Ecology</code> ("Bio" is replaced by two values). You can use any number of values here.'));

        FrmTabEnd();
        return ob_get_clean();
    }
}

class AA_Transformation_Replace extends AA_Transformation {
    var $searchpattern;
    var $replacestring;

    /** AA_Transformation_Translate function
     * @param $param
     */
    function AA_Transformation_Replace($param) {
        $this->searchpattern = $param['searchpattern'];
        $this->replacestring = $param['replacestring'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Search and Replace");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m("Search the content of text and replaces it with the another text. No other transformations/unaliasing are applied - just basic search and replace.");
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     * @return array/false
     */
    function transform($field_id, &$content4id) {
        if (!$this->searchpattern) {
            $this->message(_m('No searchstring specified.'));
            return false;
        }

        $ret = new AA_Value;
        foreach ( $content4id->getValues($field_id) as $source ) {
            $ret->addValue(str_replace($this->searchpattern, $this->replacestring, $source['value']));
            $ret->setFlag($source['flag']);
        }
        return $ret;
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname_searchpattern = AA_Transformation::_getVarname('searchpattern', $input_prefix, __CLASS__);
        $varname_replacestring = AA_Transformation::_getVarname('replacestring', $input_prefix, __CLASS__);

        FrmTextarea(  $varname_searchpattern, _m('Search'),  $_GET[$varname_searchpattern],  4, 80, true);
        FrmTextarea(  $varname_replacestring, _m('Replace'), $_GET[$varname_replacestring],  4, 80, true);
        FrmTabEnd();
        return ob_get_clean();
    }
}

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class AA_Transformation_CopyField extends AA_Transformation {

    var $field2copy;

    /** AA_Transformation_CopyField function
     * @param $param
     */
    function AA_Transformation_CopyField($param) {
        $this->field2copy = $param['field2copy'];
    }

    /** name function
     * @return message
     */
    function name() {
        return _m("Copy field");
    }

    /** description function
     * @return message
     */
    function description() {
        return _m('Selected field will be copied to the "Field" (including multivalues)');
    }

    /** transform function
     * @param $field_id
     * @param $content4id (by link)
     */
    function transform($field_id, &$content4id) {
        if (($this->field2copy == 'no_field') OR !$field_id OR ($field_id == 'no_field')) {
            $this->message(_m('Source or destination field is not specified.'));
            return false;
        }
        // get content from $field2copy field of current item
        return $content4id->getAaValue($this->field2copy);
    }

    /** htmlSetting function
     * @param $input_prefix
     * @param $params
     */
    function htmlSetting($input_prefix, $params) {
        ob_start();
        FrmTabCaption();
        FrmStaticText('', self::description());

        $varname = AA_Transformation::_getVarname('field2copy', $input_prefix, __CLASS__);
        FrmInputSelect($varname, _m('Copy field'), $params['field_copy_arr'], $_GET[$varname], true,
                       _m('Selected field will be copied to the "Field" (including multivalues)'));

        FrmTabEnd();
        return ob_get_clean();
    }
}

if ( !IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items this way in this slice"));
    exit;
}

$searchbar = new AA_Searchbar();   // mainly for bookmarks
$items=$chb;

if ( !$fill ) {               // for the first time - directly from item manager
    $sess->register('r_sr_state');
    unset($r_sr_state);       // clear if it was filled
    $r_sr_state['items'] = $items;
    // init variable settings goes here
} else {
    $items     = $r_sr_state['items'];  // session variable holds selected items fmom item manager
    if ( $fill ) {    // we really want to fill fields
        $zids = new zids;
        if ($_GET[SEARCH_REPLACE_PREFIX] AND (strpos(strtolower($_GET[SEARCH_REPLACE_PREFIX]), 'aa_transformation_')===0)) {   // strtolower is fix for php4, where case of any class is small
            $transformation = AA_Components::factory($_GET[SEARCH_REPLACE_PREFIX],AA_Transformation::getRequestVariables(SEARCH_REPLACE_PREFIX, $_GET[SEARCH_REPLACE_PREFIX]));
            $zids           = ( ($group == 'testitemgroup') ? new zids($testitem) : getZidsFromGroupSelect($group, $items, $searchbar) );

            $updated_items  = 0;  // number of updated items

            for ( $i=0, $ino=$zids->count(); $i<$ino; ++$i) {

                $content4id    = new ItemContent();
                $content4id->setByItemID($zids->zid($i), true);     // ignore password
                // if we do not ignore it, then it will not work for slices with slice_pwd
                // It is OK to do not care about password here - we are loged in and have permission PS_EDIT_ALL_ITEMS

                $sli_id  = $content4id->getSliceID();
                $item_id = $content4id->getItemID();
                if (!$sli_id OR !$item_id) {
                    // Probably: item not found, for some reason
                    continue;
                }

                // transform retuns AA_Value
                $field_content = $transformation->transform($field_id, $content4id);
                $field_content->removeDuplicates();

                $newcontent4id = new ItemContent();
                $newcontent4id->setAaValue($field_id, $field_content);
                $newcontent4id->setItemID($item_id);
                $newcontent4id->setSliceID($sli_id);

                if ($newcontent4id->storeItem( $silent ? 'update_silent' : 'update', array(false, false, false))) {    // not invalidatecache, not feed, no events
                    $updated_items++;
                }
                $slices2invalidate[$sli_id] = $sli_id;
            }
            if (is_array($slices2invalidate)) {
                foreach($slices2invalidate as $sli_id) {
                    $GLOBALS['pagecache']->invalidateFor("slice_id=$sli_id");
                }

                // we disabled events, so at the end we should update auth data
                // for Reader Management slice
                AuthMaintenance();
            }
        }

        $Msg     = MsgOK(_m("Items selected: %1, Items sucessfully updated: %2",
                                           array($zids->count(), $updated_items)));
        if ((string)$group == (string)"sel_item") {
            $sel = "LIST";
        } elseif ((string)$group == (string)"testitemgroup") {
            $sel = "TEST";
        } else {
            $sel = get_if($group,"0");  // bookmarks groups are identified by numbers
        }
        AA_Log::write("ITEM_FIELD_FILLED", array($zids->count(), $updated_items),$sel);
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <link rel=StyleSheet href="'.AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">
  <title>'.  _m("Modify items") .'</title>';
FrmJavascriptFile( 'javascript/inputform.js' );
IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Modify items") .'</h1>
  <form name="modifyitemsform">';

PrintArray($err);
echo $Msg;

$slice  = AA_Slices::getSlice($slice_id);

if ( !IsSuperadmin() ) {
    $restricted_fields = array( 'slice_id........' => true,
                                'id..............' => true,
                                'short_id........' => true );
} else {
    $restricted_fields = array( 'short_id........' => true );
}

// create field_select array for field selection
$field_select['no_field'] = _m('Select field...');
$fields                   = $slice->fields('record');
foreach ($fields as $fld_id => $fld) {
    if ( !$restricted_fields[$fld_id] ) {
        $field_select[$fld_id] = $fld['name']. ' ('. $fld_id. ')';
    }
}

// create field_copy_arr array for field selection
foreach ($fields as $fld_id => $fld) {
    $field_copy_arr[$fld_id] = $fld['name']. ' ('. $fld_id. ')';
}

$params = array('field_copy_arr' => $field_copy_arr,
                'field_select'   => $field_select);

FrmTabCaption( (is_array($items) ? _m("Items") : ( _m("Stored searches for ").$slice->name()) ));

$messages['view_items']     = _m("View items");
$messages['selected_items'] = _m('Selected items');
FrmItemGroupSelect( $items, $searchbar, 'items', $messages, $additional);

FrmTabSeparator( _m('Fill field') );
FrmInputSelect('field_id',       _m('Field'),             $field_select,       $field_id, true,
               _m('Be very carefull with this. Changes in some fields (Status Code, Publish Date, Slice ID, ...) could be very crucial for your item\'s data. There is no data validity check - what you will type will be written to the database.<br>You should also know there is no UNDO operation (at least now).'));

FrmInputChBox('silent', _m('Silent'), true, false, "", 1, false, _m('Just update the field and do not perform any other operations like set-last-edit, evaluate-computed-field, feed, ...'));

FrmStaticText(_m('Action'), AA_Components::getSelectionCode('AA_Transformation_', SEARCH_REPLACE_PREFIX, $params), true, "", "", false );

FrmTabEnd(array( 'fill' =>array('type'=>'submit', 'value'=>_m('Fill')),
                 'close'=>array('type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"')),
          $sess, $slice_id);

// list selected items to special form - used by manager.js to show items (recipients)
echo "\n  </form>";
FrmItemListForm($items);
echo "\n  </body>\n</html>";
page_close();
?>
