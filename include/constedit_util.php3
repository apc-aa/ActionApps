<?php 
/*  Author: Jakub Adámek, February 2002

	The "util" create a string $consts, which contains the JavaScript array
	with all constants, prepare some JavaScript constants and call the constedit.js
	JavaScript.
	
	Function "showHierConstBoxes" paints a table with level boxes.
	Function "showHierConstInitJavaScript (group_id)" prints JavaScript definitions needed for the editor
	Function "hcUpdate" deletes and updates all things in Admin panel
*/

if (!defined ("AA_CONSTEDIT_UTIL_INCLUDED"))
    define ("AA_CONSTEDIT_UTIL_INCLUDED", 1);
else return;

$hcCol = array (
	"Name" => 0,
	"Value"=>1,
	"Prior"=>2,
	"Desc"=>3,
	"ID" => 4,
	"Dirty" => 5,
	// always leave colChild as the last one
	"Child" => 6);

/*  Params: 
		group_id - name of constant group
		levelCount - count of level boxes
		formName - from <form name="formName"></form>
		admin - if true, send all info (for constants admin)
*/	

function showHierConstInitJavaScript ($group_id, $levelCount=3, $formName='f', $admin=true)
{
	global $hcCol;
	echo "
	<script language=javascript>
	<!--";
		//if set to 1, doesn't uncheck the confirmation check box
		echo "
	    hcEasyDelete = 0;";
		// consts columns: name, value, priority, description, ID, dirty flag, children
		echo "
		colName = ".$hcCol['Name'].";
		colValue = ".$hcCol['Value'].";
		colPrior = ".$hcCol['Prior'].";
		colDesc = ".$hcCol['Desc'].";
		colID = ".$hcCol['ID'].";
		colDirty = ".$hcCol['Dirty'].";
		colChild = ".($admin ? $hcCol['Child'] : $hcCol['Prior']).";
	
		// count of levels in hierarchy
		hcLevelCount = $levelCount;
		
		// name of form in which are the fields
		hcForm = '$formName';";
	
		// this will be supplied by the database
		echo "
		var hcConsts = ".createConstsJavaScript ($group_id, $admin).";
	// -->
	</script>
	<script language=javascript src=\"".$GLOBALS[AA_INSTAL_PATH]."javascript/constedit.js\"></script>";
}

/* paints horizontally or vertically the level boxes in a table. Params: 
		levelCount - count of boxes
		horizontal - should be the boxes placed horizontal?
		targetBox - where to put selected values (usefull by admin=false)
		admin - are the boxes in admin interface? 
			if yes, buttons are "Add new" and "Select",
			else, buttons are "Select" - moves to the targetBox
		minLevelSelect - from which level should be the "Select" button shown
        levelNames - names for the level boxes (if you don't like Level 0, Level 1, etc.)
*/


function showHierConstBoxes ($levelCount, $horizontal=0, $targetBox="", $admin=true, 
	$minLevelSelect=0, $boxWidth=0, $levelNames=array())
{
	$admin = $admin ? 1 : 0;
	if ($boxWidth == 0) $boxWidth = $horizontal ? 30 : 70;

	echo "<table border=0 cellpadding=3>";
	if ($horizontal) echo "<tr>";
	for ($i=0; $i < $boxWidth; ++$i) $widhtTxt .= "m";
		
	for ($i=0; $i < $levelCount; ++$i) {
		if ($admin) {
			$buttonAdd = "<input type=button value=\""._m("Add new")."\" onClick=\"hcAddNew($i)\">";
			$buttonSelect = "<input type=button value=\""._m("Select")."\" onClick=\"hcSelectItem($i,1)\">";
		}
		else {
			$buttonAdd = "";
			if ($minLevelSelect > $i) $buttonSelect = "";
			else $buttonSelect = "<input type=button value=\""._m("Select")."\" onClick=\"hcAddItemTo ($i,'$targetBox');\">";
		}
        if (!$levelNames[$i]) $levelNames[$i] = _m("Level")." $i";
		if ($horizontal)
			echo "
			<td align=left valign=top width='10%'>
				<b>".$levelNames[$i]."</b><br>
		 		<select name=\"hclevel$i\" multiple size=10 onChange=\"hcSelectItem($i,$admin)\">
				<option>$widthTxt</select>
				<br><br>$buttonAdd&nbsp;&nbsp;$buttonSelect
			</td>";
		else
			echo "
			<tr><td align=right valign=top>
	  		    <b>".$levelNames[$i]."</b><br>$buttonAdd<br>
				<img src=\"../images/spacer.gif\" width=1 height=2><br>$buttonSelect
				</td><td>
		 		<select name=\"hclevel$i\" multiple size=4 onChange=\"hcSelectItem($i,$admin)\">
				<option>$widhtTxt</select>
			</td></tr>";
	}

	if ($horizontal) echo "</tr>";
	echo "</table>";
}

