<?php
/**
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;

require_once __DIR__."/varset.php3";
require_once __DIR__."/notify.php3";
require_once __DIR__."/imagefunc.php3";
require_once __DIR__."/date.php3";
require_once __DIR__."/item_content.php3";
require_once __DIR__."/event.class.php3";

if ( !is_object($event) ) {
    $event = new aaevent;   // not defined in scripts which do not include init_page.php3 (like offline.php3)
}


/** classes for default values of the fields
 *  derived from  AA_Serializable in order to be able to factory from string
 *
 *  Usage: $aa_value = AA_Generator::factoryByString('dte:5000')->generate();
 *          $string   = AA_Generator::factoryByString('uid')->generate()->getValue();
*/


abstract class AA_Generator extends AA_Serializable implements AA\Util\NamedInterface {

    public function name(): string        { return _m('');  }      // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    /**
     * @return AA_Value
     */
    abstract function generate();
}

/** AA_Generator_Now - current timestamp */
class AA_Generator_Now extends AA_Generator {

    /** Name of the component for selection */
    public function name(): string { return _m("Now, i.e. current date");  }

    /** generate() - main function for generating the value */
    function generate()    { return new AA_Value(now()); }
}

/** AA_Generator_Never - the biggest date possible */
class AA_Generator_Never extends AA_Generator {

    /** Name of the component for selection */
    public function name(): string { return _m("Never, i.e. maximum date");  }

    /** generate() - main function for generating the value */
    function generate()    { return new AA_Value(2145826800); }
}


/** AA_Generator_Uid - User ID */
class AA_Generator_Uid extends AA_Generator {

    /** Name of the component for selection */
    public function name(): string { return _m("User ID");  }

    /** generate() - main function for generating the value */
    function generate() {
        global $auth;                                  // 9999999999 for anonymous
        return new AA_Value(isset($auth) ? $auth->auth["uid"] : "9999999999");
    }
}

/** AA_Generator_Log - Login name */
class AA_Generator_Log extends AA_Generator {

    /** Name of the component for selection */
    public function name(): string { return _m("Login name");  }

    /** generate() - main function for generating the value */
    function generate() {
        global $auth;                                  // "anonymous" for anonymous
        return new AA_Value(isset($auth) ? $auth->auth["uname"] : "anonymous");
    }
}

/** AA_Generator_Dte - Date + 'Parameter' days */
class AA_Generator_Dte extends AA_Generator {

    protected $plusdays;         // see getClassProperties

    /** Name of the component for selection */
    public function name(): string {  return _m("Date + 'Parameter' days");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {
        return [             //           id         name                        type    multi  persist validator, required, help, morehelp, example
            'plusdays' => new AA_Property( 'plusdays',  _m("Number of days"), 'int', false, true, 'int', false, '', '', '365')
        ];
    }

    /** generate() value of currrent timestamp */    // checks maximum date bounds
    function generate()    {  return new AA_Value(min(2145826800, mktime(0,0,0,date("m"),date("d")+(int)$this->plusdays,date("Y"))));  }
}

/** AA_Generator_Txt - Text from 'Parameter' */
class AA_Generator_Txt extends AA_Generator {

    protected $text;         // see getClassProperties

    /** Name of the component for selection */
    public function name(): string {  return _m("Text from 'Parameter'");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {
        return [      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text' )
        ];
    }

    /** generate() - main function for generating the value */
    function generate() {
        return strlen($this->text) ? new AA_Value($this->text) : new AA_Value();
    }
}

/** AA_Generator_Qte - only for backward complatibility. The same as Txt */
class AA_Generator_Qte extends AA_Generator_Txt {
    /** Name of the component for selection */
    public function name(): string {  return _m('');  }
}




/** AA_Generator_Rnd - Random string */
class AA_Generator_Rnd extends AA_Generator {

    protected $length;         // see getClassProperties
    protected $checkfield;     // see getClassProperties
    protected $wheretocheck;   // see getClassProperties

    /** Name of the component for selection */
    public function name(): string        {  return _m("Random string");  }
    /** Decription  of the component for selection */
    public function description(): string {  return _m("Random alphanumeric [A-Z0-9] string.");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'length'       => new AA_Property( 'length      ',  _m("String length"),  'int',    false, true, 'int', false, '', '', '5'),
            'checkfield'   => new AA_Property( 'checkfield  ',  _m("Field to check"), 'string', false, true, 'field', false, _m("If you need a unique code, you must send the field ID, the function will then look into this field to ensure uniqueness."), '', 'unspecified.....'),
            'wheretocheck' => new AA_Property( 'wheretocheck',  _m("Slice only"),     'bool',   false, true, 'bool', false, _m("Do you want to check for uniqueness this slice only or all slices?"))
        ];
    }

    /** generate() - main function for generating the value */
    function generate() {
        global $slice_id;

        $len        = $this->length ?: 5;   // default is 5
        $field_id   = $this->checkfield;
        $slice_only = is_numeric($this->wheretocheck) ? $this->wheretocheck : true;

        if (strlen($field_id) != 16) {
            return new AA_Value(gensalt($len));
        }

        $rec = false;
        do {
            $randstring = gensalt($len);
            if ($slice_only) {
                $rec = DB_AA::select1('', 'SELECT item_id FROM content INNER JOIN item ON content.item_id = item.id', [
                    ['item.slice_id', $slice_id, 'l'],
                    ['field_id', $field_id],
                    ['text', $randstring]
                ]);
            } else {
                $rec = DB_AA::select1('', 'SELECT item_id FROM content', [
                    ['field_id', $field_id],
                    ['text', $randstring]
                ]);
            }
        } while ($rec);
        return new AA_Value($randstring);
    }
}

/** AA_Generator_Variable - AA Expression */
class AA_Generator_Variable extends AA_Generator {

