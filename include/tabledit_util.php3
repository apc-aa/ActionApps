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

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tabledit.html, doc/tabledit_developer.html, doc/tableview.html

require $GLOBALS[AA_INC_PATH]."varset.php3";

// -----------------------------------------------------------------------------------

/** Processes TableEdit form data. To be called in each script using TableEdit before
    showing the TableEdit class.
*/
function ProcessFormData ($getTableViewsFn, $val, &$cmd) 
{   
    global $err, $debug, $tabledit_formdata_processed;
    
    if ($tabledit_formdata_processed)
        return;
    $tabledit_formdata_processed = true;
    
    if (!is_array ($cmd)) return;
    if ($debug) 
    { echo "cmd: ";print_r ($cmd); echo "<br>val: ";print_r($val); echo"<br>"; }

    reset ($cmd);
    while (list ($myviewid, $com) = each ($cmd)) {
        $myview = $getTableViewsFn ($myviewid, "form");
        SetColumnTypes ($myview["fields"], $primary_aliases, $myview["table"], 
            $myview["join"], false, $primary);
        reset ($com);
        while (list ($command, $par) = each ($com)) {                
            switch ($command) {
            case "update":
                if (current ($par)) {
                    RunColumnFunctions ($val[key($par)], $myview["fields"], $myview["table"], $myview["join"]);
                    $ok = true;
                    if (key($par) == $GLOBALS[new_key])                        
                        $ok = ProcessInsert ($myviewid, $myview, $primary_aliases, $val, $cmd);
                    else $ok = TableUpdate (
                        $myview["table"], $val[key($par)], 
                        $myview["fields"], $primary_aliases, $myview["primary"],
                        $myview["messages"]["error_update"], $myview["triggers"]);
                    if (!$ok) { PrintArray ($err); $err = ""; }
                }
                break;
            case "update_all":
                if ($par) {
                    reset ($val);
                    $ok = true;
                    while (list ($key, $vals) = each ($val)) {
                        RunColumnFunctions ($vals, $myview["fields"], $myview["table"], $myview["join"]);
                        if ($key != $GLOBALS[new_key])                        
                            $ok = $ok && TableUpdate (
                                $myview["table"], $vals, 
                                $myview["fields"], $primary_aliases, $myview["primary"],
                                $myview["messages"]["error_update"], $myview["triggers"]);
                    }
                    if (!$ok) { PrintArray ($err); $err = ""; }
                }
                break;
            case "delete_all":
                if ($com["run_delete_all"]) {
                    reset ($par);
                    while (list ($key, $checked) = each ($par)) {
                        RunColumnFunctions ($val[$key], $myview["fields"], $myview["table"], $myview["join"]);                        
                        TableDelete ($myview["table"], $val[$key],
                                     $myview["fields"], $primary_aliases,
                                     $myview["messages"]["error_delete"], $myview["triggers"]);
                    }
                }
                break;
            case "delete":
                RunColumnFunctions ($val[key($par)], $myview["fields"], $myview["table"], $myview["join"]);
                TableDelete ($myview["table"], $val[key($par)], $myview["fields"], $primary_aliases, 
                    $myview["messages"]["error_delete"], $myview["triggers"]);
                break;
            default:
                break;
            }
        }
    }    
    PrintArray($err);
}

// -----------------------------------------------------------------------------------

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

function SetColumnTypes (&$columns, &$primary_aliases, $default_table, $join="", 
    $default_readonly=false, $primary="") {
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
                
            // is this column a part of join condition? if yes, it must be created
            $is_join_part = false;    
            if ($join[$table]) {
                reset ($join[$table]["joinfields"]);
                while (list (, $join_childf) = each ($join[$table]["joinfields"]))
                    if ($join_childf == $col["name"]) {
                        $is_join_part = true;
                        break;
                    }
            }
                        
            // is it a part of the primary key?
            if ($primary && $primary[$table])
                 $is_primary = my_in_array ($col["name"], $primary[$table]);
            else $is_primary = strstr ($col["flags"], "primary_key");
            if ($is_primary || $is_join_part) {
                // create the column if not exists
                if (!$cprop) {
                    $alias = "_".$table."_".$col["name"]."_";
                    $cprop = &$columns[$alias];
                    $cprop["table"] = $table;
                    $cprop["field"] = $col["name"];
                    $cprop["view"]["type"] = "hide";                              
                }
                else {
                    if ($is_join_part) 
                    { echo "Define only the child (left) fields for join tables! Wrong alias: $alias"; exit; }
                    else if ($cprop["view"]["type"] == "ignore")
                    { echo "<h2>Column type for a primary key part must not be IGNORE.</h2>"; exit; }
                }
                //echo "primary $table . $alias";
                if ($is_primary) {
                    $cprop["primary"] = true;
                    $primary_aliases[$table][$alias] = 1;
                }
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
function TableDelete ($table, $val, $columns, $primary_aliases, $error_msg="", $triggers="", $be_cautious=1) {
    global $db, $err;
    $varset = new CVarset;
    AddKeyValues ($varset, $val, $primary_aliases[$table], $columns);
    if ($be_cautious) {
        $db->query ($varset->makeSELECT ($table));
        if ($db->num_rows() != 1) {
			$err[] = $error_msg ? $error_msg : 
                "Error deleting from $table. ".$varset->makeSELECT($table)." returned ".$db->num_rows()." rows instead of 1.";
			return false;
		}
    }
    callTrigger ($triggers, "BeforeDelete", $varset);       
    $retval = $db->query ($varset->makeDELETE ($table));
    callTrigger ($triggers, "AfterDelete", $varset);
    return $retval;
}

// -----------------------------------------------------------------------------------

/** inserts or updates a record
*
* @param  $primary see SetColumnTypes
* @return returns true if successfull, false if not
*/
function TableUpdate ($default_table, $val, $columns, $primary_aliases, $primary="", $error_msg="", 
    $triggers = "", $be_cautious=1) {
    global $db, $err;    

    if (!ProoveVals ($val, $columns))
        return false;
        
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
                    $varset->add($col["field"],"number",$value);
    			}
                else $varset->add($col["field"],"quoted",$value);         
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
                $err[] = $error_msg ? $error_msg : "Error in TableUpdate ".$varset->makeSELECT($table).", row count is ".$db->num_rows()." instead of 1.";
                return false;
            }
        }
        callTrigger ($triggers, "BeforeUpdate", $varset);
        $db->query ($varset->makeUPDATE ($table));
        callTrigger ($triggers, "AfterUpdate", $varset);
    }
    
    return true;
}

