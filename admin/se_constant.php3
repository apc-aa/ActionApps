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
#             as_new - if we want to create new category group based on an existing (id of "template" group)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."constedit_util.php3";

$where_used = true;

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IfSlPerm(PS_FIELDS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
  exit;
}  

if( $categ OR $category ) {
  if(!IfSlPerm(PS_CATEGORY)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change category settings"), "admin");
    exit;
  }  
}

if( $deleteGroup && $group_id && !$category ) {
  delete_constant_group ($group_id);
  go_url( $sess->url(self_base() . "se_fields.php3"));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

function myQuery (&$db, $SQL)
{
    global $debug;
    if ($debug) 
      return $db->dquery ($SQL);
    else 
      return $db->query($SQL);
}

// Check permissions
if (! $category && $group_id ) {
    $SQL = "SELECT * FROM constant_slice INNER JOIN slice 
    	ON constant_slice.slice_id = slice.id 
    	WHERE group_id='$group_id'";

    myQuery ($db, $SQL);
      
    if ($db->next_record() && !CheckPerms( $auth->auth["uid"], "slice", unpack_id($db->f("slice_id")), PS_FIELDS)) {
        MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings for the slice owning this group")." (".$db->f("name").")", "admin");
        exit;
    }  
}

function ShowConstant($id, $name, $value, $cid, $pri, $class, $categ, $classes) {
  global $sess;
  $name = safe($name); $value=safe($value); $pri=safe($pri); $cid=safe($cid);

  echo "
  <tr>
    <td><input type=\"Text\" name=\"name[$id]\" size=25 maxlength=149 value=\"$name\"></td>
    <td><input type=\"Text\" name=\"value[$id]\" size=25 maxlength=149 value=\"$value\">
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

/** Propagates changes to a constant value to the items which contain this value. */
function propagateChanges ($cid, $newvalue, $short=true)
{
	global $db, $group_id, $Msg, $debug;
	  MyQuery ($db, "SELECT value FROM constant WHERE ".
		($short ? "short_id=$cid" : "id='$cid'"));
	if (!$db->next_record()) return;
	$oldvalue = addslashes($db->f("value"));
	if ($oldvalue == $newvalue) return;
    if ($oldvalue)
    	myQuery ($db, "
		SELECT item_id,field_id
		FROM content, field WHERE field.id=content.field_id
		AND (field.input_show_func LIKE '___:$group_id:%'
        OR  field.input_show_func LIKE '___:$group_id')
		AND content.text = '$oldvalue'");
	$db1 = new DB_AA;
	$cnt = 0;
	while ($db->next_record()) {
		++$cnt;
		myQuery ($db1, "
			UPDATE content SET text='$newvalue'
			WHERE item_id='".addslashes($db->f("item_id"))."' 
			AND field_id='".addslashes($db->f("field_id"))."' 
			AND text='$oldvalue'");
	}
	if ($cnt) $Msg .= $cnt . _m(" items changed to new value ") . "'$newvalue'<br>";
}

hcUpdate();

if( $update ) {
  do {
    if( !(isset($name) AND is_array($name) ))
      break;
    reset($name);
    while( list($key,$nam) = each($name) ) {
      $prior = $pri[$key];
      $val =   $value[$key];
      $cid[$key] = ( ($cid[$key]=="") ? "x".new_id() : $cid[$key] );  # unpacked, with beginning 'x' for string indexing array
      ValidateInput("nam", _m("Name"), $nam, $err, false, "text");   // if not filled it will be deleted
      ValidateInput("val", _m("Value"), $val, $err, false, "text");
      ValidateInput("prior", _m("Priority"), $prior, $err, false, "number");
    }
      
    if( !$group_id ) {  # new constant group  
      $new_group_id = str_replace(':','-',$new_group_id);  # we don't need ':'
                                                           # in id (parameter separator)
      
      ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
      if( count($err) > 1)
        break;
      $SQL = "SELECT * FROM constant WHERE group_id = '$new_group_id'";
      myQuery ($db, $SQL);
      if( $db->next_record() )
        $err["DB"] = _m("This constant group already exists");
       else {
        $add_new_group = true;
        $group_id = $new_group_id;
      }  
    }    

    if( count($err) > 1)
      break;

    if ($group_id) {
		  // if there is no group owner, promote this slice to owner
      MyQuery ($db, "SELECT * FROM constant_slice WHERE group_id='$group_id'");
      if (!$db->next_record()) 
        myQuery ($db, "
          INSERT INTO constant_slice (slice_id,group_id,propagate)
          VALUES ('$p_slice_id','$group_id',".($propagate_changes ? 1 : 0).");");
      else {
        myQuery ($db, "
          UPDATE constant_slice SET propagate=".($propagate_changes ? 1 : 0)."
           WHERE group_id = '$group_id'");
        if ($new_owner_id) {
          myQuery ($db, "
            UPDATE constant_slice SET slice_id='".addslashes(pack_id($new_owner_id))."'
             WHERE group_id = '$group_id'");
				  $chown = 0;
        }
      }
    }	  

    # add new group to constant group list
    if ($add_new_group) {
      $SQL = "INSERT INTO constant SET id='". q_pack_id(new_id()) ."',
                                       group_id='lt_groupNames',
                                       name='$group_id',
                                       value='$group_id',
                                       class='',
                                       pri='100'";
      myQuery ($db, $SQL);
    }  
  
    reset($name);
    while( list($key) = each($name) ) {
		  $p_cid = q_pack_id(substr($cid[$key],1));
        // if name is empty, delete the constant
      if ($name[$key] == "") {
        if( !MyQuery ($db, "
            DELETE FROM constant WHERE id='$p_cid'")) {
          $err["DB"] .= MsgErr("Can't delete constant");
          break;
        }
        continue;
      }
  		$varset->clear();
  		$varset->set("name",  $name[$key], "quoted");
  		$varset->set("value", $value[$key], "quoted");
  		$varset->set("pri", ( $pri[$key] ? $pri[$key] : 1000), "number");
  		$varset->set("class", $class[$key], "quoted");
  		MyQuery ($db, "SELECT * FROM constant WHERE id='$p_cid'");
      if ($db->next_record()) {
        if ($propagate_changes) 
          propagateChanges ($p_cid, $value[$key], false);
        if( !MyQuery ($db, "
          UPDATE constant SET " . $varset->makeUPDATE() ."
           WHERE id='$p_cid'")) {
          $err["DB"] .= MsgErr("Can't update constant");
          break;
        }
      }	else {
        $varset->set("id", substr($cid[$key],1), "unpacked" );  # remove beginning 'x'
        $varset->set("group_id", $group_id, "quoted" );
        if( !MyQuery ($db, "INSERT INTO constant " . $varset->makeINSERT() )) {
          $err["DB"] .= MsgErr("Can't copy constant");
          break;
        }
      }
    }
    
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
    if( count($err) <= 1 )
      $Msg .= MsgOK(_m("Constants update successful"));
  } while( 0 );           #in order we can use "break;" statement
}    

  # edit categories for this slice
if( $category ) {
  $group_id = GetCategoryGroup($slice_id);
  if( $group_id )
    $categ=true;
  else {
    MsgPage($sess->url(self_base())."slicedit.php3", _m("No category field defined in this slice.<br>Add category field to this slice first (see Field page)."), "admin");
    exit;
  }
}  

  # lookup constants
if( $group_id OR $as_new ) {
  $gid = ( $as_new ? $as_new : $group_id );
  $SQL = "SELECT id, name, value, class, pri FROM constant
           WHERE group_id='$gid' ORDER BY pri, name";
  $s_constants = GetTable2Array($SQL, $db, "NoCoLuMn");
}  

  # lookup apc categories classes
$SQL = "SELECT name, value, pri, id FROM constant
         WHERE group_id='lt_apcCategories' ORDER BY name";
$classes = GetTable2Array($SQL, $db, "id");


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - Constants Setting");?></TITLE>
</HEAD>
<?php 
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", $categ ? "category" : "");
    
  echo "<H1><B>" . _m("Admin - Constants Setting") . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<input type=hidden name="group_id" value="<?php echo $group_id ?>">
<input type=hidden name="categ" value="<?php echo $categ ?>">
<?php
  // load the HIERARCHICAL EDITOR
  if ($hierarch) {
	  require $GLOBALS[AA_INC_PATH]."constedit.php3";
  }
?>
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Constants")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
 <td class=tabtxt><b><?php echo _m("Constant Group") ?></b></td>
 <td class=tabtxt colspan=3><?php
   echo ( $group_id ? safe($group_id) :
         "<input type=\"Text\" name=\"new_group_id\" size=16 maxlength=16 value=\"".safe($new_group_id)."\">");
   echo "
	 </td>
</tr>";

# Find slices, where the constant group is used 
if( $group_id && $where_used ) {
  $delim = '';
  myQuery ($db, "
  	SELECT slice.name FROM slice, field 
     WHERE slice.id = field.slice_id
       AND field.input_show_func LIKE '%$group_id%'");
  while( $db->next_record() ) {
    $using_slices .= $delim. $db->f('name');
    $delim = ', ';
  }
  echo "
      <tr><td><b>"._m("Constants used in slice")."</b></td>
        <td colspan=3>$using_slices</td>
      </tr>";
}


# Find the slice owner of this group
myQuery ($db, "
	SELECT * FROM constant_slice INNER JOIN slice 
	ON constant_slice.slice_id = slice.id 
	WHERE group_id='$group_id'");
if ($db->next_record()) $owner_id = unpack_id128($db->f("slice_id"));

echo "
<tr><td><b>"._m("Constant group owner - slice")."</b></td>
<td colspan=3>";

if (!$owner_id || !$group_id) 
	echo _m("Whoever first updates values becomes owner.");
	
// display the select box to change group owner if requested ($chown)
elseif($chown AND is_array($g_modules) AND (count($g_modules) > 1) ) {
    echo "<select name=new_owner_id>";
    reset($g_modules);
    while(list($k, $v) = each($g_modules)) { 
      echo "<option value='". htmlspecialchars($k)."'".
	  	 ($owner_id == $k ? " selected" : "").
      	 "> ". htmlspecialchars($v["name"]);
    }
    echo "</select>\n";
}
else {
	echo $db->f("name")."&nbsp;&nbsp;&nbsp;&nbsp; 
	<input type=submit name='chown' value='"._m("Change owner")."'>";
}
	
echo "</td></tr>
<tr><td colspan=4><input type=checkbox name='propagate_changes'".($db->f("propagate") ? " checked" : "").">"._m("Propagate changes into current items");
if( !$where_used ) 
  echo "&nbsp;&nbsp;<input type=submit name='where_used' value='"._m("Where are these constants used?");
echo "'</td></tr>
<tr><td colspan=4><input type=submit name='hierarch' value='"._m("Edit in Hierarchical editor (allows to create constant hierarchy)")."'></td></tr>
<tr>
 <td class=tabtxt align=center><b><a href=\"javascript:SortConstants('name')\">". _m("Name") ."</a></b><br>". _m("shown&nbsp;on&nbsp;inputpage") ."</td>
 <td class=tabtxt align=center><b><a href=\"javascript:SortConstants('value')\">". _m("Value") ."</a></b><br>". _m("stored&nbsp;in&nbsp;database") ."</td>
 <td class=tabtxt align=center><b><a href=\"javascript:SortPri()\">". _m("Priority") ."</a></b><br>". _m("constant&nbsp;order") ."</td>
 <td class=tabtxt align=center><b><a href=\"javascript:SortConstants('class')\">". _m("Parent") ."</a></b><br>". _m("categories&nbsp;only") ."</td>
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
      ShowConstant($i, $v["name"], $v["value"], $as_new ? '' : 'x'.unpack_id128($v["id"]), $v["pri"], $v["class"], $categ, $classes);
    $i++;  
  }
}  

  # ten rows for possible new constants
for( $j=0; $j<10; $j++) {
/*
  if( $update ) # get values from form
    ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $class[$i], $categ, $classes, $ancestors[$i], $);
  else  
*/    ShowConstant($i, "", "", "", 1000, "", $categ, $classes);
  $i++;
}  

$lastIndex = $i-1;    // lastindex used in javascript (below) to get number of rows

echo '</table>
<tr><td align="center">
  <input type=hidden name="update" value=1>
  <input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;
  <input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;
  <input type=button value="'. _m("Delete whole group") .'" onclick="deleteWholeGroup();">&nbsp;&nbsp;
  <input type=hidden name=deleteGroup value=0>
</td></tr></table>
</FORM>
<SCRIPT language=javascript>
<!--
    function deleteWholeGroup() {
        if (prompt ("'._m("Are you sure you want to PERMANENTLY DELETE this group? Type yes or no.").'","'._m("no").'") == "'._m("yes").'") {
            document.f.deleteGroup.value = 1;
            document.f.submit();
        }
    }

  var data2sort;

  function GetFormData( col2sort ) {
    var i,element,varname;
    data2sort = null;
    data2sort = new Array();
    for( i=0; i<='. $lastIndex .'; i++ ) {
      element = "document.f.elements[\'"+col2sort+"["+i+"]\']";
      // add rownumber at the end of the text (to be able to get old possition)
      data2sort[i] = eval(element).value + " ~~"+i;
    }
  }

  function SortConstants( col2sort ) {
    var i,element,element2, text,row,counter=10;
    GetFormData(col2sort);
    data2sort.sort();
    for( i=0; i<='. $lastIndex .'; i++ ) {
      text = data2sort[i];
      row = text.substr(text.lastIndexOf(" ~~")+3);
      element = "document.f.elements[\'pri["+row+"]\']";
      element2 = "document.f.elements[\'"+col2sort+"["+row+"]\']";
      if( eval(element2).value == "" ) 
        eval(element).value = 9000;
       else { 
        eval(element).value = counter;
        counter += 10;
      }  
    }
  }

  function SortPri( ) {
    var i,element,counter=10;
    for( i=0; i<='. $lastIndex .'; i++ ) {
      element = "document.f.elements[\'pri["+i+"]\']";
      eval(element).value = counter;
      counter += 10;
    }
  }

//-->
</SCRIPT>';
HtmlPageEnd();
page_close()?>