    protected $text;         // see getClassProperties

    /** Name of the component for selection */
    public function name(): string        {  return _m("AA Expression");  }
    /** Decription  of the component for selection */
    public function description(): string {  return _m("any text with possible {AA expressions} like: {date:Y}");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {
        return [      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text', false, true, 'text', false, '', '', '{date:Y}')
        ];
    }

    /** getClassProperties function of AA_Serializable  */
    function generate() {
        return new AA_Value(AA::Stringexpander()->unalias($this->text));
    }
}

/** AA_Generator_Mul - Multivalues */
class AA_Generator_Mul extends AA_Generator {

    protected $text;               // see getClassProperties
    protected $delimiter;          // see getClassProperties

    /** Name of the component for selection */
    public function name(): string        {  return _m("Multivalue");  }

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {
        return [      //           id         name     type                  multi  persist validator, required, help, morehelp, example
            'text'      => new AA_Property( 'text',       _m("Text"),      'text',   false, true, 'text', false, '', '', 'red|green|blue'),
            'delimiter' => new AA_Property( 'delimiter',  _m("Delimiter"), 'string', false, true, 'text', false, '', '', '|')
        ];
    }

    /** getClassProperties function of AA_Serializable  */
    function generate() {
        return new AA_Value(array_filter(explode(($this->delimiter ?: '|'), $this->text),'strlen'));
    }
}

// ----------------------- insert functions ------------------------------------
/** classes for insert data to the database field
 *  derived from  AA_Serializable in order to be able to factory from string
 *
 *  Usage: $aa_value = AA_Generator::factoryByString('dte:5000')->generate();
 *          $string   = AA_Generator::factoryByString('uid')->generate()->getValue();
 * @method  static AA_Inserter factoryByString($string)
 */
abstract class AA_Inserter extends AA_Serializable implements \AA\Util\NamedInterface {

    /** @var AA_Field */
    protected $field;         // additional variable common to all AA_Inserters - not filled by standard factoryByString

    public function name(): string        { return _m('');  }      // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    // __construct()  // we use AA_Serializable::factoryByString + post initiation for $field

    /** preffered way of construction
     * @param AA_Field $field
     * @return AA_Inserter
     */
    public static function factoryByField($field) : AA_Inserter {
        $definition = $field->getProperty('input_insert_func');
        $inserter = AA_Inserter::factoryByString($definition);
        if (!$inserter) {
            warn("Can't create AA_Inserter from <b>$definition</b>");
            $inserter = AA_Inserter::factoryByString('nul');
        }
        $inserter->setField($field);
        return $inserter;
    }

    /**
     * @param AA_Field $field
     * @return AA_Inserter
     */
    public function setField($field) {
        $this->field = $field;
        return $this;
    }

    /** deletes content for field in database
     * @param $item_id
     * @param $field_id
     */
    protected function _clear_field($item_id, $field_id) {
        // delete content just for displayed fields
        DB_AA::delete('content', [['item_id', $item_id, 'l'], ['field_id', $field_id]]);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return false;
    }