/** creates string forming JavaScript array definition
	params:
		group_id - name of constants group
		admin - admin pages
*/
function createConstsJavaScript ($group_id, $admin) 
{
	createConstsArray ($group_id, $admin, $myconsts);
	//return $myconsts;
	eval ('$data = '.$myconsts.';');
	//print_r ($data); 
	
	hcSortArray ($data);

	$consts = "new Array(";
	for ($i=0; $i < count($data); ++$i) {
		if ($i) $consts .= ",";
		$consts .= printConstsArray ($data[$i], $admin);
	}
	$consts .= ")";
	return $consts;
}

// creates string forming PHP array definition

function createConstsArray ($group_id, $admin, &$consts)
{
	$dbc = new DB_AA;
	
	$data = array ();
	$dbc->query("SELECT * FROM constant WHERE group_id = '$group_id'");
	while ($dbc->next_record()) {
		$value = str_replace ("&","%26",ff($dbc->f("value")));
		if (ff($dbc->f("name")) == $value)
			$value = '#';
		$data[$dbc->f("ancestors").$dbc->f("id")] = "'"
				.ff($dbc->f("name"))."','"
				.$value."',"
				.ff($dbc->f("pri")).",'"
				.($admin ? ff($dbc->f("description")) : "")."',"
				.ff($dbc->f("short_id")).","
				."false";
	}
		
	ksort ($data);

	$path = "";
	$consts = "array(";
	$error_data = array();
	$ok_data = 0;
    $depth = 0;
	reset ($data);
	while (list ($ancestors,$col) = each ($data)) {
		$error = false;
		if (!$path) {
			if (strlen($ancestors) != 16)
				$error = true;
			else 
                $consts .= "array($col";
		}
		else if (strlen($ancestors) % 16 != 0)
			$error = true;
		// step over one layer
		else if (strlen($ancestors) > strlen($path)
			&& substr ($ancestors,0,strlen($path)) == $path) {
			if (strlen($ancestors)-strlen($path) == 16) {
				$consts .= ",array(array($col";
                $depth++;
            }
			else $error = true; // error: missing layer, jump over 
		}
		else if (strlen($ancestors) == strlen($path)) {
			if (substr($ancestors,0,strlen($path)-16) != substr($path,0,strlen($path)-16))
				$error = true;
			else $consts .= "),array($col";
		}
		else {
			$consts .= ")";
			$level=0;
			while (substr($ancestors,0,$level*16) == substr($path,0,$level*16)) 
				++$level;
			for ($i = 0; $i < strlen($path) / 16 - $level && $depth > 0; ++$i) {
				$consts .= "))";
                $depth --;
            }
			$consts .= ",array($col";
		}
		if ($error)
			$error_data[] = $col;
		else {
			$path = $ancestors;	
			++$ok_data;
		}
	}
	if ($ok_data) $consts .= ")";
	for ($i = 0; $i < $depth; ++$i)
		$consts .= "))";
	if (count($error_data)) {
		if ($ok_data) $consts .= ",";
		reset ($error_data);
		while (list (,$col) = each ($error_data))
			$consts .= "array($col),";
		$consts = substr ($consts,0,strlen($consts)-1);
	}
	
	$consts .= ")";
}


function hcCompareConstants ($a, $b) {
	global $hcCol;
	if ($a[$hcCol["Prior"]] > $b[$hcCol["Prior"]])
		return 1;
	else if ($a[$hcCol["Prior"]] < $b[$hcCol["Prior"]])
		return -1;
	else return $a[$hcCol["Name"]] > $b[$hcCol["Name"]];
}

