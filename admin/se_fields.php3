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

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

# lookup - APC wide possible field types are defined as special slice AA_Core_Fields..
$field_types = GetTable2Array("SELECT * FROM field 
                                WHERE slice_id='AA_Core_Fields..'", $db);

function ShowField($id, $name, $pri, $required, $show, $type="") {
  global $sess, $field_types;
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
    } else {
      $ft = GetFieldType($id);
      echo "<td class=tabtxt>". 
        ($field_types[$ft][name] =="" ? $ft : $field_types[$ft][name]) ."</td>";
    }  
    echo "  
    <td class=tabtxt><input type=\"Text\" name=\"pri[$id]\" size=4 maxlength=4 value=\"$pri\"></td>
    <td><input type=\"checkbox\" name=\"req[$id]\"". ($required ? " checked" : "") ."></td>
    <td><input type=\"checkbox\" name=\"shw[$id]\"". ($show ? " checked" : "") ."></td>";
    if( $type=="new")
      echo "<td class=tabtxt>&nbsp;</td><td class=tabtxt>&nbsp;</td>";
     else { 
      echo "<td class=tabtxt><a href=\"". $sess->url(con_url("./se_inputform.php3", "fid=".urlencode($id))) ."\">". L_EDIT ."</a></td>";
      if( $type=="in_item_tbl" )
        echo "<td class=tabtxt>". L_DELETE ."</td>";
       else 
        echo "<td class=tabtxt><a href=\"javascript:DeleteField('$id')\">". L_DELETE ."</a></td>";
      echo "</tr>\n";
    }  
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
      ValidateInput("val", L_FIELD, $val, &$err, true, "text");
      ValidateInput("prior", L_FIELD_PRIORITY, $prior, &$err, true, "number");
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
        $db->query($SQL);   # get all fields with the same type in this slice
        $max=0; 
        while( $db->next_record() ) 
          $max = max( $max, substr (strrchr ($db->f(id), "."), 1 ));
        $max++;
           #create name like "time...........2"
        $fieldid = $ftype. substr("................$max", -(16-strlen($ftype)));
        
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
        $SQL = "UPDATE field SET ". $varset->makeUPDATE() . " WHERE id='$key'";
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
      $Msg = MsgOK(L_FIELDS_OK);
      if( $name["New_Field"] )
        go_url( $sess->url($PHP_SELF) );  # reload to incorporate new field
    }    
  } while( 0 );           #in order we can use "break;" statement
}    

  # lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl
          FROM field
         WHERE slice_id='$p_slice_id' ORDER BY input_pri";
$s_fields = GetTable2Array($SQL, $db);
         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FIELDS_TIT;?></TITLE>
 <SCRIPT Language="JavaScript"><!--
   function DeleteField(id) {
     if( !confirm("<?php echo L_DELETE_FIELD; ?>"))
       return
     var url="<?php echo $sess->url(con_url("./se_inputform.php3", "del=1")); ?>"
     document.location=url + "&fid=" + escape(id);
   }
// -->
</SCRIPT>

</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>false, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_FIELDS_EDT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_FIELDS_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
 <td class=tabtxt align=center><b><?php echo L_FIELD ?></b></td>
 <td class=tabtxt align=center><b><?php echo L_FIELD_TYPE ?></b></td>
 <td class=tabtxt align=center><b><?php echo L_FIELD_PRIORITY ?></b></td>
 <td class=tabtxt align=center><b><?php echo L_NEEDED_FIELD ?></b></td>
 <td class=tabtxt align=center><b><?php echo L_FIELD_IN_EDIT ?></b></td>
 <td class=tabtxt colspan=2>&nbsp;</td>
</tr>
<tr><td colspan=7><hr></td></tr>
<?php
  reset($s_fields);
  while( list(, $v) = each($s_fields)) {
    $type = ( $v[in_item_tbl] ? "in_item_tbl" : "" );
    if( $update ) # get values from form
      ShowField($v[id], $name[$v[id]], $pri[$v[id]], $req[$v[id]], $shw[$v[id]], $type);
    else  
      ShowField($v[id], $v[name], $v[input_pri], $v[required], $v[input_show], $type);
  }
    # one row for possible new field
  ShowField("New_Field", "", "1000", false, true, "new");
  
?>  
</table>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;
</td></tr></table>
</FORM>
</BODY>
</HTML>';

/*
$Log$
Revision 1.4  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

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
