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

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";

if(!IsSuperadmin()) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
    <TITLE><?php echo L_EDIT_WIZARD_WELCOME;?></TITLE>
    </HEAD>
<?php
    require $GLOBALS[AA_INC_PATH]."menu.php3"; 
    showMenu ("aaadmin","te_wizard_template");
    
    echo "<H1><B>" . L_EDIT_WIZARD_TEMPLATE . "</B></H1>";
    PrintArray($err);
    echo $Msg;
    
    $tblname = "wizard_template";
    $fields = array ("dir","description");
    $primary_key = array ("id"=>"number");
    $hint = array ();
    $attrs_edit = array (
            "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
    $attrs_browse = array (
            "url"=>"AA_CP_Session=$AA_CP_Session",
            "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
    $script = "te_wizard_template.php3";
    
    if ($cmd["update"] && ($par["where"] || $cmd["insertsent"]))
        TableUpdate ($tblname, $par["where"], $val, $fields);
    
    else if ($cmd["delete"])
        TableDelete ($tblname, $cmd["delete"], $primary_key);
      
    if ($cmd["edit"] || $cmd["insert"]) TableEditView ($tblname, $cmd["edit"], "$script?AA_CP_Session=$AA_CP_Session", $attrs_edit, $fields, $primary_key,$hint);
    else TableBrowseView ($tblname, $script, $attrs_browse, $fields, $primary_key);
?>
