<?php
/**
 * Class AA_Validate
 *
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;

/** ValidateInput function
 *  Validate users input. Error is reported in $err array
 * @param $variableName
 * @param $inputName
 * @param string|array $variable could be array or not
 * @param $err
 * @param $needed
 * @param $validator (object of simple string)
 * @return bool
 */
function ValidateInput($variableName, $inputName, $variable, &$err, $needed=false, $validator="all") {
    foreach ((array)$variable as $var) {
        $valid = _ValidateSingleInput($variableName, $inputName, $var, $err, $needed, $validator);
        if ( !$valid ) {
            break;
        }
    }
    return $valid;
}

const VALIDATE_ERROR_BAD_TYPE         = 400;
const VALIDATE_ERROR_BAD_VALIDATOR    = 401;
const VALIDATE_ERROR_OUT_OF_RANGE     = 402;
const VALIDATE_ERROR_NOT_MATCH        = 403;
const VALIDATE_ERROR_BAD_PARAMETER    = 404;
const VALIDATE_ERROR_NOT_UNIQUE       = 405;
const VALIDATE_ERROR_TOO_LONG         = 406;
const VALIDATE_ERROR_TOO_SHORT        = 407;
const VALIDATE_ERROR_WRONG_CHARACTERS = 408;
const VALIDATE_ERROR_NOT_IN_LIST      = 409;

const VALIDATE_ERROR_NOT_FILLED = 410;

/** AA user input validation class
 *  usage (for standard validators):
 *      if ( AA_Validate::doValidate($variable, 'int') ) {...};
 *      if ( AA_Validate::doValidate($variable, array('int', array('min'=>0, 'max'=>10)) ) {...};
 */
class AA_Validate extends AA_Serializable {

    use \AA\Util\LastErrTrait;

