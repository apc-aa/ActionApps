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

# Parameters: group_id - identifier of constant group
#             categ - if true, constants are taken as category, so 
#                     APC parent categories are displayed for selecting parent
#             category - edit categories for this slice (no group_id nor categ required)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS);
  exit;
}  

if( $categ OR $category ) {
  if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CATEGORY);
    exit;
  }  
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

function ShowConstant($id, $name, $value, $cid, $pri, $class, $categ, $classes) {
  global $sess;
  $name = safe($name); $value=safe($value); $pri=safe($pri); $cid=safe($cid);

  echo "
  <tr>
    <td><input type=\"Text\" name=\"name[$id]\" size=25 maxlength=49 value=\"$name\"></td>
    <td><input type=\"Text\" name=\"value[$id]\" size=25 maxlength=49 value=\"$value\">
      <input type=\"Hidden\" name=\"cid[$id]\" value=\"$cid\"></td>
    <td class=tabtxt><input type=\"Text\" name=\"pri[$id]\" size=4 maxlength=4 value=\"$pri\"></td>";
  if( $categ ){   # it is categories - show APC wide categories for parent category select
    echo "<td class=tabtxt>";
    echo "<select name=\"class[$id]\" $add>";	
    reset($classes);
    while(list($k, $v) = each($classes)) { 
      echo "<option value=\"". htmlspecialchars($k)."\"";
      if ((string)$class == (string)$k) 
        echo " selected";
      echo "> ". htmlspecialchars($v[name]) ." </option>";
    }
    echo "</select>\n";
    echo"</td>";
  } else {
    echo "<td class=tabtxt>&nbsp;</td>";
  }  
  echo "</tr>\n";
}

if( $update )
{
  do {
    if( !(isset($name) AND is_array($name) ))
      break;
    reset($name);
    while( list($key,$nam) = each($name) ) {
      $prior = $pri[$key];
      $val =   $value[$key];
      $cid[$key] = ( ($cid[$key]=="") ? "x".new_id() : $cid[$key] );  # unpacked, with beginning 'x' for string indexing array
      ValidateInput("nam", L_CONSTANT_NAME, $nam, &$err, false, "text");   // if not filled it will be deleted
      ValidateInput("val", L_CONSTANT_VALUE, $val, &$err, false, "text");
      ValidateInput("prior", L_CONSTANT_PRIORITY, $prior, &$err, false, "number");
    }
      
    if( !$group_id ) {  # new constant group  
      ValidateInput("new_group_id", L_CONSTANT_GROUP, $new_group_id, &$err, true, "text");
      if( count($err) > 1)
        break;
      $SQL = "SELECT * FROM constant WHERE group_id = '$new_group_id'";
      $db->query($SQL);
      if( $db->next_record() )
        $err["DB"] = L_CONSTANT_GROUP_EXIST;
       else {
        $add_new_group = true;
        $group_id = $new_group_id;
      }  
    }    

    if( count($err) > 1)
      break;

    # add new group to constant group list
    if ($add_new_group) {
      $SQL = "INSERT INTO constant SET id='". q_pack_id(new_id()) ."',
                                       group_id='lt_groupNames',
                                       name='$group_id',
                                       value='$group_id',
                                       class='',
                                       pri='100'";
      $db->query($SQL);
    }  
  
    # first delete old values
    $SQL = "DELETE FROM constant WHERE group_id = '$group_id'";
    $db->query($SQL);
      
    reset($name);
    while( list($key,$nam) = each($name) ) {
      if( $nam == "" )   # remove this constant
        continue;

      $varset->clear();
      $varset->set("id", substr($cid[$key],1), "unpacked" );  # remove beginning 'x'
      $varset->set("group_id", $group_id, "quoted" );
      $varset->set("name",  $name[$key], "quoted");
      $varset->set("value", $value[$key], "quoted");
      $varset->set("pri", ( $pri[$key] ? $pri[$key] : 1000), "number");
      $varset->set("class", $class[$key], "quoted");
      if( !$db->query("INSERT INTO constant " . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't copy constant");
        break;
      }
    }
    
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
    if( count($err) <= 1 )
      $Msg = MsgOK(L_CONSTANTS_OK);
  } while( 0 );           #in order we can use "break;" statement
}    

  # edit categories for this slice
if( $category ) {
  $group_id = GetCategoryGroup($slice_id);
  if( $group_id )
    $categ=true;
  else {
    MsgPage($sess->url(self_base())."slicedit.php3", L_NO_CATEGORY_FIELD);
    exit;
  }
}  

  # lookup constants
if( $group_id ) {
  $SQL = "SELECT id, name, value, class, pri FROM constant
           WHERE group_id='$group_id' ORDER BY pri";
  $s_constants = GetTable2Array($SQL, $db, "NoCoLuMn");
}  

  # lookup apc categories classes
$SQL = "SELECT name, value, pri FROM constant
         WHERE group_id='lt_apcCategories' ORDER BY pri";
$classes = GetTable2Array($SQL, $db, "value");

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_CONSTANTS_TIT;?></TITLE>
</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>($xx && !$categ), "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_CONSTANTS_EDT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
 <td class=tabtxt><b><?php echo L_CONSTANT_GROUP ?></b></td>
 <td class=tabtxt colspan=3><?php
   echo ( $group_id ? safe($group_id) :
         "<input type=\"Text\" name=\"new_group_id\" size=16 maxlength=16 value=\"".safe($new_group_id)."\">");?>
 </td>
</tr>
<tr><?php
echo "
 <td class=tabtxt align=center><b>". L_CONSTANT_NAME ."</b><br>". L_CONSTANT_NAME_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_VALUE ."</b><br>". L_CONSTANT_VALUE_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_PRI ."</b><br>". L_CONSTANT_PRI_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_CLASS . "</b><br>". L_CONSTANT_CLASS_HLP ."</td>
</tr>
<tr><td colspan=4><hr></td></tr>";

   # existing constants
if( $s_constants ) {
  reset($s_constants);
  $i=0;
  while( list(, $v) = each($s_constants)) {
    if( $update ) # get values from form
      ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $class[$i], $categ, $classes);
    else          # get values from database
      ShowConstant($i, $v["name"], $v["value"], 'x'.unpack_id($v["id"]), $v["pri"], $v["class"], $categ, $classes);
    $i++;  
  }
}  

  # ten rows for possible new constants
for( $j=0; $j<10; $j++) {
  if( $update ) # get values from form
    ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $class[$i], $categ, $classes);
  else  
    ShowConstant($i, "", "", "", 1000, "", $categ, $classes);
  $i++;
}  

echo '</table>
<tr><td align="center">
  <input type=hidden name="update" value=1>
  <input type=hidden name="group_id" value="'. $group_id .'">
  <input type=hidden name="categ" value="'. $categ .'">
  <input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;
  <input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;
</td></tr></table>
</FORM>
</BODY>
</HTML>';

/*
$Log$
Revision 1.8  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.7  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.6  2001/02/26 17:26:08  honzam
color profiles

Revision 1.5  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.2  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.1.1.1  2000/06/21 18:39:59  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:49  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.9  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
page_close()?>
