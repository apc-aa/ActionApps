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

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CONFIG, "admin");
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
    ValidateInput("admin_format_top", L_ADMIN_FORMAT_TOP, $admin_format_top, $err, false, "text");
    ValidateInput("admin_format", L_ADMIN_FORMAT, $admin_format, $err, true, "text");
    ValidateInput("admin_format_bottom", L_ADMIN_FORMAT_BOTTOM, $admin_format_bottom, $err, false, "text");
    ValidateInput("admin_remove", L_ADMIN_REMOVE, $admin_remove, $err, false, "text");
    if( count($err) > 1)
      break;

    $varset->add("admin_format_top", "quoted", $admin_format_top);
    $varset->add("admin_format", "quoted", $admin_format);
    $varset->add("admin_format_bottom", "quoted", $admin_format_bottom);
    $varset->add("admin_remove", "quoted", $admin_remove);
    if( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . 
                     "WHERE id='".q_pack_id($slice_id)."'")) {
      $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
      break;    # not necessary - we have set the halt_on_error
    }

    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

    $admin_format_top = dequote($admin_format_top);
    $admin_format = dequote($admin_format);
    $admin_format_bottom = dequote($admin_format_bottom);
  }while(false);
  if( count($err) <= 1 )
    $Msg = MsgOK(L_ADMIN_OK);
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
<TITLE><?php echo L_A_ADMIN_TIT;?></TITLE>
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
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "config");

  echo "<H1><B>" . L_A_ADMIN . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_ADMIN_HDR?></b>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmTextarea("admin_format_top", L_ADMIN_FORMAT_TOP, $admin_format_top, 4, 60,
              false, L_TOP_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("admin_format", L_ADMIN_FORMAT, $admin_format, 8, 60, true,
                     L_FORMAT_HLP, DOCUMENTATION_URL, 1);
  FrmTextarea("admin_format_bottom", L_ADMIN_FORMAT_BOTTOM, $admin_format_bottom,
              4, 60, false, L_BOTTOM_HLP, DOCUMENTATION_URL, 1);
  FrmInputText("admin_remove", L_ADMIN_REMOVE, $admin_remove, 254, 50, false,
               L_REMOVE_HLP, DOCUMENTATION_URL);
?>
</table></td></tr>
<?php
  PrintAliasHelp(GetAliasesFromFields($fields));
?>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
  echo '<input type=button onClick = "Defaults()" align=center value="'. L_DEFAULTS .'">&nbsp;&nbsp;';
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