    /** factoryCached function
     *  Returns validators for standard data types
     * @param string|array $v_type - string, or array($type,$parameter)
     * @return AA_Validate|null
     */
    static function factoryCached($v_type): ? AA_Validate {
        static $standard_validators = [];

        [$type, $parameters] = is_array($v_type) ? $v_type : [$v_type, []];

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
                case 'alpha':    $standard_validators[$sv_key] = new AA_Validate_Regexp(['pattern'=>'/^[a-zA-Z]+$/']);          break;
                case 'id':
                case 'long_id':  $standard_validators[$sv_key] = new AA_Validate_Id();    break;  // empty = "" or "0"
                case 'short_id': $standard_validators[$sv_key] = new AA_Validate_Number(['min'=>0]);           break;
                case 'alias':    $standard_validators[$sv_key] = new AA_Validate_Regexp(['pattern'=>'/^[_X]#[0-9_#a-zA-Z]{8}$/']); break;
                case 'filename': $standard_validators[$sv_key] = new AA_Validate_Regexp(['pattern'=>'/^[-.0-9a-zA-Z_]+$/']); break;
                case 'regexp':   $standard_validators[$sv_key] = new AA_Validate_Regexp($parameters); break;
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
     *      if ( AA_Validate::doValidate($variable, 'email') ) {...};
     *      if ( AA_Validate::doValidate($variable, array('int', array('min'=>0, 'max'=>10)) ) {...};
     * @param $var
     * @param $type
     * @return bool
     */
    public static function doValidate(&$var, $type) {
        $validator = self::factoryCached($type);
        if ( is_null( $validator ) ) {
            return AA_Validate::bad(VALIDATE_ERROR_BAD_VALIDATOR, _m('Bad validator type: %1', [$type]));
        }
        return $validator->validate($var);
    }

    /** filter function - returns array of values matching the criteria
     *      AA_Validate::filter(array('my@mail.cz','your@mail.cz'), 'email')
     *      AA_Validate::filter($vararray, array('int', array('min'=>0, 'max'=>10))
     * @param $var
     * @param $type
     * @return array
     */
    public static function filter($vararray, $type) {
        return array_filter((array)$vararray, [self::factoryCached($type),'validate']);
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool { return true; }

    /** checks if the variable is empty
     * @param $var
     * @return bool
     */
    function varempty($var) : bool {
        return strlen(trim($var))==0;
    }

    /** bad function
     *  Protected static method - used only from AA_Validate_* objects
     * @param $err_id
     * @param $err_msg
     * @param string $var_id
     * @return bool
     */
    protected static function bad($err_id, $err_msg, string $var_id = '') : bool {
        self::lastErr($err_id, $var_id ? ($err_msg." [$var_id]") : $err_msg);
        return false;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some more attributes (like min, max, step, pattern, ...) */
    public function getHtmlInputAttr() {
        return [];
    }

}

/** Test for integer value
 *  @param   $min
 *  @param   $max
 *  @param   $step
 */
class AA_Validate_Signed extends AA_Validate {
    /** Minimum number */
    var $min;

    /** Maximum number */
    var $max;

    /** Step */
    var $step;

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     * @return array
     */
    static function getClassProperties(): array {
        return [
            //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'min'  => new AA_Property('min', _m("Alloved minimum value"), 'int', false, true, 'int', false, _m(""), '', 1),
            'max'  => new AA_Property('max', _m("Alloved maximum value"), 'int', false, true, 'int', false, _m(""), '', 12),
            'step' => new AA_Property('step', _m("Step"), 'int', false, true, 'int', false, _m(""), '', 1),
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if (!IsSigInt($var)) {
            return AA_Validate::bad(VALIDATE_ERROR_BAD_TYPE, _m('No integer value') . "'$var'", $var_id);
        }
        $var = (int)$var;
        if (IsSigInt($this->max) and ($var > $this->max)) {
            return AA_Validate::bad(VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $var_id);
        }
        if (IsSigInt($this->min) and ($var < $this->min)) {
            return AA_Validate::bad(VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $var_id);
        }
        return true;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        $ret = ['type' => 'number', 'pattern' => '-?[0-9]*'];
        if (IsSigInt($this->min)) {
            $ret['min'] = $this->min;
        }
        if (IsSigInt($this->max)) {
            $ret['max'] = $this->max;
        }
        if (IsSigInt($this->step) and ($this->step > 1)) {
            $ret['step'] = $this->step;
        }
        return $ret;
    }
}

/** Test for integer value
 *  @param   $min
 *  @param   $max
 *  @param   $step
 */
class AA_Validate_Number extends AA_Validate_Signed {
    function __construct($param= []) {
        parent::__construct($param);
        $this->min = 0;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        $ret = parent::getHtmlInputAttr();
        $ret['pattern']='?[0-9]*';
        return $ret;
    }
}


/** Test for bool value
 */
class AA_Validate_Bool extends AA_Validate_Number {
    function __construct($param= []) {
        parent::__construct($param);
        $this->min = 0;
        $this->max = 1;
    }
}

/** Test for date value (could be negative)
 */
class AA_Validate_Date extends AA_Validate_Signed {
}

/** Test for Regular Expression
 *  @param   $pattern
 *  @param   $empty_expression
 *  @param   $maxlength
 */
class AA_Validate_Regexp extends AA_Validate {
    /** Regular Expression */
    var $pattern;
    var $empty_expression = '/^\s*$/';
    var $maxlength;

    static function getClassProperties(): array {
        return [                      //           id            name              type    multi  persist validator, required, help, morehelp, example
            'pattern'          => new AA_Property( 'pattern',           _m("Regular expression"), 'string', false, true, 'string', false, _m(""), '', '/^[a-z]*$/'),
            'empty_expression' => new AA_Property( 'empty_expression',  _m("Empty expression"),   'string', false, true, 'string', false, _m(""), '', '/^(0|\s*)$/'),
            'maxlength'        => new AA_Property( 'maxlength',         _m("Maximum length"),     'int',    false, true, 'int',    false, _m(""), '', '15')
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if ( ($this->maxlength > 0) AND (strlen($var) > $this->maxlength) ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_LONG, _m('Too long'), $var_id);
        }
        if ( strlen($this->pattern) < 3 ) {
            return true;
        }
        return  preg_match($this->pattern, $var) ? true : AA_Validate::bad(VALIDATE_ERROR_OUT_OF_RANGE, _m('Do not match the pattern'), $var_id);
    }

    /** checks if the variable is empty
     * @param $var
     * @return bool
     */
    public function varempty($var) : bool {
        return preg_match($this->empty_expression, $var);
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        $ret = ['type'=>'text'];
        if ($this->maxlength > 0)                               { $ret['maxlength']  = (int)$this->maxlength; }
        if (($this->maxlength > 0) AND ($this->maxlength < 60)) { $ret['size']       = ((int)$this->maxlength+2); }
        if (strlen($this->pattern) > 2)                         { $ret['pattern']    = substr($this->pattern, 1, -1); } // we need to convert /^[a-z]*$/ to ^[a-z]*$
        return $ret;
    }
}

/** Test for url
 */
class AA_Validate_Url extends AA_Validate_Regexp {
    function __construct($param= []) {
        parent::__construct($param);
        $this->pattern          = '|^http(s?):\/\/\S+\.\S+|';
        $this->empty_expression = '~(^http(s?):\/\/$)|(^\s*$)~';
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=> 'url', 'pattern'=>"^http(s?):\/\/\S+\.\S+"];
    }
}

/** Test for email
 */
class AA_Validate_Email extends AA_Validate_Regexp {
    function __construct($param= []) {
        parent::__construct($param);
        $this->pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,6}$/';
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=>'email'];
    }
}


/** Test for long item id
 */
class AA_Validate_Id extends AA_Validate {

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=>'text', 'pattern'=>'[0-9a-f]{32}'];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if (!is_long_id($var)) {
            return AA_Validate::bad(VALIDATE_ERROR_BAD_TYPE, _m('No ID value'), $var_id);
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
    var $f_min;

    /** Maximum number */
    var $f_max;

    static function getClassProperties(): array {
        return [                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'f_min'  => new AA_Property( 'f_min',  _m("Alloved minimum value"), 'float', false, true, 'float', false, _m(""), '', '1.0'),
            'f_max'  => new AA_Property( 'f_max',  _m("Alloved maximum value"), 'float', false, true, 'float', false, _m(""), '', '1000.0'),
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if ( !is_float($var) ) {
            return AA_Validate::bad(VALIDATE_ERROR_BAD_TYPE, _m('No float value'), $var_id);
        }
        $var = (float)$var;
        if ( !is_null($this->f_max) AND ($var > $this->f_max) ) {
            return AA_Validate::bad(VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too big'), $var_id);
        }
        if ( !is_null($this->f_min) AND ($var < $this->f_min) ) {
            return AA_Validate::bad(VALIDATE_ERROR_OUT_OF_RANGE, _m('Out of range - too small'), $var_id);
        }
        return true;
    }
}

class AA_Validate_Enum extends AA_Validate {
    /** Enumeration array (array of possible values). Values are stored as keys. */
    var $possible_values;

    static function getClassProperties(): array {
        return [                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'possible_values' => new AA_Property( 'possible_values', _m("Possible values"), 'string', true, true, 'string', false)
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        return isset($this->possible_values[$var]) ? true : AA_Validate::bad(VALIDATE_ERROR_NOT_IN_LIST, _m('Out of range - not in the list'), $var_id);
    }
}

/** Test for login name value */
class AA_Validate_Login extends AA_Validate {

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        $len = strlen($var);
        if ( $len<3 ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $var_id);
        }
        if ( $len>32 ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_LONG, _m('Too long'), $var_id);
        }
        return preg_match('/^[a-zA-Z0-9]*$/', $var) ? true : AA_Validate::bad(VALIDATE_ERROR_WRONG_CHARACTERS, _m("Wrong characters - you should use a-z, A-Z and 0-9 characters"), $var_id);
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=>'text', 'pattern'=>'[a-zA-Z0-9]{3,32}'];
    }
}

/** Test for password */
class AA_Validate_Pwd extends AA_Validate {

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        $len = strlen($var);
        if ( $len<5 ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_SHORT, _m('Too short'), $var_id);
        }
        if ( $len>255 ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_LONG, _m('Too long'), $var_id);
        }
        return true;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=>'password', 'min'=>'5'];
    }
}

