<?php
/** PHP versions 4 and 5
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


// expected $uid - user id we have to edit profile for

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."profile.php3";
require_once AA_INC_PATH."constants_param_wizard.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "./se_users.php3"));
}

if (!IfSlPerm(PS_USERS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to manage users"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ( $del ) {
    // slice identification is not neccessry
    $SQL = "DELETE FROM profile WHERE id='$del' AND slice_id='$p_slice_id'";
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't delete profile");
        exit;
    }

    $Msg = MsgOK(_m("Rule deleted"));
}

if ( $add ) {
    if (!AddProfileProperty($uid, $slice_id, $property, $field_id, $fnction, $param, $html)) {
        $Msg = MsgOK(_m("Error: Can't add rule"));
    }
}

if ( $set_as ) {
    AA_Profile::copyProfile($slice_id, $set_as_uid, $uid);
}

// prepare forms ---------------------------------------------------------------

// get current profiles
$rules = GetTable2Array("SELECT * FROM profile WHERE slice_id='$p_slice_id' AND (uid='$uid') ORDER BY property, selector");

// get fields for this slice
list($fields,) = GetSliceFields($slice_id);
foreach ($fields as $k => $v) {
    $lookup_fields[$k] = $v['name'];
}

// set property names array
$PROPERTY_TYPES = array( 'listlen'           => _m("Item number"),
                         'input_view'        => _m("Input view ID"),
                         'admin_search'      => _m("Item filter"),
                         'admin_order'       => _m("Item order"),
                         'admin_perm'        => _m("Item permissions"),
                         'hide'              => _m("Hide field"),
                         'show'              => _m("Show field"),
                         'hide&fill'         => _m("Hide and Fill"),
                         'fill'              => _m("Fill field"),
                         'predefine'         => _m("Predefine field"),
                         'bookmark'          => _m("Stored query"),
                         'ui_manager'        => _m("UI - manager"),
                         'ui_manager_hide'   => _m("UI - manager - hide"),
                         'ui_inputform'      => _m("UI - inputform"),
                         'ui_inputform_hide' => _m("UI - inputform - hide")
                       );

$SORTORDER_TYPES = array( '+'=>_m("Ascending"), '-' => _m("Descending") );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - user Profiles");?></title>
<script language="JavaScript"><!--
  function addrule(n) {
    var si;
    document.sf.property.value = eval('document.fr.prop'+n+'.value');
    if ( eval('document.fr.param'+n) != null )
      document.sf.param.value = eval('document.fr.param'+n+'.value');
    if ( eval('document.fr.html'+n) != null )
      document.sf.html.value = ((eval('document.fr.html'+n).checked) ? '1' : '0');
    if ( eval('document.fr.fnc'+n) != null ) {
      si = eval('document.fr.fnc'+n+'.options.selectedIndex');
      document.sf.fnction.value = eval('document.fr.fnc'+n+'.options['+si+'].value');
    }
    if ( eval('document.fr.fld'+n) != null ) {
      si = eval('document.fr.fld'+n+'.options.selectedIndex');
      document.sf.field_id.value = eval('document.fr.fld'+n+'.options['+si+'].value');
    }
    document.sf.submit();
  }
//-->
</script>
</head>
<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin","");

echo "<h1><b>" . _m("Admin - user Profiles") . "</b></h1>";
PrintArray($err);
echo $Msg;

echo "
 <table width=\"70%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr>
   <td class=\"tabtit\"><b>&nbsp;". _m("Rules") ." - $uid - ". perm_username($uid). "</b></td>
  </tr>
  <tr>
   <td>
    <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">";

if ( isset($rules) AND is_array($rules) ) {
    foreach ($rules as $v) {
        PrintRule($v);
    }
} else {
    echo "<tr><td>"._m("No rule is set")."</td></tr>";
}

echo "</table>
  <tr>
   <td class=\"tabtit\"><b>&nbsp;". _m("Add Rule") ."</b></td>
  </tr>
  <tr>
   <td>
    <form name=\"fr\">
     <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" width=\"100%\" bgcolor=\"". COLOR_TABBG ."\">
      <tr class=\"tabtxt\" align=\"center\">
       <td><b>". _m("Rule") . "</b></td>
       <td><b>". _m("Field") . "</b></td>
       <td><b>". _m("Function") . "</b></td>
       <td><b>". _m("Value") . "</b></td>
       <td><b>". _m("HTML") . "</b></td>
       <td>&nbsp;</td>
      </tr>";

$inputDefaultTypes = getSelectBoxFromParamWizard($DEFAULT_VALUE_TYPES);

$menu_entries = array('top_logo'                        => _m('Logo (top)'),
                      'top_view'                        => _m('View site (top)'),
                      'top_additem'                     => _m('Add Item link (top)'),
                      'top_itemmanager'                 => _m('Item Manager link (top)'),
                      'top_sliceadmin'                  => _m('Slice Admin link (top)'),
                      'top_aaadmin'                     => _m('AA link (top)'),
                      'top_central'                     => _m('Central link (top)'),
                      'top_title'                       => _m('Title (top)'),
                      'top_logout'                      => _m('Logout link (top)'),
                      'top_userinfo'                    => _m('User Info link (top)'),
                      'top_moduleselection'             => _m('Module Selectbox (top)'),
                      'top_moduleswitchtext'            => _m('Module Switch text (top)'),
                      'itemmanager_submenu_header1'     => _m('Item Manager Menu: Header (left)'),
                      'itemmanager_submenu_additem'     => _m('Item Manager Menu: Add Item (left)'),
                      'itemmanager_submenu_app'         => _m('Item Manager Menu: Active (left)'),
                      'itemmanager_submenu_appb'        => _m('Item Manager Menu: Pending (left)'),
                      'itemmanager_submenu_appc'        => _m('Item Manager Menu: Expired (left)'),
                      'itemmanager_submenu_hold'        => _m('Item Manager Menu: Holding (left)'),
                      'itemmanager_submenu_trash'       => _m('Item Manager Menu: Trash (left)'),
                      'itemmanager_submenu_bookmarks'   => _m('Item Manager Menu: Bookmarks show (left)'),
                      'itemmanager_submenu_add1'        => _m('Item Manager Menu: Additional 1 (left)'),
                      'itemmanager_submenu_header2'     => _m('Item Manager Menu: Header 2 (left)'),
                      'itemmanager_submenu_slice_fld'   => _m('Item Manager Menu: Slice Setting (left)'),
                      'itemmanager_submenu_empty_trash' => _m('Item Manager Menu: Empty Trash (left)'),
                      'itemmanager_submenu_CSVimport'   => _m('Item Manager Menu: CSV Import (left)'),
                      'itemmanager_submenu_debug'       => _m('Item Manager Menu: Debug (left)'),
                      'itemmanager_submenu_add2'        => _m('Item Manager Menu: Additional 2 (left)'),
                      'css_add'                         => _m('Add CSS file')
    );

$manager_hide = array('mgr_actions'                     => _m('Manager Actions'),
                      'mgr_sb_searchrows'               => _m('Searchbar - Search Rows'),
                      'mgr_sb_orderrows'                => _m('Searchbar - Order Rows'),
                      'mgr_sb_bookmarks'                => _m('Searchbar - Boomarks')
    );

$inputform_entries = array(
                      'add_title'                       => _m('Title (add form)'),
                      'add_tophtml'                     => _m('Top HTML code (add form)'),
                      'add_bottomhtml'                  => _m('Bottom HTML code (add form)'),
                      'add_btn_insert'                  => _m('Button "Insert" / "Insert as new" (add form)'),
                      'add_btn_ins_edit'                => _m('Button "Insert & Edit" (add form)'),
                      'add_btn_ins_preview'             => _m('Button "Insert & View" (add form)'),
                      'add_btn_cancel'                  => _m('Button "Cancel" (add form)'),
                      'edit_title'                      => _m('Title (edit form)'),
                      'edit_tophtml'                    => _m('Top HTML code (edit form)'),
                      'edit_bottomhtml'                 => _m('Bottom HTML code (edit form)'),
                      'edit_btn_update'                 => _m('Button "Update" (edit form)'),
                      'edit_btn_upd_edit'               => _m('Button "Update & Edit" (edit form)'),
                      'edit_btn_upd_preview'            => _m('Button "Update & View" (edit form)'),
                      'edit_btn_reset'                  => _m('Button "Reset form" (edit form)'),
                      'edit_btn_cancel'                 => _m('Button "Cancel" (edit form)')
                      );

$permission_roles = array(
                      'perm_author'                     => _m('Author Role'),
                      'perm_editor'                     => _m('Editor Role'),
                      'perm_admin'                      => _m('Adminostrator Role'),
                      'perm_super'                      => _m('Superadmin Role')
                      );


         // row, rule,            show_field_selectbox,function_selectbox,show_parameter_box,show_html_checkbox, description
PrintSetRule( 1,'listlen',        0,                   0,                 1,                 0,                 _m('number of item displayed in Item Manager') );
PrintSetRule( 2,'input_view',     0,                   0,                 1,                 0,                 _m('id of view used for item input') );
PrintSetRule( 3,'admin_search',   1,                   $inputDefaultTypes,1,                 0,                 _m('preset "Search" in Item Manager'));
PrintSetRule( 4,'admin_order',    1,                   $SORTORDER_TYPES,  0,                 0,                 _m('preset "Order" in Item Manager'));
PrintSetRule(12,'admin_perm',     0,                   0,                 1,                 0,                 _m('ID of "Item Set" which defines the permissions for item - see "Admin - Item Set"'));
PrintSetRule( 5,'hide',           1,                   0,                 0,                 0,                 _m('hide the field in inputform'));
PrintSetRule(13,'show',           1,                   0,                 0,                 0,                 _m('show (= unhide) the field in inputform (reverts hide made by group profile,...)'));
PrintSetRule( 6,'hide&fill',      1,                   $inputDefaultTypes,1,                 1,                 _m('hide the field in inputform and fill it by the value'));
PrintSetRule( 7,'fill',           1,                   $inputDefaultTypes,1,                 1,                 _m('fill the field in inputform by the value'));
PrintSetRule( 8,'predefine',      1,                   $inputDefaultTypes,1,                 1,                 _m('predefine value of the field in inputform'));
PrintSetRule( 9,'ui_manager',     $menu_entries,       0,                 1,                 0,                 _m('redefine manager UI - (empty values = do not show)'));
PrintSetRule(10,'ui_manager_hide',$manager_hide,       0,                 0,                 0,                 _m('hide this UI element'));
PrintSetRule(11,'ui_inputform',   $inputform_entries,  0,                 1,                 0,                 _m('redefine inputform UI - (empty values = do not show)'));

echo "</table>
    </form>
    <form name=\"sf\" action='se_profile.php3'>
      <input type=\"hidden\" name=\"uid\" value='$uid'>
      <input type=\"hidden\" name=\"add\" value='1'>
      <input type=\"hidden\" name=\"property\">
      <input type=\"hidden\" name=\"param\">
      <input type=\"hidden\" name=\"field_id\">
      <input type=\"hidden\" name=\"fnction\">
      <input type=\"hidden\" name=\"html\">";
      $sess->hidden_session();
echo '</form>
    </td>
   </tr>';

$set_as_from = GetTable2Array("SELECT DISTINCT uid FROM profile WHERE slice_id='$p_slice_id' AND (uid <>'$uid') AND (uid <>'*')", 'uid', 'uid');

if (is_array($set_as_from) AND (count($set_as_from) > 0)) {
    foreach ($set_as_from as $from_uid) {
        $set_as_from[$from_uid] = perm_username($from_uid);
    }
    echo '<tr><td>
    <form name="cpf" action="se_profile.php3">
      <input type="hidden" name="uid" value="'.$uid.'">
      <input type="hidden" name="set_as" value="1">';
      $sess->hidden_session();

    echo _m('Copy Profile from');
    FrmSelectEasy('set_as_uid', $set_as_from);
    echo '<input type="submit" name="set_as" value=" '. _m('Copy') .' ">';
    echo '</form>
        </td>
       </tr>';
}

echo  '
  </table>';
HTMLPageEnd();
page_close();
?>