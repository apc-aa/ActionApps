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

/* (c) Jakub Adamek, May 2002

   This script allows to change a field ID in hopefully all tables where it occurs.
   
   The array maintain_fields contains database fields to be checked. All such fields
   are downloaded from database, the old ID occuring anywhere in them is changed to the
   new one and the fields are uploaded back. 
       
   Some texts cannot be described in this easy way, so maintain_sql may contain other 
   SQL commands. You may use the :old_id: and :new_id: strings which will be replaced by the old / new ids.
*/

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

set_time_limit(600);

//$debug = 1;

$maintain_fields = array (
    "slice" => array (
            "primary" => "id",  // primary key
            "primary_type" => "text", // key type (text / number)
            "slice_id" => "id", // slice_id key
            "fields" => array (     // fields to be changed
                "fulltext_format_top",
                "fulltext_format",
                "fulltext_format_bottom",
                "odd_row_format",
                "even_row_format",
                "compact_top",
                "compact_bottom",
                "category_top",
                "category_format",
                "category_bottom",
                "admin_format_top",
                "admin_format",
                "admin_format_bottom",
                "aditional",
                "javascript")),
    "field" => array (
            "primary_part" => "id", // the whole key is (id,slice_id)
            "primary_type" => "text", 
            "slice_id" => "slice_id",
            "fields" => array (
                "id",
                "alias1_func",
                "alias2_func",
                "alias3_func")),
    "view" => array (
            "primary" => "id", 
            "primary_type" => "number", 
            "slice_id" => "slice_id",
            "fields" => array (
                "before",
                "even",
                "odd",
                "after",
                "group_title",
                "order1",
                "order2",
                "group_by1",
                "group_by2",
                "cond1field",
                "cond2field",
                "cond3field",
                "aditional",
                "aditional2",
                "aditional3",
                "aditional4",
                "aditional5",
                "aditional6",
                "group_bottom",
                "field1",
                "field2",
                "field3")));

$maintain_sql = array (
    "UPDATE feedmap SET to_field_id=':new_id:' 
     WHERE to_field_id=':old_id:' AND to_slice_id = '$p_slice_id'" ,
    "UPDATE feedmap SET from_field_id=':new_id:' 
     WHERE from_field_id=':old_id:' AND from_slice_id = '$p_slice_id'");
        
 
if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));
  

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

function tryQuery (&$db, $sql, $always=1) {
    global $debug;
    if (!$always) echo $sql;
    else if ($debug) $db->dquery ($sql);
    else $db->query ($sql);
}

