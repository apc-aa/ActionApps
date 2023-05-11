<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
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

use AA\Cache\CacheStr2find;
use AA\Util\Langs;
use AA\Util\JsTriggers;
use AA\Widget\Widget;


/**
* Form utility functions
*/

require_once __DIR__ . "/constedit_util.php";
require_once __DIR__ . "/itemfunc.php";
require_once __DIR__ . "/stringexpand.php";
require_once __DIR__ . "/validate.php";

// IsUserNameFree() function definition here
require_once __DIR__."/perm_" . PERM_LIB . ".php";

const AA_WIDTHTOR       = '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>';
const AA_WIDTHTOR_SHORT = '<option value="wIdThTor">&nbsp;</option>';

// Deprecated GYR tagged ids - it adds letter before item id in related item, which is not practical for joined tables, ...
    // Easy to redefine this functionality by changing the array below
    // prefix is what goes in the selection box in "Edit Item",
    // tag is what goes on the front of the id as stored in the database
    // str is the string to display in the Related Items window
    // Note that A M B are hard-coded in the Related Items Window param wizard,
    // but any letters can be used, i.e. this table can be extended.
    // Next step might be to extend parameter recognition to load this table
    // Retaining backward compatibility with "[AMB]+" recognition
const TPS = [
  'AMB' => [
    'A' => ['prefix' => '&rarr; ',   'tag' => '',  'str' => 'Add'],             // _m("Add") ),             - we call the translation later - here it is not translated
    'M' => ['prefix' => '&harr; ',   'tag' => 'y', 'str' => 'Add&nbsp;Mutual'], // _m("Add&nbsp;Mutual") ),
    'B' => ['prefix' => '&larr; ',   'tag' => 'z', 'str' => 'Backward']
  ],      // _m("Backward") ) ),
  'GYR' => [
    'G' => ['prefix' => 'Good:', 'tag' => 'x', 'str' => 'Good'],
    'Y' => ['prefix' => 'OK  :', 'tag' => 'y', 'str' => 'OK'],
    'R' => ['prefix' => 'Bad :', 'tag' => 'z', 'str' => 'Bad']
  ]
];

/** varname4form function
 * @param $fid
 * @param $type
 * @return string
 */
function varname4form($fid, $type='normal') {
    $additions = ['normal' => '', 'multi' => '[]', 'file' => 'x'];
    return $fid ? ('v'. unpack_id($fid) .$additions[$type]) : '';
}

/** GetInputFormTemplate function
 *  Returns inputform template
 */
function GetInputFormTemplate() {
     global $slice_id;
     $slice = AA_Slice::getModule($slice_id);
     //           inputform($inputform_settings);
     $form  = new inputform();
     // ItemContent in getForm passed by reference
     return $form->getForm(new ItemContent(), $slice);
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

    var $template;        // if you want to display alternate form design,
                          // template holds view_id of such template
                          // view type 'inputform'
    var $formheading;     // add extra form heading here (default none, used by MLX)
    var $hidden;          //
    var $msg;             // stores return code from functions

    // computed values form form fields
    var $show_func_used;    // used show functions in the input form
    var $js_proove_fields;  // javascript form validation code


    /** inputform function
     *  Constructor - initializes inputform
     * @param $settings
     */
    function __construct($settings= []) {
        $this->display_aa_begin_end = $settings['display_aa_begin_end'];
        $this->page_title           = $settings['page_title'];
        $this->form_action          = $settings['form_action'];
        $this->form4update          = $settings['form4update'];
        $this->show_preview_button  = $settings['show_preview_button'];
        $this->cancel_url           = $settings['cancel_url'];
        $this->messages             = $settings['messages'];
        $this->template             = $settings['template'];
        $this->formheading          = $settings['formheading']; //aded for MLX
        $this->hidden               = $settings['hidden'];      // array of hidden fields to be added to the form
    }

    /** static */
    function _getButton($key, $type, $default_text, $profile_text, $url = null) {
        return ($profile_text === '') ? [] : [$key => ['type'=>$type, 'value'=> get_if($profile_text, $default_text), 'url' => $url]];
    }


    /**
     * @param ItemContent $content4id
     * @param AA_Slice    $slice
     * @param bool        $edit
     * @return mixed
     */
    function dryEvaluate($content4id, $slice, $edit) {
        $form = $this->getForm($content4id, $slice, $edit);
        $out  = AA::Stringexpander()->unalias($form, '', GetItemFromContent($content4id));
        return  AA::Stringexpander()->postprocess($out); // to replace  {generate:HEAD} with dynamic javascripts needed by inputform
    }

    /** generateForm - generates the form html for printing
     * @param $content4id
     * @param AA_Slice $slice
     * @param bool $edit    - edit form or input form (form for new item or existing one)
     * @param $slice_fields - true, if we want to edit "slice setting fields"
     *                         which are stored in content table
     */
    function printForm($content4id, $slice, $edit, $slice_fields=false) {
        // Get the default form and FILL CONTENTCACHE with fields
        // This function also fills the $this->show_func_used and
        // $this->js_proove_fields
        $form          = $this->getForm($content4id, $slice, $edit, '', $slice_fields);
        $remove_string = '';

        // design of form could be customized by view
        if ( $this->template AND ($view = AA_Views::getViewNumeric($this->template))) {
            // we can use different forms for 'EDIT' and 'ADD' item => (even/odd)
            $form          = ($edit AND $view->f('even_odd_differ')) ? $view->f('even') : $view->f('odd');
            $remove_string = $view->f('remove_string');
        }

        $slice_id = $slice->getId();
        $profile  = AA_Profile::getProfile(AuthGetUid(), $slice_id); // current user settings

        // create buttons array for top (and lately for bottom of the form) ------------------
        $buttons = [];
        if ( $this->form4update ) {
            $buttons += inputform::_getButton('update',   'submit', _m("Update"), $profile->getProperty('ui_inputform', 'edit_btn_update'));
            $buttons += inputform::_getButton('upd_edit', 'submit', _m("Update & Edit"), $profile->getProperty('ui_inputform', 'edit_btn_upd_edit'));
            if ( $this->show_preview_button ) {
                $buttons += inputform::_getButton('upd_preview', 'submit', _m("Update & View"), $profile->getProperty('ui_inputform', 'edit_btn_upd_preview'));
            }
            // if we edit dynamic slice setting fields, we do not need such buttons
            if (!$slice_fields) {
                $fields = $slice->getFields($slice_fields);
                if (!array_find_fnc($fields, function ($f) { return $f->disable_insert_as_new(); })) {
                    $buttons += inputform::_getButton('insert_new', 'submit', _m("Insert as new"), $profile->getProperty('ui_inputform', 'edit_btn_insert'));
                }
                if (strlen($profile->getProperty('ui_inputform', 'edit_btn_reset')) > 0) {
                    // reset button display just if it is defined in profile
                    $buttons += inputform::_getButton('reset', 'reset', _m("Reset form"), $profile->getProperty('ui_inputform', 'edit_btn_reset'));
                }
            }
            $buttons += inputform::_getButton('cancel', 'button', _m("Cancel"), $profile->getProperty('ui_inputform', 'edit_btn_cancel'), $this->cancel_url);
        } else {
            $buttons += inputform::_getButton('insert',      'submit', _m("Insert"), $profile->getProperty('ui_inputform', 'add_btn_insert'));
            $buttons += inputform::_getButton('ins_edit',    'submit', _m("Insert & Edit"), $profile->getProperty('ui_inputform', 'add_btn_ins_edit'));
            $buttons += inputform::_getButton('ins_preview', 'submit', _m("Insert & View"), $profile->getProperty('ui_inputform', 'add_btn_ins_preview'));
            $buttons += inputform::_getButton('cancel', 'button', _m("Cancel"), $profile->getProperty('ui_inputform', 'add_btn_cancel'), $this->cancel_url);
        }
        // /buttons  ------------------

        $html_end   = '';
        $html_start = '';
        if ( $this->display_aa_begin_end ) {
            $page_title = get_if($profile->getProperty('ui_inputform', $this->form4update ? 'edit_title' : 'add_title'), $this->page_title);

            HtmlPageBegin(false, $slice->getLang());   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

            // adding to $form just for {generate:HEAD}. Honza 2017-06-13

            // get validation and gui javascripts for used fields
            // getFormJavascript must be called after getForm since
            // $this->show_func_used, $this->js_proove_fields must be already filled

            $jstriggers  = JsTriggers::singleton4id($slice_id);
            $html_start  = $this->getFormJavascript($jstriggers);
            $html_start .= '
                <title>'. $page_title .'</title>
                {generate:HEAD}
              </head>
              <body '. $jstriggers->get('body') .' class="aa-ui">';

            $html_start .= getMenu(''); // opens two divs - in all cases
            if ($page_title) {
                $html_start .= "\n<h1>$page_title</h1>";
            }

            // you can add some html code by user profiles, above the form
            $html_start .= get_if($profile->getProperty('ui_inputform', $this->form4update ? 'edit_tophtml' : 'add_tophtml'), '');

            $html_start .= GetPrintArray( $this->messages['err'] );     // prints err or OK messages

            $html_end = "</div></div>{generate:FOOT}</body></html>";
        }

        AA::Stringexpander()->addRequire("document.querySelectorAll('form').forEach(function(f) { f.addEventListener('submit', function() {sessionStorage.setItem('aa_pgtop', window.pageYOffset);});})", 'AA_Req_Load');
        AA::Stringexpander()->addRequire("if (sessionStorage.getItem('aa_pgtop')>0) { window.scrollTo(0,sessionStorage.getItem('aa_pgtop')); sessionStorage.setItem('aa_pgtop', 0); }", 'AA_Req_Load');

        // print the inputform
        $out        = AA::Stringexpander()->unalias($form, $remove_string, GetItemFromContent($content4id));
        $out        = AA::Stringexpander()->postprocess($out); // to replace  {generate:HEAD} with dynamic javascripts needed by inputform

        $form_parts      = $GLOBALS['g_formpart'];        // must be here - right after unalias
        $form_part_names = $GLOBALS['g_formpart_names'];  // must be here - right after unalias
        $form_part_pos   = $GLOBALS['g_formpart_pos'];    // must be here - right after unalias

        $html_start = AA::Stringexpander()->unalias($html_start);
        $html_start = AA::Stringexpander()->postprocess($html_start);
        $html_end   = AA::Stringexpander()->unalias($html_end);
        $html_end   = AA::Stringexpander()->postprocess($html_end);

        echo $html_start;

        echo $this->getFormStart();

        //echo getFrmTabCaption('', $buttons, 'id="inputtab"', 'id="inputtabrows"');
        echo getFrmTabCaption('', $buttons, 'id="inputtab"');
        $tabs  = [];
        if ( $form_parts ) {
            for ( $idx=0; $idx <= $form_parts; $idx++ ) {
                $tabs['formrow'.(string)$idx] = get_if( $form_part_names[$idx], _m('Part'). " ".($idx+1));
            }
            // print tabs for form switching
            if ( $form_part_pos & 1 ) {  // 1 ~ top
                FrmTabs( $tabs, 'formtabs' );
            }
        }

    //added for MLX
        if ($this->formheading) {// added for mlx tab
            echo $this->formheading;
        }

        echo $out;

        if ( $form_parts AND ($form_part_pos & 2)) {  // 2 ~ bottom
            // print tabs for form switching
            FrmTabs( $tabs, 'formtabs2' );
        }

        if (is_array($this->hidden)) {
            foreach ( $this->hidden as $name => $value) {
                $buttons[$name]         = ['value' => $value];
            }
        }

        // add rest "hidden buttons" to the end of form
        // $buttons['MAX_FILE_SIZE']       = array('value' => IMG_UPLOAD_MAX_SIZE );   // browsers do not use this variable - removed
        $buttons['encap']               = ['value' => "false"];

        //$buttons['vid']                 = array('value' => $vid);   // ?? $vid is not defined here ?? - @todo


        FrmTabEnd( $buttons);

        if ( $form_parts ) {
            FrmJavascript('document.getElementById("inputtabrows").style.display = \'\';
                           TabWidgetToggle(\'formrow0\');');
        }

        echo '</form>';

        // you can add some html code by user profiles, below the form
        echo get_if($profile->getProperty('ui_inputform', $this->form4update ? 'edit_bottomhtml' : 'add_bottomhtml'), '');

        AA::$debug&4 && AA::$dbg->duration_stat();

        echo $html_end;
    }

    /** getForm function
     *   Shows the Add / Edit item form fields
     * @param ItemContent $content4id - current item content in db
     * @param AA_Slice    $slice
     * @param bool        $edit  - edit form or input form (form for new item or existing one)
     * @param array       $show is used by the Anonymous Form Wizard, it is an array
     *                (packed field id => 1) of fields to show
     * @param             $slice_fields
     * @return string
     */
    function getForm($content4id, $slice, $edit=false, $show=null, $slice_fields=false) {
        $profile   = AA_Profile::getProfile(AuthGetUid(), $slice->getId()); // current user settings
        $fields = $slice->getFields($slice_fields);

        if ( !$fields->count() ) {
            return MsgErr(_m("No fields defined for this slice"));
        }
        $form4anonymous_wizard = is_array($show);

        // holds array of fields, which we will use on the form, so we have
        // to count with them for javascript and show_func_used
        $shown_fields = [];

        // $item = $edit ? GetItemFromContent($content4id) : null;
        $item = $content4id ? GetItemFromContent($content4id) : null;

        // just read the data from posted form, if it was already posted (on error, ...)
        $content_from_form = new ItemContent();
        $content_from_form->setFieldsFromForm($content4id->getContent(), !$edit, $fields, $profile);

        $ret = '';
        $fids = $fields->getPriorityArray();
        foreach ($fids as $fid) {
            $field = $fields->getField($fid);

            if ( ($form4anonymous_wizard  AND !$show[$fid]) OR
                 (!$form4anonymous_wizard AND (!$field->getProperty('input_show') OR
                                         $profile->getProperty('hide',$fid) OR
                                         $profile->getProperty('hide&fill',$fid)))) {
                // do not show this field
                continue;
            }

            $shown_fields[$fid] = true;  // used => generate js for it

            // ----- collect all field_* parameters in order we can call display function

            // field_mode - how to display the field
            $field_mode  = !IsEditable($content4id->getValues($fid), $field, $profile) ? 'freeze' : ($form4anonymous_wizard ? 'anonym' : 'normal');

            if ( $edit ) {
                $fval = $content4id->getAaValue($fid);
            } else {     // insert or new reload of form after error in inserting
                // first get values from profile or field setting, if there are some predefined value
                $fval = $field->getDefault(true);   // cont with profile

                // get values from form (values are filled when error on form occurs
                if ($content_from_form->isSet($fid)) {
                    $fval = $content_from_form->getAaValue($fid);
                }
            }            // Display the field
            $aainput = new AA_Inputfield('', true, $field_mode); // set empty AA_Inputfield
            $aainput->setAaValue($fval);

            //fix -- otherwise $field_value keeps array
            unset($fval);
            $aainput->setFromField($field);

            // do not return template for anonymous form wizard
            $ret .= $aainput->get($item, $slice->getId());
            unset($aainput);
        }
        // no longer used - we use standard HTML5 validation, now;    get_js_validation() already removed 2021-01-26 HM
        // $this->js_proove_fields = $slice->get_js_validation( $edit ? 'edit' : '', $content4id->getItemID(), $shown_fields, $slice_fields);
        // we need to know, if the file upload is used to use multipart form encoding
        if (!is_array($this->show_func_used)) {       // not already computed
            $this->show_func_used = $slice->get_show_func_used($content4id->getItemID(), $shown_fields, $slice_fields);
        }
        return $ret;
    }

    /** getFormJavascript
     *  Get validation, triggers and gui javascript for used fields on Add/Edit Form
     *  Must be called after getForm since $this->show_func_used and
     *  $this->js_proove_fields must be already filled
     * @param JsTriggers $jstriggers
     * @return string
     */
    function getFormJavascript(JsTriggers $jsTriggers): string {
        $retval = '';

        AA::Stringexpander()->addRequire(get_aa_url('javascript/inputform.min.js?v='.AA_JS_VERSION,'',false));
        AA::Stringexpander()->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION,'',false));

        // $retval .= getFrmJavascriptFile( 'javascript/inputform.min.js?v=1' );
        // $retval .= getFrmJavascriptFile( 'javascript/js_lib.min.js' );
        // $retval .= getFrmJavascriptFile( 'javascript/aajslib.php' );

        $jscode = $this->js_proove_fields;

        // field javascript feature - triggers see JsTriggers class
        $jscode .= $jsTriggers->getJavascriptAndRegisterRequires();

        $retval .= getFrmJavascript( $jscode );
        $retval .= getFrmJavascriptFile('misc/htmleditor/ckeditor.js');

        // AA::Stringexpander()->addRequire(get_aa_url('javascript/constedit.min.js?v='.AA_JS_VERSION,'',false));  // no longer needed - managed by {require:...} in hco

        return $retval;
    }

    /** getFormStart function
     *  Get form tag with right enctype and triggers
     *  Must be called after getForm since $this->show_func_used and
     *  $this->js_proove_fields must be already filled
     */
    function getFormStart() {
        // uses fileupload?
        $html_form_type = $this->show_func_used['fil'] ? 'enctype="multipart/form-data"' : '';
        return "<form name=\"inputform\" $html_form_type method=\"post\" action=\"" . $this->form_action .'"'.
            JsTriggers::getFromGlobalSliceId("form", "v" . unpack_id("inputform"), ["onSubmit" => "return BeforeSubmit()"]) .'>';
    }
} // inputform class

