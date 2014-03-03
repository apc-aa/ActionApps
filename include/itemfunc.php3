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

require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."imagefunc.php3";
require_once AA_INC_PATH."javascript.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."item_content.php3";
require_once AA_INC_PATH."event.class.php3";
require_once AA_INC_PATH."profile.class.php3";
require_once AA_INC_PATH."files.class.php3";

if ( !is_object($event) ) {
    $event = new aaevent;   // not defined in scripts which do not include init_page.php3 (like offline.php3)
}


/** classes for default values of the fields
 *  derived from  AA_Serializable in order to be able to factory from string
 *  
 *  Ussage: $aa_value = AA_Generator::factoryByString('dte:5000')->generate();
*/
abstract class AA_Generator extends AA_Serializable {
    abstract function generate();
}

/** AA_Generator_Now - current timestamp */
class AA_Generator_Now extends AA_Generator {
    
    /** Name of the component for selection */    
    static function name() { return _m("Now, i.e. current date");  }

    /** generate() - main function for generating the value */
    function generate()    { return new AA_Value(now()); }
}
        
/** AA_Generator_Uid - User ID */
class AA_Generator_Uid extends AA_Generator {

    /** Name of the component for selection */    
    static function name() { return _m("User ID");  }

    /** generate() - main function for generating the value */
    function generate() {
        global $auth;                                  // 9999999999 for anonymous
        return new AA_Value(isset($auth) ? $auth->auth["uid"] : "9999999999");
    }
}
        
/** AA_Generator_Log - Login name */
class AA_Generator_Log extends AA_Generator {

    /** Name of the component for selection */    
    static function name() { return _m("Login name");  }

    /** generate() - main function for generating the value */
    function generate() {
        global $auth;                                  // "anonymous" for anonymous
        return new AA_Value(isset($auth) ? $auth->auth["uname"] : "anonymous");
    }
}
        
/** AA_Generator_Dte - Date + 'Parameter' days */
class AA_Generator_Dte extends AA_Generator {

    /** Name of the component for selection */    
    static function name() {  return _m("Date + 'Parameter' days");  }

    /** getClassProperties function of AA_Serializable  */
    static function getClassProperties()  {
        return array (             //           id         name                        type    multi  persist validator, required, help, morehelp, example
            'plusdays' => new AA_Property( 'plusdays',  _m("Number of days"), 'int', false, true, 'int', false, '', '', '365')
            );
    }
    
    /** generate() velue of currrent timestamp */
    function generate()    {  return new AA_Value(mktime(0,0,0,date("m"),date("d")+(int)$this->plusdays,date("Y")));  }
}
        
/** AA_Generator_Qte - only for backward complatibility. The same as Txt */
class AA_Generator_Qte extends AA_Generator_Txt {}

/** AA_Generator_Txt - Text from 'Parameter' */
class AA_Generator_Txt extends AA_Generator {

    /** Name of the component for selection */    
    static function name() {  return _m("Text from 'Parameter'");  }

    /** getClassProperties function of AA_Serializable  */
    static function getClassProperties()  {
        return array (      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text' )
            );
    }
    
    /** generate() velue of currrent timestamp */
    function generate() {
        return new AA_Value($this->text);
    }
}
        
/** AA_Generator_Rnd - Random string */
class AA_Generator_Rnd extends AA_Generator {

    /** Name of the component for selection */    
    static function name()        {  return _m("Random string");  }
    /** Decription  of the component for selection */    
    static function description() {  return _m("Random alphanumeric [A-Z0-9] string.");  }

    /** getClassProperties function of AA_Serializable  */
    static function getClassProperties()  {
        return array (             //           id         name                       type      multi  persist validator, required, help, morehelp, example
            'length'       => new AA_Property( 'length      ',  _m("String length"),  'int',    false, true, 'int', false, '', '', '5'),
            'checkfield'   => new AA_Property( 'checkfield  ',  _m("Field to check"), 'string', false, true, 'field', false, _m("If you need a unique code, you must send the field ID, the function will then look into this field to ensure uniqueness."), '', 'unspecified.....'),
            'wheretocheck' => new AA_Property( 'wheretocheck',  _m("Slice only"),     'bool',   false, true, 'bool', false, _m("Do you want to check for uniqueness this slice only or all slices?"))
            );
    }
    
