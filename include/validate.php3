<?php
/**
 * Class AA_Validate
 *
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
/** ValidateInput function
 *  Validate users input. Error is reported in $err array
 * @param $variableName
 * @param $inputName
 * @param $variable could be array or not
 * @param $err
 * @param $needed
 * @param $type
 *  You can add parameters to $type divided by ":".
 */
function ValidateInput($variableName, $inputName, $variable, &$err, $needed=false, $type="all") {
    foreach ((array)$variable as $var) {
        $valid = _ValidateSingleInput($variableName, $inputName, $var, $err, $needed, $type);
        if ( !$valid ) {
            break;
        }
    }
    return $valid;
}

define('VALIDATE_ERROR_BAD_TYPE',         400);
define('VALIDATE_ERROR_BAD_VALIDATOR',    401);
define('VALIDATE_ERROR_OUT_OF_RANGE',     402);
define('VALIDATE_ERROR_NOT_MATCH',        403);
define('VALIDATE_ERROR_BAD_PARAMETER',    404);
define('VALIDATE_ERROR_NOT_UNIQUE',       405);
define('VALIDATE_ERROR_TOO_LONG',         406);
define('VALIDATE_ERROR_TOO_SHORT',        407);
define('VALIDATE_ERROR_WRONG_CHARACTERS', 408);
define('VALIDATE_ERROR_NOT_IN_LIST',      409);

/** AA user input validation class
 *  Ussage (for standard validators):
 *      if ( AA_Validate::validate($variable, 'int') ) {...};
 *      if ( AA_Validate::validate($variable, array('int', array('min'=>0, 'max'=>10)) ) {...};
 */
class AA_Validate {

    /** factory function
     *  Returns validators for standard data types
     *   @param $v_type is string, or array($type,$parameter)
     */
    function &factory($v_type) {
        static $standard_validators = array();

        list ($type, $parameters) = is_array($v_type) ? $v_type : array($v_type, array());

        $sv_key = get_hash($type, $parameters);
        if ( !isset($standard_validators[$sv_key]) ) {
            switch ($type) {
                case 'bool':     $standard_validators[$sv_key] = new AA_Validate_Bool($parameters);   break;
                case 'num':
                case 'number':
                case 'int':
                case 'integer':  $standard_validators[$sv_key] = new AA_Validate_Number($parameters); break;
                case 'float':    $standard_validators[$sv_key] = new AA_Validate_Float($parameters);  break;
                case 'e-mail':
                case 'email':    $standard_validators[$sv_key] = new AA_Validate_Email($parameters);  break;
                case 'alpha':    $standard_validators[$sv_key] = new AA_Validate_Regexp(array('pattern'=>'/^[a-zA-Z]+$/'));          break;
                case 'id':
                case 'long_id':  $standard_validators[$sv_key] = new AA_Validate_Id();    break;  // empty = "" or "0"
                case 'short_id': $standard_validators[$sv_key] = new AA_Validate_Number(array('min'=>0));           break;
                case 'alias':    $standard_validators[$sv_key] = new AA_Validate_Regexp(array('pattern'=>'/^_#[0-9_#a-zA-Z]{8}$/')); break;
                case 'filename': $standard_validators[$sv_key] = new AA_Validate_Regexp(array('pattern'=>'/^[-.0-9a-zA-Z_]+$/')); break;
                case 'login':    $standard_validators[$sv_key] = new AA_Validate_Login($parameters);  break;
                case 'password': $standard_validators[$sv_key] = new AA_Validate_Pwd($parameters);    break;
                case 'unique':   $standard_validators[$sv_key] = new AA_Validate_Unique($parameters); break;
                case 'e_unique':
                case 'e-unique':
                case 'eunique':  $standard_validators[$sv_key] = new AA_Validate_Eunique($parameters); break;
                case 'url':      $standard_validators[$sv_key] = new AA_Validate_Url($parameters);  break;
                case 'date':     $standard_validators[$sv_key] = new AA_Validate_Date($parameters); break;
                case 'text':
                case 'string':
                case 'field':
                case 'all':      $standard_validators[$sv_key] = new AA_Validate_Text($parameters); break;
                case 'enum':     $standard_validators[$sv_key] = new AA_Validate_Enum($parameters); break;
                default:         // Bad validator type: $type;
                                 return null;
            }
        }

        return $standard_validators[$sv_key];
    }

