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
    See doc/tabledit.html for more info.
*/	

require "tabledit_column.php3";
require "tabledit_util.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";

// identifies new record 
$new_key = "__new__";
// identifies info about checkbox
$checkbox_dummy = "_c_b_";

class tabledit {
    // serialization purposes??
    var $classname = "tabledit";

    // PUBLIC VARIABLES
    // active tableview
    var $view;
    // view ID
    var $viewID;
    // main script URL, used as FORM action
    var $action;
    // global cmd[] parameter, created by this class, sent by a form
    var $all_cmd;
    // value array returned as form data (edit / update table row)
    var $form_vals;
    /* used for CHILD tables only, contains joining field values
                        e.g. array ("id" => 7)    */
    var $joincols;
    // session (for scroller management)
    var $sess;
    // URL path for images (up.gif and down.gif)
    var $imagepath;    
    // function to get other Table View definitions by ID. Used in ShowChildren() and ProcessFormData()
    var $getTableViewsFn;
    
    // PRIVATE VARIABLES
    // command to be executed (exactly $all_cmd[$viewID])
    var $cmd;
    // field name to order by
    var $orderby;
    // ''='a' or 'd'
    var $orderdir;
    // show an empty record to add new data?
    var $show_new;
    // columns (fields) definition from view with added types info
    var $cols;
    // view type
    var $type;

	/* constructor, see above for parameter description */
    function tabledit($viewID, $action, $cmd, $form_vals, $view, $imagepath, &$sess, $joincols="", $parentViewID="", $getTableViewsFn="") {
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
        $this->form_vals = $form_vals;
        $this->type = $this->view["type"];
        
        $this->ProcessFormData();
        $this->UpdateCmd ();
    }

