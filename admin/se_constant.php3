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
//require $GLOBALS[AA_INC_PATH]."constedit_util.php3";


if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS, "admin");
  exit;
}  

if( $categ OR $category ) {
  if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CATEGORY, "admin");
    exit;
  }  
}

if( $deleteGroup && $group_id && !$category ) {
  # delete constant group name
  $db->query ("DELETE FROM constant WHERE (group_id='lt_groupNames' AND value='$group_id')
              OR group_id='$group_id'");
  # delete constants itself
  $db->query ("DELETE FROM constant WHERE (group_id='$group_id')");
  go_url( $sess->url(self_base() . "se_fields.php3"));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

// Check permissions
if (! $category && $group_id ) {
    $SQL = "SELECT * FROM constant_slice INNER JOIN slice 
    	ON constant_slice.slice_id = slice.id 
    	WHERE group_id='$group_id'";
    
    $db->query ($SQL);
      
    if ($db->next_record() && !CheckPerms( $auth->auth["uid"], "slice", unpack_id($db->f("slice_id")), PS_FIELDS)) {
        MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS_GROUP." (".$db->f("name").")", "admin");
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

function propagateChanges ($cid, $newvalue, $short=true)
{
	global $db, $group_id, $Msg;
	$db->query ("SELECT value FROM constant WHERE ".
		($short ? "short_id=$cid" : "id='$cid'"));
	if (!$db->next_record()) return;
	$oldvalue = addslashes($db->f("value"));
	if ($oldvalue == $newvalue) return;
	$db->query ("
		SELECT item_id,field_id
		FROM content, field WHERE field.id=content.field_id
		AND (field.input_show_func LIKE '___:$group_id:%'
        OR  field.input_show_func LIKE '___:$group_id')
		AND content.text = '$oldvalue'");
	$db1 = new DB_AA;
	$cnt = 0;
	while ($db->next_record()) {
		++$cnt;
		$db1->query ("
			UPDATE content SET text='$newvalue'
			WHERE item_id='".$db->f("item_id")."' 
			AND field_id='".$db->f("field_id")."' 
			AND text='$oldvalue'");
	}
	if ($cnt) $Msg .= $cnt . L_CONSTANT_ITEM_CHNG . "'$newvalue'<br>";
}

hcUpdate();

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

	if ($group_id) {
		 // if there is no group owner, promote this slice to owner
		 $db->query ("SELECT * FROM constant_slice WHERE group_id='$group_id'");
		 if (!$db->next_record()) $db->query ("
			INSERT INTO constant_slice (slice_id,group_id,propagate)
			VALUES ('$p_slice_id','$group_id',".($propagate_changes ? 1 : 0).");");
		 else {
		 	$db->query ("
		 		UPDATE constant_slice SET propagate=".($propagate_changes ? 1 : 0)."
			WHERE group_id = '$group_id'");
			if ($new_owner_id) {
				$db->query ("
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
      $db->query($SQL);
    }  
  
    reset($name);
    while( list($key) = each($name) ) {
		$p_cid = q_pack_id(substr($cid[$key],1));
        // if name is empty, delete the constant
        if ($name[$key] == "") {
            if( !$db->query("
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
		$db->query ("SELECT * FROM constant WHERE id='$p_cid'");
        if ($db->next_record()) {
		    if ($propagate_changes) 
                propagateChanges ($p_cid, $value[$key], false);
		    if( !$db->query("
				UPDATE constant SET " . $varset->makeUPDATE() ."
				WHERE id='$p_cid'")) {
	        	$err["DB"] .= MsgErr("Can't update constant");
		        break;
			}
    	}
		else {
			$varset->set("id", substr($cid[$key],1), "unpacked" );  # remove beginning 'x'
			$varset->set("group_id", $group_id, "quoted" );
			if( !$db->query("INSERT INTO constant " . $varset->makeINSERT() )) {
		        $err["DB"] .= MsgErr("Can't copy constant");
        		break;
      		}
		}
    }
    
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
    if( count($err) <= 1 )
      $Msg .= MsgOK(L_CONSTANTS_OK);
  } while( 0 );           #in order we can use "break;" statement
}    

  # edit categories for this slice
if( $category ) {
  $group_id = GetCategoryGroup($slice_id);
  if( $group_id )
    $categ=true;
  else {
    MsgPage($sess->url(self_base())."slicedit.php3", L_NO_CATEGORY_FIELD, "admin");
    exit;
  }
}  

  # lookup constants
if( $group_id ) {
  $SQL = "SELECT id, name, value, class, pri FROM constant
           WHERE group_id='$group_id' ORDER BY pri, name";
  $s_constants = GetTable2Array($SQL, $db, "NoCoLuMn");
}  

  # lookup apc categories classes
$SQL = "SELECT name, value, pri, id FROM constant
         WHERE group_id='lt_apcCategories' ORDER BY name";
$classes = GetTable2Array($SQL, $db, "id");


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_CONSTANTS_TIT;?></TITLE>
</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show["category"] = $xx && !$categ;
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_CONSTANTS_EDT . "</B></H1>";
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
<tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
 <td class=tabtxt><b><?php echo L_CONSTANT_GROUP ?></b></td>
 <td class=tabtxt colspan=3><?php
   echo ( $group_id ? safe($group_id) :
         "<input type=\"Text\" name=\"new_group_id\" size=16 maxlength=16 value=\"".safe($new_group_id)."\">");
   echo "
	 </td>
</tr>";

# Find slices, where the constant group is used 
if( $group_id ) {
  $delim = '';
  $db->query ("
  	SELECT slice.name FROM slice, field 
     WHERE slice.id = field.slice_id
       AND field.input_show_func LIKE '%$group_id%'");
  while( $db->next_record() ) {
    $using_slices .= $delim. $db->f('name');
    $delim = ', ';
  }
  if( $using_slices ) {
    echo "
        <tr><td><b>".L_CONSTANT_USED."</b></td>
          <td colspan=3>$using_slices</td>
        </tr>";
  }      
}


# Find the slice owner of this group
$db->query ("
	SELECT * FROM constant_slice INNER JOIN slice 
	ON constant_slice.slice_id = slice.id 
	WHERE group_id='$group_id'");
if ($db->next_record()) $owner_id = unpack_id ($db->f("slice_id"));

echo "
<tr><td><b>".L_CONSTANT_OWNER."</b></td>
<td colspan=3>";

if (!$owner_id || !$group_id) 
	echo L_CONSTANT_OWNER_HELP;
	
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
	<input type=submit name='chown' value='".L_CONSTANT_CHOWN."'>";
}
	
echo"</td></tr>
<tr><td colspan=4><input type=checkbox name='propagate_changes'".($db->f("propagate") ? " checked" : "").">".L_CONSTANT_PROPAGATE."</td></tr>
<tr><td colspan=4><input type=submit name='hierarch' value='".L_CONSTANT_HIERARCH_EDITOR."'></td></tr>
<tr>
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
/*
  if( $update ) # get values from form
    ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $class[$i], $categ, $classes, $ancestors[$i], $);
  else  
*/    ShowConstant($i, "", "", "", 1000, "", $categ, $classes);
  $i++;
}  

echo '</table>
<tr><td align="center">
  <input type=hidden name="update" value=1>
  <input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;
  <input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;
  <input type=button value="'. L_CONST_DELETE .'" onclick="deleteWholeGroup();">&nbsp;&nbsp;
  <input type=hidden name=deleteGroup value=0>
</td></tr></table>
</FORM>
<SCRIPT language=javascript>
<!--
    function deleteWholeGroup() {
        if (prompt ("'.L_CONST_DELETE_PROMPT.'","'.L_NO.'") == "'.L_YES.'") {
            document.f.deleteGroup.value = 1;
            document.f.submit();
        }
    }
//-->
</SCRIPT>
</BODY>
</HTML>';

page_close()?>