function hcSortArray (&$arr) {
	global $hcCol;
	usort ($arr, "hcCompareConstants");
	for ($i = 0; $i < count ($arr); ++$i) 
		if (count ($arr[$i]) > $hcCol["Child"]) 
			hcSortArray ($arr[$i][$hcCol["Child"]]);
}

function ff ($str) {
	return str_replace ("\r","",str_replace ("\n","",str_replace ("'","\\'",$str)));
}

function printConstsArray (&$arr, $admin) {
	global $hcCol;
	$value = ff($arr[$hcCol["Value"]]);
	if (ff($arr[$hcCol["Name"]]) == $value)
		$value = '#';
	$retval = "new Array('"
			.ff($arr[$hcCol["Name"]])."','"
			.$value."'".
			($admin ? ","
			.ff($arr[$hcCol["Prior"]]).",'"
			.ff($arr[$hcCol["Desc"]])."',"
			.ff($arr[$hcCol["ID"]]).","
			."false"
			: "");
	if (count ($arr) > $hcCol["Child"]) {
		$retval .= ",new Array(";
		for ($i=0; $i < count($arr[$hcCol["Child"]]); ++$i) {
			if ($i) $retval .= ",";
			$retval .= printConstsArray ($arr[$hcCol["Child"]][$i], $admin);
		}
		$retval .= ")";
	}
	$retval .= ")";
	return $retval;
}