    /** generate() velue of currrent timestamp */
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
                $rec = DB_AA::select1('SELECT item_id FROM content INNER JOIN item ON content.item_id = item.id','', array(array('item.slice_id',$slice_id, 'l'),array('field_id',$field_id),array('text',$randstring)));
            } else {
                $rec = DB_AA::select1('SELECT item_id FROM content','', array(array('field_id',$field_id),array('text',$randstring)));
            }
        } while ($rec);
        return new AA_Value($randstring);
    }
}
        
/** AA_Generator_Variable - AA Expression */
class AA_Generator_Variable extends AA_Generator {

    /** Name of the component for selection */    
    static function name()        {  return _m("AA Expression");  }
    /** Decription  of the component for selection */    
    static function description() {  return _m("any text with possible {AA expressions} like: {date:Y}");  }

    /** getClassProperties function of AA_Serializable  */
    static function getClassProperties()  {
        return array (      //           id         name     type    multi  persist validator, required, help, morehelp, example
            'text' => new AA_Property( 'text',  _m("Text"), 'text', false, true, 'text', false, '', '', '{date:Y}')
            );
    }
    
    /** getClassProperties function of AA_Serializable  */
    function generate() {
        return new AA_Value(AA_Stringexpand::unalias($this->text));
    }
}
        
/** AA_Generator_Mul - Multivalues */
class AA_Generator_Mul extends AA_Generator {
    
    protected $text; 
    protected $delimiter; 

    /** Name of the component for selection */    
    static function name()        {  return _m("Multivalue");  }

    /** getClassProperties function of AA_Serializable  */
    static function getClassProperties()  {
        return array (      //           id         name     type                  multi  persist validator, required, help, morehelp, example
            'text'      => new AA_Property( 'text',       _m("Text"),      'text',   false, true, 'text', false, '', '', 'red|green|blue'),
            'delimiter' => new AA_Property( 'delimiter',  _m("Delimiter"), 'string', false, true, 'text', false, '', '', '|')
            );
    }
    
    /** getClassProperties function of AA_Serializable  */
    function generate() {
        return new AA_Value(explode(($this->delimiter ?: '|'), $this->text));
    }
}

// ----------------------- insert functions ------------------------------------


class AA_Field_Writer {