/** getAAField function
 *  Special constructor shortcut for AA_Inputfield class
 *  Returns new AA_Inputfield object with setting defined in array
 * @param array $settings
 * @return AA_Inputfield
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
    var $varname_modified; // modified name of the field in html form
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

    var $valid;            // string validation function used - could be 'number' or 'number:0:9999' - in order og getClassProperties of AA_Validate_Number
                           // (this function we added in order we can use this class for AA Admin forms as well)
    var $translations;     // field allows translation
    var $dbfield;          // contains name of database field. It is often
                           // empty, so we will use varname, but there are some
                           // cases, where we use different database field than
                           // the varname (for examlpe "name" is good database
                           // column name, but wrong for HTML form)

    // --- privates ---
    /** @var string $result Collects output of the field  */
    protected $result;           // result string (depends on result_mode if variables are as aliases or it is expanded)

    /** @var string $result_mode expand | template
     *                           expand   - input fields are normally printed;
     *                           template - field is printed as alias
     *                             {inputfield:...} and content is stored into contentcache
     */
    protected $result_mode;
    protected $value_modified;   // sometimes we have to modify value before output
    protected $selected;         // helper array used for quick search, if specified
                           // value is selected (mainly for multivalues)
    protected $const_arr;        // array of constants used for selections (selectbox, radio, ...)
    protected $msg;                         // stores return code from functions

    protected $use_triggers_4_slice = 0;  // shall the input contain aa_onXxxx() triggers? used for item-editing widgets

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
    function __construct($value='', $html_flag=true, $mode='normal', $varname="", $name="", $add=false, $required=false, $hlp="", $morehlp="", $arr=null) {
        $this->clear();
        $settings = [
            'value'         => is_array($value) ? $value : [0=> ['value'=>$value]],
                           'html_flag'     => $html_flag,
                           'mode'          => $mode,
                           'varname'       => $varname,
                           'name'          => $name,
                           'required'      => $required,
                           'input_help'    => $hlp,
                           'input_morehlp' => $morehlp,
                           'additional'    => $add,
                           'const_arr'     => $arr
        ];
        $this->setFromArray($settings);
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
        $this->value = [0=> ['value'=>$value]];
    }

    /**
     * @param AA_Value $val
     */
    function setAaValue($val) {
        $arr         = $val->getValues();
        $this->value = [];
        foreach ($arr as $k => $v) {
             $this->value[$k] = ['value'=>$v];
        }
        $this->html_flag = $val->getFlag() & FLAG_HTML;
    }

    /**
     * @return AA_Value
     */
    function getAaValue() {
        return new AA_Value($this->value, $this->html_flag);
    }

    /**
     * @param AA_Validate $validator
     * @return AA_Inputfield
     */
    public function setValidator(AA_Validate $valid): AA_Inputfield {
        $this->valid = $valid;
        return $this;
    }

    /** getDefaults function
     * private function - Returns list of class variables and its defaults
     */
    function getDefaults() {
        return [
            'value'         =>  [0 => ['value' => '']],
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
        ];
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
     * @param AA_Field $field_arr
     */
    function setFromField($field) {
        $field_arr = $field->getRecord();

        $this->id            = $field_arr['id'];
        $this->varname       = varname4form($this->id);
        $this->name          = $field_arr['name'];
        $this->input_before  = $field_arr['input_before'];
        $this->required      = $field_arr['required'];
        $this->input_help    = $field_arr['input_help'];
        $this->input_morehlp = $field_arr['input_morehlp'];

        //$funct = ParamExplode($field["input_show_func"]);
        //$this->input_type    = AA::Stringexpander()->unalias($funct[0]);
        $funct = array_values(Widget::parseClassProperties($field_arr["input_show_func"]));
        $this->input_type    = substr(strtolower($funct[0]),-9,3);    // grab from AA\Widget\FldWidget
        $this->param         = array_slice( $funct, 1 );
        $this->html_rb_show  = $field_arr["html_show"];
        $this->valid         = $field_arr["input_validate"];

        if ($field_arr["multiple"] & 2) {   // translation
            $this->translations = AA_Slice::getModule(unpack_id($field_arr["slice_id"]))->getTranslations();
        }
        // removed - const_arr should be in input_show_func param
        // if ( isset($field["const_arr"]) ) {
        //     $this->const_arr  = $field["const_arr"];
        // }
    }

    /** Validates $value is is 'valid'
     * @param $value
     * @param $err
     * @return bool
     */
    function validate($value, &$err) {
        return ValidateInput($this->varname, $this->name, $value, $err, $this->required, $this->valid);
    }

    // private methods - helper - data manipulation

    /** implodeVal function
     * Joins all values to one long string separated by $delim
     * @param $delim
     * @return string
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
     * @param string $slice_field
     * @param bool   $conds
     * @param bool   $sort
     * @param int    $whichitems
     * @param bool   $ids_arr
     * @param null   $crypted_additional_slice_pwd
     * @param        $tagprefix
     * @param null   $const_arr_param
     * @return string - unpacked slice_id if array is filled from slice
     * (not so important value, isn't?)
     */
    function fill_const_arr($slice_field="", $conds=false, $sort=false, $whichitems=AA_BIN_ACT_PEND, $ids_arr=null, $crypted_additional_slice_pwd=null, $tagprefix=null, $const_arr_param=null) {
        $constgroup = $this->param[0];
        $sid        = (substr($constgroup,0,7) == "#sLiCe-") ? substr($constgroup, 7) : '';

        if ( isset($this->const_arr) AND is_array($this->const_arr) ) {  // already filled
            return $sid;
        }

        if ($const_arr_param) {
            $this->const_arr = $const_arr_param;
            return $sid;
        }

        if ($sid) {
            // Get format for which represents the id
            // Could be field_id (then it is grabbed from item and truncated to 50
            // characters, or normal AA format string.
            // Headline is default (if empty "$slice_field" is passed)
            if (!$slice_field) {
                $slice_field = GetHeadlineFieldID($sid, "headline.");
                if (!$slice_field) {
                    $slice_field = 'publish_date....';
                }
            }
            if ( $whichitems < 1 ) {
                $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
            }
            $format          = AA_Slice::getModule($sid)->getField($slice_field) ? '{substr:{index:'.$slice_field.'}:0:50}' : $slice_field;
            $zids            = is_null($ids_arr) ? false : new zids($ids_arr);  // transforms content array to zids
            $set             = new AA_Set($sid, $conds, $sort, $whichitems);
            $this->const_arr = GetFormattedItems( $set->query($zids), $format, $crypted_additional_slice_pwd, $tagprefix);
            // $this->const_arr = GetFormatedItems( $sid, $format, $zids, $whichitems, $conds, $sort, $tagprefix); // older version of the function :honzam03/09
        } elseif ($constgroup) {
            $this->const_arr = GetFormatedConstants($constgroup, $slice_field, $ids_arr, $conds, $sort);
        }

        if ( !isset($this->const_arr) OR !is_array($this->const_arr) ) {
            $this->const_arr = [];
        }
        return $sid; // in most cases not very important information, but used in inputRelation() input type
    }

    /** varname_modify function
     *  Modifies varname in case we need to display two (or more) inputs
     *  for one field (varname_modified is used instead of varname - if set).
     * @param $add
     * @return string
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
     * @return array
     */
    function prepareVars($valtype='first') {
        if (isset($this->value_modified)) {
            $val = $this->value_modified;
        } elseif ($valtype == 'first') {
            $val = $this->value[0]['value'];
        } else {
            $val = $this->value;
        }
        return [$this->varname(), $val, $this->additional];
    }

    /** echoo function
     *  Echo wrapper - prints output to string instead of to output
     * @param $txt
     */
    function echoo($txt) {
        $this->result .= $txt;
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
     * @param string $param
     * @param string $aliasname used for alias name different from 'inputvar'
     */
    function echovar($txt, $part='', $param='', $aliasname='') {
        if ( $this->result_mode == 'expand' ) {   // write directly to the output
            $this->echoo($txt);
        } else {
            if ($aliasname=='') {
                $aliasname='inputvar';
            };
            $alias = $aliasname.':'.$this->id. ($part  ? ":$part"  : ''). ($param ? ":$param" : '');
            AA::Contentcache()->set($alias, $txt);
            $this->echoo('{'.$alias.'}');
        }
    }

    /** if_selected function
     * returns $ret_val if given $option is selected for current field
     * @param $option
     * @param $ret_val
     * @return string
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
                    if ( strlen( (string)$v['value'] ) ) {
                        $this->selected[(string)$v['value']] = true;
                    }
                }
            }
        }
    }

    /** get function
     *  Returns field as it should be displayed on screen (or at least template
     *  for the field with filled $contentcache object
     * @param AA_Item $item
     * @param string  $slice_id
     */
    function get( $item, $slice_id ) {
        // @todo check, how to do it better - this do not work if "slice_field" parameter uses {subst...} for example
        AA::Stringexpander()->unaliasArray($this->param);
        $this->result_mode = 'template';
        $this->echoo($this->input_before);

        $this->use_triggers_4_slice = $slice_id;  // we are in item editing mode - add triggers

        if ($this->mode == 'freeze') {
            switch ($this->input_type) {
                case 'chb': $this->value_modified = $this->value[0]['value'] ? _m("set") : _m("unset");
                            $this->staticText();       break;
                case 'lup':     // Local URL Picker | Omar/Jaime | 11-06-2005
                case 'iso': $this->value_modified = $this->implodeVal('<br>');
                            $this->staticText();       break;
                case 'pwd': $this->value_modified = $this->value[0] ? "*****" : "";
                            $this->staticText();       break;
                case 'dte': $datectrl = new datectrl($this->varname());
                            $datectrl->setdate_int($this->value[0]['value']);
                            $this->value_modified = $datectrl->get_datestring();
                            $this->staticText();       break;
                case 'nul': break;
                case 'txt':
                case 'hid':
                case 'edt':
                case 'fld':
                case 'rio':
                case 'sel':
                case 'pre':
                case 'tpr':
                case 'inf':
                case 'fil': $this->staticText();       break;
                case 'wi2':
                case 'tag':
                case 'mse':
                case 'mfl':
                case 'mch':
                default:    $this->value_modified = $this->implodeVal();
                            $this->staticText();       break;

            }
        } elseif (($this->mode == 'anonym') OR ($this->mode == 'normal')) {
            switch ($this->input_type) {
//                case 'inf': $this->staticText();       break;

                case 'nul': break;
                case 'chb': $this->inputChBox();       break;
                case 'hid': $this->hidden();           break;
                case 'fld': if (!$this->translations) {
                                // not yet prepared for standard widget - Triggers - SetField, GetField do not work. @TODO
                                $this->inputText(get_if($this->param[0],255), get_if($this->param[1],0)); // maxlength, fieldsize
                            } else {
                                $this->inputWidget($item, $slice_id);          // standard
                            }
                            break;
                // case 'mfl': [$actions, $rows, $max_characters, $width] = $this->param;
                //            $actions = get_if($actions, 'MDAC'); // move, delete, add, change
                //            $this->varname_modify('[]');         // use slightly modified varname
                //
                //            // prepare value array for selectbox
                //            foreach ( $this->value as $content ) {
                //                $this->const_arr[$content['value']] = $content['value'];
                //            }
                //            $this->inputRelation($rows, '', '', '', $actions);
                //            break;
                //case 'edt': $this->richEditTextarea(
                //                       get_if($this->param[0],10),       // rows
                //                       get_if($this->param[1],70),       // cols
                // //                      get_if($this->param[2],'class')); // type
                //            $GLOBALS['list_fnc_edt'][] = $this->varname();
                //            break;
                case 'sel': [,$slice_field, $usevalue, $whichitems, $conds_str, $sort_str, $add_slice_pwd, $const_param] = $this->param;
                            if ( !is_null($item) ) {
                                $conds_str = $item->unalias($conds_str);
                            }
                            if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
                            $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems, null, ($add_slice_pwd ? AA_Credentials::encrypt($add_slice_pwd) : null), null, $const_param);  // if we fill it there, it is not refilled in inputSel()
                            $this->inputSelect($usevalue);
                            break;
//                case 'rio': [,$ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str, $add_slice_pwd, $const_arr] = $this->param;
//                            if ( !is_null($item) ) {
//                                $conds_str = $item->unalias($conds_str);
//                            }
//                            $this->inputRadio($ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str, $add_slice_pwd, $const_arr);
//                            break;
                //case 'mch': [,$ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str, $add_slice_pwd, ,$height] = $this->param;
                //            if ( !is_null($item) ) {
                //                $conds_str = $item->unalias($conds_str);
                //            }
                //            $this->varname_modify('[]');         // use slightly modified varname
                //            $this->inputMultiChBox($ncols, $move_right, $slice_field, $whichitems, $conds_str, $sort_str, $add_slice_pwd, false, $height);  // move_right
                //            break;
                case 'mse': [,$rows, $slice_field, $whichitems, $conds_str, $sort_str, $add_slice_pwd, $const_param] = $this->param;
                            if ( !is_null($item) ) {
                                $conds_str = $item->unalias($conds_str);
                            }
                            $rows = ($rows < 1) ? 5 : $rows;
                            $this->varname_modify('[]');         // use slightly modified varname
                            $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems, null, ($add_slice_pwd ? AA_Credentials::encrypt($add_slice_pwd) : null), null, $const_param);
                            $this->inputMultiSelect($rows);
                            break;
//                case 'fil': [$accepts, $text, $hlp] = $this->param;
//                            $this->inputFile($accepts, $text, $hlp);
//                            break;
                case 'dte': if ( strstr($this->param[0], "'")) {     // old format
                                $this->param = explode("'",$this->param[0]);
                            }
                            [$y_range_minus, $y_range_plus, $from_now, $display_type] = $this->param;
                            if ($display_type == 2) {
                                $this->inputWidget($item, $slice_id);  // new form of input type
                            } else {
                                $this->dateSelect($y_range_minus, $y_range_plus, $from_now, $display_type);
                            }
                            break;
                case 'pre': [, $maxlength, $fieldsize, $slice_field, $usevalue, $adding, $secondfield, $add2constant, $whichitems, $conds_str, $sort_str, $add_slice_pwd, $const_param] = $this->param;
                            // add2constant is used in insert_fnc_qte - adds new value to constant table
                            if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
                            $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems, null, ($add_slice_pwd ? AA_Credentials::encrypt($add_slice_pwd) : null), null, $const_param);
                            $this->inputPreSelect($maxlength, $fieldsize, $adding, $secondfield, $usevalue );
                            break;
                case 'tpr': $this->textareaPreSelect(get_if($this->param[1],4),    // rows
                                                     get_if($this->param[2],60));  // cols
                            break;
                case 'iso': [, $rows, $mode, $design, $tp, $actions, $whichitems, $conds, $condsrw, $slice_field, $sort_by, $additional_slice_pwd, $const_arr, $listlen] = $this->param;
                            $mode      = get_if($mode,'AMB');         // AMB - show 'Add', 'Add mutual' and 'Add backward' buttons
                            $tp        = get_if($tp,  'AMB');         // Default to use the AMP table
                            $tagprefix = TPS[$tp] ?? ( $GLOBALS['apc_state']['TPS'][$tp] ?? null );
                            if ( is_null($tagprefix) ) {
                                $this->msg[] = _m("Unable to find tagprefix table %1", [$tp]);
                            }
                            $this->varname_modify('[]');         // use slightly modified varname
                            // read related items for selected items
                            $sid = $this->fill_const_arr($slice_field, false, false, AA_BIN_ALL, $this->value, null, $tagprefix);  // if we fill it there, it is not refilled in inputSel()
                            //$sid = (substr($this->param[0],0,7) == "#sLiCe-") ? substr($this->param[0], 7) : '';
                            $this->inputRelation($rows, $sid, $mode, $design, $actions, $whichitems, $conds, $condsrw, $slice_field, $sort_by, $listlen);
                            break;
                case 'hco': [$constgroup, $levelCount, $boxWidth, $rows, $horizontalLevels, $firstSelectable, $levelNames] = $this->param;
                            $this->varname_modify('[]');         // use slightly modified varname
                            $this->hierarchicalConstant($constgroup, $levelCount, $boxWidth, $rows, $horizontalLevels, $firstSelectable, explode('~',$levelNames));
                            break;
                case 'pwd':  // handled in passwordModify
                            [$fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help] = $this->param;
                            $this->passwordModify( $fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label, $change_pwd_help, $retype_pwd_help);
                            break;
                            //BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
                case 'lup': [$url] = $this->param;
                            $this->inputLocalURLPick($url);
                            break;
                            //END// Local URL Picker | Omar/Jaime | 11-06-2005
                case 'txt': if (!$this->translations) {
                                $this->textarea(get_if($this->param[0], 4));   // not yet prepared for standard widget - Triggers - SetField, GetField do not work. Also the converters are not implemented @TODO
                            } else {
                                $this->inputWidget($item, $slice_id);          // standard
                            }
                    break;
                //case 'txt': $this->textarea(get_if($this->param[0], 4));   // not yet prepared for standard widget - Triggers - SetField, GetField do not work. @TODO
                //            break;
                //case 'txt': if (AA\Util\JsTriggers::singleton4id($slice_id)->isTriggers()) {
                //                $this->textarea(get_if($this->param[0], 4));   // not yet prepared for standard widget - Triggers - SetField, GetField do not work. @TODO
                //            } else {
                //                $this->inputWidget($item, $slice_id);          // standard
                //            }
                //    break;
                case 'wi2': [$constgroup, $rows, $wi2_offer, $wi2_selected, $slice_field, $whichitems, $conds_str, $sort_str, $addform, $add_slice_pwd] = $this->param;
                            if ( !is_null($item) ) {
                                $conds_str = $item->unalias($conds_str);
                            }
                            $this->varname_modify('[]');         // use slightly modified varname
                            $this->twoBox(get_if($rows,5), $wi2_offer, $wi2_selected, $slice_field, $whichitems, $conds_str, $sort_str, $addform, $add_slice_pwd);
                            break;
                //case 'wi2':  // still uses mch
                case 'tag':
                case 'mfl':
                case 'rim':
                case 'cod':
                case 'rio':
                case 'inf':
                case 'edt':
                case 'mch':
                case 'mng': // Manager
                case 'fil':
                //case 'hid': // not prepared yet - the field name and help is visible in inputWidget
                //case 'pwd':
                //case 'txt':
                //case 'fld':
                default:    $this->inputWidget($item, $slice_id);

            }
        }
        return $this->result;
    }

    /**
     * @return string
     */
    function get_result(): string {
        return $this->result;
    }


    // private functions - functions helping field display ---------------------

    /** needed function
     * functions to show additional field data
     */
    protected function needed() {
        if ( $this->required ) {
            $this->echoo( "&nbsp;*" );
        }
    }

    protected function help($hlp) {
        if ( $hlp ) {
            $this->echoo( "<div class=\"tabhlp\">$hlp</div>" );
        }
    }

    protected function morehelp($hlp) {
        if ( $hlp ) {
            $this->echoo( "<a href=".safe($hlp)." target='_blank' class='aa-morehelp'>?</a>" );
        }
    }

    /** helps function
     * shows help message and link to more help document, if set
     * @param $plus
     * @param $hlp
     * @param $more_hlp
     */
    protected function helps($plus=false, $hlp=null, $more_hlp=null ) {
        $this->morehelp(is_null($more_hlp) ? $this->input_morehlp : $more_hlp );
        $this->help(    is_null($hlp)      ? $this->input_help    : $hlp );
        if ( $plus ) {
            $this->echoo("</td>\n</tr>\n");
        }
    }

    /** field_tr - begin of field row
     * @param bool $start
     * @param bool $mark_single
     */
    protected function field_tr(bool $start=true, bool $mark_single=false) {
        $start  = $start ? ' fieldstart' : '';
        $start .= $mark_single ? ' aa-singlecol' : '';
        $this->echoo("\n<tr class=\"formrow{formpart}$start cont-".$this->varname."\">");
    }

    /** Prints field name (and 'needed' sign - star) in table cell for inputform
     * @param $plus
     * @param $colspan
     * @param $name
     */
    protected function field_name($plus=false, $colspan=1, $name=null ) {
        $name = is_null($name) ? $this->name : $name;
        switch( $plus ) {
        case 'plus':    $this->field_tr();
                        break;
        case 'secrow':  $this->field_tr(false);
        }
        $this->echoo("\n <th ". (($colspan==1) ? '': "colspan=\"$colspan\""). '>'. $name );
        $this->needed();
        $this->echoo("</th>\n");
        if ( $plus=='plus' OR $plus=='secrow' ) {
            $this->echoo(' <td>');
        }
    }

    /** get_convertors function
     *  Print links to document convertors, if convertors are installed
     */
    function get_convertors() {
        $constants = array_filter(get_defined_constants(true)['user'], function($key) {
            return strpos($key, 'CONV_TEXTFILTERS') === 0;
        }, ARRAY_FILTER_USE_KEY);

        $convertors = array_map(function($key) {
            return substr($key, 17);
        },array_keys($constants));

        $convtext = join('/',array_diff($convertors, ['ICONV']));
        $convertor = "<a class=\"btn aa-convertor\" href=\"javascript:CallConvertor('".self_server(). AA_INSTAL_PATH."', '".$this->varname."')\">$convtext "._m('import') ."</a>";

        return $convertor;
    }

    /** html_radio function
     * Prints html/plan_text radiobutton
     * @param bool $conv
     */
    function html_radio($conv = false) {
        $varname = $this->varname;

        if ( $this->html_rb_show ) {
            $htmlvar     = $varname."html";
            $radio_html  = "<input type=\"radio\" name=\"$htmlvar\" value=\"h\"". (  $this->html_flag ? " checked>" : ">" );
            $radio_plain = "<input type=\"radio\" name=\"$htmlvar\" value=\"t\"". ( !$this->html_flag ? " checked>" : ">" );
            $htmlareaedit= "<a href=\"javascript:openHTMLAreaFullscreen('".$varname."');\">"._m("Edit in HTMLArea")."</a>"; // used for HTMLArea
            // conversions menu
            $convertor   = $conv ? $this->get_convertors() : false;

            // show plaintext / html radio
            $this->echoo('<div class="aa-fld-toolbar" id="htmlplainspan'.$varname.'">');  // used for hiding "edit in htmlarea" link, dont remove

            $this->echoo('<span class="aa-formatters">');
            $this->echoo('<label>');
            $this->echovar($radio_html,  'html_rb');
            $this->echoo(_m("HTML"));
            $this->echoo('</label><label>');
            $this->echovar($radio_plain, 'plain_rb');
            $this->echoo(_m("Plain text"));
            $this->echoo('</label></span>');
            $this->echoo('<span id="arealinkspan'.$varname.'" class="btn aa-arealink" style="display:none">'); // used for hiding "edit in htmlarea" link, don't remove
            $this->echovar($htmlareaedit, 'htmlarea_edit_link');
            $this->echoo('</span>');

            if ( $convertor ) {
                $this->echovar($convertor,   'conv');
            } 
            $this->echoo("</div>\n");
        }
    }

    // private functions - field specific helper functions ---------------------

    /** getRadioButtonTag function
     * Returns one radio tag - Used in inputRadio
     * @param        $k
     * @param        $v
     * @param string $add
     * @param string $labeladd
     * @return string
     */
    function getRadioButtonTag($k, $v, $add='', $labeladd='') {
        $name  = $this->varname();
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::getFromGlobalSliceId("input", $name) : '';
        $ret   = "<label $labeladd><input type=radio name='$name' value='". safe($k) ."' $add $triggers";
        $ret  .= $this->if_selected($k, " checked");
        $ret  .= '>'.safe($v).'</label>';
        return $ret;
    }

    /**
     * @param array $parts
     * @return string
     */
    protected static function getLangSwitch($parts) {
        $ret = '';
        if (count($parts)>1) {
            $ret .= "<div class=\"aa-langswitch\">";
            $ret .= "\n  <input type=button value=\""._m('Translate')."\" class=\"aa-langall\" onclick=\"[].forEach.call(document.querySelectorAll('#".join(",#", array_keys($parts))."'), function(a) { a.style.display='block'; }); this.parentNode.style.display='none';\">";
            //$ret .= "\n  <input type=button value=\""._m('Translate')."\" class=\"aa-langall\" onclick=\"[$('".join("'),$('", array_keys($parts))."')].invoke($(this).hasClassName('aa-open')?'hide':'show');[$('aa-but".join("'),$('aa-but", array_keys($parts))."')].invoke('toggleClassName', 'aa-open', !$(this).hasClassName('aa-open'));$(this).toggleClassName('aa-open')\">";
            foreach ($parts as $id => $prop) {
                $ret .= "\n     <input type=button value=\"".$prop[0]."\" id=\"aa-but$id\" class=\"".join(' ',$prop[1])."\" onclick=\"this.classList.toggle('aa-open');document.querySelector('#$id').style.display=(this.classList.contains('aa-open')?'block':'none');\">";
            }
            $ret .= "\n</div>";
        }
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
        [$name,$val,$add] = $this->prepareVars();

        $this->field_tr();
        if ( !$changeorder ) {
            $this->field_name(false, $colspan);
        }
        $this->echoo("<td>");
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->echovar("<input type=\"checkbox\" name=\"$name\" $add ". ($this->required ? " required": ''). ($val ? " checked" : ''). " $triggers>");
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
        [$name,$val,$add] = $this->prepareVars();

        $datectrl = new datectrl($this->varname(), $y_range_minus, $y_range_plus, $from_now, $display_time, $this->required);
        $datectrl->setdate_int($val);

        AA::$debug&2 && AA::$dbg->log('---------------', $this, $val, $datectrl);

        $this->field_name('plus');

        $this->echovar( $datectrl->getdayselect(),   'day'  );
        $this->echovar( $datectrl->getmonthselect(), 'month');
        $this->echovar( $datectrl->getyearselect(),  'year' );
        $this->echovar( $datectrl->gettimeselect(),  'time' );
        $this->helps('plus');
    }

    /** inputText function
     * Prints html tag <input type=text .. to 2-column table
     * for use within <form> and <table> tag.
     * @param int    $maxsize
     * @param int    $size
     * @param string $type allows to show <INPUT type=PASSWORD> field as well
     *                     (and perhaps BUTTON and SUBMIT also, but I do not see
     *                      any usage) - added by Jakub, 28.1.2003
     * @param string $placeholder
     * @param string $pattern
     */
    function inputText($maxsize=255, $size=25, $type="text", $placeholder='', $pattern='') {
        [$name,$val,$add] = $this->prepareVars('multi');

        $attrs = ['type'=>'text'];
        $validator = is_object($this->valid) ? $this->valid : AA_Validate::factoryByString($this->valid);
        if (is_object($validator)) {
            $attrs = array_merge($attrs, $validator->getHtmlInputAttr());
        }

        if (in_array($type, ['password','search', 'email', 'url', 'tel', 'number', 'range', 'date', 'month', 'week', 'time', 'datetime', 'datetime-local', 'color'])) {
            $attrs['type'] = $type;
        }
        if ($placeholder) {
            $attrs['placeholder'] = safe($placeholder);
        }
        if (strlen($pattern)) {
            $attrs['pattern'] = $pattern;
        }

        $attrs['size']      = get_if($size, $attrs['size']);
        if (!$attrs['size']) {
            unset($attrs['size']);
            if ( in_array($attrs['type'], ['text','search', 'email', 'url']) ) {
                $attrs['class'] = $attrs['class'] ? $attrs['class']. ' aa-w-full' : 'aa-w-full'; // $attrs['style'] = 'width:95%';
            }
        }

        $attrs['maxlength'] = get_if($maxsize, $attrs['maxlength'], 255);

        // type number do not support size parameter, so ve have to set max and min (make no sence to do it for numbers > 11 characters - use default)
        if (($attrs['type']=='number') AND !isset($attrs['max']) AND ($nchars = $attrs['maxlength'] ?: $attrs['size'] ?: 0) AND ($nchars<11)) {
            $attrs['max'] = pow(10,$nchars)-1;
            $attrs['min'] = $attrs['min'] ?: '0';
        }
        $attr_string = join(' ', array_map( function($k, $v) {return "$k=\"$v\"";}, array_keys($attrs), $attrs));

        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->field_name('plus');
        $this->html_radio();

        if ($this->translations) {
            $first = true;
            $parts = [];
            $widg = "<div class=\"aa-langdiv\">";
            foreach($this->translations as $lang) {
                $lang_id           = Langs::getLangName2Num($lang);  // converts two letter lang code into number used for translation fields (cz -> 78000000, en -> 118000000, ...)
                $value             = safe($val[$lang_id]['value']);
                if ( !strlen($value) AND $first) {
                    // if the translation is not set try to use standard value (possibly filled before we set the translations for the field)
                    $value = $val[0]['value'];
                }

                $name2             = $name."[$lang_id]";
                $id2               = $name.'-'.$lang_id;
                $classes           = [$first ?'aa-open' : ''];
                if (strlen(trim($value))) {
                    $classes[] = 'aa-filled';
                }
                $parts["part$id2"] = [$lang, $classes];

                $widg .= "<label class=\"aa-langtrans aa-lang-$lang\" id=\"part$id2\" ".($first ? '' : ' style="display:none;"')."><strong class=\"aa-langtrans-label\">$lang</strong>";


                $link  = ((substr($value,0,7)==='http://') OR (substr($value,0,8)==='https://')) ? a_href($value, GetAAImage('external-link.png', _m('Show'), 16, 16), ['class'=>'aa-externallink']) : '';
                $widg .= "<input id=\"$id2\" name=\"$name2\" $attr_string ". (($this->required AND $first) ? " required": ''). $GLOBALS['mlxFormControlExtra']. " value=\"$value\" $triggers>$link";
                $widg .= "</label>";
                $first = false;
            }
            $widg .= AA_Inputfield::getLangSwitch($parts);
            $widg .= "\n</div>";

        } else {
            $value     = safe($val[0]['value']);
            $link    = ((substr($value,0,7)==='http://') OR (substr($value,0,8)==='https://')) ? a_href($value, GetAAImage('external-link.png', _m('Show'), 16, 16), ['class'=>'aa-externallink']) : '';

            $widg =  "<input name=\"$name\" $attr_string ". ($this->required ? " required": ''). $GLOBALS['mlxFormControlExtra']." value=\"$value\" $triggers>$link";
        }

        $this->echovar($widg);
        $this->helps('plus');


    }

    /** staticText function
    * Prints two static text to 2-column table
    * for use within <table> tag
    * @param $safing
    * @param $type
    */
    function staticText($safing=true, $type='first') {
        [$name,$val,$add] = $this->prepareVars($type);
        if ( $safing ) $val   = safe($val);
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
        [$name,$val,$add] = $this->prepareVars();
        if ( $safing ) {
            $val=safe($val);
        }
        $this->echoo('<tr style="visibility:collapse;"><td colspan=2>');
        $this->echovar( "<input type=hidden name=\"$name\" value=\"$val\">" );
        $this->echoo('</td></tr><tr style="visibility:collapse;"><td colspan=2></td></tr>'."\n");  // add extra row fro zebra stripes
    }

    /** textarea function
     * Prints html tag <textarea .. to 2-column table
     * for use within <form> and <table> tag
     *
     * @param int $rows
     * @param int $cols
     * @param bool $single - mark row by class to be displayed as big textarea spanning two columns
     * @param bool $showrich_href - have we show "Show Editor" link? (if we want to, we have
     *                  to include /misc/htmlarea/aafunc.js script to the page
     * @param bool $showhtmlarea
     * @param int $maxlength
     */
    function textarea($rows = 4, $cols = 60, $single = false, $showrich_href = true, $showhtmlarea = false, $maxlength = 0)
    {

        [$name,$val,$add] = $this->prepareVars('multi');
        // make the textarea bigger, if already filled with long text

        $rows     = (int)$rows;
        $maxlen   = ($maxlength = (int)$maxlength) ? " maxlength=$maxlength" : '';
        $colsy    = ($cols = (int)$cols) ? " cols=$cols" : '';
        // $colspan  = $single ? 2 : 1;
        $this->field_tr(true, (bool)$single);
        $this->field_name();
        $this->echoo(' <td>');
        $this->html_radio(!$showhtmlarea);

        if (!$showhtmlarea AND $showrich_href ) {
            AA::Stringexpander()->addRequire("if (CKEDITOR.env.isCompatible) { [].forEach.call(document.querySelectorAll('.aa-arealink'), function(e) { e.style.display=''; }); }", 'AA_Req_Run');
//                    $widg .= getFrmJavascript( 'showHTMLAreaLink("'.$id2.'");');
        }

        // fix for IE - where the textarea icons are too big so there is
        // no space for the text
        if ($showhtmlarea) {
            $rows += 8;
        }

        $areaclass = $showhtmlarea ? ' class=ckeditor' : '';
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("textarea", $name) : '';

        if ($this->translations) {
            $first = true;
            $parts = [];
            $widg = "<div class=\"aa-langdiv\">";
            foreach($this->translations as $lang) {
                $lang_id           = Langs::getLangName2Num($lang);  // converts two letter lang code into number used for translation fields (cz -> 78000000, en -> 118000000, ...)
                $value             = safe($val[$lang_id]['value']);
                if ( !strlen($value) AND $first) {
                    // if the translation is not set try to use standard value (possibly filled before we set the translations for the field)
                    $value = $val[0]['value'];
                }
                $rows              = max($rows, min(floor(substr_count($value,"\n")*1.3), 30));
                $rows_css          = $rows*1.3;  // firefox adds extra line, so we specify the height in css as well. Not so important, can be changed later.

                $name2             = $name."[$lang_id]";
                $id2               = $name.'-'.$lang_id;
                $classes           = [$first ?'aa-open' : ''];
                if (strlen(trim($value))) {
                    $classes[] = 'aa-filled';
                }
                $parts["part$id2"] = [$lang, $classes];

                $widg .= "<label class=\"aa-langtrans aa-lang-$lang\" id=\"part$id2\" ".($first ? '' : ' style="display:none;"')."><strong class=\"aa-langtrans-label\">$lang</strong>";
                $widg .= "<textarea id=\"$id2\" name=\"$name2\"$areaclass rows=\"$rows\" ".$GLOBALS['mlxFormControlExtra']."$colsy$maxlen class=aa-w-full style=\"height:{$rows_css}em;\" $triggers>$value</textarea>\n";
                $widg .= "</label>";
                if (!$showhtmlarea AND $showrich_href ) {
                    AA::Stringexpander()->addRequire("if (CKEDITOR.env.isCompatible) { [].forEach.call(document.querySelectorAll('.aa-arealink'), function(e) { e.style.display=''; }); }", 'AA_Req_Run');
//                    $widg .= getFrmJavascript( 'showHTMLAreaLink("'.$id2.'");');
                }
                $first = false;
            }
            $widg .= AA_Inputfield::getLangSwitch($parts);
            $widg .= "\n</div>";
        } else {

            $value    = safe($val[0]['value']);
            $rows     = max($rows, 2, min(floor(substr_count($value,"\n")*1.35), 30));
            $rows_css = $rows*1.3;  // firefox adds extra line, so we specify the height in css as well. Not so important, can be changed later.

            $widg    = "<textarea id=\"$name\" name=\"$name\"$areaclass". ($this->required ? " required": '') ." rows=\"$rows\" ".$GLOBALS['mlxFormControlExtra']."$colsy$maxlen class=aa-w-full style=\"height:{$rows_css}em;\" $triggers>$value</textarea>\n";
        }
        $this->echovar($widg);
        $this->helps('plus');
    }

    /** inputRadio function
     * Prints a radio group, html tags <input type="radio" .. to 2-column table
     * for use within <form> and <table> tag
     * @param int  $ncols
     * @param bool $move_right
     */
    function inputRadio($ncols=0, $move_right=true) {
        [$name,$val,$add] = $this->prepareVars('multi');
        $records = [];
        foreach ( $this->const_arr as $k => $v ) {
            $records[] = $this->getRadioButtonTag($k, $v, $add);
        }
        $this->printInMatrix_Frm($records, $ncols, $move_right);
    }

    /** printInMatrix_Frm function
     * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
     * - for use internal use of removed inputMultiChBox and FrmInputRadio
     * @param      $records
     * @param      $ncols
     * @param      $move_right
     * @param null $height
     */
    function printInMatrix_Frm($records, $ncols, $move_right, $height=null) {
        //[$name,$val,$add] = $this->prepareVars('multi');
        $ret = '';
        $this->field_name('plus');

        if (is_array($records)) {
            if (! $ncols) {
                $this->echovar( implode('', $records) );
            } else {
                $height    = (int)$height;
                $nrows     = ceil( count($records) / $ncols);
                if ($height > 0) {
                    $this->echoo("<div style=\"max-height:{$height}px; overflow:auto;\">");
                }
                $this->echoo('<table>');
                for ($irow = 0; $irow < $nrows; ++$irow) {
                    $ret  .= '<tr>';
                    for ($icol = 0; $icol < $ncols; ++$icol) {
                        $pos  = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
                        $ret .= '<td>'. get_if($records[$pos], "&nbsp;") .'</td>';
                    }
                    $ret .= '</tr>';
                }
                $this->echovar($ret);
                $this->echoo('</table>');
                if ($height > 0) {
                    $this->echoo('</div>');
                }
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
     * @return string
     */
    function get_options( $arr, $usevalue=false, $testval=false, $restrict='all', $add_empty=false, $do_not_select=false) {
        $selectedused  = false;
        $select_string = ( $do_not_select ? ' class="sel_on"' : ' selected class="sel_on"');

        $already_selected = [];    // array where we mark selected values
        $pair_used        = [];    // array where we mark used pairs
        $this->_fillSelected();         // fill selected array by all values in order we can print invlaid values later
        $ret = '';
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
                $ret .= "<option value=\"". safe($k) ."\" $selected>".safe($v);    // do not put newlines here in order select:epty css selector works
            }
        }
        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if ( isset( $this->selected ) AND is_array( $this->selected ) AND ($restrict != 'unselected')) {
            foreach ( $this->selected as $k =>$v ) {
                if ( !$already_selected[$k] ) {
                    $ret .= "<option value=\"". safe($k) ."\" selected class=\"sel_missing\">".safe($k);
                    $selectedused = true;
                }
            }
        }
        if ( $add_empty ) {
            $emptyret = "<option value=\"\"";
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
     */
    function inputMultiSelect($rows=6) {
        [$name,$val,$add] = $this->prepareVars('multi');
        $rows                 = get_if($rows, 6);
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("select", $name) : '';

        $this->field_name('plus');
        $ret       = "<select name=\"$name\" size=\"$rows\" multiple $triggers". ($this->required ? " required": '') .">";
        $ret      .= $this->get_options( $this->const_arr, false, false, 'all', !$this->required);
        // $option_no = count($this->const_arr) + ($this->required ? 0:1);
        // add blank rows if asked for
        // removed - since it was never used and it just works - $minrows is not filled here
        // while ( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
        //     $ret .= AA_WIDTHTOR;
        // }
        $ret .= "</select>";
        //$ret .= $this->presentFieldIndicator($name); // not necessary - the value is sent either way
        $this->echovar( $ret );
        $this->helps('plus');
    }

    /** inputRelation function
     * Prints html tag <select multiple .. and "Add" relation button
     * to 2-column table for use within <form> and <table> tag
     * @param int    $rows
     * @param string $sid
     * @param string $mode    - which buttons to show in related item window:
     *                     'A'dd, add 'M'utual, 'B'ackward
     * @param bool   $design
     * @param string $actions - which action to show:
     *                     'M'ove (up and down),
     *                     'D'elete relation,
     *                     add 'R'elation,
     *                     add 'N'ew related item
     *                     'E'dit related item
     *                     'A'dd text field (you can type the value - see mft)
     *                     'C'hange the value (by typing - see mft input type)
     * @param int    $whichitems
     * @param string $conds
     * @param string $condsrw
     * @param string $slice_field
     */
    function inputRelation($rows=6, $sid='', $mode='AMB', $design=false, $actions='MDR', $whichitems=AA_BIN_ACT_PEND, $conds="", $condsrw="", $slice_field='', $sort_by='', $listlen=0) {
        [$name,$val,$add] = $this->prepareVars('multi');
        // backward compatibility - 0 means "not show move buttons", 1 - "show"
        $listlen              = get_if($listlen, EDIT_ITEM_COUNT);
        $actions              = get_if($actions, 'DR');
        $actions              = str_replace(['0','1'], ['DR','MDR'],(string)$actions);
        $movebuttons          = (strpos($actions,'M') !== false);
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("select", $name) : '';

        if ( $whichitems < 1 ) {
            $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        }

        $this->field_name('plus');
        $option_no = count($this->const_arr) + ($this->required ? 0:1);
        $rows      = get_if($rows, 6);
        if ($rows < $option_no) {   // we can make the select bigger, if it contains more options, not more than 3 times bigger, however
            $rows = min($option_no, 3*$rows);
        }
        $ret       ="<select name=\"$name\" size=\"$rows\" multiple $triggers>";
        $ret      .= $this->get_options( $this->const_arr, false, false, 'all', false, true);
        $ret .= "</select>";
        $ret .= $this->presentFieldIndicator($name);

        $this->echoo("<div class=\"aa-widget aa-widget-iso aa-stdwidget\"><div class='aa-input'>
          <div class='aa-iso-list'>");

        $this->echovar( $ret );
        $this->echoo("</div>\n");
        if ($movebuttons) {
             $this->echoo("<div class='aa-iso-updown'>");
             $this->echoo("<input type=\"button\" value=\" &#9650; \" class=aa-widg-up onClick=\"moveItem(this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;),'up');\">");
             $this->echoo("<input type=\"button\" value=\" &#9660; \" class=aa-widg-down onClick=\"moveItem(this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;), 'down');\">");
             $this->echoo("</div>");
        }
        $this->echoo("</div>\n <div class='aa-iso-foot'>\n");
        if (strpos($actions,'R') !== false) {
            $this->echoo("<input type='button' value='". _m("Add") ."' onclick='OpenRelated(\"$name\", \"$sid\", \"$mode\", \"$design\", \"$whichitems\",\"".rawurlencode($conds)."\",\"".rawurlencode($condsrw)."\", \"".rawurlencode($slice_field)."\",\"".get_admin_url('related_sel.php')."\", \"".rawurlencode($sort_by)."\", \"".rawurlencode($listlen)."\" )'>\n");
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
        $this->echoo(getFrmJavascript("if (typeof listboxes == 'undefined') { var listboxes = []; };  listboxes[listboxes.length] = '$name';"));
        $this->echoo("</div></div></div>\n");
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
    //function inputRelation2($rows=6, $sid='', $minrows=0, $mode='AMB', $design=false, $movebuttons=true, $whichitems=AA_BIN_ACT_PEND, $conds="", $condsrw="") {
    //    [$name,$val,$add] = $this->prepareVars('multi');
    //    $rows                 = get_if($rows, 6);
    //    if ( $whichitems < 1 ) {
    //        $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
    //    }
    //
    //    $this->field_name('plus');
    //
    //    $new_version = true;
    //    if ( $new_version ) {
    //        $varname = $this->varname;  // name without ending []
    //        $var_code = '
    //        <table class="formtable" id="rel'.$varname.'">
    //          <tr>
    //            <th>'._m('Item').'</th>
    //            <th>'._m('Actions').'</th>
    //          </tr>';
    //        $i=0;
    //        foreach ( (array)$this->const_arr as $id => $text) {
    //            $tr_id     = 'rel'.$varname.'old'.($i++);
    //            $var_code .= '<tr id="'.$tr_id.'">
    //            <td>'.safe($text).'<input type="hidden" name="'.$name.'" value="'.safe($id).'"></td>
    //            <td>'.
    //              GetAAImage("edit.gif", _m('Edit'), 16, 16).
    //              GetAAImage("delete.gif", _m('Delete'), 16, 16).
    //              '<a href="javascript:MoveRowUp(\''.$tr_id.'\')">'. GetAAImage("up.gif", _m('Move up'), 16, 16). '</a>'.
    //              '<a href="javascript:MoveRowDown(\''.$tr_id.'\')">'. GetAAImage("down.gif", _m('Move down'), 16, 16). '</a>'.
    //            '</td>
    //          </tr>';
    //        }
    //        $var_code .= '</table>';
    //        $this->echovar( $var_code );
    //        $var_code = '
    //        <div>
    //          <input type="button" value="'. _m("Add") ."\" onclick=\"OpenRelated('".$this->varname."', '$sid', '$mode', '$design', '$whichitems','".rawurlencode($conds)."','".rawurlencode($condsrw)."','".get_admin_url('related_sel.php')."' )\">
    //        </div>
    //        ";
    //        $this->echovar( $var_code, 'buttons' );
    //    } else {
    //        $ret       = "<select name=\"$name\" size=\"$rows\" multiple".getTriggers("select",$name).">";
    //        $ret      .= $this->get_options( $this->const_arr, false, false, 'all', false);
    //        $option_no = count($this->const_arr) + ($this->required ? 0:1);
    //        // add blank rows if asked for
    //        while ( $option_no++ < $minrows ) { // if no options, we must set width of <select> box
    //            $ret .= AA_WIDTHTOR;
    //        }
    //        $ret .= "</select>";
    //
    //        $this->echoo('<table><tr>');
    //        if ($movebuttons) {
    //            $this->echoo("\n <td rowspan=\"2\">");
    //        } else {
    //            $this->echoo("\n <td>");
    //        }
    //        $this->echovar( $ret );
    //        $this->echoo("</td>\n");
    //        if ($movebuttons) {
    //             $this->echoo("<td>");
    //             $this->echoo("<input type=\"button\" value=\" &#9650; \" ".
    //             " onClick=\"moveItem(document.inputform['".$name."'],'up');\">");
    //             $this->echoo('</td></tr>');
    //             $this->echoo('<tr><td>');
    //             $this->echoo("<input type=\"button\" value=\" &#9660; \" ".
    //             " onClick=\"moveItem(document.inputform['".$name."'], 'down');\">");
    //             $this->echoo("</td>");
    //        }
    //        $this->echoo("</tr>\n <tr><td>
    //          <input type='button' value='". _m("Add") ."' onclick='OpenRelated(\"$name\", \"$sid\", \"$mode\", \"$design\", \"$whichitems\",\"".rawurlencode($conds)."\",\"".rawurlencode($condsrw)."\",\"".get_admin_url('related_sel.php')."\" )'>
    //          &nbsp;&nbsp;");
    //        $this->echoo("<input type='button' value='". _m("Delete") ."' onclick=\"sb_RemoveItem(document.inputform['".$name."']);\">\n");
    //        $this->echoo(getFrmJavascript("if (typeof listboxes == 'undefined') { var listboxes = []; };  listboxes[listboxes.length] = '$name';"));
    //        $this->echoo("</td></tr></table>\n");
    //    }
    //    $this->helps('plus');
    //}


    /** hierarchicalConstant function
     *  shows boxes allowing to choose constant in a hiearchical way
     * @param        $group_id
     * @param        $levelCount
     * @param        $boxWidth
     * @param        $rows
     * @param int    $horizontal
     * @param int    $firstSelect
     * @param string $levelNames
     */
    function hierarchicalConstant($group_id, $levelCount, $boxWidth, $rows, $horizontal=0, $firstSelect=0, $levelNames="") {
        static $hcid = 0;
        $hcid++;   // this is hc identifier
        [$name,$val,$add] = $this->prepareVars('multi');
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
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("select", $name) : '';
        $aa_onchange_exist = strstr($triggers,'aa_onChange(');
        $delete_button_trigger = "";
        if( $aa_onchange_exist ){
            [,$fieldid,] = explode("'",$aa_onchange_exist,3);
            $delete_button_trigger = 'aa_onChange("'.$fieldid.'"); ';
        }

        $this->echoo("
            <!-- {require:aa-constedit} -->
            <table><tr>
            <td><b><span class=\"redtext\">"._m("Selected").":<span></b><br><br><input type=\"button\" value=\""._m("Delete")."\" onclick='hcDelete(\"$name\"); $delete_button_trigger'></td>
            <td>");
        $out = "\n<select name=\"$name\" multiple size=\"$rows\" $triggers>";
            if (is_array($val)) {
                $constants_names = GetConstants($group_id);
                foreach ( $val as $v) {
                    if ($v['value']) {
                        $out .= "\n  <option value=\"".safe($v['value'])."\">".safe($constants_names[$v['value']])."\n";
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
            if (typeof listboxes == 'undefined') { var listboxes = []; };
            listboxes[listboxes.length] = '$name';"));
        $this->helps('plus');
    }

    /** inputSelect function
    * Prints html tag <select .. to 2-column table
    * for use within <form> and <table> tag
    * @param $usevalue
    */
    function inputSelect($usevalue=false) {
        [$name,$val,$add] = $this->prepareVars();
        $this->fill_const_arr();
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("select", $name) : '';

        $this->field_name('plus');
        $out = "<select name=\"$name\" $triggers>";
        $out .= $this->get_options( $this->const_arr, $usevalue, false, 'all', !$this->required );
        $out .= "</select>";
        $this->echovar( $out );
        $this->helps('plus');
    }

    /**
     * @param AA_Item $item
     * @param string $slice_id
     */
    function inputWidget($item, $slice_id) {
        if ( !($field = AA_Slice::getModule($slice_id)->getField($this->id)) ) {
            return '';
        }

        $widget      = $field->getWidget();
        $aa_property = $field->getAaProperty();

        $this->echoo($widget->stdFormStart($aa_property));

        if (is_null($item) OR $item->isEmpty()) {
            $this->echovar($field->getWidgetNewHtml(null, null, null, null, $this->getAaValue(), null, true));
        } else {
            // another approach would be, but it had troubles in InfWidget with {manager:...{_#ITEM_ID_}...}
            //$content = (new AA_Content() )->setOwnerId($slice_id)->setId($item->getId())->setAaValue($this->id, $this->getAaValue());
            $content = $item->getItemContent();

            $this->echovar($widget->getOnlyHtml($aa_property, $content));
        }
//        if (is_null($item)) {
//            $this->echovar(AA::Stringexpander()->unalias('{inputonly:'.$slice_id.':'.$this->id.'}'));
//        } else {
//            $this->echovar(AA::Stringexpander()->unalias('{edit:'.$item->getId().':'.$this->id.'}','',$item));
//        }
        $this->echoo($widget->stdFormEnd($aa_property));
    }

    /** inputFile function
    * Prints html tag <input type=file .. to 2-column table
    * for use within <form> and <table> tag
    * @param $accepts
    * @param $text
    * @param $hlp
    */
    function inputFile($accepts="*/*", $text="", $hlp="") {
        [$name,$val,$add] = $this->prepareVars();
        if ( !$accepts ) {
            $accepts = '*/*';
        }
        $val  = safe($val);
        $link = $val ? a_href($val, GetAAImage('external-link.png', _m('Show'), 16, 16), ['target' => '_blank']) : '';
        $triggers   = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->field_name('plus');
        $this->echovar( "<input type=text name=\"$name\" class=aa-w-full". $GLOBALS['mlxFormControlExtra']." maxlength=255 value=\"$val\" $triggers>&nbsp;$link" );
        $this->helps('plus');

        $this->name       = $text;
        $this->input_help = $hlp;
        $this->field_name('secrow');
        $file_field_name = $name.'x';
        //$triggers  = $this->use_triggers ? AA\Util\JsTriggers::get("input", $file_field_name, "") : '';
        $this->echovar( "<input type=file name=\"$file_field_name\" accept=\"$accepts\">", 'file');
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
        [$name,$val,$add] = $this->prepareVars();
        $this->fill_const_arr();

        $val=safe($val);
        $maxsize = get_if( $maxsize, 254 );
        $size    = get_if( $size   , 25 );
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->field_name('plus');
        $this->html_radio();

        $this->echovar("<input type=text name=\"$name\" size=\"$size\" maxlength=\"$maxsize\" value=\"$val\" $triggers>");
        $out = "<select name=\"foo_$name\"";
        if ($secondfield) {
            $varsecfield = varname4form($secondfield);
            $out .= " onchange=\"$name.value=this.options[this.selectedIndex].text;";
            $out .= "$varsecfield.value=this.options[this.selectedIndex].value\">";      // could be just this.value, but in this case I want to be consistent with this.options[this.selectedIndex].text method above
        } else {
            $out .= ($adding ?
                     " onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">" :
                     " onchange=\"$name.value=this.options[this.selectedIndex].value\">");
        }
        $out .= $this->get_options( $this->const_arr, $usevalue, $secondfield, 'all', true); // add empty option
        $out .= '</select>';
        $this->echovar( $out, 'presets' );
        $this->helps('plus');
    }
    /** textareaPreSelect function
     * @param $rows
     * @param $cols
     */
    function textareaPreSelect($rows=4, $cols=60) {
        [$name,$val,$add] = $this->prepareVars();
        $this->fill_const_arr();
        $val=safe($val);
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->field_name('plus');
        $this->html_radio();
        $this->echovar( "<textarea name=\"$name\" rows=\"$rows\" cols=\"$cols\" wrap=\"virtual\" $triggers>$val</textarea>" );
        $out  = "<select name=\"foo_$name\" onchange=\"add_to_line($name, this.value)\">";
        $out .= $this->get_options( $this->const_arr );
        $out .= '</select>';
        $this->echovar( $out, 'presets' );
        $this->helps('plus');
    }

    /** twoBox function
     * Prints two boxes for multiple selection for use within <form> and <table> tag
     * @param        $rows
     * @param        $wi2_offer
     * @param        $wi2_selected
     * @param string $slice_field
     * @param int    $whichitems
     * @param bool   $conds_str
     * @param bool   $sort_str
     * @param string $addform
     * @param bool   $add_slice_pwd
     */
    function twoBox($rows, $wi2_offer, $wi2_selected, $slice_field='', $whichitems=AA_BIN_ACT_PEND, $conds_str=false, $sort_str=false, $addform='', $add_slice_pwd=false) {
        [$name,$val,$add] = $this->prepareVars('multi');
        if ( $whichitems < 1 ) $whichitems = AA_BIN_ACT_PEND;              // fix for older (bool) format
        $sid = $this->fill_const_arr($slice_field, $conds_str, $sort_str, $whichitems, null, $add_slice_pwd ? AA_Credentials::encrypt($add_slice_pwd) : null);
        $wi2_offer    = get_if( $wi2_offer,    _m("Offer") );
        $wi2_selected = get_if( $wi2_selected, _m("Selected") );

        // for MoveSelected(), MoveItem()
        AA::Stringexpander()->addRequire(get_aa_url('javascript/inputform.min.js?v='.AA_JS_VERSION,'',false));

        $this->field_name('plus');
        $this->echoo("<div class=\"aa-widget aa-widget-wi2 aa-stdwidget\"><div class='aa-input'>
          <div class='aa-wi2-offer'>
            <label for=''>". $wi2_offer ."</label>");

        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("select", $name) : '';
        $offername = str_replace("[]", "", $name). '_1';
        $out  = "<select multiple name=\"".$offername."\" size=\"$rows\" $triggers ondblclick=\"MoveSelected(this,this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;))\">";  // do not put newlines here in order select:empty css selector works
        $out .= get_if( $this->get_options( $this->const_arr, false, false, 'unselected'), '' );
        $out  .= '</select>';
        $this->echovar( $out, 'unselected' );

        $this->echoo("</div>
          <div class=\"aa-wi2-leftright\">
            <input type=button value=\"  &#9654;  \" class=aa-wi2-right onClick = \"MoveSelected(this.parentNode.parentNode.querySelector(&quot;select[name='$offername']&quot;),this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;))\">
            <input type=button value=\"  &#9664;  \" class=aa-wi2-left onClick = \"MoveSelected(this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;),this.parentNode.parentNode.querySelector(&quot;select[name='$offername']&quot;))\">
          </div>");

        $this->echoo("
          <div class='aa-wi2-selected'>
            <label for=''>". $wi2_selected ."</label>");

        // varname - name without []
        $out = "<select multiple id=\"".$this->varname."\" name=\"".$name."\" size=\"$rows\" $triggers ondblclick=\"MoveSelected(this,this.parentNode.parentNode.querySelector(&quot;select[name='$offername']&quot;))\">";

        // we need values in second box sorted just like in values
        $selected_values = [];
        if ( isset($this->value) AND is_array($this->value) ) {
            foreach ( $this->value as $v ) {
                $key = (string)$v['value'];
                $selected_values[$key] = $this->const_arr[$key];
                if (strlen($selected_values[$key])==0) {
                    $selected_values[$key] = $key;
                }
            }
        }

        $out .= get_if( $this->get_options( $selected_values, false, false, 'selected', false, true), '' );
        $out .= '</select>';
        $out .= $this->presentFieldIndicator($name);

        $this->echovar( $out, 'selected' );

        $this->echoo(getFrmJavascript("if (typeof listboxes == 'undefined') { var listboxes = []; };  listboxes[listboxes.length] = '$name';"));
        $this->echoo("\n      </div>");
        $this->echoo("\n      <div class='aa-wi2-updown'>
            <input type=button value=\" &#9650; \" class=aa-wi2-up onClick=\"moveItem(this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;),'up');\">
            <input type=button value=\" &#9660; \" class=aa-wi2-down onClick=\"moveItem(this.parentNode.parentNode.querySelector(&quot;select[name='$name']&quot;), 'down');\"></div>");

        if (!empty($addform) AND !empty($sid)) {

            // for AA_AjaxInsert()
            AA::Stringexpander()->addRequire('aajslib');

            $this->echoo("\n<div class='aa-wi2-add'>");
            if (!$slice_field) {
                $slice_field = '{'.GetHeadlineFieldID($sid, "headline.").'}';
            }
            $form_url = get_aa_url('form.php', ['form_id' => $addform, 'type' => 'ajax', 'ret_code' => $slice_field]);
            $img      = GetAAImage('icon_new.gif', _m('new'), 17, 17);
            // varname used for as pointer to right selectbox for returning ajax call
            $this->echoo("\n<a id=\"a".$this->varname ."\"href=\"javascript:void(0)\" onclick=\"AA_AjaxInsert(this, '$form_url'); return false;\">$img</a>");
            $this->echoo("\n      </div>");
        }
        $this->echoo("\n        </div></div>");
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
        [$name,$val,$add] = $this->prepareVars();
        $change_pwd_label = get_if($change_pwd_label, _m("Change Password"));
        $retype_pwd_label = get_if($retype_pwd_label, _m("Retype Password"));
        $delete_pwd_label = get_if($delete_pwd_label, _m("Delete Password"));
        $fieldsize        = get_if($fieldsize,        60);
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';

        $this->field_name('plus');
        if ( $this->mode == 'anonym' ) {
            $this->echovar("<input type=\"password\" name=\"$name\" size=\"$fieldsize\" maxlength=\"255\" autocomplete=\"off\" value=\"\" $triggers>" );
            $this->helps('plus');
        } else {
            $this->echovar( $val ? "*****" : _m("not set") );
            $this->echoo("</td></tr>\n");
        }

        if (!$this->required) {
            $this->field_name('secrow',1,$delete_pwd_label);
            $ch_name = $name."d";
            $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $ch_name) : '';
            $this->echovar("<input type=\"checkbox\" name=\"$ch_name\" $triggers>", 'delete');
            $this->helps('plus');
        }

        // change pwd
        $this->field_name('secrow',1,$change_pwd_label);
        $ch_name = $name."a";
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $ch_name) : '';
        $this->echovar("<input type=\"password\" name=\"$ch_name\" size=\"$fieldsize\" maxlength=\"255\" autocomplete=\"new-password\" value=\"\" $triggers>", 'change' );
        $this->helps('plus',$change_pwd_help );

        // retype pwd
        $this->field_name('secrow',1,$retype_pwd_label);
        $ch_name = $name."b";
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $ch_name) : '';
        $this->echovar("<input type=\"password\" name=\"$ch_name\" size=\"$fieldsize\" maxlength=\"255\" autocomplete=\"new-password\" value=\"\" $triggers>", 'retype' );
        $this->helps('plus',$retype_pwd_help );
    }

    //BEGIN// Local URL Picker | Omar/Jaime | 11-06-2005
    /** inputLocalURLPick function
     * Local URL Picker | Omar/Jaime | 11-06-2005
     * @param $url
     */
    function inputLocalURLPick($url) {
        [$name,$val] = $this->prepareVars();
        $this->field_name('plus');
        $triggers  = $this->use_triggers_4_slice ? JsTriggers::singleton4id($this->use_triggers_4_slice)->get("input", $name) : '';
        $ret ="<input type=\"text\" name=\"$name\" size=\"60\" value=\"".safe($val)."\" $triggers>";

        $this->echoo('<table><tr>');
        $this->echoo("\n <td>");
        $this->echovar( $ret );
        $this->echoo("</td>\n");
        $this->echoo("</tr>\n <tr><td>\n");
        $this->echoo("<input type=\"button\" value=\"". _m("Add") ."\" onclick=\"OpenLocalURLPick(\"$name\",\"$url\",\"".self_server().get_aa_url("admin", '', false)."\",\"$val\")\">\n");
        $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Clear") ."' onclick=\"sb_ClearField(document.inputform['".$name."']);\">\n");
        $this->echoo("</td></tr></table>\n");
        $this->helps('plus');
    }
    //END// Local URL Picker | Omar/Jaime | 11-06-2005

    /** Hidden field added to inputs, which sends no POST variable if unfilled
     *  (so we do not know the widget for the field was shown)
     * @param string $name
     * @return string
     */
    protected function presentFieldIndicator(string $name): string
    {
        $present_hidden_flag_name = str_replace(['[',']'], '', $name) . 'p';
        return "<input type=hidden name=\"$present_hidden_flag_name\" value=P>";
    }

}

// ----------------------- Public Form functions ----------

/** getFrmMoreHelp function
 *  prints anchor tag with link to external documentation
 * @param $hlp_url
 * @param $hint
 * @return string
 */
function getFrmMoreHelp($hlp_url, $hint="") {
    if ( $hlp_url ) {
        return a_href($hlp_url,'?', ['title'=>$hint, 'target'=>'_blank', 'class'=>'aa-morehelp']);
    }
    return htmltag('abbr','?', ['title'=>$hint, 'class'=>'aa-morehelp']);
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
    echo $input->get_result();
}


/** Gets html tag <input type=text .. to 2-column table  for use within <form> and <table> tag.
 * @param        $name
 * @param        $txt
 * @param        $val
 * @param int    $maxsize
 * @param int    $size
 * @param bool   $needed
 * @param string $hlp
 * @param string $morehlp
 * @param bool   $html
 * @param string $type  allows to show <INPUT type=PASSWORD> field as well
 *                      (and perhaps BUTTON and SUBMIT also, but I do not see
 *                      any usage) - added by Jakub, 28.1.2003
 * @param string $placeholder
 * @param string $pattern
 * @return string
 */
function getFrmText($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $placeholder='', $pattern=''): string {
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, "text", $placeholder, $pattern);
    return $input->get_result();
}

/** Prints html tag <input type=text .. to 2-column table  for use within <form> and <table> tag.
 * @see getFrmText
 */
function FrmInputText($name, $txt, $val, $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $html=false, $placeholder='', $pattern='') {
    echo getFrmText($name, $txt, $val, $maxsize, $size, $needed, $hlp, $morehlp, $html, $placeholder, $pattern);
}

/** Prints password input box
 * @param        $name
 * @param        $txt
 * @param        $val
 */
function FrmInputPwd($name, $txt, $val) {
    $input = new AA_Inputfield($val, false, 'normal', $name, $txt, false, true);
    $input->inputText(50, 50, 'password');
    echo $input->get_result();
}

/** Prints password input box
 * @param        $name
 * @param        $txt
 * @param        $val
 */
function FrmInputEmail($name, $txt, $val,  $maxsize=254, $size=25, $needed=false, $hlp="", $morehlp="", $placeholder='') {
    $input = new AA_Inputfield($val, false, 'normal', $name, $txt, false, $needed, $hlp, $morehlp);
    $input->inputText($maxsize, $size, 'email', $placeholder);
    echo $input->get_result();
}


/** Gets html tag <input type=text .. to 2-column table for use within <form> and <table> tag.
 * @param        $name
 * @param        $txt
 * @param        $val
 * @param int    $min
 * @param int    $max
 * @param bool   $needed
 * @param string $hlp
 * @param string $morehlp
 * @param string $placeholder
 */
function getFrmNumber($name, $txt, $val, $min=0, $max=9999, $needed=false, $hlp="", $morehlp="", $placeholder=''): string {
    $input = new AA_Inputfield($val, true, 'normal', $name, $txt, false, $needed, $hlp, $morehlp);
    $input->setValidator(new AA_Validate_Number(['min'=>$min, 'max'=>$max]));
    $input->inputText((int)log10($max)+1, (int)log10($max)+4, 'number', $placeholder);  // size must be bigger to show also arrows
    return $input->get_result();

    /* concept of new widget approach - not working - uses aa[][...]
    //                     ($id='', $name='', $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perm=null, $default=null, $translations=null) {  // default values are needed for AA_Storable's construction
    $aa_property = new AA_Property($name, $txt, 'int', false, false, null, $needed, $hlp, $morehlp);
    $widget = $aa_property->defaultWidget();
    echo $widget->stdFormStart($aa_property);
    echo $widget->getOnlyHtml($aa_property, (new AA_Content())->setValue($name,$val));
    echo $widget->stdFormEnd($aa_property);
    */
}

/** Prints html tag <input type=text .. to 2-column table for use within <form> and <table> tag.
 * @see FrmInputNumber
 */
function FrmInputNumber($name, $txt, $val, $min=0, $max=9999, $needed=false, $hlp="", $morehlp="", $placeholder='') {
    echo getFrmNumber($name, $txt, $val, $min, $max, $needed, $hlp, $morehlp, $placeholder);
}

/** FrmStaticText function
 *  Prints two static text to 2-column table for use within <table> tag
 * @param $txt
 * @param $val
 * @param string $hlp
 * @param string $morehlp
 * @param bool $safing
 */
function FrmStaticText($txt, $val, $hlp = "", $morehlp = "", $safing = true) {
    $input = new AA_Inputfield($val, false, 'normal', '', $txt, '', false, $hlp, $morehlp);
    $input->staticText($safing);
    echo $input->get_result();
}

/** FrmHidden function
 *  Prints html tag <input type=hidden .. to 2-column table
 *  for use within <form> and <table> tag
 * @param string $name
 * @param string $val
 * @param bool $safing
 */
function FrmHidden($name, $val, $safing=true ) {
    $input = new AA_Inputfield($val, false, 'normal', $name);
    $input->hidden($safing);
    echo $input->get_result();
}

/** FrmTextarea function
 *  Prints html tag <textarea .. to 2-column table
 *  for use within <form> and <table> tag
 * @param        $name
 * @param        $txt
 * @param        $val
 * @param int    $rows
 * @param int    $cols
 * @param bool   $needed
 * @param string $hlp
 * @param string $morehlp
 * @param bool   $single
 * @param bool   $showrich_href
 * @param int    $maxlength
 */
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false, $hlp="", $morehlp="", $single=false, $showrich_href=false, $maxlength=0) {
    $html  = false;   // it was in parameter, but was never used in the code /honzam 05/15/2004
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    if ($showrich_href) {
        $input->html_rb_show = true;
    }
    $input->textarea($rows, $cols, $single, $showrich_href, false, $maxlength);
    echo $input->get_result();
}

/** FrmDate function
 * @param $name
 * @param $txt
 * @param $val
 * @param $needed
 * @param $hlp
 * @param $morehlp
 * @param $display_time  - 0 - no
 *                         1 - hour:minutes:seconds
 *                         2 - hour:minutes - if time is 00:00, it shows nothing
 *                         3 - hour:minutes
 */
function FrmDate($name, $txt, $val, $needed=false, $hlp="", $morehlp="", $display_time=0) {
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->dateSelect(7, 1, true, $display_time);
    echo $input->get_result();
}

/** returnsPrints html tag <select multiple .. to 2-column table for use within <form> and <table> tag
 * @param        $name
 * @param        $txt
 * @param        $arr
 * @param string $selected
 * @param int    $rows
 * @param bool   $needed
 * @param string $hlp
 * @param string $morehlp
 */
function getFrmInputMultiSelect($name, $txt, $arr, $selected="", $rows=5, $needed=false, $hlp="", $morehlp=""): string {
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputMultiSelect($rows);
    return $input->get_result();
}

/** FrmInputMultiSelect function Prints html tag <select multiple .. to 2-column table for use within <form> and <table> tag
 * @see getFrmText
 */
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $rows=5, $needed=false, $hlp="", $morehlp="") {
    echo getFrmInputMultiSelect($name, $txt, $arr, $selected, $rows, $needed, $hlp, $morehlp);
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
        $html  = false;   // in parameter, but never filled. Honza 24.4.2013
        $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
        $input->inputRadio($ncols, $move_right);
        echo $input->get_result();
    }


/** FrmInputMultiText function - Multiple Text Field Widget
 *  Prints html tag <input .. to 2-column table for use within <form> and <table> tag
 * @param        $name
 * @param        $txt
 * @param        $arr
 * @param string $selected
 * @param int    $rows
 * @param bool   $needed
 * @param string $hlp
 * @param string $morehlp
 * @param string $actions
 */
function FrmInputMultiText($name, $txt, $arr, $selected="", $rows=5, $needed=false, $hlp="", $morehlp="", $actions='MDAC') {
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputRelation($rows, '', '', '', $actions);
    echo $input->get_result();
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
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($selected, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputSelect($usevalue);
    echo $input->get_result();
}

/** FrmInputFile function
 *  Prints html tag <input type=file .. to 2-column table
 *  for use within <form> and <table> tag
 * @param        $name
 * @param        $txt
 * @param        $val
 * @param bool   $needed
 * @param string $accepts
 * @param string $hlp
 * @param string $morehlp
 */
function FrmInputFile($name, $txt, $val, $needed=false, $accepts="image/*", $hlp="", $morehlp="" ) {
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp);
    $input->inputFile($accepts);
    echo $input->get_result();
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
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $input = new AA_Inputfield($val, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->inputPreSelect($maxsize,$size,$adding,$secondfield,$usevalue);
    echo $input->get_result();
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
    $html  = false;   // in parameter, but never filled. Honza 24.4.2013
    $add   = false;   // in parameter, but never filled. Honza 24.4.2013
    $sel = [];
    if (is_array($selected)) {
        foreach($selected as $val) {
            $sel[] = ['value'=>$val];
        }
    }
    // $val is not used - there is only from historical reasons and should be removed accross files
    $input = new AA_Inputfield($sel, $html, 'normal', $name, $txt, $add, $needed, $hlp, $morehlp, $arr);
    $input->twoBox($rows,$wi2_offer,$wi2_selected);
    echo $input->get_result();
}

/** PrintHelp function
 * if $txt, shows help message
 * @param $txt string
 */
function PrintHelp( $txt ) {
    if ( $txt ) {
        echo "<div class=\"tabhlp\">$txt</div>";
    }
}

/** FrmChBoxEasy function
 * Prints html tag <input type=checkbox
 * @param        $name
 * @param bool   $checked
 * @param string $add
 * @param string $value
 * @param string $id
 */
function FrmChBoxEasy($name, $checked=true, $add="", $value='', $id='') {
    echo getFrmChBoxEasy($name, $checked, $add, $value, $id);
}

/** getFrmChBoxEasy function
 * @param        $name
 * @param bool   $checked
 * @param string $add
 * @param string $value
 * @param string $id
 * @return string
 */
function getFrmChBoxEasy($name, $checked=true, $add="", $value='', $id=''): string {
  $name  = safe($name);
  $value = safe($value); // $add=safe($add); NO!!

  return "<input type=\"checkbox\" name=\"$name\" $add".
    ($id      ? " id=\"$id\"" : '').
    ($value   ? " value=\"$value\"" : '').
    ($checked ? " checked>" : ">");
}

/** FrmSelectEasy function
 * Prints html tag <select ..
 * @param $name
 * @param $arr
 * @param $selected
 * @param array $add
 */
function FrmSelectEasy($name, $arr, $selected="", array $add=[]) {
    echo getFrmSelectEasy($name, $arr, $selected, $add);
}

/** getFrmSelectEasy function
 * @param        $name
 * @param        $arr
 * @param string $selected
 * @param array  $add
 * @param bool   $showempty - display select even if is empty (for two boxes of user admin... )
 * @return string
 */
function getFrmSelectEasy($name, $arr, $selected="", array $add= [], $showempty=false) {
    $name=safe($name); // safe($add) - NO! - do not safe it

    if ((!is_array($arr) OR empty($arr)) AND !$showempty) {
        return '';
    }
    if (is_array($add)) {
        $add = htmlattr($add);
    }
    $retval       = "\n<select name=\"$name\" $add>";
    $selectedused = false;
    foreach ((array)$arr as $k => $v) {
        if (0===strpos($k,'AA_OPTGROUP')) {  // begins with AA_OPTGROUP
            $retval .= "\n<optgroup label=\"".safe($v).'">';
            continue;
        }
        $retval .= "\n <option value=\"". safe($k)."\"";
        if ((string)$selected == (string)$k) {
            $retval .= ' selected class="sel_on"';
            $selectedused = true;
        }
        $title = '';
        $val = is_array($v) ? $v['name'] : $v;
        if (strlen($val)>2) {                   // we do not want to strip '<', '<=' and '<>' for operator selection
            $val = strip_tags($val);
        }
        if (strlen($val) > 100) {
            $title = ' title="'. safe($val) .'"';
            $val   = AA_Stringexpand_Shorten::shorten_middle($val, 100);
        }
        $retval .= "$title>". safe($val);
    }

    // now add all values, which is not in the array, but field has this value
    if ($selected AND !$selectedused) {
        $retval .= "\n <option value=\"". safe($selected) ."\" selected class=\"sel_missing\">".safe($selected);
    }

    $retval .= "</select>\n";
    return $retval;
}

/**
 * @param string $outer_add  - if contains class="aa-table", then the newer design is used
 * @return string
 */
function getTabStart($outer_add='') {
    //return (strpos($add, 'aa-table')!==false) ? "<table $add>" : '<table bgcolor="'. COLOR_TABTITBG .'" '.$add.'>';
    return "<div $outer_add>";  // @todo convert to htmltag()
}


/** FrmTabCaption function
 * Prints start of form table with caption and possibly additional tags (classes) to tables
 * @param string $caption
 * @param string $buttons
 * @param string $outer_add
 * @param bool   $simple no table stripping
 * @return string
 */
function getFrmTabCaption($caption = '', $buttons = '', $outer_add = '', $simple=false) {
    $ret = getTabStart($outer_add);
    if ($buttons) {
        $ret .= getFrmInputButtons($buttons, true, 'top');
    }
    if ($caption != "") {
        $ret .= "\n <div class=\"aa-tab-title\">$caption</div>";
    }
    $table_add_class = $simple ? ' aa-table-simple' : '';
    $ret .= "\n<table class=\"aa-table$table_add_class\">";
    return $ret;
}

function FrmTabCaption($caption = '', $buttons = '', $simple=false) {
    echo getFrmTabCaption($caption, $buttons, 'data-aa-class=clasic', $simple);
}

/**
 * @param      $subtitle
 * @param bool $simple no table stripping
 * @return void
 */
function FrmTabSeparator($subtitle, bool $simple=false) {
    echo getFrmTabSeparator($subtitle, $simple);
}

/** returns middle row with subtitle into form table
 * @param        $subtitle
 * @param bool   $simple no table stripping
 * @return string
 */
function getFrmTabSeparator($subtitle, bool $simple=false): string {
    $ret = "\n </table>";
    if ( $subtitle ) {
        $ret .= "\n <div class=\"aa-tab-title\">$subtitle</div>";
    }
    $table_add_class = $simple ? ' aa-table-simple' : '';
    $ret .= "\n<table class=\"aa-table$table_add_class\">";
    return $ret;
}

/** returns middle row with subtitle into the same form table
 * @param        $subtitle
 */
function getFrmTabInnerSeparator($subtitle): string {
    return "\n <tr><td class=\"aa-tab-title\" colspan=2>$subtitle</td></tr>";  // could be changer from 2 to something like 9999
}


/** getFrmTabEnd function
 * Prints form table end with buttons (@see FrmInputButtons)
 * @param bool $buttons
 * @return string
 */
function getFrmTabEnd( $buttons=false ) {
    $ret = "\n</table>";
    if ( $buttons ) {
        $ret .= getFrmInputButtons($buttons, true, 'bottom');
    }
    $ret .= "\n</div>";
    return $ret;
}

function FrmTabEnd( $buttons=false ) {
    echo getFrmTabEnd($buttons);
}


/** returns buttons based on $buttons array. It also adds slice_id
 * Maybe better is to use (@param $buttons
 * @param string $bgcolor
 * @param bool   $print_hidden - if false, prints all $buttons except the hidden fields
 * @param string $prefix       - prefix for button ids
 * @return string
 *@see FrmTabEnd())
 */
function getFrmInputButtons($buttons, $print_hidden = true, $prefix = ''): string {
    if ( !$buttons ) {
        return '';
    }

    $sticky = ($prefix=='top') ? ' style="position: sticky; top: 0; z-index: 5;"' : ''; //z-index - CodeMirror goes over if not > 2
    $ret = "<div class=aa-tab-buttons $sticky>";

    if ($prefix) {
        $prefix .= '_';
    }
    $noaccess = false;

    foreach ( $buttons as  $name => $properties ) {
        if ( !is_array($properties) ) {
            $name = $properties;
            $properties = [];
        }
        $title = $properties['title'] ? ' title="'.$properties['title'].'"' : '';
        switch($name) {
            case 'update':
                if ($properties['type'] == 'hidden') {
                    $ret .= ' <input type="hidden" name="update" id="'.$prefix .'update"'.$title.' value="'. get_if($properties['value'], "") .'">';
                } else {
                    $ret .= ' <input type="submit" class="btn-l" name="update" id="'.$prefix .'update"'.$title.' accesskey="S" value=" '. get_if($properties['value'], _m("Update")) ." [^S] " .' ">';
                    $noaccess = true; // use for update of item, bug was, that both "update" and "insert" has accesskey S
                }
                break;
            case 'insert':
                $ret .= ' <input type="submit" class="btn-l" name="insert"'.$title.' id="'.$prefix .'insert" ';
                if (!$noaccess) { // was accesskey used ?
                    $ret .= 'accesskey="S" ';
                }
                $ret .= 'value=" '. get_if($properties['value'], _m("Insert")) ." ";
                if (!$noaccess) {
                    $ret .= " [^S]";
                }
                $ret .= '  "> ';
                break;
            case 'cancel':
                $url = get_if($properties['url'],self_server().$_SERVER['PHP_SELF']);
                if (!$properties['url']) {
                    $url = con_url($url,'cancel=1');
                }
                $url  = StateUrl($url);
                //          echo ' <input type="button" name="cancel" value=" '. get_if($properties['value'], _m("Cancel")) .' "> ';
                $ret .= ' <input type="button" name="cancel" id="'.$prefix .'cancel"'.$title.' value=" '. get_if($properties['value'], _m("Cancel")) .' " class="btn-l btn-secondary" onclick="window.location=\''.$url.'\'"> ';
                break;
            case 'close':
                $ret .= ' <input type="button" name="cancel" id="'.$prefix .'cancel"'.$title.' value=" '. get_if($properties['value'], _m("Cancel")) .' " class="btn-l btn-secondary" onclick="window.close();"> ';
                break;
            case 'reset':
                $ret .= ' <input type="reset" id="'.$prefix .'reset"'.$title.' value=" '. _m("Reset form") .' " class="btn-l btn-secondary"> ';
                break;
            case 'submit': // name is not submit because it breaks document.f.submit() function call
                $ret .= ' <input type="submit" class="btn-l" name="submitbtn"'.$title.' id="'.$prefix .'submit" accesskey="S" value=" '. get_if($properties['value'], _m("Submit")) ."  [^S] ". ' "> ';
                break;
            default:
                if ($properties['js']) {
                    $type    = ($properties['type'] ?: 'button');
                    $onclick = ' onclick="'.$properties['js'].'"';
                } elseif ($properties['url']) {
                    $type = ($properties['type'] ?: 'button');
                    $onclick = ' onclick="window.location=\''. StateUrl($properties['url']).'\'"';
                } else {
                    $type = ($properties['type'] ?: 'hidden');
                    $onclick = '';
                }

                if ( !$print_hidden AND ($type == 'hidden') ) {
                    // do not print hidden fields if print_hidden is false
                    // (used for FrmTabSeparator, to not duplicate hiddens)
                    break;
                }
                $nbsp = ( $type == 'hidden' ) ? '' : ' ';
                $ret .= $nbsp.'<input type="'.  $type .'"'.$onclick .
                      ' name="'.  $name .
                     '" id="'.  $prefix . $name .
                     '" value="'. $properties['value'] . ($properties['accesskey'] ? "  [^".$properties['accesskey']."]  " : "").
                     '" '.($properties['accesskey'] ? 'accesskey="'.$properties['accesskey'].'" ' : ""). $properties['add'] . ' class="btn-l btn-secondary"'.$title.'>'.$nbsp;
        }
        $ret .= "\n";
    }

    if ($print_hidden) {
        $ret .= StateHidden();
    }
    $ret .= "</div>";
    return $ret;
}

/** getFrmTabRow function
 * @param        $row
 * @param string $mode
 * @return string
 */
function getFrmTabRow( $row, $mode='td', $attrs=[]) {
    $ret = '';
    $add = $attrs ? ' '.htmlattr($attrs) : '';
    if ( isset($row) AND is_array($row) ) {
        $ret .= "\n <tr$add>";
        foreach ( $row as $col ) {
            $ret .= ( !is_array($col) ? "<$mode>$col</$mode>" : "<$mode " .$col['attr']. '>'. $col['text'] ."</$mode>");
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
 * @return string
 */
function getFrmTabs( $tabs, $tabsId ) {
    if ( isset($tabs) AND is_array($tabs) ) {
        $ret = "\n <tr id=\"$tabsId\"><td colspan=\"2\" class=\"tabsrow\">";
        $non = '';
        foreach ( $tabs as $class => $name ) {
            $ret .= "<a href=\"javascript:TabWidgetToggle('$class')\" id=\"$tabsId$class\" class=\"tabs{$non}activ\">$name</a>";
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
 * @param array[] $content
 * @param string   $mode = td | th | key
 * @param string   $caption
 * @param bool     $clasic
 * @return string
 */
function GetHtmlTable( array $content, $mode='td', $caption='', $clasic=false ) {
    if ($mode == 'key') {
        array_unshift($content,array_keys(reset($content)));
        $mode = 'th';
    }
    $ret = getFrmTabCaption($caption, [], $clasic ? 'data-aa-class=clasic' : '');
    $first = true;
    foreach ($content as $row) {
        if ($mode=='th' AND $first) {
            $ret .= getFrmTabRow( $row , 'th', ['class'=>'aa-tbl-header']);
        } else {
            $ret .= getFrmTabRow($row);
        }
        $first = false;
    }
    $ret .= getFrmTabEnd();
    return  $ret;
}

/** getFrmJavascriptFile function
 * @param        $src
 * @param string $add
 * @return string
 */
function getFrmJavascriptFile( $src, $add='') {
    return "\n <script $add src=\"". safe(get_aa_url($src, '', false)) . "\"></script>";
}

/** getFrmJavascript function
 * @param      $jscode
 * @param bool $onload
 * @return string
 */
function getFrmJavascript($jscode , bool $onload=false) {
    if ($onload) {
        $jscode = "document.addEventListener('DOMContentLoaded', function() { $jscode });";
    }
    return "\n<script>\n  $jscode \n</script>\n";
}

/** getFrmJavascriptCached function
 *  Stores the javascript to the dababase cache in order we can call this
 *  javascript as external file.
 *  The idea of this is: External js files are cached by the browser so it is
 *  better to store the js code in the database, assign an ID to this record
 *  (=keystr) and then call it as external file with this ID as parameter
 * @param        $jscode
 * @param        $name
 * @param string $loadtype
 * @return string
 */
function getFrmJavascriptCached( $jscode, $name, $loadtype = 'async defer' ) {
    $key  = get_hash('jscache', $jscode);

    if (!AA::Pagecache()->getById($key)) {     // not in cache, yet
        $str2find = new CacheStr2find([$name], 'js');
        AA::Pagecache()->store($key, $jscode, $str2find, true);
    }
    return getFrmJavascriptFile( 'cached.php3?keystr='.$key, $loadtype );
}

/** getFrmCSS function
 * @param $stylecode
 * @return string
 */
function getFrmCSS( $stylecode ) {
    return "\n<style>\n  $stylecode \n</style>\n";
}
/** FrmJavascript function
 * @param $jscode
 */
function FrmJavascript( $jscode ) {
    echo getFrmJavascript( $jscode );
}

/** FrmJavascriptFile function
 * @param        $src
 * @param string $add
 */
function FrmJavascriptFile( $src, $add='' ) {
    echo getFrmJavascriptFile( $src, $add );
}
/** FrmJavascriptCached function
 * @param $jscode
 * @param $name
 */
function FrmJavascriptCached( $jscode, $name ) {
    echo getFrmJavascriptCached( $jscode, $name );
}

/** IncludeManagerJavascript function
 */
function IncludeManagerJavascript() {
    FrmJavascriptFile( 'javascript/aajslib-legacy.min.js' );
    FrmJavascriptFile( 'javascript/manager.min.js' );
}

/** getRadioBookmarkRow function
 *  returns one row with one radiobutton - asociated to bookmark (stored search)
 *  or item list
 * @param string $selected - value
 * @param      $name - dislpayed name of this option
 * @param      $count
 * @param      $value - value for this option
 * @param      $list_type - items preview type ('items' | 'users') @see usershow.php
 * @param      $list_text
 * @param bool $safe - escape html entities in name?
 * @param null $bookmark_id
 * @return string
 */
function getRadioBookmarkRow( $selected, $name, $count, $value, $list_type, $list_text, $safe=true, $bookmark_id=null) {
    global $slice_id;

    static $checked = ' checked';  // mark first option when no $group selected

    if ( strlen($selected) ) {
        $checked = (((string)$selected == (string)$value) ? ' checked' : '');
    }

    if ( $safe ) {
        $name = safe($name);
    }
    $out = "
    <tr>
      <td><input type=radio name=group value=\"$value\" $checked></td>";

    $out .= ((string)$value == "testuser") ? "<td colspan=8>" : "<td>";

    $out .= "$name</td><td>$count</td>";
    // get data about bookmark (who created, last used, ...)
    if (!is_null($bookmark_id)) {
        if ($event = AA_Log::getLastLogEvents("BM_CREATE", $bookmark_id)) {
            $out .= "<td>". date("j.n.Y G:i:s",$event['time']). "</td><td>". perm_username($event['user']) . "</td>";
        } else {
            $out .= "<td></td><td></td>";
        }
        if ($event  = AA_Log::getLastLogEvents("BM_UPDATE", $bookmark_id)) {
            $out .= "<td>". date("j.n.Y G:i:s",$event['time']). "</td><td>". perm_username($event['user']) . "</td>";
        } else {
            $out .= "<td></td><td></td>";
        }
        if ($event  = AA_Log::getLastLogEvents("EMAIL_SENT", (string)$value)) {
            $out .= "<td>". date("j.n.Y G:i:s",$event['time']). "</td>";
        } else {
            $out .= "<td>". _m('Not used, yet'). "</td>";
        }
    } else {
        $out .= "<td colspan=\"5\"></td>";
    }
    $out .= "<td>";
    if ((string)$value != (string)"testuser") {
        $grp = $value;
        $js = "OpenUsershowPopup('".get_admin_url("usershow.php")."&slice_id=$slice_id&group=$grp&type=$list_type')";
        $out .= "<a class=btn href=\"javascript:$js;\">$list_text</a>";
    }
    $out .=  "</td>
    </tr>";
    $checked = '';  // static variable
    return $out;
}

/** FrmItemGroupSelect function
 *   Allows select items group (used for bulk e-mails as well as for Find&Replace)
 *  @param array $items - array of item ids with 'x' prefix
 *  @param AA_Searchbar $searchbar
 *  @param string $selected - selected group
 *  @param string $list_type - items preview type ('items' | 'users') @see usershow.php
 *  @param array  $messages - $messages['view_items']     = _m("View Recipients")
 *                            messages['selected_items'] = _m('Selected users')
 *  @param array additional = array( 'text' => 'Test', 'varname'=>'testuser');
 */
function FrmItemGroupSelect( $items, $searchbar, $selected, $list_type, $messages, $additional=[]) {
    $out = '';
    if (is_array($items) AND count($items)) {
        $out = getRadioBookmarkRow( $selected, $messages['selected_items'], count($items), 'sel_item', $list_type, $messages['view_items']);
    } elseif ( isset($searchbar) AND is_object($searchbar) ) {
        $book_arr = $searchbar->getBookmarkNames();
        if ( count($book_arr) ) {
            $out .= "<tr>
                       <th>&nbsp;</th>
                       <th>". _m("Group Name"). "</th>
                       <th>". _m("Count").      "</th>
                       <th>". _m("Created on"). "</th>
                       <th>". _m("Created by"). "</th>
                       <th>". _m("Last updated") ."</th>
                       <th>". _m("Updated by") ."</th>
                       <th>". _m("Last used"). "</th>
                     </tr>";
            foreach ( $book_arr as $k => $v ) {
                $bookparams = $searchbar->getBookmarkParams($k);
                $out .= getRadioBookmarkRow( $selected, $v, count(getZidsFromGroupSelect($k, $items, $searchbar)), $k, $list_type, $messages['view_items'], true, is_array($bookparams) ? $bookparams['id'] : null);
            }
        }
        $out .= getRadioBookmarkRow( $selected, _m('All active items'),         count(getZidsFromGroupSelect('AA_BIN_ACTIVE' , $items, $searchbar)), 'AA_BIN_ACTIVE', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( $selected, _m('All items'),                count(getZidsFromGroupSelect('AA_ALL'        , $items, $searchbar)), 'AA_ALL', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( $selected, _m('All pending items'),        count(getZidsFromGroupSelect('AA_BIN_PENDING', $items, $searchbar)), 'AA_BIN_PENDING', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( $selected, _m('All expired items'),        count(getZidsFromGroupSelect('AA_BIN_EXPIRED', $items, $searchbar)), 'AA_BIN_EXPIRED', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( $selected, _m('All items in holding bin'), count(getZidsFromGroupSelect('AA_BIN_HOLDING', $items, $searchbar)), 'AA_BIN_HOLDING', $list_type, $messages['view_items']);
        $out .= getRadioBookmarkRow( $selected, _m('All items in trash bin'),   count(getZidsFromGroupSelect('AA_BIN_TRASH'  , $items, $searchbar)), 'AA_BIN_TRASH', $list_type, $messages['view_items']);
    }
    // aditional group (test one, for examle)
    foreach ( $additional as $row ) {
        $out .= getRadioBookmarkRow( $selected, $row['text'], '', $row['varname'], $list_type, $messages['view_items'], false);
    }
    echo $out;
}

/** getZidsFromGroupSelect function
 *  Returns zids according to user selection of FrmItemGroupSelect
 * @param              $group
 * @param array        $items
 * @param AA_Searchbar $searchbar
 * @return zids
 */
function getZidsFromGroupSelect($group, $items, $searchbar) {
    global $slice_id;
    if ( (string)$group == 'sel_item' ) {  // user specified users
        $zids = (new zids())->setFromItemArr($items);
    } else {                   // user defined by bookmark
        $conds = false;
        $sort  = false;
        $bins  = null;
        switch ((string)$group) {
            case 'AA_BIN_ACTIVE':  $bins = AA_BIN_ACTIVE;  break;
            case 'AA_ALL':         $bins = AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH;  break;
            case 'AA_BIN_PENDING': $bins = AA_BIN_PENDING; break;
            case 'AA_BIN_EXPIRED': $bins = AA_BIN_EXPIRED; break;
            case 'AA_BIN_HOLDING': $bins = AA_BIN_HOLDING; break;
            case 'AA_BIN_TRASH':   $bins = AA_BIN_TRASH;   break;
            default:
                if ( $searchbar->setFromBookmark($group) ) {
                    $set   = $searchbar->getSet();
                    $bins  = AA_BIN_ACTIVE;
                    $conds = $set->getConds();
                    $sort  = $set->getSort();
                }
        }
        $zids  = is_null($bins) ? new zids() : QueryZids( [$slice_id], $conds, $sort,  $bins);
    }
    return $zids;
}

/** FrmItemListForm function
 *  Lists selected items to special form - used by manager.min.js to show items
 * @param $items
 */
function FrmItemListForm($items) {
    $out = '<form name="itform" method="post">';
    if (is_array($items)) {
        foreach ($items as $key=> $it) {
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
 * @return string
 */
function getSelectWithParam($name, $arr, $selected="", $html_setting=null) {
    $add = ["onchange"=>"ShowThisTagClass('fswp$name', 'div', 'fswp_'+this.value, 'fswp_')"];
    $ret = getFrmSelectEasy($name, $arr, $selected, $add);
    if (isset($html_setting) AND is_array($html_setting)) {
        $ret .= "\n<div id=\"fswp$name\">";
        foreach($html_setting as $value => $html) {
            $ret .= "\n  <div class=\"fswp_$value\" style=\"display:none\">$html</div>";
        }
        $ret .= "\n</div>";
    }
    return $ret;
}

/**
 *  get alias names as help for fulltext and compact format page
 * @param AA_Aliases $aliases
 * @param bool       $fields
 * @param string     $buttons
 * @return string
 */
function getAliasHelp(AA_Aliases $aliases, $fields = false): string {
    $rows = [];
    foreach ($aliases as $ali => $v ) {
        // if it is possible point to alias editing page
        $fld     = $v->getField();
        $aliasedit = ( !$fld ? "&nbsp;" : "<a href=\"". con_url("./se_inputform.php", ['fid'=>$fld], true) ."\">". _m("Edit") . "</a>");
        if ($fields AND $fields[$fld] AND !$fields[$fld]['input_show']) {
            $ali = "<span class=\"disabled\">$ali</span>";
        }
        $rows[] = [$ali, safe($v->getHelp()), $aliasedit];
    }
    return GetHtmlTable($rows);
}
