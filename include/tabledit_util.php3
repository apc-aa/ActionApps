<?php

require $GLOBALS[AA_INC_PATH]."varset.php3";

/**   $columns is array of columns, see "fields" in tableviews.php3
*    appends ["type"] to each column, with column type
*    appends ["primary"] to primary columns, if not exist, adds them with ["view"]["type"]=hide 
*    <br>
*
*    @param $primary is an input array ("tablename" => array ("primary_field1", "primary_field2", ...)).
*                    Send only info for tables with more than 1 primary key.                        
*    @param $primary_aliases is an output array with a complete list of field aliases of primary fields 
*                       in all tables
*/

function SetColumnTypes (&$columns, &$primary_aliases, $default_table, $default_readonly=false, $primary="") {
    global $db;
    $primary_aliases = array ();
    
    // set column defaults and find all tables used in $columns
    reset ($columns);
    while (list ($colname) = each ($columns)) {
        $column = &$columns[$colname];
        setDefault ($column["table"], $default_table);
        setDefault ($column["field"], $colname);
        setDefault ($column["caption"], $colname);
        setDefault ($column["view"]["readonly"], 
            $default_readonly || $column["view"]["type"] == "userdef");
        if ($column["view"]["type"] == "date") 
            $cols = strlen (date ($column["view"]["format"], "31.12.1970"));
        setDefault ($column["view"]["size"]["rows"], 4);
        setDefault ($column["view"]["html"], false);
        setDefault ($column["view"]["type"], $column["type"]);
        if ($column["view"]["type"] == "hidden")
            $column["view"]["type"] = "hide";
        
        $tables [$column["table"]] = 1;
    }
    
    reset ($tables);
    while (list ($table) = each ($tables)) {
        $cols = $db->metadata ($table);
        reset ($cols);
        while (list (,$col) = each ($cols)) {
            // find the column
            reset ($columns);
            unset ($cprop);
            while (list ($alias) = each ($columns)) 
                if ($columns[$alias]["field"] == $col["name"] 
                    && $columns[$alias]["table"] == $table) {
                    $cprop = &$columns[$alias];
                    break;
                }
            // is it a part of the primary key?
            if ($primary && $primary[$table])
                 $is_primary = my_in_array ($col["name"], $primary[$table]);
            else $is_primary = strstr ($col["flags"], "primary_key");
            if ($is_primary) {
                // create the column if not exists
                if (!$cprop) {
                    $alias = "_".$table."_".$col["name"]."_";
                    $cprop = &$columns[$alias];
                    $cprop["table"] = $table;
                    $cprop["field"] = $col["name"];
                    $cprop["view"]["type"] = "hide";                              
                }
                else if ($cprop["view"]["type"] == "ignore")
                    echo "<h2>Column type for a primary key part must not be IGNORE.</h2>";
                //echo "primary $table . $alias";
                $cprop["primary"] = true;
                $primary_aliases[$table][$alias] = 1;
            }
            if ($cprop) {
                $cprop["type"] = $col["type"];
                $cprop["view"]["dbtype"] = $col["type"];
            }
            if (strstr ($col["flags"], "auto_increment"))
                $cprop["auto_increment"] = 1;
            if (strstr ($col["flags"], "not_null"))
                $cprop["not_null"] = 1;

            $_cols = $col["len"] ? min (80, $col["len"]) : 40;
            setDefault ($cprop["view"]["size"]["cols"], $_cols);                
        }
    }
}

// -----------------------------------------------------------------------------------

/** deletes one record identified by key values from given table
*/
function TableDelete ($table, $val, $columns, $error_msg="", $be_cautious=1) {
    global $db, $err;
    SetColumnTypes ($columns, $primary_aliases, $table);
    $varset = new CVarset;
    AddKeyValues ($varset, $val, $primary_aliases[$table], $columns);
    if ($be_cautious) {
        $db->query ($varset->makeSELECT ($table));
        if ($db->num_rows() != 1) {
			$err[] = $error_msg ? $error_msg : "Error deleting from $table. ".$db->num_rows()." rows instead of 1.";
			return false;
		}
    }
    return $db->query ($varset->makeDELETE ($table));
}

// -----------------------------------------------------------------------------------

/** inserts or updates a record
*
* @param  $action = "insert" | "update"
* @param  $primary see SetColumnTypes
* @param
* @return for "update" returns the key or "" if not successfull
*         for "insert" returns true if successfull, false if not
*/
function TableUpdate ($default_table, $val, $columns, $primary="", $error_msg="", $be_cautious=1) {
    global $db, $err;    
    SetColumnTypes ($columns, $primary_aliases, $default_table, false, $primary);

    if (!ProoveVals ($val, $columns))
        return $error_msg ? $error_msg : join ("\n", $GLOBALS["err"]);        
        
    // prepare varsets with primary key values
    reset ($primary_aliases);
    while (list ($table, $primary) = each ($primary_aliases)) {
        $varset = new CVarset;
        AddKeyValues ($varset, $val, $primary, $columns);
        $varsets [$table] = $varset;
    }
    
    // add non-key values
    reset ($columns);
    while (list ($alias, $col) = each ($columns)) {
        if (isset ($val[$alias])) {        	
            $varset = &$varsets[$col["table"]];
            $value = $val[$alias];
            if (!$col["primary"]) {
                if (is_field_type_numerical ($col["type"])) {
    				if ($value == "" && !$col["not_null"])
    					$value = "NULL";
                    $varset->add($alias,"number",$value);
    			}
                else $varset->add($alias,"quoted",$value);         
            }
        }
    }
    
    // run varsets
    reset ($varsets);
    while (list ($table) = each ($varsets)) {        
        $varset = &$varsets[$table];
        if ($be_cautious) {
            $db->query ($varset->makeSELECT ($table));
            if ($db->num_rows() != 1) {
                $err[] = "Error in TableUpdate ".$varset->makeSELECT($table).", row count is ".$db->num_rows()." instead of 1.";
                return false;
            }
        }
        $db->query ($varset->makeUPDATE ($table));
    }
    
    return true;
}

