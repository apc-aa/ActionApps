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

//require $GLOBALS[AA_INC_PATH]."scroller.php3";

// identifies new record 
$new_key = "__new__";

class tabledit {
    // serialization purposes??
    var $classname = "tabledit";

    // EXTERN VARIABLES
    // active tableview
    var $view;
    // view ID
    var $viewID;
    // main script URL
    var $action;
    // cmd[] parameter (commands for all table views)
    var $all_cmd;
    // command to be executed 
    var $cmd;
    /* used for CHILD tables only, contains joining field values
                        e.g. array ("collectionid" => 7)    */
    var $joincols;
    // session (for scroller management)
    var $sess;
    // columns (fields) definition from view with added types info
    var $cols;
    // URL path for images (up.gif and down.gif)
    var $imagepath;    
    // function to get other Table View definitions by ID. Used in ShowChildren()
    var $getTableViewsFn;
    
    // INTERN VARIABLES
    // field name to order by
    var $orderby;
    // ''='a' or 'd'
    var $orderdir;
    // show an empty record to add new data?
    var $show_new;

    function tabledit($viewID, $action, $cmd, $view, $imagepath, &$sess, $joincols="", $parentViewID="", $getTableViewsFn="") {
        $this->viewID = $viewID;
        $this->all_cmd = $cmd;
        $this->cmd = $cmd[$viewID];
        $this->view = $view;
        $this->cols = GetColumnTypes ($this->view["table"], $this->view["fields"]);
        $this->joincols = $joincols;
        $this->action = $action;       
        $this->sess = &$sess;
        $this->imagepath = $imagepath;
        $this->getTableViewsFn = $getTableViewsFn;
        
        $this->UpdateCmd ();
    }

    // exchanges data between session-stored cmd (as tabledit_cmd) and current URL cmd
    function UpdateCmd () {
        $this->sess->register("tabledit_cmd");
        global $tabledit_cmd;
        $tecmd = &$tabledit_cmd[$this->viewID];
        
        // update ORDERBY and ORDERDIR
        $orderby = $this->cmd["orderby"];
        if (is_array ($orderby)) {
            reset ($orderby);
            $orderby = key ($orderby);
            $this->orderby = $orderby;
            if ($tecmd["orderby"][$orderby]) {
                $tecmd["orderdir"] = 
                    $tecmd["orderdir"] == 'd' ? 'a' : 'd';
                $this->orderdir = $tecmd["orderdir"];
            }
            else {
                unset ($tecmd["orderdir"]);
                $tecmd["orderby"] = array ($orderby => 1);
            }
        }
        else {
            $orderby = $tecmd["orderby"];
            if (is_array ($orderby)) {
                $orderby = key ($orderby);
                $this->orderby = $orderby;
                $this->orderdir = $tecmd["orderdir"];
            }
            else {
                $this->orderby = $this->view["orderby"];
                $this->orderdir = $this->view["orderdir"];
            }
        }
        
        // update EDIT
        $edit = $this->cmd["edit"];
        if (is_array ($edit)) {
            reset ($edit);
            $edit_key = key ($edit);
            $tecmd["edit"] = $edit;
        }
        else if (is_array ($tecmd["edit"])) 
            $this->cmd["edit"] = $tecmd["edit"];
    }    
    
