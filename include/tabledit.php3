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

/*  Class TableEdit

	(c) Jakub Adámek, Econnect, September 2002

	This is a multi-purpose class allowing to edit various tables. 
	It works based on a configuration array called Table View, see tableviews.php3. 
	The main features are:
	
    * highly configurable
	* two basic view types: browse x edit
	* insert, update, delete records
	* input validation
	* sort data by clicking on column headers
	* filter data by easy conditions or by complex WHERE SQL clauses
	* show hints about the table, columns etc.
	* scroller to easy go through lots of records
	* user-defined messages to show on errors
	* show 1:n tables as a parent and child
	* show m:n relations with a select box in a child
	
	VIEW TYPES: BROWSE x EDIT
	
	The Browse view is used to view many records at once. 
	It looks like a table with one row for each table record. If there are many
	records, a scroller is shown under the table allowing to jump through the records. 
	A search form may be shown to quickly find records. 
	After clicking on Edit on the left of a record you usually go to an Edit view,
	set by "gotoview".
		
	The Edit view is used to edit a record. Each field is shown on a separate row. 
	Usually only one record is shown although you may show many at once if you wish.
	
	INSERT, UPDATE, DELETE RECORDS AND INPUT VALIDATION
	
	Separate functions are prepared to do this. They work with a part of the
	Table View, describing columns to be shown. Input is first validated by 
	JavaScript before sending the form and again by PHP itself. 
	See "Table View grammar" in tableviews.php3 for validation types.
	
	PARENTS AND CHILDREN
	
	A 1:n (one-to-many) relationship means that 1 record in a Parent table is connected by
	key values to many records in the Child table. For example table Countries contains
	Czech republic with ID 54 and table Towns contains records (54, Praha), (54, Brno),
	(54, Ostrava) etc.
	
	The usual way to view one-to-many related tables is to use Edit view to show one
	parent record and one or more Browse view with related children records. Use "children"
	in the Table View definition to create such a view.
	
	A m:n (many-to-many) relationship always needs 3 tables A, B, C, with relationship
	1:n between A and B and 1:n between C and B. Table B contains the relation info,
	i.e. keys from both A and C. To view such a relationship you may use a child view
	on one 1:n relationship (e.g. parent A and child B) and use a select box which maps
	keys from C (as OPTION values) to some other field from C.
	
	For example A = country, B = place, C = place type (city / town / village). 
	You may have records like (54, Praha, city), (54, Brno, town), (54, Ostrava, town) 
	in table B and (1,city), (2,town), (3,village) in table C. 
	You view A as parent, B as child and the field typeID from table B
	is shown as a select box with values got from C.
	
	TABLE EDIT CLASS USAGE
	
	See admin/tabledit.php3 for an example of the class usage. 
*/	

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
    // main script URL, used as FORM action
    var $action;
    // global cmd[] parameter, created by this class, sent by a form
    var $all_cmd;
    // command to be executed (exactly $all_cmd[$viewID])
    var $cmd;
    /* used for CHILD tables only, contains joining field values
                        e.g. array ("id" => 7)    */
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

	/* constructor, see above for parameter description */
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
        if ($this->cmd["show_new"]) {
            unset($tecmd["edit"]);
            unset($this->cmd["edit"]);
        }
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
						if ($record_count > 1) {
	                        echo "$td<a href='".$this->getAction($gotoview2)."&cmd[".$this->viewID."]"
    	                        ."[orderby][$colname]=1'><b>$column[caption]</b>";
	                        if ($this->orderby == $colname) {
                                echo "&nbsp;<img src='".$this->imagepath;
                                if ($this->orderdir == 'd')
    	                             echo "down.gif' alt='"._m("order ascending")."'";
                                else echo "up.gif' alt='"._m("order descending")."'";
	                            echo " border=0>";                                                
                            }
	                        echo "</a><br>";
						}
						else echo "$td<b>$column[caption]</b>";
                        if ($column["hint"])
                            echo "<font class=te_hint>".$column["hint"]."</font>";
                        echo "</TD>\n";
                    }
                echo "</TR>";        
            }
            
            $fnname = "prooveFields_".$this->viewID;
            $this->ShowProoveFields ($fnname);

            // if $show_new is enabled, show empty record as last one
            $new_record = false;
            while (!$new_record) {
                if (!$db->next_record()) 
                    if ($this->show_new)
                        $new_record = true;
                    else break;
    
                $key = $new_record ? $GLOBALS[new_key] : GetKey ($this->cols, $db->Record);
    
                $formname = "tv_".$this->viewID."_".$key;
                if ($this->view["type"] == "browse") {
                    echo "<FORM name='$formname' method=post action='".$this->getAction($gotoview2)."'>\n";
                    echo "<TR>";
                    $this->ShowButtons ($gotoview, $gotoview2, $new_record, $key, $fnname, $formname);
                }
                else echo "<FORM name='$formname' method=post onSubmit='$onsubmit' action='".$this->action."'>\n";
                            
                // add join fields
                if ($new_record && is_array ($this->joincols)) {
                    reset ($this->joincols);
                    while (list ($col,$val) = each ($this->joincols)) 
                        echo "<INPUT TYPE=hidden NAME='val[$col]' VALUE='".str_replace("'","\\'",$val)."'>";
                }
                
                $this->ShowColumnValues ($db->Record, $new_record);
                
                // show buttons Update/Insert and Cancel
                if ($this->view["type"] == "edit") {
                    echo "<TR><TD colspan=2 ".$this->view["attrs"]["td"]." align=center>";
                    echo "<INPUT type=hidden name='set_tview' VALUE='$gotoview2'>\n";
                    if (!$this->view["readonly"]) {
                        echo "<INPUT type=submit name='cmd[".$this->viewID."]";
                        if (!$new_record) 
                             echo "[update][$key]' value='".L_UPDATE."'>";
                        else echo "[insert]' value='".L_INSERT."'>";
                        echo "&nbsp;&nbsp;";
                    }
                    echo "<INPUT type=button name='cancel' onclick='this.form.set_tview.value=\"$gotoview\"; this.form.submit();'"
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
        
            if ($this->view["search"])  
                $this->ShowSearchRow ($gotoview2);
        }
        
        if ($this->view["type"] == "browse" 
            && ($this->view["readonly"] || $this->view["button_add"]) 
            && $this->view["gotoview"]) {
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

    function setDefault (&$var, $def) {
        if (!isset ($var)) $var = $def;
    }
    
    function SetViewDefaults () {
        $this->setDefault ($this->view["addrecord"], true);
        $this->setDefault ($this->view["listlen"],   15);
        $this->setDefault ($this->view["search"],    $this->view["type"] == "browse");
        $this->setDefault ($this->view["messages"]["no_item"], _m("Nothing to be shown."));
        
        reset ($this->cols);
        while (list ($colname) = each ($this->cols)) {
            $column = &$this->cols[$colname];
            $this->setDefault ($column["caption"], $colname);
            $this->setDefault ($column["view"]["size"]["cols"], 40);
            $this->setDefault ($column["view"]["size"]["rows"], 4);
            $this->setDefault ($column["view"]["html"], false);
        }
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
            $where .= " AND (".stripslashes_magic($srch["where"]).") ";
            unset($srch["value"]);
            unset($srch["field"]);
        }
        else if ($srch["value"] || $srch["value"] == "0") {
            if (is_field_type_numerical ($this->cols[$srch["field"]]["type"]))
                 $where .= " AND $srch[field] = $srch[value] ";
            else $where .= " AND $srch[field] LIKE '%".addslashes_magic($srch[value])."%' ";
        }
        
        if ($this->view["where"]) 
            $where .= " AND (".$this->view["where"].") ";
            
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
   
    // prints javascript for input validation 
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
                       
            if ($this->view["type"] == "edit") {
                echo "<TR>$td<b>".$column["caption"]."</b><br>\n";
                if ($column["hint"])
                    echo "<font class=\"te_hint\">".$column["hint"]."</font></TD>\n";
			}
        
            $type = $cview["type"];
            if (!$type) $type = $column["type"];                
        
            $val = $new_record ? $column["default"] : $record[$colname];
            
            $rows = 5;
            $cols = 80;
            
            echo $td;
            if (!$readonly) {
                switch ($type) {
                case 'area':
                case 'blob': 
                    $val = str_replace ('"','&quot;',$val);
                    echo "<textarea name=\"val[$colname]\""
                        ." rows=\"".$cview["size"]["rows"]."\" cols=\"".$cview["size"]["cols"]."\">\n"
                        .$val."</textarea>"; 
                    break;
                case 'select': FrmSelectEasy("val[$colname]", $cview["source"], $val); break;
                case 'text':
                default:
                   $val = str_replace ('"','&quot;',$val);
                   echo "<INPUT type=\"text\" size=\"".$cview["size"]["cols"]."\" name=\"val[$colname]\"
                        value=\"".$val."\">"; 
                }
            }
            else {
                switch ($type) {
                    case "select": $val = $cview["source"][$record[$colname]]; break;
                    case "date" :  $val = date($cview["format"], $val); break;
                }
                if ($val) {
                    if (!$cview["html"]) $val = htmlentities ($val);
                }
                else if (is_field_type_numerical ($column["type"]))
                    $val = $new_record ? "&nbsp;" : "0";
                else $val = "&nbsp;";
                if ($cview["href_view"]) 
                    echo "<a href='".$this->getAction($cview["href_view"])
                        ."&cmd[".$cview["href_view"]."][edit]"
                        ."[".str_replace("\"","\\\"",$record[$colname])."]=1'>".$val."</a>\n";
                else echo $val;
            }        
            echo "</TD>\n";
            
            if ($this->view["type"] == "edit") echo "</TR>";
        }
    }
    
    // -----------------------------------------------------------------------------------
    
    function ShowButtons ($gotoview, $gotoview2, $new_record, $key, $fnname, $formname) {                
        // "new" is label for new record, "new_name" is command for new record, 
        // "view" is view on which $this->cmd operates, "gotoview" is view which will be shown                
        $buttons_text = array (
            "edit" => array (
                "label" => "<img border=0 src=\"".$this->imagepath."edit.gif\" alt=\""._m("edit")."\">", 
                "view" => $gotoview, 
                "gotoview" => $gotoview),
            "delete" => array (
                "label" => "<img border=0 src=\"".$this->imagepath."delete.gif\" alt=\""._m("delete")."\">", 
                "view" => $this->viewID, 
                "gotoview" => $gotoview2),
            "update" => array (
                "label" => "<img border=0 src=\"".$this->imagepath."ok.gif\" alt=\""._m("update")."\">", 
                "new" => "<img border=0 src=\"".$this->imagepath."ok.gif\" alt=\""._m("insert")."\">", 
                "new_name" => "insert", 
                "view" => $this->viewID, 
                "gotoview" => $gotoview2, 
                "button"=>true));
/*
                <FORM name='tv_".$this->viewID."_insert' method=post action='".$this->getAction($gotoview)."'>
                <INPUT type=submit name='cmd[".$gotoview."][show_new]' value='".L_INSERT."'>
*/
                
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
                    echo $td;
                    if ($bt["button"]) 
                        echo "<INPUT type=hidden name='cmd[".$this->viewID."][$button][$key]' value='1'>\n";
                    $url = $this->getAction($bt[gotoview])."&cmd[$bt[view]][$button][$key]=1";
                    if ($button == "delete") $url = "javascript:confirmDelete (\"".$url."\");";
                    if ($bt["button"]) $url = "javascript:if ($fnname (\"$formname\")) document.forms[\"$formname\"].submit();";
                    if ($label) echo "<a href='$url'>".$label."</a></td>\n";                
                    else echo "&nbsp;</td>\n";
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
    
    if table has more than 1 primary key, send the chosen one in $primary
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
            $columns[$cname]["view"]["type"] = "hide";
        $columns[$cname]["type"] = $col["type"];
        if (is_array ($primary))
             $is_primary = my_in_array ($cname, $primary);
        else $is_primary = strstr ($col["flags"], "primary_key");
        if ($is_primary) {
            if (!isset ($columns[$cname]))
                $columns[$cname]["view"]["type"] = "hide";              
            $type = is_field_type_numerical ($col["type"]) ? "number" : "text";
            $columns[$cname]["primary"] = $type;
        }
        if (strstr ($col["flags"], "auto_increment"))
            $columns[$cname]["auto_increment"] = 1;
    }
    return $columns;
}

// -----------------------------------------------------------------------------------

// deletes one record identified by key values from given table
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
   
// updates one record identified by key values in given table    
function TableUpdate ($table, $key_value, $val, $columns, $error_msg="",  $be_cautious=1) {
    global $db;
    $columns = GetColumnTypes ($table, $columns);
    if (!ProoveVals ($table, $val, $columns))
        return $error_msg ? $error_msg : join ("\n", $GLOBALS["err"]);
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

/* inserts a record and returns the key or "" if not successfull
    params:
        $primary = array (field1,field2,...) - if the table has more than 1 primary key, you   
                 must send the correct one
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
// Warning: send $columns processed with GetColumnTypes
function CreateWhereCondition ($key_value, $columns) {
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
            else return true;
        }
        
        function confirmDelete (url) {
            if (confirm ('"._m("Are you sure you want to permanently DELETE this record?")."'))
                document.URL = url;
        }
    // -->
    </script>";   
}
?>