// -----------------------------------------------------------------------------------

function TableInsert (&$newkey, &$where, $table, $val, $columns, $primary="", $error_msg="", $be_cautious=1) {
    global $db, $err;

    SetColumnTypes ($columns, $primary_aliases, $table, false, $primary);
    if (!ProoveVals ($val, $columns))
        return $error_msg ? $error_msg : join ("\n", $GLOBALS["err"]);

    // prepare varsets with primary key values
    $primary = $primary_aliases [$table];
    $varset = new CVarset;
    AddKeyValues ($varset, $val, $primary, $columns, false);
    $varsets [$table] = $varset;
        
    // add non-key values
    reset ($columns);
    while (list ($alias, $col) = each ($columns)) {
        if ($col["table"] != $table)
            continue;
        if (isset ($val[$alias])) {        	
            $varset = &$varsets[$col["table"]];
            $value = $val[$alias];
            if (!$col["primary"]) {
                if (is_field_type_numerical ($col["type"])) {
    				if ($value == "" && !$col["not_null"])
    					$value = "NULL";
                    $varset->set($alias,$value,"number");
    			}
                else $varset->set($alias,$value,"quoted");         
            }
        }
    }
    
    // run varsets
    $varset = &$varsets[$table];
    $auto_inc = false;
    reset ($primary);
    while (list ($alias) = each ($primary)) 
        if ($columns[$alias]["auto_increment"])
            $auto_inc = true;
    if (!$auto_inc && $be_cautious) {
        $db->query ($varset->makeSELECT ($table));
        if ($db->num_rows() > 0) { 
            $err[] = "Error in TableInsert ".$varset->makeSELECT($tab).", row count is ".$db->num_rows()." instead of 0.";
            return "";
        }
    }
    $db->query ($varset->makeINSERT ($table)); 
    if ($auto_inc) $newkey = get_last_insert_id ($db, $table);
    else $newkey = GetKey ($table, $columns, $val);
    $where = $varset->makeWHERE ();
}

// -----------------------------------------------------------------------------------

function ProoveVals ($val, $columns) {
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
function GetKey ($table, $columns, $record)
{
    reset ($columns);
    unset ($key);
    while (list ($colname,$column) = each ($columns)) 
        if ($column["table"] == $table && $column["primary"]) {
            if ($column["view"]["unpacked"])
                $key[] = unpack_id ($record[$colname]);
            else $key[] = htmlentities ($record[$colname]); 
        }
    return join_escaped (":",$key,"#:");
}
            
// -----------------------------------------------------------------------------------    

/** creates where condition from key fields values separated by :
* Warning: send $columns processed with GetColumnTypes
*
* @param $auto_increment ... include auto increment fields
*/
function AddKeyValues (&$varset, $val, $primary, $columns, $auto_increment = true) 
{
    if (!is_array ($primary)) { echo "error in AddKeyValues"; exit; }

    reset ($primary);
    while (list ($alias) = each ($primary)) {
        $colname = $columns[$alias]["field"];
        $value = $val[$alias];
        if ($auto_increment || !$columns[$alias]["auto_increment"])
            $varset->addkey ($colname, "text", $value);
    }
}

// -----------------------------------------------------------------------------------    

function GetKeyValues ($key_val, $primary, $columns)
{
    $keys = split_escaped (":", $key_val, "#:");
    reset ($keys);

    reset ($primary);
    while (list ($alias) = each ($primary)) {
        list (,$value) = each ($keys);
        $colname = $columns[$alias]["field"];
        if ($columns[$alias]["view"]["unpacked"])
            $value = pack_id ($value);
        $retval[$colname] = $value;
    }
    return $retval;
}

// -----------------------------------------------------------------------------------    

function CreateWhereCondition ($key_val, $primary, $columns, $table)
{
    $varset = new CVarset;

    $keys = GetKeyValues ($key_val, $primary, $columns);
    reset ($keys);
    while (list ($colname, $value) = each ($keys)) 
        $varset->addkey ($colname, "text", $value);
    return $varset->makeWHERE($table);
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

function GetEditedKey ($tview) {
    global $cmd;
    $edit = $cmd[$tview]["edit"];
    if (!is_array ($edit)) {
        global $tabledit_cmd;
        $edit = $tabledit_cmd[$tview]["edit"];
        if (!is_array ($edit)) { echo "Error calling GetEditKey ($tview)"; exit; }
    }        
    reset ($edit);
    return key($edit);
}

?>