    /**
     * @param AA_Field_Writer $fieldwriter
     * @param string          $item_id
     * @param array           $value
     * @param array          $additional
     */
    public function execute($fieldwriter, $item_id, $value, $additional= []) {

        $varset = new Cvarset();
        // if input function is 'selectbox with presets' and add2connstant flag is set,
        // store filled value to constants
        $fnc = ParseFnc($this->field->getProperty("input_show_func"));   // input show function
        if ($fnc AND ($fnc['fnc']=='pre')) {
            // get add2constant and constgroup (other parameters are irrelevant in here)
            [$constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding, $secondfield, $add2constant] = explode(':', $fnc['param']);
            // add2constant is used in $this->_store - adds new value to constant table
            if ($add2constant AND $constgroup AND (substr($constgroup,0,7) != "#sLiCe-") AND strlen(trim($value['value']))) {
                $db = getDB();
                // does this constant already exist?
                $constgroup = quote($constgroup);
                $constvalue = quote($value['value']);
                $SQL = "SELECT * FROM constant WHERE group_id='$constgroup' AND value='$constvalue'";
                $db->query($SQL);
                if (!$db->next_record()) {
                    // constant is not in database yet => add it

                    // first we have to get max priority in order we can add new constant
                    // with bigger number
                    $SQL = "SELECT max(pri) as max_pri FROM constant WHERE group_id='$constgroup'";
                    $db->query($SQL);
                    $new_pri = ($db->next_record() ? $db->f('max_pri') + 10 : 1000);

                    // we have priority - we can add
                    $varset->set("name",  $constvalue, 'quoted');
                    $varset->set("value", $constvalue, 'quoted');
                    $varset->set("pri",   $new_pri, "number");
                    $varset->set("id", new_id(), "unpacked" );
                    $varset->set("group_id", $constgroup, 'quoted' );
                    $varset->doInsert('constant');
                }
                freeDB($db);
            }
        }

        if ($fid = $this->field->getProperty("in_item_tbl")) {
            // Mitra thinks that this might want to be 'expiry_date.....' ...
            // ... which is not correct because in 'in_item_tbl' database field
            // we store REAL database field names from aadb.item table (honzam)
            if (($fid == 'expiry_date') AND (date("Hi",$value['value']) == "0000")) {
                // $value['value'] += 86399;
                // if time is not specified, take end of day 23:59:59
                // !!it is not working for daylight saving change days !!!
                $value['value'] = mktime(23,59,59,date("m",$value['value']),date("d",$value['value']),date("Y",$value['value']));
            }

            // field in item table  - check, if numeric...
            if (in_array($fid, ['short_id','status_code','post_date','publish_date','expiry_date','highlight','last_edit','display_count','disc_count','disc_app','moved2active'])) {
                $fieldwriter->getItemVarset()->add($fid, "number", (int)$value['value']);
            } else {
                $fieldwriter->getItemVarset()->add($fid, "text", $value['value']);
            }
            return;
        }

        $delete = $this->field->isSafeStored();  // safe stored fields are not erased

        // field in content table (function defined in util.php since we need it for display count
        StoreToContent($item_id, $this->field->getId(), $this->field->getProperty("text_stored"), $value, $additional, $delete);
    }
}

/** AA_Inserter_Qte - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Qte extends AA_Inserter {
    public function name(): string        { return _m("Text = don't modify");  }             // see NamedInterface
    public function description(): string { return _m("Does not modify the value.");  }      // see NamedInterface
}

/** AA_Inserter_Dte - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Dte extends AA_Inserter {
    public function name(): string        { return _m("Date = don't modify");  }             // see NamedInterface
    public function description(): string { return _m("Does not modify the value (just like Text), but it is better to separate it for future usage.");  }      // see NamedInterface
}

// not used. eventualy the same as Qte
// class AA_Inserter_Cns extends AA_Inserter {}

/** AA_Inserter_Num - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Num extends AA_Inserter {
    public function name(): string        { return _m("Number = don't modify");  }             // see NamedInterface
    public function description(): string { return _m("Does not modify the value (just like Text), but it is better to separate it for future usage.");  }      // see NamedInterface
}

/** AA_Inserter_Boo - 0 | 1 */
class AA_Inserter_Boo extends AA_Inserter {
    public function name(): string        { return _m("Boolean = store 0 or 1");  }             // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        $value['value'] = ( $value['value'] ? 1 : 0 );
        parent::execute($fieldwriter, $item_id, $value, $additional);
    }
}

/** AA_Inserter_Ids - Just insert string (so quote it before storing to the DB) */
class AA_Inserter_Ids extends AA_Inserter {
    public function name(): string        { return _m("Item IDs");  }             // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {

        $fid         = $this->field->getId();
        $text_stored = $this->field->getProperty("text_stored");

        $add_mode = substr($value['value'],0,1);          // x=add, y=add mutual, z=add backward
        if (strpos('txyz', $add_mode) !== false) {
            $value['value'] = substr($value['value'],1);  // remove x, y or z
        }
        switch( $add_mode ) {
            case 't':  // t for tags - it could be normal item ID or new Tag
                $v = $value['value'];
                if ( !is_long_id($v)) {
                    $fnc = ParseFnc($this->field->getProperty("input_show_func"));   // input show function
                    if ($fnc AND ($fnc['fnc']=='tag')) {
                        // get add2constant and constgroup (other parameters are irrelevant in here)
                        [$constgroup, $others] = explode(':', $fnc['param']);
                        // add2constant is used in $this->_store - adds new value to constant table
                        if ((substr($constgroup,0,7) == "#sLiCe-") AND strlen(trim($v))) {
                            $sid = substr($constgroup,7);
                            $content4id = new ItemContent();
                            $content4id->setItemID($new_id=new_id());
                            $content4id->setSliceID($sid);
                            $content4id->setAaValue('headline........', new AA_Value($v));
                            $content4id->complete4Insert();
                            $content4id->storeItem('insert');
                            $value['value'] = $new_id;
                        }
                    }
                }
                parent::execute($fieldwriter, $item_id, $value, $additional);
                break;
            case 'y':   // y means 2way related item id - we have to store it for both
                parent::execute($fieldwriter, $item_id, $value, $additional);
            // !!!!! there is no break or return - CONTINUE with 'z' case !!!!!

            case 'z':   // z means backward related item id - store it only backward
                // add reverse related
                $reverse_id     = $value['value'];
                $value['value'] = $item_id;

                // mimo added
                // get rid of empty dummy relations (text='')
                // this is only a problem for text content
                if ($text_stored) {
                    DB_AA::delete('content', [['item_id', $reverse_id, 'l'], ['field_id', $fid], ['text', '']]);
                }
                // is reverse relation already set?
                if (!DB_AA::test('content', [['item_id',$reverse_id, 'l'], ['field_id', $fid], [($text_stored ? "text" : "number"), $value['value']]])) { // not found
                    parent::execute($fieldwriter, $reverse_id, $value, $additional);
                }
                break;

            case 'x':   // just filling character - remove it
            default:
                parent::execute($fieldwriter, $item_id, $value, $additional);
        }
    }
}


