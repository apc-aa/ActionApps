<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
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
* Form utility functions
*/

require_once $GLOBALS["AA_INC_PATH"]."constedit_util.php3";
require_once $GLOBALS["AA_INC_PATH"]."javascript.php3";
require_once $GLOBALS["AA_INC_PATH"]."profile.class.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
// IsUserNameFree() function deffinition here
require_once($GLOBALS["AA_INC_PATH"] . "perm_" . PERM_LIB . ".php3");

define( 'AA_WIDTHTOR', '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;  &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>');

# Easy to redefine this functionality by changing the array below
# prefix is what goes in the selection box in "Edit Item",
# tag is what goes on the front of the id as stored in the database
# str is the string to display in the Related Items window
# Note that A M B are hard-coded in the Related Items Window param wizard,
# but any letters can be used, i.e. this table can be extended.
# Next step might be to extend parameter recognition to load this table
# Retaining backward compatability with "[AMB]+" recognition
global $tps;
$tps = array (
  'AMB' => array (
    'A' => array ( 'prefix' => '>> ',   'tag' => 'x', 'str' => _m("Add") ),
    'M' => array ( 'prefix' => '<> ',   'tag' => 'y', 'str' => _m("Add&nbsp;Mutual") ),
    'B' => array ( 'prefix' => '<< ',   'tag' => 'z', 'str' => _m("Backward") ) ),
  'GYR' => array (
    'G' => array ( 'prefix' => 'Good:', 'tag' => 'x', 'str' => _m("Good") ),
    'Y' => array ( 'prefix' => 'OK  :', 'tag' => 'y', 'str' => _m("OK") ),
    'R' => array ( 'prefix' => 'Bad :', 'tag' => 'z', 'str' => _m("Bad") ) ) );

/** */
function varname4form($fid, $type='normal') {
    $additions = array( 'normal' => '', 'multi' => '[]', 'file' => 'x' );
    return 'v'. unpack_id($fid) .$additions[$type];
}

/** Returns inputform template */
function GetInputFormTemplate() {
     global $slice_id;
     $slice = new slice($slice_id);
     list($fields, $prifields) = $slice->fields();
     $form = new inputform($inputform_settings);
     return $form->getForm('', $fields, $prifields, false, $slice_id);
}

/**
 * inputform class - used for displaying input form (item add/edit)
 */
class inputform {
    var $display_aa_begin_end;
    var $page_title;
    var $show_func_used;
    var $js_proove_fields;
    var $form_action;
    var $form4update;
    var $show_preview_button;
    var $cancel_url;
    var $messages;
    var $result_mode;

    var $template;        // if you want to display alternate form design,
                          // template holds view_id of such template
                          // view type 'inputform'

    var $msg;             // stores return code from functions


    // required - class name (just for PHPLib sessions)
    var $classname = "inputform";

    /** Constructor - initializes inputform  */
    function inputform($settings) {
        $this->display_aa_begin_end = $settings['display_aa_begin_end'];
        $this->page_title           = $settings['page_title'];
        $this->show_func_used       = $settings['show_func_used'];
        $this->js_proove_fields     = $settings['js_proove_fields'];
        $this->form_action          = $settings['form_action'];
        $this->form4update          = $settings['form4update'];
        $this->show_preview_button  = $settings['show_preview_button'];
        $this->cancel_url           = $settings['cancel_url'];
        $this->messages             = $settings['messages'];
        $this->result_mode          = $settings['result_mode'];  // if not supplied, standard form is used
        $this->template             = $settings['template'];
    }

    function printForm($content4id, $fields, $prifields, $edit, $slice_id) {
        global $sess;
        if ( $this->display_aa_begin_end ) {
            HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
/*            echo '
                <style>
                    #body_white_color { color: #000000; }
                </style>';*/
            echo GetFormJavascript($this->show_func_used, $this->js_proove_fields);
            echo '
                <title>'. $this->page_title .'</title>
              </head>
              <body id="body_white_color">
                <H1><B>' . $this->page_title .'</B></H1>';
            PrintArray( $this->messages['err'] );     // prints err or OK messages

            if ( $this->show_func_used['fil']) { # uses fileupload?
                  $html_form_type = 'enctype="multipart/form-data"';
            }
        }

        echo "<form name=inputform $html_form_type method=post
                    action=\"" . $this->form_action .'"'.
                    getTriggers ("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()")).'>';
        FrmTabCaption( '', 'class="inputtab"', 'class="inputtab2"' );

        // get the default form and FILL CONTENTCACHE with fields
        $form = $this->getForm($content4id, $fields, $prifields, $edit);

        // design of form could be customized by view
        if ( $this->template AND ($view_info = GetViewInfo($this->template))) {
            // we can use different forms for 'EDIT' and 'ADD' item => (even/odd)
            $form =   ($edit AND $view_info['even_odd_differ']) ?
                                $view_info['even'] : $view_info['odd'];
            $remove_string =    $view_info['remove_string'];
        }

//        debug( $form, $GLOBALS['contentcache']);

        // print the inputform
        $CurItem = new item($content4id, GetAliasesFromFields($fields), '', $form, $remove_string);   # just prepare
        echo $CurItem->get_item();

