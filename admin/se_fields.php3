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

# expected $slice_id for edit slice, nothing for adding slice

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IfSlPerm(PS_FIELDS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

# lookup - APC wide possible field types are defined as special slice AA_Core_Fields..
$field_types = GetTable2Array("SELECT * FROM field 
                                WHERE slice_id='AA_Core_Fields..'", $db);

function ShowField($id, $name, $pri, $required, $show, $type="", $alias="") {
    global $sess, $field_types, $AA_CP_Session;
    $name = safe($name); $pri=safe($pri);
    echo "
    <tr>
    <td><input type=\"Text\" name=\"name[$id]\" size=25 maxlength=254 value=\"$name\"></td>";
    if( $type=="new" ){
        echo '<td class=tabtxt>
             <select name="ftype">';	
        reset($field_types);
        while(list($k, $v) = each($field_types)) { 
            echo '<option value="'. htmlspecialchars($k).'"> '. 
                              htmlspecialchars($v[name]) ." </option>";
        }
        echo "</select>\n
              </td>";
    } 
    else 
        echo "<td class=tabtxt>$id</td>";
    echo "  
        <td class=tabtxt><input type=\"Text\" name=\"pri[$id]\" size=4 maxlength=4 value=\"$pri\"></td>
        <td><input type=\"checkbox\" name=\"req[$id]\"". ($required ? " checked" : "") ."></td>
        <td><input type=\"checkbox\" name=\"shw[$id]\"". ($show ? " checked" : "") ."></td>";
    if( $type=="new")
        echo "<td class=tabtxt>&nbsp;</td><td class=tabtxt>&nbsp;</td>";
    else { 
        echo "<td class=tabtxt><a href=\"". $sess->url(con_url("./se_inputform.php3", "fid=".urlencode($id))) ."\">". _m("Edit") ."</a></td>";
        if( $type=="in_item_tbl" )
            echo "<td class=tabtxt>". _m("Delete") ."</td>";
        else 
            echo "<td class=tabtxt><a href=\"javascript:DeleteField('$id')\">". _m("Delete") ."</a></td>";
            
        if (is_array ($alias)) 
            echo "<td class=tabtxt><font size='-2'>".join($alias," ")."</font></td>";
/*        for ($i = 0; $i < count ($alias); ++$i) {
            if ($alias[$i] != "_#UNDEFINE" && $alias[$i]) 
//               $ali = "<a href='se_inputform.php3?fid=$id&AA_CP_Session=$AA_CP_Session#alias".($i+1)."'>$alias[$i]</a>";
               $ali = $alias[$i];
            else $ali = "";
            echo "<td class=tabtxt><font size='-2'>$ali</font></td>";
        }*/
    }  
    echo "</tr>\n";
}

if( $update )
{
  do {
    if( !(isset($name) AND is_array($name) ))
      break;
    reset($name);
    while( list($key,$val) = each($name) ) {
      if( $key == "New_Field" )
        continue;
      $prior = $pri[$key];
      ValidateInput("val", _m("Field"), $val, $err, true, "text");
      ValidateInput("prior", _m("Priority"), $prior, $err, true, "number");
    }
      
    if( count($err) > 1)
      break;

    reset($name);
    while( list($key,$val) = each($name) ) {
      if( $key == "New_Field" ){   # add new field
        if( $val == "" )           # if not filled - don't add the field
          continue;

          # copy fields
          # use the same setting for new field as template in AA_Core_Fields..
        $varset->clear();
        $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
        $varset->setFromArray($field_types[$ftype]);   # from template for this field

          # get new field id
        $SQL = "SELECT id FROM field 
                 WHERE slice_id='$p_slice_id' AND id like '". $ftype ."%'";
        $max=0;
        $db->query($SQL);   # get all fields with the same type in this slice
        while( $db->next_record() ) 
          $max = max( $max, substr (strrchr ($db->f(id), "."), 1 ));
        $max++;
           #create name like "time...........2"
        $fieldid = CreateFieldId ($ftype, $max);

        $varset->set("slice_id", $slice_id, "unpacked" );
        $varset->set("id", $fieldid, "quoted" );
        $varset->set("name",  $val, "quoted");
        $varset->set("input_pri", $pri[$key], "number");
        $varset->set("required", ($req[$key] ? 1 : 0), "number");
        $varset->set("input_show", ($shw[$key] ? 1 : 0), "number");
        if( !$db->query("INSERT INTO field " . $varset->makeINSERT() )) {
          $err["DB"] .= MsgErr("Can't copy field");
          break;
        }
      } else { # current field
        $varset->clear();
        $varset->add("name", "quoted", $val);
        $varset->add("input_pri", "number", $pri[$key]);
        $varset->add("required", "number", ($req[$key] ? 1 : 0));
        $varset->add("input_show", "number", ($shw[$key] ? 1 : 0));
        $SQL = "UPDATE field SET ". $varset->makeUPDATE() . 
               " WHERE id='$key' AND slice_id='$p_slice_id'";
        if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
          $err["DB"] = MsgErr("Can't change field");
          break;
        }
      }
      $r_filelds = "";   // unset the r_fields array to be load again
    }

    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

    if( count($err) <= 1 ) {
      $Msg = MsgOK(_m("Fields update successful"));
      if( $name["New_Field"] )
        go_url( $sess->url($PHP_SELF) );  # reload to incorporate new field
    }    
  } while( 0 );           #in order we can use "break;" statement
}    

  # lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl, alias1, alias2, alias3
        FROM field
        WHERE slice_id='$p_slice_id' ORDER BY input_pri";
$s_fields = GetTable2Array($SQL, $db);
         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - configure Fields");?></TITLE>
 <SCRIPT Language="JavaScript"><!--
   function DeleteField(id) {
     if( !confirm("<?php echo _m("Do you really want to delete this field from this slice?"); ?>"))
       return
     var url="<?php echo $sess->url(con_url("./se_inputform.php3", "del=1")); ?>"
     document.location=url + "&fid=" + escape(id);
   }
// -->
</SCRIPT>

</HEAD>
<?php 
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "fields");  
  
  echo "<H1><B>" . _m("Admin - configure Fields") . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Fields")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
 <td class=tabtxt align=center><b><?php echo _m("Field") ?></b></td>
 <td class=tabtxt align=center><b><?php echo _m("Id") ?></b></td>
 <td class=tabtxt align=center><b><?php echo _m("Priority") ?></b></td>
 <td class=tabtxt align=center><b><?php echo _m("Required") ?></b></td>
 <td class=tabtxt align=center><b><?php echo _m("Show") ?></b></td>
 <td class=tabtxt colspan=2>&nbsp;</td>
 <td class=tabtxt align=center><b><?php echo _m("Aliases")?></b></td>
</tr>
<tr><td colspan=8><hr></td></tr>
<?php
  if( isset($s_fields) and is_array($s_fields)) {
    reset($s_fields);
    while( list(, $v) = each($s_fields)) {
    $type = ( $v[in_item_tbl] ? "in_item_tbl" : "" );
    if( $update ) # get values from form
      ShowField($v[id], $name[$v[id]], $pri[$v[id]], $req[$v[id]], $shw[$v[id]], $type, 
        array ($v[alias1], $v[alias2], $v[alias3]));
    else  
      ShowField($v[id], $v[name], $v[input_pri], $v[required], $v[input_show], $type,
        array ($v[alias1], $v[alias2], $v[alias3]));
    }
  }  
    # one row for possible new field
  ShowField("New_Field", "", "1000", false, true, "new");
  
?>  
</table>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;
</td></tr></table>
</FORM>';
HtmlPageEnd();
page_close()?>