    /** insert_fnc_qte function
     *  What are the parameters to this function - $field must be an array with values [input_show_func] etc
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_qte($item_id, $field, $value, $param, $additional='') {
        $this->_store($item_id, $field, $value, $param, $additional);
    }

    /** insert_fnc_dte function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_dte($item_id, $field, $value, $param, $additional='') {
        $this->_store($item_id, $field, $value, $param, $additional);
    }

    /** insert_fnc_cns function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_cns($item_id, $field, $value, $param, $additional='') {
        $this->_store($item_id, $field, $value, $param, $additional);
    }
    /** insert_fnc_num function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_num($item_id, $field, $value, $param, $additional='') {
        $this->_store($item_id, $field, $value, $param, $additional);
    }
    /** insert_fnc_boo function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_boo($item_id, $field, $value, $param, $additional='') {
        $value['value'] = ( $value['value'] ? 1 : 0 );
        $this->_store($item_id, $field, $value, $param, $additional);
    }
    /** insert_fnc_ids function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_ids($item_id, $field, $value, $param, $additional='') {

        $add_mode = substr($value['value'],0,1);          // x=add, y=add mutual, z=add backward
        if (strpos('txyz', $add_mode) !== false) {
            $value['value'] = substr($value['value'],1);  // remove x, y or z
        }
        switch( $add_mode ) {
            case 't':  // t for tags - it could be normal item ID or new Tag
                $v = $value['value'];
                if ( (strlen($v)!=32) OR (strspn($v, "0123456789abcdefABCDEF")!=32)) {
                    $fnc = ParseFnc($field["input_show_func"]);   // input show function
                    if ($fnc AND ($fnc['fnc']=='tag')) {
                        // get add2constant and constgroup (other parameters are irrelevant in here)
                        list($constgroup, $others) = explode(':', $fnc['param']);
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
                $this->_store($item_id, $field, $value, $param, $additional);
                break;
            case 'y':   // y means 2way related item id - we have to store it for both
                $this->_store($item_id, $field, $value, $param, $additional);
                // !!!!! there is no break or return - CONTINUE with 'z' case !!!!!

            case 'z':   // z means backward related item id - store it only backward
                // add reverse related
                $reverse_id     = $value['value'];
                $value['value'] = $item_id;

                // mimo added
                // get rid of empty dummy relations (text='')
                // this is only a problem for text content
                $db = getDB();
                if($field["text_stored"]) {
                  $SQL = "DELETE FROM content
                           WHERE item_id = '". q_pack_id($reverse_id) ."'
                             AND field_id = '". $field["id"] ."'
                             AND `text`=''";
                  $db->query( $SQL );
                }
                // is reverse relation already set?
                $SQL = "SELECT * FROM content
                         WHERE item_id = '". q_pack_id($reverse_id) ."'
                           AND field_id = '". $field["id"] ."'
                           AND ". ($field["text_stored"] ? "text" : "number") ."= '". $value['value'] ."'";
                $db  = getDB();
                $db->query( $SQL );
                if (!$db->next_record()) { // not found
                    $this->_store($reverse_id, $field, $value, $param);
                }
                freeDB($db);
                break;;

            case 'x':   // just filling character - remove it
            default:
                $this->_store($item_id, $field, $value, $param, $additional);
        }
        return;
    }
    /** insert_fnc_uid function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_uid($item_id, $field, $value, $param, $additional='') {
        global $auth;

        if ( $value['value'] AND IsSuperadmin() ) {
            $val = $value['value'];
        } else {
            // if not $auth, it is from anonymous posting - 9999999999 is anonymous user
            $val = (isset($auth) ?  $auth->auth["uid"] : ((strlen($value['value'])>0) ?
                                                      $value['value'] : "9999999999"));
        }
        $this->_store($item_id, $field, array('value' => $val), $param, $additional);
    }
    /** insert_fnc_log function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_log($item_id, $field, $value, $param, $additional='') {
        global $auth;
        // if not $auth, it is from anonymous posting
        $val = (isset($auth) ?  $auth->auth["uname"] : ((strlen($value['value'])>0) ?
                                                        $value['value'] : 'anonymous'));
        $this->_store($item_id, $field, array('value' => $val), $param, $additional);
    }
    /** insert_fnc_now function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_now($item_id, $field, $value, $param, $additional='') {
        $this->_store($item_id, $field, array("value"=>now()), $param, $additional);
    }

    /** insert_fnc_co2 function - Computed field for INSERT/UPDATE
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_co2($item_id, $field, $value, $param, $additional='') {
        // we store it to the database at this time, even if it is probably
        // not final value for this field - we probably recompute this value later
        // in storeItem method, but we should compute with this new value there,
        // so we need to store it, right now
        // (this is the only case for computed field SHOWN IN INPUTFORM)
        $this->_store($item_id, $field, $value, $param, $additional);
        return;
    }

    /** insert_fnc_com function - Computed field
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_com($item_id, $field, $value, $param, $additional='') {
        $this->insert_fnc_co2($item_id, $field, $value, $param, $additional);
        return;
    }

    // -----------------------------------------------------------------------------
    /** insert_fnc_fil function
     *  Insert function for File Upload.
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     * @return Array of fields stored inside this function as thumbnails.
     */
    // There are three cases here
    // 1: uploaded - overwrites any existing value, does resampling etc
    // 2: file name left over from existing record, just stores the value
    // 3: newly entered URL, this is not distinguishable from case //2 so
    //    its just stored, and no thumbnails etc generated, this could be
    //    fixed later (mtira)
    // in $additional are fields
    function insert_fnc_fil($item_id, $field, $value, $param, $additional="") {
        global $err;

        if (is_array($additional)) {
            $fields  = $additional["fields"];
            $order   = $additional["order"];
            $context = $additional["context"];
        }

        if (strpos('x'.$value['value'], 'AA_UPLOAD:')==1) {
            // newer - widget approach - the uploaded file is encoded into the value
            // and prefixed with "AA_UPLOAD:" constant
            $up_file = array_combine(array('aa_const', 'name', 'type', 'tmp_name', 'error', 'size'), ParamExplode($value['value']));
            if ($up_file['name']=='') {
               $value['value'] = '';
            }
        } else {
            // old vedsion of input form
            $up_file = $_FILES["v".unpack_id($field["id"])."x"];
        }

        // look if the uploaded picture exists
        if ($up_file['name'] AND ($up_file['name'] != 'none') AND ($context != 'feed')) {
            $sid = $GLOBALS["slice_id"];
            if (!$sid AND $item_id) {
                $item  = AA_Items::getItem(new zids($item_id));
                if ($item) {
                    $sid = $item->getSliceID();
                }
            }
            $slice = AA_Slices::getSlice($sid);
            if (!is_object($slice) OR !$slice->isValid()) {
                $err[$field["id"]] = _m("Slice with id '%1' is not valid.", array($sid));
                return;
            }

            // $pdestination and $purl is not used, yet - it should be used to allow
            // slice administrators to store files to another directory
            // list($ptype, $pwidth, $pheight, $potherfield, $preplacemethod, $pdestination, $purl) = ParamExplode($param);
            list($ptype, $pwidth, $pheight, $potherfield, $preplacemethod, $pexact) = ParamExplode($param);

            $dest_file = Files::uploadFile($up_file, Files::destinationDir($slice), $ptype, $preplacemethod);

            if ($dest_file === false) {   // error
                $err[$field["id"]] = Files::lastErrMsg();
                return;
            }

            // ---------------------------------------------------------------------
            // Create thumbnails (image miniature) into fields identified in this
            // field's parameters if file type is supported by GD library.

            // This has been considerable simplified, by making ResampleImage
            // return true for unsupported types IF they are already small enough
            // and also making ResampleImage copy the files if small enough

            if ($e = ResampleImage($dest_file, $dest_file, $pwidth, $pheight, $pexact)) {
                $err[$field["id"]] = $e;
                return;
            }
            if ($potherfield != "") {
                // get ids of field store thumbnails
                $thumb_arr=explode("##",$potherfield);

                foreach ($thumb_arr as $thumb) {
                    //copy thumbnail
                    $f              = $fields[$thumb];       // Array from fields
                    $fncpar         = ParseFnc($f["input_insert_func"]);
                    $thumb_params   = explode(":",$fncpar['param']);  // (type, width, height)

                    $dest_file_tmb  = Files::generateUnusedFilename($dest_file, '_thumb');  // xxx_thumb1.jpg

                    if ($e = ResampleImage($dest_file,$dest_file_tmb, $thumb_params[1],$thumb_params[2],$thumb_params[5])) {
                        $err[$field["id"]] = $e;
                        return;
                    }

                    // store link to thumbnail
                    $val['value'] = $slice->getUrlFromPath($dest_file_tmb);
                    $this->_clear_field($item_id, $f['id']);
                    $this->_store( $item_id, $f, $val, "", $additional);
                }
            } // params[3]

            $value['value'] = $slice->getUrlFromPath($dest_file);
        } // File uploaded

        // store link to uploaded file or specified file URL if nothing was uploaded
        $this->_store( $item_id, $field, $value, "", $additional);

        // return array with fields that were filled with thumbnails  (why?)
        return $thumb_arr;
    } // end of insert_fnc_fil

