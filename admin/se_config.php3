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

# se_config.php3 - setting configuration of slice (WDDX)
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CONFIG);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

if( $updateconfig ) {
  $columns = explode(",",$showcol); //showcol contains comma delimeted list of columns in admin interface (index.php3)

  if( isset($columns) AND is_array($columns) ) {
    reset($columns);
    while( list(,$name) = each($columns)) {
      $widthvar = "frm_".$name;
      $admin_fields[$name] = array("width"=>$$widthvar);
    }
  }  

  $wddx_packet = wddx_serialize_vars("admin_fields");
  $SQL = "UPDATE slices SET config=\"$wddx_packet\" where id='$p_slice_id'";
//  huhw($SQL);
  $db->query($SQL);
  if ($db->affected_rows() == 0) 
    $err["DB"] .= "<div class=err>Can't change configuration</div>";

  if( count($err) > 1 )
    MsgPage($sess->url(self_base()."se_config.php3"), $err);
   else {
    $r_stored_slice = "No-Slice-iD";      // invalidate $r_stored_slice - read parameters for this slice again
    page_close();
    go_url( $sess->url(self_base() . "se_config.php3"));
  }  

}  

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_INC_PATH]."js_lib.js";
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>


<SCRIPT Language="JavaScript"><!--
function UpdateConfig(action) {
  document.f.showcol.value = CommaDelimeted( 'document.f.shown' )
  document.f.submit()
}
// -->
</SCRIPT>
</HEAD>
<?php
  $xx = ($slice_id!="");
  $useOnLoad = false;
  $show = Array("main"=>true, "config"=>false, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_SLICE_CFG . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_VISIBLE_ADMIN_FIELDS ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
	<td width="45%" class=tabtxt align=center><b><?php echo L_HIDDEN ?></b></td>
	<td width="10%">&nbsp;</td>
	<td width="45%" class=tabtxt align=center><b><?php echo L_VISIBLE ?></b></td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="hidden" size=8 class=tabtxt>
  <?
  reset($af_columns);
  while(list($name,$val) = each($af_columns)) {
    if( !$r_slice_config["admin_fields"][$name] ) {
      if ( !($foo = $val[name])) // if there is no name defined, use title
        $foo = $val[title];
      echo "<option value=\"$name\"> $foo </option>";
    }
  }    
  ?>
</SELECT></td>
<td><input type="button" VALUE="  >>  " onClick = "MoveSelected('document.f.hidden','document.f.shown')" align=center>
    <br><br><input type="button" VALUE="  <<  " onClick = "MoveSelected('document.f.shown','document.f.hidden')" align=center></td>
<td align="CENTER" valign="TOP">
<SELECT name="shown" size=8 class=tabtxt>
  <?
  if( isset($r_slice_config["admin_fields"]) AND is_array($r_slice_config["admin_fields"])) {
    reset($r_slice_config["admin_fields"]);
    while(list($name,) = each($r_slice_config["admin_fields"])) {
      if ( !($foo = $af_columns[$name][name])) // if there is no name defined, use title
        $foo = $af_columns[$name][title];
      echo "<option value=\"$name\"> $foo </option>"; 
    }  
  }    ?>
</SELECT>
</td>
</tr>
<tr><td colspan=2>&nbsp;</td>
<td align=center><input type="button" VALUE="<?php echo L_UP?>" onClick = "MoveSelectedUp('document.f.shown')"> &nbsp; <input type="button" VALUE="<?php echo L_DOWN?>" onClick = "MoveSelectedDown('document.f.shown')"></td>
</tr>
</table></tr></td>
<tr><td class=tabtit><b>&nbsp;<?php echo L_FIELD_WIDTH ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<?php
  reset($af_columns);
  while(list($name,$val) = each($af_columns)) {
    $fooname = ($val["name"] ? $val["name"] : $val["title"]);
    $foowidth = ($r_slice_config["admin_fields"][name] ? $r_slice_config["admin_fields"][name][width] : $val[width]);
    FrmInputText("frm_".$name, $fooname, $foowidth, 20, 10, false);
  }               
?>
<tr><td colspan=2>&nbsp;</td></tr>
</table></tr></td>
<tr><td align="center">
<input type="button" VALUE="<?php echo L_UPDATE ?>" onClick = "UpdateConfig()" align=center>
<input type=hidden name=showcol value=0>  <!-- to this variable store selected columns (by javascript) -->
<input type=hidden name=updateconfig value=1>
</td></tr></table>
</FORM>
</BODY>
</HTML>

<?php 
/*
$Log$
Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:39:59  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:49  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.1  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.12  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.11  2000/03/29 14:34:12  honzama
Better Netscape Navigator support in javascripts.

Revision 1.10  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
page_close()?>