/** AA_Inserter_Uid - User ID = always store current user ID */
class AA_Inserter_Uid extends AA_Inserter {
    public function name(): string        { return _m("User ID = always store current user ID");  }             // see NamedInterface
    public function description(): string { return _m('Inserts the identity of the current user, no matter what the user sets.');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        global $auth;

        if ( $value['value'] AND IsSuperadmin() ) {
            $val = $value['value'];
        } else {
            // if not $auth, it is from anonymous posting - 9999999999 is anonymous user
            $val = (isset($auth) ?  $auth->auth["uid"] : ((strlen($value['value'])>0) ? $value['value'] : "9999999999"));
        }
        parent::execute($fieldwriter, $item_id, ['value' => $val], $additional);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}

/** AA_Inserter_Log - Login name */
class AA_Inserter_Log extends AA_Inserter {
    public function name(): string        { return _m("Login name");  }             // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        global $auth;
        // if not $auth, it is from anonymous posting
        $val = (isset($auth) ?  $auth->auth["uname"] : ((strlen($value['value'])>0) ? $value['value'] : 'anonymous'));

        parent::execute($fieldwriter, $item_id, ['value' => $val], $additional);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}

/** AA_Inserter_Now - Now = always store current time */
class AA_Inserter_Now extends AA_Inserter {
    public function name(): string        { return _m("Now = always store current time");  }             // see NamedInterface
    public function description(): string { return _m('Inserts the current time, no matter what the user sets.');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        parent::execute($fieldwriter, $item_id, ["value"=>time()], $additional);
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}


/** AA_Inserter_Co2 - Computed field for INSERT/UPDATE */
class AA_Inserter_Co2 extends AA_Inserter {
    // we store it to the database at this time, even if it is probably
    // not final value for this field - we probably recompute this value later
    // in storeItem method, but we should compute with this new value there,
    // so we need to store it, right now
    // (this is the only case for computed field SHOWN IN INPUTFORM)

    protected $code;                   // see getClassProperties
    protected $code_update;            // see getClassProperties
    protected $delimiter;              // see getClassProperties
    protected $recompute;              // see getClassProperties

