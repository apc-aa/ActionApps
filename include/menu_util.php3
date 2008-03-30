<?php
/** This script shows the top menu (navigation bar) and second level menu (left bar)
 *   by the function showMenu ($aamenus, $activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1).
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// ----------------------------------------------------------------------------------------
/* creates a JavaScript variable modulesOptions, which allows to create another Module selectbox
    without reprinting all the options */
/** PrintModuleSelection function
 *
 */
function PrintModuleSelection() {
    global $slice_id, $g_modules, $sess, $db, $MODULES;

    if ( is_array($g_modules) AND (count($g_modules) > 1) ) {

        // create the modulesOptions content:
        $permitted = GetUserSlices();
        if ($permitted != "all") {
            $slice_ids = "'". implode("','", array_map( 'q_pack_id', array_keys($permitted))) . "'";
            $slice_ids = "WHERE module.id IN ($slice_ids) ";
        }

        $js = "
            var modulesOptions = ''\n";
        $db->query("SELECT module.id, module.type, slice.type AS slice_type, module.name
                      FROM module LEFT JOIN slice ON module.id=slice.id $slice_ids
                      ORDER BY module.priority, module.name");

        $module_types = array (
            "Alerts" => array (5, _m("Alerts")),
            "J" => array (6, _m("Jump inside control panel")),
            "Links" => array (7, _m("Links")),
            "A" => array (8, _m("MySQL Auth (old version)")),
            "P" => array (9, _m("Polls")),
            "W" => array (10,_m("Site")),
            "S" => array (0, _m("Slice")),
            "RM"=> array (1, _m("Reader Management Slice")));

        while ($db->next_record()) {
            if ($db->f("type") == "S") {
                $order = $db->f("slice_type") == "ReaderManagement" ? 1 : 0;
            } else {
                $order = $module_types[$db->f("type")][0];
            }
            //if (! $module_types[$db->f("type")]) { echo $db->f("type")."!!"; exit; }
            $modules[$order][$db->f("id")] = $db->f("name");
        }
        // count($modules) - count of module types
        $display_modtypes = ( count($modules) > 1 ); // display types in selectbox?

        $option_begin = "\t+'<option value=\"";
        ksort ($modules);
        foreach ($modules as $order => $mods) {
            if ( $display_modtypes ) {
                foreach ($module_types as $module_type) {
                    if ($module_type[0] == $order) {
                        $js .= $option_begin . '" class="sel_title">*** '.$module_type[1]." ***'\n";
                    }
                }
            }
            //        asort ($mods);
            foreach ($mods as $id => $name) {
                $js .= $option_begin . htmlspecialchars(unpack_id($id))."\"";
                if ($slice_id == unpack_id($id)) {
                    $js .= " selected";
                }
                $js .= ">". str_replace("'","`",safe($name)) . "'\n";
            }
        }

        if ( !$slice_id ) {   // new slice
            $js .= "\t+'<option value=\"new\" selected>". _m("New slice") + "'";
        }
        $js .= ";
        document.write('<select name=\"slice_id\" onChange=\\'if (this.options[this.selectedIndex].value != \"\") document.location=\"" .con_url($sess->url($_SERVER['PHP_SELF']),"change_id=")."\"+this.options[this.selectedIndex].value\\'>');
        document.write(modulesOptions);
        document.write('</select>');\n";
        FrmJavascriptCached($js, 'modules');
    } else {
        echo "&nbsp;";
    }
}

// ----------------------------------------------------------------------------------------
//                                SHOW MENU

/** showMenu function
 * @param $smmenus -- array with menu information, see menu.php3 for an example
 * @param $activeMain -- selected item in main (top) menu
 * @param $activeSubmenu -- selected item in sub (left) menu
 * @param $showMain -- show the main menu (top navigation bar) ?
 * @param $showSub -- show the submenu (left navigation bar) ?
 */
function showMenu($smmenus, $activeMain, $activeSubmenu = "", $showMain = true, $showSub = true) {
    global $slice_id, $useOnLoad, $sess, $db, $auth;
    global $menu_function;
    global $debug;
    trace("+","showMenu",$smmenus);
    //huhsess("Session Variables");
    // load the main AA menu (see menu.php3)
    if ($smmenus == "aamenus") {
        // @todo
        // Menu functions are defined in include/menu.php3 or modules/*/menu.php3
        // We need to call last defined menu function (when switching to another
        // module). This solution is not so nice, but it removes get_aamenus()
        // redeclaration error. We probably change menus to object in the future
        $smmenus = $menu_function();
    }

    // HACKISH: aaadmin menu needs always the _news_ lang file, even in other than slice modules
    if ($activeMain == "aaadmin") {
        bind_mgettext_domain(AA_INC_PATH."lang/".get_mgettext_lang()."_news_lang.php3");
    }

    $nb_logo = '<a href="'. AA_INSTAL_PATH .'">'. GetAAImage('action.gif', aa_version(), 106, 73). '</a>';

    echo '
<body'. ($useOnLoad ? ' OnLoad="InitPage()"' : ''). ' bgcolor="'. COLOR_BACKGROUND .'">
  <table border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
';
    if ($showMain) {
        // Show the Alerts and Reader management images in the header
        switch ( $GLOBALS["g_modules"][$slice_id]["type"] ) {
            case 'Alerts':
                $title_img = a_href( AA_INSTAL_PATH. 'doc/reader.html', GetAAImage('alerts.gif', _m('Alerts'), 62, 36));
                break;
            case 'S':
                if (AA_Slices::getSliceProperty($slice_id, 'type') == "ReaderManagement") {
                    $title_img = a_href(AA_INSTAL_PATH. 'doc/reader.html', GetAAImage('readers.gif', _m('Reader management'), 28, 40));
                }
                break;
        }
        if (!$title_img) {
            $title_img = GetAAImage('spacer.gif', '', 28, 36);
        }
        echo '
        <td colspan="2">
          <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td width="1%"><img src="'. AA_INSTAL_PATH. 'images/spacer.gif" width="122" height="1"></td>
              <td><img src="'. AA_INSTAL_PATH. 'images/spacer.gif" height="1"></td>
              <td><img src="'. AA_INSTAL_PATH. 'images/spacer.gif" height="1"></td>
            </tr>
            <tr>
              <td width="1%" rowspan="2" align="center" class="nblogo">'.$nb_logo.'</td>
              <td height="43" align="center" valign="middle" class="slicehead">'.
                  $title_img .'&nbsp;'. $smmenus[$activeMain]['title']. "  -  ".
                  ($slice_id ? AA_Slices::getName($slice_id) : _m("New slice")).
              '</td>
              <td width="20%" align="right" class="navbar">
                <form name="logoutform" method="post" action="'. get_admin_url('logout.php3').'">';
        echo GetMenuLink('userinfo' == $activeMain, $auth->auth['uname'], IfSlPerm(PS_EDIT_SELF_USER_DATA), 'admin/um_passwd.php3', false, $slice_id);
        echo '
                   <input type="submit" name="logout" value="'._m('logout').'">&nbsp;
                </form>
              </td>
            </tr>
            <tr>
              <td align="center" class="navbar">
        ';
        $delim = '';

        foreach ($smmenus as $aamenu =>$aamenuprop) {
            if ($aamenuprop["level"] == "main") {
                $link  = GetMenuLink($aamenu == $activeMain,
                                     $aamenuprop['label'],
                                     isset($aamenuprop["cond"]) ? $aamenuprop["cond"] : true,
                                     $aamenuprop["href"],
                                     $aamenuprop["exact_href"],
                                     $slice_id);

                if ($link) {
                    echo $delim. $link;
                    $delim = ' | ';
                }
            }
        }


        echo '
              </td>
              <td width="20%" class="navbar" valign="bottom">
                <form name="nbform" enctype="multipart/form-data" method="post" action="'. $sess->url($_SERVER['PHP_SELF']) .'" style="display:inline">
                &nbsp; ';
        echo "\n";
        PrintModuleSelection();
        echo '
                </form>
              </td>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
      ';
    }

    if ($showSub) {
        $submenu = $smmenus[$activeMain]["submenu"];
        if ($submenu) {
            echo "<td valign=\"top\">";
            showSubmenu($smmenus[$submenu], $activeSubmenu);
            echo "</td>";
        }
    }
    echo '
        <td align="left" valign="top" width="99%">
          <table border="0" cellspacing="0" cellpadding="10" width="100%">
            <tr>
              <td align="left">
              ';
    trace("-");
}
/** GetMenuLink
 * @param $active
 * @param $label
 * @param $cond
 * @param $aa_href
 * @param $exact_href
 * @param $slice_id
 */
function GetMenuLink($active, $label, $cond, $aa_href, $exact_href, $slice_id) {

    $cssclass = $active ? 'nbactive' : 'nbenable';
//    if ($active) {
//        return "<span class=\"nbactive\">$label</span>\n";
//    }
    if ($slice_id AND $cond) {
        $href = $exact_href;
        if (!$href) {
            $href = get_aa_url($aa_href);
        }
        $href = con_url($href, "slice_id=$slice_id");
        return a_href($href, "<span class=\"$cssclass\">$label</span>");
    }
    // maked invisible which is better, I think. Honza 2007-08-02
    // return "<span class=\"nbdisable\">$label</span>";
    return '';
}

/** showSubMenuRows function
 * @param $aamenuitems
 * @param $active
 */
function showSubMenuRows( $aamenuitems, $active ) {
    global $slice_id,$debug;

    if ( !isset($aamenuitems) OR !is_array($aamenuitems) ) {
       return;
    }

    foreach ($aamenuitems as $itemshow => $item) {
        if (substr($itemshow,0,4) == "text") {
            echo "<tr><td>$item</td></tr>\n";
        } elseif (substr($itemshow,0,6) == "header") {
            echo '<tr><td>&nbsp;</td></tr>
                  <tr><td><img src="'.AA_INSTAL_PATH.'images/black.gif" width="120" height="1"></td></tr>
                  <tr><td class="leftmenu">'.$item.'</td></tr>
                  <tr><td><img src="'.AA_INSTAL_PATH.'images/black.gif" width="120" height="1"></td></tr>'."\n";
        } elseif (substr($itemshow,0,4) == "line") {
            echo '<tr><td><img src="'.AA_INSTAL_PATH.'images/black.gif" width="120" height="1"></td></tr>'."\n";
        } elseif ( $item["function"] ) {
            // call some function to get menu items
            // it is better mainly for left submenus for which we need
            // database access - if we use function, it is called only if
            // submenu should be displayed.
            $function = $item["function"];
            showSubMenuRows( $function( $item["func_param"] ), $active );
        } else {
            echo '<tr><td width="122" valign="TOP">&nbsp;';
            if (!isset ($item["cond"])) {
                $item["cond"] = 1;
            }
            $cssclass = ($itemshow == $active) ? 'leftmenua' : 'leftmenuy';
//            if ($itemshow == $active) {
//                echo "<span class=\"leftmenua\">".$item["label"]."</span>\n";
//            } elseif (($slice_id || $item["show_always"]) && $item["cond"]) {
            if (($slice_id || $item["show_always"]) && $item["cond"]) {
                $href = ($item["exact_href"] ? $item["exact_href"] : get_aa_url($item["href"]));
                if ($slice_id && !$item["no_slice_id"]) {
                    $href = con_url($href, "slice_id=$slice_id");
                }
                if ($item['js']) {
                    $item['js'] = str_replace("{href}",$href,$item['js']);
                    $item['js'] = str_replace("{exact_href}",$href,$item['js']);
                    $href       = "javascript:".$item['js'];
                }
                echo '<a href="'.$href.'" class='.$cssclass.'>'.$item["label"]."</a>\n";
            } elseif ( !$item["hide"] ) {
                echo "<span class=\"leftmenun\">".$item["label"]."</span>\n";
            }
            echo "</td></tr>\n";
        }
    }
}

// ----------------------------------------------------------------------------------------
//                                SHOW SUBMENU
/** showSubmenu function
 * @param $aamenu
 * @param $active
 */
function showSubmenu(&$aamenu, $active) {
    global $debug;
    if ($debug) { echo "<p><font color=\"purple\">showSubmenu:active=$active</font></p>\n"; }
    echo '<table width="122" border="0" cellspacing="0" bgcolor="'.COLOR_TABBG.'" cellpadding="1" align="left" class="leftmenu">'."\n";

    $aamenuitems = $aamenu["items"];
    showSubMenuRows( $aamenuitems, $active );

    echo '<tr><td class="leftmenu">&nbsp;</td></tr>
          <tr><td class="leftmenu" height="'.$aamenu["bottom_td"].'">&nbsp;</td></tr>
          <tr><td class="copymsg"><small>'. _m("Copyright (C) 2001 the <a href=\"http://www.apc.org\">Association for Progressive Communications (APC)</a>") .'</small></td></tr>
          </table>'."\n";
}
/** CreateMenuItem function
 * @param $label
 * @param $href
 * @param $cond
 */
function CreateMenuItem( $label, $href, $cond = true ) {
    return array('label' => $label, 'href' => $href, 'cond' => $cond);
}
?>