    // shows one table form    
    function view ($where) {
        global $db;
        // is this a child view?
        $child = $this->joincols != "";
    
        $this->SetViewDefaults();    
        $where = $this->GetWhere ($where);

        // find tview and gotoview
        // $gotoview = edit in browse view; update in edit view
        $gotoview = $this->view["gotoview"];
        // $gotoview2 = delete,update in browse view; search form
        $gotoview2 = $child ? $gotoview : $this->viewID;
        if (!$gotoview) $gotoview = $this->viewID;

        $db->tquery ("SELECT COUNT(*) AS mycount FROM ".$this->view["table"]." WHERE ".$where);
        $db->next_record();        
        $rowcount = $db->f("mycount");

        // scroller stuff                                           
        $scrname = "te_".$this->viewID;
        global $$scrname;
        $scroll = $$scrname;
        $scrurl = $this->sess->url($GLOBALS[PHP_SELF]."?set_tview=".$gotoview2."&")."&";

        if (is_object($scroll)) {
            $scroll->metapage = $this->view["listlen"];
            $scroll->countPages ($rowcount);
            $scroll->updateScr($scrurl);
        }
        else {
            $$scrname = new scroller($scrname, $scrurl);
            $scroll = $$scrname;
            $this->sess->register ($scrname);
            $scroll->metapage = $this->view["listlen"];
            $scroll->countPages ($rowcount);
        }
        $$scrname = $scroll;

        if ($this->orderby) 
            $orderby = " ORDER BY ".$this->orderby.($this->orderdir == 'd' ? " DESC" : "");
        
        reset ($this->cols);
        while (list ($col) = each ($this->cols)) 
            $collist[] = $col;    
        $db->tquery (
             " SELECT ".join(",",$collist)
            ." FROM ".$this->view[table]
            ." WHERE ".$where
            . $orderby
            ." LIMIT ".($scroll->current-1)*$scroll->metapage.",".$scroll->metapage);
            
        $record_count = $db->num_rows();
        
        if ($db->num_rows() == 0 && !$this->show_new) 
            echo "<B>".$this->view["no_item_msg"]."</B>";
            
        else {      
            echo "<TABLE ".$this->view["attrs"]["table"].">";
            $td = "<TD ".$this->view["attrs"]["td"].">";
          
            // show clickable column headers in Browse view
            if ($this->view["type"] == "browse") {
                echo "<TR><TD ".$this->view["attrs"]["td"]
                    ." colspan=".count($this->view["buttons"]).">&nbsp;</TD>\n";   
                reset ($this->cols);
                while (list ($colname,$column) = each ($this->cols)) 
                    if ($column["view"]["type"] != "hide") {
                        echo "$td<a href='".$this->getAction($gotoview2)."&cmd[".$this->viewID."]"
                            ."[orderby][$colname]=1'><b>$colname</b>";
                        if ($this->orderby == $colname) 
                            echo "&nbsp;<img src='".$this->imagepath
                                .($this->orderdir=='d' ? "up" : "down").".gif' border=0>";                                                
                        echo "</a><br>"
                            ."<font size=-1>".$column["hint"]."</font></TD>\n";
                    }
                echo "</TR>";        
            }
            
            // if $show_new is enabled, show empty record as last one
            $new_record = false;
            while (!$new_record) {
                if (!$db->next_record()) 
                    if ($this->show_new)
                        $new_record = true;
                    else break;
    
                $key = $new_record ? $new_key : GetKey ($this->cols, $db->Record);
    
                $formname = "tv_".$this->viewID."_".$key;
                $fnname = "prooveFields_".$this->viewID;
                $this->ShowProoveFields ($fnname);
                $onsubmit = "return $fnname (\"$formname\");";
                if ($this->view["type"] == "browse") {
                    echo "<FORM name='$formname' method=post onSubmit='$onsubmit' action='".$this->getAction($gotoview2)."'>\n";
                    echo "<TR>";
                    $this->ShowButtons ($gotoview, $gotoview2, $new_record, $key);
                }
                else echo "<FORM name='$formname' method=post onSubmit='$onsubmit' action='".$this->action."'>\n";
                            
                // add join fields
                if ($new_record && is_array ($this->joincols)) {
                    reset ($this->joincols);
                    while (list ($col,$val) = each ($this->joincols)) 
                        echo "<INPUT TYPE=hidden NAME=val[$col] VALUE='".str_replace("'","\\'",$val)."'>";
                }
                
                $this->ShowColumnValues ($db->Record, $new_record);
                
                // show buttons Update/Insert and Cancel
                if ($this->view["type"] == "edit") {
                    echo "<TR><TD colspan=2 ".$this->view["attrs"]["td"]." align=center>";
                    echo "<INPUT type=hidden name='set_tview' VALUE='$gotoview2'>\n";
                    echo "<INPUT type=submit name='cmd[".$this->viewID."]";
                    if (!$new_record) 
                         echo "[update][$key]' value='".L_UPDATE."'>";
                    else echo "[insert]' value='".L_INSERT."'>";
                    echo "&nbsp;&nbsp;<INPUT type=button name='cancel' onclick='this.form.set_tview.value=\"$gotoview\"; this.form.submit();'"
                        ." value='".L_CANCEL."'>"
                        ."</TD></TR>\n";
                }
                if ($this->view["type"] == "browse") echo "</TR>";
                echo "</FORM>";
            }
            echo "</TABLE>";
        }
        
        // scroller
        if ($scroll->pageCount() > 1) {
            echo "<P align=\"center\"><B>";
        	$scroll->pnavbar();
            echo "</B></P>";
        }
        
        if ($this->view["search"])  
            $this->ShowSearchRow ($gotoview2);
        
        if (($this->view["readonly"] || $this->view["buttons"]["add"]) && $this->view["gotoview"]) {
            echo "<br><br>
                <FORM name='tv_".$this->viewID."_insert' method=post action='".$this->getAction($gotoview)."'>
                <INPUT type=submit name='cmd[".$gotoview."][show_new]' value='".L_INSERT."'>
                </FORM>";
        }
        
        if (is_array ($this->view["children"]) && $record_count == 1) 
             $err = $this->ShowChildren();

        return $err;
    }        

