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

# se_fulltext.php3 - assigns html format for fulltext view
# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FULLTEXT);
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
    ValidateInput("fulltext_format_top", L_FULLTEXT_FORMAT_TOP, &$fulltext_format_top, &$err, false, "text");
    ValidateInput("fulltext_format", L_FULLTEXT_FORMAT, &$fulltext_format, &$err, true, "text");
    ValidateInput("fulltext_format_bottom", L_FULLTEXT_FORMAT_BOTTOM, &$fulltext_format_bottom, &$err, false, "text");
    ValidateInput("fulltext_remove", L_FULLTEXT_REMOVE, &$fulltext_remove, &$err, false, "text");
    if( count($err) > 1)
      break;

    $varset->add("fulltext_format_top", "quoted", $fulltext_format_top);
    $varset->add("fulltext_format", "quoted", $fulltext_format);
    $varset->add("fulltext_format_bottom", "quoted", $fulltext_format_bottom);
    $varset->add("fulltext_remove", "quoted", $fulltext_remove);
    if( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . 
                     "WHERE id='".q_pack_id($slice_id)."'")) {
      $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
      break;    # not necessary - we have set the halt_on_error
    }     
    $fulltext_format_top = dequote($fulltext_format_top);
    $fulltext_format = dequote($fulltext_format);
    $fulltext_format_bottom = dequote($fulltext_format_bottom);

    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
  }while(false);
  if( count($err) <= 1 )
    $Msg = MsgOK(L_FULLTEXT_OK);
}

if( $slice_id!="" ) {  // set variables from database
  $SQL= " SELECT fulltext_format, fulltext_format_top, fulltext_format_bottom, 
                 fulltext_remove 
            FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record()) {
    $fulltext_format_top = $db->f(fulltext_format_top);
    $fulltext_format = $db->f(fulltext_format);
    $fulltext_format_bottom = $db->f(fulltext_format_bottom);
    $fulltext_remove = $db->f(fulltext_remove);
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo L_A_FULLTEXT_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function Defaults() {
  document.f.fulltext_format_top.value = '<?php echo DEFAULT_FULLTEXT_TOP ?>'
  document.f.fulltext_format.value = '<?php echo DEFAULT_FULLTEXT_HTML ?>'
  document.f.fulltext_format_bottom.value = '<?php echo DEFAULT_FULLTEXT_BOTTOM ?>'
  document.f.fulltext_remove.value = '<?php echo DEFAULT_FULLTEXT_REMOVE ?>'
}
// -->
</SCRIPT>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>false, 
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_FULLTEXT . "</B></H1>&nbsp;&nbsp;" . L_A_FULLTEXT_HELP;
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_FULLTEXT_HDR?></b>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmTextarea("fulltext_format_top", L_FULLTEXT_FORMAT_TOP, $fulltext_format_top, 4, 60, false,
               L_TOP_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("fulltext_format", L_FULLTEXT_FORMAT, $fulltext_format, 8, 60, true,
               L_FORMAT_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("fulltext_format_bottom", L_FULLTEXT_FORMAT_BOTTOM, $fulltext_format_bottom, 4, 60, false,
               L_BOTTOM_HLP, DOCUMENTATION_URL, 1); 
  FrmInputText("fulltext_remove", L_FULLTEXT_REMOVE, $fulltext_remove, 254, 50, false,
               L_REMOVE_HLP, DOCUMENTATION_URL);
?>
</table></td></tr>
<?php
  PrintAliasHelp(GetAliasesFromFields($fields));
?>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
  echo '<input type=button onClick = "Defaults()" align=center value="'. L_DEFAULTS .'">&nbsp;&nbsp;';
/*
$Log$
Revision 1.12  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.11  2001/03/30 11:52:53  honzam
reverse displaying HTML/Plain text bug and others smalll bugs fixed

Revision 1.10  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.9  2001/02/26 17:26:08  honzam
color profiles

Revision 1.8  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.7  2001/01/31 02:44:29  madebeer
added help prompt at top of page

Revision 1.6  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.4  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.3  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

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

