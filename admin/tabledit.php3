<?php

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
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

echo '<LINK rel=StyleSheet href="$AA_INSTAL_PATH/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";
showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<H1><B>" . $tableview["caption"] . "</B></H1>";
PrintArray($err);
echo $Msg;

if ($tableview["help"])     
    echo '<table border="0" cellspacing="0" cellpadding="5"><tr><td class="tabtit">'
        .$tableview["help"]
        .'</td></tr></table><br>';

$script = "tabledit.php3?AA_CP_Session=$AA_CP_Session";

$tabledit = new tabledit ($tview, $script, $cmd, $val, $tableview, $AA_INSTAL_PATH."images/", $sess, "", "", "GetTableView");
$err = $tabledit->view ($where);

if ($err) echo "<b>$err</b>";
HTMLPageEnd();
page_close ();
?>
