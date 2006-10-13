<?php
/**
 * Class AA_Validate
 *
 * @package UserInput
 * @version $Id$
 * @author Honza Malik, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/**
*  Validate users input. Error is reported in $err array
*  @param $variable could be array or not
*  You can add parameters to $type divided by ":".
*/
function ValidateInput($variableName, $inputName, $variable, &$err, $needed=false, $type="all") {
    foreach ((array)$variable as $var) {
        $valid = _ValidateSingleInput($variableName, $inputName, $var, &$err, $needed, $type);
        if ( !$valid ) {
            break;
        }
    }
    return $valid;
}

define('VALIDATE_ERROR_BAD_TYPE',         400);
define('VALIDATE_ERROR_OUT_OF_RANGE',     401);
define('VALIDATE_ERROR_NOT_MATCH',        402);
define('VALIDATE_ERROR_BAD_PARAMETER',    403);
define('VALIDATE_ERROR_NOT_UNIQUE',       404);
define('VALIDATE_ERROR_TOO_LONG',         405);
define('VALIDATE_ERROR_TOO_SHORT',        406);
define('VALIDATE_ERROR_WRONG_CHARACTERS', 407);

/** AA user input validation class
 *  Ussage: AA_Validate::integer();
 */
class AA_Validate {

    /** Method returns or sets last itemContent error
     *  The trick for static class variables is used */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** Return last error message - it is grabbed from static variable
     *  of lastErr() method */
    function lastErrMsg() {
        return AA_Validate::lastErr(null, null, true);
    }

    function _bad(&$var, $err_id, $err_msg, $default) {
        AA_Validate::lastErr($err_id, $err_msg);
        if ( $default != 'AA_noDefault' ) {
            $var = $default;
        }
        return false;
    }

    /** Test for integer value
     *  @param   $param['min']
     *  @param   $param['max']
     */
    function integer(&$var, $param=array(), $default='AA_noDefault') {
        if ((string)$var !== (string)(int)$var) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_BAD_TYPE, _m('No integer value'), $default);
        }
        $var = (int)$var;
        if ( isset($param['max']) AND ($var > $param['max']) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $default);
        }
        if ( isset($param['min']) AND ($var < $param['min']) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $default);
        }
        return true;
    }

    /** Test for decimal value
     *  @param   $param['min']
     *  @param   $param['max']
     */
    function float(&$var, $param=array(), $default='AA_noDefault') {
        if ( !is_float($var) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_BAD_TYPE, _m('No float value'), $default);
        }
        $var = (float)$var;
        if ( isset($param['max']) AND ($var > $param['max']) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $default);
        } elseif (isset($param['min']) AND ($var < $param['min']) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $default);
        }
        return true;
    }

    function _regexp(&$var, $pattern, $default, $err_id=null, $err_msg=null) {
        $err_id  = is_null($err_id) ? VALIDATE_ERROR_NOT_MATCH : $err_id;
        $err_msg = is_null($err_msg) ? _m('Wrong value')       : $err_msg;

        return preg_match($pattern, $var) ? true : AA_Validate::_bad($var, $err_id, $err_msg, $default);
    }

    function long_id(&$var, $param=array(), $default='AA_noDefault') {
        return AA_Validate::_regexp($var, '/^[0-9a-f]{30,32}$/', $default);
    }

    function short_id(&$var, $param=array(), $default='AA_noDefault') {
        return AA_Validate::integer($var, $param, $default);
    }

    function alias(&$var, $param=array(), $default='AA_noDefault') {
        return AA_Validate::_regexp($var, '/^_#[0-9_#a-zA-Z]{8}$/', $default);
    }

    /** Email validation
     *  @todo the regexp should be improved
     */
    function email(&$var, $param=array(), $default='AA_noDefault') {
        // should be improved
        return AA_Validate::_regexp($var, '/^.+@.+\..+$/', $default);
    }

    function login(&$var, $param=array(), $default='AA_noDefault') {
        $len = strlen($var);
        if ( $len<3 ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $default);
        }
        if ( $len>32 ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_TOO_LONG, _m('Too long'), $default);
        }
        return AA_Validate::_regexp($var, '/^[a-zA-Z0-9]*$/', $default, VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters - you should use a-z, A-Z and 0-9 characters"));
    }

    function password(&$var, $param=array(), $default='AA_noDefault') {
        $len = strlen($var);
        if ( $len<5 ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $default);
        }
        if ( $len>32 ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_TOO_LONG, _m('Too long'), $default);
        }
        return true;
    }

    function filename(&$var, $param=array(), $default='AA_noDefault') {
        return AA_Validate::_regexp($var, '/^[-.0-9a-zA-Z_]+$/', $default, VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters - you should use a-z, A-Z, 0-9 . _ and - characters"));
    }

    /** Test for unique value in slice/database
     *  @param   $param['field_id']
     *  @param   $param['scope']       - username | slice | allslices
     */
    function unique(&$var, $param=array(), $default='AA_noDefault') {
        if ( !isset($param['scope'] )) {
            $param['scope'] = 'slice';
        }
        if ( !AA_Fields::isField($param['field_id']) ) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_BAD_PARAMETER, _m('Wrong parameter field_id for unique check'), $default);
        }
        global $slice_id;

        if ( $param['scope'] == 'username') {
            if ( !IsUsernameFree($var) ) {
                return AA_Validate::_bad($var, VALIDATE_ERROR_NOT_UNIQUE, _m('Username is not unique'), $default);
            }
            return true;
        }
        if ( $param['scope'] == 'slice') {
            $SQL = "SELECT * FROM content INNER JOIN item ON content.item_id = item.id
                    WHERE item.slice_id='".q_pack_id($slice_id)."'
                        AND field_id='".addslashes($field_id)."'
                        AND text='$var'";
        } else {
            $SQL = "SELECT * FROM content WHERE field_id='".addslashes($field_id) ."' AND text='$var'";
        }
        if (GetTable2Array($SQL, 'aa_first', 'aa_mark')) {
            return AA_Validate::_bad($var, VALIDATE_ERROR_NOT_UNIQUE, _m('Not unique - value already used'), $default);
        }
        return true;
    }

    function e_unique(&$var, $param=array(), $default='AA_noDefault') {
        if ( !AA_Validate::email($var, $param, $default )) {
            return false;
        }
        return AA_Validate::unique($var, $param, $default);
    }

    /** @todo url check */
    function url(&$var, $param=array(), $default='AA_noDefault') {
        return true;
    }
}


