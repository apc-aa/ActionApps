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
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FULLTEXT, "admin");
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
    ValidateInput("discus_sel", L_DISCUS_SEL, $discus_sel, &$err, true, "text");

    if( count($err) > 1)
      break;

    $varset->add("fulltext_format_top", "quoted", $fulltext_format_top);
    $varset->add("fulltext_format", "quoted", $fulltext_format);
    $varset->add("fulltext_format_bottom", "quoted", $fulltext_format_bottom);
    $varset->add("fulltext_remove", "quoted", $fulltext_remove);
    $varset->add("flag", "number", $discus_htmlf ? 1 : 0);
    $varset->add("vid", "number", $discus_sel);


    $SQL = "UPDATE slice SET ". $varset->makeUPDATE().
           " WHERE id='".q_pack_id($slice_id)."'";
           
    if( !$db->query($SQL)) {
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
                 fulltext_remove, flag, vid
            FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record()) {
    $fulltext_format_top = $db->f(fulltext_format_top);
    $fulltext_format = $db->f(fulltext_format);
    $fulltext_format_bottom = $db->f(fulltext_format_bottom);
    $fulltext_remove = $db->f(fulltext_remove);
    $discus_htmlf = ($db->f(flag) & DISCUS_HTML_FORMAT) == DISCUS_HTML_FORMAT;
    $discus_vid = $db->f(vid);
  }  
}

# lookup discussion views
$discus_vids[0] = L_DISCUS_EMPTY;
$SQL = "SELECT id, name FROM view WHERE slice_id ='". $p_slice_id ."' AND type='discus'";
$db->query($SQL);
while ($db->next_record()) {
  $discus_vids[$db->f(id)] = $db->f(name);
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
  $show ["fulltext"] = false;
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
  FrmInputSelect("discus_sel", L_DISCUS_SEL, $discus_vids, $discus_vid, false);
  FrmInputChBox("discus_htmlf", L_DISCUS_HTML_FORMAT, $discus_htmlf);

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
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