function ChangeFieldID ($old_id, $new_id)
{
    global $db, $maintain_fields, $maintain_sql, $p_slice_id;
    
    $varset = new Cvarset();
    reset ($maintain_fields);
    while (list ($table,$settings) = each ($maintain_fields)) {
        $keyfield = $settings[primary];
        if (!$keyfield) $keyfield = $settings[primary_part];
        $SQL = "SELECT $keyfield,".join($settings[fields],",")." FROM $table 
                WHERE $settings[slice_id] = '$p_slice_id'";
        $rows = GetTable2Array ($SQL, $db);
        reset ($rows);
        $i = 0;
        while (list (,$row) = each ($rows)) {
//            echo "Table $table, record ".$i++;
            $varset->clear();
            reset ($settings[fields]);
            while (list (,$field) = each ($settings[fields])) {
                $cont = $row[$field];
//                echo $cont." ";
                if (strstr ($cont, $old_id)) {
                    $cont = str_replace ($old_id, $new_id, $cont);
                    $varset->set ($field, $cont, "quoted");
                }
            }
            if ($varset->vars) {
                $SQL = "UPDATE $table SET ".$varset->makeUPDATE();
                $SQL .= " WHERE $keyfield = ";
                if ($settings[primary_type] == "text")
                     $SQL .= "'".$row[$keyfield]."'";
                else $SQL .= $row[$keyfield];
                if ($settings[primary_part])
                     $SQL .= " AND $settings[slice_id] = '$p_slice_id'";
                tryQuery ($db,$SQL);
            }           
        }
    }

    reset ($maintain_sql);
    while (list (,$sql) = each ($maintain_sql)) {
        $sql = str_replace (":old_id:", $old_id, $sql);
        $sql = str_replace (":new_id:", $new_id, $sql);
        tryQuery ($db, $sql);
    }
        
    // replace the field id in table content
    $db->query ("SELECT id FROM item WHERE slice_id='$p_slice_id'");
    while ($db->next_record()) 
        $item_ids[] = myaddslashes ($db->f("id"));
    if (count ($item_ids)) tryQuery($db, 
        "UPDATE content SET field_id='$new_id'
         WHERE item_id IN ('".join($item_ids,"','")."') AND field_id='$old_id'");
}
    
if ($update && $new_id_text && $p_slice_id) {
    $nchanges = 0;
    if (strlen ($new_id_text) + strlen ($new_id_number) <= 16) {
        $new_id = $new_id_text;
        for ($i = 0; $i < 16 - strlen($new_id_text) - strlen($new_id_number); ++$i)
            $new_id .= ".";
        $new_id .= $new_id_number;
        // proove the field does not exist
        $db->query ("SELECT id FROM field WHERE slice_id='$p_slice_id' AND id='$new_id'");
        if ($old_id != $new_id && strlen ($new_id) == 16 && !$db->next_record()) {
            $nchanges ++;
            ChangeFieldID ($old_id, $new_id);
        }
    }
}

  # lookup fields
$SQL = "SELECT id, name FROM field
        WHERE slice_id='$p_slice_id'
        ORDER BY id";
$db = new DB_AA;
$s_fields = GetTable2Array($SQL, $db);
         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FIELD_IDS_TIT;?></TITLE>

</HEAD>
<?php 
  $show ["field_ids"] = false;
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_FIELD_IDS_TIT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
  if ($update) echo "$nchanges ".L_FIELD_IDS_CHANGED.".<br>";

echo "
<form method=post action='".$sess->url($PHP_SELF)."'>
<table width=500 border=0 cellspacing=0 cellpadding=1 bgcolor='".COLOR_TABTXTBG."' align=center>
<tr><td class=tabtit>".L_FIELD_ID_HELP."</td></tr>
<tr><td class=tabtit align=center><br>".L_CHANGE_FROM.": <select name='old_id'>";
reset ($s_fields);
while (list (,$field) = each ($s_fields)) 
    echo "<option value='$field[id]'>$field[id]";
echo "</select> ".L_TO." <select name='new_id_text'>";
$db->query ("SELECT id FROM field 
             WHERE slice_id='AA_Core_Fields..'");
while ($db->next_record()) {
    $id_text = $db->f("id");
    echo "<option value='$id_text'>$id_text";
}
echo "</select> <select name='new_id_number'>
<option value='.'>.";
for ($i = 1; $i < 100; ++$i)
    echo "<option value='$i'>$i";
echo "</select><br><br>
    <input type=hidden name=\"update\" value=1>
    <input type=submit name=update value='". L_UPDATE ."'>&nbsp;&nbsp;
    <input type=submit name=cancel value='". L_CANCEL ."'>
    </td></tr></table>";
?>
<br>
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr height=10><td class=tabtxt colspan=2></td></tr>
<tr>
 <td class=tabtxt align=left><b>&nbsp;&nbsp;<?php echo L_FIELD_TYPE ?></b></td>
 <td class=tabtxt align=left><b>&nbsp;&nbsp;<?php echo L_FIELD ?></b></td>
</tr>
<tr><td colspan=2 class=tabtxt><hr></td></tr>
<?php 
    reset ($s_fields);
    while (list (,$field) = each ($s_fields)) {
        echo "
        <tr>
        <td class=tabtxt align=left>&nbsp;&nbsp;$field[id]&nbsp;&nbsp;</td>
        <td class=tabtxt>&nbsp;&nbsp;<b>$field[name]&nbsp;&nbsp;</b></td></tr>";
    }
echo "
</table>
</FORM>
</BODY>
</HTML>";

page_close()?>