    // -----------------------------------------------------------------------------------

    function getAction ($viewID) {
        return $this->action. (strstr($this->action,"?") ? "&" : "?") . "set_tview=".$viewID;
    } 
        
    // -----------------------------------------------------------------------------------

    function SetViewDefaults () {
        if (!isset ($this->view["addrecord"])) $this->view["addrecord"] = true;
        if (!isset ($this->view["listlen"]))   $this->view["listlen"] = 15;
        if (!isset ($this->view["search"]))    $this->view["search"] = $this->view["type"] == "browse";
        if (!isset ($this->view["no_item_msg"])) $this->view["no_item_msg"] = _m("Nothing to be shown.");
    }        
    
    // -----------------------------------------------------------------------------------

    // sets $where and $this->show_new
    function GetWhere ($where)
    {
        //echo "edit ".$this->cmd["edit"]." show new ".$this->cmd["show_new"]." readonly ".$this->view["readonly"]." addrecord ".$this->view["addrecord"]." gotoview ".$this->view["gotoview"];
        $this->show_new = false;
        // create SQL SELECT        
        // apply edit command only in Edit view
        if ($this->cmd["edit"] && $this->view["type"] == "edit") {
            $where = CreateWhereCondition (key ($this->cmd["edit"]), $this->cols);
        }
        else if ($this->cmd["show_new"]) {
            $where = "0";
            $this->show_new = true;
        }
        else if (!$this->view["readonly"] && $this->view["addrecord"])
            $this->show_new = true;
        if (!isset($where)) 
            $where = "1";            
        
        // process search row
        $srch = &$this->cmd["search"];
        if ($srch["where"]) {
            // care user can't add another SQL command with this field
            $srch["where"] = str_replace (";", "", $srch["where"]);
            $where .= " AND ".stripslashes_magic($srch["where"]);
            unset($srch["value"]);
            unset($srch["field"]);
        }
        else if ($srch["value"] || $srch["value"] == "0") {
            if (is_field_type_numerical ($this->cols[$srch["field"]]["type"]))
                 $where .= " AND $srch[field] = $srch[value] ";
            else $where .= " AND $srch[field] LIKE '%".addslashes_magic($srch[value])."%' ";
        }
        
        // restrict keys
        if (is_array ($this->view["restrict"])) {
            reset ($this->cols);
            while (list ($colname, $column) = each ($this->cols)) 
                if ($column["primary"]) {
                    $key_cols ++;
                    $key_type = $column["type"];
                    $key_name = $colname;
                }
            if ($key_cols != 1)
                echo "Restrict used on a table with wrong key column count $key_cols";
            if (count ($this->view["restrict"]) == 0)
                 $where .= " AND 0 ";
            else if (is_field_type_numerical ($key_type))
                 $where .= " AND $key_name IN (". join (",",$this->view["restrict"]).") ";
            else {
                reset ($this->view["restrict"]);
                while (list (,$id) = each ($this->view["restrict"])) {
                    if ($in) $in .= ",";
                    $in .= "'".str_replace("'","\\'",$id)."'";
                }
                $where .= " AND $key_name IN ($in) ";
            }
        }                
            
        return $where;
    }   

