<?php

require $GLOBALS[AA_INC_PATH]."varset.php3";

/**   $columns is array of columns, see "fields" in tableviews.php3
*    appends ["type"] to each column, with column type
*    appends ["primary"] to primary columns, if not exist, adds them with ["view"]["type"]=hide 
*    <br>
*    if table has more than 1 primary key, send the chosen one in $primary
*/

function GetColumnTypes ($table, $columns, $primary="") {
    global $db;
    //echo "TABLE $table";
    if (!$table) {
        echo "Error in GetColumnTypes";
        return $columns;
    }
    $cols = $db->metadata ($table);
    reset ($cols);
    while (list (,$col) = each ($cols)) {
        $cname = $col["name"];
        if (!isset ($columns[$cname])) 
            $columns[$cname]["view"]["type"] = "ignore";
        $columns[$cname]["type"] = $col["type"];
        $columns[$cname]["view"]["dbtype"] = $col["type"];
        if (is_array ($primary))
             $is_primary = my_in_array ($cname, $primary);
        else $is_primary = strstr ($col["flags"], "primary_key");
        if ($is_primary) {
            if (!$columns[$cname])
                $columns[$cname]["view"]["type"] = "hide";                              
            $type = is_field_type_numerical ($col["type"]) ? "number" : "text";
            $columns[$cname]["primary"] = $type;
        }
        if (strstr ($col["flags"], "auto_increment"))
            $columns[$cname]["auto_increment"] = 1;
        if (strstr ($col["flags"], "not_null"))
            $columns[$cname]["not_null"] = 1;
		if ($col["len"])
			$columns[$cname]["len"] = $col["len"];
    }
    
    // fill the values for columns from joined tables
    reset ($columns);
    $metadata = array ();
    while (list ($cname, $cprop) = each ($columns)) {
        if (!isset ($cprop["table"]) || $cprop["table"] == $table) 
            continue;
        if (!isset ($metadata[$cprop["table"]]))
            $metadata[$cprop["table"]] = $db->metadata ($cprop["table"]);
        $cols = $metadata[$cprop["table"]];
        reset ($cols);
        while (list (,$col) = each ($cols)) 
            if ($col["name"] == $cname) 
                break;
        $columns[$cname]["type"] = $col["type"];
        $columns[$cname]["view"]["dbtype"] = $col["type"];
        if (strstr ($col["flags"], "auto_increment"))
            $columns[$cname]["auto_increment"] = 1;
        if (strstr ($col["flags"], "not_null"))
            $columns[$cname]["not_null"] = 1;
		if ($col["len"])
			$columns[$cname]["len"] = $col["len"];
    }
    
    return $columns;
}

// -----------------------------------------------------------------------------------

/** deletes one record identified by key values from given table
*/
function TableDelete ($table, $key_value, $columns, $error_msg="", $be_cautious=1) {
    global $db, $err;
    $columns = GetColumnTypes ($table, $columns);
    $where = CreateWhereCondition ($key_value, $columns);
    if ($be_cautious) {
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) {
			$err[] = $error_msg ? $error_msg : "Error deleting from $table. ".$db->num_rows()." rows instead of 1.";
			return false;
		}
    }
    return $db->query ("DELETE FROM $table WHERE $where");
}

// -----------------------------------------------------------------------------------
   
/** updates one record identified by key values in given table    
*/
function TableUpdate ($table, $join, $key_value, $val, $columns, $error_msg="",  $be_cautious=1) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    if (!ProoveVals ($table, $val, $columns))
        return $error_msg ? $error_msg : join ("\n", $GLOBALS["err"]);
    $varsets = array (); 
    reset ($columns);
    while (list ($colname, $col) = each ($columns)) {
        if (isset ($val[$colname])) {        	
        	setdefault ($col["table"], $table);
            if (!isset ($varsets[$col["table"]]))
                $varsets[$col["table"]] = new CVarset();
            $varset = &$varsets[$col["table"]];
            $value = $val[$colname];
            if (get_magic_quotes_gpc()) 
                $value = stripslashes ($value);
            if (is_field_type_numerical ($col["type"])) {
				if ($value == "" && !$col["not_null"])
					$value = "NULL";
                 $varset->set($colname,$value,"number");
			}
            else $varset->set($colname,$value,"text");         
        }
    }

    
    unset ($varset);
    reset ($varsets);
    while (list ($tab, $varset) = each ($varsets)) {        
        $where = CreateWhereCondition ($key_value, $columns, $tab, $join);
        if ($be_cautious) {
            $db->query ("SELECT * FROM $tab WHERE $where");
            if ($db->num_rows() != 1) {
                global $err;
                $err[] = "Error in TableUpdate SELECT * FROM $tab WHERE $where, row count is ".$db->num_rows()." instead of 1.";
                return false;
            }
        }
        $db->query ("UPDATE $tab SET ".$varset->makeUPDATE()." WHERE $where");
    }
    
    return true;
}

// -----------------------------------------------------------------------------------

