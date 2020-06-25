<?php
/**
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
 * GNU General Public License for more details
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


/** AA_Property class defines one variable in AA. It is describes the datatype,
 *  (numeric, date, string), constraints (range of values, length, if it is
 *  required, ...), name, and some description of the variable. It do not hold
 *  the information, how the value is presented to the user and how it could
 *  be entered. It also do not contain the value of the variable.
 *  For displaying the AA_Property we choose some AA\Widget\Widget and pass
 *  the AA_Value there.
 *  Used also for definition of components's parameters
 *  (like AA_Transofrmations, ...)
 *
 *  This approach AA_Property/AA\Widget\Widget/AA_Value replaces the old one - all
 *  in one AA_Inputfield approach. It should be used not only for AA Fields,
 *  but also for parameters of functions/widgets...
 */
class AA_Property extends AA_Storable {

    /** Id of property - like: new_flag */
    var $id;

    /** Property name - like: _m('Mark as') */
    var $name;

    /** Property type - text | int | bool | float | <class_name>
     *  If the type is <class_name>, then it should support getAaValue() method.
     */
    var $type;

    /** Contain one or multiple values (numbered array) - bool (default is false)  */
    var $multi;

    /** should be stored, when we are storing the state of the object */
    var $persistent;

    /** validate - standard validators are
     *  text | bool | int | float | email | alpha | long_id | short_id | alias | filename | login | password | unique | e_unique | url | all | enum
     */
    protected $_validator;

    /** boolean - is it required? - like: true */
    var $required;

    /** AA_Value - default value for the field. If value for store not matching the validation, the default is used*/
    var $default;

    /** Help text for the property */
    var $input_help;

    /** Url, where user can get more informations about the property */
    var $input_morehlp;

    /** Value example */
    var $example;

    /** show_content_type_switch is used instead of $html_show or $html_rb_show.
     *  It is more generalized, so we can use more formaters in the future (not
     *  only HTML / Plain text, but also Wiki, Texy or whatever.
     *  The value is flagged 0 - do not show, FLAG_HTML | FLAG_PLAIN (1+2=3)
     *  means HTML / Plain text switch. There is an idea to use constant like
     *  CONTENT_SWITCH_STANDARD = FLAG_HTML | FLAG_PLAIN | .... = 1+2+4+8+16+...
     *  = 65535, so first 16 formaters will be standard (displayed after we add
     *  it to AA) and the rest (above 16) will be used for special purposes.
     *  However, it is just an idea right now (we still have just HTML and
     *  plain text)
     */
    var $show_content_type_switch;

    /** Default value for content type switch
    *   (FLAG_HTML at this moment)
    */
    var $content_type_switch_default;

    /** @todo some kind of perms - who can edit/change, ... - not defined, yet */
    var $perm;

    /** array of constants used for selections (selectbox, radio, ...) */
    var $const_arr = array();

    /** array of two letters shortcuts for languages used in this slice for translations - array('en','cz','es') */
    var $translations = array();


    /** AA_Property function
     * @param $id
     * @param $name
     * @param $type
     * @param $multi
     * @param $persistent
     * @param $validator
     * @param $required
     * @param $input_help
     * @param $input_morehlp
     * @param $example
     * @param $show_content_type_switch
     * @param $content_type_switch_default
     */
    function __construct($id='', $name='', $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perm=null, $default=null, $translations=null) {  // default values are needed for AA_Storable's construction
        $this->id                          = $id;
        $this->name                        = $name;
        $this->type                        = $type;
        $this->multi                       = $multi;
        $this->persistent                  = $persistent;
        $this->_validator                  = is_object($validator) ? $validator : ($validator ?: $type);   // we do not create AA_Validator object here, because then it makes infinite loop - AA_Property contains validator...
        $this->required                    = $required;
        $this->input_help                  = $input_help;
        $this->input_morehlp               = $input_morehlp;
        $this->example                     = $example;
        $this->show_content_type_switch    = $show_content_type_switch;
        $this->content_type_switch_default = $content_type_switch_default;
        $this->perm                        = $perm;
        $this->const_arr                   = (is_array($validator) AND ($validator[0]=='enum')) ? $validator[1] : array();
        $this->default                     = $default;
        $this->translations                = is_array($translations) ? $translations : array();
    }