    public function name(): string        { return _m("Computed field for INSERT/UPDATE");  }             // see NamedInterface
    public function description(): string { return _m('The field is the result of expression written in "Code for unaliasing". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'       => new AA_Property( 'code',        _m("Code for unaliasing (INSERT)"),'text', false, true, 'text', false, _m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"), '', '({publish_date....}) {headline........}'),
            'code_update'=> new AA_Property( 'code_update', _m("Code for unaliasing (UPDATE)"),'text', false, true, 'text', false, _m("The same as above, but just for UPDATE operation. If unfilled, the value of the field stays unchanged"), '', ''),
            'delimiter'  => new AA_Property( 'delimiter',   _m("Multivalue delimiter"),        'text', false, true, 'text', false, _m("Character or string, which will split the computed string into multiple values (the same field)"), '', '|'),
            'recompute'  => new AA_Property( 'recompute',   _m("Recompute every"),             'text', false, true, 'text', false, "minute|hour|day|week|month "._m("The field will be recomputed every minute, hour, ... (using UPDATE code above). The times are not exact - the minute means something 1-10 minutes. If not filled, the field will be recomputed only on insert/update of the item."), '', '')
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;  // @todo - maybe better check - it could be generated, but who knows
    }
}

/** AA_Inserter_Com - Computed field*/
class AA_Inserter_Com extends AA_Inserter {

    protected $code;                   // see getClassProperties

    public function name(): string        { return _m("Computed field");  }             // see NamedInterface
    public function description(): string { return _m('The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'   => new AA_Property( 'code',        _m("Code for unaliasing (INSERT+UPDATE"),    'text', false, true, 'text', false, _m("There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"), '', '({publish_date....}) {headline........}')
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;  // @todo - maybe better check - it could be generated, but who knows
    }
}

/** AA_Inserter_Seo - SEO Name -  */
class AA_Inserter_Seo extends AA_Inserter {

    // the value computed in updateComputedFields

    protected $code;                   // see getClassProperties

    public function name(): string        { return _m("SEO Name");  }             // see NamedInterface
    public function description(): string { return _m('If the field is not filled, compute seoname from _#HEADLINE alias (or another if specified) unique to all slices in sitemodules, where the slice is mentioned in "Uses slices" settings. Basically the SEO Name alias is shortcut for comuted field with {ifset:{seo.............}:_#1:{seoname:{_#HEADLINE}:all:CHARSET}}');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'code'   => new AA_Property( 'code', _m("Alias to compute from"), 'text', false, true, 'text', false, _m("if not specified, the _#HEADLINE is used"), '', '_#HEADLINE')
        ];
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}

/** AA_Inserter_Seq */
class AA_Inserter_Seq extends AA_Inserter {
    public function name(): string        { return _m("Unique number for this field (from 1,..)");  }             // see NamedInterface
    public function description(): string { return _m('Unique number in sequence 1,2,3,...');  }      // see NamedInterface
}

/** AA_Inserter_Fil  - File = uploaded file
 */
// There are three cases here
// 1: uploaded - overwrites any existing value, does resampling etc
// 2: file name left over from existing record, just stores the value
// 3: newly entered URL, this is not distinguishable from case //2 so
//    its just stored, and no thumbnails etc generated, this could be
//    fixed later (mtira)
// in $additional are fields
class AA_Inserter_Fil extends AA_Inserter {

    protected $type;                   // see getClassProperties
    protected $width;                  // see getClassProperties
    protected $height;                 // see getClassProperties
    protected $otherfield;             // see getClassProperties
    protected $replacemethod;          // see getClassProperties
    protected $exact;                  // see getClassProperties
    protected $nametemplate;           // see getClassProperties

    public function name(): string        { return _m("File = uploaded file");  }             // see NamedInterface
    public function description(): string { return _m('Stores the uploaded file and a link to it, parameters only apply if type is image/something.');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'type'          => new AA_Property( 'type',         _m("Mime types accepted"), 'text', false, true, 'text', false, _m('Only files of matching mime types will be accepted'), '', 'image/*'),
            'width'         => new AA_Property( 'width',        _m("Maximum image width"), 'int',  false, true, 'int',  false, '', '', '800'),
            'height'        => new AA_Property( 'height',       _m("Maximum image height"),'int',  false, true, 'int',  false, _m("The image will be resampled to be within these limits, while retaining aspect ratio."), '', '600'),
            'otherfield'    => new AA_Property( 'otherfield',   _m("Other fields"),        'text', false, true, 'text', false, _m("List of other fields to receive this image, separated by ##"), '', 'image..........2'),
            'replacemethod' => new AA_Property( 'replacemethod',_m("Upload policy"),       'text', false, true, 'text', false, _m("new | overwrite | backup<br>This parameter controls what to do if uploaded file alredy exists:<br>new - AA creates new filename (by adding _x postfix) and store it with this new name (default)<br>overwrite - the old file of the same name is overwritten<br>backup - the old file is copied to new (non-existing) file and current file is stored with current name.<br>In all cases the filename is escaped, so any non-word characters will be replaced by an underscore."), '', 'new'),
            'exact'         => new AA_Property( 'exact',        _m("Exact dimensions"),    'bool', false, true, 'bool', false, _m("If set to 1 the image will be downsized exactly to the specified dimensions (and croped if needed). Default is 0 or empty: Maintain aspect ratio while resizing the image."), '', '1'),
            'nametemplate'  => new AA_Property( 'nametemplate', _m("Filename template"),   'text', false, true, 'text', false, _m("Renames file according to template. You can use AA expressions as well as special alias _#name and _#ext or _#full (file name, extension and fullname). The item aliases in AA expressions do not work on INSERT (item is not exist in time of file write). They will work on UPDATE."), '', 'file-_#SITEM_ID-_#name._#ext'),
        ];
    }

    /**  @return array - fields stored inside this function as thumbnails.  */
    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        global $err;

        $fid         = $this->field->getId();

        if (is_array($additional)) {
            /** @var AA_Fields $fields */
            $fields  = $additional["fields"];
            $order   = $additional["order"];
            $context = $additional["context"];
        }

        if (strpos('x'.$value['value'], 'AA_UPLOAD:')==1) {
            // newer - widget approach - the uploaded file is encoded into the value
            // and prefixed with "AA_UPLOAD:" constant
            $up_file = array_combine(['aa_const', 'name', 'type', 'tmp_name', 'error', 'size'], ParamExplode($value['value']));
            if ($up_file['name']=='') {
                $value['value'] = '';
            }
        } else {
            // old version of input form
            $up_file = $_FILES["v".unpack_id($fid)."x"];
        }

        // look if the uploaded picture exists
        if ($up_file['name'] AND ($up_file['name'] != 'none') AND ($context != 'feed')) {
            $sid = $fieldwriter->getSliceId();       // GLOBALS["slice_id"];
            if (!$sid AND $item_id) {
                $item  = AA_Items::getItem(new zids($item_id));
                if ($item) {
                    $sid = $item->getSliceID();
                }
            }
            $slice = AA_Slice::getModule($sid);
            if (!is_object($slice) OR !$slice->isValid()) {
                $err[$fid] = _m("Slice with id '%1' is not valid.", [$sid]);
                return [];
            }

            if ( strlen($this->nametemplate) ) {
                $template = str_replace(['_#name', '_#ext', '_#full'], [pathinfo($up_file['name'],PATHINFO_FILENAME), pathinfo($up_file['name'],PATHINFO_EXTENSION), pathinfo($up_file['name'],PATHINFO_BASENAME)], $this->nametemplate);
                if ($item OR  ($item_id AND ($item = AA_Items::getItem(new zids($item_id))))) {
                    $filename = AA::Stringexpander()->unalias($template,'',$item);
                } else {
                    $filename = AA::Stringexpander()->unalias($template);
                }
            } else {
                $filename = null;
            }

            $dest_file = Files::uploadFile($up_file, Files::destinationDir($slice), $this->type, $this->replacemethod, $filename);

            if ($dest_file === false) {   // error
                $err[$fid] = Files::lastErrMsg();
                return [];
            }

            // ---------------------------------------------------------------------
            // Create thumbnails (image miniature) into fields identified in this
            // field's parameters if file type is supported by GD library.

            // This has been considerable simplified, by making ResampleImage
            // return true for unsupported types IF they are already small enough
            // and also making ResampleImage copy the files if small enough

            if ($e = ResampleImage($dest_file, $dest_file, $this->width, $this->height, $this->exact)) {
                $err[$fid] = $e;
                return [];
            }
            if ($this->otherfield != "") {
                // get ids of field store thumbnails
                $thumb_arr=explode("##",$this->otherfield);

                foreach ($thumb_arr as $thumb) {
                    //copy thumbnail
                    $fncpar         = ParseFnc($fields->getProperty($thumb,'input_insert_func'));
                    $thumb_params   = explode(":",$fncpar['param']);  // (type, width, height)

                    $dest_file_tmb  = Files::generateUnusedFilename($dest_file, '_thumb');  // xxx_thumb1.jpg

                    if ($e = ResampleImage($dest_file,$dest_file_tmb, $thumb_params[1],$thumb_params[2],$thumb_params[5])) {
                        $err[$fid] = $e;
                        return [];
                    }

                    // store link to thumbnail
                    $val['value'] = $slice->getUrlFromPath($dest_file_tmb);
                    $this->_clear_field($item_id, $fid);
                    parent::execute($fieldwriter, $item_id, $val, $additional);
                }
            } // params[3]

            $value['value'] = $slice->getUrlFromPath($dest_file);
        } // File uploaded

        // store link to uploaded file or specified file URL if nothing was uploaded
        parent::execute($fieldwriter, $item_id, $value, $additional);

        // return array with fields that were filled with thumbnails  (why?)
        return $thumb_arr;
    }
}


/** AA_Inserter_Pwd - Password and Change Password */
class AA_Inserter_Pwd extends AA_Inserter {