/**
*  Validate users input. Error is reported in $err array
*  @param $variable is not array
*  You can add parameters to $type divided by ":".
*/
function _ValidateSingleInput($variableName, $inputName, $variable, &$err, $needed, $type) {
    if ($variable=="" OR chop($variable)=="") {
        if ( $needed ) {                     // NOT NULL
            $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("it must be filled").")");
            return false;
        } else {
            return true;
        }
    }

    if (strchr ($type, ":")) {
        $params = substr($type, strpos($type,":")+1);
        $type   = substr($type, 0, strpos ($type,":"));
    }

    // empty values for 'alias' and 'id' types
    if (($type == 'alias') OR ($type == 'id')) {
        if ((string)$variable=="0" AND !$needed) {
            return true;
        }
    }
    switch ($type) {
        case 'alias':    $ret = AA_Validate::alias($variable); break;
        case 'id':       $ret = AA_Validate::long_id($variable); break;
        case 'integer':
        case 'number':   $ret = AA_Validate::integer($variable); break;
        case 'email':    $ret = AA_Validate::email($variable); break;
        case 'login':    $ret = AA_Validate::login($variable); break;
        case 'password': $ret = AA_Validate::password($variable); break;
        case 'filename': $ret = AA_Validate::filename($variable); break;
        case 'e-unique':
        case 'unique':
                         list($field_id, $scope) = explode(":", $params);
                         $UNIQUE_SCOPES               = array ( 0 => 'username',
                                                                1 => 'slice',
                                                                2 => 'allslices'
                                                               );
                         $validate_params['scope']    = $UNIQUE_SCOPES[(int)$scope];
                         $validate_params['field_id'] = $field_id;
                         if ( $type == 'unique' ) {
                             $ret = AA_Validate::unique($variable, $validate_params);
                         } else {
                             $ret = AA_Validate::e_unique($variable, $validate_params);
                         }
                         break;
        case 'url':      $ret = AA_Validate::url($variable); break;
        case 'all':
        default:         $ret = true;
    }
    if (!$ret) {
        $err["$variableName"] = MsgErr(_m("Error in")." $inputName - ". AA_Validate::lastErrMsg());
    }
    return $ret;
}

/**
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