    /** validate function
     *  static class function
     *      if ( AA_Validate::validate($variable, 'email') ) {...};
     *      if ( AA_Validate::validate($variable, array('int', array('min'=>0, 'max'=>10)) ) {...};
     * @param $var
     * @param $type
     * @param $default
     */
    function validate(&$var, $type, $default='AA_noDefault') {
        $validator = AA_Validate::factory($type);
        if ( is_null( $validator ) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_BAD_VALIDATOR, _m('Bad validator type: %1', array($type)), $default);
        }
        return $validator->validate($var);
    }

    /** filter function - returns array of values matching the criteria
     *  static class function
     *      AA_Validate::filter(array('my@mail.cz','your@mail.cz'), 'email')
     *      AA_Validate::filter($vararray, array('int', array('min'=>0, 'max'=>10))
     * @param $var
     * @param $type
     */
    function filter($vararray, $type) {
        return array_filter((array)$vararray, array(AA_Validate::factory($type),'validate'));
    }

    /** checks if the variable is empty */
    function varempty(&$variable) {
        return  ($variable=="" OR chop($variable)=="");
    }

    /** lastErr function
     *  Method returns or sets last itemContent error
     *  The trick for static class variables is used
     * @param $err_id
     * @param $err_msg
     * @param $getmsg
     */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** lastErrMsg function
     * @return last error message - it is grabbed from static variable
     *  of lastErr() method */
    function lastErrMsg() {
        return AA_Validate::lastErr(null, null, true);
    }

    /** bad function
     *  Protected static method - used only from AA_Validate_* objects
     * @param $var
     * @param $err_id
     * @param $err_msg
     * @param $default
     */
    function bad(&$var, $err_id, $err_msg, $default) {
        AA_Validate::lastErr($err_id, $err_msg);
        if ( $default != 'AA_noDefault' ) {
            $var = $default;
        }
        return false;
    }


        /** parseClassProperties function
     *  Parses class parameters from the string, which is stored in the database
     *  Typical use is for fields.input_show_func, where parameters are stored
     *  as string in the form: fnc:const:param
     *  @param $class_mask
     *  @param $param
     *  @return asociative array of parameters, the name of parameters is given
     *  by the class itself ($class_mask . fnc).
     */
    function parseClassProperties($class_mask, $string) {
        // we do not use ParamExplode() - I  do not like the http:// replacement there
        $splited = explode('##Sx', str_replace(array('#:', ':', '~@|_'), array('~@|_', '##Sx', ':'), $string));

        // first parameter is the class identifier - the parameters starts then
        $i      = 1;
        $class  = AA_Object::constructClassName($class_mask, str_replace('-','',$splited[0]));
        $params = array('class' => $class);

        if ( class_exists($class) ) {
            // ask class, which parameters uses
            // call AA_Widget_Txt::getClassProperties()), for example
            foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $name => $property) {
                if (isset($splited[$i])) {
                    $params[$name] = $splited[$i++];
                }
            }
        }
        return $params;
    }


    /** ask class, which parameters uses and fill it
     *  copied from AA_Object
     */
    function __construct($params=array()) {
        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $name =>$property) {
            if (isset($params[$name])) {
                $this->$name = $params[$name];
            }
        }
    }

    function getClassProperties()  {
        return array ();
    }

    function &factoryByString($class_mask, $string) {
        $params = self::parseClassProperties($class_mask, $string);
        $classname = $params['class'];
        return class_exists($classname) ? new $classname($params) : null;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some more attributtes (like min, max, step, pattern, ...) */
    function getHtmlInputAttr() {
        return '';
    }
}

/** Test for integer value
 *  @param   $min
 *  @param   $max
 *  @param   $step
 */
class AA_Validate_Number extends AA_Validate {
    /** Minum number */
    var $min;

    /** Maximum number */
    var $max;

    /** Step */
    var $step;

    /** getClassProperties function
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     */
    function getClassProperties()  {
        return array (
                 // we use array instead of "new AA_Property", because then it makes infinite loop - AA_Property contains validator...
                 //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'min'  => array( 'min',  _m("Alloved minimum value"), 'int', false, true, 'int', false, _m(""), '', 1),
            'max'  => array( 'max',  _m("Alloved maximum value"), 'int', false, true, 'int', false, _m(""), '', 12),
            'step' => array( 'step', _m("Step"),                  'int', false, true, 'int', false, _m(""), '', 1),
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        if ((string)$var !== (string)(int)$var) {
            return AA_Validate::bad($var, VALIDATE_ERROR_BAD_TYPE, _m('No integer value'), $default);
        }
        $var = (int)$var;
        if ( !is_null($this->max) AND ($var > $this->max) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $default);
        }
        if ( !is_null($this->min) AND ($var < $this->min) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $default);
        }
        return true;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=text pattern="[0-9]*"'
               . (is_numeric($this->min) ? ' min='.$this->min : '')
               . (is_numeric($this->max) ? ' max='.$this->max : '')
               . (is_numeric($this->step) AND ($this->step > 1) ? ' step='.$this->step : '');
    }
}