    protected $bck_field;                   // see getClassProperties
    protected $crypt_phrase;            // see getClassProperties

    public function name(): string        { return _m("Password and Change Password");  }             // see NamedInterface
    public function description(): string { return _m('Stores value from a "Password and Change Password" field type. First prooves the new password matches the retyped new password, and if so, encrypts the new password and stores it.');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'bck_field'    => new AA_Property( 'bck_field',    _m("Copy of password"), 'text', false, true, 'field', false, _m('Field_id, where you want to store the copy of the password. Some times usefull if you want to allow people to get hir/her old password. It is strongly recommended to not store it in plaintext and encrypt it. See second parameter.'), '', 'password.......1'),
            'crypt_phrase' => new AA_Property( 'crypt_phrase', _m("Encrypt phrase"),   'text',  false, true, 'text', false, _m('If you specify the "Copy of password" field, it is recommended to ecrypt the password in the database. Fill there any string. If you then need the password, you can decrypt it from the field by using the same phrase - {decrypt:{password.......1}:Some Phrase}'), '', 'Some Phrase')
        ];
    }

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        [$aa_const, $password] = ParamExplode($value['value']);

        if ($aa_const == 'AA_PASSWD') {
            if ($this->bck_field AND ($slice = AA_Slice::getModule($fieldwriter->getSliceId())) AND $slice->isField($this->bck_field)) {
                // $password is_a decrypted here
                $backup = $this->crypt_phrase ? StrExpand('AA_Stringexpand_Encrypt', [$password, $this->crypt_phrase]) : $password;
                // store backup value to specified field
                $this->_clear_field($item_id, $this->bck_field);
                AA_Inserter::factoryByField($slice->getField($this->bck_field))->execute($fieldwriter, $item_id, ['value'=> $backup]);
            }
            $value['value'] = AA_Perm::cryptPwd($password);
        } elseif ($aa_const == 'AA_PASSWD_CRYPTED') {
            // this is the only case if you are updating the item and you want to left the password the same
            $value['value'] = $password;
        } else {
            $value['value'] = AA_Perm::cryptPwd($value['value']);
        }
        parent::execute($fieldwriter, $item_id, $value, $additional);
    }
}

/** AA_Inserter_2fa - 2FA Secret */
class AA_Inserter_2fa extends AA_Inserter {

    protected $crypt_phrase;            // see getClassProperties

    public function name(): string        { return _m("2FA Secret");  }             // see NamedInterface
    public function description(): string { return _m('Generates and stores secret for Two-factor authentication, possibly encrypted by password');  }      // see NamedInterface

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties():array  {
        return [             //           id         name                       type      multi  persist validator, required, help, morehelp, example
                             'crypt_phrase' => new AA_Property( 'crypt_phrase',  _m("Encrypt phrase"), 'text', false, true, 'text', false, _m('Pasword to be applied for crypting this field value. Could contain AA expressions or aliases (for user item in Reader Management slice)'), '', 'SomeSecret784!*')
        ];
    }

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        [$aa_const, $username, $password, $code2fa] = ParamExplode($value['value']);

        if ($aa_const == 'AA_2FASECRET') {
            global $auth;
            if (!strlen($username) OR !($uid = AA::$perm->authenticateUsername($username, $password, $code2fa)) OR ($uid != $auth->auth['uid'])) {
                return '';
            }
            $ga = new PHPGangsta_GoogleAuthenticator();
            $secret = $ga->createSecret();

            $this->crypt_phrase = trim($this->crypt_phrase);

            if (strlen($this->crypt_phrase)) {
                if (is_long_id($uid) AND ($item = AA_Items::getItem(new zids($uid)))) {
                    $phrase = AA::Stringexpander()->unalias($this->crypt_phrase,'',$item);
                } else {
                    $phrase = AA::Stringexpander()->unalias($this->crypt_phrase);
                }
                if ($phrase = trim($phrase)) {
                    $secret = StrExpand('AA_Stringexpand_Encrypt', [$secret, $phrase]);
                }
            }

            // $this->_clear_field($item_id, $this->field->getId());  // I think it is not necessary - safeFields will be deleted in execute, others are already deleted
            $value['value'] = $secret;
            parent::execute($fieldwriter, $item_id, $value, $additional);
        }
        // nothing stored. The field should be safefield, so not cleared
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}

/** AA_Inserter_Unq - not tested, not used, yet*/
class AA_Inserter_Unq extends AA_Inserter {
    public function name(): string        { return _m("");  }             // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
        parent::execute($fieldwriter, $item_id, ["value"=>StrExpand('AA_Stringexpand_Finduniq', [$value['value'], $this->field->getId(), empty($unique_slices) ? $fieldwriter->getSliceId() : $unique_slices, $item_id])], $additional);
    }
}

