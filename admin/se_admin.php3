<?php  
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

# se_admin.php3 - assigns html format for administation item view (index.php3)
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";     // GetAliasesFromField funct def 
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IfSlPerm(PS_CONFIG)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have no permission to set configuration parameters of this slice"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

if( $r_fields )
  $fields = $r_fields;
else
  list($fields,) = GetSliceFields($slice_id);

  
if( $update )
{
  do
  {
    ValidateInput("admin_format_top", _m("Top HTML"), $admin_format_top, $err, false, "text");
    ValidateInput("admin_format", _m("Item format"), $admin_format, $err, true, "text");
    ValidateInput("admin_format_bottom", _m("Bottom HTML"), $admin_format_bottom, $err, false, "text");
    ValidateInput("admin_remove", _m("Remove strings"), $admin_remove, $err, false, "text");
    if( count($err) > 1)
      break;

    $varset->add("admin_format_top", "quoted", $admin_format_top);
    $varset->add("admin_format", "quoted", $admin_format);
    $varset->add("admin_format_bottom", "quoted", $admin_format_bottom);
    $varset->add("admin_remove", "quoted", $admin_remove);
    if( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . 
                     "WHERE id='".q_pack_id($slice_id)."'")) {
      $err["DB"] = MsgErr( _m("Can't change slice settings") );
      break;    # not necessary - we have set the halt_on_error
    }

    $GLOBALS[pagecache]->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

    $admin_format_top = dequote($admin_format_top);
    $admin_format = dequote($admin_format);
    $admin_format_bottom = dequote($admin_format_bottom);
  }while(false);
  if( count($err) <= 1 )
    $Msg = MsgOK(_m("Admin fields update successful"));
}

if( $slice_id!="" ) {  // set variables from database
  $SQL= " SELECT admin_format, admin_format_top, admin_format_bottom, 
                 admin_remove 
            FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record()) {
    $admin_format_top = $db->f(admin_format_top);
    $admin_format = $db->f(admin_format);
    $admin_format_bottom = $db->f(admin_format_bottom);
    $admin_remove = $db->f(admin_remove);
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo _m("Admin - design Item Manager view");?></TITLE>
<SCRIPT Language="JavaScript"><!--
function Defaults() {
  document.f.admin_format_top.value = '<?php echo DEFAULT_ADMIN_TOP ?>'
  document.f.admin_format.value = '<?php echo DEFAULT_ADMIN_HTML ?>'
  document.f.admin_format_bottom.value = '<?php echo DEFAULT_ADMIN_BOTTOM ?>'
  document.f.admin_remove.value = '<?php echo DEFAULT_ADMIN_REMOVE ?>'
}
// -->
</SCRIPT>
</HEAD>

<?php
  require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "config");

  echo "<H1><B>" . _m("Admin - design Item Manager view") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Listing of items in Admin interface")?></b>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmTextarea("admin_format_top", _m("Top HTML"), $admin_format_top, 4, 60,
              false, _m("HTML code which appears at the top of slice area"), DOCUMENTATION_URL, 1); 
  FrmTextarea("admin_format", _m("Item format"), $admin_format, 8, 60, true,
                     _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliase will be substituted by real values from database when it will be posted to page"), DOCUMENTATION_URL, 1);
  FrmTextarea("admin_format_bottom", _m("Bottom HTML"), $admin_format_bottom,
              4, 60, false, _m("HTML code which appears at the bottom of slice area"), DOCUMENTATION_URL, 1);
  FrmInputText("admin_remove", _m("Remove strings"), $admin_remove, 254, 50, false,
               _m("Removes empty brackets etc. Use ## as delimeter."), DOCUMENTATION_URL);
?>
</table></td></tr>
<?php
  PrintAliasHelp(GetAliasesFromFields($fields));
?>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;';
  echo '<input type=button onClick = "Defaults()" align=center value="'. _m("Default") .'">&nbsp;&nbsp;';
?>
</td></tr></table>
</FORM>
<?php HtmlPageEnd();
page_close()?>