/** Test for bool value
 */
class AA_Validate_Bool extends AA_Validate_Number {
    function __construct($param=array()) {
        parent::__construct($param);
        $this->min = 0;
        $this->max = 1;
    }
}

/** Test for bool value
 */
class AA_Validate_Date extends AA_Validate_Number {
}

/** Test for bool value
 */
class AA_Validate_Url extends AA_Validate_Regexp {
    function __construct($param=array()) {
        parent::__construct($param);
        $this->pattern          = '|^http(s?)\://\S+\.\S+|';
        $this->empty_expression = '~(^http(s?)\://$)|(^\s*$)~';
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=url pattern="http(s?)\://\S+"';
    }
}

/** Test for bool value
 */
class AA_Validate_Email extends AA_Validate_Regexp {
    function __construct($param=array()) {
        parent::__construct($param);
        $this->pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,6}$/';
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=email';
    }
}


/** Test for bool value
 */
class AA_Validate_Id extends AA_Validate_Regexp {
    function __construct($param=array()) {
        parent::__construct($param);
        $this->pattern          = '/^.*$/';   // temporarily disabled for Tags feature
        //$this->pattern          = '/^[0-9a-fxyz]{30,33}$/';
        $this->empty_expression = '/^(0|\s*)$/';
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=text pattern="[0-9a-f]{32}"';
    }
}

/** Test for float value
 *  @param   $min
 *  @param   $max
 */
class AA_Validate_Float extends AA_Validate {
    /** Minum number */
    var $f_min;

    /** Maximum number */
    var $f_max;

    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'f_min'  => array( 'f_min',  _m("Alloved minimum value"), 'float', false, true, 'float', false, _m(""), '', '1.0'),
            'f_max'  => array( 'f_max',  _m("Alloved maximum value"), 'float', false, true, 'float', false, _m(""), '', '1000.0'),
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        if ( !is_float($var) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_BAD_TYPE, _m('No float value'), $default);
        }
        $var = (float)$var;
        if ( !is_null($this->max) AND ($var > $this->max) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $default);
        }
        if ( !is_null($this->min) AND ($var < $this->min) ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $default);
        }
        return true;
    }
}

/** Test for Regular Expression
 *  @param   $regular_expression
 *  @param   $default_error_id
 *  @param   $default_error_msg
 */
class AA_Validate_Regexp extends AA_Validate {
    /** Regular Expression */
    var $pattern;
    var $empty_expression = '/^\s*$/';

    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'pattern'          => array( 'pattern',           _m("Regular expression"), 'string', false, true, 'string', false, _m(""), '', '/^[a-z]*$/'),
            'empty_expression' => array( 'empty_expression',  _m("Empty expression"),   'string', false, true, 'string', false, _m(""), '', '/^(0|\s*)$/')
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        return preg_match($this->pattern, $var) ? true : AA_Validate::bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Do not match the pattern'), $default);
    }

    function varempty($var) {
        //huhl($this->empty_expression, $var, preg_match($this->empty_expression, $var));
        return preg_match($this->empty_expression, $var);
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return (strlen($this->pattern) > 2) ? 'type=text pattern="'.substr($this->pattern, 1, -1).'"' : '';  // we need to convert /^[a-z]*$/ to ^[a-z]*$
    }
}

class AA_Validate_Enum extends AA_Validate {
    /** Enumeration array (array of possible values). Values are stored as keys. */
    var $possible_values;

    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'possible_values' => array( 'possible_values', _m("Possible values"), 'string', true, true, 'string', false)
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        return isset($this->possible_values[$var]) ? true : AA_Validate::bad($var, $this->default_error_id, $this->default_error_msg, $default);
    }
}

/** Test for login name value */
class AA_Validate_Login extends AA_Validate {

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        $len = strlen($var);
        if ( $len<3 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $default);
        }
        if ( $len>32 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_LONG, _m('Too long'), $default);
        }
        return preg_match('/^[a-zA-Z0-9]*$/', $var) ? true : AA_Validate::bad($var, VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters - you should use a-z, A-Z and 0-9 characters"), $default);
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=text pattern="[a-zA-Z0-9]{3,32}"';
    }
}

/** Test for password */
class AA_Validate_Pwd extends AA_Validate {

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        $len = strlen($var);
        if ( $len<5 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $default);
        }
        if ( $len>255 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_LONG, _m('Too long'), $default);
        }
        return true;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return 'type=password min=5';
    }
}