    /** getClassProperties function of AA_Serializable
     * Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()  {
        return array (
            //                                                 id                            name                                 type    multi  persist validator, required, help, morehelp, example
            'id'                          => new AA_Property( 'id'                         , _m('id'                         ), 'string',   false),
            'name'                        => new AA_Property( 'name'                       , _m('name'                       ), 'string',   false),
            'type'                        => new AA_Property( 'type'                       , _m('type'                       ), 'string',   false),
            'multi'                       => new AA_Property( 'multi'                      , _m('multi'                      ), 'bool',     false),
            'persistent'                  => new AA_Property( 'persistent'                 , _m('persistent'                 ), 'bool',     false),
            'validator'                   => new AA_Property( 'validator'                  , _m('validator'                  ), 'string',   false),
            'required'                    => new AA_Property( 'required'                   , _m('required'                   ), 'bool',     false),
            'input_help'                  => new AA_Property( 'input_help'                 , _m('input_help'                 ), 'string',   false),
            'input_morehlp'               => new AA_Property( 'input_morehlp'              , _m('input_morehlp'              ), 'string',   false),
            'example'                     => new AA_Property( 'example'                    , _m('example'                    ), 'string',   false),
            'show_content_type_switch'    => new AA_Property( 'show_content_type_switch'   , _m('show_content_type_switch'   ), 'int',      false), // bitfield
            'content_type_switch_default' => new AA_Property( 'content_type_switch_default', _m('content_type_switch_default'), 'int',      false), // int or maybe bitfield in future
            'perm'                        => new AA_Property( 'perm'                       , _m('perm'                       ), 'string',   false),
            'const_arr'                   => new AA_Property( 'const_arr'                  , _m('const_arr'                  ), 'string',   true),
            'default'                     => new AA_Property( 'default'                    , _m('default'                    ), 'AA_Value', true)
            );
    }


    /** getters */
    function getId()                  { return $this->id;            }
    function getName()                { return $this->name;          }
    function getType()                { return $this->type;          }
    function getHelp()                { return $this->input_help;    }
    function getMorehelp()            { return $this->input_morehlp; }
    function getExample()             { return $this->example;       }
    function getConstants()           { return $this->const_arr;     }
    function getTranslations()        { return $this->translations;  }
    function getContentTypeSwitches() { return $this->show_content_type_switch;    }
    function getContentTypeDefault()  { return $this->content_type_switch_default; }

    public function getValidator() {
        if (!is_object($this->_validator)) {
            if (is_null($this->_validator = AA_Validate::factoryCached($this->_validator))) {
                $this->_validator = AA_Validate::factoryCached('all');
            }
        }
        return $this->_validator;
    }

    /** setters */
    function setHelp($val)     { $this->input_help = (string)$val; return $this; }
    function setExample($val)  { $this->example    =         $val; return $this; }

    /** set the Values array and also the validator */
    function setConstants($arr) {
        $this->const_arr = (array) $arr;
        $this->_validator = new AA_Validate_Enum(array('possible_values'=>array_keys($this->const_arr)));
    }

    /** called before StoreItem to fill the field with correct data */
    function complete4Insert($new_value, $profile) {
        $fid           = $this->getId();
        $profile_value = $profile->getProperty('hide&fill',$fid) ?: $profile->getProperty('fill',$fid);
        if ($profile_value) {
            $new_value = $profile->parseContentProperty($profile_value);
        }

//      if ($profile->getProperty('hide',$fid) || !$this->validate($new_value) || $new_value->isEmpty()) {
//      if ($profile->getProperty('hide',$fid) || !$this->validate($new_value->getValues()) ) {  // HM 2016-10-14
        if ($profile->getProperty('hide',$fid) || !$this->validate($new_value->getValues()) || $new_value->isEmpty()) {  // not valid values we replace with default here. However, it should already be validated, so we should not reach this
            return $this->default;
        }
        return $new_value;
    }

    /** Converts AA_Value to real property value (scallar, Array, ...)
     *  @param $aa_value AA_Value
     *  @return array
     */
    function toValue($aa_value) {
        $validator = $this->getValidator();
        if ($this->isMulti()) {
            $val = $aa_value->getValues();
            foreach($val as &$value) {
                $validator->validate($value);
            }
        } else {
            $val = $aa_value->getValue();
            $validator->validate($val);
        }
        return $val;
    }