    // exchanges data between session-stored cmd (as tabledit_cmd) and current URL cmd
    function UpdateCmd () {
        if (is_object ($this->sess))
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
                $tecmd["orderdir"] = 'a';
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

    // -----------------------------------------------------------------------------------
    
    // shows one table form    
    function view ($where) {
        global $db;
        // is this a child view?
        $child = $this->joincols != "";
    
        $this->SetViewDefaults();    
        $where = $this->GetWhere ($where);
        
        $db->query ("SELECT COUNT(*) AS mycount FROM ".$this->getSelectFrom()." WHERE ".$where);
        $db->next_record();        
        $rowcount = $db->f("mycount");

        // scroller stuff                                           
        $scrname = "te_".$this->viewID;
        global $$scrname;
        $scroll = $$scrname;
        if (is_object ($this->sess))
            $scrurl = $this->sess->url($GLOBALS[PHP_SELF]."?set_tview=".$this->gotoview2()."&")."&";

        if (is_object($scroll)) {
            $scroll->metapage = $this->view["listlen"];
            $scroll->countPages ($rowcount);
            $scroll->updateScr($scrurl);
        }
        else {
            $$scrname = new scroller($scrname, $scrurl);
            $scroll = $$scrname;
            if (is_object ($this->sess))
                $this->sess->register ($scrname);
            $scroll->metapage = $this->view["listlen"];
            $scroll->countPages ($rowcount);
        }
        $$scrname = $scroll;

        if ($this->orderby) 
            $orderby = " ORDER BY ".$this->orderby.($this->orderdir == 'd' ? " DESC" : "");
        
        reset ($this->cols);
        while (list ($cname,$cprop) = each ($this->cols)) 
            if ($this->view["join"])
                $collist[] = $cprop["table"].".".$cname;
            else $collist[] = $cname;
            
        $db->query (
             " SELECT ".join(",",$collist)
            ." FROM ".$this->getSelectFrom()
            ." WHERE ".$where
            . $orderby
            ." LIMIT ".($scroll->current-1)*$scroll->metapage.",".$scroll->metapage);
            
        $record_count = $db->num_rows();

        if (!$record_count) 
            $no_item = !$this->cmd["search"]["value"];
        else $no_item = false;

        if ($this->view["help"]) {
            echo '<table border="0" cellspacing="0" cellpadding="5"><tr><td class="te_help">'
                .$this->view["help"]
                .'</td></tr></table><br>';
        }
        
        if (!$record_count && !$this->show_new) {
            if ($no_item)
                echo "<span class=te_no_item_msg>".$this->view["messages"]["no_item"]."</span>";
            else echo "<span class=te_no_item_msg>"._m("No record matches your search condition.")."</span>";
        }
            
        if ($record_count || $this->show_new) {      

            if ($this->view["search"] && !$no_item)
                $this->ShowSearchRow ();       
        
            echo "<TABLE ".$this->view["attrs"]["table"].">";
            $td = "<TD class=te_".substr($this->type,0,1)."_td>";

            if ($this->type == "browse") 
                $this->showBrowseHeader ($record_count);
            
            $fnname = "prooveFields_".$this->viewID;
            $this->ShowProoveFields ($fnname);

            $formname = "tv_".$this->viewID;
            if ($this->type == "browse") 
                echo "<FORM name='$formname' method=post action='".$this->getAction($this->gotoview2())."'>\n";

            while ($db->next_record()) {
                $records[] = $db->Record;
                $all_keys[] = GetKey ($this->cols, $db->Record);
            }
            // if $show_new is enabled, show empty record as last one
            if ($this->show_new) {
                $records[] = "new";
                $all_keys[] = $GLOBALS[new_key];
            }

            reset ($records);
            reset ($all_keys);
            $irow = 0;
            while (list (, $record) = each ($records)) {                
                $new_record = $record == "new";
                list (,$key) = each ($all_keys);
                $irow ++;
    
                if ($this->type == "browse") {
                    echo "<TR>";
                    $this->ShowButtons ($new_record, $key, $fnname, $formname, $irow, "left");
                }
                else {
                    $formname = "tv_".$this->viewID."_".$key;
                    echo "<FORM name='$formname' method=post action='".$this->action."'>\n";
                }
                            
                // add join fields for child tables
                if ($new_record && is_array ($this->joincols)) {
                    reset ($this->joincols);
                    while (list ($col,$val) = each ($this->joincols)) 
                        echo "<INPUT TYPE=hidden NAME='val[$key][$col]' VALUE='".str_replace("'","\\'",$val)."'>";
                }
                
                $this->ShowColumnValues ($record, $new_record, $key, $irow);
                
                if ($this->type == "browse") {
                    //$this->ShowButtons ($new_record, $key, $fnname, $formname, $irow, "left");
                    echo "</TR>";
                }
                else {
                    echo "<TR><TD align=center colspan=100>";
                    $this->ShowButtons ($new_record, $key, $fnname, $formname, $irow, "down", $all_keys, $record_count);                    
                    echo "</TD></TR></FORM>";
                }
            }
            if ($this->type == "browse") 
                $this->showBrowseFooter ($formname, $all_keys, $record_count);
            echo "</TABLE>";
        }
        else if ($this->type == "browse")
            $this->ShowButtons (false, "", "", "", 0, "down", array (), $record_count);        
        
        // scroller
        if ($scroll->pageCount() > 1) {
            echo "<P align=\"center\">";
        	$scroll->pnavbar();
            echo "</P>";        
        }
        
        if (is_array ($this->view["children"]) && $record_count == 1) 
             $err = $this->ShowChildren();

        return $err;
    }        

    // -----------------------------------------------------------------------------------
    
    function showBrowseHeader ($record_count) {
        echo "<TR>";
        $header = "<TD colspan=".count ($this->view["buttons_left"]).">&nbsp;</TD>";
        /*
        reset ($this->view["buttons_left"]);
        while (list ($button, $use) = each ($this->view["buttons_left"])) {
            $bt = $this->ButtonsText (false);
            $bt = $bt[$button];
            $alt = $bt["alt"] ? $bt["alt"] : "&nbsp;";
            $header .= "<TD class=te_b_col_head align=center>".$alt."</TD>\n"; 
        }*/
    
        echo $header;
        $td = "<TD class=te_".substr($this->type,1,1)."_td>";
        reset ($this->cols);
        while (list ($colname,$column) = each ($this->cols)) {
            if ($column["view"]["type"] != "hide" && $column["view"]["type"] != "ignore") {
                if ($record_count > 0) {
                     echo "$td<a href='".$this->getAction($this->gotoview2())."&cmd[".$this->viewID."]"
                         ."[orderby][$colname]=1'><span class=te_b_col_head>$column[caption]</span>\n";
                     if ($this->orderby == $colname) {
                         echo "&nbsp;<img src='".$this->imagepath;
                         if ($this->orderdir == 'd')
                              echo "down.gif' alt='"._m("order ascending")."'";
                         else echo "up.gif' alt='"._m("order descending")."'";
                         echo " border=0>";                                                
                     }
                     echo "</a>";
                }
                else echo "$td<span class=te_b_col_head>$column[caption]</span>\n";
                if ($column["hint"])
                    echo "<br>\n<span class=te_b_col_hint>".$column["hint"]."</span>";
                echo "</TD>\n";
            }
        }
        //echo $header;
        echo "</TR>";    
    }

    // -----------------------------------------------------------------------------------
    
    function showBrowseFooter ($formname, $all_keys, $record_count) {
        echo "<TR><TD colspan=100><TABLE width=\"100%\">
            <TR><TD class=\"te_b_col_head\" width=\"100\" valign=top>";
        if ($record_count) {    
            reset ($this->view["buttons_left"]);
            while (list ($button, $use) = each ($this->view["buttons_left"])) {
                $bt = $this->ButtonsText (false);
                $bt = $bt[$button];
                $alt = $bt["alt"] ? $bt["alt"] : "&nbsp;";
                $img = '<image border="0" src="'.$this->imagepath.$bt["img"].$big.'.gif" alt="'.$bt["alt"].'">';
                echo "$img = $alt<br>";
            }
        }
        else echo "&nbsp;";
        echo '</TD><TD width="50">&nbsp;</TD><TD>';
    
        $this->ShowButtons (false, "", "", $formname, 0, "down", $all_keys, $record_count);
        echo "</TD></TR></TABLE></TD></TR></FORM>";
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
        if (!$this->view["type"]) $err[] = "Missing type.";
        if (!$this->view["table"]) $err[] = "Missing table.";
        if (is_array ($err)) {
            echo "Table Edit : error in Table View params for ".$this->viewID." :<BR>";
            echo join ("<BR>", $err);
            exit;
        }
    
        $this->setDefault ($this->view["addrecord"], true);
        $this->setDefault ($this->view["listlen"],   15);
        $this->setDefault ($this->view["search"],    $this->type == "browse");
        $this->setDefault ($this->view["messages"]["no_item"], _m("Nothing to be shown."));
        $this->setDefault ($this->view["readonly"],  true);
                       
        $this->view["attrs"]["table"] .= " class=te_".substr($this->type,0,1)."_table ";
        
        reset ($this->cols);
        while (list ($colname) = each ($this->cols)) {
            $column = &$this->cols[$colname];
            $this->setDefault ($column["table"], $this->view["table"]);
            $this->setDefault ($column["caption"], $colname);
            $this->setDefault ($column["view"]["readonly"], 
                $this->view["readonly"] || $column["view"]["type"] == "userdef");
			$cols = 40;
			if ($column["len"]) 
				$cols = min (80, $column["len"]);
			if ($column["view"]["type"] == "date") 
				$cols = strlen (date ($column["view"]["format"], "31.12.1970"));
            $this->setDefault ($column["view"]["size"]["cols"], $cols);
            $this->setDefault ($column["view"]["size"]["rows"], 4);
            $this->setDefault ($column["view"]["html"], false);
            $this->setDefault ($column["view"]["type"], $column["type"]);
        }
        
        $this->setDefaultButtons();
    }        
    
    // -----------------------------------------------------------------------------------

    function getSelectFrom () {
        $from = $this->view[table];    
        if (is_array ($this->view["join"])) {
            reset ($this->view["join"]);
            while (list ($tname, $tprop) = each ($this->view["join"])) {
                unset ($froms);
                reset ($tprop["joinfields"]);
                while (list ($thisfield, $joinfield) = each ($tprop["joinfields"])) 
                    $froms[] = $this->view["table"].".$thisfield=".$tname.".$joinfield";
                switch ($tprop["jointype"]) {
                case "exact 1 to 1": $from .= " INNER JOIN "; break;
                default: $from .= " error .. bad jointype .. "; break;
                }
                $from .= $tname." ON ".join(" AND ", $froms);
            }
        }                 
        return $from;
    }   
                
    // -----------------------------------------------------------------------------------    
    // sets $where and $this->show_new
    function GetWhere ($where)
    {
        //echo "edit ".$this->cmd["edit"]." show new ".$this->cmd["show_new"]." readonly ".$this->view["readonly"]." addrecord ".$this->view["addrecord"]." gotoview ".$this->view["gotoview"];
        $this->show_new = false;
        // create SQL SELECT        
        // apply edit command only in Edit view
        if ($this->cmd["edit"] && $this->type == "edit") {
            $where = CreateWhereCondition (key ($this->cmd["edit"]), $this->cols, $this->view["table"]);
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
        
        if (isset ($this->view["where"])) 
            $where .= " AND (".$this->view["where"].") ";
            
        echo "<!-- where_condition $where -->";
            
        return $where;
    }   

    // -----------------------------------------------------------------------------------

    function ShowSearchRow () 
    {
        $td = "<TD class=te_search_td>";
        $tdd = "</span></TD>";
        
        echo "<FORM name='search_".$this->viewID."' method=post action='".$this->getAction($this->gotoview2())."'>
              <TABLE ".$this->view["attrs"]["table_search"]." class=te_search_table><TR>$td"
            .'<image border="0" src="'.$this->imagepath.'search.gif" alt="'._m("search").'">&nbsp;'
            ._m("search").": $tdd"
            ."$td";
        reset ($this->cols);
        while (list ($colname,$column) = each ($this->cols)) 
            if ($column["view"]["type"] != "hide" && $column["view"]["type"] != "ignore") 
                $options[$colname] = $column["caption"];
        $srch = $this->cmd["search"];
        FrmSelectEasy ("cmd[".$this->viewID."][search][field]", $options, $srch["field"]);
        echo "&nbsp;<INPUT name='cmd[".$this->viewID."][search][value]' type=text size=30 "
                ."value=\"".stripslashes_magic($srch[value])."\">$tdd"
            ."$td<INPUT type=submit name='go' value='"._m("Go")."'>$tdd</TR>";
    /*  echo "<TR>$td"._m("Complex Search (SQL WHERE clause): ")."$tdd"  
            ."$td<INPUT name='cmd[".$this->viewID."][search][where]' type=text size=50 "
                ."value=\"".stripslashes_magic($srch[where])."\">$tdd"
            ."$td<INPUT type=submit name='go' value='"._m("Go")."'>$tdd</TR>"; */
        echo "</TABLE></FORM>";
    }

    // -----------------------------------------------------------------------------------
   
    // prints javascript for input validation 
    function ShowProoveFields ($fnname)
    {
        PrintJavaScript_Validate();
        echo "
        <script language=javascript>
        <!--
            function $fnname (formname, keys) {
                myform = document.forms[formname];
                for (ikey = 0; ikey < keys.length; ikey ++) {
                    name = 'val[' + keys [ikey] + ']';\n";                
        reset ($this->cols);
        while (list ($colname, $column) = each ($this->cols)) {
            $req = $column["required"];
            if (!$req) $req = "0";
            if ($column["validate"] || $req != 0) {
                if ($column["validate_min"] && $column["validate"] == "number") 
                     echo "if (!validate_number(myform[name+'[".$colname."]'],".$column["validate_min"].",".$column["validate_max"].", $req))
                        return false;\n";
                else echo "if (!validate(myform[name+'[".$colname."]'],\"".$column["validate"]."\", $req))
                    return false;\n";
            }
        }
        echo "
                }
                return true;
            }
        // -->
        </script>";
    }
    
    // -----------------------------------------------------------------------------------
    
    function ShowColumnValues ($record, $new_record, $key, $irow)
    {
        if ($this->type == "browse") 
            $td = "<TD class=te_b_row".($irow % 2 ? "1" : "2").">";
        else $td .= "<TD class=te_e_td>";
        reset ($this->cols);
        while (list ($colname,$column) = each ($this->cols)) {
            $cview = $column["view"];
            if ($new_record && $column["view_new_record"])
                $cview = $column["view_new_record"];
                
            $val = $new_record ? $column["default"] : $record[$colname];
            
            $visible = $cview["type"] != "ignore" && $cview["type"] != "hide";
            if ($visible && $this->type == "edit") {
                echo "<TR>$td<span class=te_e_col_head>".$column["caption"]."</span><br>\n";
                if ($column["hint"])
                    echo "<span class=\"te_e_col_hint\">".$column["hint"]."</span>";
                echo "</TD>\n";
			}        

            if ($visible && $cview["href_view"] && $cview["readonly"]) 
                echo "<a href='".$this->getAction($cview["href_view"])
                    ."&cmd[".$cview["href_view"]."][edit]"
                    ."[".str_replace("\"","\\\"",$val)."]=1'>";
                    
            if ($visible)
                echo $td;        
            
            $name = str_replace ("\"", "\\\"", "val[$key][$colname]");
            // in tabledit_column.php3
            ColumnFunctions ($cview, $val, "show", $name);
        
            if ($visible && $cview["href_view"] && $cview["readonly"]) 
                echo "</a>\n";

            if ($visible) {
                echo "</TD>\n";                
                if ($this->type == "edit") 
                    echo "</TR>";
            }
        }
    }

    // -----------------------------------------------------------------------------------

    // gotoview = edit in browse view; update in edit view
    function gotoview () {
        return $this->view["gotoview"] ? $this->view["gotoview"] : $this->viewID;
    }
    
    // gotoview2 = delete,update in browse view; search form
    function gotoview2() {
        return ($this->joincols && $this->view["gotoview"]) 
            ? $this->view["gotoview"]
            : $this->viewID;
    }
                
    // -----------------------------------------------------------------------------------
    
    function SetDefaultButtons ()
    {
        $bl = $this->view["buttons_left"];
        $bd = $this->view["buttons_down"];
        
        $gotoview = $this->view["gotoview"] && $this->view["gotoview"] != $this->gotoview2();
        
        // default buttons:
        
        if ($this->view["type"] == "edit") {
            if ($gotoview) {
                if (!$this->view["readonly"]) {
                    $bd["update"] = 1;
                    $bd["delete"] = 1;
                }
                $bd["cancel"] = 1;
            }
            else if (!$this->view["readonly"])
                $bd["update"] = 1;
        }
                
        // browse view        
                
        else {
            if ($this->view["readonly"]) {
                if ($gotoview) {
                    $bl["edit"] = true;
                    if (!$this->view["addrecord"])
                        $bd["add"] = true;
                }
            }
            else {
                $bd["update_all"] = true;
                if ($gotoview) {
                    $bd["add"] = true;
                    $bl["edit"] = true;
                }
                $bd["delete_all"] = true;
                $bl["delete_checkbox"] = true;
            }
                    
            if ($bd["update"] || $bd["delete"]) 
                echo $this->viewID.": You should not use bottom buttons 'update' or 'delete' in
                        browse view, use 'update_all' and 'delete_all' instead.";
        }
        
        $this->setDefault ($this->view["buttons_left"], $bl);
        $this->setDefault ($this->view["buttons_down"], $bd);
    }

    // -----------------------------------------------------------------------------------
    
    function ButtonsText ($new_record) {
        // "new" is label for new record, "new_name" is command for new record, 
        // "view" is view on which $this->cmd operates, "gotoview" is view which will be shown                
        $buttons_text["edit"] = array (
            "name" => "edit",
            "img" => $new_record ? "" : "edit",
            "alt" => _m("edit"),
            "view" => $this->gotoview(), 
            "gotoview" => $this->gotoview());
        $buttons_text["add"] = array (
            "name" => "add",
            "img" => "edit",
            "alt" => _m("add"),
            "view" => $this->viewID,
            "gotoview" => $this->gotoview());            
        $buttons_text["delete"] = array (
            "name" => "delete",
            "img" => $new_record ? "" : "delete",
            "alt" => _m("delete"),
            "view" => $this->viewID, 
            "gotoview" => $this->type == "browse" ? $this->gotoview2() : $this->gotoview());
            
        // SPECIAL: "delete_checkbox" becomes "add" on new records
        $buttons_text["delete_checkbox"] = array (
            "name" => $new_record ? "insert" : "delete_all",
            "img" => $new_record ? "ok" : "delete",
            "alt" => $new_record ? _m("insert") : _m("delete"),
            "checkbox" => !$new_record,
            "view" => $this->viewID,
            "gotoview" => $this->gotoview2());
        $buttons_text["delete_all"] = array (
            "name" => "run_delete_all",
            "img" => "delete",
            "alt" => _m("delete checked"),
            "view" => $this->viewID,
            "gotoview" => $this->gotoview2());
        $buttons_text["update"] = array (
            "name" => $new_record ? "insert" : "update",
            "img" => "ok",
            "alt" => $new_record ? _m("insert") : _m("update"),
            "view" => $this->viewID, 
            "gotoview" => $this->gotoview2());
        $buttons_text["update_all"] = array (
            "name" => "update_all",
            "img" => "ok",
            "alt" => _m("update all"),
            "view" => $this->viewID, 
            "gotoview" => $this->gotoview2());
        $buttons_text["cancel"] = array (
            "name" => "cancel",
            "img" => "exit",
            "alt" => _m("cancel"),
            "view" => $this->viewID,
            "gotoview" => $this->gotoview());
        return $buttons_text;
    }    
    
    function ShowButtons ($new_record, $key, $fnname, $formname, $irow, $place="left", $all_keys="", $record_count=0) {                
        if ($place != "left")
            $big = "_big";
                
        if (!is_array ($this->view["buttons_$place"])) 
            return;

        if ($place == "down")
            echo "<TABLE><TR>\n";
            
        reset ($this->view["buttons_$place"]);
        while (list ($button,$use) = each ($this->view["buttons_$place"])) {
            $bt = $this->ButtonsText ($new_record);
            $bt = $bt[$button];
            if (!$use || !$bt)
                continue;
            switch ($place) {
                case "left": echo "<TD class=te_b_row".($irow % 2 ? "1" : "2").">"; break;
                case "down" :echo "<TD class=te_button_text align=center width=50>"; break;
            }
            switch ($bt["name"]) {
                case "add":
                    $url = $this->getAction($bt[gotoview])."&cmd[$bt[gotoview]][show_new]=1";
                    break;
                case "delete":
                    $url = $this->getAction($bt[gotoview])."&cmd[$bt[view]][$bt[name]][$key]=1";
                    $url = "javascript:confirmDelete (\"".$url."\");";
                    break;
                case "cancel":
                    $url = $this->getAction($bt[gotoview]);
                    break;
                case "edit":
                    $url = $this->getAction($bt[gotoview])."&cmd[$bt[view]][$bt[name]][$key]=1";
                    break;
                case "run_delete_all":
                    $hidden = "cmd[".$this->viewID."][run_delete_all]";
                    $url = "javascript: if (confirm (\""._m("Are you sure you want to permanently DELETE all the checked records?")."\")) exec_commit (\"$formname\",\"$hidden\");";
                    echo "<INPUT type=hidden name='$hidden' value=0>";
                    break;
                case "insert":
                case "update":
                    $hidden = "cmd[".$this->viewID."][update][$key]";
                    $url = "javascript:if ($fnname (\"$formname\",new Array(\"$key\"))) exec_commit (\"$formname\",\"$hidden\");";
                    echo "<INPUT type=hidden name='$hidden' value=0>";
                    break;
                case "update_all":
                     // javascript array of all keys for form validation
                    $js_all_keys = 'new Array ("'.join ('","', $all_keys).'")';          
                    $hidden = "cmd[".$this->viewID."][update_all][]";
                    $url = "javascript:if ($fnname (\"$formname\",$js_all_keys)) exec_commit (\"$formname\",\"$hidden\");";
                    echo "<INPUT type=hidden name='$hidden' value=0>";
                    break;
                default:
                    $url = "";
                    break;
            }

            if ($bt["img"])
                 $img = '<image border="0" src="'.$this->imagepath.$bt["img"].$big.'.gif" alt="'.$bt["alt"].'">';
            else $img = "";
        
            if ($this->type == "browse" && $place == "down" && $record_count == 0 && $bt["name"] != "add")
                $text = "";
            //if ($bl["delete_checkbox"] && $bl["update"] && $new_record) 
                //echo "";
            else if ($bt["checkbox"]) 
                $text = "$img<INPUT TYPE=checkbox NAME=cmd[$bt[view]][$bt[name]][$key]>";
            else if ($img) $text = "<a href='$url'>$img</a>";                
            else $text = "";   
            
            echo $text ? $text : "&nbsp;";
            // show the text label for bottom buttons and for insert                     
            if ($text && ($place == "down" || $new_record)) 
                echo "<br>".$bt["alt"]; 
            echo "</td>\n";
        }
        if ($place == "down")
            echo "</TR></TABLE>\n";
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
            
            echo "<br><span class=te_child_header>".$child["header"]."</span><br>";
            $fn = $this->getTableViewsFn;
            $chtv = $fn ($chview);
            $chtv["gotoview"] = $this->viewID;
            $action = $this->action . (strstr($this->action,"?") ? "&" : "?")
                      ."cmd[".$this->viewID."][edit][$key]=1";
            $childte = new tabledit ($chview, $action, $this->all_cmd[$chview], $this->form_vals, $chtv, $this->imagepath, $this->sess, $joincols);
            $err = $childte->view($where);
            if ($err) return $err;
        }
    }

    // -----------------------------------------------------------------------------------

    // processes insert form data, returns true on success, false on fail
    function ProcessInsert ($myviewid) {
        $getTableViewsFn = $this->getTableViewsFn;
        $myview = $getTableViewsFn ($myviewid);
        // WARNING: a bit hackish: after inserting an item, the command is changed to edit it
        $newkey = TableInsert ($myview["table"], $this->form_vals[$GLOBALS[new_key]],
                    $myview["fields"], $myview["primary"], $myview["messages"]["error_insert"]);
        unset ($this->all_cmd[$myviewid]["insert"]);
        if ($newkey != "") {
            // show inserted record again
            $this->all_cmd[$myviewid]["edit"][$newkey] = 1;
            
            // add currently inserted item to editable items
            if ($myviewid == $this->viewID && $this->view["where"]) {
            	$mywhere = CreateWhereCondition ($newkey, $this->cols);
            	$this->view["where"] = "(".$this->view["where"].") OR $mywhere";		 
            }
        }
        // reload the actual command
        $this->cmd = $this->all_cmd[$this->viewID];
        return $newkey != "";
    }

    // -----------------------------------------------------------------------------------
    
    function ProcessFormData () {   
        global $err, $debug;
        // don't process again in children views 
        if ($this->joincols) return;
        if (!is_array ($this->all_cmd)) return;
        $getTableViewsFn = $this->getTableViewsFn;
        if ($debug) 
        { echo "cmd: ";print_r ($this->all_cmd); echo "<br>val: ";print_r($this->form_vals); echo"<br>"; }

        if (is_array ($this->form_vals)) {
            reset ($this->form_vals);
    		while (list ($key, $key_vals) = each ($this->form_vals)) 
    	        while (list ($col, $val) = each ($key_vals))
                    // defined in tabledit_column.php3 
            	    ColumnFunctions ($this->cols[$col]["view"],
                        &$this->form_vals[$key][$col],
                        "form");
        }

        reset ($this->all_cmd);
        while (list ($myviewid, $com) = each ($this->all_cmd)) {
            $myview = $getTableViewsFn ($myviewid);
            reset ($com);
            while (list ($command, $par) = each ($com)) {                
                switch ($command) {
                case "update":
                    if (current ($par)) {
                        $ok = true;
                        if (key($par) == $GLOBALS[new_key])                        
                            $ok = $this->ProcessInsert ($myviewid);
                        else $ok = TableUpdate ($myview["table"], $myview["join"], key($par), $this->form_vals[key($par)], $myview["fields"], $myview["messages"]["error_update"]);
                        if (!$ok) { PrintArray ($err); $err = ""; }
                    }
                    break;
                case "update_all":
                    reset ($this->form_vals);
                    $ok = true;
                    while (list ($key, $vals) = each ($this->form_vals)) {
                        if ($key != $GLOBALS[new_key])
                            $ok = $ok && TableUpdate ($myview["table"], $myview["join"], $key, $vals, $myview["fields"], $myview["messages"]["error_update"]);
                    }
                    if (!$ok) { PrintArray ($err); $err = ""; }
                    break;
                case "delete_all":
                    if ($com["run_delete_all"]) {
                        reset ($par);
                        while (list ($key) = each ($par))                        
                            TableDelete ($myview["table"], $key, $myview["fields"],
                                         $myview["messages"]["error_delete"]);
                    }
                    break;
                case "delete":
                    TableDelete ($myview["table"], key($par), $myview["fields"], $myview["messages"]["error_delete"]);
                    break;
                default:
                    break;
                }
            }
        }
        
        PrintArray($err);
    }
    
}
// END of class tabledit

?>
