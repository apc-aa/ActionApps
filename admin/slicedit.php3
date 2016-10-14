<?php
/**
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
// expected $slice_id for edit slice, no_slice_id=1 for adding slice

// set template id (changes language file => must be here):
require_once "../include/slicedit2.php3";

$template_slice_sel = $_REQUEST['template_slice_sel'];
list($set_template_id, $change_lang_file) = get_template_and_lang($template_slice_sel["slice"] ? $_REQUEST['template_id2'] : $_REQUEST['template_id']);

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
// add mlx functions
require_once AA_INC_PATH."mlx.php";



$PERMS_STATE = array( "0" => _m("Not allowed"),
                      "1" => _m("Active"),
                      "2" => _m("Hold bin") );

$PERMS_ANONYMOUS_EDIT = array(
    ANONYMOUS_EDIT_NOT_ALLOWED      => _m("Not allowed"),
    ANONYMOUS_EDIT_ALL              => _m("All items"),
    ANONYMOUS_EDIT_ONLY_ANONYMOUS   => _m("Only items posted anonymously"),
    ANONYMOUS_EDIT_NOT_EDITED_IN_AA => _m("-\"- and not edited in AA"),
    ANONYMOUS_EDIT_PASSWORD         => _m("Authorized by a password field"),
    ANONYMOUS_EDIT_HTTP_AUTH        => _m("Readers, authorized by HTTP auth"),
);

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

foreach ($MODULES as $type => $module) {
    if ($create[$type]) {
        $url = $sess->url(AA_INSTAL_PATH. $module["directory"] . "modedit.php3?no_slice_id=1");
        if ( $template[$type] ) {
            $url = get_url( $url, "template%5B$type%5D=". $template[$type]);
        }
        go_url( $url );
    }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$superadmin  = IsSuperadmin();

require_once AA_INC_PATH."slicedit.php3";

$foo_source = ( ( $slice_id=="" ) ? $set_template_id : $slice_id);

$sarr = DB_AA::select1('SELECT slice.id, slice.name, reading_password, mailman_field_lists, auth_field_group, fileman_dir, fileman_access, slice.lang_file, permit_offline_fill,permit_anonymous_edit,permit_anonymous_post, d_listlen, slice.template, slice.deleted, LOWER(HEX(slice.owner)) as owner, slice.slice_url, slice.flag, slice.mlxctrl, module.priority FROM `slice`, `module`', '', array(array('slice.id', 'module.id', 'j'), array('module.id',$foo_source, 'l')));
if (!$sarr) {
    $sarr = array();
}

$flag_allow_expired = ($sarr['flag'] & SLICE_ALLOW_EXPIRED_CONTENT) == SLICE_ALLOW_EXPIRED_CONTENT;

//if ( $slice_id == "" ) {
//    // load default values for new slice
//    $name         = "";
//    $owner        = "";
//    $template     = "";
//    $slice_url    = "";
//}

// lookup owners
$slice_owners = DB_AA::select(array('unpackid'=>'name'), 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice_owner` ORDER BY `name`');

foreach ($LANGUAGE_NAMES as $l => $langname) {
    $biglangs[$l."_news_lang.php3"] = $langname;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Slice Administration");?></title>
</head>
<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin","main");

echo "<h1><b>" . ( $slice_id=="" ? _m("Add Slice") : _m("Admin - Slice settings")) . "</b></h1>";
PrintArray($err);
echo $Msg;

if ($slice_id == "") {
    $form_buttons = array("insert", "cancel"=>array("url"=>"sliceadd.php3"));
} else {
    $form_buttons = array ("update", "cancel"=>array("url"=>"sliceadd.php3"));
}

?>
<form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php

FrmTabCaption(_m("Slice"), '','', $form_buttons, $sess, $slice_id);

FrmStaticText(_m("Id"), $slice_id);
FrmInputText("name", _m("Title"), $sarr['name'], 99, 25, true);
FrmInputText("slice_url", _m("URL of .shtml page (often leave blank)"), $sarr['slice_url'], 254, 25, false);
FrmInputText("priority", _m("Priority (order in slice-menu)"), $sarr['priority'], 5, 5, false);
// not functional, yet
// FrmInputText("upload_url", _m("Upload URL"), $slice_url, 254, 25, false, _m('Url of uploaded files is %1 by default. You can change it by setting this parameter.<br>Note: This do not change the place, wheer the file is stored - you can just use another virtualhost name, for example.', array(IMG_UPLOAD_URL)));
FrmInputSelect("owner", _m("Owner"), $slice_owners, $sarr['owner'], false);
if ( !$sarr['owner'] ) {
    FrmInputText("new_owner", _m("New Owner"), $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, 99, 25, false);
}
if ( $superadmin ) {
    FrmInputChBox("template", _m("Template"), $sarr['template']);
    FrmInputChBox("deleted", _m("Deleted"), $sarr['deleted']);
}
FrmInputChBox("flag_allow_expired", _m("Show content of expired items"), $flag_allow_expired);
FrmInputSelect("permit_anonymous_post", _m("Allow anonymous posting of items"), $PERMS_STATE, $sarr['permit_anonymous_post'], false);
FrmInputSelect("permit_anonymous_edit", _m("Allow anonymous editing of items"), $PERMS_ANONYMOUS_EDIT, $sarr['permit_anonymous_edit'], false, "", "../doc/anonym.html");
FrmInputSelect("permit_offline_fill", _m("Allow off-line item filling"),        $PERMS_STATE, $sarr['permit_offline_fill'], false);
FrmInputSelect("lang_file", _m("Language"), $biglangs, $sarr['lang_file'], false);

// Reader Management specific settings (Jakub, 7.2.2003)
$slice     = AA_Slice::getModule($slice_id);
if ($slice AND ($slice->getProperty("type") == 'ReaderManagement')) {
    $slicefields = GetFields4Select($slice_id, false, 'input_pri');
    FrmInputSelect("auth_field_group", _m("Auth Group Field"), $slicefields, $sarr['auth_field_group'], false, "", "../doc/reader.html#auth_field_group");
    FrmInputSelect("mailman_field_lists",_m("Mailman Lists Field"), $slicefields, $sarr['mailman_field_lists'], false, "", "../doc/reader.html#mailman");
}

FrmInputText("reading_password", _m("Password for Reading"), $sarr['reading_password'], 100, 25, false, "", "http://apc-aa.sourceforge.net/faq/#slice_pwd");

if ($slice_id) {
    FrmStaticText(_m("Additional setting"), AA_Slice::getModuleObjectForm($slice_id),false,'','',false);
}

FrmTabSeparator(_m("Settings for older AA - you will probably not use it for new slices"));
FrmInputText("d_listlen", _m("Listing length"), $sarr['d_listlen'], 5, 5, false);
//mimo's MLX
if ($slice_id && ($mlx_ctrl_for = DB_AA::select1('SELECT name FROM `slice`', 'name', array(array('mlxctrl', $slice_id, 'l'))))) {
    FrmStaticText(_m("MLX Control Slice for"),$mlx_ctrl_for,0,0,"http://mimo.gn.apc.org/mlx/");
} else {
    $mlx_slices = DB_AA::select(array('unpackid'=>'name'), "SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice` WHERE ((mlxctrl IS NULL) OR (mlxctrl='')) ORDER BY `name`");
    FrmInputSelect('mlxctrl', _m("MLX: Language Control Slice"), $mlx_slices, unpack_id($sarr['mlxctrl']), false, "", "http://mimo.gn.apc.org/mlx/");
}

if ($superadmin) {
    FrmInputSelect("fileman_access", _m("File Manager Access"), getFilemanAccesses(), $sarr['fileman_access'], false, "", "http://apc-aa.sourceforge.net/faq/#1106");
    FrmInputText("fileman_dir", _m("File Manager Directory"), $sarr['fileman_dir'], 99, 25, false, "", "http://apc-aa.sourceforge.net/faq/#1106");
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


FrmTabEnd($form_buttons, $sess, $slice_id);

?>
</form>
<?php
freeDB($db);
HTMLPageEnd();
page_close();
?>