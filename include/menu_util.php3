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

/* Author: Jakub Adamek 

   This script shows the top menu (navigation bar) and second level menu (left bar)
   by the function showMenu ($aamenus, $activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1).
*/

if (!defined ("AA_MENU_INCLUDED"))
    define("AA_MENU_INCLUDED","1");
else return;

// use instead of </body></html> on pages which show menu
function HtmlPageEnd() {
  echo "
    </TD></TR></TABLE>
    </TD></TR></TABLE>
    </BODY></HTML>";
}

// ----------------------------------------------------------------------------------------
/* creates a JavaScript variable modulesOptions, which allows to create another Module selectbox
    without reprinting all the options */

function PrintModuleSelection() {
  global $slice_id, $g_modules, $sess, $PHP_SELF, $db, $MODULES;

  if( is_array($g_modules) AND (count($g_modules) > 1) ) {

    // create the modulesOptions content:
    echo "
    <SCRIPT language=JAVASCRIPT><!-- 
        var modulesOptions = ''\n";
    $db->query ("SELECT module.id, module.type, slice.type AS slice_type, module.name 
        FROM module LEFT JOIN slice ON module.id=slice.id
        ORDER BY module.type, slice.type, name");
    $option_begin = "\t+'<option value=\"";
    while ($db->next_record()) {
        switch ($db->f("type")) {
        case 'Alerts': $caption = _m("Alerts"); break;
        case 'W': $caption = _m("Site"); break;
        case 'A': $caption = _m("MySQL Auth (old version)"); break;
        case 'J': $caption = _m("Jump inside control panel"); break;
        case 'P': $caption = _m("Polls"); break;
        case 'L': $caption = _m("Links"); break;
        case 'S':
            switch ($db->f("slice_type")) {
            case 'ReaderManagement': $caption = _m("Reader Management Slice"); break;
            default: $caption = _m("Slice"); break;
            }
        }
        if ($caption != $old_caption) {
            if ($old_caption) echo $option_begin."\">'\n";
            echo $option_begin . "\">*** ".$caption." ***'\n";
            $old_caption = $caption;
        }

        echo $option_begin . htmlspecialchars(unpack_id ($db->f("id")))."\"";
        
        if ($slice_id == unpack_id ($db->f("id"))) 
            echo " selected";
        echo ">". str_replace("'","`",safe($db->f("name"))) . "'\n";
    }
        
    if( !$slice_id )   // new slice
      echo "\t+'<option value=\"new\" selected>". _m("New slice") + "'";
    echo ";
        document.write('<select name=slice_id onChange=\\'if (this.options[this.selectedIndex].value != \"\") document.location=\"" .con_url($sess->url($PHP_SELF),"change_id=")."\"+this.options[this.selectedIndex].value\\'>');
        document.write(modulesOptions);
        document.write('</select>');
    //-->
    </SCRIPT>\n";
} else
    echo "&nbsp;"; 
}  

// ----------------------------------------------------------------------------------------
//                                SHOW MENU

/* PARAMS: $smmenus -- array with menu information, see menu.php3 for an example
           $activeMain -- selected item in main (top) menu
           $activeSubmenu -- selected item in sub (left) menu
           $showMain -- show the main menu (top navigation bar) ?
           $showSub -- show the submenu (left navigation bar) ?
*/             
function showMenu ($smmenus, $activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1)
{
    global $slice_id, $AA_INSTAL_PATH, $r_slice_headline, $useOnLoad, $sess, $db;
    global $debug;
    
    #huhsess("Session Variables");
    // load the main AA menu (see menu.php3)
    if ($smmenus == "aamenus")
        $smmenus = get_aamenus();

    // HACKISH: aaadmin menu needs always the _news_ lang file, even in other than slice modules
    if ($activeMain == "aaadmin") 
        bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".get_mgettext_lang()."_news_lang.php3");
         
    if( $useOnLoad )
        echo '<body OnLoad="InitPage()" background="'. COLOR_BACKGROUND .'">';
    else
        echo '<body background="'. COLOR_BACKGROUND .'">';

    if ($debug) { echo "<p><font color=purple>showMenu:activeMain=$activeMain;activeSubmenu=$activeSubmenu;showMain=$showMain;showSub=$showSub:</font></p>";  }
   
    if( !$slice_id )
        $r_slice_headline = _m("New slice");

    $nb_logo = '<a href="'. $AA_INSTAL_PATH .'"><img src="'.$AA_INSTAL_PATH.'images/action.gif" width="106" height="73" border="0" alt="'. _m("APC Action Applications") .'"></a>';

    echo "<TABLE border=0 cellspacing=0 cellpadding=0 width='100%'><TR>";

    if ($showMain) {
        if ($GLOBALS["g_modules"][$slice_id]["type"] == "Alerts")
            $title_img = "<img border=0 src=\"".$AA_INSTAL_PATH."images/alerts.gif\" 
                alt=\""._m("Alerts")."\">";
        else if ($GLOBALS["g_modules"][$slice_id]["type"] == "S") {
            $slice_info = GetSliceInfo ($slice_id);
            if ($slice_info ["type"] == "ReaderManagement")
                $title_img = "<img border=0 src=\"".$AA_INSTAL_PATH."images/readers.gif\"
                 alt=\""._m("Reader management")."\">";
        }
            
        echo "
        <TD colspan=2>
        <TABLE border=0 cellpadding=0 cellspacing=0 width='100%'>
            <TR><TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=122 height=1></TD>
                <TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=300 height=1></TD>
                <TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=67 height=1></TD>
                <TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width='99%' height=1></TD>
            </TR>
            <TR><TD rowspan=2 align=center class=nblogo>$nb_logo</td>
                <TD height=43 colspan=3 align=center valign=middle class=slicehead>\n";

        if ($title_img) 
            echo "<table><tr><td>$title_img&nbsp;</td>
                <td width=\"0%\" class=slicehead>";
        echo $smmenus[$activeMain]["title"]."  -  $r_slice_headline";
        if ($title_img)
            echo "</td><td>&nbsp;$title_img</td></tr></table>";
        echo "
        </TD>
            </TR>
            <form name=nbform enctype=\"multipart/form-data\" method=post
                action=\"". $sess->url($PHP_SELF) ."\">            
            <TR><td align=center class=navbar>";
                            
        $first = true;
        reset ($smmenus);
        while (list ($aamenu,$aamenuprop) = each ($smmenus)) {
            if ($aamenuprop["level"] == "main") {
                if ($first) $first = false;
                else echo " | ";
                if (!isset ($aamenuprop["cond"])) $aamenuprop["cond"] = 1;
                if ($aamenu == $activeMain)
                    echo "<span class=nbactive>$aamenuprop[label]</span>\n";
                else if ($slice_id && $aamenuprop["cond"]) {
                     $href = $aamenuprop["exact_href"];
                     if (!$href) $href = get_aa_url($aamenuprop["href"]);
                     $href = con_url ($href, "slice_id=$slice_id");
                     echo "<a href=\"$href\">"
                         ."<span class=nbenable>$aamenuprop[label]</span></a>\n";
                }
                else echo "<span class=nbdisable>$aamenuprop[label]</span>\n";
            }
        }
        
        echo "</td><TD class=navbar align=right><span class=nbdisable> &nbsp;";
        if( is_array($g_modules) AND (count($g_modules) > 1) ) 
            echo _m("Switch to:") ."&nbsp; ";
        echo "</span></TD>
        <TD class=navbar>\n";
        PrintModuleSelection();
        echo "</TD></TR></form></TABLE>
        </TD></TR><TR>";
    }
    
    if ($showSub) {
        $submenu = $smmenus[$activeMain]["submenu"];
        if ($submenu) {
            echo "<TD valign=top>";
            showSubmenu ($smmenus[$submenu], $activeSubmenu);
            echo "</TD>";
        }
    }
    echo "
        <TD align=left valign=top width='99%'>
        <TABLE border=0 cellspacing=0 cellpadding=10 width='100%'><TR><TD align=left>\n";
}   

// ----------------------------------------------------------------------------------------
//                                SHOW SUBMENU

function showSubmenu (&$aamenu, $active)
{
    global $AA_INSTAL_PATH, $slice_id,$debug;
    if ($debug) { echo "<p><font color=purple>showSubmenu:active=$active</font></p>\n"; }
    echo '<table width="122" border="0" cellspacing="0" bgcolor="'.COLOR_TABBG.'" cellpadding="1" align="LEFT" class="leftmenu">'."\n";

    $aamenuitems = $aamenu["items"];
    reset ($aamenuitems);
    while (list ($itemshow, $item) = each ($aamenuitems)) {
        if (substr($itemshow,0,4) == "text") 
            echo "<tr><td>$item</td></tr>\n";
        if (substr($itemshow,0,6) == "header") 
            echo '<tr><td>&nbsp;</td></tr>
                  <tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>
                  <tr><td class=leftmenu>'.$item.'</td></tr>
                  <tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>'."\n";
        else if (substr($itemshow,0,4) == "line")
            echo '<tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>'."\n";
        else {
            echo '<tr><td width="122" valign="TOP">&nbsp;';
            if (!isset ($item["cond"])) $item["cond"] = 1;
            if ($itemshow == $active)
                echo "<span class=leftmenua>".$item["label"]."</span>\n";
            else if (($slice_id || $item["show_always"]) && $item["cond"]) {
                if ($item["exact_href"]) $href = $item["exact_href"]; 
                else $href = get_aa_url($item["href"]);
                if ($slice_id && !$item["no_slice_id"]) 
                    $href = con_url ($href, "slice_id=$slice_id");
                echo '<a href="'.$href.'" class=leftmenuy>'.$item["label"]."</a>\n";
            }  
            else echo "<span class=leftmenun>".$item["label"]."</span>\n";
            echo "</td></tr>\n";
        }
    }
  
    echo '<tr><td class=leftmenu>&nbsp;</td></tr>
          <tr><td class=leftmenu height="'.$aamenu["bottom_td"].'">&nbsp;</td></tr>
          <tr><td class=copymsg ><small>'. _m("Copyright (C) 2001 the <a href=\"http://www.apc.org\">Association for Progressive Communications (APC)</a>") .'</small></td></tr>
          </table>'."\n";
}