/** Test for unique value in slice/database
 *  @param   $field_id
 *  @param   $scope       - username | slice | allslices
 *  @param   $item_id     - current item ID
 */
class AA_Validate_Unique extends AA_Validate {
    /** Search in which field */
    var $field_id;

    /** Scope, where to search - username | slice | allslices */
    var $scope = 'slice';

    /** Item, which we do not count (current item) */
    var $item_id;

    /** getClassProperties function
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'field_id' => array( 'field_id', _m("Field id"), 'string', false, true, 'string', false, _m(""), '', ''),
            'scope'    => array( 'scope',    _m("Scope"),    'string', false, true, 'string', false, _m("username | slice | allslices"), '', 'slice'),
            'item_id'  => array( 'item_id' , _m("Item id whichh we do not count"), 'string', false, true, 'string', false),
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        global $slice_id;

        if ( $this->scope == 'username') {
            if ( !AA::$perm->isUsernameFree($var) AND ( !$this->item_id OR (AA_Reader::name2Id($var) != $this->item_id))) {
                return AA_Validate::bad($var, VALIDATE_ERROR_NOT_UNIQUE, _m('Username is not unique'), $default);
            }
            return true;
        }
        if ( $this->scope == 'slice') {
            if ( !AA_Slices::getField($slice_id, $this->field_id) ) {
                return AA_Validate::bad($var, VALIDATE_ERROR_BAD_PARAMETER, _m('Wrong parameter field_id for unique check'), $default);
            }
            $SQL = "SELECT * FROM content INNER JOIN item ON content.item_id = item.id
                    WHERE item.slice_id='".q_pack_id($slice_id)."'
                        AND field_id='".addslashes($this->field_id)."'
                        AND text='$var'";
            if ($this->item_id) {
                $SQL .= " AND item.id <> '".q_pack_id($this->item_id)."'";
            }
        } else {
            $SQL = "SELECT * FROM content WHERE field_id='". addslashes($this->field_id) ."' AND text='$var'";
            if ($this->item_id) {
                $SQL .= " AND item_id <> '".q_pack_id($this->item_id)."'";
            }
        }
        if (GetTable2Array($SQL, 'aa_first', 'aa_mark')) {
            return AA_Validate::bad($var, VALIDATE_ERROR_NOT_UNIQUE, _m('Not unique - value already used'), $default);
        }
        return true;
    }
}

/** Test for unique value in slice/database
 *  @param   $field_id
 *  @param   $scope       - username | slice | allslices
 *  @param   $item_id     - current item ID
 */
class AA_Validate_Eunique extends AA_Validate {
    /** Search in which field */
    var $field_id;

    /** Scope, where to search - username | slice | allslices */
    var $scope = 'slice';

    /** Item, which we do not count (current item) */
    var $item_id;

    /** getClassProperties function
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'field_id' => array( 'field_id', _m("Field id"), 'string', false, true, 'string', false, _m(""), '', ''),
            'scope'    => array( 'scope',    _m("Scope"),    'string', false, true, 'string', false, _m("username | slice | allslices"), '', 'slice'),
            'item_id'  => array( 'item_id' , _m("Item id whichh we do not count"), 'string', false, true, 'string', false),
            );
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        if ( !AA_Validate::validate($var, 'email', $default) ) {
            return false;
        }
        $validator = new AA_Validate_Unique($this->scope, $this->field_id, $this->item_id);
        return $validator->validate($var, $default);
    }
}

/** Test for text (any characters allowed)
 *  @todo Realy validate URL
 */
class AA_Validate_Text extends AA_Validate {

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        return true;
    }
}


/** _ValidateSingleInput function
*  Validate users input. Error is reported in $err array
* @param $variableName
* @param $inputName
*  @param $variable is not array
* @param $err
* @param $needed
* @param $type
*  You can add parameters to $type divided by ":".
*/
function _ValidateSingleInput($variableName, $inputName, $variable, &$err, $needed, $type) {
    $validate_definition = ParamExplode($type);
    $type                = $validate_definition[0];

    switch ($type) {
        case 'regexp':   $regexp    = $validate_definition[1];
                         $err_text  = isset($validate_definition[2]) ? $validate_definition[2] : null;
                         $validator = new AA_Validate_Regexp($regexp, null, $err_text);
                         break;
        case 'e-unique':
        case 'unique':
                         $field_id  = $validate_definition[1];
                         $scope     = $validate_definition[2];
                         $item_id   = $validate_definition[3];
                         $UNIQUE_SCOPES               = array ( 0 => 'username',
                                                                1 => 'slice',
                                                                2 => 'allslices'
                                                               );
                         if ( $type == 'unique' ) {
                             $validator = new AA_Validate_Unique( $UNIQUE_SCOPES[(int)$scope], $field_id, $item_id);
                         } else {
                             $validator = new AA_Validate_Eunique( $UNIQUE_SCOPES[(int)$scope], $field_id, $item_id);
                         }
                         break;
        default:         $validator = AA_Validate::factory($type);
    }

    $ret = true;
    if (is_object($validator)) {
        if ( $validator->varempty($variable) ) {
            if ( $needed ) {
                $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("it must be filled").")");
                return false;
            } else {
                return true;
            }
        }
        $ret = $validator->validate($variable);
        if (!$ret) {
            $err["$variableName"] = MsgErr(_m("Error in")." $inputName - ". AA_Validate::lastErrMsg());
        }
    }
    return $ret;
}

