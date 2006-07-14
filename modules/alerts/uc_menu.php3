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

// (c) Econnect, Jakub Adamek, January 2003

require "uc_init_page.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";

require $GLOBALS["AA_INC_PATH"].get_mgettext_lang()."_news_lang.php3";

$menu["user"] = array (
    "label" => _m("User Info"),
    "title" => _m("User Info"),
    "href" => "uc_tabledit.php3?set_tview=au_edit",
);

$menu["password"] = array (
    "label" => _m("Change Password"),
    "title" => _m("Change Password"),
    "href" => "uc_chpwd.php3"
);

$menu["subscribed"] = array (
    "label" => _m("Subscribed Collections"),
    "title" => _m("Subscribed Collections"),
    "href" => "uc_tabledit.php3?set_tview=auc",
);

$menu["new"] = array (
    "label" => _m("New Subscription"),
    "title" => _m("New Subscription"),
    "href" => "uc_new.php3",
);

$menu["signout"] = array (
    "label" => _m("Sign Out"),
    "href" => "uc_tabledit.php3",
    "nosess"=>true);

function showMenu ($item) {
    global $menu, $sess, $AA_INSTAL_PATH;
    
    HtmlPageBegin();   

    echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
    echo "<TITLE>".$menu[$item]["title"]."</TITLE></HEAD>";

    echo '<TABLE cellspacing="0" cellpadding="0" height="100%" width="100%">
        <TR>';
        
    // left menu    
    echo '<TD class="leftmenu" valign="top" rowspan="2" width="1%">';                
    echo '<TABLE width="120" cellspacing="0" cellpadding="5" height="100%">';
    echo '<TR><TD class="tabtit"><HR></TD></TR>';
    echo '<TR height="50"><TD align="center" valign="middle" class="tabtit"><font size="normal"><b>'
        ._m("User Center")."</b></font></TD></TR>";
    echo '<TR><TD class="tabtit"><HR></TD></TR>';
    reset ($menu);
    while (list ($myitem, $itemprop) = each ($menu)) {
        if ($myitem != $item) {
            $url = $itemprop["href"];
            if (!$itemprop["nosess"])
                $url = $sess->url($url);
            echo '<TR><TD class=leftmenuy>';
            echo '<a href="'.$url.'">';
        }
        else echo '<TR><TD class=leftmenua>';
        echo $itemprop["label"];
        if ($myitem != $item)
            echo '</a>';
        echo '</b></TD></TR>';
    }
    echo '<TR height="100%"><TD>&nbsp;</TD></TR>';
    echo '<tr valign="bottom" height="10%"><td class="copymsg"><small>'. L_COPYRIGHT .'</small></td></tr>';
    echo "</TABLE></TD>";
    // end of left menu
    
    // signed as
    global $db, $auth;
    $db->query ("SELECT * FROM alerts_user WHERE id=".$auth->auth["uid"]);
    $db->next_record();
    echo '<TD valign="top"><TABLE cellspacing="0" width="100%">
        <TR height="25"><TD class="tabtxt">&nbsp;&nbsp;'
        ._m("You are signed in as").': <b>'
        .$db->f("firstname")." ".$db->f("lastname")." &lt;".$db->f("email").'&gt;</b></TD></TR>';
    echo '</TD></TR><TR height="100%"><TD valign="top">
        <TABLE cellspacing="10"><TR><TD><H1>' . $menu[$item]["title"] . "</H1>";
}

function EndMenuPage () {
    echo "</TD></TR></TABLE>
    </TD></TR></TABLE>
    </TD></TR></TABLE></BODY></HTML>";
    page_close();
}
?>