/** inserts a record and returns the key or "" if not successfull
*
* @param  $primary = array (field1,field2,...) - if the table has more than 1 primary key, you   
*                 must send the correct one
*/
function TableInsert ($table, $val, $columns, $primary="", $error_msg="", $be_cautious=1) {
    global $db, $err;
    $columns = GetColumnTypes ($table, $columns, $primary);
    if (!ProoveVals ($table, $val, $columns)) { return ""; }
    $varset = new CVarset();
    reset ($columns);
    while (list ($colname, $col) = each ($columns)) {
        $is_key = false;
        if ($col["primary"]) {
            if ($col["auto_increment"])
                $auto_inc = true;
            else if (!$val[$colname]) 
                { $err[] = $error_msg ? $error_msg : "Error: Primary column $colname not set."; return ""; }
            else $key[] = $val[$colname];
        }
        if (isset ($val[$colname])) {
            $value = $val[$colname];
            if (get_magic_quotes_gpc()) 
                $value = stripslashes ($value);
            if (is_field_type_numerical ($col["type"]))
                 $varset->set($colname,$value,"number");
            else $varset->set($colname,$value,"text");         
        }
    }
   
    if ($be_cautious && !$auto_inc) {
        $key = join_escaped (":", $key, "#:");
        $where = CreateWhereCondition ($key, $columns);
        $db->query ("SELECT COUNT(*) AS key_already_used FROM $table WHERE $where");
        $db->next_record();
        if ($db->f("key_already_used") > 0)
            { $err[] = $error_msg ? $error_msg : "Error inserting to $table: A row with the same primary key ($key) already exists."; return ""; }
    }
    
    $ok = $db->query ("INSERT INTO $table ".$varset->makeINSERT());
    if (!$ok) { $err[] = $error_msg ? $error_msg : "DB error on inserting record to $table"; return ""; }
    if ($auto_inc) return get_last_insert_id ($db, $table);
    else return join_escaped (":", $key, "#:");
}

// -----------------------------------------------------------------------------------

function ProoveVals ($table, $val, $columns) {
    global $err;
    while (list ($colname, $column) = each ($columns)) {
        if ($column["validate"] || $column["required"]) {
            if (!ValidateInput ($colname, $colname, $val[$colname], $err, $column["required"], $column["validate"]))
                return false;
            if ($column["validate_min"] && $column["validate"] == "number") {
                if ($val[$colname] < $column["validate_min"] || $val[$colname] > $column["validate_max"]) {
                    $err[$colname] = "Value of $colname should be between $column[validate_min] and $column[validate_max].";
                    return false;
                }
            }
        }
    }
    return true;
}

// -----------------------------------------------------------------------------------

/** creates key string with values from key fields separated by :
*/
function GetKey ($columns, $record)
{
    reset ($columns);
    unset ($key);
    while (list ($colname,$column) = each ($columns)) 
        if ($column["primary"]) {
            if ($column["view"]["unpacked"])
                $key[] = unpack_id ($record[$colname]);
            else $key[] = htmlentities ($record[$colname]); 
        }
    return join_escaped (":",$key,"#:");
}
            
// -----------------------------------------------------------------------------------    

/** creates where condition from key fields values separated by :
* Warning: send $columns processed with GetColumnTypes
*/
function CreateWhereCondition ($key_value, $columns, $table="", $join="") {
    $key_values = split_escaped (":", $key_value, "#:");
    if (!is_array ($key_values))
        return " 0 ";
    reset ($key_values);
    reset ($columns);
    $where = array();
    while (list ($colname,$column) = each ($columns)) {
        if (!$column["primary"])        
            continue;
        list (,$val) = each ($key_values);
        if ($join[$table]) {
            $colname = $join[$table]["joinfields"][$colname];
            if (!$colname) 
                continue;
        }        
        if ($column["primary"]) {
            if ($column["view"]["unpacked"])
                $val = pack_id ($val);
            $where[] = ($table ? $table."." : "")
                .$colname."='".addslashes ($val)."'";
        }
    }
    return join (" AND ",$where);
}

// -----------------------------------------------------------------------------------    

function PrintJavaScript_Validate () {
    global $_javascript_validate_printed;
    if ($_javascript_validate_printed) return;
    else $_javascript_validate_printed = 1;
    
    echo "
    <script language=javascript>
    <!--"
        . get_javascript_field_validation ()."
        
        function validate_number (txtfield, minval, maxval, required) {
            if (!validate (txtfield, 'number', required))
                return false;
            var val = txtfield.value;
            var err = '';
            if (val > maxval || val < minval) 
                err = '"._m("Wrong value: a number between %1 and %2 is expected.",array("'+minval+'","'+maxval+'"))."';
            if (err != '') {
                alert (err);
                txtfield.focus();
                return false;
            }
            else return true;
        }
        
        function confirmDelete (url) {
            if (confirm ('"._m("Are you sure you want to permanently DELETE this record?")."'))
                goto_url (url);
        }
        
        function goto_url (url)
        { window.location = url; }
        
        function exec_commit (formname, ctrlName) {
            var f=document.forms[formname]; 
            f[ctrlName].value=1; 
            f.submit();
        }
    // -->
    </script>";   
}

?>