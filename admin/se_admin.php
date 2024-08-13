<?php
/**
 *  se_admin.php - assigns html format for administation item view (index.php)
 *  optionally $Msg to show under <h1>Headline</h1> (typically: update successful)
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
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/item.php";     // GetAliasesFromField funct def

try {
    // array ( '<tr> <td…' => 1, '<tr class=t…' => 30, )
    $admin_format = InputVars::stringPOST('admin_format');
    // array ( '' => 19, '`@#$~^&*{}�…' => 2, 'Fň' => 4, 'Fň!%' => 2, 'Fň!%qwerty…' => 4, )
    $admin_format_bottom = InputVars::stringPOST('admin_format_bottom'); // empty
    // array ( '{version:sv…' => 1, '{iftype:hm@…' => 1, '{distance:5…' => 23, ' <tr cla…' => 1, ' <tr class…' => 1, '<tr class=t…' => 2, '{reverse:1-…' => 2, )
    $admin_format_top = InputVars::stringPOST('admin_format_top');
    // array ( '' => 25, 'NE!' => 1, 'NE!😩' => 1, 'NE!qwertyui…' => 4, )
    $admin_noitem_msg = InputVars::stringPOST('admin_noitem_msg'); // empty
    // array ( '' => 21, 'Fň' => 1, 'qwertyuiop' => 1, 'zxcvbnměš…' => 1, '🐵' => 1, 'Fň!%' => 6, )
    $admin_remove = InputVars::stringPOST('admin_remove'); // empty
    // array ( '' => 31, )
    $inputform_sel = InputVars::intPOST('inputform_sel'); // empty
    // array ( 'Přihlásit' => 2, )
    $submit = InputVars::boolPOST('submit');
    // array ( 1 => 24, ' Změnit [^…' => 7, )
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

const DEFAULT_ADMIN_TOP    = '<tr>\n  <th></th>\n  <th>www</th>\n  <th>name</th>\n</tr>';
const DEFAULT_ADMIN_HTML   = '<tr>\n  <td><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"></td>\n  <td><a href=\"_#SEO_URL_\">&nbsp;&raquo;&nbsp;</a></td>\n  <td><a href=\"_#EDITITEM\">_#HEADLINE</a><br><small>_#SEO_URL_</small></td>\n</tr>';
const DEFAULT_ADMIN_BOTTOM = '';
const DEFAULT_ADMIN_MSG    = '';

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_CONFIG)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have no permission to set configuration parameters of this slice"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

if ($update) {
    do {
        ValidateInput("admin_format_top",    _m("Top HTML"),                                $admin_format_top,    $err, false, "text");
        ValidateInput("admin_format",        _m("Item format"),                             $admin_format,        $err, true,  "text");
        ValidateInput("admin_format_bottom", _m("Bottom HTML"),                             $admin_format_bottom, $err, false, "text");
        ValidateInput("admin_remove",        _m("Remove strings"),                          $admin_remove,        $err, false, "text");
        ValidateInput("admin_noitem_msg",    _m("HTML code for \"No item found\" message"), $admin_noitem_msg,    $err, false, "text");
        ValidateInput("inputform_sel",       _m("Show discussion"),                         $inputform_sel,       $err, false,  "text");

        if ( count($err) ) {
            break;
        }

        $varset->add("admin_format_top",    "text", $admin_format_top);
        $varset->add("admin_format",        "text", $admin_format);
        $varset->add("admin_format_bottom", "text", $admin_format_bottom);
        $varset->add("admin_remove",        "text", $admin_remove);
        $varset->add("admin_noitem_msg",    "text", $admin_noitem_msg);
        if ( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() .
                         "WHERE id='".q_pack_id(AA::$module_id)."'")) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;    // not necessary - we have set the halt_on_error
        }

        AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        // set the
        AA_Profile::addProfileProperty('*', AA::$module_id, 'input_view', '', '', $inputform_sel, '');

    } while(false);

    if ( !count($err) ) {
        $Msg = MsgOk(_m("Admin fields update successful"));
    }
}

if ( AA::$module_id!="" ) {  // set variables from database
    $SQL = "SELECT admin_format, admin_format_top, admin_format_bottom,
                   admin_remove, admin_noitem_msg
            FROM slice WHERE id='". q_pack_id(AA::$module_id)."'";
    $db->query($SQL);
    if ($db->next_record()) {
        $admin_format_top    = $db->f('admin_format_top');
        $admin_format        = $db->f('admin_format');
        $admin_format_bottom = $db->f('admin_format_bottom');
        $admin_remove        = $db->f('admin_remove');
        $admin_noitem_msg    = $db->f('admin_noitem_msg');
    }

    $default_profile = AA_Profile::getProfile('*', AA::$module_id);
    $inputform_vid   = $default_profile->getProperty('input_view');
}

// lookup inputform views
$inputform_vids = DB_AA::select(['id'=>'name'], "SELECT id, name FROM view", [
    ['slice_id', AA::$module_id, 'l'],
    ['type', 'inputform']
]);

$script2run = ' 
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_top  = CodeMirror.fromTextArea(document.getElementById("admin_format_top"), opts);
   window.cm_code = CodeMirror.fromTextArea(document.getElementById("admin_format"), opts);
   window.cm_bot  = CodeMirror.fromTextArea(document.getElementById("admin_format_bottom"), opts);
   window.cm_msg  = CodeMirror.fromTextArea(document.getElementById("admin_noitem_msg"), opts);
   ';
$headcode = '<script>
  function Defaults() {
    window.cm_top.setValue(  "'. DEFAULT_ADMIN_TOP .'");
    window.cm_code.setValue( "'. DEFAULT_ADMIN_HTML.'");
    window.cm_bot.setValue(  "'. DEFAULT_ADMIN_BOTTOM.'");
    window.cm_msg.setValue(  "'. DEFAULT_ADMIN_MSG.'");
  }
</script>';

$apage = new AA_Adminpageutil('sliceadmin','config');
$apage->setTitle(_m("Admin - design Item Manager view"));
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire('codemirror@5');
$apage->printHead($err, $Msg);

$form_buttons = [
    "update" => ["type"=>"hidden", "value"=>"1"],
                      "update", "cancel"=> ["url"=>"se_fields.php"],
                      "defaults" => ["type"=>"button", "value"=> _m("Default"), "add"=>'onclick="Defaults()"']
];

FrmTabCaption(_m("Listing of items in Admin interface"), $form_buttons);

FrmTextarea("admin_format_top", _m("Top HTML"), $admin_format_top, 4, 60,
    false, _m("HTML code which appears at the top of slice area")
    . '<br>' . AA_View::getViewJumpLinks($admin_format_top), '', 1);
FrmTextarea("admin_format", _m("Item format"), $admin_format, 12, 60, true,
    _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
    . '<br>' . AA_View::getViewJumpLinks($admin_format), '', 1);
FrmTextarea("admin_format_bottom", _m("Bottom HTML"), $admin_format_bottom,
    4, 60, false, _m("HTML code which appears at the bottom of slice area")
    . '<br>' . AA_View::getViewJumpLinks($admin_format_bottom), '', 1);
FrmInputText("admin_remove", _m("Remove strings"), $admin_remove, 254, 50, false,
    _m("Removes empty brackets etc. Use ## as delimiter."));
FrmTextarea("admin_noitem_msg", _m("HTML code for \"No item found\" message"), $admin_noitem_msg,
    4, 60, false, _m("Code to be printed when no item is filled (or user have no permission to any item in the slice)")
    . '<br>' . AA_View::getViewJumpLinks($admin_noitem_msg), '', 1);
FrmInputSelect("inputform_sel", _m("Use special view"), $inputform_vids, $inputform_vid, false,
             _m("You can set special view - template for the Inputform on \"Design\" -> \"View\" page (inputform view)"));
$slice = AA_Slice::getModule(AA::$module_id);

FrmTabEnd($form_buttons);
$apage->addHelpbox(_m("Use these aliases for database fields"), getAliasHelp(new AA_Aliases(new AA_Aliasgenerator_Slice(AA::$module_id)), $slice->getFields()));
$apage->printFoot();