    /** returns default widget for given property - it tries to identify,
     *  if it is multiple, uses constants, is bool, ...
     * @param $form AA_Form
     * @return mixed|void
     * @throws Exception
     */
    function addPropertyFormrows($form) {
        if ($this->isObject()) {
            if (is_callable(array($this->type, 'addFormrows'))) {
                return call_user_func_array(array($this->type, 'addFormrows'), array($form));
            }
            throw new Exception('Can\'t generate widget for '.$this->type.' object property');
        }

        return $form->addRow(new AA_Formrow_Full($this, $this->defaultWidget()));
    }

    function defaultWidget() {
        $values = $this->getConstants();
        $widget = '';
        if ($this->isMulti()) {
            if (empty($values)) {
                $widget = new \AA\Widget\MflWidget();
            }
            elseif (count($values) < 5) {
                $widget = new \AA\Widget\MchWidget(array('const_arr' => $values));
            } else {
                $widget = new \AA\Widget\MseWidget(array('const_arr' => $values));
            }
        }
        elseif (!empty($values)) {
            $widget = new \AA\Widget\SelWidget(array('const_arr' => $values));
        }
        elseif ($this->type == 'bool') {
            $widget = new \AA\Widget\ChbWidget();
        }
        elseif ($this->type == 'text') {
            $widget = new \AA\Widget\CodWidget();
        }
        else {
            $widget = new \AA\Widget\FldWidget();
        }
        return $widget;
    }

    /**
     * @param $value_arr
     * @return bool
     */
    function validate($value_arr) {
        return !$this->validateDetailed($value_arr);
    }

    /**
     * @param $value_arr
     * @return array  - empty array means OK - valid
     */
    function validateDetailed($value_arr) {
        $valid     = true;
        $validator = $this->getValidator();
        $empty     = true;
        foreach ($value_arr as $v) {
            if ($validator->varempty($v)) {
                continue;
            }
            $empty = false;
            if (!($valid = $validator->validate($v))) {
                break;
            }
        }
        if ($empty AND $this->isRequired()) {
            return array(VALIDATE_ERROR_NOT_FILLED, _m("Not filled"));
        }
        return $valid ? array() : array($validator->lastErr(), $validator->lastErrMsg());
    }

    /** isObject function */
    function isObject() {
        return !in_array($this->type, array('text', 'string', 'int', 'bool', 'float'));
    }

    /** @return string - table, where the property would be stored */
    static function storageType($type) {  // AA_Object needs to access the method
        switch ($type) {
        case 'string':
        case 'text':   return 'object_text';
        case 'int':
        case 'bool':   return 'object_integer';
        case 'float':  return 'object_float';
        }
        // object_text for serialized object data ...
        return 'object_text';
    }

    /** isMulti function */
    function isMulti() {
        return $this->multi;
    }

    /** isRequired function */
    function isRequired() {
        return $this->required AND !in_array(trim($this->id,'.'), array('short_id','status_code','post_date','last_edit','display_count','disc_count','disc_app','moved2active'));
    }

    /** isPersistent function */
    function isPersistent() {
        return $this->persistent;
    }

    /** save property to the database
     * @param $value
     * @param $priority
     * @return bool
     * @throws Exception
     */
    function save($value, $object_id, $owner_id='') {
        $ret = true;
        if ($this->isMulti()) {
            if ( is_array($value) ) {
                // all keys are numeric
                foreach($value as $k => $v) {
                    $ret &= $this->_saveSingle($v, $object_id, $k, $owner_id);
                }
//            } elseif (!empty($value)) {
//                throw new Exception('Property marked as multi but do not contain array value');
//            not necessary - we must call validate before object saving,
//            so this kind of thing is already spotted
            }
        } else {
            $ret = $this->_saveSingle($value, $object_id, 0, $owner_id);
        }
        return $ret;
    }

    /** _saveRow function
     * @param $property_id
     * @param $value
     * @param $type
     * @param $priority
     * @return bool
     * @throws Exception
     */
    private function _saveSingle($value, $object_id, $priority, $owner_id) {
//      not necessary - we must call validate before object saving,
//      so this kind of thig is already spotted
//      if ( is_array($value) ) {
//          throw new Exception('Property marked as scalar (not multi) but contain array value');
//      }

        // Property type - text | int | bool | float | <class_name>
        if ( !$this->isObject()) {
            AA_Property::_saveRow($this->id, $value, $this->type, $object_id, $priority);
            return true;
        }

        if (empty($value)) {
            return true;
        }

        if (is_subclass_of($value, 'AA_Object')) {
            //  this property is object - so save it (the id of the object is returned)
            $value->setOwnerId($owner_id);
            $sub_object_id = $value->save();
            // if not saved, then it returns null
            if (!$sub_object_id) {
                return false;
            }
            AA_Property::_saveRow($this->id, $sub_object_id, 'text', $object_id, $priority);
            return true;
        } elseif (is_subclass_of($value, 'AA_Storable')) {
            AA_Property::_saveRow($this->id, serialize($value->getState()), $this->type, $object_id, $priority);
            return true;
        }
        throw new Exception('object is not AA_Storable - ', $this->id);
    }

