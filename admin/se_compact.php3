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

# se_compact.php3 - assigns html format for compact view
# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_COPMPACT);
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
    ValidateInput("odd_row_format", L_ODD_ROW_FORMAT, &$odd_row_format, &$err, true, "text");
    ValidateInput("compact_top", L_COMPACT_TOP, &$compact_top, &$err, false, "text");
    ValidateInput("compact_bottom", L_COMPACT_BOTTOM, &$compact_bottom, &$err, false, "text");
    ValidateInput("compact_remove", L_COMPACT_REMOVE, &$compact_remove, &$err, false, "text");
    if( $even_odd_differ )
      ValidateInput("even_row_format", L_EVEN_ROW_FORMAT, &$even_row_format, &$err, true, "text");
    if( $category_sort ) {
      ValidateInput("category_top", L_CATEGORY_TOP, &$category_top, &$err, false, "text");
      ValidateInput("category_format", L_CATEGORY_FORMAT, &$category_format, &$err, true, "text");
      ValidateInput("category_bottom", L_CATEGORY_BOTTOM, &$category_bottom, &$err, false, "text");
    }  
    if( count($err) > 1)
      break;

    $varset->add("odd_row_format", "quoted", $odd_row_format);
    $varset->add("even_row_format", "quoted", $even_row_format);
    $varset->add("category_top", "quoted", $category_top);
    $varset->add("category_format", "quoted", $category_format);
    $varset->add("category_bottom", "quoted", $category_bottom);
    $varset->add("compact_top", "quoted", $compact_top);
    $varset->add("compact_bottom", "quoted", $compact_bottom);
    $varset->add("compact_remove", "quoted", $compact_remove);
    $varset->add("even_odd_differ", "number", $even_odd_differ ? 1 : 0);
    $varset->add("category_sort", "number", $category_sort ? 1 : 0);

    if( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . " 
                      WHERE id='".q_pack_id($slice_id)."'")) {
      $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
      break;   # not necessary - we have set the halt_on_error
    }     
    
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
  }while(false);
  if( count($err) <= 1 )
    $Msg = MsgOK(L_COMPACT_OK);
}

if( $slice_id!="" ) {  // set variables from database - allways
  $SQL= " SELECT odd_row_format, even_row_format, even_odd_differ, compact_top, 
                 compact_bottom, compact_remove, category_sort, category_format,
                 category_top, category_bottom
          FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record()) {
    $odd_row_format = $db->f(odd_row_format);
    $even_row_format = $db->f(even_row_format);
    $category_top = $db->f(category_top);
    $category_format = $db->f(category_format);
    $category_bottom = $db->f(category_bottom);
    $compact_top = $db->f(compact_top);
    $compact_bottom = $db->f(compact_bottom);
    $compact_remove = $db->f(compact_remove);
    $even_odd_differ = $db->f(even_odd_differ);
    $category_sort = $db->f(category_sort);
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_COMPACT_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function Defaults()
{
  document.f.odd_row_format.value = '<?php echo DEFAULT_ODD_HTML ?>'
  document.f.even_row_format.value = '<?php echo DEFAULT_EVEN_HTML ?> '
  document.f.category_top.value = '<?php echo DEFAULT_CATEGORY_TOP ?>'
  document.f.category_format.value = '<?php echo DEFAULT_CATEGORY_HTML ?>'
  document.f.category_bottom.value = '<?php echo DEFAULT_CATEGORY_BOTTOM ?>'
  document.f.compact_top.value = '<?php echo DEFAULT_TOP_HTML ?>'
  document.f.compact_remove.value = '<?php echo DEFAULT_COMPACT_REMOVE ?>'
  document.f.even_odd_differ.checked = <?php echo (DEFAULT_EVEN_ODD_DIFFER ? "true" : "false"). "\n"; ?>
  document.f.category_sort.checked = <?php echo (DEFAULT_CATEGORY_SORT ? "true" : "false")."\n"; ?>
  InitPage()
}

function InitPage() {
  EnableClick('document.f.even_odd_differ','document.f.even_row_format')
  EnableClick('document.f.category_sort','document.f.category_format')
}

function EnableClick(cond,what) {
  eval(what).disabled=!(eval(cond).checked);
  // property .disabled supported only in MSIE 4.0+
}   


// -->
</SCRIPT>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $useOnLoad = true;
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>false, "fulltext"=>$xx, 
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_COMPACT . "</B></H1>&nbsp;" . L_COMPACT_HELP;
  PrintArray($err);
  echo $Msg;
?>
<form name=f enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_COMPACT_HDR?></b><BR>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmTextarea("compact_top", L_COMPACT_TOP, $compact_top, 4, 50, false,
               L_TOP_HLP, DOCUMENTATION_URL, 1);
  FrmTextarea("odd_row_format", L_ODD_ROW_FORMAT, $odd_row_format, 6, 50, false,
               L_FORMAT_HLP, DOCUMENTATION_URL, 1); 
  FrmInputChBox("even_odd_differ", L_EVEN_ODD_DIFFER, $even_odd_differ, true, "OnClick=\"EnableClick('document.f.even_odd_differ','document.f.even_row_format')\"");
  FrmTextarea("even_row_format", L_EVEN_ROW_FORMAT, $even_row_format, 6, 50, false,
               L_EVEN_ROW_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("compact_bottom", L_COMPACT_BOTTOM, $compact_bottom, 4, 50, false,
               L_BOTTOM_HLP, DOCUMENTATION_URL, 1); 
  FrmInputChBox("category_sort", L_CATEGORY_SORT, $category_sort, true, "OnClick=\"EnableClick('document.f.category_sort','document.f.category_format')\"");
  FrmTextarea("category_top", L_CATEGORY_TOP, $category_top, 4, 50, false,
               L_TOP_HLP, DOCUMENTATION_URL, 1);
  FrmTextarea("category_format", L_CATEGORY_FORMAT, $category_format, 6, 50, false,
               L_FORMAT_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("category_bottom", L_CATEGORY_BOTTOM, $category_bottom, 4, 50, false,
               L_BOTTOM_HLP, DOCUMENTATION_URL, 1); 
  FrmInputText("compact_remove", L_COMPACT_REMOVE, $compact_remove, 254, 50, false,
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
Revision 1.12  2001/03/30 11:52:53  honzam
reverse displaying HTML/Plain text bug and others smalll bugs fixed

Revision 1.11  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.10  2001/02/26 17:26:08  honzam
color profiles

Revision 1.9  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.8  2001/01/31 02:44:29  madebeer
added help prompt at top of page

Revision 1.7  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.5  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.4  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

Revision 1.3  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.2  2000/07/25 11:25:26  kzajicek
Fixed Javascript error in Netscape.

Revision 1.1.1.1  2000/06/21 18:39:58  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:48  madebeer
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

