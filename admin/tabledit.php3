<?php

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set[table] -- required, name of the table view
*/

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."menu.php3";
require $GLOBALS[AA_INC_PATH]."mgettext.php3";
require $GLOBALS[AA_INC_PATH]."../misc/alerts/util.php3";

require $GLOBALS[AA_INC_PATH]."tableviews.php3";

// ----------------------------------------------------------------------------------------    

$sess->register("tview");
if ($set_tview) $tview = $set_tview;

$tableview = GetTableView($tview);
        
if (!is_array ($tableview)) {
    MsgPage ($sess->url(self_base()."index.php3"), "Bad Table view ID: ".$tview);
    exit;
}
    
if (! $tableview["cond"] ) {
    MsgPage ($sess->url(self_base()."index.php3"), L_NO_PS_ADD, "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";

showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);

echo "<H1><B>" . $tableview["caption"] . "</B></H1>";
PrintArray($err);
echo $Msg;

if ($tableview["help"])     
    echo '<table border="0" cellspacing="0" cellpadding="5"><tr><td class="tabtit">'
        .$tableview["help"]
        .'</td></tr></table><br>';

if (is_array ($cmd)) {        
    reset ($cmd);
    while (list ($myviewid, $com) = each ($cmd)) {
        if ($com["update"]) {
            $key = key ($com["update"]);      
            $myview = GetTableView ($myviewid);
            $error = TableUpdate ($myview["table"], $key, $val, $myview["fields"]);
            if ($error) PrintArray ($err);
        }
        // WARNING: a bit hackish: after inserting an item, the command is changed to edit it
        if ($com["insert"]) {
            $myview = GetTableView ($myviewid);
            $newkey = TableInsert ($myview["table"], $val, $myview["fields"]);
            unset ($cmd[$myviewid]["insert"]);
            // show inserted record again
            //if ($myview["type"] == "edit")
            $cmd[$myviewid]["edit"][$newkey] = 1;
        }
        if ($com["delete"]) {
            $key = key ($com["delete"]);      
            $myview = GetTableView ($myviewid);
            TableDelete ($myview["table"], $key, $myview["fields"]);
        }
    }
}
  
$script = "tabledit.php3?AA_CP_Session=$AA_CP_Session";

$tabledit = new tabledit ($tview, $script, $cmd, $tableview, AA_INSTAL_URL."images/", $sess, "", "", "GetTableView");
$err = $tabledit->view ($where);

if ($err) echo "<b>$err</b>";
page_close ();
?>