    // -----------------------------------------------------------------------------------

    function ShowSearchRow ($gotoview) 
    {
        $td = "<TD ".$this->view["attrs"]["td"]."><B>";
        $tdd = "</B></TD>";
        
        echo "<FORM name='search_".$this->viewID."' method=post action='".$this->getAction($gotoview)."'>
              <TABLE ".$this->view["attrs"]["table"].">"
            ."<TR>$td"._m("Quick Search: ")."$tdd"
            ."$td";
        reset ($this->cols);
        while (list ($colname,$column) = each ($this->cols)) 
            if ($column["view"]["type"] != "hide") {
                if (is_field_type_numerical($column["type"]))
                     $text = $colname." = ";
                else $text = $colname." LIKE ";
                $options[$colname] = $text;
            }    
        $srch = $this->cmd["search"];
        FrmSelectEasy ("cmd[".$this->viewID."][search][field]", $options, $srch["field"]);
        echo "&nbsp;<INPUT name='cmd[".$this->viewID."][search][value]' type=text size=30 "
                ."value=\"".stripslashes_magic($srch[value])."\">$tdd"
            ."$td<INPUT type=submit name='go' value='"._m("Go")."'>$tdd</TR>"
            ."<TR>$td"._m("Complex Search (SQL WHERE clause): ")."$tdd"  
            ."$td<INPUT name='cmd[".$this->viewID."][search][where]' type=text size=50 "
                ."value=\"".stripslashes_magic($srch[where])."\">$tdd"
            ."$td<INPUT type=submit name='go' value='"._m("Go")."'>$tdd</TR>
              </TABLE></FORM>";
    }

    // -----------------------------------------------------------------------------------
    
    function ShowProoveFields ($fnname)
    {
        PrintJavaScript_Validate();
        echo "
        <script language=javascript>
        <!--
            function $fnname (formname) {
                myform = document.forms[formname];\n";                
        reset ($this->cols);
        while (list ($colname, $column) = each ($this->cols)) {
            $req = $column["required"];
            if (!$req) $req = "0";
            if ($column["validate"] || $req != 0) {
                if ($column["validate_min"] && $column["validate"] == "number") 
                     echo "if (!validate_number(myform['val[".$colname."]'],".$column["validate_min"].",".$column["validate_max"].", $req))
                        return false;\n";
                else echo "if (!validate(myform['val[".$colname."]'],\"".$column["validate"]."\", $req))
                    return false;\n";
            }
        }
        echo "
                return true;
            }
        // -->
        </script>";
    }
    
    // -----------------------------------------------------------------------------------

