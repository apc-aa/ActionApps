<?php

// identifies new record 
$new_key = "__new__";

class tabledit {

    // EXTERN VARIABLES
    // active tableview
    var $view;
    // view ID
    var $viewID;
    // main script URL
    var $action;
    // command to be executed 
    var $cmd;
    /* used for CHILD tables only, contains joining field values
                        e.g. array ("collectionid" => 7)    */
    var $joincols;
    
    // INTERN VARIABLES

    function tabledit($viewID, $action, $cmd, $view, $joincols="") {
        $this->viewID = $viewID;
        $this->cmd = $cmd;
        $this->view = $view;
        $this->joincols = $joincols;
        $this->action = $action;       
    }
    
    // shows one table form    
    function view ($where) {
        global $db;
        // is this a child view?
        $child = $this->joincols != "";
        $columns = GetColumnTypes ($this->view["table"], $this->view["fields"]);
    
        // defaults
        if (!isset ($this->view["addrecord"])) $this->view["addrecord"] = true;
        
        // create SQL SELECT        
        if ($this->cmd["edit"][$this->viewID]) {
            $where = CreateWhereCondition (key ($this->cmd["edit"][$this->viewID]), $columns);
        }
        else if ($this->cmd["insert"][$this->viewID]) {
            $where = "1=2";
            $show_new = true;
        }
        else if (!$this->view["readonly"] && $this->view["addrecord"])
            $show_new = true;
        
        // find tview and gotoview
        // $gotoview = edit in browse view; update in edit view
        $gotoview = $this->view["gotoview"];
        // $gotoview2 = delete,update in browse view
        $gotoview2 = $child ? $gotoview : $this->viewID;
        if (!$gotoview) $gotoview = $this->viewID;
        
        while (list ($col) = each ($columns)) 
            $collist[] = $col;    
        $db->tquery ("SELECT ".join(",",$collist)
                   ." FROM ".$this->view[table]
                   .($where ? " WHERE ".$where : ""));
        
        if ($db->num_rows() == 0 && !$show_new) 
            echo "<B>".L_TABLE_EMPTY."</B>";
            
        else {      
            echo "<TABLE ".$this->view["attrs"]["table"].">";
            $td = "<TD ".$this->view["attrs"]["td"].">";
          
            // "new" is label for $new_record, "view" is view on which $this->cmd operates, "gotoview" is view which will be shown
            $buttons_text = array (
                "edit" => array ("label" => L_EDIT, "new" => "", "view" => $gotoview, "gotoview" => $gotoview),
                "delete" => array ("label" => L_DELETE, "new" => "", "view" => $this->viewID, "gotoview" => $gotoview2),
                "update" => array ("label" => L_UPDATE, "new" => L_ADD, "view" => $this->viewID, "gotoview" => $gotoview2, "button"=>true));
                
            // show column headers in Browse view
            if ($this->view["type"] == "browse") {
                $colspan = 0;
                reset ($buttons_text);
                while (list ($button) = each ($buttons_text))
                    if ($this->view["buttons"][$button]) $colspan ++;
    
                echo "<TR><TD ".$this->view["attrs"]["td"]." colspan=".$colspan.">&nbsp;</TD>\n";   
                reset ($columns);
                while (list ($colname,$column) = each ($columns)) 
                    if ($column["view"]["type"] != "hide") 
                        echo "$td<b>$colname</b><br><font size=-1>".$column["hint"]."</font></TD>\n";
                echo "</TR>";        
            }
            
            // if $show_new is enabled, show empty record as last one
            $new_record = false;
            while (!$new_record) {
                if (!$db->next_record()) 
                    if ($show_new)
                        $new_record = true;
                    else break;
    
                $key = $new_record ? $new_key : GetKey ($columns, $db->Record);
    
                if ($this->view["type"] == "browse") {
                    echo "<FORM name='tv_".$this->viewID."_$key' method=post action='".$this->getAction($gotoview2)."'>\n";
                    echo "<TR>";
                    $this->ShowButtons ($buttons_text, $new_record, $key);
                }
                else echo "<FORM name='tv_".$this->viewID."_$key' method=post action='".$this->getAction($gotoview)."'>\n";
                            
                // add join fields
                if ($new_record && is_array ($this->joincols)) {
                    reset ($this->joincols);
                    while (list ($col,$val) = each ($this->joincols)) 
                        echo "<INPUT TYPE=hidden NAME=val[$col] VALUE='".str_replace("'","\\'",$val)."'>";
                }
                
                $this->ShowColumnValues ($db->Record, $new_record);
                
                if ($this->view["type"] == "edit")    
                    echo "<TR><TD colspan=2 ".$this->view["attrs"]["td"]." align=center>
                        <INPUT type=submit name='cmd[update][".$this->viewID."][$key]' value='".($new_record ? L_INSERT : L_UPDATE)."'>
                        <INPUT type=submit name='cmd[cancel][".$this->viewID."]' value='".L_CANCEL."'>
                        </TD></TR>\n";
                if ($this->view["type"] == "browse") echo "</TR>";
                echo "</FORM>";
            }
            echo "</TABLE>";
        }
        
        if ($this->view["readonly"] && $this->view["gotoview"]) {
            echo "<br><br>
                <FORM name='tv_".$this->viewID."_insert' method=post action='".$this->getAction($gotoview)."'>
                <INPUT type=submit name='cmd[insert][".$gotoview."]' value='".L_INSERT."'>
                </FORM>";
        }
        
        return "";
    }        

