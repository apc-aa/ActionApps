<?php
/**
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
// expected $slice_id for edit slice, no_slice_id=1 for adding slice

use AA\IO\DB\DB_AA;
use AA\Util\Langs;
use AA\Util\InputVars;

// it is message for init_page.php so it must be set here already
$no_slice_id = $_POST['no_slice_id'] ?? false;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/date.php";
require_once __DIR__ . "/../include/varset.php";
// add mlx functions
require_once __DIR__."/../include/mlx.php";
require_once __DIR__ . "/../include/slicedit.php";
require_once __DIR__ . "/../include/modutils.php";

try {
    // array ( )
    $aa = InputVars::arrayPOST('aa');
    // array ( 1 => 7, )
    $add = InputVars::boolPOST('add');
    // array ( 1 => 2, )
    $add_submit = InputVars::boolPOST('add_submit');
    // array ( '' => 1, )
    $auth_field_group = InputVars::stringPOST('auth_field_group'); // empty
    // array ( )
    $create = InputVars::arrayPOST('create');
    // array ( 'pppp' => 1, 10000 => 43, 99999 => 1, 11111 => 2, 22222 => 1, 33333 => 1, 29999 => 1, 30000 => 1, 30001 => 1, 31000 => 1, 32000 => 1, 33000 => 1, 32999 => 2, 5 => 32, 'H' => 1, '🐖' => 1, 36000 => 1, 15 => 2, )
    $d_listlen = InputVars::intPOST('d_listlen');
    // array ( 'on' => 19, )
    $deleted = InputVars::boolPOST('deleted'); // alpha
    // array ( '' => 61, 'ADMINISTRAT…' => 30, 0 => 3, )
    $fileman_access = InputVars::stringPOST('fileman_access'); // alnum, empty
    // array ( '' => 86, 38 => 1, 'B' => 2, 'Džusík s …' => 4, '🐸' => 1, )
    $fileman_dir = InputVars::stringPOST('fileman_dir'); // empty
    // array ( 'on' => 19, )
    $flag_allow_expired = InputVars::boolPOST('flag_allow_expired'); // alpha
    // array ( 'on' => 18, )
    $flag_allow_pending = InputVars::boolPOST('flag_allow_pending'); // alpha
    // array ( ' Vložit […' => 7, )
    $insert = InputVars::boolPOST('insert');
    // array ( 'cz-utf8_new…' => 79, 'fr_news_lan…' => 5, 'sk-utf8_new…' => 1, 'ru_news_lan…' => 2, 'de_news_lan…' => 1, 'es_news_lan…' => 1, 'es-utf8_new…' => 1, 'en_news_lan…' => 1, 'cz_news_lan…' => 2, 'hr_news_lan…' => 1, )
    $lang_file = InputVars::stringPOST('lang_file');
    // array ( '' => 1, )
    $mailman_field_lists = InputVars::stringPOST('mailman_field_lists'); // empty
    // array ( '' => 58, '4429a8e44bb…' => 32, )
    $mlxctrl = InputVars::idPOST('mlxctrl'); // empty
    // array ( 'adamsbeauty…' => 1, 'testtesttes…' => 1, ' …' => 1, 'test' => 25, 'test2' => 3, 'test 3' => 1, 5 => 49, 'Džusík s …' => 4, 'testos' => 1, 'News (EN) T…' => 1, 'Test2 was i…' => 1, 'Naštvanej …' => 2, 'test2 win12…' => 4, )
    $name = InputVars::stringPOST('name');
    // array ( 'Jeď: Přid…' => 2, )
    $no_slice = InputVars::boolGETPOST('no_slice');
    // array ( 1 => 14, )
    // $no_slice_id = InputVars::boolPOST('no_slice_id');  // already set above for init_page.php
    // array ( 'b6849ffd5ce…' => 90, '41415f436f7…' => 2, '96ab12bbeae…' => 2, )
    $owner = InputVars::idPOST('owner');
    $new_owner = InputVars::stringPOST('new_owner');
    $new_owner_email = InputVars::stringPOST('new_owner_email');
    // array ( 0 => 93, '' => 1, )
    $permit_anonymous_edit = InputVars::intPOST('permit_anonymous_edit'); // empty
    // array ( 0 => 4, 1 => 89, 2 => 1, )
    $permit_anonymous_post = InputVars::intPOST('permit_anonymous_post');
    // array ( 1 => 89, 0 => 4, 2 => 1, )
    $permit_offline_fill = InputVars::intPOST('permit_offline_fill');
    // array ( 0 => 76, '' => 2, 44444 => 8, 99999 => 3, 'aaaa' => 1, 'Fň' => 1, 'I am ' => 1, 'hfdkd' => 1, 66666 => 1, )
    $priority = InputVars::intPOST('priority'); // empty
    // array ( '' => 92, 'asdfghjkl"_…' => 1, 'asdfghjkl\\"…' => 1, )
    $reading_password = InputVars::stringPOST('reading_password'); // empty
    // array ( 'https://ada…' => 2, '' => 87, 'www' => 1, 'B' => 1, 3 => 1, 'Kvakoš 765…' => 1, 'He said tha…' => 1, )
    $slice_url  = InputVars::stringPOST('slice_url'); // empty
    $slice_type = InputVars::stringPOST('slice_type'); // empty
    // array ( 'on' => 18, )
    $template = InputVars::arrayPOST('template'); // alnum
    // array ( 'Přidat' => 3, )
    $template_from_slice = InputVars::boolPOST('template_from_slice');
    // array ( 'Přidat' => 1, )
    $template_from_template = InputVars::boolPOST('template_from_template');
    // array ( '011ba695b78…' => 2, '4085d9fe61e…' => 2, '4e6577735f4…' => 2, '52656164657…' => 4, '4429a8e44bb…' => 3, '6e6f7469636…' => 1, )
    $template_id = InputVars::idPOST('template_id');
    // array ( '4085d9fe61e…' => 2, '6c81ec28dd4…' => 1, '4429a8e44bb…' => 4, )
    $template_id2 = InputVars::idPOST('template_id2');
    // array ( 'slice' => 2, )
    $template_slice_radio = InputVars::stringPOST('template_slice_radio'); // alpha
    // array ( 1 => 2, )
    $um_uedit_no_go_url = InputVars::boolPOST('um_uedit_no_go_url');
    // array ( ' Změnit [^…' => 79, ' Mise � j…' => 3, ' Zmeniť [^…' => 1, ' Update [^S…' => 1, ' Aktualisie…' => 1, ' Zm�nit […' => 1, ' Obnovi [^S…' => 1, )
    $update = InputVars::boolPOST('update');
    // array ( 'She was a l…' => 1, 'Největší ' => 1, )
    $user_firstname = InputVars::stringPOST('user_firstname');
    // array ( '' => 6, 'He said tha…' => 2, 'Největší…' => 1, )
    $user_login = InputVars::stringPOST('user_login'); // empty
    // array ( 'Myš@krtek.…' => 2, )
    $user_mail1 = InputVars::stringPOST('user_mail1');
    // array ( 'Jsem krtek!' => 1, 'Kachna85?!' => 1, )
    $user_password1 = InputVars::stringPOST('user_password1');
    // array ( 'Jsem krtek!' => 1, 'Kachna85?!' => 1, )
    $user_password2 = InputVars::stringPOST('user_password2');
    // array ( '' => 6, 'ADMINISTRAT…' => 3, )
    $user_role = InputVars::stringPOST('user_role'); // alpha, empty
    // array ( 'I am not go…' => 1, 'Borec' => 1, )
    $user_surname = InputVars::stringPOST('user_surname');
    // array ( )
    $wiz = InputVars::arrayPOST('wiz');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

define ('PERMS_STATE', [
    "0" => _m("Not allowed"),
    "1" => _m("Active"),
    "2" => _m("Hold bin")
]);

define ('PERMS_ANONYMOUS_EDIT', [
    ANONYMOUS_EDIT_NOT_ALLOWED      => _m("Not allowed"),
    ANONYMOUS_EDIT_ALL              => _m("All items"),
    ANONYMOUS_EDIT_ONLY_ANONYMOUS   => _m("Only items posted anonymously"),
    ANONYMOUS_EDIT_NOT_EDITED_IN_AA => _m("-\"- and not edited in AA"),
    ANONYMOUS_EDIT_PASSWORD         => _m("Authorized by a password field"),
    ANONYMOUS_EDIT_HTTP_AUTH        => _m("Readers, authorized by HTTP auth"),
    ANONYMOUS_EDIT_CRON             => _m("Only by Planned tasks (cron-toexecute)")
]);

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

// Create new module
// If we want to create new site, then $_POST['create']['W'] is set and $_POST['template']['W'] contains template site id

if (is_array($create = $_POST['create'])) {
    reset($create);
    if ($type = key($create)) {
        $url = StateUrl(AA_INSTAL_PATH. $MODULES[$type]["directory"] . "modedit.php?no_slice_id=1");
        if ( $_POST['template'][$type] ) {
            $url = get_url( $url, "template%5B$type%5D=". $_POST['template'][$type]);
        }
        go_url( $url );
    }
}

//foreach ($MODULES as $type => $module) {
//     if ($create[$type]) {
//         $url = StateUrl(AA_INSTAL_PATH. $module["directory"] . "modedit.php?no_slice_id=1");
//         if ( $template[$type] ) {
//             $url = get_url( $url, "template%5B$type%5D=". $template[$type]);
//         }
//         go_url( $url );
//     }
//}

$err = [];          // error array (Init - just for initializing variable
$superadmin  = IsSuperadmin();

$set_template_id = $_POST['template_from_slice'] ? $_POST['template_id2'] : $_POST['template_id'];

$err = SliceEditProcess($slice_id, $user_firstname, $user_surname, $err, $add_submit, $user_login, $user_password1, $user_password2, $user_mail1, $user_mail2, $user_mail3, $user_super, $um_uedit_no_go_url, $selected_user, $posted_groups, $sel_groups, $perm_mod, $new_module, $new_module_role, $add, $update, $owner, $new_owner, $new_owner_email, $name, $slice_url, $slice_type, $upload_url, $priority, $permit_anonymous_post, $permit_anonymous_edit, $permit_offline_fill, $lang_file, $d_listlen, $mlxctrl, $fileman_access, $fileman_dir, $template, $deleted, $flag_allow_expired, $auth_field_group, $mailman_field_lists, $reading_password, $flag_allow_pending, $set_template_id, $wiz, $user_role);

$foo_source = ( ( $slice_id=="" ) ? $set_template_id : $slice_id);

$sarr = DB_AA::select1('', 'SELECT slice.id, slice.name, slice.type as slice_type, reading_password, mailman_field_lists, auth_field_group, fileman_dir, fileman_access, slice.lang_file, permit_offline_fill,permit_anonymous_edit,permit_anonymous_post, d_listlen, slice.template, slice.deleted, LOWER(HEX(module.owner)) as owner, slice.slice_url, slice.flag, slice.mlxctrl, module.priority, module.created_by, module.created_at FROM `slice`, `module`',
          [ [ 'slice.id', 'module.id', 'j' ], [ 'module.id', $foo_source, 'l' ]]);
if (!$sarr) {
    $sarr = [];
}

if ($_GET['convert']=='utf') {
    $optimizer = new AA\Optimize\ConvertSliceEncoding();
    $optimizer->test4modules([$slice_id]);
    $Msg = $optimizer->report();
}
if ($_GET['convert']=='doutf') {
    $optimizer = new AA\Optimize\ConvertSliceEncoding();
    $optimizer->repair4modules([$slice_id]);
    $Msg = $optimizer->report();
}

$flag_allow_expired = ($sarr['flag'] & SLICE_ALLOW_EXPIRED_CONTENT) == SLICE_ALLOW_EXPIRED_CONTENT;
$flag_allow_pending = ($sarr['flag'] & SLICE_ALLOW_PENDING_CONTENT) == SLICE_ALLOW_PENDING_CONTENT;

// lookup owners
$slice_owners = DB_AA::select(['unpackid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice_owner` ORDER BY `name`');

$biglangs = Langs::getFiles();

$apage = new AA_Adminpageutil('sliceadmin','main');
$apage->setTitle( $slice_id=="" ? _m("Add Slice") : _m("Admin - Slice settings"));
$apage->printHead($err, $Msg);

if ($slice_id == "") {
    $form_buttons = ["insert", "cancel"=> ["url"=>"sliceadd.php"]];
} else {
    $form_buttons = ["update", "cancel"=> ["url"=>"sliceadd.php"]];
}

FrmTabCaption(_m("Slice"), $form_buttons);

ModuleFieldsForm($foo_source, $marr = GetModuleFields($foo_source), $superadmin, ($slice_id==""), $new_owner, $new_owner_email);

$convert_action = '';
if ((DB_CHARACTER_SET == "cp1250") and $slice_id and (AA_Slice::getModule($slice_id)->getCharset() == 'windows-1250')) {
    $convert_action = 'Convert encoding of this slice to utf <a href=' . StateUrl($_SERVER['PHP_SELF'] . '?convert=utf') . '>test</a> <a href=' . StateUrl($_SERVER['PHP_SELF'] . '?convert=doutf') . '>convert</a>';
}

FrmInputSelect("lang_file", _m("Language"), $biglangs, rtrim($sarr['lang_file'],'3'), false, $convert_action);  // trim for old php3 lang file extension
if ( $sarr['slice_type'] !== 'AA_Core_Fields..') {  // we do not want change type for AA_Core_Fields.. slice
    FrmInputSelect("slice_type", _m("Slice Type"), AA_Slice::getSliceTypes(), $sarr['slice_type']);
}

if ( $superadmin ) {
    FrmInputChBox("template", _m("Template"), $sarr['template']);
}

FrmInputChBox("flag_allow_expired", _m("Show content of expired items"), $flag_allow_expired);
FrmInputChBox("flag_allow_pending", _m("Show content of pending items"), $flag_allow_pending);
FrmInputSelect("permit_anonymous_post", _m("Allow anonymous posting of items"), PERMS_STATE, $sarr['permit_anonymous_post']);
FrmInputSelect("permit_anonymous_edit", _m("Allow anonymous editing of items"), PERMS_ANONYMOUS_EDIT, $sarr['permit_anonymous_edit'], false, "", "../doc/anonym.html");
FrmInputSelect("permit_offline_fill", _m("Allow off-line item filling"),        PERMS_STATE, $sarr['permit_offline_fill']);

// Reader Management specific settings (Jakub, 7.2.2003)
$slice     = AA_Slice::getModule($slice_id);
if ($slice AND ($slice->getProperty("type") == 'ReaderManagement')) {
    $slicefields = AA_Fields::getFields4Select($slice_id, false, 'input_pri');
    FrmInputSelect("auth_field_group", _m("Auth Group Field"), $slicefields, $sarr['auth_field_group'], false, "", "../doc/reader.html#auth_field_group");
    FrmInputSelect("mailman_field_lists",_m("Mailman Lists Field"), $slicefields, $sarr['mailman_field_lists'], false, "", "../doc/reader.html#mailman");
}
FrmInputText("reading_password", _m("Password for Reading"), $sarr['reading_password'], 100, 50, false, "", "https://actionapps.org/faq/detail.shtml?x=1667");

if ($slice_id) {
    FrmStaticText(_m("Additional setting"), AA_Slice::getModuleObjectForm($slice_id), '', '', false);
}

FrmStaticText(_m("Created"), date('Y-m-d H:i', $sarr['created_at']). ' ('. perm_username($sarr['created_by']). ')');

FrmTabSeparator(_m("Settings for older AA - you will probably not use it for new slices"));
FrmInputNumber("d_listlen", _m("Listing length"), $sarr['d_listlen'], 0, 99999, false, _m('Used by slice.php. Fill 0 to disable slice.php output for this slice (recommended for all newer slices - they are presented by Site Module).'));

//mimo's MLX
if ($slice_id && ($mlx_ctrl_for = DB_AA::select1('name', 'SELECT name FROM `slice`', [['mlxctrl', $slice_id, 'l']]))) {
    FrmStaticText(_m("MLX Control Slice for"), $mlx_ctrl_for, 0, "https://actionapps.org/faq/detail.shtml?x=2290");
} else {
    $mlx_slices = DB_AA::select(['unpackid'=>'name'], "SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice` WHERE ((mlxctrl IS NULL) OR (mlxctrl='')) ORDER BY `name`");
    FrmInputSelect('mlxctrl', _m("MLX: Language Control Slice"), $mlx_slices, unpack_id($sarr['mlxctrl']), false, "", "https://actionapps.org/faq/detail.shtml?x=2290");
}

if ($superadmin) {
    FrmInputSelect("fileman_access", _m("File Manager Access"), getFilemanAccesses(), $sarr['fileman_access'], false, "", "https://actionapps.org/faq/detail.shtml?x=1697");
    FrmInputText("fileman_dir", _m("File Manager Directory"), $sarr['fileman_dir'], 99, 50, false, "", "https://actionapps.org/faq/detail.shtml?x=1698");
}

if ($slice_id=="") {
    echo "<input type=\"hidden\" name=\"add\" value=\"1\">";        // action
    echo "<input type=\"hidden\" name=\"no_slice_id\" value=\"1\">";  // detects new slice
    echo "<input type=\"hidden\" name=\"template_id\" value=\"". $set_template_id .'">';

    // fields storing values from wizard
    echo "<input type=\"hidden\" name=\"wiz[copyviews]\" value='$wiz[copyviews]'>";
    echo "<input type=\"hidden\" name=\"wiz[constants]\" value='$wiz[constants]'>";
    echo "<input type=\"hidden\" name=\"wiz[welcome]\" value='$wiz[welcome]'>";
    echo "<input type=\"hidden\" name=\"user_login\" value='$user_login'>";
    echo "<input type=\"hidden\" name=\"user_role\" value='$user_role'>";
    // end of fields storing values from wizard
}
FrmTabEnd($form_buttons);

$apage->printFoot();