    function ShowColumnValues ($record, $new_record)
    {
        $td = "<TD ".$this->view["attrs"]["td"].">";
        reset ($this->cols);
        while (list ($colname,$column) = each ($this->cols)) {
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
        
            $val = $new_record ? $column["default"] : htmlentities($record[$colname]);
            
            $rows = 5;
            $cols = 80;
            
            echo $td;
            if (!$readonly) {
                switch ($type) {
                case 'blob': echo "<textarea name='val[$colname]' rows=5 cols=80>$val</textarea>"; break;
                case 'select': FrmSelectEasy("val[$colname]", $cview["source"], $val); break;
                case 'text': $cols = $cview["size"]["cols"];
                default: echo "<INPUT type=text size=$cols name='val[$colname]'
                    value='".str_replace("'","\"",$val)."'>"; 
                }
            }
            else {
                switch ($type) {
                    case "select": $val = htmlentities($cview["source"][$record[$colname]]); break;
                    case "date" :  $val = date($cview["format"], $val); break;
                }
                if (is_field_type_numerical ($column["type"]) && !$val)
                    $val = $new_record ? "&nbsp;" : "0";
                else if (!$val) $val = "&nbsp;";
                if ($cview["href_view"]) 
                    echo "<a href='".$this->getAction($cview["href_view"])
                        ."&cmd[".$cview["href_view"]."][edit]"
                        ."[".str_replace("\"","\\\"",$record[$colname])."]=1'>".$val."</a>";
                else echo $val;
            }        
            echo "</TD>";
            
            if ($this->view["type"] == "edit") echo "</TR>";
        }
    }
    
    // -----------------------------------------------------------------------------------

    function ShowButtons ($gotoview, $gotoview2, $new_record, $key) {                
        // "new" is label for new record, "new_name" is command for new record, 
        // "view" is view on which $this->cmd operates, "gotoview" is view which will be shown                
        $buttons_text = array (
            "edit" => array (
                "label" => L_EDIT, 
                "view" => $gotoview, 
                "gotoview" => $gotoview),
            "delete" => array (
                "label" => L_DELETE, 
                "view" => $this->viewID, 
                "gotoview" => $gotoview2),
            "update" => array (
                "label" => L_UPDATE, 
                "new" => L_ADD,
                "new_name" => "insert", 
                "view" => $this->viewID, 
                "gotoview" => $gotoview2, 
                "button"=>true));
                
        $td = "<TD ".$this->view["attrs"]["td"].">";
        if (is_array ($this->view["buttons"])) {
            reset ($this->view["buttons"]);
            while (list ($button,$use) = each ($this->view["buttons"])) {
                $bt = $buttons_text[$button];
                if ($use && $bt) {
                    $label = $bt["label"];
                    if ($new_record) {
                        if ($bt["new"]) $label = $bt["new"];
                        else $label = "";
                        if ($bt["new_name"]) $button = $bt["new_name"];
                    }
                    if ($bt["button"])
                         echo $td."<INPUT type=submit name='cmd[".$this->viewID."][$button][$key]' value='$label'>\n";
                    else {
                        if ($label) echo $td."<a href='".$this->getAction($bt[gotoview])
                            ."&cmd[$bt[view]][$button][$key]=1'>".$label."</a></td>\n";                
                        else echo $td."&nbsp;</td>\n";
                    }
                }
            }
        }
    }    

    // -----------------------------------------------------------------------------------
    
    /* Function: ShowChildren
       Purpose:  shows children forms
       Params:   $getTableViewsFn -- name of function which gets table views by ID
                 $all_cmd -- the whole cmd[] array
    */
    function ShowChildren () {
        reset ($this->view["children"]);
        while (list ($chview, $child) = each ($this->view["children"])) {       
            if ($this->cmd["edit"]) {
                $key = key ($this->cmd["edit"]);
                $key_values = split_escaped (":", $key, "#:");
                reset ($key_values);
                reset ($child["join"]);
                while (list ($masterf,$childf) = each ($child["join"])) {
                    $childcols[$childf] = $this->cols[$masterf];
                    list (,$key_value) = each ($key_values);
                    $joincols[$childf] = $key_value;
                }
                $where = CreateWhereCondition ($key, $childcols);
            }
            else $where = "0";
            
            echo "<h3>".$child["header"]."</h3>";
            $fn = $this->getTableViewsFn;
            $chtv = $fn ($chview);
            $chtv["gotoview"] = $this->viewID;
            $action = $this->action . (strstr($this->action,"?") ? "&" : "?")
                      ."cmd[".$this->viewID."][edit][$key]=1";
            $childte = new tabledit ($chview, $action, $this->all_cmd[$chview], $chtv, $this->imagepath, $this->sess, $joincols);
            $err = $childte->view($where);
            if ($err) return $err;
        }
    }
}
// END of class tabledit