/** AA_Inserter_Null - insert nothing - not used */
class AA_Inserter_Nul extends AA_Inserter {
    public function name(): string        { return _m("");  }             // see NamedInterface
    public function description(): string { return _m('');  }      // see NamedInterface

    public function execute($fieldwriter, $item_id, $value, $additional= []) {
    }

    /** Returns true, if the content of this field is always generated (so it is OK to have this field unfilled)
     * @return bool
     */
    public function isContentSelfGenerated() {
        return true;
    }
}

// -----------------------------------------------------------------------------


class AA_Field_Writer {

    /** @var Cvarset  */
    protected $item_varset;
    protected $slice_id;

    /**
     * AA_Field_Writer constructor.
     * @param string $slice_id - long slice id - it is optional for backward compatibility, but should be provided
     *                           in order fileupload and uniques works...
     */
    function __construct($slice_id=null) {
        $this->slice_id    = $slice_id;
        $this->item_varset = new Cvarset;
    }

    /** The content fields are already written, but the fields in item table still probably waits - get them for update
      * @return Cvarset
      */
    public function getItemVarset() {
        return $this->item_varset;
    }

    /**
     * @return null|string
     */
    public function getSliceId() {
        return $this->slice_id;
    }
}

// ----------------------- show functions --------------------------------------
// moved to formutil into AA_Inputfield class (formutil.php3)
// -----------------------------------------------------------------------------
/** IsEditable function
 * @param $fieldcontent
 * @param AA_Field $field
 * @param AA_Profile $profile
 * @return bool
 */
function IsEditable($fieldcontent, $field, $profile): bool {
    return (!($fieldcontent[0]['flag'] & FLAG_FREEZE)
        AND $field->getProperty("input_show")
        AND !$profile->getProperty('hide',$field->getId())
        AND !$profile->getProperty('hide&fill',$field->getId())
        AND !$profile->getProperty('fill',$field->getId()));
}

// -----------------------------------------------------------------------------
/** StoreItem - deprecated function - $content4id->storeItem() instead
 *
 *   Basic function for changing contents of items.
 *   Use always this function, not direct SQL queries.
 *   Updates the tables @c item and @c content.
 *   $GLOBALS[err][field_id] should be set on error in function
 *   It looks like it will return true even if inset_fnc_xxx fails
 *
 * @param $id
 * @param $slice_id
 * @param array $content4id   array (field_id => array of values
 *						      (usually just a single value, but still an array))
 * @param $fields
 * @param $insert
 * @param $invalidatecache
 * @param $feed
 * @param array $oldcontent4id if not sent, StoreItem finds it
 * @param $context special parameter used for thumbnails - ''|feed
 * @return true on success, false otherwise
 */
function StoreItem( $id, $slice_id, $content4id, $insert, $invalidatecache=true, $feed=true, $context='' ) {
    $content4id = new ItemContent($content4id);
    $content4id->setItemID($id);
    $content4id->setSliceID($slice_id);
    return $content4id->storeItem( $insert ? 'insert' : 'update', [$invalidatecache, $feed], $context);     // invalidatecache, feed
} // end of StoreItem

// ----------------------------------------------------------------------------

/** Validates new content, sets defaults, reads dates from the 3-selectbox-AA-format,
 *   sets global variables:
 *       $oldcontent4id
 *       $special input variables
 *
 *   This function is used in itemedit.php3, filler.php3 and file_import.php3.
 *
 *   @author Jakub Adamek, Econnect, January 2003
 *           Most of the code is taken from itemedit.php3, created by Honza.
 *
 * @param $err
 * @param $slice AA_Slice
 * @param string $action should be one of:
 *                       "add" .... a "new item" page (not form-called)
 *                       "edit" ... an "edit item" page (not form-called)
 *                       "insert" . call for inserting an item
 *                       "update" . call for updating an item
 * @param string $id is useful only for "update"
 * @param bool $do_validate Should validate the fields?
 * @param array $notshown is an optional array ("field_id"=>1,...) of fields
 *                          not shown in the anonymous form
 */
//./admin/itemedit.php3:       ValidateContent4Id($err, $slice, $action, $id);
//./admin/slicefieldsedit.php3:ValidateContent4Id($err, $slice, $action, $id);
//./filler.php3:               ValidateContent4Id($err_valid, $slice, $insert ? "insert" : "update", $my_item_id, !$notvalidate, $notshown);