    // -----------------------------------------------------------------------------------

    function getAction ($viewID) {
        return $this->action. (strstr($this->action,"?") ? "&" : "?") . "set[tview]=".$viewID;
    } 
        
    // -----------------------------------------------------------------------------------

    function ShowColumnValues ($record, $new_record)
    {
        $columns = GetColumnTypes ($this->view["table"], $this->view["fields"]);
        $td = "<TD ".$this->view["attrs"]["td"].">";
        while (list ($colname,$column) = each ($columns)) {
            $cview = $column["view"];
            if ($new_record && $column["view_new_record"])
                $cview = $column["view_new_record"];

            if ($cview["type"] == "hide") 
                continue;
            
            if (isset ($cview["readonly"]))
                 $readonly = $cview["readonly"];
            else $readonly = $this->view["readonly"];
                       
            if ($this->view["type"] == "edit")
                echo "<TR>$td<b>$colname</b><br><font size=-1>".$column["hint"]."</font></TD>\n";
        
            $type = $cview["type"];
            if (!$type) $type = $column["type"];                
        
            $val = $new_record ? $column["view_new_record"]["default"] : htmlentities($record[$colname]);
            
            $rows = 5;
            $cols = 80;
            
            echo $td;
            if (!$readonly) switch ($type) {
                case 'blob': echo "<textarea name='val[$colname]' rows=5 cols=80>$val</textarea>"; break;
                case 'select': FrmSelectEasy("val[$colname]", $cview["source"], $val); break;
                case 'text': $cols = $cview["size"]["cols"];
                default: echo "<INPUT type=text size=$cols name='val[$colname]' 
                    value='".str_replace("'","\"",$val)."'>"; 
            }
            else {
                if ($type == "select") 
                    $val = htmlentities($cview["source"][$record[$colname]]);
                if (is_field_type_numerical ($column["type"]) && !$val)
                    if (!$new_record)
                         $val = "0";
                    else $val = "&nbsp;";
                else if (!$val) $val = "&nbsp;";
                if ($cview["href_view"]) 
                    echo "<a href='".$this->getAction($cview["href_view"])
                        ."&cmd[edit][".$cview["href_view"]."]"
                        ."[\"".str_replace("\"","\\\"",$record[$colname])."\"]=1'>".$val."</a>";
                else echo $val;
            }        
            echo "</TD>";
            
            if ($this->view["type"] == "edit") echo "</TR>";
        }
    }
    
    // -----------------------------------------------------------------------------------

    function ShowButtons ($buttons_text, $new_record, $key) {                
        $td = "<TD ".$this->view["attrs"]["td"].">";
        if (is_array ($this->view["buttons"])) {
            reset ($this->view["buttons"]);
            while (list ($button,$use) = each ($this->view["buttons"])) {
                $bt = $buttons_text[$button];
                if ($use && $bt) {
                    $label = $bt["label"];
                    if (isset ($bt["new"]) && $new_record)
                        $label = $bt["new"];
                    if ($bt["button"])
                         echo $td."<INPUT type=submit name='cmd[$button][".$this->viewID."][$key]' value='$label'>\n";
                    else {
                        if ($label) echo $td."<a href='".$this->getAction($bt[gotoview])
                            ."&cmd[$button][$bt[view]][$key]=1'>".$label."</a></td>\n";                
                        else echo $td."&nbsp;</td>\n";
                    }
                }
            }
        }
    }    

    // -----------------------------------------------------------------------------------
    
    // shows children forms
    function ShowChildren (&$tableviews) {
        if (!is_array ($this->view["children"]) || !$this->cmd["edit"][$this->viewID]) 
            return "";
        reset ($this->view["children"]);
        while (list ($chview, $child) = each ($this->view["children"])) {       
            $key = key ($this->cmd["edit"][$this->viewID]);
            $key_values = split_escaped (":", $key, "#:");
            reset ($key_values);
            reset ($child["join"]);
            while (list ($masterf,$childf) = each ($child["join"])) {
                $childcols[$childf] = $this->view["fields"][$masterf];
                list (,$key_value) = each ($key_values);
                $joincols[$childf] = $key_value;
            }
            $where = CreateWhereCondition ($key, $childcols);
            echo "<h3>".$child["header"]."</h3>";
            $chtv = $tableviews[$chview];
            $chtv["gotoview"] = $this->viewID;
            $action = $this->action . (strstr($this->action,"?") ? "&" : "?")
                      ."cmd[edit][".$this->viewID."][$key]=1";
            $childte = new tabledit ($chview, $action, $this->cmd, $chtv, $joincols);
            $err = $childte->view($where);
            if ($err) return $err;
        }
    }
}
// END OF class tabledit