    // -----------------------------------------------------------------------------
    /** insert_fnc_pwd function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_pwd($item_id, $field, $value, $param, $additional='') {
        list ($aa_const, $password) = ParamExplode($value['value']);
        if ($aa_const == 'AA_PASSWD') {
            list($pfield, $pcrypt) = ParamExplode($param);

            if ($pfield AND ($f = $field[$pfield])) {  // wrong - $field[$pfield] is nonsence - the if is never executed, Honza 9.12.2012
                // $password is_a decrypted here
                $backup = $pcrypt ? AA_Stringexpand_Encrypt::explode($password, $pcrypt) : $password;
                // store backup value to specified field
                $this->_clear_field($item_id, $f['id']);
                $this->_store( $item_id, $f, array('value'=> $backup), "", $additional);
            }
            $value['value'] = AA_Perm::cryptPwd($password);
        } elseif ($aa_const == 'AA_PASSWD_CRYPTED') {
            // this is the only case if you are updating the item and you want to left the password the same
            $value['value'] = $password;
        } else {
            $value['value'] = AA_Perm::cryptPwd($value['value']);
        }
        $this->_store($item_id, $field, $value, $param, $additional);
    }

    // -----------------------------------------------------------------------------
    /** insert_fnc_unq function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_unq($item_id, $field, $value, $param, $additional='') {
        $value['value'] = AA_Stringexpand_Finduniq::expand($value['value'], $field["id"], empty($unique_slices) ? $GLOBALS["slice_id"] : $unique_slices, $item_id);
        $this->_store($item_id, $field, $value, $param, $additional);
    }

    // -----------------------------------------------------------------------------
    /** insert_fnc_nul function
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_nul($item_id, $field, $value, $param, $additional='') {
    }

    /** insert_fnc_ function
     *  not defined insert func in field table (it is better to use insert_fnc_nul)
     * @param $item_id
     * @param $field
     * @param $value
     * @param $param
     * @param $additional
     */
    function insert_fnc_($item_id, $field, $value, $param, $additional='') {
    }