    /** _saveRow function
     * @param $property_id
     * @param $value
     * @param $type
     * @param $priority
     */
    static private function _saveRow($property_id, $value, $type, $object_id, $priority=0) {
        $varset = new CVarset();
        $varset->add('object_id', 'text',   $object_id);
        $varset->add('priority',  'number', $priority);
        $varset->add('property',  'text',   $property_id);
        $varset->add('value',      $type,   $value);        // Property type - text | int | bool | float | <class_name>
        $varset->doInsert(AA_Property::storageType($type));
    }
}

/** Base class for formatters (like HTML/Plain text/wiki/Texy/...)
*   Currently we use just HTML and Plain text
*/
class AA_Formatters {

    const RAW       = 1;   // former HTML - see constants.php3 - FLAG_HTML

    const TEXT2HTML = 0;   // default

    /** bit-field representig, which formatters we want to show. 65535
     *   means "all standard formatters", which means all 16 standard formatters.
     *   We use just two, at this moment - HTML (=1) and PLAIN (=2)
     *   (we will continue on bit basis, so next formatter would be xxx (=4))
     */
    const STANDARD_FORMATTERS = 1;   // default  0 | 1 -  TEXT2HTML | RAW | ... (will be increased up to 65535 after adding 128 - TEXY, ....)


    protected static function getFromatters() {
        static $formatters = null;
        if (is_null($formatters)) {
            $formatters = array(
                0 => array('TEXT2HTML', _m("Plain text")),
                1 => array('RAW', _m("HTML"))
            );
        }
        return $formatters;
    }

    /** html_radio function
     * Prints html/plan_text radiobutton
     * @param string      $varname - name of widget variable
     * @param AA_Property $aa_property
     * @param AA_Value    $value
     * @return string
     */
    static function getFormattersRadio($basevarname, $aa_property, $value) {
        if (!($contenttypes = $aa_property->getContentTypeSwitches())) {
            return self::getHiddenFormatters($basevarname, $aa_property, $value);
        }
        $formatters = self::getFromatters();
        $flag_frmtr = self::getCurrentFormatter($value, $aa_property);

        $ret  = '<div class=aa-formatters>';
        foreach ($formatters as $bit => $formdef) {
            if (($bit==0) OR ($contenttypes & $bit)) {
                $ret .= ' <label><input type=radio name="'.$basevarname.'[flag]" value="'.$bit.'"'. (($flag_frmtr == $bit) ? ' checked' : ''). '>'.$formdef[1].'</label>';
            }
        }
        $ret .= '</div>';

        //$htmlareaedit= "<a href=\"javascript:openHTMLAreaFullscreen('".$varname."');\">"._m("Edit in HTMLArea")."</a>"; // used for HTMLArea

        //// conversions menu
        // if ($convertor = ($convert ? $this->get_convertors() : false) ) {
        //     $this->echovar($convertor,   'conv');
        // }

        return $ret;
    }

    /**
     * Prints hidden formatter flag, if necessary
     * @param string      $varname - name of widget variable
     * @param AA_Property $aa_property
     * @param AA_Value    $value
     * @return string
     */
    static function getHiddenFormatters($basevarname, $aa_property, $value) {
        $flag_frmtr  = self::getCurrentFormatter($value, $aa_property);
        return $flag_frmtr ? '<input type=hidden name="'.$basevarname.'[flag]" value="'.$flag_frmtr.'">' : '';
    }

    /** returns current formatter flag to set on widget
     * @param AA_Value    $value
     * @param AA_Property $aa_property
     * @return int
     */
    static function getCurrentFormatter($value, $aa_property) {
        return $value->isEmpty() ? $aa_property->getContentTypeDefault() : ($value->getFlag() & AA_Formatters::STANDARD_FORMATTERS);
    }

}

//class AA_Formatter {
//
//}
//
//class AA_Formatter_Raw extends AA_Formatter {
//    const BIT_VALUE = 1;
//
//}