        $buttons['MAX_FILE_SIZE']       = array('value' => IMG_UPLOAD_MAX_SIZE );
        $buttons['encap']               = array('value' => (($encap) ? "true" : "false"));
        $buttons['vid']                 = array('value' => $vid);
        if ( $this->form4update ) {
            $buttons[]                  = 'update';
            if ( $this->show_preview_button ) {
                $buttons['upd_preview'] = array('type'=>'submit', 'value'=>_m("Update & View"));
            }
            $buttons['insert']          = array('type'=>'submit', 'value'=>_m("Insert as new"));
            $buttons['reset']           = array('type'=>'reset',  'value'=>_m("Reset form"));
        } else {
            $buttons[]                  = 'insert';
            $buttons['ins_preview']     = array('type'=>'submit', 'value'=>_m("Insert & View"));
        }
        $buttons['cancel']              = array('type'=>'button', 'value'=>_m("Cancel"),
                                                'add'=>'onclick="document.location=\''.$this->cancel_url.'\'"');
        FrmTabEnd( $buttons, $sess, $slice_id );
        echo '</form>';
        if ( $this->display_aa_begin_end ) {
            echo "</body></html>";
        }
    }

    /** Shows the Add / Edit item form fields
    *   @param $show is used by the Anonymous Form Wizard, it is an array
    *                (packed field id => 1) of fields to show
    */
    function getForm($content4id, $fields, $prifields, $edit, $show="") {
        global $slice_id, $auth, $profile;

        if( !isset($prifields) OR !is_array($prifields) )
            return MsgErr(_m("No fields defined for this slice"));

        if ( !is_object( $profile ) ) {
            $profile = new aaprofile($auth->auth["uid"], $slice_id);  // current user settings
        }

        $form4anonymous_wizard = is_array($show);

        foreach($prifields as $pri_field_id) {
            $f           = $fields[$pri_field_id];
            $varname     = 'v'. unpack_id($pri_field_id);
            $htmlvarname = $varname."html";

            if ( ($form4anonymous_wizard  AND !$show[$f['id']]) OR
                 (!$form4anonymous_wizard AND (!$f["input_show"] OR
                                         $profile->getProperty('hide',$f['id']) OR
                                         $profile->getProperty('hide&fill',$f['id'])))) {
                // do not show this field
                continue;
            }

            // ----- collect all field_* parameters in order we can call display function

            // field_mode - how to display the field
            $field_mode = !IsEditable($content4id[$pri_field_id], $f, $profile) ?
                          'freeze' : ($form4anonymous_wizard ? 'anonym' : 'normal');

            // $field_value and $field_html_flag

            if( $edit ) {
                $field_value     = $content4id[$pri_field_id];
                $field_html_flag = $content4id[$pri_field_id][0]['flag'] & FLAG_HTML;
            } else {     # insert or new reload of form after error in inserting
                # first get values from profile, if there are some predefined value
                $foo = $profile->getProperty('predefine',$f['id']);
                if( $foo AND !$GLOBALS[$varname]) {
                    $x                     = $profile->parseContentProperty($foo);
                    $GLOBALS[$varname]     = $x[0];
                    $GLOBALS[$htmlvarname] = $x[1];
                }

                # get values from form (values are filled when error on form ocures
                if( $f["multiple"] AND is_array($GLOBALS[$varname]) ) {
                      # get the multivalues
                    foreach( $GLOBALS[$varname] as $value ) {
                        $field_value[] = array('value' => $value);
                    }
                } else {
                    $field_value[0]['value'] = $GLOBALS[$varname];
                }
                $field_html_flag = (((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
            }
            // Display the field
            $aainput = new aainputfield($field_value, $field_html_flag, $field_mode);
            $aainput->setFromField($f);

            // do not return template for anonymous form wizard
            $ret .= $aainput->get($form4anonymous_wizard ? 'expand' : 'template');
        }
        return $ret;
    }
}

/**
 * aainputfield class - used for displaying input field
 */
class aainputfield {
    var $id;               // field id (like headline........) -
                           //  - we generate html variable name from id: v435...
    var $input_type;       // shortcut of 'input type' (like 'fld','sel','mse')
    var $varname;          // name of the field in html form
    var $param;            // parameter array for this inputtype (already exploded)
    var $name;             // user frieldly name 'Publish Date'
    var $value;            // current value of the field in [0]['value'] form
    var $html_flag;        // field (value) is/isn't HTML formated
    var $mode;             // 'normal' - just show input type
                           // 'freeze' - display as static
                           // 'anonym' - special display of field on anonym form
                           //            (used for passwords, ...)
    var $input_before;     // HTML code displayed before the field
    var $required;         // boolean - is this field required (needed)?
    var $input_help;       // help/description text for the field
    var $input_morehlp;    // url, where user will jump after (s)he click on '?'
    var $additional;       // additional code for input (like 'class="chleba")
    var $html_rb_show;     // show HTML/Plaintext radiobutton?

    // --- private ---
    var $result;           // result string (depends on result_mode if variables are as aliases or it is expanded)
    var $result_mode;      // expand | template | cache
                           // expand   - input fields are normally printed;
                           // template - field is printed as alias
                           //            {inputfield:...} and content is stored
                           //            into contentcache
                           // cache    - no result printed - only
                           //            the contentcache is filled
    var $value_modified;   // sometimes we have to modify value before output
    var $selected;         // helper array used for quick search, if specified
                           // value is selected (manily for multivalues)
    var $const_arr;        // array of constants used for selections (selectbox, radio, ...)
    var $msg;                         // stores return code from functions
    var $classname = "aainputfield";  // class name (just for PHPLib sessions)

    /** Constructor - initializes aainputfield  */
    function aainputfield($value='', $html_flag=true, $mode='normal',
                          $varname="", $name="", $add=false, $required=false,
                          $hlp="", $morehlp="", $arr=null) {
        $this->value             = is_array($value) ? $value : array( 0=>array('value'=>$value));
        $this->html_flag         = $html_flag;
        $this->mode              = $mode;
        $this->varname           = $varname;
        $this->name              = $name;
        $this->required          = $required;
        $this->input_help        = $hlp;
        $this->input_morehlp     = $morehlp;
        $this->additional        = $add;
        $this->const_arr         = $arr;
        $this->result_mode       = 'expand';
        $this->html_rb_show      = false;
        if ( !is_object($GLOBALS['contentcache']) ) $GLOBALS['contentcache'] = new contentcache;
    }

    /** Sets object variables according to field setting */
    function setFromField(&$field) {
        if (isset($field) AND is_array($field)) {
            $this->id            = $field['id'];
            $this->varname       = varname4form($this->id);
            $this->name          = $field['name'];
            $this->input_before  = $field['input_before'];
            $this->required      = $field['required'];
            $this->input_help    = $field['input_help'];
            $this->input_morehlp = $field['input_morehlp'];
            $funct = ParamExplode($field["input_show_func"]);
            $this->input_type    = $funct[0];
            $this->param         = array_slice( $funct, 1 );
            $this->html_rb_show  = $field["html_show"];
        }
    }

    // private methods - helper - data manipulation

    /** Joins all values to one long string separated by $delim */
    function implodaval($delim=',') {
        if ( isset($this->value) AND is_array($this->value) ) {
            foreach ( $this->value as $v ) {
                $res .= ($res ? $delim : ''). $v['value']; # add value separator just if field is filled
            }
        }
        return $ret;
    }

    /** Fills array used for list selection. Fill it from constant group or
      * slice.
      * It never refills the array (and we relly on this fact in the code)
      * @returns unpacked slice_id if array is filled from slice
      * (not so important value, isn't?)
      */
    function fill_const_arr($whichitems='active', $slice_field="", $zids='', $tagprefix=null) {
        if ( isset($this->const_arr) and is_array($this->const_arr) ) {  // already filled
            return;
        }
        if ( !($constgroup=$this->param[0]) ) {  // assignment
            $this->const_arr = array();
        } elseif ( substr($constgroup,0,7) == "#sLiCe-" ) { # prefix indicates select from items
            $sid = substr($constgroup, 7);
            switch ($whichitems ) {
                case 'all':    // show also pending and expired items
                               $this->const_arr = GetItemHeadlines( $sid, $slice_field,'','all', null, 'all');
                               break;
                case 'ids':    // show only zids, which are active items  (used for 'iso' input type)
                               $this->const_arr = GetItemHeadlines( $sid, $slice_field, $zids, 'ids', $tagprefix);
                               break;
                default:       // only active items - default
                               $this->const_arr = GetItemHeadlines( $sid, $slice_field);
                               break;
            }
            return $sid; // in most cases not very impotant information, but used in related() input type
        } else {
            $this->const_arr = GetConstants($constgroup);
        }
        if ( !isset($this->const_arr) OR !is_array($this->const_arr) ) {
            $this->const_arr = array();
        }
    }

    /** Modifies varname in case we need to display two (or more) inputs
     *  for one field (varname_modified is used insted of varname - if set).
     */
    function varname_modify($add) {
        return ($this->varname_modified = $this->varname . $add);
    }

    /** Returns curent varname */
    function varname() {
        return get_if($this->varname_modified, $this->varname);
    }

    /** input_type manipulation functions */
    function get_inputtype()      {  return $this->input_type; }
    function set_inputtype($type) {  $this->input_type = $type; }

    /** Grabs common variables from object. Internal function used as shortcut
      * in most of input functions (maybe all)
      */
    function prepareVars($valtype='first') {
        if     (isset($this->value_modified)) $val = $this->value_modified;
        elseif ($valtype == 'first')  $val = $this->value[0]['value'];
        else                          $val = $this->value;
        return array( $this->varname(), $val, $this->additional);
    }

    /** Echo wrapper - prints output to string insted of to output
     *  If result_mode is cache, no result is printed - only the cache is filled
     */
    function echoo($txt)   { if ( $this->result_mode != 'cache' ) $this->result .= $txt; }

    /** Similar function to echoo, but it allows to create print aliases
     *  for usage in templates. If 'template' result_mode is selected, then
     *  the output is filled with:
     *    {inputvar:<field_id>[:part[:param]]}
     *  like:
     *    {inputvar:abstract........}          // alias for abstract input field
     *    {inputvar:abstract........:html_rb}  // alias 'HTML' radiobutton for
     *                                         // abstract input field
     * Real content of created aliases is stored into $contentcache.
     * This costruct is used to allow users to create its own forms, where
     * aliases are automatically replaced by current item content/constants/...
     * @param $param is not used, yet
     * @param $aliasname used for alias name different from 'inputvar'
     */
    function echovar($txt, $part='', $param='', $aliasname='') {
        global $contentcache;
        if ( $this->result_mode == 'expand' ) {   // write directly to the output
            $this->echoo($txt);
        } else {
            if ($aliasname=='') { $aliasname='inputvar'; };
            $alias = $aliasname.':'.$this->id. ($part  ? ":$part"  : ''). ($param ? ":$param" : '');
            $contentcache->set($alias, $txt);
            $this->echoo('{'.$alias.'}');
        }
    }

    /** returns $ret_val if given $option is selected for current field */
    function if_selected($option, $ret_val) {
        # fill selected array from value
        if ( !isset( $this->selected ) ) {  // not cached yet => create selected array
            if( isset($this->value) AND is_array($this->value) ) {
                foreach ( $this->value as $v ) {
                    if( $v['value'] ) {
                        $this->selected[(string)$v['value']] = true;
                    }
                }
            }
        }
        return $this->selected[(string)$option] ? $ret_val : '';
    }

    /** Returns field as it should be displayed on screen (or at least template
     *  for the field with filled $contentcache object
     */
    function get( $result_mode='expand' ) {
        $this->result_mode = $result_mode;
        $this->echoo($this->input_before);
        switch ($this->mode. '_'. $this->input_type) {
            case 'freeze_chb': $this->value_modified = $this->value[0]['value'] ? _m("set") : _m("unset");
                               $this->staticText();       break;
            case 'freeze_wi2':
            case 'freeze_mse':
            case 'freeze_mch': $this->value_modified = $this->implodaval();
                               $this->staticText();       break;
            case 'freeze_pwd': $this->value_modified = $this->value[0] ? "*****" : "";
                               $this->staticText();       break;
            case 'freeze_dte': $datectrl = new datectrl($this->varname());
                               $datectrl->setdate_int($this->value[0]['value']);
                               $this->value_modified = $datectrl->get_datestring();
                               $this->staticText();       break;
            case 'freeze_txt':
            case 'freeze_hid':
            case 'freeze_edt':
            case 'freeze_fld':
            case 'freeze_rio':
            case 'freeze_sel':
            case 'freeze_fil':
            case 'freeze_pre':
            case 'freeze_tpr':
            case 'freeze_fil': $this->staticText();       break;
            case 'freeze_nul': break;

            case 'anonym_nul':
            case 'normal_nul': break;
            case 'anonym_chb':
            case 'normal_chb': $this->inputChBox();       break;
            case 'anonym_hid':
            case 'normal_hid': $this->hidden();           break;
            case 'anonym_fld':
            case 'normal_fld': $this->inputText(get_if($this->param[0],255), // maxlength
                                                get_if($this->param[1],60)); // fieldsize
                               break;
            case 'anonym_txt':
            case 'normal_txt': $this->textarea(get_if($this->param[0],4), 60);
                               break;
            case 'anonym_edt':
            case 'normal_edt': $this->richEditTextarea(
                                          get_if($this->param[0],10),       // rows
                                          get_if($this->param[1],70),       // cols
                                          get_if($this->param[2],'class')); // type
                               $GLOBALS['list_fnc_edt'][] = $this->varname();
                               break;
            case 'anonym_sel':
            case 'normal_sel': list(,$slice_field, $usevalue, $allitems) = $this->param;
                               $this->fill_const_arr($allitems, $slice_field);  // if we fill it there, it is not refilled in inputSel()
                               $this->inputSelect($usevalue);
                               break;
            case 'anonym_rio':
            case 'normal_rio': $this->inputRadio($this->param[1],   // ncols
                                                 $this->param[2]);  // move_right
                               break;
            case 'anonym_mch':
            case 'normal_mch': $this->varname_modify('[]');         // use slightly modified varname
                               $this->inputMultiChBox($this->param[1],   // ncols
                                                      $this->param[2]);  // move_right
                               break;
            case 'anonym_mse':
            case 'normal_mse': $selectsize = ($this->param[1] < 1) ? 5 :  $this->param[1];
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->inputMultiSelect($selectsize);
                               break;
            case 'anonym_fil':
            case 'normal_fil': list($accepts, $text, $hlp) = $this->param;
                               $this->inputFile($accepts, $text, $hlp);
                               break;
            case 'anonym_dte':
            case 'normal_dte': if( strstr($this->param[0], "'")) {     // old format
                                   $this->param = explode("'",$this->param[0]);
                               }
                               list($y_range_minus, $y_range_plus, $from_now, $display_time) = $this->param;
                               $this->dateSelect($y_range_minus, $y_range_plus, $from_now, $display_time);
                               break;
            case 'anonym_pre':
            case 'normal_pre': list(, $maxlength, $fieldsize, $slice_field, $usevalue, $adding, $secondfield, $add2constant) = $this->param;
                               # add2constant is used in insert_fnc_qte - adds new value to constant table
                               $this->fill_const_arr('active', $slice_field);  // if we fill it there, it is not refilled in inputSel()
                               $this->inputPreSelect($maxlength, $fieldsize, $adding, $secondfield, $usevalue );
                               break;
            case 'anonym_tpr':
            case 'normal_tpr': $this->textareaPreSelect(get_if($this->param[1],4),    // rows
                                                        get_if($this->param[2],60));  // cols
                               break;
            case 'anonym_iso':
            case 'normal_iso':
            case 'freeze_iso': list(, $selectsize, $mode, $design, $tp, $movebuttons, $frombins, $conds, $condsrw) = $this->param;
                               $mode      = get_if($mode,'AMB');         # AMB - show 'Add', 'Add mutual' and 'Add backward' buttons
                               $tp        = get_if($tp,  'AMB');         # Default to use the AMP table
                               $tagprefix = ( isset($GLOBALS['tps'][$tp])              ? $GLOBALS['tps'][$tp] :
                                            ( isset($GLOBALS['apc_state']['tps'][$tp]) ? $GLOBALS['apc_state']['tps'][$tp] :
                                                                                        null ));
                               if ( is_null($tagprefix) ) {
                                   $this->msg[] = _m("Unable to find tagprefix table %1", array($tp));
                               }
                               $this->varname_modify('[]');         // use slightly modified varname
                               $sid = $this->fill_const_arr('ids', "", $this->value, $tagprefix=null);  // if we fill it there, it is not refilled in inputSel()
                               if ( $this->mode == 'freeze' ) {
                                   $this->value_modified = $this->implodaval('<br>');
                                   $this->staticText();
                               } else {
                                   $this->related($selectsize, $sid, $mode, $design, $movebuttons, $frombins, $conds, $condsrw);
                               }
                               break;
            case 'anonym_hco':
            case 'normal_hco': list($constgroup, $levelCount, $boxWidth, $size, $horizontalLevels, $firstSelectable, $levelNames) = $this->param;
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->hierarchicalConstant($constgroup, $levelCount, $boxWidth, $size, $horizontalLevels, $firstSelectable, explode('~',$levelNames));
                               break;
            case 'anonym_wi2':
            case 'normal_wi2': list($constgroup, $size, $wi2_offer, $wi2_selected) = $this->param;
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->twoBox(get_if($size,5), $wi2_offer, $wi2_selected);
                               break;
            case 'anonym_pwd':  // handled in passwordModify
            case 'normal_pwd': list($fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help) = $this->param;
                               $this->passwordModify( $fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help);
                               break;
        }
        return $this->result;
    }

    function print_result() { echo $this->result; }


    // pivate functions - functions helping field display ---------------------

    /** functions to show additional field data */
    function needed()       { if( $this->required ) $this->echoo( "&nbsp;*" ); }
    function help($hlp)     { if( $hlp )            $this->echoo( "<div class=tabhlp>$hlp</div>" ); }
    function morehelp($hlp) { if( $hlp )            $this->echoo( "&nbsp;<a href=".safe($hlp)." target='_blank'>?</a>" ); }

    /** shows help message and link to more help document, if set */
    function helps( $plus=false, $hlp=null, $more_hlp=null ) {
        $this->morehelp(is_null($more_hlp) ? $this->input_morehlp : $more_hlp );
        $this->help(    is_null($hlp)      ? $this->input_help    : $hlp );
        if ( $plus )  $this->echoo("</td>\n</tr>\n");
    }

    /** Prints field name (and 'needed' sign - star) in table cell for inputform*/
    function field_name( $plus=false, $colspan=1, $name=null ) {
        $name = is_null($name) ? $this->name : $name;
        if ( $plus=='plus' ) $this->echoo("\n<tr align=left>");
        $this->echoo("\n <td class=\"tabtxt\" ".
                      (($colspan==1) ? '': "colspan=\"$colspan\"").
                      '><b>'. $name .'</b>');
        $this->needed();
        $this->echoo("</td>\n");
        if ( $plus=='plus' ) $this->echoo(' <td>');
    }

    /** Print links to document convertors, if convertors are installed */
    function get_convertors() {
        global $CONV_HTMLFILTERS, $AA_INSTAL_PATH;
        if( isset($CONV_HTMLFILTERS) AND is_array($CONV_HTMLFILTERS) ) {
            $delim='';
            foreach( $CONV_HTMLFILTERS as $format => $program) {
               if ( $format == 'iconv' )
                    continue;
                $convertor .= $delim . strtoupper(str_replace( '.', '', $format ));
                $delim = '/';
            }
            $convertor = "<a href=\"javascript:CallConvertor('".self_server().$AA_INSTAL_PATH."', '".$this->varname."')\">$convertor "._m('import') ."</a>";
        }
        return $convertor;
    }

    /** Prints html/plan_text radiobutton */
    function html_radio($convert=false, $show_rp_butt=true) {
        global $sess;
        if( $this->html_rb_show ) {
            $htmlvar     = $this->varname."html";
            $radio_html  = "<input type=\"radio\" name=\"$htmlvar\" value=\"h\"". (  $this->html_flag ? " checked>" : ">" )."</input>";
            $radio_plain = "<input type=\"radio\" name=\"$htmlvar\" value=\"t\"". ( !$this->html_flag ? " checked>" : ">" )."</input>";
//        debug($this->varname, $this->html_flag, !$this->html_flag,$radio_html, $radio_plain );
            $htmlareaedit= "<a href=\"javascript:openHTMLAreaFullscreen('".$this->varname."', '".$sess->id."');\">"._m("Edit in HTMLArea")."</a>"; // used for HTMLArea
            // conversions menu
            if( $convert AND ($convertor = $this->get_convertors())) {
                $this->echoo('  <table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="'. COLOR_TABBG ."\">\n   <tr><td>");
                if ($show_rp_butt) {
                    $this->echoo('<!-- used for hiding html/plain radio buttons, dont remove !!! --><span id="htmlplainspan">');
                    $this->echovar($radio_html,  'html_rb');
                    $this->echoo(_m("HTML"));
                    $this->echovar($radio_plain, 'plain_rb');
                    $this->echoo(_m("Plain text"));
                    $this->echoo('</span>');
                    $this->echoo("&nbsp;&nbsp;");
                    $this->echoo('<!-- used for hiding "edit in htmlarea" link, dont remove !!! --><span id="arealinkspan'.$this->varname.'" style="display: none">');
                    $this->echovar($htmlareaedit, 'htmlarea_edit_link');
                    $this->echoo('</span>');
                }
                $this->echoo("</td>");
                $this->echoo("<td align=right>");
                $this->echovar($convertor,   'conv');
                $this->echoo("</td></tr>\n  </table>");
            } else {
                if ($show_rp_butt) {
                    $this->echoo('<!-- used for hiding html/plain radio buttons, dont remove !!! --><span id="htmlplainspan">');
                    $this->echovar($radio_html,  'html_rb');
                    $this->echoo(_m("HTML"));
                    $this->echovar($radio_plain, 'plain_rb');
                    $this->echoo(_m("Plain text"));
                    $this->echoo('</span>');
                    $this->echoo("&nbsp;&nbsp;");
                    $this->echoo('<!-- used for hiding "edit in htmlarea" link, dont remove !!! --><span id="arealinkspan'.$this->varname.'" style="display: none">');
                    $this->echovar($htmlareaedit, 'htmlarea_edit_link');
                    $this->echoo('</span>');
                }
                $this->echoo("<br>\n");
            }
        }
    }

    // pivate functions - field specific helper functions ---------------------

    /** Returns one radio tag - Used in inputRadio */
    function getRadioButtonTag(&$k, &$v) {
        $name = $this->varname();
        $ret = "<input type='radio' name='$name' value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
        $ret .= $this->if_selected($k, " checked");
        $ret .= ">".htmlspecialchars($v);
        return $ret;
    }

    /** Returns one checkbox tag - Used in inputMultiChBox */
    function getOneChBoxTag(&$k, &$v) {
        $name = $this->varname();
        $ret = "<nobr><input type='checkbox' name='$name'
             value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
        $ret .= $this->if_selected($k, " checked");
        $ret .= ">".htmlspecialchars($v)."</nobr>";
        return $ret;
    }

    // field displaying functions ---------------------------------------------


    /** Prints html tag <input type=checkbox .. to 2-column table
     *  for use within <form> and <table> tag
     */
    function inputChBox($changeorder=false, $colspan=1){
        list($name,$val,$add) = $this->prepareVars();

        $this->echoo("\n<tr align=left>");
        if( !$changeorder ) {
            $this->field_name(false, $colspan);
        }
        $this->echoo("<td>");
        $this->echovar("<input type=\"checkbox\" name=\"$name\" $add ".
                              ($val ? " checked" : '').
                              getTriggers("input",$name).">");
        $this->helps();
        $this->echoo("</td>");
        if( $changeorder ) {
            $this->field_name($colspan);
        }
        $this->echoo("</tr>\n");
    }

    function dateSelect($y_range_minus=5, $y_range_plus=5, $from_now=false, $display_time=false) {
        list($name,$val,$add) = $this->prepareVars();

        $datectrl = new datectrl($this->varname(), $y_range_minus, $y_range_plus, $from_now, $display_time);
        $datectrl->setdate_int($val);
        $this->field_name('plus');

        $this->echovar( $datectrl->getdayselect(),   'day'  );
        $this->echovar( $datectrl->getmonthselect(), 'month');
        $this->echovar( $datectrl->getyearselect(),  'year' );
        $this->echovar( $datectrl->gettimeselect(),  'time' );
//        $this->echovar(datum($this->varname(), $val, $y_range_minus, $y_range_plus, $from_now, $display_time));
        $this->helps('plus');
    }

    /**
    * Prints html tag <input type=text .. to 2-column table
    * for use within <form> and <table> tag.
    *
    * @param string $type allows to show <INPUT type=PASSWORD> field as well
    *                     (and perhaps BUTTON and SUBMIT also, but I do not see
    *                      any usage) - added by Jakub, 28.1.2003
    */
    function inputText($maxsize=254, $size=25, $type="text") {
        list($name,$val,$add) = $this->prepareVars();
        $val     = htmlspecialchars($val);
        $maxsize = get_if( $maxsize, 254 );
        $size    = get_if( $size   , 25 );

        $this->field_name('plus');
        $this->html_radio();
        $this->echovar( "<input type=\"$type\" name=\"$name\" size=\"$size\"".
                        " maxlength=\"$maxsize\" value=\"$val\"".getTriggers("input",$name).">" );
        $this->helps('plus');
    }


    /**
    * Prints two static text to 2-column table
    * for use within <table> tag
    */
    function staticText($safing=true, $type='first') {
        list($name,$val,$add) = $this->prepareVars($type);
        if( $safing ) $val=htmlspecialchars($val);
        $this->field_name('plus');
        $this->echovar( $val );
        $this->helps('plus');
    }

    /**
    * Prints html tag <input type=hidden .. to 2-column table
    * for use within <form> and <table> tag
    */
    function hidden($safing=true ) {
        list($name,$val,$add) = $this->prepareVars();
        if( $safing ) $val=htmlspecialchars($val);
        $this->echoo('<tr height="1" colspan="2"><td height="1">');
        $this->echovar( "<input type=\"hidden\" name=\"$name\" value=\"$val\">" );
        $this->echoo("</td></tr>\n");
    }

    /**
    * Prints html tag <textarea .. to 2-column table
    * for use within <form> and <table> tag
    *
    * $showrich_href - have we show "Show Editor" link? (if we want to, we have
    *                  to include /misc/htmlarea/aafunc.js script to the page
    */
    function textarea( $rows=4, $cols=60, $single=false, $showrich_href=true, $showhtmlarea=false) {

        global $BName, $BPlatform, $sess;

        list($name,$val,$add) = $this->prepareVars();
        $val = htmlspecialchars($val);
        $colspan = $single ? 2 : 1;
        $this->echoo("<tr align=left>");
        $this->field_name(false, $colspan);
        if ($single) {
            $this->echoo("</tr>\n<tr align=left>");
        }
        $this->echoo("<td colspan=\"$colspan\">");
        $this->html_radio($showhtmlarea ? false : 'convertors');
        $tarea .= "<textarea id=\"$name\" name=\"$name\" rows=\"$rows\" cols=\"$cols\"".getTriggers("textarea",$name).">$val</textarea>\n";
        if ($showhtmlarea) {
            $tarea .= '
            <script type="text/javascript" language="javascript"><!--
                generateArea("'.$name.'", true, '.(AA_HTMLAREA_SPELL_CGISCRIPT ? "true" : "false").', "'.$rows.'", "'.$cols.'", "'.$sess->id.'");
            //--></script>';
        } elseif ( $showrich_href ) {
                        $tarea .= '
            <script type="text/javascript" language="javascript"><!--
                showHTMLAreaLink("'.$name.'");
            //--></script>';
        }
        $this->echovar($tarea);
        $this->helps('plus');
    }

    /**
    * On browsers which do support it, loads a special rich text editor with many
    * advanced features based on triedit.dll
    * On the other browsers, loads a normal text area
    */

    function richEditTextarea($rows=10, $cols=80, $type="class", $single="") {
        /*
        global $BName, $BPlatform;
        if ( !richEditShowable() ) {
            $this->textarea($rows, $cols, $single, $BName != "MSIE");
            return;
        }

        list($name,$val,$add) = $this->prepareVars();

        $colspan = $single ? 2 : 1;
        $this->echoo("<tr>");
        $this->field_name(false, $colspan);
        if ($single) {
            $this->echoo("</tr>\n<tr>");
        }
        $this->echoo("<td colspan=\"$colspan\">");

        $val = ( !$this->html_flag ? // text only
            str_replace("\r","",str_replace ("\n","", nl2br(htmlspecialchars ($val,ENT_QUOTES)))) :
            str_replace( array("'","\n","\r"), array("\\'","\\n","\\r"), $val));

        if (!$BName || !$BPlatform) detect_browser();

        $this->echoo("<!-- Browser $BName -->");
        if     ($type == "iframe") $richedit = "richedit_iframe";
        elseif ($BName == "MSIE")  $richedit = "richedt_ie";
        else                       $richedit = "richedit_ns";

        $this->echoo(
            "<script language=\"javascript\" type=\"text/javascript\">
            <!--
            var edt$name"."_doc_complet = $doc_complet;
            var edt = \"edt$name\";
            var edtdoc = \"edt$name.document\";
            var richHeight = ".($rows * 22).";
            var richWidth = ".($cols * 8).";
            var imgpath = '../misc/wysiwyg/images/';

            richedits[richedits.length] = '".$name."';
            // -->
        </script>
        <script language=\"javascript\"  type=\"text/javascript\" src=\"../misc/wysiwyg/".$richedit.".js\">
        </script>
        <script language=\"javascript\" type=\"text/javascript\" src=\"../misc/wysiwyg/".$richedit.".html\">
        </script>");

        $q_val = str_replace('"', '\"', $val);   // quote quotes for tag attribs

        $this->echovar("
            <script language =\"javascript\"  type=\"text/javascript\">
                <!--
                edt$name"."_timerID=setInterval(\"edt$name"."_inicial()\",100);
                var edt$name"."_content = \"$q_val\";
                function edt$name"."_inicial() {
                    //change_state ('edt$name');
                    posa_contingut_html('edt$name',edt$name"."_content);
                    //change_state ('edt$name');
                    clearInterval(edt$name"."_timerID);
                    return true;
                }
                // -->
            </script>
            <input type=\"hidden\" name=\"$name\" value=\"$q_val\">
            <input type=\"hidden\" name=\"${name}html\" value=\"h\">");
        $this->helps('plus');
        */
        $this->textarea($rows, $cols, false, false, true);
    }


    /**
    * Prints a radio group, html tags <input type="radio" .. to 2-column table
    * for use within <form> and <table> tag
    */
    function inputRadio($ncols=0, $move_right=true) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $this->fill_const_arr();
        foreach ( $this->const_arr as $k => $v ) {
            $records[] = $this->getRadioButtonTag($k, $v);
        }
        $this->printInMatrix_Frm($records, $ncols, $move_right);
    }


    /**
    * Prints html tag <input type="radio" .. to 2-column table
    * for use within <form> and <table> tag
    */
    function inputMultiChBox($ncols=0, $move_right=true) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $this->fill_const_arr();
        foreach ( $this->const_arr as $k => $v ) {
            $records[] = $this->getOneChBoxTag($k, $v);
        }
        $this->printInMatrix_Frm($records, $ncols, $move_right);
    }

    /**
    * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
    * - for use internal use of FrmInputMultiChBox and FrmInputRadio
    */
    function printInMatrix_Frm($records, $ncols, $move_right) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $this->field_name('plus');

        if (is_array ($records)) {
            if (! $ncols) {
                $this->echovar( implode($records) );
            } else {
                $nrows = ceil (count ($records) / $ncols);
                $this->echoo('<table border="0" cellspacing="0">');
                for ($irow = 0; $irow < $nrows; $irow ++) {
                    $ret .= '<tr>';
                    for ($icol = 0; $icol < $ncols; $icol ++) {
                        $pos = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
                        $ret .= '<td>'. get_if($records[$pos], "&nbsp;") .'</td>';
                    }
                    $ret .= '</tr>';
                }
                $this->echovar($ret);
                $this->echoo('</table>');
            }
        }
        $this->helps('plus');
    }

    /** returns select options created from given array */
    function get_options( &$arr, $usevalue=false, $testval=false, $restrict='all', $add_empty=false) {
        $selectedused = false;
        if( isset($arr) && is_array($arr) ) {
            foreach ( $arr as $k => $v ) {
                if( $usevalue ) $k = $v;    // special parameter to use values instead of keys
                $select_val = $testval ? $v : $k;
                $selected   = $this->if_selected($select_val, ' selected class="sel_on"');
                if ($selected != '') {
                    $selectedused = true;
                    $already_selected[(string)$select_val] = true;  // flag
                }
                if ( ($restrict == 'selected')   AND !$selected ) continue;  // do not print this option
                if ( ($restrict == 'unselected') AND $selected  ) continue;  // do not print this option
                $ret .= "<option value=\"". htmlspecialchars($k) ."\" $selected>".htmlspecialchars($v)."</option>";
            }
        }
        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if (isset( $this->selected ) AND is_array( $this->selected ) AND ($restrict != 'unselected')) {
            foreach ( $this->selected as $k =>$v ) {
                if ( !$already_selected[$k] ) {
                    $ret .= "<option value=\"". htmlspecialchars($k) ."\" selected class=\"sel_missing\">".htmlspecialchars($k)."</option>";
                    $selectedused = true;
                }
            }
        }
        if ( $add_empty ) {
            $ret .= '<option value=""';
            if ($selectedused == false) $ret .= ' selected class="sel_on"';
           $ret .= '> </option>';
        }
        return $ret;
    }

    /**
    * Prints html tag <select multiple .. to 2-column table
    * for use within <form> and <table> tag
    */
    function inputMultiSelect($size=6, $relation=false, $minrows=0, $mode='AMB', $design=false, $movebuttons=true, $frombins=3, $conds="", $condsrw="") {
        list($name,$val,$add) = $this->prepareVars('multi');
        $size                 = get_if($size, 6);
        $frombins             = get_if($frombins, AA_BIN_ACTIVE | AA_BIN_PENDING );  // =3
        if (!$relation) $this->fill_const_arr();

        $this->field_name('plus');
        $ret ="<select name=\"$name\" size=\"$size\" multiple".getTriggers("select",$name).">";
        $ret .= $this->get_options( $this->const_arr, false, false, 'all', ($relation ? false : !$this->required));
        $option_no = count($this->const_arr) + ($this->required ? 0:1);
        // add blank rows if asked for
        while( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
            $ret .= AA_WIDTHTOR;
        }
        $ret .= "</select>";

        if( !$relation )  { // all selection in this box should be selected on submit
            $this->echovar( $ret );
        } else {
            $this->echoo('<table border="0" cellspacing="0"><tr>');
            if ($movebuttons) { $this->echoo("\n <td rowspan=\"2\">");
            } else {
                $this->echoo("\n <td>");
            }
            $this->echovar( $ret );
            $this->echoo("</td>\n");
            if ($movebuttons) {
                 $this->echoo("<td valign=\"top\">");
                 $this->echoo("<input type=\"button\" value=\" /\ \" ".
                 " onClick=\"moveItem(document.inputform['".$name."'],'up');\">");
                 $this->echoo('</td></tr>');
                 $this->echoo('<tr><td valign="bottom">');
                 $this->echoo("<input type=\"button\" value=\" \/ \" ".
                 " onClick=\"moveItem(document.inputform['".$name."'], 'down');\">");
                 $this->echoo("</td>");
            }
            $this->echoo("</tr>\n <tr><td valign=\"bottom\"><center>
              <input type='button' value='". _m("Add") ."' onclick='OpenRelated(\"$name\", \"$relation\", \"$mode\", \"$design\", \"$frombins\",\"".rawurlencode($conds)."\",\"".rawurlencode($condsrw)."\" )'>
              &nbsp;&nbsp;");
/*              <input type='button' value='". _m("Delete") ."' size='250'
                onclick='document.inputform.elements[\"$name\"].options[document.inputform.elements[\"$name\"].selectedIndex].value=\"wIdThTor\";
                         document.inputform.elements[\"$name\"].options[document.inputform.elements[\"$name\"].selectedIndex].text=\"\";'>*/
            $this->echoo("<input type='button' value='". _m("Delete") ."' size='250' onclick=\"removeItem(document.inputform['".$name."']);\"></center>
              <SCRIPT Language=\"JavaScript\" type=\"text/javascript\"><!--

                 listboxes[listboxes.length] = '$name'
                // -->
              </SCRIPT>\n" );
            $this->echoo("</td></tr></table>\n");
        }
        $this->helps('plus');
    }


    /**
    *  shows boxes allowing to choose constant in a hiearchical way
    */
    function hierarchicalConstant($group_id, $levelCount, $boxWidth, $size, $horizontal=0, $firstSelect=0, $levelNames="") {
        list($name,$val,$add) = $this->prepareVars('multi');
        $levelCount = get_if( $levelCount, 3 );
        $size       = get_if( $size      , 5 );

        $this->field_name('plus');
        $this->echoo( getHierConstInitJavaScript ($group_id, $levelCount, "inputform", false) );
        $this->echoo( getHierConstBoxes ($levelCount, $horizontal, $name, false, $firstSelect, $boxWidth, $levelNames) );

        $widthTxt = str_repeat("m",$boxWidth);

        $this->echoo("
            <TABLE border=0 cellpadding=2 width='100%'><TR>
            <TD align=center><b><span class=redtext>Selected:<span></b><BR><BR><INPUT TYPE=BUTTON VALUE='Delete' onclick='hcDelete(\"$name\")'></TD>
            <TD>");
        $out = "<SELECT name='$name' MULTIPLE size=$size".getTriggers("select",$name).">";
            if (is_array($val)) {
                $constants_names = GetConstants($group_id);
                foreach( $val as $v) {
                    if ($v['value']) {
                        $out .= "<option value=\"".htmlspecialchars($v['value'])."\">".htmlspecialchars($constants_names[$v['value']])."\n";
                    }
                }
            }
        $out .= "<OPTION value='wIdThTor'>$widthTxt";
        $out .= "</SELECT>";
        $this->echovar($out);
        $this->echoo("</TD></TR></TABLE>
          <script language=\"javascript\" type=\"text/javascript\"><!--\n
            hcInit();
            hcDeleteLast ('$name');
            listboxes[listboxes.length] = '$name';
            // -->\n
            </script>\n");
        $this->helps('plus');
    }

    /**
    * Prints html tag <select .. to 2-column table
    * for use within <form> and <table> tag
    */
    function inputSelect($usevalue=false) {
        list($name,$val,$add) = $this->prepareVars();
        $this->fill_const_arr();

        $this->field_name('plus');
        $out = "<select name=\"$name\"".getTriggers("select",$name).">";
        $out .= $this->get_options( $this->const_arr, $usevalue, false, 'all', !$this->required );
        $out .= "</select>";
        $this->echovar( $out );
        $this->helps('plus');
    }


    /**
    * Prints html tag <input type=file .. to 2-column table
    * for use within <form> and <table> tag
    */
    function inputFile($accepts="image/*", $text="", $hlp="") {
        list($name,$val,$add) = $this->prepareVars();
        $size=60;
        $this->inputText(255,$size);
        if ( $accepts ) {
            $this->name       = $text;
            $this->input_help = $hlp;
            $this->field_name('plus');
            $file_field_name = $name.'x';
            $this->echovar( "<input type=\"file\" name=\"$file_field_name\" size=\"$size\" accept=\"$accepts\"".getTriggers("input",$file_field_name).">", 'file');
            $this->helps('plus');
        }
    }

    /**
    * Prints html tag <intup type=text ...> with <select ...> as presets to 2-column
    * table for use within <form> and <table> tag
    */
    function inputPreSelect($maxsize=254, $size=25, $adding=0, $secondfield="", $usevalue=false) {
        list($name,$val,$add) = $this->prepareVars();
        $this->fill_const_arr();

        $val=safe($val);
        $maxsize = get_if( $maxsize, 254 );
        $size    = get_if( $size   , 25 );
        if ($secondfield) {
            $varsecfield = varname4form($secondfield);
        }

        $this->field_name('plus');
        $this->html_radio();

        $this->echovar("<input type=\"Text\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"".getTriggers("input",$name).">");
        $out = "<select name=\"foo_$name\"";
        if ($secondfield) {
            $out .= "onchange=\"$name.value=this.options[this.selectedIndex].text;";
            $out .= "$varsecfield.value=this.options[this.selectedIndex].value\">";
        } else {
            $out .= ($adding ?
                     "onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">" :
                     "onchange=\"$name.value=this.options[this.selectedIndex].value\">");
        }
        $out .= $this->get_options( $this->const_arr, $usevalue, $secondfield);
        $out .= '</select>';
        $this->echovar( $out, 'presets' );
        $this->helps('plus');
    }

    function textareaPreSelect($rows=4, $cols=60) {
        list($name,$val,$add) = $this->prepareVars();
        $this->fill_const_arr();
        $val=safe($val);

        $this->field_name('plus');
        $this->echovar( "<textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual".getTriggers("textarea",$name).">$val</textarea>" );
        $out = "<select name=\"foo_$name\" onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">";
        $out .= $this->get_options( $this->const_arr );
        $out .= '</select>';
        $this->echovar( $out, 'presets' );
        $this->helps('plus');
    }

    function related($size, $sid, $mode, $design, $movebuttons=true, $frombins=3, $conds="", $condsrw="") {
        $this->inputMultiSelect($size, $sid, MAX_RELATED_COUNT, $mode, $design, $movebuttons, $frombins, $conds, $condsrw);
    }

    /**
    * Prints two boxes for multiple selection for use within <form> and <table> tag
    */
    function twoBox($size, $wi2_offer, $wi2_selected) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $this->fill_const_arr();
        $wi2_offer    = get_if( $wi2_offer,    _m("Offer") );
        $wi2_selected = get_if( $wi2_selected, _m("Selected") );

        $this->field_name('plus');
        $this->echoo("<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr align=left>
          <td align='CENTER' valign='TOP'>". $wi2_offer ."</td><td></td>
            <td align=\"CENTER\" valign=\"TOP\">". $wi2_selected ."</td></tr>
          <tr align=left><td align='CENTER' valign='TOP'>");

        $offername = str_replace("[]", "", $name). '_1';
        $out  = "<select name=\"".$offername."\" size=$size ".getTriggers("select",$name).">\n";
        $out .= get_if( $this->get_options( $this->const_arr, false, false, 'unselected'), AA_WIDTHTOR );
        $out  .= '</select>';
        $this->echovar( $out, 'unselected' );

        $this->echoo("</td>
          <td>&nbsp;&nbsp;<input type=\"button\" VALUE=\"  >>  \" onClick = \"MoveSelected(document.inputform.".$offername.",document.inputform['".$name."'])\" align=center>
              <br><br>&nbsp;&nbsp;<input type=\"button\" VALUE=\"  <<  \" onClick = \"MoveSelected(document.inputform['".$name."'],document.inputform.".$offername.")\" align=center>&nbsp;&nbsp;</td>
          <td align=\"CENTER\" valign=\"TOP\">");

        $out = "<select multiple name=\"".$name."\" size=$size  ".getTriggers("select",$name).">";
        $out .= get_if( $this->get_options( $this->const_arr, false, false, 'selected'), AA_WIDTHTOR );
        $out  .= '</select>';
        $this->echovar( $out, 'selected' );

        $this->echoo('
          <script language="javascript" type="text/javascript"><!--
            listboxes[listboxes.length] = \''. $name .'\';
            //-->
          </script>
          ');
        $this->echoo("
        </td></tr></table>");
        $this->helps('plus');
    }


    function passwordModify( $fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help) {
        list($name,$val,$add) = $this->prepareVars();
        $change_pwd_label = get_if($change_pwd_label, _m("Change Password"));
        $retype_pwd_label = get_if($retype_pwd_label, _m("Retype Password"));
        $delete_pwd_label = get_if($delete_pwd_label, _m("Delete Password"));
        $fieldsize        = get_if($fieldsize,        60);

        $this->field_name('plus');
        if ( $this->mode == 'anonym' ) {
            $this->echovar("<input type=\"password\" name=\"$name\" size=\"$fieldsize\" maxlength=\"255\" value=\"\"".getTriggers("input",$name).">" );
            $this->helps('plus');
        } else {
            $this->echovar( $val ? "*****" : _m("not set") );
            $this->echoo("</td></tr>\n");
        }

        if (!$this->required) {
            $this->field_name('plus',1,$delete_pwd_label);
            $ch_name = $name."d";
            $this->echovar("<input type=\"checkbox\" name=\"$ch_name\"". getTriggers("input",$ch_name).">", 'delete');
            $this->helps('plus',$delete_pwd_help );
        }

        // change pwd
        $this->field_name('plus',1,$change_pwd_label);
        $ch_name = $name."a";
        $this->echovar("<input type=\"password\" name=\"$ch_name\" size=\"$fieldsize\" maxlength=\"255\" value=\"\"".getTriggers("input",$ch_name).">", 'change' );
        $this->helps('plus',$change_pwd_help );

        // retype pwd
        $this->field_name('plus',1,$retype_pwd_label);
        $ch_name = $name."b";
        $this->echovar("<input type=\"password\" name=\"$ch_name\" size=\"$fieldsize\" maxlength=\"255\" value=\"\"".getTriggers("input",$ch_name).">", 'retype' );
        $this->helps('plus',$retype_pwd_help );
    }


}

// ----------------------- Public Form functions ----------

/** prints anchor tag with link to external documentation */
function FrmMoreHelp($hlp, $text="", $hint="", $image=false) {
    if ($image) {
        $img = GetAAImage('help50.gif', htmlspecialchars($hint), 16, 12);
    }
    if( $hlp ) {
        if (is_array($text) || ($image)) {
          return "&nbsp;".($image ? "&nbsp;&nbsp;" : $text["before"])."<a href=".safe($hlp)." target='_blank' ".
            (($hint != "") ? "title=\"".htmlspecialchars($hint)."\"" : "") .">".($image ? $img : $text["text"])."</a>".($image ? "" : $text["after"]);
        } elseif (is_string($text) && ($text != "")) {
            return "&nbsp;<a href=".safe($hlp)." target='_blank'>".($image ? $img : $text)."</a>";
        } else {
            return "&nbsp;<a href=".safe($hlp)." target='_blank' ".(($hint != "") ? "title=\"".htmlspecialchars($hint)."\"" : "").
               ">".($image ? $img : "?")."</a>";
        }
    } else {
        if (($text == "") && ($image)) {
          return "&nbsp;<abbr title=\"".htmlspecialchars($hint)."\">".$img."</abbr>";
        }
    }

}

/** Prints html tag <input type=checkbox .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmInputChBox($name, $txt, $checked=true, $changeorder=false, $add="", $colspan=1, $needed=false, $hlp="", $morehlp="") {
    $input = new aainputfield($checked, false, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputChBox($changeorder, $colspan);
    $input->print_result();
}


/** Prints html tag <input type=text .. to 2-column table
 *  for use within <form> and <table> tag.
 *
 * @param string $type allows to show <INPUT type=PASSWORD> field as well
 *                     (and perhaps BUTTON and SUBMIT also, but I do not see
 *                      any usage) - added by Jakub, 28.1.2003
 */
function FrmInputText($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $type="text") {
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, $type);
    $input->print_result();
}

/** Prints password input box */
function FrmInputPwd($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $type="password") {
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, $type);
    $input->print_result();
}


/** Prints two static text to 2-column table for use within <table> tag */
function FrmStaticText($txt, $val, $needed=false, $hlp="", $morehlp="", $safing=1 ) {
    $input = new aainputfield($val, false, 'normal', '', $txt, '', $needed, $hlp, $morehlp);
    $input->staticText($safing);
    $input->print_result();
}

/** Prints html tag <input type=hidden .. to 2-column table
 *  for use within <form> and <table> tag
*/
function FrmHidden($name, $val, $safing=true ) {
    $input = new aainputfield($val, false, 'normal', $name);
    $input->hidden($safing);
    $input->print_result();
}

/** Prints html tag <textarea .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false, $hlp="", $morehlp="", $single="") {
    $html=false;  // it was in parameter, but was never used in the code /honzam 05/15/2004
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->textarea($rows, $cols, $single, false);
    $input->print_result();
}

/** On browsers which do support it, loads a special rich text editor with many
 *  advanced features based on triedit.dll
 *  On the other browsers, loads a normal text area
*/
function FrmRichEditTextarea($name, $txt, $val, $rows=10, $cols=80, $type="class", $needed=false, $hlp="", $morehlp="", $single="", $html=false) {
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->richEditTextarea($rows, $cols, $type, $single);
    $input->print_result();
}

/** Prints a radio group, html tags <input type="radio" .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmInputRadio($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $ncols=0, $move_right=true) {
    $input = new aainputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputRadio($ncols, $move_right);
    $input->print_result();
}

/** Prints html tag <select multiple .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $size=5, $relation=false, $needed=false, $hlp="", $morehlp="", $minrows=0, $mode='AMB', $design=false) {
    $input = new aainputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputMultiSelect($size, $relation, $minrows, $mode, $design);
    $input->print_result();
}

/** Print boxes allowing to choose constant in a hiearchical way */
function FrmHierarchicalConstant($name, $txt, $value, $group_id, $levelCount, $boxWidth, $size, $horizontal=0, $firstSelect=0, $needed=false, $hlp="", $morehlp="", $levelNames="") {
    $input = new aainputfield($value, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->hierarchicalConstant($group_id, $levelCount, $boxWidth, $size, $horizontal, $firstSelect, $levelNames);
    $input->print_result();
}

/** Prints html tag <select .. to 2-column table
 * for use within <form> and <table> tag
 */
function FrmInputSelect($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $usevalue=false) {
    $input = new aainputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputSelect($usevalue);
    $input->print_result();
}

/** Prints html tag <input type="radio" .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmInputMultiChBox($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $ncols=0, $move_right=true) {
    $input = new aainputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputMultiChBox($ncols, $move_right);
    $input->print_result();
}

/** Prints html tag <input type=file .. to 2-column table
 *  for use within <form> and <table> tag
 */
function FrmInputFile($name, $txt, $needed=false, $accepts="image/*", $hlp="", $morehlp="" ){
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputFile($accepts);
    $input->print_result();
}

/** Prints html tag <intup type=text ...> with <select ...> as presets
 *  to 2-column table for use within <form> and <table> tag
 */
function FrmInputPreSelect($name, $txt, $arr, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $adding=0, $secondfield="", $usevalue=false) {
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputPreSelect($maxsize,$size,$adding,$secondfield,$usevalue);
    $input->print_result();
}

function FrmTextareaPreSelect($name, $txt, $arr, $val, $needed=false, $hlp="", $morehelp="",  $rows=4, $cols=60) {
    $input = new aainputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->textareaPreSelect($rows,$cols);
    $input->print_result();
}

function FrmRelated($name, $txt, $arr, $size, $sid, $mode, $design, $needed=false, $hlp="", $morehlp="") {
    FrmInputMultiSelect($name, $txt, $arr, "", $size, $sid, $needed, $hlp, $morehlp, MAX_RELATED_COUNT, $mode, $design);
}

/** Prints two boxes for multiple selection for use within <form> and <table> */
function FrmTwoBox($name, $txt, $arr, $val, $size, $selected, $needed=false, $wi2_offer='', $wi2_selected='', $hlp="", $morehlp="") {
    // $val is not used - there is only from historical reasons and should be removed accross files
    $input = new aainputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->twoBox($size,$wi2_offer,$wi2_selected);
    $input->print_result();
}

/**
* if $condition, shows star
*/
function Needed( $condition=true ) {
  if( $condition )
    echo "&nbsp;*";
}

/**
* if $txt, shows help message
S*/
function PrintHelp( $txt ) {
  if( $txt )
    echo "<div class=tabhlp>$txt</div>";
}

/**
* if $txt, shows link to more help
*/
function PrintMoreHelp( $txt ) {
  if( $txt )
    echo "&nbsp;<a href='$txt' target='_blank'>?</a>";
}

/**
* Prints html tag <input type=checkbox
*/
function FrmChBoxEasy($name, $checked=true, $add="") {
  echo FrmChBoxEasyCode($name, $checked, $add);
}

function FrmChBoxEasyCode($name, $checked=true, $add="") {
  $name=safe($name); // $add=safe($add); NO!!

  return "<input type=\"checkbox\" name=\"$name\" $add".
    ($checked ? " checked>" : ">");
}

/**
* Prints html tag <intup type=text ...> with <select ...> and buttons
* for moving with items
* to 2-column table for use within <form> and <table> tag
*/
function FrmInputWithSelect($name, $txt, $arr, $val, $input_maxsize=254, $input_size=25,
                            $select_size=6, $numbered=0, $needed=false, $hlp="", $morehlp="", $adding=0,
                            $secondfield="", $usevalue=false) {
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  if( !$input_maxsize )
    $input_maxsize = 254;
  if( !$input_size )
    $input_size = 25;
  if ( !$select_size )
    $select_size = 6;
  if ($secondfield) {
    $varsecfield = 'v'. unpack_id($secondfield);
  }
    echo "\n<script language=\"JavaScript\"  type=\"text/javascript\">
  <!--
    function add_to_select(selectbox, inputbox) {
                  value = inputbox.value;
                  length = selectbox.length;
                  if (value.length != 0) {
                    if((length == 1) && (selectbox.options[0].value=='wIdThTor') ){\n";

        if ($numbered==1) {
          echo "    text = length+'. '+value; ";
        }
        echo "
                selectbox.options[0].text = text;
                selectbox.options[0].value = value;
              } else {";
        if ($numbered==1) {
          echo "    text = (length+1)+'. '+value; ";
        }
        echo "      selectbox.options[selectbox.length] = new Option (text, value);
        }
                    inputbox.select();
                  }
                }

                function remove_selected(selectbox) {
                  number = selectbox.selectedIndex;
                  length = selectbox.length;
                  selectbox.options[number] = null;\n";
        if ($numbered==1) {
          echo "
                  for (i=number;i<length; i++){
                    selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                  }";
        }
        echo "    selectbox.selectedIndex = number;
                }

                function move(selectbox, type) {
                  length = selectbox.length;
                  s = selectbox.selectedIndex;

                  dontwork = 0;

                  if (s < 0) { dontwork=1; }

                  if (type=='up') {
                    s2 = s-1;
                    if (s2 < 0) { s2 = 0;}
                  } else {
                    s2 = s+1;
                    if (s2 >= length-1) { s2 = length-1; }
                  }

                  if (dontwork == 0) {
                    dummy_val = selectbox.options[s2].value;
                    dummy_txt = selectbox.options[s2].text;
                    selectbox.options[s2].value = selectbox.options[s].value;
                    selectbox.options[s2].text = selectbox.options[s].text;
                    selectbox.options[s].value = dummy_val;
                    selectbox.options[s].text  = dummy_txt;

                    selectbox.selectedIndex = s2;\n";
        if ($numbered==1) {
          echo "
                  number = selectbox.selectedIndex;
                  if (type == 'up') {
                    for (i=number;i<length; i++){
                      selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                    }
                  } else {
                    for (i=0;i<=number; i++){
                      selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                    }
                  }";
        }
        echo "

                  }
                }

                listboxes[listboxes.length] = '$name';
  //-->
  </script>\n";

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td align=left>

        <table>
        <tr><td><input type=\"Text\" name=\"foo_$name\" size=$input_size maxlength=$input_maxsize value=\"$val\"></td>
        <td align=center><input type=\"button\" name=\"".$name."_add\" value=\"  Add  \" ".
        " onclick=\"add_to_select(document.inputform['".$name."[]'], foo_$name)\"></td></tr>
        <tr align=left><td rowspan=3><select name=\"".$name."[]\" multiple width=$input_size size=\"$select_size\">\n";

  if (is_array($arr)) {
    reset($arr);
    $i=0;
    while(list($k, $v) = each($arr)) {
      $i++;
      echo "<option value=\"". htmlspecialchars($usevalue ? $v : $k)."\"";
      if ((string)$val == (string)(($usevalue OR $secondfield) ? $v : $k))
        echo ' selected class="sel_on"';
      echo "> ";
      if ($numbered ==1) { echo htmlspecialchars($i.". ".$v); }
      else { echo htmlspecialchars($v); }
      echo " </option>";
    }
    reset($arr);
  } else {
    echo "<option value=\"wIdThTor\"> ";
        for ($i=0; $i<$select_size*3; $i++) {
          echo "&nbsp; ";
        }
        echo "</option>";
  }

  echo "</select></td>
        <td align=center><input type=\"button\" name=\"".$name."_up\" value=\" /\ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'],'up');\"></td></tr>
        <tr><td align=center><input type=\"button\"  name=\"".$name."_remove\" value=\" "._m("Remove")."\" ".
                 " onclick = \"remove_selected(document.inputform['".$name."[]']);\"></td></tr>
        <tr><td align=center><input type=\"button\" name=\"".$name."_down\" value=\" \/ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'], 'down');\"></td></tr>
        </table>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

/// Used in FrmInputRadio
function getRadioButtonTag(&$k, &$v, &$name, &$selected) {
    $ret = "<input type='radio' name='$name'
                 value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
    if ((string)$selected == (string)$k)
      $ret .= " checked";
    $ret .= ">".htmlspecialchars($v);
    return $ret;
}

/**
* Prints html tag <select ..
*/
function FrmSelectEasy($name, $arr, $selected="", $add="") {
  echo FrmSelectEasyCode ($name, $arr, $selected, $add);
}

function FrmSelectEasyCode($name, $arr, $selected="", $add="") {
  $name=safe($name); // safe($add) - NO! - do not safe it

  $retval = "<select name=\"$name\" $add>\n";
  reset($arr);
  while(list($k, $v) = each($arr)) {
    $retval .= "  <option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k)
      $retval .= ' selected class="sel_on"';
    $retval .= ">". htmlspecialchars( is_array($v) ? $v['name'] : $v ) ."</option>\n";
  }
  $retval .= "</select>\n";
  return $retval;
}

function FrmRadioEasy($name, $arr, $selected="", $new_line=false) {
  $name=safe($name); // safe($add) - NO! - do not safe it

  reset($arr);
  while(list($k, $v) = each($arr)) {
    $retval .= "<input type=radio name=\"$name\" value=\"". htmlspecialchars($k)."\"";
    if (!$selected) $selected = $k;
    if ((string)$selected == (string)$k)
        $retval .= " selected";
    $retval .= "> ". htmlspecialchars( is_array($v) ? $v['name'] : $v );
    if ($new_line) $retval .= "<br>";
    $retval .= "\n";
  }
  echo $retval;
}

/**
* Prints start of form table with caption and possibly additional tags (classes) to tables
*/
function FrmTabCaption( $caption, $outer_add='', $inner_add='', $buttons='', $sess='', $slice_id='', $valign='middle') {
    echo '
    <table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\" $outer_add>";
    if ($buttons) {
        echo '
        <tr><td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'. COLOR_TABBG .'">
          <tr>';
          FrmInputButtons($buttons, $sess, $slice_id, $valign, false);
        echo '</tr></table></td></tr>';
    }
    if ($caption != "") {
      echo "
        <tr><td class=tabtit><b>&nbsp;$caption</b></td></tr>";
    }
     echo "
      <tr>
        <td>
          <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\" $inner_add>";
}

/**
* Prints middle row with subtitle into form table
* @param no_hidden - prints all $buttons except the hidden fields
*/
function FrmTabSeparator( $subtitle , $buttons='', $sess='', $slice_id='', $valign='middle', $no_hidden=false) {
    echo '</table>';
    if ($buttons) {
        echo '<table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\">
          <tr>";
        FrmInputButtons($buttons, $sess, $slice_id, $valign, false, COLOR_TABTITBG, $no_hidden);
        echo "</tr></table>";
    }
    echo '</td>
        </tr>';
    if ($buttons) {
        echo "<tr><td bgcolor=". COLOR_TABBG." hegiht=6></td></tr>";
    }
    echo '
      <tr><td class=tabtit><b>&nbsp;'. $subtitle .'</b></td>';

    echo '</tr>
      <tr>
        <td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'. COLOR_TABBG .'">';
}


/**
* Prints middle row with subtitle into form table
*/
function FrmTabSeparatorNoHidden( $subtitle , $buttons='' ) {
    FrmTabSeparator( $subtitle , $buttons, '', '', 'middle', true);
}


/**
* Prints form table end with buttons (@see FrmInputButtons)
*/
function FrmTabEnd( $buttons=false, $sess='', $slice_id='', $valign='middle' ) {
    echo '    </table>
            </td>
          </tr>';
    if( $buttons ) FrmInputButtons($buttons, $sess, $slice_id, $valign, false, COLOR_TABTITBG);
    echo '
        </table>';
}


/**
* Prints buttons based on $buttons array. It also adds slice_id and session id
* Maybe better is to use (@see FrmTabEnd())
* @param no_hidden - prints all $buttons except the hidden fields
*/
function FrmInputButtons( $buttons, $sess='', $slice_id='', $valign='middle', $tr=true, $bgcolor=COLOR_TABBG, $no_hidden=false) {
    global $BName, $BVersion, $BPlatform;

    if ($tr) { echo '<tr>'; }
    echo '<td align="center" valign="'.$valign.'" bgcolor='.$bgcolor. '>';
    if( isset($buttons) AND is_array($buttons) ) {
        // preparison: is the accesskey working?
        detect_browser();
        if ($BPlatform == "Macintosh") {
            if ($BName == "MSIE" || ($BName == "Netscape" && $BVersion >= "6")) {
                $accesskey_pref = "CTRL";
            }
        } elseif ($BName == "MSIE" || ($BName == "Netscape" && $BVersion > "5") || ($BName == "Mozilla")) {
            $accesskey_pref = "ALT";
        }

        foreach ( $buttons as  $name => $properties ) {
            if( !is_array($properties) ) {
                $name = $properties;
                $properties = array();
            }
            switch($name) {
                case 'update':
                    if ($properties['type'] == 'hidden') {
                        echo '&nbsp;<input type="hidden" name="update" value="'. get_if($properties['value'], "") .'">&nbsp;';
                    } else {
                        echo '&nbsp;<input type="submit" name="update" accesskey="S" value=" '. get_if($properties['value'], _m("Update")) ." ($accesskey_pref+S) " .' ">&nbsp;';
                        $noaccess = 1; // use for update of item, bug was, that both "update" and "insert"
                        // has accesskey S
                    }
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'insert':
                    echo '&nbsp;<input type="submit" name="insert" ';
                    if (!$noaccess) { // was accesskey used ?
                        echo 'accesskey="S" ';
                    }
                    echo 'value=" '. get_if($properties['value'], _m("Insert")) ." ";
                    if (!$noaccess) {
                        echo " ($accesskey_pref+S)";
                    }
                    echo '  ">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'cancel':
                    $url = get_if($properties['url'],self_server().$_SERVER['PHP_SELF']);
                    if ($slice_id) $url = con_url($url, 'slice_id='.$slice_id);
                    if (!$properties['url']) {
                        $url = con_url($url,'cancel=1');
                    }
                    if ($sess)     $url  = $sess->url($url);
                    //          echo '&nbsp;<input type="button" name="cancel" value=" '. get_if($properties['value'], _m("Cancel")) .' ">&nbsp;';
                    echo '&nbsp;<input type="button" name="cancel" value=" '. get_if($properties['value'], _m("Cancel")) .' " onclick="document.location=\''.$url.'\'">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'reset':
                    echo '&nbsp;<input type="reset" value=" '. _m("Reset form") .' ">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'submit':
                    echo '&nbsp;<input type="submit" accesskey="S" value=" '. get_if($properties['value'], _m("Submit")) ."  ($accesskey_pref+S) ". ' ">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                default:
                    $type = ($properties['type'] ? $properties['type'] : 'hidden');
                    if ( $no_hidden AND ($type == 'hidden') ) {
                        // do not print hidden fields if no_hidden is true
                        // (used for FrmTabSeparator, to not duplicate hiddens)
                        break;
                    }
                    echo '&nbsp;<input type="'.  ($properties['type'] ? $properties['type'] : 'hidden') .
                         '" name="'.  $name .
                         '" value="'. $properties['value'] . ($properties['accesskey'] ? "  (".$accesskey_pref."+".$properties['accesskey'].")  " : "").
                         '" '.($properties['accesskey'] ? 'accesskey="'.$properties['accesskey'].'" ' : ""). $properties['add'] . '>&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
            }
            echo "\n";
        }
    }

    if( $sess )
    $sess->hidden_session();
    if( $slice_id )
    echo '<input type="hidden" name="slice_id" value="'. $slice_id .'">';

    echo "</td>";
    if ($tr) { echo "</tr>"; }
}

/** */
function getFrmTabRow( $row ) {
   if ( isset($row) AND is_array($row) ) {
        $ret .= "\n <tr>";
        foreach ( $row as $col ) {
            $ret .= ( !is_array($col) ? "<td>$col</td>" :
                        '<td ' .$col['attr']. '>'. $col['text'] .'</td>');
        }
        $ret .= "</tr>";
    }
    return $ret;
}

/** Prints table row with calls defined in array */
function FrmTabRow( $row ) {
    echo getFrmTabRow( $row );
}

/** Returns table based on config array */
function GetHtmlTable( $content ) {
    if ( !(isset($content) AND is_array($content)) )   return "";
    $ret = '<table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="'. COLOR_TABBG .'">';
    foreach ($content as $row) {
        $ret .= getFrmTabRow( $row );
    }
    return  $ret . '</table>';
}


/** returns one row with one radiobutton - asociated to bookmark (stored search)
 *  or item list
 *  $name  - dislpayed name of this option
 *  $value - value for this option
 *  $list_type - items preview type ('items' | 'users') @see usershow.php3
 *  $safe - escape html entities in name?
 */
function getRadioBookmarkRow( $name, $value, $list_type, $list_text, $safe=true, $bookmark_id=null) {
    global $slice_id, $items;

    static $checked = ' checked';  // mark first option when no $group selected

    if ( isset( $GLOBALS['group'] ) ) {
        $checked = (((string)$GLOBALS['group'] == (string)$value) ? ' checked' : '');
    }

    if ( $safe ) $name = safe($name);
    $out .= "
    <tr>
      <td align=center><input type=\"radio\" name=\"group\" value=\"$value\" $checked></td>";

    $out .= ((string)$value == (string)"testuser") ? "<td colspan=6>" : "<td>";

    $out .= "$name</td>";
    // get data about bookmark (who created, last used, ...)
    if (!is_null($bookmark_id)) {
        $event    = getLogEvents("BM_%", "",   "", false, false, $bookmark_id);
        $lastused = getLogEvents("EMAIL_SENT", "", "",    false, false, (string)$value);
        if (is_array($event)) {
            foreach ($event as $evkey => $evval) {
                if ($evval["type"] == "BM_CREATE") {
                    $created = $evval["time"];
                    $createdby = $evval["user"];
                }
            }
            rsort($event);
            $last_edited = $event[key($event)]["time"];
            $out .= "<td>". perm_username($createdby) . "</td><td>". date("j.n.Y G:i:s",$created). "</td>";
            $out .= "<td>". date("j.n.Y G:i:s",$last_edited). "</td>";
            if (is_array($lastused)) {
                rsort($lastused);
                $last_used = $lastused[key($lastused)]["time"];
                $out .= "<td>". date("j.n.Y G:i:s",$last_used). "</td>";
            } else {
                $out .= "<td>". _m('Not used, yet'). "</td>";
            }
        } else {
            $out .= "<td colspan=4></td>";
        }
    }
    $out .= "<td>";
    if ((string)$value != (string)"testuser") {
        $grp = $value;
        $js = "OpenUsershowPopup('".get_admin_url("usershow.php3")."&sid=$slice_id&group=$grp&type=$list_type')";
        $out .= "<a href=\"javascript:$js;\">$list_text</a>";
    }
    $out .=  "</td>
    </tr>";
    $checked = '';  // static variable
    return $out;
}

/** Allows select items group (used for bulk e-mails as well as for Find&Replace)
 *   list_type - items preview type ('items' | 'users') @see usershow.php3
 *   messages['view_items']     = _m("View Recipients")
 *   messages['selected_items'] = _m('Selected users')
 *   additional[] = array( 'text' => 'Test', 'varname'=>'testuser');
 */
function FrmItemGroupSelect( &$items, &$searchbar, $list_type, $messages, $additional) {
    if ( isset($items) AND is_array($items) ) {
        $out .= getRadioBookmarkRow( $messages['selected_items'].' ('.count($items).')', 'sel_item', $list_type, $messages['view_items']);
    } elseif ( isset($searchbar) AND is_object($searchbar) ) {
        $book_arr = $searchbar->getBookmarkNames();
        if ( isset($book_arr) AND is_array($book_arr) ) {
            $out .= "<tr><td></td><td><b>"._m("Group Name")."</b></td><td><b>". _m("Created by"). "</td><td><b>"
                    ._m("Created on"). "</b></td><td><b>". _m("Last updated") ."</b></td><td><b>"._m("Last used"). "</b></td></tr>";
            foreach ( $book_arr as $k => $v ) {
                $bookparams = $searchbar->getBookmarkParams($k);
                $out .= getRadioBookmarkRow( $v, $k, $list_type, $messages['view_items'], true, is_array($bookparams) ? $bookparams['id'] : null);
            }
        }
    }
    // aditional group (test one, for examle)
    if ( isset($additional) AND is_array($additional) ) {
        foreach ( $additional as $row ) {
            $out .= getRadioBookmarkRow( $row['text'], $row['varname'], $list_type, $messages['view_items'], false);
        }
    }
    echo $out;
}

/** Returns zids according to user selection of FrmItemGroupSelect */
function getZidsFromGroupSelect($group, &$items, &$searchbar) {
    global $slice_id;
    if ( $group == 'sel_item' ) {  // user specified users
        $zids = new zids(null, 'l');
        $zids->set_from_item_arr($items);
    } else {                   // user defined by bookmark
        $slice = new slice($slice_id);
        $searchbar->setFromBookmark($group);
        $conds = $searchbar->getConds();
        $zids=QueryZIDs($slice->fields('record'), $slice_id, $conds, "", "", 'ACTIVE');
    }
    return $zids;
}

/** Lists selected items to special form - used by manager.js to show items */
function FrmItemListForm(&$items) {
    $out = '<form name="itform" method="post">';
    if (is_array($items)) {
        foreach ($items as $key=>$it) {
            $out .= '<input type="hidden" name="items['.$key.']" value="">';
        }
    }
    $out .= "\n  </form>";
    echo $out;
}

# Prints alias names as help for fulltext and compact format page
function PrintAliasHelp($aliases, $fields=false, $endtable=true, $buttons='', $sess='', $slice_id='') {
  global $sess;

  FrmTabSeparator(_m("Use these aliases for database fields") , $buttons, $sess, $slice_id);

//  echo '
//  <tr><td class=tabtit><b>&nbsp;'._m("Use these aliases for database fields").'</b></td></tr>
//  <tr><td>
//  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'.COLOR_TABBG.'">';

  $count = 0;
  while ( list( $ali,$v ) = each( $aliases ) ) {
    # if it is possible point to alias editing page
    $aliasedit = ( !$v["fld"] ? "&nbsp;" :
      "<a href=\"". $sess->url(con_url("./se_inputform.php3",
                    "fid=".urlencode($v["fld"]))) ."\">". _m("Edit") . "</a>");
    if ($fields AND $fields[$v["fld"]] AND !$fields[$v["fld"]]['input_show'])
        $ali = "<span class=\"disabled\">$ali</span>";
    echo "<tr><td nowrap>$ali</td><td>". $v[hlp] ."</td><td>$aliasedit</td></tr>";
  }

  if ($endtable) {
   echo '
    </table></td></tr>';
  }
}


/**
* Validate users input. Error is reported in $err array
* You can add parameters to $type divided by ":".
*/
function ValidateInput($variableName, $inputName, $variable, &$err, $needed=false, $type="all")
{
    if($variable=="" OR Chop($variable)=="")
        if( $needed ) {                     // NOT NULL
            $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("it must be filled").")");
            return false;
        }
        else  return true;

    if (strchr ($type, ":")) {
        $params = substr ($type, strpos($type,":")+1);
        $type = substr ($type, 0, strpos ($type,":"));
    }

    switch($type) {
    case "id":     if((string)$variable=="0" AND !$needed)
                     return true;
                   if( !EReg("^[0-9a-f]{1,32}$",Chop($variable)))
                   { $err["$variableName"] = MsgErr(_m("Error in")." $inputName");
                     return false;
                   }
                   return true;
    case "alias":  if((string)$variable=="0" AND !$needed)
                     return true;
                   if( !EReg("^_#[0-9_#a-zA-Z]{8}$",Chop($variable)))
                   { $err[$variableName] = MsgErr(_m("Error in")." $inputName");
                     return false;
                   }
                   return true;
    case "number": if( !EReg("^[0-9]+$",Chop($variable)) )
                   { $err[$variableName] = MsgErr(_m("Error in")." $inputName");
                     return false;
                   }
                   return true;
    case "perms":  if( !(($Promenna=="editor") OR ($Promenna=="admin")))
                   { $err[$variableName] = MsgErr(_m("Error in")." $inputName");
                     return false;
                   }
                   return true;
    case "email":  if ( !valid_email(Chop($variable)) )
                   { $err[$variableName] = MsgErr(_m("Error in")." $inputName");
                     return false;
                   }
                   return true;
    case "login":
      $len = strlen($variable);
      if( ($len>=3) AND ($len<=32) )
      { if( !EReg("^[a-zA-Z0-9]*$",Chop($variable)))
        { $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("you should use a-z, A-Z and 0-9 characters").")");
          return false;
        }
        return true;
      }
      $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("it must by 5 - 32 characters long").")");
      return false;

    case "password":
      $len = strlen($variable);
      if( ($len>=5) AND ($len<=32) )
        return true;
      $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("it must by 5 - 32 characters long").")");
      return false;

    case "filename": if( !EReg("^[-.0-9a-zA-Z_]+$", $variable)) {
                       $err[$variableName] = MsgErr(_m("Error in")." $inputName ("._m("only 0-9 A-Z a-z . _ and - are allowed").")");
                       return false;
                     }
                     return true;

    case "e-unique": // validate email ...
                     if( !EReg("^.+@.+\..+",Chop($variable)))
                       { $err[$variableName] = MsgErr(_m("Error in")." $inputName");
                         return false;
                       }
                     // ... and proceed to "unique"

    case "unique":

        // username is searched in all slices AND in permission system
        define("SCOPE_USERNAME",0);
        // search only in this slice
        define("SCOPE_SLICE",1);
        // search in all slices
        define("SCOPE_ALLSLICES",2);

        list ($field_id, $scope) = split (":", $params);
        if (!strchr ($params, ":"))
            $scope = SCOPE_SLICE;
        if (strlen ($field_id) != 16) {
            $err[$variableName] = MsgErr(_m("Error in parameters for UNIQUE validation: "
                ."field ID is not 16 but %1 chars long: ",array(strlen($field_id))).$field_id);
            return false;
        } else {
            global $slice_id, $db;
            if ($scope == SCOPE_USERNAME) {
                $ok = IsUsernameFree ($variable);
            }
            else {
                if ($scope == SCOPE_SLICE)
                    $SQL = "SELECT * FROM content INNER JOIN
                            item ON content.item_id = item.id
                            WHERE item.slice_id='".q_pack_id($slice_id)."'
                            AND field_id='".addslashes($field_id)."'
                            AND text='".$variable."'";
                else $SQL = "SELECT * FROM content WHERE field_id='".addslashes($field_id)
                            ."' AND text='$variable'";
                $db->query ($SQL);
                $ok = ! $db->next_record();
            }

            if (! $ok) {
                $err[$variableName] = MsgErr(_m("Error in")." $inputName (".
                    _m("this value is already used, choose another one").")");
                return false;
            }
        }
        return true;

    case "url":
    case "all":
    default:       return true;
    }
}

/**
* used in tabledit.php3 and itemedit.php3
*/
function get_javascript_field_validation () {
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
            var valid_email = /^.+@[a-zA-Z0-9\\-\\.]+\\.([a-zA-Z]{2,3}|[0-9]{1,3})$/;

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

?>