    protected function _clear_field($item_id, $field_id) {
        // delete content just for displayed fields
        DB_AA::sql("DELETE FROM content WHERE item_id='". q_pack_id($item_id). "' AND field_id = '".quote($field_id)."'");
    }

    protected function _store($item_id, $field, $value, $param, $additional='') {
        global $itemvarset;

        $varset = new Cvarset();
        // if input function is 'selectbox with presets' and add2connstant flag is set,
        // store filled value to constants
        $fnc = ParseFnc($field["input_show_func"]);   // input show function
        if ($fnc AND ($fnc['fnc']=='pre')) {
            // get add2constant and constgroup (other parameters are irrelevant in here)
            list($constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding, $secondfield, $add2constant) = explode(':', $fnc['param']);
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

        if ($field["in_item_tbl"]) {
            // Mitra thinks that this might want to be 'expiry_date.....' ...
            // ... which is not correct because in 'in_item_tbl' database field
            // we store REAL database field names from aadb.item table (honzam)
            if (($field["in_item_tbl"] == 'expiry_date') AND (date("Hi",$value['value']) == "0000")) {
                // $value['value'] += 86399;
                // if time is not specified, take end of day 23:59:59
                // !!it is not working for daylight saving change days !!!
                $value['value'] = mktime(23,59,59,date("m",$value['value']),date("d",$value['value']),date("Y",$value['value']));
            }

            // field in item table
            $itemvarset->add($field["in_item_tbl"], "text", $value['value']);
            return;
        }

        // field in content table (function defined in util.php since we need it for display count
        StoreToContent($item_id, $field, $value, $additional);
    }
}


// ----------------------- show functions --------------------------------------
// moved to formutil into AA_Inputfield class (formutil.php3)
// -----------------------------------------------------------------------------
/** IsEditable function
 * @param $fieldcontent
 * @param $field
 * @param $profile
 */
function IsEditable($fieldcontent, $field, &$profile) {
    return (!($fieldcontent[0]['flag'] & FLAG_FREEZE)
        AND $field["input_show"]
        AND !$profile->getProperty('hide',$field['id'])
        AND !$profile->getProperty('hide&fill',$field['id'])
        AND !$profile->getProperty('fill',$field['id']));
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
 * @param $context
 * @return true on success, false otherwise
 */
function StoreItem( $id, $slice_id, $content4id, $fields, $insert, $invalidatecache=true, $feed=true, $oldcontent4id="", $context='direct' ) {
    $content4id = new ItemContent($content4id);
    $content4id->setItemID($id);
    $content4id->setSliceID($slice_id);
    return $content4id->storeItem( $insert ? 'insert' : 'update', array($invalidatecache, $feed), $context);     // invalidatecache, feed
} // end of StoreItem

// -----------------------------------------------------------------------------
/** ShowForm function
 *  Shows the Add / Edit item form fields
 * @param $content4id
 * @param $fields
 * @param $prifields
 * @param $edit
 * @param $show is used by the Anonymous Form Wizard, it is an array
 *                (packed field id => 1) of fields to show
 */
function ShowForm($content4id, $fields, $prifields, $edit, $show="") {
    global $slice_id, $auth;

    if ( !isset($prifields) OR !is_array($prifields) ) {
        return MsgErr(_m("No fields defined for this slice"));
    }

    $profile   = AA_Profile::getProfile($auth->auth["uid"], $slice_id); // current user settings

    foreach ($prifields as $pri_field_id) {
        $f   = $fields[$pri_field_id];
        $fnc = ParseFnc($f["input_show_func"]);   // input show function

        if (is_array($show)) {
            $showme = $show [$f['id']];
        } else {
            $showme = $f["input_show"] AND !$profile->getProperty('hide',$f['id']) AND !$profile->getProperty('hide&fill',$f['id']);
        }

        if (!$fnc OR !$showme) {
            continue;
        }

        // get varname - name of field in inputform
        $varname     = 'v'. unpack_id($pri_field_id); // "v" prefix - database field var
        $htmlvarname = $varname."html";

        if (!IsEditable($content4id[$pri_field_id], $f, $profile)) {
            // if fed as unchangeable
            $show_fnc_prefix = 'show_fnc_freeze_';          // display it only
        } else {
            $show_fnc_prefix = 'show_fnc_';
        }

        $fncname = $show_fnc_prefix . $fnc["fnc"];

        // look for alternative function for Anonym Wizard (used for passwords)
        if (is_array($show) && function_exists($fncname."_anonym")) {
            $fncname .= "_anonym";
        }

        // updates content table or fills $itemvarset
        if ($edit) {
            $fncname($varname, $f, $content4id[$pri_field_id],
                     $fnc["param"], $content4id[$pri_field_id][0]['flag'] & FLAG_HTML );
        } else {
            // insert or new reload of form after error in inserting
            // first get values from profile, if there are some predefined value
            $foo = $profile->getProperty('predefine',$f['id']);
            if ($foo AND !$GLOBALS[$varname]) {
                $x = $profile->parseContentProperty($foo);
                 // it is not quoted, so OK
                $GLOBALS[$varname]     = $x->getValue();
                $GLOBALS[$htmlvarname] = $x->getFlag();
            }

            // get values from form (values are filled when error on form ocures
            if ($f["multiple"] AND is_array($GLOBALS[$varname])) {
                // get the multivalues
                $i=0;
                foreach ($GLOBALS[$varname] as $v) {
                    $arr[$i++]['value'] = $v;
                }
            } else {
                $arr[0]['value'] = $GLOBALS[$varname];
            }

            $fncname($varname, $f, $arr, $fnc["param"],
                     ((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
        }
    }
}

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
 * @param $slice
 * @param string $action should be one of:
 *                       "add" .... a "new item" page (not form-called)
 *                       "edit" ... an "edit item" page (not form-called)
 *                       "insert" . call for inserting an item
 *                       "update" . call for updating an item
 * @param id $id is useful only for "update"
 * @param bool $do_validate Should validate the fields?
 * @param array $notshown is an optional array ("field_id"=>1,...) of fields
 *                          not shown in the anonymous form
 */
//./admin/itemedit.php3:                               ValidateContent4Id($err, $slice, $action, $id);
//./misc/file2slice/tab2slice_php/file_import.php3:    ValidateContent4Id($err, $slice, "insert", 0, ! $notvalidate);
//./filler.php3:                                       ValidateContent4Id($err_valid, $slice, $insert ? "insert" : "update", $my_item_id, !$notvalidate, $notshown)
function ValidateContent4Id(&$err, &$slice, $action, $id=0, $do_validate=true, $notshown="")
{
    global $oldcontent4id, $auth;

    $profile   = AA_Profile::getProfile($auth->auth["uid"], $slice->unpacked_id()); // current user settings

    // error array (Init - just for initializing variable
    if (!is_array($err)) {
        $err["Init"] = "";
    }

    // Are we editing dynamic slice setting fields?
    $slice_fields = ($id == $slice->unpacked_id());

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
                || ($action == "insert" && $notshown [$varname]);

        list($validate) = explode(":", $f["input_validate"], 2);

        if ($setdefault) {
            $default = $field->getDefault();
            // modify the value to be compatible with $_GET[] array - we use
            // slashed variables (this will be changed in future) - TODO
            $$varname     = ($default->valuesCount() > 1) ? array_map('addslashes',$default->getValues()) : addslashes($default->getValue());
            $$htmlvarname = $default->getFlag();
        } elseif ($validate=='date') {
            $default = $field->getDefault();
            // we do not know at this moment, if we have to use default
            $default_val  = addslashes($default->getValue());
        }

        $editable = IsEditable($oldcontent4id[$pri_field_id], $f, $profile) && !$notshown[$varname];

        // Run the "validation" which changes field values
        if ($editable && ($action == "insert" || $action == "update")) {
            switch( $validate ) {
                case 'date':
                    $foo_datectrl_name = new datectrl($varname);

                    if ($$varname != "") {                  // loaded from defaults
                        $foo_datectrl_name->setdate_int($$varname);
                    }
                    $foo_datectrl_name->ValidateDate($f["name"], $err, $f["required"], $default_val);
                    $$varname = $foo_datectrl_name->get_date();  // write to var
                    break;
                case 'bool':
                    $$varname = ($$varname ? 1 : 0);
                    break;
                case 'pwd':
                    $change_varname   = $varname.'a';
                    $retype_varname   = $varname.'b';
                    $delete_varname   = $varname.'d';

                    global $$change_varname, $$retype_varname, $$delete_varname;

                    if ($$change_varname && ($$change_varname == $$retype_varname)) {
                        $$varname = ParamImplode(array('AA_PASSWD',$$change_varname));
                    } elseif ($$delete_varname) {
                        $$varname = '';
                    } elseif ($action == "update") {
                        // store the original password to use it in
                        // insert_fnc_pwd when it is not changed
                        // $$varname = $oldcontent4id[$pri_field_id][0]['value'];
                        $$varname = ParamImplode(array('AA_PASSWD_CRYPTED',$oldcontent4id[$pri_field_id][0]['value']));
                    }
                    break;
            }
        }

        // Run the validation which really only validates
        if ($do_validate && ($action == "insert" || $action == "update")) {
            // special setting for file upload - there we solve the problem
            // of required fileupload field, but which is empty at this moment
            if ( $f["required"] AND (substr($f["input_show_func"], 0,3) === 'fil')) {
                ValidateInput($varname, $f["name"], $$varname. $GLOBALS[$varname.'x'] , $err, // status code is never required
                    $f["required"] ? 1 : 0, $f["input_validate"]);
                continue;
            }

            switch( $validate ) {
                // necessary for 'unique' validation: do not validate if
                // the value did not change (otherwise would the value always
                // be found)
                case 'e-unique':
                case 'unique':

                    // fill field with curent field, if not filled and
                    // add $id, so we do not find the currently edited item when
                    // we are looking for uniqueness
                    list($v_func,$v_field,$v_scope) = ParamExplode($f["input_validate"]);
                    if (!$v_field) {
                        $v_field = $pri_field_id;
                    }
                    $v_type = ParamImplode(array($v_func,$v_field,$v_scope,$id));
                    ValidateInput($varname, $f["name"], $$varname, $err, $f["required"] ? 1 : 0, $v_type);

                    break;
                case 'user':
                    // this is under development.... setu, 2002-0301
                    // value can be modified by $$varname = "new value";
                    $$varname = usr_validate($varname, $f["name"], $$varname, $err, $f, $fields);
                    break;
                //case 'text':
                //case 'url':
                //case 'email':
                //case 'number':
                //case 'id':
                default:
                    // status code is never required
                    ValidateInput($varname, $f["name"], $$varname, $err, ($f["required"] AND ($pri_field_id!='status_code.....')) ? 1 : 0, $f["input_validate"]);
                    break;
            }
        }
    }
}

?>
