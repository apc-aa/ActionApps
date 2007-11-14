<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
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


/**
* Form utility functions
*/

require_once AA_INC_PATH."constedit_util.php3";
require_once AA_INC_PATH."javascript.php3";
require_once AA_INC_PATH."profile.class.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."stringexpand.php3";
require_once AA_INC_PATH."sliceobj.php3";
require_once AA_INC_PATH."validate.php3";

// IsUserNameFree() function deffinition here
require_once(AA_INC_PATH . "perm_" . PERM_LIB . ".php3");

define( 'AA_WIDTHTOR', '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;  &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>');
define( 'AA_BIN_ACT_PEND', AA_BIN_ACTIVE|AA_BIN_PENDING );
// Easy to redefine this functionality by changing the array below
// prefix is what goes in the selection box in "Edit Item",
// tag is what goes on the front of the id as stored in the database
// str is the string to display in the Related Items window
// Note that A M B are hard-coded in the Related Items Window param wizard,
// but any letters can be used, i.e. this table can be extended.
// Next step might be to extend parameter recognition to load this table
// Retaining backward compatability with "[AMB]+" recognition
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

/** varname4form function
 * @param $fid
 * @param $type
 */
function varname4form($fid, $type='normal') {
    $additions = array( 'normal' => '', 'multi' => '[]', 'file' => 'x' );
    return 'v'. unpack_id($fid) .$additions[$type];
}

/** GetInputFormTemplate function
 *  Returns inputform template
 */
function GetInputFormTemplate() {
     global $slice_id;
     $slice = AA_Slices::getSlice($slice_id);
     //           inputform($inputform_settings);
     $form  = new inputform();
     // ItemContent in getForm passed by reference
     return $form->getForm(new ItemContent(), $slice, false, $slice_id);
}

/**
 * inputform class - used for displaying input form (item add/edit)
 */
class inputform {
    var $display_aa_begin_end;
    var $page_title;
    var $form_action;
    var $form4update;
    var $show_preview_button;
    var $cancel_url;
    var $messages;
    var $result_mode;

    var $template;        // if you want to display alternate form design,
                          // template holds view_id of such template
                          // view type 'inputform'
    var $formheading;     // add extra form heading here (default none, used by MLX)
    var $msg;             // stores return code from functions

    // computed values form form fields
    var $show_func_used;    // used show functions in the input form
    var $js_proove_fields;  // javascript form validation code


    // required - class name (just for PHPLib sessions)
    var $classname = "inputform";

    /** inputform function
     *  Constructor - initializes inputform
     * @param $settings
     */
    function inputform($settings=array()) {
        $this->display_aa_begin_end = $settings['display_aa_begin_end'];
        $this->page_title           = $settings['page_title'];
        $this->form_action          = $settings['form_action'];
        $this->form4update          = $settings['form4update'];
        $this->show_preview_button  = $settings['show_preview_button'];
        $this->cancel_url           = $settings['cancel_url'];
        $this->messages             = $settings['messages'];
        $this->result_mode          = $settings['result_mode'];  // if not supplied, standard form is used
        $this->template             = $settings['template'];
        $this->formheading          = $settings['formheading']; //aded for MLX
        $this->hidden               = $settings['hidden'];      // array of hidden fields to be added to the form
    }