/*   $columns is array of columns, see "fields" in tableviews.php3
     returns array (field_name => field_type, ...)
*/

function GetColumnTypes ($table, $columns) {
    global $db;
    //echo "TABLE $table";
    if (!$table) {
        echo "Error in GetColumnTypes";
        return $columns;
    }
    $cols = $db->metadata ($table);
    reset ($cols);
    while (list (,$col) = each ($cols)) 
        if (isset ($columns[$col["name"]])) 
            $columns[$col["name"]]["type"] = $col["type"];
    return $columns;
}

// -----------------------------------------------------------------------------------

// deletes one record identified by key values from given table
function TableDelete ($table, $key_value, $columns, $be_cautious=1) {
    global $db;
    $where = CreateWhereCondition ($key_value, $columns);
    if ($be_cautious) {
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) return false;
    }
    return $db->query ("DELETE FROM $table WHERE $where");
}

// -----------------------------------------------------------------------------------
   
// inserts or updates one record identified by key values in given table    
// records to insert are identified by $key_value == $new_key
function TableUpdate ($table, $key_value, $val, $columns, $be_cautious=1) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    $varset = new CVarset();
    reset ($val);
    while (list($name,$value)=each($val)) {
        if (get_magic_quotes_gpc()) 
            $value = stripslashes ($value);
        if (is_field_type_numerical ($columns[$name]["type"]))
            $varset->set($name,$value,"number");
        else switch ($columns[$name]) {
            case 'packed': $varset->set($name,$value,"packed"); break;
            default: $varset->set($name,$value,"text"); 
        }
    }

    if ($key_value != $new_key) { 
        $where = CreateWhereCondition ($key_value, $columns);
        if ($be_cautious) {
            $db->query ("SELECT * FROM $table WHERE $where");
            if ($db->num_rows() != 1) return false;
        }
        return $db->query ("UPDATE $table SET ".$varset->makeUPDATE()." WHERE $where");
    }
    else return $db->query ("INSERT INTO $table ".$varset->makeINSERT());
}

// -----------------------------------------------------------------------------------

// creates key string with values from key fields separated by :
function GetKey ($columns, $record)
{
    reset ($columns);
    unset ($key);
    while (list ($colname,$column) = each ($columns)) 
        switch ($column["primary"]) {
        case "packed": $key[] = unpack_id ($record [$colname]); break;
        case "number": $key[] = $record[$colname]; break;
        case "text": $key[] = htmlentities ($record[$colname]); break;
        }
    return join_escaped (":",$key,"#:");
}
            
// -----------------------------------------------------------------------------------    

// creates where condition from key fields values separated by :
function CreateWhereCondition ($key_value, $columns) {
    $key_values = split_escaped (":", $key_value, "#:");
    reset ($key_values);
    reset ($columns);
    $where = array();
    while (list ($colname,$column) = each ($columns)) {
        if (!$column["primary"])        
            continue;
        list (,$val) = each ($key_values);
        switch ($column["primary"]) {
            case 'packed': $where[] = "$colname='".q_pack_id ($val)."'"; break;
            case 'number': $where[] = "$colname=$val"; break;
            case 'text': $where[] = "$colname='".str_replace("'","\\'",$val)."'";
        }
    }
    return join (" AND ",$where);
}
   
?>