function hcUpdate ()
{	
	global $db, $levelCount, $hide_value, $levelsHorizontal, $group_id, $p_slice_id;

	$db->query("SELECT * FROM constant_slice WHERE group_id = '$group_id'");
	if ($levelCount) {
		if ($db->next_record())
			$db->query(
				"UPDATE constant_slice SET levelcount=$levelCount,
				horizontal=".($levelsHorizontal ? 1 : 0).", 
				hidevalue=".($hide_value ? 1 : 0)." 
				WHERE group_id='$group_id'");
		else $db->query(
				"INSERT INTO constant_slice (group_id,slice_id,horizontal,hidevalue,levelcount)
				VALUES ('$group_id','$p_slice_id',".($levelsHorizontal ? 1 : 0)
				.",".($hide_value ? 1 : 0).",".$levelCount.")");
	}
	else {
		$hide_value = 0;
		$levelCount = 2;
		$levelsHorizontal = 0;
		if ($db->next_record()) {
			$hide_value = $db->f("hidevalue");
			$levelCount = $db->f("levelcount");
			$levelsHorizontal = $db->f("horizontal");
		}
	}

	global $hcalldata, $varset;
	if ($hcalldata) {
        if (get_magic_quotes_gpc()) 
            $hcalldata = stripslashes ($hcalldata);
		$hcalldata = str_replace ("\\'","'",$hcalldata);
        $hcalldata = str_replace ("\\:", "--$--", $hcalldata);
        $hcalldata = str_replace ("\\~", "--$$--", $hcalldata);
        $chtag = ":changes:";
        if (strstr ($hcalldata, "$chtag")) {
            $changes = substr ($hcalldata, strpos ($hcalldata,$chtag) + strlen($chtag) + 1);
            $hcalldata = substr ($hcalldata, 0, strpos ($hcalldata,$chtag) - 1);
            $chs = split (":", $changes);
            $changes = array ();
            reset ($chs);
            while (list(,$ch) = each ($chs)) {
                if (!strchr ($ch,"~")) continue;
                $ar = split ("~",$ch);
                for ($i=0; $i < count($ar); ++$i)
                    $ar[$i] = str_replace ("--$$--","~",str_replace("--$--",":",$ar[$i]));
                $changes[] = $ar;
            }
        }
    }
	
	// delete items
	if ($hcalldata > "0")
        $db->query("DELETE FROM constant WHERE short_id IN ($hcalldata)");

	// update items

	if (is_array ($changes)) {
		$db->query("SELECT id, short_id FROM constant;");
		while ($db->next_record())
			$shortIDmap [$db->f("short_id")] = addslashes($db->f("id"));

        $db->query("SELECT propagate FROM constant_slice WHERE group_id='$group_id'");
        if ($db->next_record())
            $propagate_changes = $db->f("propagate");
        else $propagate_changes = false;
	
		reset ($changes);
		while (list (,$change) = each($changes)) {
			$column_id = 4;
			$column_ancestors = 5;
			for ($i = 0; $i < $column_id; ++$i) 
				$change[$i] = str_replace ("'","\\'",$change[$i]);
			$varset->clear();
			$varset->set("name",  $change[0], "quoted");
			$varset->set("value", $change[1], "quoted");
			$varset->set("pri", ( $change[2] ? $change[2] : 1000), "number");
			$varset->set("description", $change[3], "quoted");

			$newvalue = $change[1];
			$new_id = $change[$column_id];
			if (substr($new_id,0,1) == "#") {
				$id = q_pack_id (new_id());
				$shortIDmap[$new_id] = $id;
				$ancestors = "";
				$path = split (",",$change[$column_ancestors]);
				reset ($path);
				while (list (,$myid) = each ($path)) {
					if (!$myid) continue;
					$ancestors .= $shortIDmap[$myid];
				}
				$varset->set("id",$id,"quoted");
				$varset->set("group_id",$group_id,"quoted");
				$varset->set("ancestors",$ancestors,"quoted");
				$db->query("INSERT INTO constant ".$varset->makeINSERT());
			}
			else {
                if ($propagate_changes) 
    				propagateChanges ($new_id, $newvalue);
				$db->query("UPDATE constant SET ".$varset->makeUPDATE()
					." WHERE short_id = ".$new_id);
			}
		}
	}
}

// Copy and rename constant groups in slice $slice_id so that they are not shared with other slices
// WARNING: doesn't work when the group id contains a ":" ??
// find new group_id by trying to add "_1", "_2", "_3", ... to the old one

function CopyConstants ($slice_id)
{ 
    global $db, $err, $debug;
 
    // max. length of the group_id field 
    $max_group_id_len = 16;
    $q_slice_id = q_pack_id ($slice_id);
    
    $db->query("SELECT name FROM constant WHERE group_id='lt_groupNames'");
    while ($db->next_record())
        $group_list[] = $db->f("name");
    $db->query("SELECT id, input_show_func FROM field WHERE slice_id ='$q_slice_id'");    while ($db->next_record()) {
        $shf = $db->f("input_show_func");
        if (strlen ($shf) > 4) {
            list (,$group_id) = split (":",$shf);
            if (my_in_array ($group_id, $group_list)) 
                $group_ids[$group_id][$db->f("id")] = $shf;
        }
    }
    
    if (!is_array ($group_ids))
        return true;
            
    reset ($group_ids);
    while (list ($old_id, $fields) = each ($group_ids)) {
    
        // find new id by trying to add "_1", "_2", "_3", ... to the old one
        $new_id = $old_id;
        for ($i = 1; my_in_array ($new_id, $group_list); $i ++) {
            $postfix = "_".$i;
            $new_id = substr ($old_id,0,
                min (strlen($old_id)+strlen($postfix), $max_group_id_len) - strlen($postfix))
                . $postfix;
        }
        $group_list[] = $new_id;
        
        if ($debug) echo "Changing $old_id to $new_id.<br>";
        
        // copy group name in table constant
      	if (!CopyTableRows (
    		"constant", 
    		"group_id='lt_groupNames' AND name='$old_id'", 
    		array ("name"=>$new_id,"value"=>$new_id), // set_columns
    		array ("short_id"),                       // omit_columns
            array ("id")                              // id_columns
            )) {
    	    $err[] = "Could not copy constant group.";
            return false;
        }

        // copy group values in table constant
      	if (!CopyTableRows (
    		"constant", 
    		"group_id='$old_id'", 
    		array ("group_id"=>$new_id),              // set_columns
    		array ("short_id"),                       // omit_columns
            array ("id")                              // id_columns
            )) {
    	    $err[] = "Could not copy constant group.";
            return false;
        }
        
        // update fields
        reset ($fields);
        while (list ($field_id, $shf) = each ($fields)) {
            if (!$db->query("UPDATE field SET input_show_func = '"
                .addslashes(str_replace ($old_id, $new_id, $shf))."'
                WHERE id='$field_id' AND slice_id='$q_slice_id'")) {
                $err[] = "Could not update fields.";
                return false;
            }
        }
    }

    return true;
}