/** get_javascript_field_validation function
* used in tabledit.php3 and itemedit.php3
*/
function get_javascript_field_validation() {
    /* javascript params:
       myform = the form object
       txtfield = field name in the form
       type = validation type
       add = is it an "add" form, i.e. showing a new item?
    */
    return "
        function validate (myform, txtfield, type, required, add) {
            var ble;
            var invalid_email = /(@.*@)|(\\.\\.)|(@\\.)|(\\.@)|(^\\.)/;
            var valid_email = /^.+@[a-zA-Z0-9\\-\\.]+\\.([a-zA-Z]{2,6}|[0-9]{1,3})$/;

            if (type == 'pwd') {
                myfield = myform[txtfield+'a'];
                myfield2 = myform[txtfield+'b'];
            } else
                myfield = myform[txtfield];

            if (myfield == null)
                return true;

            var val = myfield.value;
            var err = '';

            if (val == '' && required && (type != 'pwd' || add == 1)) {
                if (type == 'pwd')
                     err = '"._m("This field is required.")."';
                else err = '"._m("This field is required (marked by *).")."';
            }

            else if (val == '')
                return true;

            else switch (type) {
                case 'number':
                    if (!val.match (/^[0-9]+$/))
                        err = '"._m("Not a valid integer number.")."';
                    break;
                case 'filename':
                    if (!val.match (/^[0-9a-zA-Z_]+$/))
                        err = '"._m("Not a valid file name.")."';
                    break;
                case 'email':
                case 'e-mail':
                    if (val.match(invalid_email) || !val.match(valid_email))
                        err = '"._m("Not a valid email address.")."';
                    break;
                case 'pwd':
                    if (val && val != myfield2.value)
                        err = '"._m("The two password copies differ.")."';
                    break;
            }

            if (err != '') {
                alert (err);
                myfield.focus();
                return false;
            }
            else return true;
        }";
}




// ----------------------------------------------------
// not used yet
// @author Jirka Reischig 28.2.2012
//
function NEW_get_lines($fp) {
    $data = "";
    while($str = @fgets($fp,515)) {
        $data .= $str;
        // if the 4th character is a space then we are done reading
        // so just break the loop
        if(substr($str,3,1) == ' ') { break; }
    }
    return $data;
}

function NEW_checkEmail($email) {
    // checks proper syntax
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // gets domain name
        list($username,$domain)=explode('@',$email);
        // checks for if MX records in the DNS
        if ( getmxrr($domain,$smtphosts,$mx_weight) or (gethostbyname($domain) != $domain) ) {
            if ( $smtphosts ) {
                // Put the records together in a array we can sort
                for ($i=0; $i<count($smtphosts); $i++) {
                    $mxs[$smtphosts[$i]] = $mx_weight[$i];
                }
                // Sort them
                asort($mxs);
                $domain = current(array_keys($mxs));
            }
            // attempts a socket connection to mail server
            $fp = fsockopen($domain,25,$errno,$errstr,30);
            if ( $fp ) {
                get_lines($fp);
                fwrite($fp, 'ehlo ecn.cz'."\r\n");
                get_lines($fp);
                fwrite($fp, 'mail from: <>'."\r\n");
                get_lines($fp);
                fwrite($fp, 'rcpt to: <'.$email.'>'."\r\n");
                $odpoved = get_lines($fp);
                fwrite($fp, 'quit'."\r\n");
                fclose($fp);
                if ( intval(substr($odpoved, 0, 3)) == '250' ) {
                    return true;
                } else {
                    echo 'Error: verification failed: '.$odpoved;
                    return false;
                }
            } else {
                echo 'Error: connection to 25 failed';
                return false;
            }
        } else {
            echo 'Error: bad domain';
            return false;
        }
    } else {
        echo 'Error: email not look like email';
        return false;
    }
}

?>