// -----------------------------------------------------------------------------------

function TableInsert (&$newkey, &$where, $key_table, $val, $columns, $primary_aliases, 
    $primary="", $error_msg="", $triggers="", $be_cautious=1) {
    global $db, $err;

    if (!ProoveVals ($val, $columns))
        return "";

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
                    $varset->set($col["field"],$value,"number");
    			}
                else $varset->set($col["field"],$value,"quoted");         
            }
        }
    }
    
    // run varsets
    reset ($varsets);
    while (list ($table) = each ($varsets)) {        
        $varset = &$varsets[$table];
        $auto_inc = false;
        reset ($primary_aliases);
        while (list ($alias) = each ($primary_aliases)) 
            if ($columns[$alias]["auto_increment"])
                $auto_inc = true;
        if (!$auto_inc && $be_cautious) {
            $db->query ($varset->makeSELECT ($table));
            if ($db->num_rows() > 0) { 
                $err[] = $error_msg ? $error_msg : "Error in TableInsert ".$varset->makeSELECT($table).", row count is ".$db->num_rows()." instead of 0.";
                return "";
            }
        }
        callTrigger ($triggers, "BeforeInsert", $varset);
        $db->query ($varset->makeINSERT ($table)); 
        callTrigger ($triggers, "AfterInsert", $varset);
        
        if ($table == $key_table) {    
            if ($auto_inc) $newkey = get_last_insert_id ($db, $table);
            else $newkey = GetKey ($table, $columns, $val);
            $where = $varset->makeWHERE ($table);
        }
    }
}

// -----------------------------------------------------------------------------------

// processes insert form data, returns true on success, false on fail
function ProcessInsert ($myviewid, $myview, $primary_aliases, $val, &$cmd) {
    // WARNING: a bit hackish: after inserting an item, the command is changed 
    TableInsert ($newkey, $where, $myview["table"], $val[$GLOBALS[new_key]],
                $myview["fields"], $primary_aliases, $myview["primary"], $myview["messages"]["error_insert"],
                $myview["triggers"]);
    if ($newkey != "") {
        global $tabledit_settings;
        $cmd[$myviewid]["edit"][$newkey] = 1;
        $cmd[$myviewid]["insert"] = $where;
    }
    else unset ($cmd[$myviewid]["insert"]);
    return $newkey != "";
}

// -----------------------------------------------------------------------------------

function RunColumnFunctions (&$val, $columns, $table, $join) {
    if (!is_array ($val)) 
        return;
        
    // change the values for appropriate column types
    reset ($val);        
    while (list ($col, $value) = each ($val))
        // defined in tabledit_column.php3 
        ColumnFunctions ($columns[$col]["view"], $val[$col], "form");
        
    // copy values between joining fields
    if (is_array ($join)) {
        reset ($join);
        while (list ($childtable, $joinprop) = each ($join)) {
            reset ($joinprop["joinfields"]);
            while (list ($masterf, $childf) = each ($joinprop["joinfields"])) {
                // find master and child field alias
                reset ($columns);                
                while (list ($alias, $cprop) = each ($columns)) {
                    if ($cprop["field"] == $masterf && $cprop["table"] == $table)
                        $mastera = $alias;
                    else if ($cprop["field"] == $childf && $cprop["table"] == $childtable)
                        $childa = $alias;
                }
                // copy value from master to child
                $val[$childa] = $val[$mastera];
            }
        }
    }    
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

function CallTrigger ($triggers, $event, $varset) {
    if (is_array ($triggers) && $triggers[$event]) {
        $fn = $triggers[$event];
        $fn ($varset);
    }
}


?>