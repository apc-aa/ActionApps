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

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CONFIG);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

$fields = ($r_fields ? 
             $r_fields : 
             GetTable2Array("SELECT * FROM field 
                              WHERE slice_id='$p_slice_id'
                              ORDER BY input_pri", $db));

if( $update )
{
  do
  {
    ValidateInput("admin_format_top", L_ADMIN_FORMAT_TOP, &$admin_format_top, &$err, false, "text");
    ValidateInput("admin_format", L_ADMIN_FORMAT, &$admin_format, &$err, true, "text");
    ValidateInput("admin_format_bottom", L_ADMIN_FORMAT_BOTTOM, &$admin_format_bottom, &$err, false, "text");
    ValidateInput("admin_remove", L_ADMIN_REMOVE, &$admin_remove, &$err, false, "text");
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
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>false, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_ADMIN . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form name=f enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_ADMIN_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<?php
  FrmTextarea("admin_format_top", L_ADMIN_FORMAT_TOP, $admin_format_top, 4, 60, false);
  FrmTextarea("admin_format", L_ADMIN_FORMAT, $admin_format, 8, 60, true);
  FrmTextarea("admin_format_bottom", L_ADMIN_FORMAT_BOTTOM, $admin_format_bottom, 4, 60, false);
  FrmInputText("admin_remove", L_ADMIN_REMOVE, $admin_remove, 254, 50, false);
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
/*
$Log$
Revision 1.1  2000/12/23 19:56:02  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:40:01  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:50  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.13  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.12  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.11  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.10  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

