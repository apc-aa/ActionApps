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

/* Alerts user settings - shows login page
   Global parameters:
       $uid or $email
       $password (may be empty when the user wishes)
       $lang - set language
       $ss - set style sheet URL
       
       $show_email - email to be shown but not processed (used by confirm.php3)
*/

$directory_depth = "../";
require "../../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $MODULES[$g_modules[$slice_id]['type']]['menu'];   
require "cf_common.php3";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Collection Form Wizard") ."</TITLE>
</HEAD>";

showMenu ($aamenus, "admin", "formwizard");

if (!is_object ($db)) $db = new DB_AA;

if (!$collectionid) {
    $db->query("SELECT id, description FROM alerts_collection WHERE showme=1");
    while ($db->next_record()) 
        $collections [$db->f("id")] = $db->f("description");
    
    echo "<FORM name=choose_collection ACTION=cf_wizard.php3 METHOD=post>\n";
    echo "<INPUT TYPE=hidden NAME=AA_CP_Session VALUE='$AA_CP_Session'>\n";
    echo "<B>"._m("Select Collection: ")."</B>";
    FrmSelectEasy ("collectionid", $collections, $collectionid, "onchange='document.choose_collection.submit()'");
    echo "<INPUT TYPE=submit VALUE='"._m("Go")."'>\n";
    echo "<br><B>"._m("You can choose only from collections where <i>url</i> is defined.")."</B>\n";
    echo "</FORM>\n";

    HTMLPageEnd();
    page_close();
    exit;
}

if ($formlang)
    bind_mgettext_domain ($GLOBALS[AA_INC_PATH]."lang/".$formlang."_news_lang.inc");
    
// create the Choose Filters code
$db->query ("
    SELECT DF.* FROM alerts_collection_filter CF INNER JOIN
    alerts_filter DF on CF.filterid = DF.id
    WHERE CF.collectionid = $collectionid
    ORDER BY CF.myindex");
while ($db->next_record()) 
    $filters .= "\n    <INPUT TYPE=\"checkbox\" NAME=\"alerts[filters][".$db->f("id")."]\" CHECKED> "
        .$db->f("description")."<BR>";
        
$cf_fields["filters"] = array ("code"=>$filters, "label"=>_m("Choose Filters"));        

$db->query ("SELECT * FROM alerts_collection WHERE id=$collectionid");
if (!$db->next_record()) { echo "Error: collection id $collectionid not found."; exit; }
$cf_url = $db->f("url");

echo "
<table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
<TR><TD class=tabtxt>";
echo "<h1>"._m("Collection Form Wizard for")." ".$db->f("description")."</h1>";
echo _m(
    "This Wizard helps to create Collection Forms, i.e. forms, where users subscribe 
     or change their subscription to a Collection. The Wizard creates the rough HTML
     form code, which you can change to your particular design.<br><br>\n
     Add the code to the .shtml page either directly or by including a file. Add the following 
     SSI includes after it:")
     ."<br><code>"
     .HTMLEntities ("<!--#include virtual=\"".$AA_INSTAL_PATH."misc/alerts/cf_filler.php3?cid=$collectionid\"-->")."<br>\n"
     .HTMLEntities ("<!--#include virtual=\"".$AA_INSTAL_PATH."misc/alerts/cf_fillform.php3?cid=$collectionid\"-->")
     ."<br></code>"
     ._m("The table below shows fields which you may want to appear in your form. Some of them 
     are required (marked by asterix *).<BR><BR>\n
     After you have created the form, jump to it: ")."<a target=_blank href='$cf_url'>$cf_url</a>\n";
echo "<BR><BR>
<FORM name='collection_form_wizard' METHOD=post ACTION='cf_wizard.php3#formcode'>
    <INPUT TYPE=hidden NAME='AA_CP_Session' VALUE='$AA_CP_Session'>
    <INPUT TYPE=hidden NAME='collectionid' VALUE=$collectionid>";

$formaction = $AA_INSTAL_PATH."post2shtml.php3?shtml_page=".$cf_url;    
echo "<b>"._m("form language").":</b> ".$cf_fields["lang"]["code"]."<br><br>";
        
echo "
<TABLE border=1>
<TR><TD class=tabtxt><B>"._m("show")."</B></TD><TD class=tabtxt colspan=2><b>"._m("example")."</b></TD></TR>";
    
$formcode = "<FORM name=\"cf$collectionid\" action=\"$formaction\" method=\"post\" 
    onsubmit=\"return validate();\">
<INPUT type=\"hidden\" name=\"alerts[lang]\" value=\"".get_mgettext_lang()."\">
<INPUT type=\"hidden\" name=\"alerts[collectionid]\" value=\"$collectionid\">
<INPUT type=\"hidden\" name=\"alerts[userid]\">
<INPUT type=\"hidden\" name=\"alerts[run_filler]\" value=\"1\">
<INPUT type=\"hidden\" name=\"alerts[choose_filters]\" value=\""
    .($show["filters"] || !$showme ? "checkbox" : "no")."\">
<TABLE border=\"0\">\n";
$rowspan = 1;
reset ($cf_fields);
while (list ($fname, $fprop) = each ($cf_fields)) {
    if ($fprop["hidewizard"])
        continue;
    echo "<TR>";
    $rowspan --;
    if ($rowspan == 0) {
        $rowspan = $fprop["rowspan"];
        echo "<TD rowspan=$rowspan>";
        if ($fprop["required"]) {
            echo "*";
            $show[$fname] = true;
        }
        else {
            if (!$showme) $show[$fname] = true;
            FrmChBoxEasy ("show[$fname]", $show[$fname]);
        }
        echo "</TD>\n";
    }
    $row = "  <TD><B>$fprop[label]:</B></TD>\n  <TD>$fprop[code]</TD></TR>\n";
    echo $row;
    
    $showme = $fprop["checkbox"] ? $show[$fprop["checkbox"]] : $show[$fname];
    if ($showme)
        $formcode .= "<TR>\n".$row;
}
$formcode .= "
<TR><TD colspan=2><INPUT type=\"submit\" value=\""._m("OK")."\"></TD></TR>
</TABLE></FORM>\n";
    
echo "</TABLE><BR><BR>";
echo "<INPUT TYPE=submit NAME=showme VALUE='"._m("Reload form code")."'>";
echo "</FORM>";    

echo "<A id='formcode'>
<TEXTAREA cols=80 rows=20>".HTMLEntities($formcode)."</TEXTAREA>\n";
    
echo "</TD></TR></TABLE>";
HTMLPageEnd();
page_close();
?>

