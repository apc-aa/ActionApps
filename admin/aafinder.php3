<?php

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
*/

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."date.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."tabledit.php3";
require_once $AA_BASE_PATH.menu_include();   //show navigation column depending on $show
require_once $GLOBALS["AA_INC_PATH"]."mgettext.php3";
require_once $GLOBALS["AA_INC_PATH"]."../misc/alerts/util.php3";

// ----------------------------------------------------------------------------------------    

if (!IsSuperadmin()) {
    MsgPage ($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"), "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>"._m("AA finder")."</TITLE></HEAD>";
showMenu ($aamenus, "aaadmin", "aafinder");
echo "<H1><B>" ._m("AA finder"). "</B></H1>";
PrintArray($err);
echo $Msg;

$db = new DB_AA;

if ($go_findview && $findview) {
    $fields = array (
        "before",
        "even",
        "odd",
        "after",
        "group_title",
        "order1",
        "order2",
        "group_by1",
        "group_by2",
        "cond1field",
        "cond2field",
        "cond3field",
        "aditional",
        "aditional2",
        "aditional3",
        "aditional4",
        "aditional5",
        "aditional6",
        "group_bottom",
        "field1",
        "field2",
        "field3");
    
    $SQL = "SELECT view.id, view.type, view.slice_id, slice.name 
        FROM view INNER JOIN slice ON view.slice_id = slice.id WHERE ";
    reset ($fields);
    while (list (,$field) = each ($fields)) 
        $SQL .= "view.$field LIKE \"%".addslashes_magic ($findview)."%\" OR ";
    $SQL .= "0";
    $db->query($SQL);
    echo $db->num_rows()." matching views found:<br>";
    while ($db->next_record()) 
        echo $db->f("id")." (".$db->f("name").") "
                ."<a href=\"".$sess->url("se_view.php3?view_id=".$db->f("id")."&view_type=".$db->f("type")
                ."&change_id=".unpack_id128($db->f("slice_id")))
                ."\">"._m("Jump")."</a><br>";
}

if ($go_findslice && $findslice) {
    $fields = array (
        "fulltext_format_top",
        "fulltext_format",
        "fulltext_format_bottom",
        "odd_row_format",
        "even_row_format",
        "compact_top",
        "compact_bottom",
        "category_top",
        "category_format",
        "category_bottom",
        "admin_format_top",
        "admin_format",
        "admin_format_bottom",
        "aditional",
        "javascript");
    
    $SQL = "SELECT slice.name, slice.id
        FROM slice WHERE ";
    reset ($fields);
    while (list (,$field) = each ($fields)) 
        $SQL .= "$field LIKE \"%".addslashes_magic ($findslice)."%\" OR ";
    $SQL .= "0";
    $db->query($SQL);
    echo $db->num_rows()." matching slices found:<br>";
    while ($db->next_record()) 
        echo $db->f("name")." "
                ."<a href=\"".$sess->url("se_fulltext.php3?change_id=".unpack_id128($db->f("id")))
                ."\">"._m("Jump")."</a><br>";
}
    
// ------------------------------------------------------------------------------------------
// SHOW THE PAGE
    
echo '<FORM name="f_findview" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Find all VIEWS containing in any field the string:").'</b><br> 
    <input type="text" name="findview" value="'.$findview.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_findview" value="'._m("Go!").'">';
echo '</FORM><BR>';

echo '<FORM name="f_findslice" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Find all SLICES containing in any field the string:").'</b><br> 
    <input type="text" name="findslice" value="'.$findslice.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_findslice" value="'._m("Go!").'">';
echo '</FORM><BR>';

HTMLPageEnd();
page_close ();
?>
