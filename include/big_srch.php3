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

// Big Search Form
// expected slice_id

// Prints html tag <select .. to 2-column table
function SrchFrmSelect($name, $txt, $arr, $selected="") {
  echo "<TR><TD class=srchtxt>$txt</TD>\n";
  echo "    <TD class=srchtxt><SELECT name=search[$name]>";
  echo "<OPTION value=0>".L_SRCH_ALL."</OPTION>";
  if( isset($arr) AND is_array($arr)) {
    reset($arr);
    while(list($k, $v) = each($arr)) { 
      echo "<option value=\"". htmlspecialchars($k)."\"";
      if ($selected == $k) 
        echo " selected";
      echo "> ". htmlspecialchars($v) ." </option>";
    }
  }  
  echo "</select>\n</td></tr>\n";
}  

// Prints html tag <input text .. to 2-column table
function SrchFrmDate($name, $txt, $val="") {
  echo "<TR><TD class=srchtxt>$txt</TD>\n";
  echo "    <TD class=srchtxt>";
  echo "<INPUT type=text name=search[$name] size=12 value=\"$val\">\n";
  echo "<span class=srchhlp>".dateExample()."</span>";
  echo "</td></tr>\n";
}  
// Prints html tag <input checkbox .. to 1-column table
function SrchFrmFields($name, $txt, $val, $checked) {
  echo "<TR><TD class=srchtxt>";
  echo "  <INPUT type=checkbox name=s_col[$name] value=\"$val\"";
  if($checked) 
    echo " checked";
  echo ">$txt</td></tr>\n";
}  

$p_slice_id = q_pack_id($slice_id);

// what to show? 
$SQL= "SELECT search_show, search_default FROM slices where id='$p_slice_id'";
$db->query($SQL);
if($db->next_record()) {
  $show    = UnpackFieldsToArray($db->f(search_show), $SHOWN_SEARCH_FIELDS);
  if( !isset($s_col))
    $s_col = UnpackFieldsToArray($db->f(search_default), $DEFAULT_SEARCH_IN);
}    

// lookup (languages) 
$languages = GetConstants("lt_languages", $db);

// lookup (slices) 
$SQL= " SELECT id, short_name FROM slices ";
$db->query($SQL);
while($db->next_record())
  $slices[unpack_id($db->f(id))]= $db->f(short_name);

// lookup (categories) 
if( $show[slice] )
  $SQL= " SELECT name, id FROM categories";
else
  $SQL= " SELECT name, id FROM categories LEFT JOIN catbinds ON categories.id = catbinds.category_id WHERE catbinds.slice_id='".q_pack_id($slice_id)."'";
$db->query($SQL);
while($db->next_record()) 
  $categories[unpack_id($db->f(id))] = $db->f(name);

/* don't lookup authors, as that functionality is missing

//lookup authors
$SQL= " SELECT username FROM auth_user ";
$db->query($SQL);
while($db->next_record()) 
  $authors[$db->f(username)]= $db->f(username);
*/
/*
$Log$
Revision 1.4  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/10/16 12:52:18  honzam
Big search form can be customized via style sheets

Revision 1.1.1.1  2000/06/21 18:40:22  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:12  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.6  2000/06/12 19:58:34  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.5  2000/04/24 16:50:33  honzama
New usermanagement interface.

Revision 1.4  2000/04/04 18:09:46  madebeer
removed author section for big_srch, included needed librariees in doit.php3
made perm_sql.php3 AuthenticateUsername more robust.

Revision 1.3  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>

<FORM method=get action="<?php echo $sess->MyUrl($slice_id, $encap, true);?>">
<P>
 <TABLE class=srchouter border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
  <TR>
   <TD>
    <TABLE class=srchinner width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
     <TR class=srchtoptr bgcolor="#584011" align="center">
      <TD class=srchtoptd colspan=2 class=srchtit><?php echo L_SRCH_KW ?>&nbsp;&nbsp; 
       <INPUT type="Text" name=search[keyword] <?php
         if( $search[keyword]!="" ) echo " value=".$search[keyword];?> size="30">&nbsp;&nbsp;
       <SELECT name="search[type]"> 
        <OPTION value="OR"><?php echo L_OR ?></OPTION>
        <OPTION value="AND"><?php echo L_AND ?></OPTION>
       </SELECT> 
       <A href="<? echo HELPPAGE?>#search"><IMG src="images/help.gif" width=23 height=20 border=0 valign="right" alt=""></A>
      </TD>
     </TR>
     <TR class=srchinnertr>
      <TD class=srchinnertd width="70%">
       <TABLE class=srchinner><?php
         if( $show[slice] )
           SrchFrmSelect("slice", L_SRCH_SLICE, $slices, $search[slice]);
         if( $show[category] )
           SrchFrmSelect("category", L_SRCH_CATEGORY, $categories, $search[category]);
         if( $show[author] )
           SrchFrmSelect("author", L_SRCH_AUTHOR, $authors, $search[author]);
         if( $show[language] )
           SrchFrmSelect("lang", L_SRCH_LANGUAGE, $languages, $search[lang]);
         if( $show[from] )
           SrchFrmDate("from", L_SRCH_FROM, $search[from]);
         if( $show[to] )
           SrchFrmDate("to", L_SRCH_TO, $search[to]);
         if( !$show[slice] AND !$show[category] AND !$show[author] AND 
             !$show[language] AND !$show[from] AND !$show[to] )
           echo "<tr><td>&nbsp;</td></tr>"; ?>
       </table>
      </td>
      <td class=srchinnertd>
       <table class=srchinner><?php
         if( $show[headline] )
           SrchFrmFields("0", L_SRCH_HEADLINE, "headline", $s_col[headline]);
         if( $show[abstract] )
           SrchFrmFields("1", L_SRCH_ABSTRACT, "abstract", $s_col[abstract]);
         if( $show[full_text] )
           SrchFrmFields("2", L_SRCH_FULL_TEXT, "full_text", $s_col[full_text]);
         if( $show[edit_note] )
           SrchFrmFields("3", L_SRCH_EDIT_NOTE, "edit_note", $s_col[edit_note]);
         if( !$show[headline] AND !$show[abstract] AND !$show[full_text] AND !$show[edit_note])
           echo "<tr><td>&nbsp;</td></tr>"; ?>
       </table>
      </td>
     </tr>
    </table>
   </td>
  </tr>  
  <tr class=srchoutertr>
   <td class=srchoutertd align="center">
   <input type=hidden name=srch value=1>
   <input type=hidden name=big value=1><?php 
   if( !$show[slice] )
     echo "<input type=hidden name=search[slice] value=$slice_id>"?>
   <input type=hidden name=slice_id value=<?php echo $slice_id?>>
   <input type=submit name=submit value="<?php echo L_SRCH_SUBMIT ?>">
</td></tr></table>
</FORM>