/** Test for unique value in slice/database
 *  @param   $field_id
 *  @param   $scope       - username | slice | allslices
 *  @param   $item_id     - current item ID (most probably is passed by content_context, instead of this param)
 */
class AA_Validate_Unique extends AA_Validate {
    /** Search in which field */
    var $field_id;

    /** Scope, where to search - username | slice | allslices */
    var $scope = 'slice';

    /** Item, which we do not count (current item) */
    var $item_id;

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     * @return array
     */
    static function getClassProperties(): array {
        return [                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'field_id' => new AA_Property( 'field_id', _m("Field id"), 'string', false, true, 'string', false, _m(""), '', ''),
            'scope'    => new AA_Property( 'scope',    _m("Scope"),    'string', false, true, 'string', false, _m("username | slice | allslices"), '', 'slice'),  // or 0 | 1 | 2 for field setting
            'item_id'  => new AA_Property( 'item_id' , _m("Item id which we do not count"), 'string', false, true, 'string', false),
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        switch ($this->scope) {  // for older approach - presented in field setting
            case '':
            case '0': $this->scope = 'username';  break;
            case '1': $this->scope = 'slice';     break;
            case '2': $this->scope = 'allslices'; break;
        }

        if ( $this->scope == 'username') {
            if ( !AA::$perm->isUsernameFree($var) AND ( !$this->item_id OR (AA_Reader::name2Id($var) != $this->item_id))) {
                return AA_Validate::bad(VALIDATE_ERROR_NOT_UNIQUE, _m('Username is not unique'), $var_id);
            }
            return true;
        }

        $field_to_check = $this->field_id ?: $var_id;
        if (!$field_to_check) {
            return AA_Validate::bad(VALIDATE_ERROR_BAD_PARAMETER, _m('Wrong parameter field_id for unique check'), $var_id);
        }
        $tables = ['content', 'item'];
        $conds  = [['content.item_id', 'item.id', 'j'], ['item.status_code',1]];  // only approved items
        if ( $this->scope == 'slice') {
            $slice = null;
            $slice_id = AA::$slice_id ?: AA::$module_id;   // AA\IO\Saver or itemedit.php tells us the current slice
            if ( !$slice_id OR !($slice = AA_Slice::getModule($slice_id)) OR !$slice->getField($field_to_check) ) {
                return AA_Validate::bad(VALIDATE_ERROR_BAD_PARAMETER, _m('Wrong parameter field_id for unique check'), $var_id);
            }
            $conds[] = ['item.slice_id', $slice_id, 'l'];
        }
        $conds[] = ['content.field_id', $field_to_check];
        $conds[] = ['content.text', $var];

        $iid = $this->item_id ?: ($content_context ? $content_context->getId() : '');
        if ($iid) {
            $conds[] = ['item.id', $iid, 'l<>'];
        }
        if (DB_AA::test($tables, $conds)) {
            return AA_Validate::bad(VALIDATE_ERROR_NOT_UNIQUE, _m('Not unique - value already used'), $var_id);
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

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_validate table)
     *  copied from $VALIDATE_TYPES
     * @return array
     */
    static function getClassProperties(): array {
        return [                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'field_id' => new AA_Property( 'field_id', _m("Field id"), 'string', false, true, 'string', false, _m(""), '', ''),
            'scope'    => new AA_Property( 'scope',    _m("Scope"),    'string', false, true, 'string', false, _m("username | slice | <b>allslices</b>"), '', 'slice'),  // or 0 | 1 | 2 for field setting
            'item_id'  => new AA_Property( 'item_id' , _m("Item id whichh we do not count"), 'string', false, true, 'string', false),
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if ( !AA_Validate::doValidate($var, 'email') ) {
            return false;
        }

        // here we use default username if the this fields is username (field is headline........) OR allslices if not (in old ParamWizard we use "Slice only" boolean)
        $scope =  $this->scope ?: (($var_id == FIELDID_USERNAME) ? 'username' : 'allslices');
        $validator = new AA_Validate_Unique(['field_id' => $this->field_id, 'scope' => $scope, 'item_id' => $this->item_id]);
        return $validator->validate($var, $content_context, $var_id);
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        return ['type'=>'email'];
    }
}

/** Test for text (any characters allowed)
 */
class AA_Validate_Text extends AA_Validate {
    var $maxlength;

    static function getClassProperties(): array {
        return [                      //           id            name              type    multi  persist validator, required, help, morehelp, example
            'maxlength'        => new AA_Property( 'maxlength',         _m("Maximum length"),     'int',    false, true, 'int',    false, _m(""), '', '15')
        ];
    }

    /** validate function
     * @param string          $var
     * @param AA_Content|null $content_context
     * @param string          $var_id
     * @return bool
     */
    public function validate(string &$var, AA_Content $content_context = null, string $var_id = ''): bool {
        if ( ($this->maxlength > 0) AND (strlen($var) > $this->maxlength) ) {
            return AA_Validate::bad(VALIDATE_ERROR_TOO_LONG, _m('Too long'), $var_id);
        }
        return true;
    }

    /** returns the type attribute for the HTML 5 <input> tag with possible some
     *  more attributtes (like min, max, step, pattern, ...)
     */
    function getHtmlInputAttr() {
        $ret = ['type'=>'text'];
        if ($this->maxlength > 0)                               { $ret['maxlength']  = (int)$this->maxlength; }
        if (($this->maxlength > 0) AND ($this->maxlength < 60)) { $ret['size']       = ((int)$this->maxlength+2); }
        return $ret;
    }
}


/** _ValidateSingleInput function
 *  Validate users input. Error is reported in $err array
 * @param $variableName
 * @param $inputName
 * @param string $variable is not array
 * @param $err
 * @param $needed
 * @param $validator (object of simple string)
 *  You can add parameters to $type divided by ":".
 * @return bool
 */
function _ValidateSingleInput($variableName, $inputName, $variable, &$err, $needed, $validator) {

    if (is_string($validator)) {
       $validator = AA_Validate::factoryCached($validator);
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
* used in tabledit.php and itemedit.php3
*/
function get_javascript_field_validation() {
    /* javascript params:
       myform = the form object
       txtfield = field name in the form
       type = validation type
       add = is it an "add" form, i.e. showing a new item?
    */
    return /** @lang JavaScript */ "
        function validate (myform, txtfield, type, required, add) {
            var invalid_email = /(@.*@)|(\\.\\.)|(@\\.)|(\\.@)|(^\\.)/;
            var valid_email = /^.+@[a-zA-Z0-9\\-\\.]+\\.([a-zA-Z]{2,6}|[0-9]{1,3})$/;

            if (type == 'pwd') {
                myfield = myform[txtfield+'a'];
                myfield2 = myform[txtfield+'b'];
            } else {
                myfield = myform[txtfield];
            }

            if (myfield == null) {
                return true;
            }

            var val = myfield.value;
            var err = '';

            if (val == '' && required && (type != 'pwd' || add == 1)) {
                err = (type == 'pwd') ? '" . _m("This field is required.") . "' : '" . _m("This field is required (marked by *).") . "';
            } else if (val == '') { 
                return true;
            }
            
            switch (type) {
            case 'number':
                if (!val.match (/^[0-9]+$/))        { err = '" . _m("Not a valid integer number.") . "'; }
                break;
            case 'filename':
                if (!val.match (/^[0-9a-zA-Z_]+$/)) { err = '" . _m("Not a valid file name.") . "';  }
                break;
            case 'email':
            case 'e-mail':
                if (val.match(invalid_email) || !val.match(valid_email)) { err = '" . _m("Not a valid email address.") . "'; }
                break;
            case 'pwd':
                if (val && val != myfield2.value)   { err = '" . _m("The two password copies differ.") . "';  }
                break;
            }

            if (err != '') {
                alert (err);
                myfield.focus();
                return false;
            }
            return true;
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
        [$username,$domain]=explode('@',$email);
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
                NEW_get_lines($fp);
                fwrite($fp, 'ehlo ecn.cz'."\r\n");
                NEW_get_lines($fp);
                fwrite($fp, 'mail from: <>'."\r\n");
                NEW_get_lines($fp);
                fwrite($fp, 'rcpt to: <'.$email.'>'."\r\n");
                $odpoved = NEW_get_lines($fp);
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