/*   $columns is array of columns, see "fields" in tableviews.php3
    appends ["type"] to each column, with column type
    appends ["primary"] to primary columns, if not exist, adds them with ["view"]["type"]=hide 
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
    while (list (,$col) = each ($cols)) {
        $cname = $col["name"];
        if (isset ($columns[$col["name"]])) 
            $columns[$col["name"]]["type"] = $col["type"];
        if (strstr ($col["flags"], "primary_key")) {
            if (!isset ($columns[$cname]))
                $columns[$cname]["view"]["type"] = "hide";              
            $type = is_field_type_numerical ($col["type"]) ? "number" : "text";
            $columns[$cname]["primary"] = $type;
        }
        if (isset ($columns[$cname]))
            $columns[$cname]["auto_increment"] = strstr ($col["flags"], "auto_increment");
    }
    return $columns;
}

// -----------------------------------------------------------------------------------

// deletes one record identified by key values from given table
function TableDelete ($table, $key_value, $columns, $be_cautious=1) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    $where = CreateWhereCondition ($key_value, $columns);
    if ($be_cautious) {
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) return false;
    }
    return $db->query ("DELETE FROM $table WHERE $where");
}

// -----------------------------------------------------------------------------------
   
// updates one record identified by key values in given table    
function TableUpdate ($table, $key_value, $val, $columns, $be_cautious=1) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    if (!ProoveVals ($table, $val, $columns))
        return join ("\n", $GLOBALS["err"]);
    $varset = new CVarset();
    reset ($columns);
    while (list ($colname, $col) = each ($columns)) {
        if (isset ($val[$colname])) {
            $value = $val[$colname];
            if (get_magic_quotes_gpc()) 
                $value = stripslashes ($value);
            if (is_field_type_numerical ($col["type"]))
                 $varset->set($colname,$value,"number");
            else $varset->set($colname,$value,"text");         
        }
    }

    $where = CreateWhereCondition ($key_value, $columns);
    if ($be_cautious) {
        $db->query ("SELECT * FROM $table WHERE $where");
        if ($db->num_rows() != 1) return false;
    }
    return $db->query ("UPDATE $table SET ".$varset->makeUPDATE()." WHERE $where");
}

// -----------------------------------------------------------------------------------

// inserts a record and returns the key
function TableInsert ($table, $val, $columns) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    if (!ProoveVals ($table, $val, $columns)) {
        print_r ($GLOBALS["err"]); exit; }
    $varset = new CVarset();
    reset ($columns);
    while (list ($colname, $col) = each ($columns)) {
        if ($col["primary"]) {
            if ($col["auto_increment"])
                $auto_inc = true;
            else if (!$val[$colname]) {
                echo "Error: Primary column $colname not set."; exit; }
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

    $ok = $db->query ("INSERT INTO $table ".$varset->makeINSERT());
    if (!$ok) { echo "DB error on inserting record"; exit; }
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

// creates key string with values from key fields separated by :
function GetKey ($columns, $record)
{
    reset ($columns);
    unset ($key);
    while (list ($colname,$column) = each ($columns)) 
        switch ($column["primary"]) {
        //case "packed": $key[] = unpack_id ($record [$colname]); break;
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
            //case 'packed': $where[] = "$colname='".q_pack_id ($val)."'"; break;
            case 'number': $where[] = "$colname=$val"; break;
            case 'text': $where[] = "$colname='".str_replace("'","\\'",$val)."'";
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
    <!--
        function validate_number (txtfield, min, max, required) {
            if (!validate (txtfield, 'number', required))
                return false;
            var val = txtfield.value;
            var err = '';
            if (val > max || val < min) 
                err = '"._m("Wrong value: a number between %1 and %2 is expected.",array("'+min+'","'+max+'"))."';
            if (err != '') {
                alert (err);
                txtfield.focus();
                return false;
            }
        }
        
        function validate (txtfield, type, required) {
            var invalid_email = /(@.*@)|(\.\.)|(@\.)|(\.@)|(^\.)/; 
            var valid_email = /^.+\@(\[?)[a-zA-Z0-9\-\.]+\.([a-zA-Z]{2,3}|[0-9]{1,3})(\]?)$/; 
            
            var val = txtfield.value;
            var err = '';
            
            if (val == '' && required)
                err = '"._m("This field is required.")."';
            
            else switch (type) {
            case 'number': 
                if (!val.match (/^[0-9]+$/)) 
                    err = '"._m("Not a valid integer number.")."';
                break;
            case 'filename':
                if (!val.match (/^[0-9a-zA-Z_]+$/)) 
                    err = '"._m("Not a valid file name.")."';
                break;
            case 'email': 
                if (val.match(invalid_email) || !val.match(valid_email)) 
                    err = '"._m("Not a valid email address.")."';
                break;
            }
            
            if (err != '') {
                alert (err);
                txtfield.focus();
                return false;
            }
            return true;
        }
    // -->
    </script>";   
}
?>