    /** printForm function
     *  Displays the form
     * @param $content4id
     * @param $slice
     * @param $edit
     * @param $slice_fields - true, if we want to edit "slice setting fields"
     *                         which are stored in content table
     */
    function printForm($content4id, &$slice, $edit, $slice_fields=false) {
        global $sess;

        // Get the default form and FILL CONTENTCACHE with fields
        // This function also fills the $this->show_func_used and
        // $this->js_proove_fields
        $form = $this->getForm($content4id, $slice, $edit, '', $slice_fields);

        if ( $this->display_aa_begin_end ) {
            HtmlPageBegin('default', false, $slice->getLang());   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

            // get validation and gui javascripts for used fields
            // getFormJavascript must be called after getForm since
            // $this->show_func_used, $this->js_proove_fields must be already filled
            echo $this->getFormJavascript();
            echo '
                <title>'. $this->page_title .'</title>
              </head>
              <body>
                <h1><b>' . $this->page_title .'</b></h1>';
            PrintArray( $this->messages['err'] );     // prints err or OK messages
        }

        echo $this->getFormStart();

        // design of form could be customized by view
        if ( $this->template AND ($view = AA_Views::getView($this->template))) {
            // we can use different forms for 'EDIT' and 'ADD' item => (even/odd)
            $form          = ($edit AND $view->f('even_odd_differ')) ? $view->f('even') : $view->f('odd');
            $remove_string = $view->f('remove_string');
        }

        // create buttons array for top (and lately for bottom of the form)
        if ( $this->form4update ) {
            $buttons[]                  = 'update';
            if ( $this->show_preview_button ) {
                $buttons['upd_preview'] = array('type'=>'submit', 'value'=>_m("Update & View"));
            }
            // if we edit dynamic slice setting fields, we do not need such buttons
            if (!$slice_fields) {
                $buttons['insert']      = array('type'=>'submit', 'value'=>_m("Insert as new"));
                $buttons['reset']       = array('type'=>'reset',  'value'=>_m("Reset form"));
            }
        } else {
            $buttons[]                  = 'insert';
            $buttons['ins_preview']     = array('type'=>'submit', 'value'=>_m("Insert & View"));
        }
        $buttons['cancel']              = array('type'=>'button', 'value'=>_m("Cancel"),
                                                'url'=> $this->cancel_url);

//        debug( $form, $GLOBALS['contentcache']);
    //added for MLX
        // print the inputform
        $CurItem = new AA_Item($content4id, $slice->aliases(), '', $form, $remove_string);   // just prepare

        $out = $CurItem->get_item();

        FrmTabCaption( '', 'id="inputtab"', 'id="inputtabrows"', $buttons);
        $parts = $GLOBALS['g_formpart'];
        $tabs  = array();
        if ( $parts ) {
            $idx = 0;
            while ( $parts+1 ) {
                $tabs['formrow'.(string)$parts] = get_if( $GLOBALS['g_formpart_names'][$parts], _m('Part'). " ".($idx+1));
                $idx++;
                $parts--;
            }
            // print tabs for form switching
            if ( $GLOBALS['g_formpart_pos'] & 1 ) {  // 1 ~ top
                FrmTabs( $tabs, 'formtabs' );
            }
        }

        if ($this->formheading) {// added for mlx tab
            echo $this->formheading;
        }

        echo $out;

        if ( $parts AND ($GLOBALS['g_formpart_pos'] & 2)) {  // 2 ~ bottom
            // print tabs for form switching
            FrmTabs( $tabs, 'formtabs2' );
        }

        if (is_array($this->hidden)) {
            foreach ( (array)$this->hidden as $name => $value) {
                $buttons[$name]         = array('value' => $value);
            }
        }

        // add rest "hidden buttons" to the end of form
        $buttons['MAX_FILE_SIZE']       = array('value' => IMG_UPLOAD_MAX_SIZE );
        $buttons['encap']               = array('value' => "false");
        $buttons['vid']                 = array('value' => $vid);   // ?? $vid is not defined here ?? - @todo


        FrmTabEnd( $buttons, $sess, $slice->unpacked_id() );

        if ( $GLOBALS['g_formpart'] ) {
            FrmJavascript('document.getElementById("inputtabrows").style.display = \'\';
                           TabWidgetToggle(\'formrow'.$GLOBALS['g_formpart'].'\');');
        }

        echo '</form>';
        if ( $this->display_aa_begin_end ) {
            echo "</body></html>";
        }
    }


    /** getForm function
     *   Shows the Add / Edit item form fields
     * @param $content4id
     * @param $slice
     * @param $edit
     * @param $show is used by the Anonymous Form Wizard, it is an array
     *                (packed field id => 1) of fields to show
     * @param $slice_fields
     */
    function getForm2(&$content4id, &$slice, $edit, $show="", $slice_fields=false) {
        global $auth, $profile;

        if ( !is_object( $profile ) ) {
            $profile = new aaprofile($auth->auth["uid"], $slice->unpacked_id());  // current user settings
        }

        $fields    = $slice->getFields($slice_fields);
        $prifields = $fields->getPriorityarray();

        if ( !isset($prifields) OR !is_array($prifields) ) {
            return MsgErr(_m("No fields defined for this slice"));
        }

        $form4anonymous_wizard = is_array($show);

        // holds array of fields, which we will use on the form, so we have
        // to count with them for javascript and show_sunc_used
        $shown_fields = array();

        $item = $edit ? GetItemFromContent($content4id) : null;

        foreach ($prifields as $field_id) {
            $field       = $fields->getField($field_id);

            $varname     = AA_Field::getId4Form($field_id, $item ? $item->getItemID() : null);
            $htmlvarname = $varname."html";

            if ( ($form4anonymous_wizard  AND !$show[$f['id']]) OR
                 (!$form4anonymous_wizard AND (!$f["input_show"] OR
                                         $profile->getProperty('hide',$f['id']) OR
                                         $profile->getProperty('hide&fill',$f['id'])))) {
                // do not show this field
                continue;
            }

            $shown_fields[$field_id] = true;  // used => generate js for it

            // ----- collect all field_* parameters in order we can call display function

            // field_mode - how to display the field
            $field_mode = !IsEditable($content4id->getValues($field_id), $f, $profile) ?
                          'freeze' : ($form4anonymous_wizard ? 'anonym' : 'normal');

            if ( $edit ) {
                $field_value     = $content4id->getValues($field_id);
                $field_html_flag = $content4id->getValue($field_id, 'flag') & FLAG_HTML;
            } else {     // insert or new reload of form after error in inserting
                // first get values from profile, if there are some predefined value
                $foo = $profile->getProperty('predefine',$f['id']);
                if ( $foo AND !$GLOBALS[$varname]) {
                    $x                     = $profile->parseContentProperty($foo);
                    $GLOBALS[$varname]     = $x[0];  // it is not quoted, so OK
                    $GLOBALS[$htmlvarname] = $x[1];
                }
                // get values from form (values are filled when error on form ocures
                if ( $f["multiple"] AND is_array($GLOBALS[$varname]) ) {
                      // get the multivalues
                    foreach ( $GLOBALS[$varname] as $value ) {
                        $field_value[] = array('value' => stripslashes($value)); // it is quoted!!!
                    }
                } else {
                    $field_value[0]['value'] = stripslashes($GLOBALS[$varname]);  // it is quoted!!!
                }
                $field_html_flag = (((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
            }            // Display the field
            $aainput = new AA_Inputfield($field_value, $field_html_flag, $field_mode);
            //fix -- otherwise $field_value keeps array
            unset($field_value);
            $aainput->setFromField($f);

            // do not return template for anonymous form wizard
            $ret .= $aainput->get($form4anonymous_wizard ? 'expand' : 'template', $item);
            unset($aainput);
        }
        $this->js_proove_fields = $slice->get_js_validation( $edit ? 'edit' : '', $content4id->getItemID(), $shown_fields, $slice_fields);
        $this->show_func_used   = $slice->get_show_func_used($edit ? 'edit' : '', $content4id->getItemID(), $shown_fields, $slice_fields);
        return $ret;
    }



    /** getForm function
     *   Shows the Add / Edit item form fields
     * @param $content4id
     * @param $slice
     * @param $edit
     * @param $show is used by the Anonymous Form Wizard, it is an array
     *                (packed field id => 1) of fields to show
     * @param $slice_fields
     */
    function getForm(&$content4id, &$slice, $edit, $show="", $slice_fields=false) {
        global $auth, $profile;

        if ( !is_object( $profile ) ) {
            $profile = new aaprofile($auth->auth["uid"], $slice->unpacked_id());  // current user settings
        }

        list($fields, $prifields) = $slice->fields(null, $slice_fields);

        if ( !isset($prifields) OR !is_array($prifields) ) {
            return MsgErr(_m("No fields defined for this slice"));
        }

        $form4anonymous_wizard = is_array($show);

        // holds array of fields, which we will use on the form, so we have
        // to count with them for javascript and show_sunc_used
        $shown_fields = array();

        $item = $edit ? GetItemFromContent($content4id) : null;

        foreach ($prifields as $pri_field_id) {
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

            $shown_fields[$pri_field_id] = true;  // used => generate js for it

            // ----- collect all field_* parameters in order we can call display function

            // field_mode - how to display the field
            $field_mode = !IsEditable($content4id->getValues($pri_field_id), $f, $profile) ?
                          'freeze' : ($form4anonymous_wizard ? 'anonym' : 'normal');

            if ( $edit ) {
                $field_value     = $content4id->getValues($pri_field_id);
                $field_html_flag = $content4id->getValue($pri_field_id, 'flag') & FLAG_HTML;
            } else {     // insert or new reload of form after error in inserting
                // first get values from profile, if there are some predefined value
                $foo = $profile->getProperty('predefine',$f['id']);
                if ( $foo AND !$GLOBALS[$varname]) {
                    $x                     = $profile->parseContentProperty($foo);
                    $GLOBALS[$varname]     = $x[0];  // it is not quoted, so OK
                    $GLOBALS[$htmlvarname] = $x[1];
                }
                // get values from form (values are filled when error on form ocures
                if ( $f["multiple"] AND is_array($GLOBALS[$varname]) ) {
                      // get the multivalues
                    foreach ( $GLOBALS[$varname] as $value ) {
                        $field_value[] = array('value' => stripslashes($value)); // it is quoted!!!
                    }
                } else {
                    $field_value[0]['value'] = stripslashes($GLOBALS[$varname]);  // it is quoted!!!
                }
                $field_html_flag = (((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
            }            // Display the field
            $aainput = new AA_Inputfield($field_value, $field_html_flag, $field_mode);
            //fix -- otherwise $field_value keeps array
            unset($field_value);
            $aainput->setFromField($f);

            // do not return template for anonymous form wizard
            $ret .= $aainput->get($form4anonymous_wizard ? 'expand' : 'template', $item);
            unset($aainput);
        }
        $this->js_proove_fields = $slice->get_js_validation( $edit ? 'edit' : '', $content4id->getItemID(), $shown_fields, $slice_fields);
        $this->show_func_used   = $slice->get_show_func_used($edit ? 'edit' : '', $content4id->getItemID(), $shown_fields, $slice_fields);
        return $ret;
    }

    /** getFormJavascript
     *  Get validation, triggers and gui javascript for used fields on Add/Edit Form
     *  Must be called after getForm since $this->show_func_used and
     *  $this->js_proove_fields must be already filled
     */
    function getFormJavascript() {
        global $slice_id, $sess;

        $retval  = getFrmJavascriptFile( 'javascript/inputform.js' );
        $retval .= getFrmJavascriptFile( 'javascript/js_lib.js' );

        $jscode .= $this->js_proove_fields;

        // field javascript feature - triggers (see /include/javascript.php3)
        $javascript = getJavascript($GLOBALS["slice_id"]);
        if ($javascript) {
            $jscode .= $javascript;
        }
        $retval .= getFrmJavascript( $jscode );

        // special includes for HTMLArea
        // we need to include some scripts
        // switchHTML(name) - switch radiobuttons from Plain text to HTML
        // showHTMLAreaLink(name) - displays "edit in htmarea" link
        // openHTMLAreaFullscreen(name) - open popup window with HTMLArea editor

        $retval .= getFrmJavascript('
                    // global variables used by multi-value selectboxes
                    var maxcount = '. MAX_RELATED_COUNT .';
                    var relmessage = "'._m("There are too many items.") .'";

                    // global variables used in HTMLArea (xinha)
                    // You must set _editor_url to the URL (including trailing slash) where
                    // where xinha is installed, it\'s highly recommended to use an absolute URL
                    //  eg: _editor_url = "/path/to/xinha/";
                    // You may try a relative URL if you wish]
                    //  eg: _editor_url = "../";
                    _editor_url        = "'.get_aa_url("misc/htmlarea/", false).'";
                    _editor_lang       = "'.substr(get_mgettext_lang(),0,2).'";
                    aa_slice_id        = "'.$slice_id.'";
                    aa_session         = "'.$sess->id.'";
                    aa_long_editor_url = "'.self_server().get_aa_url("misc/htmlarea/", false).'";'
                    );

        // HtmlArea scripts should be loaded allways - we use Dialog() function
        // from it ...
        $retval .= getFrmJavascriptFile('misc/htmlarea/htmlarea.js');
        //    $retval .= getFrmJavascriptFile('misc/htmlarea/popups/popup.js');
        $retval .= getFrmJavascriptFile('misc/htmlarea/aafunc.js');
        $retval .= getFrmJavascriptFile('javascript/constedit.js');

        if ($this->show_func_used['txt'] || $this->show_func_used['edt']) {
            $retval .= getFrmJavascript('
                window.onload   = xinha_init;
                window.onunload = HTMLArea.flushEvents;'
            );
        }

        if ($javascript) {
            $retval .= getFrmJavascriptFile('javascript/fillform.js' );
        }

        return $retval;
    }

    /** getFormStart function
     *  Get form tag with right enctype and triggers
     *  Must be called after getForm since $this->show_func_used and
     *  $this->js_proove_fields must be already filled
     */
    function getFormStart() {
        if ( $this->show_func_used['fil']) { // uses fileupload?
            $html_form_type = 'enctype="multipart/form-data"';
        }
        return "<form name=\"inputform\" $html_form_type method=\"post\" action=\"" . $this->form_action .'"'.
                    getTriggers("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()")).'>';
    }
} // inputform class

/** getAAField function
 *  Special constructor shortcut for AA_Inputfield class
 *  Returns new AA_Inputfield object with setting defined in array
 * @param $settings
 */
function getAAField( $settings ) {
    $x = new AA_Inputfield();
    $x->setFromArray( $settings );
    return $x;
}

/**
 * AA_Inputfield class - used for displaying input field
 */
class AA_Inputfield {
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

    var $valid;            // validation function used (this function we added in order we can use this class for AA Admin forms as well)
    var $dbfield;          // contains name of database field. It is often
                           // empty, so we will use varname, but there are some
                           // cases, where we use different database field than
                           // the varname (for examlpe "name" is good database
                           // column name, but wrong for HTML form)

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
    var $classname = "AA_Inputfield";  // class name (just for PHPLib sessions)

    /** AA_Inputfield function
     * Constructor - initializes AA_Inputfield
     * @param $value
     * @param $html_flag
     * @param $mode
     * @param $varname
     * @param $name
     * @param $add
     * @param $required
     * @param $hlp
     * @param $morehlp
     * @param $arr
     */
    function AA_Inputfield($value='', $html_flag=true, $mode='normal',
                          $varname="", $name="", $add=false, $required=false,
                          $hlp="", $morehlp="", $arr=null) {
        $this->clear();
        $settings = array( 'value'         => is_array($value) ? $value : array( 0=>array('value'=>$value)),
                           'html_flag'     => $html_flag,
                           'mode'          => $mode,
                           'varname'       => $varname,
                           'name'          => $name,
                           'required'      => $required,
                           'input_help'    => $hlp,
                           'input_morehlp' => $morehlp,
                           'additional'    => $add,
                           'const_arr'     => $arr
                         );
        $this->setFromArray($settings);

        contentcache::global_instance();   // make sure $contentcache exists
    }

    /** getValue function
     *   Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     */
    function getValue() {
        return $this->value[0]['value'];
    }
    /** setValue function
     * @param $value
     */
    function setValue($value) {
        $this->value = array( 0=>array('value'=>$value));
    }

    /** getDefaults function
     * private function - Returns list of class variables and its defaults
     */
    function getDefaults() {
        return array( 'value'         =>  array( 0 => array( 'value' => '')),
                      'html_flag'     => true,
                      'mode'          => 'normal',
                      'varname'       => '',
                      'name'          => '',
                      'required'      => false,
                      'input_help'    => '',
                      'input_morehlp' => '',
                      'additional'    => false,
                      'const_arr'     => null,
                      'result_mode'   => 'expand',
                      'html_rb_show'  => false,
                      'valid'         => 'text',
                      'dbfield'       => null
                    );
    }

    /** clear function
     * Set all class variables to its defaults
     */
    function clear() {
        foreach ( $this->getDefaults() as $propname => $defvalue ) {
            $this->$propname = $defvalue;
        }
    }
    /** setFormArray function
     * @param $settings
     */
    function setFromArray( $settings ) {
        foreach ( $this->getDefaults() as $propname => $defvalue) {
            if ( isset($settings[$propname]) ) {
                $this->$propname = $settings[$propname];
            }
        }
    }

    /** setFromField function
     *  Sets object variables according to field setting
     * @param $field
     */
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
            $this->input_type    = AA_Stringexpand::unalias($funct[0]);
            $this->param         = array_slice( $funct, 1 );
            $this->html_rb_show  = $field["html_show"];
            if ( isset($field["const_arr"]) ) {
                $this->const_arr  = $field["const_arr"];
            }
        }
    }

    /** validate function
     *  Validates $value is is 'valid'
     * @param $value
     * @param $err
     */
    function validate($value, &$err) {
        return ValidateInput($this->varname, $this->name, $value, $err, $this->required, $this->valid);
    }

    // private methods - helper - data manipulation

    /** implodeVal function
     * Joins all values to one long string separated by $delim
     * @param $delim
     */
    function implodeVal($delim=',') {
        $ret = '';
        if ( isset($this->value) AND is_array($this->value) ) {
            foreach ( $this->value as $v ) {
                $ret .= ($ret ? $delim : ''). $v['value']; // add value separator just if field is filled
            }
        }
        return $ret;
    }

    /** fill_const_arr function
      * Fills array used for list selection. Fill it from constant group or
      * slice.
      * It never refills the array (and we relly on this fact in the code)
      * @return unpacked slice_id if array is filled from slice
      * (not so important value, isn't?)
      * @param $slice_field
      * @param $conds
      * @param $sort
      * @param $whichitems
      * @param $ids_arr
      * @param $tagprefix
      */
    function fill_const_arr($slice_field="", $conds=false, $sort=false, $whichitems=AA_BIN_ACT_PEND, $ids_arr=false, $tagprefix=null) {
        if ( isset($this->const_arr) and is_array($this->const_arr) ) {  // already filled
            return;
        }
        $this->const_arr = array();  // Initialize

        $zids = $ids_arr ? new zids($ids_arr) : false;  // transforms content array to zids
        if ( !($constgroup=$this->param[0]) ) {  // assignment
            $this->const_arr = array();
        } elseif ( substr($constgroup,0,7) == "#sLiCe-" ) { // prefix indicates select from items
            $sid = substr($constgroup, 7);
            /** Get format for which represents the id
             *  Could be field_id (then it is grabbed from item and truncated to 50
             *  characters, or normal AA format string.
             *  Headline is default (if empty "$slice_field" is passed)
             */
            if (!$slice_field) {
                $slice_field = GetHeadlineFieldID($sid, "headline.");
                if (!$slice_field) {
                    return;
                }
            }
            $format = AA_Fields::isField($slice_field) ? '{substr:{'.$slice_field.'}:0:50}' : $slice_field;
            $this->const_arr = GetFormatedItems( $sid, $format, $zids, $whichitems, $conds, $sort, $tagprefix);
            return $sid; // in most cases not very impotant information, but used in inputRelatION() input type
        } else {
            $this->const_arr = GetFormatedConstants($constgroup, $slice_field, $ids_arr, $conds, $sort);
        }
        if ( !isset($this->const_arr) OR !is_array($this->const_arr) ) {
            $this->const_arr = array();
        }
    }

    /** varname_modify function
     *  Modifies varname in case we need to display two (or more) inputs
     *  for one field (varname_modified is used insted of varname - if set).
     * @param $add
     */
    function varname_modify($add) {
        return ($this->varname_modified = $this->varname . $add);
    }

    /** varname function
     *  Returns curent varname
     */
    function varname() {
        return get_if($this->varname_modified, $this->varname);
    }

    /** get_inputtype function
     * input_type manipulation functions
     */
    function get_inputtype() {
        return $this->input_type;
    }
    /** set_inputtype function
     * @param $type
     */
    function set_inputtype($type) {
        $this->input_type = $type;
    }
    /** getDbfield function
     *
     */
    function getDbfield() {
        return get_if($this->dbfield, $this->varname());
    }

    /** prepareVars function
      * Grabs common variables from object. Internal function used as shortcut
      * in most of input functions (maybe all)
      * @param $valtype
      */
    function prepareVars($valtype='first') {
        if     (isset($this->value_modified)) {
            $val = $this->value_modified;
        } elseif ($valtype == 'first') {
            $val = $this->value[0]['value'];
        } else {
            $val = $this->value;
        }
        return array( $this->varname(), $val, $this->additional);
    }

    /** echoo function
     *  Echo wrapper - prints output to string insted of to output
     *  If result_mode is cache, no result is printed - only the cache is filled
     * @param $txt
     */
    function echoo($txt) {
        if ( $this->result_mode != 'cache' ) $this->result .= $txt;
    }

    /** echovar function
     *  Similar function to echoo, but it allows to create print aliases
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
     * @param $txt
     * @param $part
     * @param $param is not used, yet
     * @param $aliasname used for alias name different from 'inputvar'
     */
    function echovar($txt, $part='', $param='', $aliasname='') {
        global $contentcache;
        if ( $this->result_mode == 'expand' ) {   // write directly to the output
            $this->echoo($txt);
        } else {
            if ($aliasname=='') {
                $aliasname='inputvar';
            };
            $alias = $aliasname.':'.$this->id. ($part  ? ":$part"  : ''). ($param ? ":$param" : '');
            $contentcache->set($alias, $txt);
            $this->echoo('{'.$alias.'}');
        }
    }

    /** if_selected function
     * returns $ret_val if given $option is selected for current field
     * @param $option
     * @param $ret_val
     */
    function if_selected($option, $ret_val) {
        // fill selected array from value
        $this->_fillSelected();
        return $this->selected[(string)$option] ? $ret_val : '';
    }
    /** _fillSelected function
     *
     */
    function _fillSelected() {
        if ( !isset( $this->selected ) ) {  // not cached yet => create selected array
            if ( isset($this->value) AND is_array($this->value) ) {
                foreach ( $this->value as $v ) {
                    if ( $v['value'] ) {
                        $this->selected[(string)$v['value']] = true;
                    }
                }
            }
        }
    }

    /** get function
     *  Returns field as it should be displayed on screen (or at least template
     *  for the field with filled $contentcache object
     * @param $result_mode
     * @param $item
     */
    function get( $result_mode='expand', $item=null ) {
        // @todo check, how to do it better - this do not work if
        // "slice_field" parameter uses {subst...} for examle
        AA_Stringexpand::unaliasArray($this->param);
        $this->result_mode = $result_mode;
        $this->echoo($this->input_before);
        switch ($this->mode. '_'. $this->input_type) {
            case 'freeze_chb': $this->value_modified = $this->value[0]['value'] ? _m("set") : _m("unset");
                               $this->staticText();       break;
            case 'freeze_wi2':
            case 'freeze_mse':
            case 'freeze_mfl':
            case 'freeze_mch': $this->value_modified = $this->implodeVal();
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
            case 'anonym_mfl':
            case 'normal_mfl': list($actions, $rows) = $this->param;
                               $actions = get_if($actions, 'MDAC'); // move, delete, add, change
                               $this->varname_modify('[]');         // use slightly modified varname

                               // prepare value array for selectbox
                               foreach ( $this->value as $content ) {
                                   $this->const_arr[$content['value']] = $content['value'];
                               }
                               $this->inputRelation($rows, '', MAX_RELATED_COUNT, '', '', $actions);
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
            case 'normal_sel': list(,$slice_field, $usevalue, $whichitems, $conds_str, $sort_str) = $this->param;
                               if ( !is_null($item) ) {
                                   $conds_str = $item->unalias($conds_str);
                               }
                               if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
                               $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()
                               $this->inputSelect($usevalue);
                               break;
            case 'anonym_rio':
            case 'normal_rio': list(,$ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str) = $this->param;
                               $this->inputRadio($ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str);
                               break;
            case 'anonym_mch':
            case 'normal_mch': list(,$ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str) = $this->param;
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->inputMultiChBox($ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str);  // move_right
                               break;
            case 'anonym_mse':
            case 'normal_mse': list(,$rows, $slice_field, $whichitems, $conds_str, $sort_str) = $this->param;
                               $rows = ($rows < 1) ? 5 : $rows;
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->inputMultiSelect($rows, $slice_field, $whichitems, $conds_str, $sort_str);
                               break;
            case 'anonym_fil':
            case 'normal_fil': list($accepts, $text, $hlp) = $this->param;
                               $this->inputFile($accepts, $text, $hlp);
                               break;
            case 'anonym_dte':
            case 'normal_dte': if ( strstr($this->param[0], "'")) {     // old format
                                   $this->param = explode("'",$this->param[0]);
                               }
                               list($y_range_minus, $y_range_plus, $from_now, $display_time) = $this->param;
                               $this->dateSelect($y_range_minus, $y_range_plus, $from_now, $display_time);
                               break;
            case 'anonym_pre':
            case 'normal_pre': list(, $maxlength, $fieldsize, $slice_field, $usevalue, $adding, $secondfield, $add2constant, $whichitems, $conds_str, $sort_str) = $this->param;
                               // add2constant is used in insert_fnc_qte - adds new value to constant table
                               if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
                               $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()
                               $this->inputPreSelect($maxlength, $fieldsize, $adding, $secondfield, $usevalue );
                               break;
            case 'anonym_tpr':
            case 'normal_tpr': $this->textareaPreSelect(get_if($this->param[1],4),    // rows
                                                        get_if($this->param[2],60));  // cols
                               break;
            case 'anonym_iso':
            case 'normal_iso':
            case 'freeze_iso': list(, $rows, $mode, $design, $tp, $actions, $whichitems, $conds, $condsrw, $slice_field) = $this->param;
                               $mode      = get_if($mode,'AMB');         // AMB - show 'Add', 'Add mutual' and 'Add backward' buttons
                               $tp        = get_if($tp,  'AMB');         // Default to use the AMP table
                               $tagprefix = ( isset($GLOBALS['tps'][$tp])              ? $GLOBALS['tps'][$tp] :
                                            ( isset($GLOBALS['apc_state']['tps'][$tp]) ? $GLOBALS['apc_state']['tps'][$tp] :
                                                                                        null ));
                               if ( is_null($tagprefix) ) {
                                   $this->msg[] = _m("Unable to find tagprefix table %1", array($tp));
                               }
                               $this->varname_modify('[]');         // use slightly modified varname
                               $sid = $this->fill_const_arr($slice_field, false, false, AA_BIN_ALL, $this->value, $tagprefix);  // if we fill it there, it is not refilled in inputSel()
                               if ( $this->mode == 'freeze' ) {
                                   $this->value_modified = $this->implodeVal('<br>');
                                   $this->staticText();
                               } else {
                                   $this->inputRelation($rows, $sid, MAX_RELATED_COUNT, $mode, $design, $actions, $whichitems, $conds, $condsrw);
                               }
                               break;
            case 'anonym_hco':
            case 'normal_hco': list($constgroup, $levelCount, $boxWidth, $rows, $horizontalLevels, $firstSelectable, $levelNames) = $this->param;
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->hierarchicalConstant($constgroup, $levelCount, $boxWidth, $rows, $horizontalLevels, $firstSelectable, explode('~',$levelNames));
                               break;
            case 'anonym_wi2':
            case 'normal_wi2': list($constgroup, $rows, $wi2_offer, $wi2_selected, $slice_field, $whichitems, $conds_str, $sort_str) = $this->param;
                               if ( !is_null($item) ) {
                                   $conds_str = $item->unalias($conds_str);
                               }
                               $this->varname_modify('[]');         // use slightly modified varname
                               $this->twoBox(get_if($rows,5), $wi2_offer, $wi2_selected, $slice_field, $whichitems, $conds_str, $sort_str);
                               break;
            case 'anonym_pwd':  // handled in passwordModify
            case 'normal_pwd': list($fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help) = $this->param;
                               $this->passwordModify( $fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help);
                               break;
        //BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
            case 'anonym_lup':
            case 'normal_lup':
            case 'freeze_lup': list($url) = $this->param;
                               if ( $this->mode == 'freeze' ) {
                                   $this->value_modified = $this->implodaval('<br>');
                                   $this->staticText();
                               } else {
                                   $this->inputLocalURLPick($url);
                               }
                               break;
        //END// Local URL Picker | Omar/Jaime | 11-06-2005
        }
        return $this->result;
    }
    /** print_result function
     *
     */
    function print_result() {
        echo $this->result;
    }


    // pivate functions - functions helping field display ---------------------

    /** needed function
     * functions to show additional field data
     */
    function needed() {
        if ( $this->required ) {
            $this->echoo( "&nbsp;*" );
        }
    }

    function help($hlp) {
        if ( $hlp ) {
            $this->echoo( "<div class=\"tabhlp\">$hlp</div>" );
        }
    }

    function morehelp($hlp) {
        if ( $hlp ) {
            $this->echoo( "&nbsp;<a href=".safe($hlp)." target='_blank'>?</a>" );
        }
    }

    /** helps function
     * shows help message and link to more help document, if set
     * @param $plus
     * @param $hlp
     * @param $more_hlp
     */
    function helps( $plus=false, $hlp=null, $more_hlp=null ) {
        $this->morehelp(is_null($more_hlp) ? $this->input_morehlp : $more_hlp );
        $this->help(    is_null($hlp)      ? $this->input_help    : $hlp );
        if ( $plus ) {
            $this->echoo("</td>\n</tr>\n");
        }
    }

    /** field_name function
     * Prints field name (and 'needed' sign - star) in table cell for inputform
     * @param $plus
     * @param $colspan
     * @param $name
     */
    function field_name( $plus=false, $colspan=1, $name=null ) {
        $name = is_null($name) ? $this->name : $name;
        if ( $plus=='plus' ) {
            $this->echoo("\n<tr class=\"formrow{formpart}\">");
        }
        $this->echoo("\n <td class=\"tabtxt\" ".
                      (($colspan==1) ? '': "colspan=\"$colspan\"").
                      '><b>'. $name .'</b>');
        $this->needed();
        $this->echoo("</td>\n");
        if ( $plus=='plus' ) {
            $this->echoo(' <td>');
        }
    }

    /** get_convertors function
     *  Print links to document convertors, if convertors are installed
     */
    function get_convertors() {
        global $CONV_HTMLFILTERS;
        if ( isset($CONV_HTMLFILTERS) AND is_array($CONV_HTMLFILTERS) ) {
            $delim='';
            foreach ( $CONV_HTMLFILTERS as $format => $program) {
                if ( $format == 'iconv' ) {
                    continue;
                }
                $convertor .= $delim . strtoupper(str_replace( '.', '', $format ));
                $delim = '/';
            }
            $convertor = "<a href=\"javascript:CallConvertor('".self_server(). AA_INSTAL_PATH."', '".$this->varname."')\">$convertor "._m('import') ."</a>";
        }
        return $convertor;
    }

    /** html_radio function
     * Prints html/plan_text radiobutton
     * @param $convert
     * @param $show_rp_butt
     */
    function html_radio($convert=false, $show_rp_butt=true) {
        global $sess;
        if ( $this->html_rb_show ) {
            $htmlvar     = $this->varname."html";
            $radio_html  = "<input type=\"radio\" name=\"$htmlvar\" value=\"h\"". (  $this->html_flag ? " checked>" : ">" )."</input>";
            $radio_plain = "<input type=\"radio\" name=\"$htmlvar\" value=\"t\"". ( !$this->html_flag ? " checked>" : ">" )."</input>";
//        debug($this->varname, $this->html_flag, !$this->html_flag,$radio_html, $radio_plain );
            $htmlareaedit= "<a href=\"javascript:openHTMLAreaFullscreen('".$this->varname."', '".$sess->id."');\">"._m("Edit in HTMLArea")."</a>"; // used for HTMLArea
            // conversions menu
            if ( $convert AND ($convertor = $this->get_convertors())) {
                $this->echoo('  <table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="'. COLOR_TABBG ."\">\n   <tr><td>");
                if ($show_rp_butt) {
                    $this->echoo('<!-- used for hiding html/plain radio buttons, dont remove !!! --><span id="htmlplainspan'.$this->varname.'">');
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
                $this->echoo("<td align=\"right\">");
                $this->echovar($convertor,   'conv');
                $this->echoo("</td></tr>\n  </table>");
            } else {
                if ($show_rp_butt) {
                    $this->echoo('<!-- used for hiding html/plain radio buttons, dont remove !!! --><span id="htmlplainspan'.$this->varname.'">');
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

    /** getRadioButtonTag function
     * Returns one radio tag - Used in inputRadio
     * @param $k
     * @param $v
     * @param $add
     */
    function getRadioButtonTag(&$k, &$v, $add='') {
        $name = $this->varname();
        $ret  = "<input type='radio' name='$name' value='". htmlspecialchars($k) ."' $add".getTriggers("input",$name);
        $ret .= $this->if_selected($k, " checked");
        $ret .= ">".htmlspecialchars($v);
        return $ret;
    }

    /** getOneChBoxTag function
     * Returns one checkbox tag - Used in inputMultiChBox
     * @param $k
     * @param $v
     * @param $add
     */
    function getOneChBoxTag(&$k, &$v, $add='') {
        $name = $this->varname();
        $ret = "<nobr><input type='checkbox' name='$name'
             value='". htmlspecialchars($k) ."' $add".getTriggers("input",$name);
        $ret .= $this->if_selected($k, " checked");
        $ret .= ">".htmlspecialchars($v)."</nobr>";
        return $ret;
    }

    // field displaying functions ---------------------------------------------


    /** inputChBox function
     *  Prints html tag <input type=checkbox .. to 2-column table
     *  for use within <form> and <table> tag
     * @param $changeorder
     * @param $colspan
     */
    function inputChBox($changeorder=false, $colspan=1){
        list($name,$val,$add) = $this->prepareVars();

        $this->echoo("\n<tr class=\"formrow{formpart}\">");
        if ( !$changeorder ) {
            $this->field_name(false, $colspan);
        }
        $this->echoo("<td>");
        $this->echovar("<input type=\"checkbox\" name=\"$name\" $add ".
                              ($val ? " checked" : '').
                              getTriggers("input",$name).">");
        $this->helps();
        $this->echoo("</td>");
        if ( $changeorder ) {
            $this->field_name($colspan);
        }
        $this->echoo("</tr>\n");
    }
    /** dateSelect function
     * @param $y_range_minus
     * @param $y_range_plus
     * @param $from_now
     * @param $display_time
     */
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

    /** inputText function
    * Prints html tag <input type=text .. to 2-column table
    * for use within <form> and <table> tag.
    * @param $maxsize
    * @param $size
    * @param string $type allows to show <INPUT type=PASSWORD> field as well
    *                     (and perhaps BUTTON and SUBMIT also, but I do not see
    *                      any usage) - added by Jakub, 28.1.2003
    */
    function inputText($maxsize=255, $size=25, $type="text") {
        list($name,$val,$add) = $this->prepareVars();
        $val     = htmlspecialchars($val);
        $maxsize = get_if( $maxsize, 254 );
        $size    = get_if( $size   , 25 );

        $this->field_name('plus');
        $this->html_radio();
        $this->echovar( "<input type=\"$type\" name=\"$name\" size=\"$size\"".
                        $GLOBALS['mlxFormControlExtra'].
                        " maxlength=\"$maxsize\" value=\"$val\"".getTriggers("input",$name).">" );
        $this->helps('plus');
    }

    /** staticText function
    * Prints two static text to 2-column table
    * for use within <table> tag
    * @param $safing
    * @param $type
    */
    function staticText($safing=true, $type='first') {
        list($name,$val,$add) = $this->prepareVars($type);
        if ( $safing ) $val   = htmlspecialchars($val);
        $this->field_name('plus');
        $this->echovar( $val );
        $this->helps('plus');
    }

    /** hidden function
    * Prints html tag <input type=hidden .. to 2-column table
    * for use within <form> and <table> tag
    * @param $safing
    */
    function hidden($safing=true) {
        list($name,$val,$add) = $this->prepareVars();
        if ( $safing ) {
            $val=htmlspecialchars($val);
        }
        $this->echoo('<tr height="1" colspan="2"><td height="1">');
        $this->echovar( "<input type=\"hidden\" name=\"$name\" value=\"$val\">" );
        $this->echoo("</td></tr>\n");
    }

    /** textarea function
    * Prints html tag <textarea .. to 2-column table
    * for use within <form> and <table> tag
    *
    * @param $rows
    * @param $cols
    * @param $single
    * @param $showrich_href - have we show "Show Editor" link? (if we want to, we have
    *                  to include /misc/htmlarea/aafunc.js script to the page
    * @param $showhtmlarea
    */
    function textarea( $rows=4, $cols=60, $single=false, $showrich_href=true, $showhtmlarea=false) {

        global $BName, $BPlatform, $sess;

        list($name,$val,$add) = $this->prepareVars();
        // make the textarea bigger, if already filled with long text
        $rows    = max($rows, min(substr_count($val,"\n")+1, 30));
        $val     = htmlspecialchars($val);
        $colspan = $single ? 2 : 1;
        $this->echoo("<tr class=\"formrow{formpart}\">");
        $this->field_name(false, $colspan);
        if ($single) {
            $this->echoo("</tr>\n<tr class=\"formrow{formpart}\">");
        }
        $this->echoo("<td colspan=\"$colspan\">");
        $this->html_radio($showhtmlarea ? false : 'convertors');

        // fix for IE - where the textarea icons are too big so there is
        // no space for the text
        if ($showhtmlarea) {
            $rows += 8;
        }
        $tarea .= "<textarea id=\"$name\" name=\"$name\" rows=\"$rows\" ".$GLOBALS['mlxFormControlExtra']." cols=\"$cols\" style=\"width:100%\" ".getTriggers("textarea",$name).">$val</textarea>\n";
        if ($showhtmlarea) {
            $tarea .= getFrmJavascript( "htmlareas[htmlareas.length] = '$name'");
        } elseif ( $showrich_href ) {
            $tarea .= getFrmJavascript( 'showHTMLAreaLink("'.$name.'");');
        }
        $this->echovar($tarea);
        $this->helps('plus');
    }

    /** richEditTextarea function
    * On browsers which do support it, loads a special rich text editor with many
    * advanced features based on triedit.dll
    * On the other browsers, loads a normal text area
    * @param $rows
    * @param $cols
    * @param $type
    * @param $single
    */

    function richEditTextarea($rows=10, $cols=80, $type="class", $single="") {
        $this->textarea($rows, $cols, false, false, true);
    }


    /** inputRadio function
    * Prints a radio group, html tags <input type="radio" .. to 2-column table
    * for use within <form> and <table> tag
    * @param $ncols
    * @param $move_right
    * @param $slice_field
    * @param $whichitems
    * @param $conds_str
    * @param $sort_str
    */
    function inputRadio($ncols=0, $move_right=true, $slice_field='', $whichitems=AA_BIN_ACT_PEND, $conds_str=false, $sort_str=false) {
        list($name,$val,$add) = $this->prepareVars('multi');
        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }
        $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()
        foreach ( $this->const_arr as $k => $v ) {
            $records[] = $this->getRadioButtonTag($k, $v, $add);
        }
        $this->printInMatrix_Frm($records, $ncols, $move_right);
    }


    /** inputMultiChBox function
    * Prints html tag <input type="radio" .. to 2-column table
    * for use within <form> and <table> tag
    * @param $ncols
    * @param $move_right
    * @param $slice_field
    * @param $whichitems
    * @param $conds_str
    * @param $sort_str
    */
    function inputMultiChBox($ncols=0, $move_right=true, $slice_field='', $whichitems=AA_BIN_ACT_PEND, $conds_str=false, $sort_str=false) {
        list($name,$val,$add) = $this->prepareVars('multi');
        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }
        $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()
        foreach ( $this->const_arr as $k => $v ) {
            $records[] = $this->getOneChBoxTag($k, $v, $add);
        }
        $this->printInMatrix_Frm($records, $ncols, $move_right);
    }

    /** printInMatrix_Frm function
    * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
    * - for use internal use of FrmInputMultiChBox and FrmInputRadio
    * @param $records
    * @param $ncols
    * @param $move_right
    */
    function printInMatrix_Frm($records, $ncols, $move_right) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $this->field_name('plus');

        if (is_array($records)) {
            if (! $ncols) {
                $this->echovar( implode('', $records) );
            } else {
                $nrows     = ceil (count ($records) / $ncols);
                $this->echoo('<table border="0" cellspacing="0">');
                for ($irow = 0; $irow < $nrows; $irow ++) {
                    $ret  .= '<tr>';
                    for ($icol = 0; $icol < $ncols; $icol ++) {
                        $pos  = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
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

    /** get_options function
     *  returns select options created from given array
     * @param $arr
     * @param $usevalue
     * @param $testval
     * @param $restrict
     * @param $add_empty
     * @param $do_not_select
     */
    function get_options( &$arr, $usevalue=false, $testval=false, $restrict='all', $add_empty=false, $do_not_select=false) {
        $selectedused  = false;
        $select_string = ( $do_not_select ? ' class="sel_on"' : ' selected class="sel_on"');

        $already_selected = array();    // array where we mark selected values
        $pair_used        = array();    // array where we mark used pairs
        $this->_fillSelected();         // fill selected array by all values in order we can print invlaid values later
        if (isset($arr) && is_array($arr)) {
            foreach ( $arr as $k => $v ) {
                if ($usevalue) {
                    $k = $v;    // special parameter to use values instead of keys
                }

                // ignore pairs (key=>value) we already used
                if ($pair_used[$k."aa~$v"]) {
                    continue;
                }
                $pair_used[$k."aa~$v"] = true;   // mark this pair - do not use it again

                $select_val = $testval ? $v : $k;
                $selected   = $this->if_selected($select_val, $select_string);
                if ($selected != '') {
                    $selectedused = true;
                    $already_selected[(string)$select_val] = true;  // flag
                }
                if ( ($restrict == 'selected')   AND !$selected ) {
                    continue;  // do not print this option
                }
                if ( ($restrict == 'unselected') AND $selected  ){
                    continue;  // do not print this option
                }
                $ret .= "\n  <option value=\"". htmlspecialchars($k) ."\" $selected>".htmlspecialchars($v)."</option>";
            }
        }
        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if ( isset( $this->selected ) AND is_array( $this->selected ) AND ($restrict != 'unselected')) {
            foreach ( $this->selected as $k =>$v ) {
                if ( !$already_selected[$k] ) {
                    $ret .= "\n  <option value=\"". htmlspecialchars($k) ."\" selected class=\"sel_missing\">".htmlspecialchars($k)."</option>";
                    $selectedused = true;
                }
            }
        }
        if ( $add_empty ) {
            $emptyret = "\n  <option value=\"\"";
            if ($selectedused == false) {
                $emptyret .= ' selected class="sel_on"';
            }
           $emptyret .= '> </option>';
           $ret = $emptyret . $ret;
        }
        return $ret;
    }

    /** inputMultiSelect function
     * Prints html tag <select multiple .. to 2-column table
     * for use within <form> and <table> tag
     * @param $rows
     * @param $slice_field
     * @param $whichitems
     * @param $conds_str
     * @param $sort_str
     */
    function inputMultiSelect($rows=6, $slice_field='', $whichitems=AA_BIN_ACT_PEND, $conds_str=false, $sort_str=false) {
        list($name,$val,$add) = $this->prepareVars('multi');
        $rows                 = get_if($rows, 6);
        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }
        $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()

        $this->field_name('plus');
        $ret       ="<select name=\"$name\" size=\"$rows\" multiple".getTriggers("select",$name).">";
        $ret      .= $this->get_options( $this->const_arr, false, false, 'all', !$this->required);
        $option_no = count($this->const_arr) + ($this->required ? 0:1);
        // add blank rows if asked for
        while ( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
            $ret .= AA_WIDTHTOR;
        }
        $ret .= "</select>";
        $this->echovar( $ret );
        $this->helps('plus');
    }

    /** inputRelation function
    * Prints html tag <select multiple .. and "Add" relation button
    * to 2-column table for use within <form> and <table> tag
    * @param $rows
    * @param $sid
    * @param $minrows
    * @param $mode    - which buttons to show in related item window:
    *                     'A'dd, add 'M'utual, 'B'ackward
    * @param $design
    * @param $actions - which action to show:
    *                     'M'ove (up and down),
    *                     'D'elete relation,
    *                     add 'R'elation,
    *                     add 'N'ew related item
    *                     'E'dit related item
    *                     'A'dd text field (you can type the value - see mft)
    *                     'C'hange the value (by typing - see mft input type)
    * @param $whichitems
    * @param $conds
    * @param $condsrw
    */
    function inputRelation($rows=6, $sid='', $minrows=0, $mode='AMB', $design=false, $actions='MDR', $whichitems=AA_BIN_ACT_PEND, $conds="", $condsrw="") {
        list($name,$val,$add) = $this->prepareVars('multi');
        $rows                 = get_if($rows, 6);
        // backward compatibility - 0 means "not show move buttons", 1 - "show"
        $actions              = get_if($actions, 'DR');
        $actions              = str_replace(array('0','1'), array('DR','MDR'),(string)$actions);
        $movebuttons          = (strpos($actions,'M') !== false);

        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }

        $this->field_name('plus');
        $ret       ="<select name=\"$name\" size=\"$rows\" multiple".getTriggers("select",$name).">";
        $ret      .= $this->get_options( $this->const_arr, false, false, 'all', false, true);
        $option_no = count($this->const_arr) + ($this->required ? 0:1);
        // add blank rows if asked for
        while ( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
            $ret .= AA_WIDTHTOR;
        }
        $ret .= "</select>";

        $this->echoo('<table border="0" cellspacing="0"><tr>');
        if ($movebuttons) {
            $this->echoo("\n <td rowspan=\"2\">");
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
        $this->echoo("</tr>\n <tr><td valign=\"bottom\" align=\"center\">\n");
        if (strpos($actions,'R') !== false) {
            $this->echoo("<input type='button' value='". _m("Add") ."' onclick='OpenRelated(\"$name\", \"$sid\", \"$mode\", \"$design\", \"$whichitems\",\"".rawurlencode($conds)."\",\"".rawurlencode($condsrw)."\",\"".get_admin_url('related_sel.php3')."\" )'>\n");
        }
        if (strpos($actions,'N') !== false) {
            $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("New") ."' onclick=\"OpenWindowTop('". Inputform_url(true, null, $sid, 'close_dialog', null, $name) .  "');\">\n");
        }
        if (strpos($actions,'E') !== false) {
            $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Edit") ."' onclick=\"EditItemInPopup('". Inputform_url(false, null, $sid, 'close_dialog', null, $name) .  "', document.inputform['".$name."']);\">\n");
        }
        // used mainly by mft
        if (strpos($actions,'A') !== false) {
            $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("New") ."' onclick=\"sb_AddValue(document.inputform['".$name."'], '"._m('Enter the value')."');\">\n");
        }
        // used mainly by mft
        if (strpos($actions,'C') !== false) {
            $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Change") ."' onclick=\"sb_EditValue(document.inputform['".$name."'], '"._m('Enter the value')."');\">\n");
        }
        if (strpos($actions,'D') !== false) {
            $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Delete") ."' onclick=\"sb_RemoveItem(document.inputform['".$name."']);\">\n");
        }
        $this->echoo(getFrmJavascript("listboxes[listboxes.length] = '$name';"));
        $this->echoo("</td></tr></table>\n");
        $this->helps('plus');
    }


    /** inputRelation2 function
     * Prints html tag <select multiple .. and "Add" relation button
     * to 2-column table for use within <form> and <table> tag
     * @param $rows
     * @param $sid
     * @param $minrows
     * @param $mode
     * @param $design
     * @param $movebuttons
     * @param $whichitems
     * @param $conds
     * @param $condsrw
     */
    function inputRelation2($rows=6, $sid='', $minrows=0, $mode='AMB', $design=false, $movebuttons=true, $whichitems=AA_BIN_ACT_PEND, $conds="", $condsrw="") {
        list($name,$val,$add) = $this->prepareVars('multi');
        $rows                 = get_if($rows, 6);
        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }

        $this->field_name('plus');

        $new_version = true;
        if ( $new_version ) {
            $varname = $this->varname;  // name without ending []
            $var_code = '
            <table width="100%" border="0" cellpadding="0" cellspacing="0" class="formtable" id="rel'.$varname.'">
              <tr>
                <th>'._m('Item').'</th>
                <th>'._m('Actions').'</th>
              </tr>';
            $i=0;
            foreach ( (array)$this->const_arr as $id => $text) {
                $tr_id     = 'rel'.$varname.'old'.($i++);
                $var_code .= '<tr id="'.$tr_id.'">
                <td>'.htmlentities($text).'<input type="hidden" name="'.$name.'" value="'.htmlentities($id).'"></td>
                <td>'.
                  GetAAImage("edit.gif", _m('Edit'), 16, 16).
                  GetAAImage("delete.gif", _m('Delete'), 16, 16).
                  '<a href="javascript:MoveRowUp(\''.$tr_id.'\')">'. GetAAImage("up.gif", _m('Move up'), 16, 16). '</a>'.
                  '<a href="javascript:MoveRowDown(\''.$tr_id.'\')">'. GetAAImage("down.gif", _m('Move down'), 16, 16). '</a>'.
                '</td>
              </tr>';
            }
            $var_code .= '</table>';
            $this->echovar( $var_code );
            $var_code = '
            <div>
              <input type="button" value="'. _m("Add") ."\" onclick=\"OpenRelated('".$this->varname."', '$sid', '$mode', '$design', '$whichitems','".rawurlencode($conds)."','".rawurlencode($condsrw)."','".get_admin_url('related_sel.php3')."' )\">
            </div>
            ";
            $this->echovar( $var_code, 'buttons' );
        } else {
            $ret       = "<select name=\"$name\" size=\"$rows\" multiple".getTriggers("select",$name).">";
            $ret      .= $this->get_options( $this->const_arr, false, false, 'all', false);
            $option_no = count($this->const_arr) + ($this->required ? 0:1);
            // add blank rows if asked for
            while ( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
                $ret .= AA_WIDTHTOR;
            }
            $ret .= "</select>";

            $this->echoo('<table border="0" cellspacing="0"><tr>');
            if ($movebuttons) {
                $this->echoo("\n <td rowspan=\"2\">");
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
            $this->echoo("</tr>\n <tr><td valign=\"bottom\" align=\"center\">
              <input type='button' value='". _m("Add") ."' onclick='OpenRelated(\"$name\", \"$sid\", \"$mode\", \"$design\", \"$whichitems\",\"".rawurlencode($conds)."\",\"".rawurlencode($condsrw)."\",\"".get_admin_url('related_sel.php3')."\" )'>
              &nbsp;&nbsp;");
            $this->echoo("<input type='button' value='". _m("Delete") ."' onclick=\"sb_RemoveItem(document.inputform['".$name."']);\">\n");
            $this->echoo(getFrmJavascript("listboxes[listboxes.length] = '$name';"));
            $this->echoo("</td></tr></table>\n");
        }
        $this->helps('plus');
    }


    /** hierarchicalConstant function
     *  shows boxes allowing to choose constant in a hiearchical way
     * @param $group_id
     * @param $levelcount
     * @param $boxWidth
     * @param $rows
     * @param $horizontal
     * @param $firstSelect
     * @param $levelNames
     */
    function hierarchicalConstant($group_id, $levelCount, $boxWidth, $rows, $horizontal=0, $firstSelect=0, $levelNames="") {
        static $hcid = 0;
        $hcid++;   // this is hc identifier
        list($name,$val,$add) = $this->prepareVars('multi');
        $levelCount           = get_if( $levelCount, 3 );
        $rows                 = get_if( $rows      , 5 );

        $this->field_name('plus');
        $this->echovar( getHierConstInitJavaScript($hcid, $group_id, $levelCount, "inputform", false), 'init_javascript' );
        $this->echoo( getHierConstBoxes($hcid, $levelCount, $horizontal, $name, false, $firstSelect, $boxWidth, $levelNames));

        $widthTxt = str_repeat("m",$boxWidth);

        /* OFMG
           20060421
           When you delete a value from a hierarchicalConstant mainbox
           it does not respond to the onChange triger
        */
        $this_triggers = getTriggers("select",$name);
        $aa_onchange_exist = strstr($this_triggers,'aa_onChange(');
        $delete_button_trigger = "";
        if( $aa_onchange_exist ){
            list($aux1,$fieldid,$aux2) = split("'",$aa_onchange_exist,3);
            $delete_button_trigger = 'aa_onChange("'.$fieldid.'"); ';
        }

        $this->echoo("
            <table border=\"0\" cellpadding=\"2\" width=\"100%\"><tr>
            <td align=\"center\"><b><span class=\"redtext\">"._m("Selected").":<span></b><br><br><input type=\"button\" value=\""._m("Delete")."\" onclick='hcDelete(\"$name\"); $delete_button_trigger'></td>
            <td>");
        $out = "\n<select name=\"$name\" multiple size=\"$rows\"".getTriggers("select",$name).">";
            if (is_array($val)) {
                $constants_names = GetConstants($group_id);
                foreach ( $val as $v) {
                    if ($v['value']) {
                        $out .= "\n  <option value=\"".htmlspecialchars($v['value'])."\">".htmlspecialchars($constants_names[$v['value']])."\n";
                    }
                }
            }
        $out .= "\n  <option value=\"wIdThTor\">$widthTxt";
        $out .= "</select>";
        $this->echovar($out);
        $this->echoo("</td></tr></table>\n");
        $this->echoo(getFrmJavascript("
            hcInit($hcid);
            hcDeleteLast('$name');
            listboxes[listboxes.length] = '$name';"));
        $this->helps('plus');
    }

    /** inputSelect function
    * Prints html tag <select .. to 2-column table
    * for use within <form> and <table> tag
    * @param $usevalue
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


    /** inputFile function
    * Prints html tag <input type=file .. to 2-column table
    * for use within <form> and <table> tag
    * @param $accepts
    * @param $text
    * @param $hlp
    */
    function inputFile($accepts="*/*", $text="", $hlp="") {
        list($name,$val,$add) = $this->prepareVars();
        $size=60;
        if ( !$accepts ) {
            $accepts = '*/*';
        }
        $this->inputText(255,$size);
        $this->name       = $text;
        $this->input_help = $hlp;
        $this->field_name('plus');
        $file_field_name = $name.'x';
        $this->echovar( "<input type=\"file\" name=\"$file_field_name\" size=\"$size\" accept=\"$accepts\"".getTriggers("input",$file_field_name).">", 'file');
        $this->helps('plus');
    }

    /** inputPreSelect function
    * Prints html tag <intup type=text ...> with <select ...> as presets to 2-column
    * table for use within <form> and <table> tag
    * @param $maxsize
    * @param $size
    * @param $adding
    * @param $secondfield
    * @param $usevalue
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
    /** textareaPreSelect function
     * @param $rows
     * @param $cols
     */
    function textareaPreSelect($rows=4, $cols=60) {
        list($name,$val,$add) = $this->prepareVars();
        $this->fill_const_arr();
        $val=safe($val);

        $this->field_name('plus');
        $this->html_radio();
        $this->echovar( "<textarea name=\"$name\" rows=\"$rows\" cols=\"$cols\" wrap=\"virtual\"".getTriggers("textarea",$name).">$val</textarea>" );
        $out  = "<select name=\"foo_$name\" onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">";
        $out .= $this->get_options( $this->const_arr );
        $out .= '</select>';
        $this->echovar( $out, 'presets' );
        $this->helps('plus');
    }

    /** twoBox function
     * Prints two boxes for multiple selection for use within <form> and <table> tag
     * @param $rows
     * @param $wi2_offer
     * @param $wo2selected
     * @param $slice_field
     * @param $whichitems
     * @param $conds_str
     * @param $sort_str
     */
    function twoBox($rows, $wi2_offer, $wi2_selected, $slice_field='', $whichitems=AA_BIN_ACT_PEND, $conds_str=false, $sort_str=false) {
        list($name,$val,$add) = $this->prepareVars('multi');
        if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems);  // if we fill it there, it is not refilled in inputSel()
        $wi2_offer    = get_if( $wi2_offer,    _m("Offer") );
        $wi2_selected = get_if( $wi2_selected, _m("Selected") );

        $this->field_name('plus');
        $this->echoo("<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr align=left>
          <td align=\"center'\ valign=\"top\">". $wi2_offer ."</td><td></td>
            <td align=\"center\" valign=\"top\">". $wi2_selected ."</td></tr>
          <tr align=left><td align=\"center\" valign='TOP'>");

        $offername = str_replace("[]", "", $name). '_1';
        $out  = "<select multiple name=\"".$offername."\" size=\"$rows\" ".getTriggers("select",$name).">\n";
        $out .= get_if( $this->get_options( $this->const_arr, false, false, 'unselected'), AA_WIDTHTOR );
        $out  .= '</select>';
        $this->echovar( $out, 'unselected' );

        $this->echoo("</td>
          <td>&nbsp;&nbsp;<input type=\"button\" value=\"  >>  \" onClick = \"MoveSelected(document.inputform.".$offername.",document.inputform['".$name."'])\" align=\"center\">
              <br><br>&nbsp;&nbsp;<input type=\"button\" value=\"  <<  \" onClick = \"MoveSelected(document.inputform['".$name."'],document.inputform.".$offername.")\" align=\"center\">&nbsp;&nbsp;</td>
          <td align=\"center\" valign=\"top\">");

        $out = "<select multiple name=\"".$name."\" size=\"$rows\"  ".getTriggers("select",$name).">";

        // we need values in second box sorted just like in values
        $selected_values = array();
        if ( isset($this->value) AND is_array($this->value) ) {
            foreach ( $this->value as $v ) {
                $key = (string)$v['value'];
                $selected_values[$key] = $this->const_arr[$key];
            }
        }

        $out .= get_if( $this->get_options( $selected_values, false, false, 'selected', false, true), AA_WIDTHTOR );
        $out  .= '</select>';
        $this->echovar( $out, 'selected' );

        $this->echoo(getFrmJavascript("listboxes[listboxes.length] = '$name';"));
        $this->echoo("
        </td></tr></table>");
        $this->helps('plus');
    }

    /** passwordModify function
     * @param $fieldsize
     * @param $change_pwd_label
     * @param $retype_pwd_label
     * @param $delete_pwd_label
     * @param $change_pwd_help
     * @param $retype_pwd_help
     */
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

    //BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
    /** inputLocalURLPick function
     * Local URL Picker | Omar/Jaime | 11-06-2005
     * @param $url
     */
    function inputLocalURLPick($url) {
        list($name,$val) = $this->prepareVars();
        $this->field_name('plus');
        $ret ="<input type=\"text\" name=\"$name\" size=\"60\" value=\"".htmlspecialchars($val)."\"".getTriggers("input",$name).">";

        $this->echoo('<table border="0" cellspacing="0"><tr>');
        $this->echoo("\n <td>");
        $this->echovar( $ret );
        $this->echoo("</td>\n");
        $this->echoo("</tr>\n <tr><td valign=\"bottom\" align=\"left\">\n");
        $this->echoo("<input type=\"button\" value=\"". _m("Add") ."\" onclick=\"OpenLocalURLPick(\"$name\",\"$url\",\"".self_server().get_aa_url("admin", false)."\",\"$val\")\">\n");
        $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Clear") ."' onclick=\"sb_ClearField(document.inputform['".$name."']);\">\n");
        $this->echoo("</td></tr></table>\n");
        $this->helps('plus');
    }
    //END// Local URL Picker | Omar/Jaime | 11-06-2005

}

// ----------------------- Public Form functions ----------

/** FrmMoreHelp function
 *  prints anchor tag with link to external documentation
 * @param $hlp
 * @param $text
 * @param $hint
 * @param $image
 */
function FrmMoreHelp($hlp, $text="", $hint="", $image=false) {
    if ($image) {
        $img = GetAAImage('help50.gif', htmlspecialchars($hint), 16, 12);
    }
    if ( $hlp ) {
        if (is_array($text) || ($image)) {
          return "&nbsp;".($image ? "&nbsp;&nbsp;" : $text["before"])."<a href=".safe($hlp)." target='_blank' ".
            (($hint != "") ? "title=\"".htmlspecialchars($hint)."\"" : "") .">".($image ? $img : $text["text"])."</a>".($image ? "" : $text["after"]);
        } elseif (is_string($text) && ($text != "")) {
            return "&nbsp;<a href=\"".safe($hlp)."\" target='_blank'>".($image ? $img : $text)."</a>";
        } else {
            return "&nbsp;<a href=\"".safe($hlp)."\" target='_blank' ".(($hint != "") ? "title=\"".htmlspecialchars($hint)."\"" : "").
               ">".($image ? $img : "?")."</a>";
        }
    } else {
        if (($text == "") && ($image)) {
          return "&nbsp;<abbr title=\"".htmlspecialchars($hint)."\">".$img."</abbr>";
        }
    }

}

/** FrmInputChBox function
 *  Prints html tag <input type=checkbox .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $checked
 * @param $changeorder
 * @param $add
 * @param $colspan
 * @param $needed
 * @param $hlp
 * @param $morehlp
 */
function FrmInputChBox($name, $txt, $checked=true, $changeorder=false, $add="", $colspan=1, $needed=false, $hlp="", $morehlp="") {
    $input = new AA_Inputfield($checked, false, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputChBox($changeorder, $colspan);
    $input->print_result();
}


/** FrmInputText function
 *  Prints html tag <input type=text .. to 2-column table
 *  for use within <form> and <table> tag.
 * @param $name
 * @param $text
 * @param $val
 * @param $maxsize
 * @param $size
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $html
 * @param string $type allows to show <INPUT type=PASSWORD> field as well
 *                     (and perhaps BUTTON and SUBMIT also, but I do not see
 *                      any usage) - added by Jakub, 28.1.2003
 */
function FrmInputText($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $type="text") {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, $type);
    $input->print_result();
}

/** FrmInputPwd function
 *  Prints password input box
 * @param $name
 * @param $text
 * @param $val
 * @param $maxsize
 * @param $size
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $html
 * @param $type
 */
function FrmInputPwd($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $type="password") {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, $type);
    $input->print_result();
}


/** FrmStaticText function
 *  Prints two static text to 2-column table for use within <table> tag
 * @param $txt
 * @param $val
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $safing
 */
function FrmStaticText($txt, $val, $needed=false, $hlp="", $morehlp="", $safing=1 ) {
    $input = new AA_Inputfield($val, false, 'normal', '', $txt, '', $needed, $hlp, $morehlp);
    $input->staticText($safing);
    $input->print_result();
}

/** FrmHidden function
 *  Prints html tag <input type=hidden .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $val
 * @param $safing
 */
function FrmHidden($name, $val, $safing=true ) {
    $input = new AA_Inputfield($val, false, 'normal', $name);
    $input->hidden($safing);
    $input->print_result();
}

/** FrmTextarea function
 *  Prints html tag <textarea .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $val
 * @param $rows
 * @param $cols
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $single
 */
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false, $hlp="", $morehlp="", $single="") {
    $html=false;  // it was in parameter, but was never used in the code /honzam 05/15/2004
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->textarea($rows, $cols, $single, false);
    $input->print_result();
}

/** FrmRichEditTextarea function
 *  On browsers which do support it, loads a special rich text editor with many
 *  advanced features based on triedit.dll
 *  On the other browsers, loads a normal text area
 * @param $name
 * @param $txt
 * @param $val
 * @param $rows
 * @param $cols
 * @param $type
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $single
 * @param $html
 */
function FrmRichEditTextarea($name, $txt, $val, $rows=10, $cols=80, $type="class", $needed=false, $hlp="", $morehlp="", $single="", $html=false) {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->richEditTextarea($rows, $cols, $type, $single);
    $input->print_result();
}

/** FrmDate function
 * @param $name
 * @param $txt
 * @param $val
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $display_time
 */
function FrmDate($name, $txt, $val, $needed=false, $hlp="", $morehlp="", $display_time=false) {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->dateSelect(7, 1, true, $display_time);
    $input->print_result();
}


/** FrmInputRadio function
 *  Prints a radio group, html tags <input type="radio" .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $arr
 * @param $selected
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $ncols
 * @param $move_right
 * @param $add
 */
function FrmInputRadio($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $ncols=0, $move_right=true, $add='') {
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputRadio($ncols, $move_right);
    $input->print_result();
}

/** FrmInputMultiSelect function
 *  Prints html tag <select multiple .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $text
 * @param $arr
 * @param $selected
 * @param $rows
 * @param $relation
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $minrows
 * @param $mode
 * @param $design
 */
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $rows=5, $relation=false, $needed=false, $hlp="", $morehlp="", $minrows=0, $mode='AMB', $design=false) {
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    if ( $relation ) {
        $input->inputRelation($rows, $relation, $minrows, $mode, $design);
    } else {
        $input->inputMultiSelect($rows);
    }
    $input->print_result();
}

/** FrmHierarchicalConstant function
 *  Print boxes allowing to choose constant in a hiearchical way
 * @param $name
 * @param $txt
 * @param $value
 * @param $group_id
 * @param $levelCount
 * @param $boxWidth
 * @param $rows
 * @param $horizontal
 * @param $firstSelect
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $levelNames
 */
function FrmHierarchicalConstant($name, $txt, $value, $group_id, $levelCount, $boxWidth, $rows, $horizontal=0, $firstSelect=0, $needed=false, $hlp="", $morehlp="", $levelNames="") {
    $input = new AA_Inputfield($value, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->hierarchicalConstant($group_id, $levelCount, $boxWidth, $rows, $horizontal, $firstSelect, $levelNames);
    $input->print_result();
}

/** FrmInputSelect function
 * Prints html tag <select .. to 2-column table
 * for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $arr
 * @param $selected
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $usevalue
 */
function FrmInputSelect($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $usevalue=false) {
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputSelect($usevalue);
    $input->print_result();
}

/** FrmInputMultiChBox function
 *  Prints html tag <input type="radio" .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $arr
 * @param $selected
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $ncols
 * @param $move_right
 */
function FrmInputMultiChBox($name, $txt, $arr, $selected="", $needed=false, $hlp="", $morehlp="", $ncols=0, $move_right=true) {
    // selected array we need to be in form array( 0 => array('value'=>val))
    // so we need to prepare it
    $sel = array();
    if (is_array($selected)) {
        foreach($selected as $val) {
            $sel[] = array('value'=>$val);
        }
    }

    $input = new AA_Inputfield($sel, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputMultiChBox($ncols, $move_right);
    $input->print_result();
}

/** FrmInputFile function
 *  Prints html tag <input type=file .. to 2-column table
 *  for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $needed
 * @param $accepts
 * @param $hlp
 * @param $morehlp
 */
function FrmInputFile($name, $txt, $needed=false, $accepts="image/*", $hlp="", $morehlp="" ){
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputFile($accepts);
    $input->print_result();
}

/** FrmInputPreSelect function
 *  Prints html tag <intup type=text ...> with <select ...> as presets
 *  to 2-column table for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $arr
 * @param $val
 * @param $maxsize
 * @param $size
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $adding
 * @param $secondfield
 * @param $usevalue
 */
function FrmInputPreSelect($name, $txt, $arr, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $adding=0, $secondfield="", $usevalue=false) {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputPreSelect($maxsize,$size,$adding,$secondfield,$usevalue);
    $input->print_result();
}
/** FrmTextareaPreSelect function
 * @param $name
 * @param $txt
 * @param $arr
 * @param $val
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $rows
 * @param $cols
 */
function FrmTextareaPreSelect($name, $txt, $arr, $val, $needed=false, $hlp="", $morehlp="",  $rows=4, $cols=60) {
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->textareaPreSelect($rows,$cols);
    $input->print_result();
}
/** FrmRelated function
 * @param $name
 * @param $txt
 * @param $arr
 * @param $rows
 * @param $sid
 * @param $mode
 * @param $design
 * @param $needed
 * @param $hlp
 * @param $morehlp
 */
function FrmRelated($name, $txt, $arr, $rows, $sid, $mode, $design, $needed=false, $hlp="", $morehlp="") {
    $input = new AA_Inputfield('', $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputRelation($rows, $relation, $minrows, $mode, $design);
    $input->print_result();
}

/** FrmTwoBox function
 *  Prints two boxes for multiple selection for use within <form> and <table>
 * @param $name
 * @param $txt
 * @param $arr
 * @param $selected
 * @param $rows
 * @param $needed
 * @param $wi2_offer
 * @param $wi2_selected
 * @param $hlp
 * @param $morehlp
 */
function FrmTwoBox($name, $txt, $arr, $selected, $rows, $needed=false, $wi2_offer='', $wi2_selected='', $hlp="", $morehlp="") {
    // $val is not used - there is only from historical reasons and should be removed accross files
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->twoBox($rows,$wi2_offer,$wi2_selected);
    $input->print_result();
}

/** Needed function
 * if $condition, shows star
 * @param $condition
 */
function Needed( $condition=true ) {
    if ( $condition ) {
        echo "&nbsp;*";
    }
}

/** PrintHelp function
 * if $txt, shows help message
 */
function PrintHelp( $txt ) {
    if ( $txt ) {
        echo "<div class=\"tabhlp\">$txt</div>";
    }
}

/** PrintMoreHelp function
 * if $txt, shows link to more help
 * @param $txt
 */
function PrintMoreHelp( $txt ) {
    if ( $txt ) {
        echo "&nbsp;<a href=\"$txt\" target=\"_blank\">?</a>";
    }
}

/** FrmChBoxEasy function
 * Prints html tag <input type=checkbox
 * @param $name
 * @param $checked
 * @param $add
 * @param $value
 */
function FrmChBoxEasy($name, $checked=true, $add="", $value='') {
  echo FrmChBoxEasyCode($name, $checked, $add, $value);
}
/** FrmChBoxEasyCode function
 * @param $name
 * @param $checked
 * @param $add
 * @param $value
 */
function FrmChBoxEasyCode($name, $checked=true, $add="", $value='') {
  $name  = safe($name);
  $value = safe($value); // $add=safe($add); NO!!

  return "<input type=\"checkbox\" name=\"$name\" $add".
    ($value   ? " value=\"$value\"" : '').
    ($checked ? " checked>" : ">");
}

/**FrmInputWithSelect function
 * Prints html tag <intup type=text ...> with <select ...> and buttons
 * for moving with items
 * to 2-column table for use within <form> and <table> tag
 * @param $name
 * @param $txt
 * @param $arr
 * @param $val
 * @param $input_maxsize
 * @param $input_size
 * @param $select_size
 * @param $numbered
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $adding
 * @param $secondfield
 * @param $usevalue
 */
function FrmInputWithSelect($name, $txt, $arr, $val, $input_maxsize=254, $input_size=25,
                            $select_size=6, $numbered=0, $needed=false, $hlp="", $morehlp="", $adding=0,
                            $secondfield="", $usevalue=false) {
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

    if ( !$input_maxsize ) {
        $input_maxsize = 254;
    }
    if ( !$input_size ) {
        $input_size = 25;
    }
    if ( !$select_size ) {
        $select_size = 6;
    }
    if ($secondfield) {
        $varsecfield = 'v'. unpack_id($secondfield);
    }
    echo "\n<script language=\"JavaScript\"  type=\"text/javascript\">
  <!--
    function add_to_select(selectbox, inputbox) {
                  value = inputbox.value;
                  length = selectbox.length;
                  if (value.length != 0) {
                    if ((length == 1) && (selectbox.options[0].value=='wIdThTor') ){\n";

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

  echo "<tr align=\"left\"><td class=\"tabtxt\"><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=\"left\">";
  echo "<td align=\"left\">

        <table>
        <tr><td><input type=\"text\" name=\"foo_$name\" size=\"$input_size\" maxlength=\"$input_maxsize\" value=\"$val\"></td>
        <td align=\"center\"><input type=\"button\" name=\"".$name."_add\" value=\"  Add  \" ".
        " onclick=\"add_to_select(document.inputform['".$name."[]'], foo_$name)\"></td></tr>
        <tr align=\"left\"><td rowspan=\"3\"><select name=\"".$name."[]\" multiple width=\"$input_size\" size=\"$select_size\">\n";

    if (is_array($arr)) {
        reset($arr);
        $i=0;
        while (list($k, $v) = each($arr)) {
            $i++;
            echo "\n  <option value=\"". htmlspecialchars($usevalue ? $v : $k)."\"";
            if ((string)$val == (string)(($usevalue OR $secondfield) ? $v : $k)) {
                echo ' selected class="sel_on"';
            }
            echo "> ";
            if ($numbered ==1) {
                echo htmlspecialchars($i.". ".$v);
            } else {
                echo htmlspecialchars($v);
            }
            echo " </option>";
        }
        reset($arr);
    } else {
      echo "\n   value=\"wIdThTor\"> ";
          for ($i=0; $i<$select_size*3; $i++) {
            echo "&nbsp; ";
          }
          echo "</option>";
    }

  echo "</select></td>
        <td align=\"center\"><input type=\"button\" name=\"".$name."_up\" value=\" /\ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'],'up');\"></td></tr>
        <tr><td align=\"center\"><input type=\"button\"  name=\"".$name."_remove\" value=\" "._m("Remove")."\" ".
                 " onclick = \"remove_selected(document.inputform['".$name."[]']);\"></td></tr>
        <tr><td align=\"center\"><input type=\"button\" name=\"".$name."_down\" value=\" \/ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'], 'down');\"></td></tr>
        </table>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

/** getRadioButtonTag function
 *  Used in FrmInputRadio
 * @param $k
 * @param $v
 * @param $name
 * @param $selected
 */
function getRadioButtonTag(&$k, &$v, &$name, &$selected) {
    $ret = "<input type=\"radio\" name=\"$name\"
                 value=\"". htmlspecialchars($k) ."\"".getTriggers("input",$name);
    if ((string)$selected == (string)$k) {
     $ret .= " checked";
    }
    $ret .= ">".htmlspecialchars($v);
    return $ret;
}

/** FrmSelectEasy function
 * Prints html tag <select ..
 * @param $name
 * @param $arr
 * @param $selected
 * @param $add
 */
function FrmSelectEasy($name, $arr, $selected="", $add="") {
    echo FrmSelectEasyCode($name, $arr, $selected, $add);
}
/** FrmSelectEasyCode function
 * @param $name
 * @param $arr
 * @param $selected
 * @param $add
 */
function FrmSelectEasyCode($name, $arr, $selected="", $add="") {
    $name=safe($name); // safe($add) - NO! - do not safe it

    $retval       = "\n<select name=\"$name\" $add>\n";
    $selectedused = false;
    foreach ($arr as $k => $v) {
        $retval .= "\n  <option value=\"". htmlspecialchars($k)."\"";
        if ((string)$selected == (string)$k) {
            $retval .= ' selected class="sel_on"';
            $selectedused = true;
        }
        $retval .= ">". htmlspecialchars( is_array($v) ? $v['name'] : $v ) ."</option>\n";
    }

    // now add all values, which is not in the array, but field has this value
    if ($selected AND !$selectedused) {
        $retval .= "\n  <option value=\"". htmlspecialchars($selected) ."\" selected class=\"sel_missing\">".htmlspecialchars($selected)."</option>";
    }

    $retval .= "</select>\n";
    return $retval;
}
/** FrmRadioEasy function
 * @param $name
 * @param $arr
 * @param $selected
 * @param $new_line
 */
function FrmRadioEasy($name, $arr, $selected="", $new_line=false) {
    $name=safe($name); // safe($add) - NO! - do not safe it

    foreach ($arr as $k => $v) {
        $retval .= "<input type=\"radio\" name=\"$name\" value=\"". htmlspecialchars($k)."\"";
        if (!$selected) {
            $selected = $k;
        }
        if ((string)$selected == (string)$k) {
            $retval .= " selected";
        }
        $retval .= "> ". htmlspecialchars( is_array($v) ? $v['name'] : $v );
        if ($new_line) {
            $retval .= "<br>";
        }
        $retval .= "\n";
    }
    echo $retval;
}

/** FrmTabCaption function
 * Prints start of form table with caption and possibly additional tags (classes) to tables
 * @param $caption
 * @param $outer_add
 * @param $inner_add
 * @param $buttons
 * @param $sess
 * @param $slice_id
 * @param $valign
 */
function FrmTabCaption( $caption='', $outer_add='', $inner_add='', $buttons='', $sess='', $slice_id='', $valign='middle') {
    echo '
    <table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\" $outer_add>";
    if ($buttons) {
        echo '
        <tr><td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'. COLOR_TABBG .'">
          <tr>';
          FrmInputButtons($buttons, $sess, $slice_id, $valign, false, COLOR_TABBG, false, 'top');
        echo '</tr></table></td></tr>';
    }
    if ($caption != "") {
      echo "
        <tr><td class=\"tabtit\"><b>&nbsp;$caption</b></td></tr>";
    }
     echo "
      <tr>
        <td>
          <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\" $inner_add>";
}

/** FrmTabSeparator function
 * Prints middle row with subtitle into form table
 * @param $subtitle
 * @param $buttons
 * @param $sess
 * @param $slice_id
 * @param $valign
 * @param $no_hidden - prints all $buttons except the hidden fields
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
        echo "<tr><td bgcolor=". COLOR_TABBG." hegiht=\"6\"></td></tr>";
    }
    if ( $subtitle ) {
        echo "\n      <tr><td class=\"tabtit\"><b>&nbsp;$subtitle</b></td></tr>";
    }
    echo '
      <tr>
        <td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'. COLOR_TABBG .'">';
}


/** FrmTabSeparatorNoHidden function
 * Prints middle row with subtitle into form table
 * @param $subtitle
 * @param $buttons
 */
function FrmTabSeparatorNoHidden( $subtitle , $buttons='' ) {
    FrmTabSeparator( $subtitle , $buttons, '', '', 'middle', true);
}


/** FrmTabEnd function
 * Prints form table end with buttons (@see FrmInputButtons)
 * @param $buttons
 * @param $sess
 * @param $slice_id
 * @param $valign
 */
function FrmTabEnd( $buttons=false, $sess='', $slice_id='', $valign='middle' ) {
    echo '    </table>
            </td>
          </tr>';
    if ( $buttons ) {
        FrmInputButtons($buttons, $sess, $slice_id, $valign, false, COLOR_TABTITBG, false, 'bottom');
    }
    echo '
        </table>';
}


/** FrmInputButtons function
 * Prints buttons based on $buttons array. It also adds slice_id and session id
 * Maybe better is to use (@see FrmTabEnd())
 * @param $buttons
 * @param $sess
 * @param $slice_id
 * @param $valign
 * @param $tr
 * @param $bgcolor
 * @param $no_hidden - prints all $buttons except the hidden fields
 * @param $prefix    - prefix for button ids
 */
function FrmInputButtons( $buttons, $sess='', $slice_id='', $valign='middle', $tr=true, $bgcolor=COLOR_TABBG, $no_hidden=false, $prefix='') {
    global $BName, $BVersion, $BPlatform;

    if ($tr) {
        echo '<tr class="formbuttons">';
    }
    echo '<td align="center" valign="'.$valign.'" bgcolor='.$bgcolor. '>';
    if ( isset($buttons) AND is_array($buttons) ) {
        // preparison: is the accesskey working?
        detect_browser();
        if ($BPlatform == "Macintosh") {
            if ($BName == "MSIE" || ($BName == "Netscape" && $BVersion >= "6")) {
                $accesskey_pref = "CTRL";
            }
        } elseif ($BName == "MSIE" || ($BName == "Netscape" && $BVersion > "5") || ($BName == "Mozilla")) {
            $accesskey_pref = "ALT";
        }

        if ($prefix) {
            $prefix = $prefix. '_';
        }

        foreach ( $buttons as  $name => $properties ) {
            if ( !is_array($properties) ) {
                $name = $properties;
                $properties = array();
            }
            switch($name) {
                case 'update':
                    if ($properties['type'] == 'hidden') {
                        echo '&nbsp;<input type="hidden" name="update" id="'.$prefix .'update" value="'. get_if($properties['value'], "") .'">&nbsp;';
                    } else {
                        echo '&nbsp;<input type="submit" name="update" id="'.$prefix .'update" accesskey="S" value=" '. get_if($properties['value'], _m("Update")) ." ($accesskey_pref+S) " .' ">&nbsp;';
                        $noaccess = 1; // use for update of item, bug was, that both "update" and "insert"
                        // has accesskey S
                    }
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'insert':
                    echo '&nbsp;<input type="submit" name="insert" id="'.$prefix .'insert" ';
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
                    if ($slice_id) {
                        $url = con_url($url, 'slice_id='.$slice_id);
                    }
                    if (!$properties['url']) {
                        $url = con_url($url,'cancel=1');
                    }
                    if ($sess) {
                        $url  = $sess->url($url);
                    }
                    //          echo '&nbsp;<input type="button" name="cancel" value=" '. get_if($properties['value'], _m("Cancel")) .' ">&nbsp;';
                    echo '&nbsp;<input type="button" name="cancel" id="'.$prefix .'cancel" value=" '. get_if($properties['value'], _m("Cancel")) .' " onclick="document.location=\''.$url.'\'">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'reset':
                    echo '&nbsp;<input type="reset" id="'.$prefix .'reset" value=" '. _m("Reset form") .' ">&nbsp;';
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
                    break;
                case 'submit':
                    echo '&nbsp;<input type="submit" name="submit" id="'.$prefix .'submit" accesskey="S" value=" '. get_if($properties['value'], _m("Submit")) ."  ($accesskey_pref+S) ". ' ">&nbsp;';
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
                    $nbsp = ( $type == 'hidden' ) ? '' : '&nbsp;';
                    echo $nbsp.'<input type="'.  $type .
                         '" name="'.  $name .
                         '" id="'.  $prefix . $name .
                         '" value="'. $properties['value'] . ($properties['accesskey'] ? "  (".$accesskey_pref."+".$properties['accesskey'].")  " : "").
                         '" '.($properties['accesskey'] ? 'accesskey="'.$properties['accesskey'].'" ' : ""). $properties['add'] . '>'.$nbsp;
                    if ($properties['help'] != '') {
                        echo FrmMoreHelp($properties['help']);
                        echo "&nbsp;&nbsp;";
                    }
            }
            echo "\n";
        }
    }

    if ( $sess ) {
        $sess->hidden_session();
    }
    if ( $slice_id ) {
        echo '<input type="hidden" name="slice_id" value="'. $slice_id .'">';
    }

    echo "</td>";
    if ($tr) {
        echo "</tr>";
    }
}

/** getFrmTabRow function
 * @param $row
 */
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

/** FrmTabRow function
 * Prints table row with calls defined in array
 * @param $row
 */
function FrmTabRow( $row ) {
    echo getFrmTabRow( $row );
}

/** getFrmTabs function
 * Prints TAB widget
 * @param $tabs
 * @param $tabsId
 */
function getFrmTabs( $tabs, $tabsId ) {
    if ( isset($tabs) AND is_array($tabs) ) {
        $ret = "\n <tr id=\"$tabsId\"><td colspan=\"2\" class=\"tabsrow\">";
        $non = '';
        foreach ( $tabs as $class => $name ) {
            $ret .= "<a href=\"javascript:TabWidgetToggle('$class')\" id=\"${tabsId}${class}\" class=\"tabs${non}activ\">$name</a>";
            $non = 'non';
        }
        $ret .= "</td></tr>";
    }
    return $ret;
}
/** FrmTabs function
 * @param $tabs
 * @param $tabsId
 */
function FrmTabs( $tabs, $tabsId ) {
    echo getFrmTabs( $tabs, $tabsId );
}


/** GetHtmlTable function
 * Returns table based on config array
 * @param $content
 */
function GetHtmlTable( $content ) {
    if ( !(isset($content) AND is_array($content)) ) {
        return "";
    }
    $ret = '<table width="100%" border="0" cellspacing="0" cellpadding="" bgcolor="'. COLOR_TABBG .'">';
    foreach ($content as $row) {
        $ret .= getFrmTabRow( $row );
    }
    return  $ret . '</table>';
}

/** getFrmJavascriptFile function
 * @param $src
 */
function getFrmJavascriptFile( $src ) {
    return "\n <script language=\"JavaScript\" type=\"text/javascript\" src=\"". get_aa_url($src,false) . "\"></script>";
}
/** getFrmJavascript function
 * @param $code
 */
function getFrmJavascript( $jscode ) {
    return '
    <script language="JavaScript" type="text/javascript"> <!--
      '.$jscode.'
      //-->
    </script>
    ';
}


/** getFrmJavascriptCached function
 *  Stores the javascript to the dababase cache in order we can call this
 *  javascript as external file.
 *  The idea of this is: External js files are cached by the browser so it is
 *  better to store the js code in the database, assign an ID to this record
 *  (=keystr) and then call it as external file with this ID as parameter
 * @param $jscode
 * @param $name
 */
function getFrmJavascriptCached( $jscode, $name ) {
    global $pagecache;
    $keystr = serialize($jscode);
    $keyid  = $pagecache->getKeyId($keystr);

    if (!$pagecache->getById($keyid)) {     // not in cache, yet
        $str2find = new CacheStr2find($name, 'js');
        $pagecache->store($keystr, $jscode, $str2find, true);
    }
    return getFrmJavascriptFile( 'cached.php3?keystr='.$keyid );
}

/** getFrmCSS function
 * @param $stylecode
 */
function getFrmCSS( $stylecode ) {
    return '
    <style type="text/css">  <!--
      '.$stylecode.'
      //-->
    </style>
    ';
}
/** FrmJavascript function
 * @param $jscode
 */
function FrmJavascript( $jscode ) {
    echo getFrmJavascript( $jscode );
}
/** FrmJavascriptFile function
 * @param $src
 */
function FrmJavascriptFile( $src ) {
    echo getFrmJavascriptFile( $src );
}
/** FrmJavascriptCached function
 * @param $jscode
 * @param $name
 */
function FrmJavascriptCached( $jscode, $name ) {
    echo getFrmJavascriptCached( $jscode, $name );
}
/** FrmCSS function
 * @param $stylecode
 */
function FrmCSS( $stylecode ) {
    echo getFrmCSS( $stylecode );
}

/** IncludeManagerJavascript function
 */
function IncludeManagerJavascript() {
    global $sess;
    FrmJavascriptFile( 'javascript/aajslib.php3?sess_name='.$sess->classname .'&sess_id='.$sess->id );
    FrmJavascriptFile( 'javascript/manager.js' );
}

/** getRadioBookmarkRow function
 *  returns one row with one radiobutton - asociated to bookmark (stored search)
 *  or item list
 * @param $name  - dislpayed name of this option
 * @param $value - value for this option
 * @param $list_type - items preview type ('items' | 'users') @see usershow.php3
 * @param $list_text
 * @param $safe - escape html entities in name?
 * @param $bookmark
 */
function getRadioBookmarkRow( $name, $value, $list_type, $list_text, $safe=true, $bookmark_id=null) {
    global $slice_id, $items;

    static $checked = ' checked';  // mark first option when no $group selected

    if ( isset( $GLOBALS['group'] ) ) {
        $checked = (((string)$GLOBALS['group'] == (string)$value) ? ' checked' : '');
    }

    if ( $safe ) {
        $name = safe($name);
    }
    $out .= "
    <tr>
      <td align=\"center\"><input type=\"radio\" name=\"group\" value=\"$value\" $checked></td>";

    $out .= ((string)$value == (string)"testuser") ? "<td colspan=\"6\">" : "<td>";

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
            $out .= "<td colspan=\"4\"></td>";
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

/** FrmItemGroupSelect function
 *   Allows select items group (used for bulk e-mails as well as for Find&Replace)
 *  @param $items
 *  @param $searchbar
 *  @param list_type - items preview type ('items' | 'users') @see usershow.php3
 *  @param messages['view_items']     = _m("View Recipients")
 *  @param messages['selected_items'] = _m('Selected users')
 *  @param additional[] = array( 'text' => 'Test', 'varname'=>'testuser');
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
        $out .= getRadioBookmarkRow( _m('All active items'),         'AA_BIN_ACTIVE', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( _m('All items'),                'AA_ALL', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( _m('All pending items'),        'AA_BIN_PENDING', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( _m('All expired items'),        'AA_BIN_EXPIRED', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( _m('All items in holding bin'), 'AA_BIN_HOLDING', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( _m('All items in trash bin'),   'AA_BIN_TRASH', $list_type, $messages['view_items']);
    }
    // aditional group (test one, for examle)
    if ( isset($additional) AND is_array($additional) ) {
        foreach ( $additional as $row ) {
            $out .= getRadioBookmarkRow( $row['text'], $row['varname'], $list_type, $messages['view_items'], false);
        }
    }
    echo $out;
}

/** getZidsFromGroupSelect function
 *  Returns zids according to user selection of FrmItemGroupSelect
 * @param $group
 * @param $items
 * @param $serchbar
 */
function getZidsFromGroupSelect($group, &$items, &$searchbar) {
    global $slice_id;
    if ( $group == 'sel_item' ) {  // user specified users
        $zids = new zids(null, 'l');
        $zids->setFromItemArr($items);
    } else {                   // user defined by bookmark
        switch ($group) {
            case 'AA_ALL':         $conds = false; $bins = AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH;  break;
            case 'AA_BIN_PENDING': $conds = false; $bins = AA_BIN_PENDING; break;
            case 'AA_BIN_EXPIRED': $conds = false; $bins = AA_BIN_EXPIRED; break;
            case 'AA_BIN_ACTIVE':  $conds = false; $bins = AA_BIN_ACTIVE;  break;
            case 'AA_BIN_HOLDING': $conds = false; $bins = AA_BIN_HOLDING; break;
            case 'AA_BIN_TRASH':   $conds = false; $bins = AA_BIN_TRASH;   break;
            case 'AA_BIN_ACTIVE':
            case '':               $conds = false; $bins = AA_BIN_ACTIVE;  break;
            default:
                $searchbar->setFromBookmark($group);
                $conds = $searchbar->getConds();
                $bins  = AA_BIN_ACTIVE;
        }
        $zids  = QueryZIDs( array($slice_id), $conds, '',  $bins);
    }
    return $zids;
}

/** FrmItemListForm function
 *  Lists selected items to special form - used by manager.js to show items
 * @param $items
 */
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
/** getSelectWithParam function
 * @param $name
 * @param $arr
 * @param $selected
 * @param $html_setting
 */
function getSelectWithParam($name, $arr, $selected="", $html_setting=null) {
    $add = "onchange=\"ShowThisTagClass('fswp$name', 'div', 'fswp_'+sb_GetSelectedValue(this), 'fswp_')\"";
    $ret = FrmSelectEasyCode($name, $arr, $selected, $add);
    if (isset($html_setting) AND is_array($html_setting)) {
        $ret .= "\n<div id=\"fswp$name\">";
        foreach($html_setting as $value => $html) {
            $ret .= "\n  <div class=\"fswp_$value\" style=\"display:none\">$html</div>";
        }
        $ret .= "\n</div>";
    }
    return $ret;
}

/** PrintAliasHelp function
 *  Prints alias names as help for fulltext and compact format page
 * @param $aliases
 * @param $fields
 * @param $endtable
 * @param $buttons
 * @param $sess
 * @param $slice_id
 */
function PrintAliasHelp($aliases, $fields=false, $endtable=true, $buttons='', $sess='', $slice_id='') {
  global $sess;

  FrmTabSeparator(_m("Use these aliases for database fields") , $buttons, $sess, $slice_id);

//  echo '
//  <tr><td class=tabtit><b>&nbsp;'._m("Use these aliases for database fields").'</b></td></tr>
//  <tr><td>
//  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'.COLOR_TABBG.'">';

  $count = 0;
  while ( list( $ali,$v ) = each( $aliases ) ) {
    // if it is possible point to alias editing page
    $aliasedit = ( !$v["fld"] ? "&nbsp;" :
      "<a href=\"". $sess->url(con_url("./se_inputform.php3",
                    "fid=".urlencode($v["fld"]))) ."\">". _m("Edit") . "</a>");
    if ($fields AND $fields[$v["fld"]] AND !$fields[$v["fld"]]['input_show'])
        $ali = "<span class=\"disabled\">$ali</span>";
    echo "<tr><td nowrap>$ali</td><td>". $v['hlp'] ."</td><td>$aliasedit</td></tr>";
  }

  if ($endtable) {
   echo '
    </table></td></tr>';
  }
}

?>
