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
 *  Ussage (for standard validators): AA_Validate::validate($variable, 'int');
 */
class AA_Validate {

    /** factory function
     *  Returns validators for standard data types
     *   @param $v_type is string, or array($type,$parameter)
     */
    function &factory($v_type) {
        static $standard_validators = array();

        list ($type, $parameter) = is_array($v_type) ? $v_type : array($v_type, '');

        $sv_key = md5($type.serialize($parameter));
        if ( !isset($standard_validators[$sv_key]) ) {
            switch ($type) {
                case 'bool':     $standard_validators[$sv_key] = new AA_Validate_Int(0,1);         break;
                case 'num':
                case 'number':
                case 'int':
                case 'integer':  $standard_validators[$sv_key] = new AA_Validate_Int();            break;
                case 'float':    $standard_validators[$sv_key] = new AA_Validate_Float();          break;
                case 'e-mail':
                case 'email':    $standard_validators[$sv_key] = new AA_Validate_Regexp('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,6}$/');          break;
                case 'alpha':    $standard_validators[$sv_key] = new AA_Validate_Regexp('/^[a-zA-Z]+$/');          break;
                case 'id':
                case 'long_id':  $standard_validators[$sv_key] = new AA_Validate_Regexp('/^[0-9a-f]{30,32}$/',null,null,'/^0|\s*$/');    break;  // empty = "" or "0"
                case 'short_id': $standard_validators[$sv_key] = new AA_Validate_Int(0);           break;
                case 'alias':    $standard_validators[$sv_key] = new AA_Validate_Regexp('/^_#[0-9_#a-zA-Z]{8}$/',null,null,'/^0|\s*$/'); break;  // empty = "" or "0"
                case 'filename': $standard_validators[$sv_key] = new AA_Validate_Regexp('/^[-.0-9a-zA-Z_]+$/', VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters - you should use a-z, A-Z, 0-9 . _ and - characters")); break;
                case 'login':    $standard_validators[$sv_key] = new AA_Validate_Login();          break;
                case 'password': $standard_validators[$sv_key] = new AA_Validate_Password();       break;
                case 'unique':   $standard_validators[$sv_key] = new AA_Validate_Unique();         break;
                case 'e_unique': $standard_validators[$sv_key] = new AA_Validate_E_Unique();       break;
                case 'url':      $standard_validators[$sv_key] = new AA_Validate_Regexp('|^http(s?)\://\S+\.\S+|', VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters in URL - you should start with http:// or https:// and do not use space characters"),'~(^http(s?)\://$)|(^\s*$)~'); break;
                case 'text':
                case 'string':
                case 'field':
                case 'all':      $standard_validators[$sv_key] = new AA_Validate_All();            break;
                case 'enum':     $standard_validators[$sv_key] = new AA_Validate_Enum($parameter); break;
                default:         // Bad validator type: $type;
                                 return null;
            }
        }

        return $standard_validators[$sv_key];
    }
    /** validate function
     *  static class function - called as AA_Validate::validate('email');
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

}

/** Test for integer value
 *  @param   $min
 *  @param   $max
 */
class AA_Validate_Int extends AA_Validate {
    /** Minum number */
    var $min;

    /** Maximum number */
    var $max;
    /** AA_Validate_Int function
     * @param $min
     * @param $max
     */
    function AA_Validate_Int($min=null, $max=null) {
        $this->min = $min;
        $this->max = $max;
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
}

/** Test for float value
 *  @param   $min
 *  @param   $max
 */
class AA_Validate_Float extends AA_Validate {
    /** Minum number */
    var $min;

    /** Maximum number */
    var $max;
    /** AA_Validate_Float
     * @param $min
     * @param $max
     */
    function AA_Validate_Float($min=null, $max=null) {
        $this->min = $min;
        $this->max = $max;
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
    var $regular_expression;

    /** Defines possible return error id and messages, if the variable do not matches */
    var $default_error_id;
    var $default_error_msg;
    var $empty_expression;
    /** AA_Validate_Regexp function
     * @param $regular_expression
     * @param $err_id
     * @param $err_msg
     */
    function AA_Validate_Regexp( $regular_expression, $err_id=null, $err_msg=null, $empty_expression='/^\s*$/' ) {
        $this->regular_expression = $regular_expression;
        $this->default_error_id  = is_null($err_id)  ? VALIDATE_ERROR_NOT_MATCH : $err_id;
        $this->default_error_msg = is_null($err_msg) ? _m('Wrong value')        : $err_msg;
        $this->empty_expression  = $empty_expression;
    }

    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        return preg_match($this->regular_expression, $var) ? true : AA_Validate::bad($var, $this->default_error_id, $this->default_error_msg, $default);
    }

    function varempty($var) {
        return preg_match($this->empty_expression, $var);
    }
}

/** Test for Regular Expression
 *  @param   $regular_expression
 *  @param   $default_error_id
 *  @param   $default_error_msg
 */
class AA_Validate_Enum extends AA_Validate {
    /** Enumeration array (array of possible values). Values are stored as keys. */
    var $possible_values;

    /** Defines possible return error id and messages, if the variable do not matches */
    var $default_error_id;
    var $default_error_msg;
    /** AA_Validate_Regexp function
     * @param $possible_values
     */
    function AA_Validate_Enum( $possible_values ) {
        $this->possible_values = $possible_values;
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
    /** AA_Validate_Login function
     *
     */
    function AA_Validate_Login() {}
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
}

/** Test for password */
class AA_Validate_Password extends AA_Validate {
    /** AA_Validate_Password function
     *
     */
    function AA_Validate_Password() {}
    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        $len = strlen($var);
        if ( $len<5 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $default);
        }
        if ( $len>32 ) {
            return AA_Validate::bad($var, VALIDATE_ERROR_TOO_LONG, _m('Too long'), $default);
        }
        return true;
    }
}

/** Test for unique value in slice/database
 *  @param   $field_id
 *  @param   $scope       - username | slice | allslices
 */
class AA_Validate_Unique extends AA_Validate {
    /** Search in which field */
    var $field_id;

    /** Scope, where to search - username | slice | allslices */
    var $scope;
    /** AA_Validate_Unique function
     * @param $scope
     * @param $field_id
     */
    function AA_Validate_Unique($scope, $field_id) {
        $this->scope    = $scope ? $scope : 'slice';
        $this->field_id = $field_id;
    }
    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        global $slice_id;

        if ( $this->scope == 'username') {
            if ( !IsUsernameFree($var) ) {
                return AA_Validate::bad($var, VALIDATE_ERROR_NOT_UNIQUE, _m('Username is not unique'), $default);
            }
            return true;
        }
        if ( $this->scope == 'slice') {
            if ( !AA_Slices::isSliceProperty($slice_id, $this->field_id) ) {
                return AA_Validate::bad($var, VALIDATE_ERROR_BAD_PARAMETER, _m('Wrong parameter field_id for unique check'), $default);
            }
            $SQL = "SELECT * FROM content INNER JOIN item ON content.item_id = item.id
                    WHERE item.slice_id='".q_pack_id($slice_id)."'
                        AND field_id='".addslashes($this->field_id)."'
                        AND text='$var'";
        } else {
            $SQL = "SELECT * FROM content WHERE field_id='". addslashes($this->field_id) ."' AND text='$var'";
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
 */
class AA_Validate_E_Unique extends AA_Validate {
    /** Search in which field */
    var $field_id;

    /** Scope, where to search - username | slice | allslices */
    var $scope;
    /** AA_Validate_E_Unique function
     * @param $scope
     * @param $field_id
     */
    function AA_Validate_E_Unique($scope, $field_id) {
        $this->scope    = $scope ? $scope : 'slice';
        $this->field_id = $field_id;
    }
    /** validate function
     * @param $var
     * @param $default
     */
    function validate(&$var, $default='AA_noDefault') {
        if ( !AA_Validate::validate($var, 'email', $default) ) {
            return false;
        }
        $validator = new AA_Validate_Unique($this->scope, $this->field_id);
        return $validator->validate($var, $default);
    }
}

/** Test for text (any characters allowed)
 *  @todo Realy validate URL
 */
class AA_Validate_All extends AA_Validate {
    /** AA_Validate_All function
     *
     */
    function AA_Validate_All() {}
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
                         $UNIQUE_SCOPES               = array ( 0 => 'username',
                                                                1 => 'slice',
                                                                2 => 'allslices'
                                                               );
                         if ( $type == 'unique' ) {
                             $validator = new AA_Validate_Unique( $UNIQUE_SCOPES[(int)$scope], $field_id);
                         } else {
                             $validator = new AA_Validate_E_Unique( $UNIQUE_SCOPES[(int)$scope], $field_id);
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
?>
