<?php

/*   $columns is array of column names or "*"
     returns array (field_name => field_type, ...)
*/

function GetColumns ($table, $columns) {
    $db = new DB_AA;    
    $cols = $db->metadata ($table);
    if ($columns == "*") {
        $columns = array ();
        reset ($cols);
        while (list (,$col) = each ($cols))
            $columns[$col["name"]] = $col["type"];
    }
    else if (is_array ($columns)) {
        $c = $columns;
        $columns = array ();
        reset ($cols);
        while (list (,$col) = each ($cols)) {
            if (my_in_array ($col["name"], $c))
                $columns[$col["name"]] = $col["type"];
        }
    }
    return $columns;
}

function CreateWhereCondition ($key_value, $primary_key) {
    $dummy = "~#$";
    if (strstr ($dummy, $key_value)) { echo "INTERNAL ERROR."; return "INTERNAL ERROR"; }
    $key_value = str_replace ("#:",$dummy,$key_value);
    $key_values = split (":", $key_value);

    reset ($key_values);
    reset ($primary_key);
    $where = array();
    while (list ($i,$kv) = each ($key_values)) {
        list ($name, $type) = each ($primary_key);       
        $val = str_replace ($dummy, ":", $kv);
        switch ($type) {
            case 'packed': $where[] = "$name='".q_pack_id ($val)."'"; break;
            case 'number': $where[] = "$name=$val"; break;
            default: $where[] = "$name='".str_replace("'","\\'",$val)."'";
        }
    }
    return join (" AND ",$where);
}

/*  function: TableEditView
    purpose: shows a form for inserting or editing a table row
    params: if $key_value is empty, inserts a new row
*/

function TableEditView ($table, $key_value, $action, $attrs = array(), $columns="*", $primary_key=array("id"=>"binary"), $column_hints=array()) {
    global $db;
    
    if (!is_array ($primary_key)) return "Primary key must be an array of field names.";
    $columns = GetColumns ($table, $columns);
    
    $number_db_types = array ("float","double","decimal","int", "timestamp");

    echo "<FORM name='f' method=post action='$action'>";

    if ($key_value) {
        $where = CreateWhereCondition ($key_value, $primary_key);
        echo "<INPUT TYPE=hidden NAME='par[where]' VALUE='$where'>";
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) 
            return L_WRONG_NUMBER_OF_ROWS;
        $db->next_record();
    }
    else {
        echo "<INPUT TYPE=hidden NAME='cmd[insertsent]' VALUE=1>";
        $val = "";
    }
    
    echo "<TABLE $attrs[table]>";
    reset ($columns);
    while (list ($col,$type) = each ($columns)) {
        echo "<TR>
        <TD $attrs[td]><b>$col</b><br><font size=-1>".$column_hints[$col]."</font></TD>";
        if ($key_value) $val = htmlentities($db->f($col));
        if (!$val) $val = "&nbsp;";
        echo "<TD $attrs[td]>";
        switch ($type) {
            case 'blob': echo "<textarea name='val[$col]' rows=5 cols=80>$val</textarea>"; break;
            default: echo "<INPUT type=text size=80 name='val[$col]' value='".str_replace("'","\"",$val)."'>"; 
        }
        echo "</TD></TR>";
    }
    echo "</TABLE>
    <br><br>
    <INPUT type=submit name='cmd[update]' value='".($key_value ? L_UPDATE : L_INSERT)."'>
    <INPUT type=submit name='cmd[cancel]' value='".L_CANCEL."'>
    </FORM>";
    return "";
}        

/* $attrs = array (
    "table"=><TABLE ...>
    "td"=><TD ...>
    "url"=>"?edit=1&...") */

function TableBrowseView ($table, $script, $attrs = array(), $columns="*", $primary_key=array("id"=>"binary"), $where="") {
    global $db;
    if (!is_array ($primary_key)) return "Primary key must be an array of field names.";
    $columns = GetColumns ($table, $columns);
    
    $collist = array();
    while (list ($col) = each ($columns)) 
        $collist[] = $col;
    reset ($primary_key);
    while (list($name) = each($primary_key)) {
        if (!my_in_array ($name, $collist))
            $collist[] = $name;
    }
    
    $db->query ("SELECT ".join(",",$collist)." FROM $table ".($where ? "WHERE $where" : ""));
    if ($db->num_rows() == 0) 
        echo "<B>".L_TABLE_EMPTY."</B>";
        
    else {
        
        echo "<TABLE $attrs[table]><TR>
        <TD $attrs[td] colspan=2>&nbsp;</TD>";   
        reset ($columns);
        while (list ($col) = each ($columns)) {
            echo "<TD $attrs[td]><B>$col</B></TD>";
            $collist[] = $col;
        }
        echo "</TR>";
        
        
        reset ($columns);
        while ($db->next_record()) {
            reset ($primary_key);
            $key = array();
            while (list ($name,$type) = each ($primary_key)) {
                switch ($type) {
                    case "binary": $key[] = str_replace (":", "#:", htmlentities ($db->f($name))); break;
                    case "packed": $key[] = unpack_id ($db->f($name)); break;
                    default: $key[] = str_replace (":","#:",$db->f($name));
                }
            }
            
            echo "<TR><TD $attrs[td]><a href='$script?cmd[edit]=".join(":",$key)."&$attrs[url]'>".L_EDIT."</A></TD>
            <TD $attrs[td]><a href='$script?cmd[delete]=".join(",",$key)."&$attrs[url]'>".L_DELETE."</a></TD>";
            reset ($columns);
            while (list ($col) = each ($columns)) {
                $val = htmlentities($db->f($col));
                if (!$val) $val = "&nbsp;";
                echo "<TD $attrs[td]>$val</TD>";
            }
            echo "</TR>";
        }
        echo "</TABLE>";
    }
    
    echo "<FORM name='f' method=post action='$action'>";
    echo "<INPUT type=submit name='cmd[insert]' value='".L_INSERT."'>";
    echo "</FORM>";
    return "";
}

function TableUpdate ($table, $where, $val, $columns="*", $be_cautious=1) {
    global $db;
    $columns = GetColumns ($table, $columns);
    $varset = new CVarset();
    reset ($val);
    while (list($name,$value)=each($val)) {
        if (get_magic_quotes_gpc()) 
            $value = stripslashes ($value);
        if (is_field_type_numerical ($columns[$name]))
            $varset->set($name,$value,"number");
        else switch ($columns[$name]) {
            case 'packed': $varset->set($name,$value,"packed"); break;
            default: $varset->set($name,$value,"text"); 
        }
    }
 
    if ($where) {
        if ($be_cautious) {
            $db->query ("SELECT * FROM $table WHERE $where");
            if ($db->num_rows() != 1) return false;
        }
        return $db->query ("UPDATE $table SET ".$varset->makeUPDATE()." WHERE $where");
    }
    else return $db->query ("INSERT INTO $table ".$varset->makeINSERT());
}

function TableDelete ($table, $key_value, $primary_key, $be_cautious=1) {
    global $db;
    $where = CreateWhereCondition ($key_value, $primary_key);
    if ($be_cautious) {
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) return false;
    }
    return $db->query ("DELETE FROM $table WHERE $where");
}
    

?>