function ValidateContent4Id(&$err, $slice, $action, $id=0, $do_validate=true, $notshown="") {
    global $oldcontent4id, $auth;

    $profile = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings

    // error array (Init - just for initializing variable
    if (!is_array($err)) {
        $err = [];
    }

    // Are we editing dynamic slice setting fields?
    $slice_fields = ($id == $slice->getId());

    // get slice fields and its priorities in inputform
    $fields = $slice->getFields($slice_fields);

    // it is needed to call IsEditable() function and GetContentFromForm()
    if ( $action == "update" ) {
        // if we are editing dynamic slice setting fields (stored in content
        // table), we need to get values from slice's fields
        if ($slice_fields) {
            $oldcontent    = $slice->get_dynamic_setting_content(true);
            $oldcontent4id = $oldcontent->getContent();   // shortcut
        } else {
            $oldcontent = GetItemContent($id);
            $oldcontent4id = $oldcontent[$id];   // shortcut
        }
    }

    foreach ($fields as $pri_field_id => $field) {

        $f = $field->getRecord();

        //  'status_code.....' is not in condition - could be set from defaults
        if (($pri_field_id=='edited_by.......') || ($pri_field_id=='posted_by.......')) {
            continue;   // filed by AA - it could not be filled here
        }
        $varname = 'v'. unpack_id($pri_field_id);  // "v" prefix - database field var
        $htmlvarname = $varname."html";

        global $$varname, $$htmlvarname;

        $setdefault = $action == "add"
                || !$f["input_show"]
                || $profile->getProperty('hide',$pri_field_id)
                || ($action == "insert" && $notshown[$varname]);

        [$validate_type] = explode(":", $f["input_validate"], 2);
        [$insert_type]   = explode(":", $f["input_insert_func"], 2);

        if ($setdefault) {
            $default = $field->getDefault();

            // modify the value to be compatible with $_GET[] array - we use
            // slashed variables (this will be changed in future) - TODO
            $$varname     = ($default->count() > 1) ? array_map('addslashes',$default->getValues()) : addslashes($default->getValue());
            $$htmlvarname = $default->getFlag();

        } elseif ($validate_type=='date') {
            $default = $field->getDefault();
            // we do not know at this moment, if we have to use default
            $default_val  = addslashes($default->getValue());
        }

        $editable = IsEditable($oldcontent4id[$pri_field_id], $field, $profile) && !$notshown[$varname];

        // Run the "validation" which changes field values
        if ($editable && ($action == "insert" || $action == "update")) {
            if ( $validate_type == 'date' ) {
                $foo_datectrl_name = new datectrl($varname);

                if ($$varname != "") {                  // loaded from defaults
                    $foo_datectrl_name->setdate_int($$varname);
                }
                $foo_datectrl_name->ValidateDate($f["name"], $err, $f["required"], $default_val);
                $$varname = $foo_datectrl_name->get_date();  // write to var
            } elseif ($insert_type == 'boo') {
                $$varname = ($$varname ? 1 : 0);
            } elseif ($insert_type == 'pwd') {
                $change_varname   = $varname.'a';
                $retype_varname   = $varname.'b';
                $delete_varname   = $varname.'d';

                global $$change_varname, $$retype_varname, $$delete_varname;

                if ($$change_varname && ($$change_varname == $$retype_varname)) {
                    $$varname = ParamImplode(['AA_PASSWD',$$change_varname]);
                } elseif ($$delete_varname) {
                    $$varname = '';
                } elseif ($action == "update") {
                    // store the original password to use it in
                    // insert_fnc_pwd when it is not changed
                    // $$varname = $oldcontent4id[$pri_field_id][0]['value'];
                    $$varname = ParamImplode(['AA_PASSWD_CRYPTED',$oldcontent4id[$pri_field_id][0]['value']]);
                }
            }
        }

        // Run the validation which really only validates
        if ($do_validate && ($action == "insert" || $action == "update")) {
            // special setting for file upload - there we solve the problem
            // of required fileupload field, but which is empty at this moment
            if ( $f["required"] AND (substr($f["input_show_func"], 0,3) === 'fil')) {
                ValidateInput($varname, $f["name"], $$varname. $GLOBALS[$varname.'x'] , $err, $f["required"] ? 1 : 0, AA_Validate::factoryByString($f["input_validate"]));
                continue;
            }

            switch( $validate_type ) {
                case 'e-unique':
                case 'unique':

                    // fill field with current field, if not filled and
                    // add $id, so we do not find the currently edited item when
                    // we are looking for uniqueness

                    [$v_func,$v_field,$v_scope] = ParamExplode($f["input_validate"]);
                    if (!$v_field) {
                        $v_field = $pri_field_id;
                    }
                    $v_type = ParamImplode([$v_func,$v_field,$v_scope,$id]);
                    ValidateInput($varname, $f["name"], $$varname, $err, $f["required"] ? 1 : 0, AA_Validate::factoryByString($v_type));

                    break;
                case 'user':
                    // this is under development.... setu, 2002-0301
                    // value can be modified by $$varname = "new value";
                    if (function_exists('usr_validate')) {
                        $$varname = usr_validate($varname, $f["name"], $$varname, $err, $f, $fields);
                    }
                    break;
                //case 'text':
                //case 'url':
                //case 'email':
                //case 'number':
                //case 'id':
                default:
                    // status code is never required
                    ValidateInput($varname, $f["name"], $$varname, $err, ($f["required"] AND ($pri_field_id!='status_code.....')) ? 1 : 0, AA_Validate::factoryByString($f["input_validate"]));
                    break;
            }
        }
    }
}
