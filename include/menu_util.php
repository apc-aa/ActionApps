<?php

use AA\IO\DB\DB_AA;

/** This script shows the top menu (navigation bar) and second level menu (left bar)
 *   by the function showMenu ($activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1).
 *
 *
 * PHP version 7.2+
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
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 * @package   Include
 * @version   $Id$
 */


/**
 * @param AA_Profile $profile
 * @return array
 */
function GetCommonMenu(AA_Profile $profile): array {
    global $r_state;

    $menu = [];

    $menu["aaadmin"] = [
        "label"   => GetLabel($profile,'ui_manager','top_aaadmin', _m("AA")),
        "title"   => _m("AA Administration"),
        "href"    => "admin/aafinder.php",
        "cond"    => IsSuperadmin() AND ($profile->getProperty('ui_manager', 'top_aaadmin') !== ''),
        "level"   => "main",
        "submenu" => "aaadmin_submenu"
    ];

    $menu["aaadmin_submenu"] = [
        "bottom_td" => 300,
        "level"     => "submenu",
        "items"     => [

        "header0"     => _m("Slices / Modules"),
        "sliceadd"    => ["label"=>_m("Create new"),      "cond"=>IfSlPerm(PS_ADD),      'ico'=>'ico-new-slice.png', "href"=>"admin/sliceadd.php"],
        //"slicewiz"    => ["label"=>_m("Create new Wizard"), "cond"=>IfSlPerm(PS_ADD),    "href"=>"admin/slicewiz.php"],
        "slicedel"    => ["label"=>_m("Delete"),          "cond"=>IsSuperadmin(),        "href"=>"admin/slicedel.php"],
        "jumpedit"    => [
            "label"=>_m("Edit Jump"),       "cond"=>IfSlPerm(PS_ADD),      "exact_href" =>
                       StateUrl(AA_INSTAL_PATH."modules/jump/modedit.php?edit=1")
        ],
    /*    "delete" => array ("label" => _m("Empty trash"), "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php3?Delete=trash"),*/

        "header1"     => ['label'=>_m("Users"), 'ico'=>'ico-user.png'],
        "u_edit"      => ["label"=>_m("Edit User"),       "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_uedit.php"],
        "u_new"       => ["label"=>_m("New User"),        "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_uedit.php?usr_new=1"],

        "header2"     => ['label'=>_m("Groups"), 'ico'=>'ico-users.png'],
        "g_edit"      => ["label"=>_m("Edit Group"),      "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_gedit.php"],
        "g_new"       => ["label"=>_m("New Group"),       "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_gedit.php?grp_new=1"],

        "header5"     =>_m("Slice structure"),
        "sliceexp"    => ["label"=>_m("Export"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceexp.php"],
        "sliceimp"    => ["label"=>_m("Import"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceimp.php"],

        "header7"     =>_m("Wizard"),
        //"te_wizard_welcome2"  => ["label"=>_m("Welcomes"),  "cond"=>IsSuperadmin(),        "href"=>"admin/tabledit.php?set_tview=email"],
        "te_wizard_welcome" => ["label"=>_m("Welcomes"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_emails.php"],
        "te_wizard_template"=> ["label"=>_m("Templates"), "cond"=>IsSuperadmin(),        "href"=>"admin/aa_wizardtemplate.php"],

        "header8"     =>_m("Feeds"),
        "rsstest"     => ["label"=>_m("RSS test"),        "cond"=>IsSuperadmin(),        "href"=>"admin/rsstest.php"],
        "aarsstest"   => ["label"=>_m("AA RSS test"),     "cond"=>IsSuperadmin(),        "href"=>"admin/aarsstest.php"],
        "testrss"     => ["label"=>_m("Run feeding"),     "cond"=>IsSuperadmin(),        "href"=>"admin/xmlclient.php3?debugfeed=4"],

        "header9"     =>_m("Misc"),
        "cron"     => ["label"=>_m("Cron"),            "cond"=>IsSuperadmin(),        "href"=>"admin/aa_cron.php"],
        "toexecute"   => ["label"=>_m("Task Manager"),    "cond"=>IsSuperadmin(),        "href"=>"admin/aa_toexecute.php"],
        "tasks"       => ["label"=>_m("Planned Tasks"),   "cond"=>IsSuperadmin(),        "href"=>"admin/aa_tasks.php"],
        "log"         => ["label"=>_m("View Log"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_log.php?srchbr_akce=1&srchbr_field%5B0%5D=time&srchbr_oper%5B0%5D=%3E&srchbr_value%5B0%5D=".(time()-8*24*3600)."&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on"],
        "searchlog"   => ["label"=>_m("View SearchLog"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_searchlog.php"],
        "aafinder"    => ["label"=>_m("AA finder"),       "cond"=>IsSuperadmin(),        "href"=>"admin/aafinder.php"],
        "xmgettext"   => ["label"=>_m("Mgettext"),        "cond"=>IsSuperadmin(),        "exact_href"=>"../misc/mgettext/index.php3"],
        'optimize'    => ["label"=>_m("Optimize"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_optimize.php"],
        "history"     => ["label"=>_m("History"),         "cond"=>IfSlPerm(PS_HISTORY),  "href"=>"admin/se_history.php"],
        "console"     => ["label" =>_m("Console"),        "cond" =>IsSuperadmin(),       "href" =>"admin/console.php"],

        "header10"     =>_m("Documentation"),
        "styleguide"  => ["label" =>_m("Style Guide"),    "cond" =>IsSuperadmin(),       "href" =>"doc/style-guide.html"],
    //    "oneoff" => array("label"=>_m("One Off Code"), "cond"=>IsSuperadmin(), "href"=>"admin/oneoff.php3"),
        ]
    ];

    $menu["central"] = [
        "label"   => GetLabel($profile,'ui_manager','top_central', _m("Central")),
        "title"   => _m("AA Central"),
        "href"    => "central/index.php3",
        "cond"    => IsSuperadmin() AND ($profile->getProperty('ui_manager', 'top_central') !== ''),
        "level"   => "main",
        "submenu" => "central_submenu"
    ];

    $menu["central_submenu"] = [
        "bottom_td" => 200,
        "level"     => "submenu",
        "items"     => [
            "header1"     => _m("Folders"),
            "app"         => ["cond"=>1,                           "href"=>"central/index.php3?Tab1=1",                                'ico'=>'ico-items.png', "label"=> _m("Active")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder1'] ."</span>"],
            "hold"        => ["cond"=>1,                           "href"=>"central/index.php3?Tab2=1",                                'ico'=>'ico-holdbin.png', "label"=>_m("Hold bin")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder2'] ."</span>"],
            "trash"       => ["cond"=>1,                           "href"=>"central/index.php3?Tab3=1",                                'ico'=>'ico-trashbin.png', "label"=>_m("Trash bin")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder3'] ."</span>"],

            "header2"     => _m("Misc"),
            "synchronize" => ["cond"=>IsSuperadmin(),              "href"=>"central/synchronize.php",                                    "label"=>_m("Synchronize...")],
            "copyslice"   => ["cond"=>IsSuperadmin(),              "href"=>"central/copyslice.php",                                      "label"=>_m("Copy Slice...")],
            "item6"       => ["cond"=>IsSuperadmin(),              "href"=>"central/index.php3?DeleteTrash=1",                           "label"=>_m("Empty trash"), "js"=>"EmptyTrashQuestion('{href}','"._m("Are You sure to empty trash?")."')"],
            "importcsv"   => ["cond"=>IsSuperadmin(),              "href"=>"central/import_csv.php",                                     "label"=>_m("Import conf from CSV...")],
        ]
    ];

    return $menu;
}


/**
 * @param AA_Profile $profile
 * @param string     $property
 * @param string     $selector
 * @param string     $default_text
 * @param string     $ico
 * @return string
 */
function GetLabel(AA_Profile $profile, string $property, string $selector, ?string $default_text, string $ico=''): string {
    $val =  $profile->getProperty($property, $selector);
    $ret =  $ico ? GetAAImage($ico,'',23,23) : '';
    $ret .= ($val === false) ? $default_text : $val;
    return $ret;
}

// ----------------------------------------------------------------------------------------
/* creates a JavaScript variable modulesOptions, which allows to create another Module selectbox
    without reprinting all the options */
/** PrintModuleSelection function
 *
 */
function GetModuleSelection(): string {
    global $g_modules;
    $ret = '';

    $profile = AA_Profile::getProfile(AuthGetUid(), AA::$module_id); // current user settings

    if ( is_array($g_modules) AND (count($g_modules) > 1) AND ($profile->getProperty('ui_manager', 'top_moduleselection') === false)) {
        // create the modulesOptions content:
        $permitted_slices = GetUserSlices();
        $modules_cond = ($permitted_slices=='all') ? [] : [['module.id', array_keys($permitted_slices), 'l']];

        $mymodules =  DB_AA::select([], 'SELECT LOWER(HEX(module.id)) AS unpackid, module.type, module.deleted, module.name, slice.type AS slice_type FROM `module` LEFT JOIN `slice` ON module.id=slice.id', $modules_cond, ['module.deleted','module.priority','module.name']);

        $module_types = [
            "Alerts" => [5, _m("Alerts")],
            "J" => [6, _m("Jump inside control panel")],
            "Links" => [7, _m("Links")],
            "A" => [8, _m("MySQL Auth (old version)")],
            "P" => [9, _m("Polls")],
            "W" => [10,_m("Site")],
            "S" => [0, _m("Slice")],
            "RM"=> [1, _m("Reader Management Slice")]
        ];

        // do not print deleted modules when you ase not admin of the slice or superadmin
        if($permitted_slices != 'all') {
            // huhl($_SESSION, AuthGetUid(), $GLOBALS['permission_uid'], AA::$perm->getUserPerms($GLOBALS['permission_uid'], "slice"), AA_Perm::ROLES, $GLOBALS['permission_to']);
            $mymodules = array_filter($mymodules, function ($slice) {
                // huhl($slice['name'], $slice['deleted'],  $slice['unpackid'], IfSlPerm(PS_FIELDS, $slice['unpackid']));
                return (!$slice['deleted'] OR IfSlPerm(PS_FIELDS, $slice['unpackid']));
            });
        }

        $modules = [];
        foreach ($mymodules as $mymodule) {
            if (($order = $module_types[$mymodule['type']][0]) === 0) {
                $order = $mymodule['slice_type'] == "ReaderManagement" ? 1 : 0;
            }
            $name = str_replace("'","`",safe($mymodule['name']));
            if ( $mymodule['deleted'] ) {
                $name = "&times;&times;&times; $name &times;&times;&times;";
            }
           // $modules[$order][$mymodule['unpackid']] = ($mymodule['deleted'] ? '&times;&times;&times; '.$mymodule['name']. ' &times;&times;&times;' : $mymodule['name']);
           $modules[$order][$mymodule['unpackid']] = $name;;
        }

        // count($modules) - count of module types
        $display_modtypes = ( count($modules) > 1 ); // display types in selectbox?

        $mydir = AA_Module::getModuleInfo('url', AA::$module_id);

        $options      = '';
        ksort ($modules);
        foreach ($modules as $order => $mods) {
            if ( $display_modtypes ) {
                foreach ($module_types as $module_type) {
                    if ($module_type[0] == $order) {
                        $options .= '<optgroup label="'.$module_type[1].'">';   // &#x1f464;&#x1f468; men symbol
                    }
                }
            }
            //        asort ($mods);
            foreach ($mods as $id => $name) {
                $mod_dir = AA_Module::getModuleInfo('url',$id);
                $val = (($mydir == $mod_dir) ? '' : AA_INSTAL_PATH.$mod_dir). "?module_id=$id";
                $options .= '<option value="' . $val .'"';
                if (AA::$module_id == $id) {
                    $options .= ' selected';
                }
                $options .= '>'. $name;
            }
        }

        if ( !AA::$module_id ) {   // new slice
            $options .= '<option value="new" selected>'. _m("New slice");
        }

        //$type = AA_Module::getModuleType(AA::$module_id);

        $html  = GetLabel($profile, 'ui_manager', 'top_moduleswitchtext', '');
        $html .= '<select name=sel_module onChange="if (this.value != \'\') window.location=this.value">';
        $html .= $options;
        $html .= '</select>';

        $js = "document.getElementById('aanbform').innerHTML = '".str_replace("'", "\\'", $html)."';";
        $ret .= getFrmJavascriptCached($js, 'modules');
    } else {
        $ret .= GetLabel($profile, 'ui_manager', 'top_moduleselection', "&nbsp;");
    }
    return $ret;
}

// ----------------------------------------------------------------------------------------
//                                SHOW MENU

/**
 * @param        $activeMain    -- selected item in main (top) menu
 * @param string $activeSubmenu -- selected item in sub (left) menu
 * @return string
 */
function getMenu($activeMain, $activeSubmenu = ""): string {
    global $slice_id;
    $ret = '';

    if ($activeMain) {

        $profile = AA_Profile::getProfile(AuthGetUid(), $slice_id); // current user settings

        // load the main AA menu (see menu.php)

        if ($menu_require = AA_Module::getModuleInfo('menu', AA::$module_id)) {

            if (!function_exists('get_aamenus')) {
                require_once __DIR__."/../" . $menu_require;
            }
            // @todo
            // Menu functions are defined in include/menu.php or modules/*/menu.php
            // We need to call last defined menu function (when switching to another
            // module). This solution is not so nice, but it removes get_aamenus()
            // redeclaration error. We probably change menus to object in the future
            $smmenus = get_aamenus();
        }

        // HACKISH: aaadmin menu needs always the _news_ lang file, even in other than slice modules
        if ($activeMain == "aaadmin") {
            mgettext_bind(\AA\Util\Langs::currentLang(), 'news');
        }

        $nb_logo = GetLabel($profile, 'ui_manager', 'top_logo', '<a href="'. AA_INSTAL_PATH .'">'. GetAAImage('action.gif', aa_version(), 106, 73). '</a>');

        // --- showMain menu -----
        // Show the Alerts and Reader management images in the header
        $title_img = '';
        switch ( $GLOBALS["g_modules"][$slice_id]["type"] ) {
            case 'Alerts':
                $title_img = a_href(AA_INSTAL_PATH . 'doc/reader.html', GetAAImage('alerts.gif', _m('Alerts'), 28, 40));
                break;
            case 'S':
                if (AA_Slice::getModuleProperty($slice_id,'type') == "ReaderManagement") {
                    $title_img = a_href(AA_INSTAL_PATH . 'doc/reader.html', GetAAImage('readers.gif', _m('Reader management'), 28, 40));
                }
                break;
        }

        $title_title = GetLabel($profile, 'ui_manager', 'top_title', $smmenus[$activeMain]['title']);
        $title_name  = safe($slice_id ? AA_Slice::getModuleName($slice_id) : _m("New slice"));
        $title_out   = $title_title . (($title_title AND $title_name) ? ' - ' : '') . $title_name. $title_img;
        $prop_logout = $profile->getProperty('ui_manager', 'top_logout');

        if ( $prop_logout === false ) {
            $logout_out = '<input type="submit" name="logout" value="'._m('logout').'">';
        } elseif ( $prop_logout === '' ) {
            $logout_out = '';
        } else {
            $logout_out = '<input type="submit" name="logout" value="'.$profile->getProperty('ui_manager', 'top_logout').'">';
        }

        $user_out = GetLabel($profile, 'ui_manager', 'top_userinfo', GetMenuLink('userinfo' == $activeMain, AuthGetUname(), IfSlPerm(PS_EDIT_SELF_USER_DATA), 'admin/um_passwd.php', '', '', 'aa-ui-userinfo'));

        // @todo change it to method=get (+ hidden fields or so) in order the Back button in browser works there better
        $search_out = IsSuperadmin() ? "<form name=aasearchform action=\"".get_admin_url('aafinder.php')."\"><input type=search name=search value=\"".safe($_REQUEST['findtext'])."\" placeholder=\"". _m('Search..') ."\"><input type=hidden name=searchinview value=1><input type=hidden name=searchinslice value=1><input type=hidden name=searchinfield value=1><input type=hidden name=searchinspot value=1><input type=hidden name=searchinobject value=1><input type=hidden name=searchincodeitems value=1><input type=hidden name=go_search value=1></form>"  : '';

        $ret .= '
          <div class="aa-ui-top noprint" id="aa_top">
            <div class="nblogo">'.$nb_logo.'</div>
            <div class="aa-ui-nav">
              <div class="flex f-between">
                <div class="slicehead">'. $title_out .'</div>
                <div class="aa-ui-custom-nav">'. $search_out .'
                  <form name=logoutform method="post" action="'. get_admin_url('logout.php').'">
                   '. $user_out .' '. $logout_out .'&nbsp;
                  </form>
                </div>
              </div>
              <div class="flex f-between">
                <nav class="navbar">
        ';

        $links = [];
        foreach ($smmenus as $aamenu =>$aamenuprop) {
            if ($aamenuprop["level"] == "main") {
                $links[$aamenu] = GetMenuLink($aamenu == $activeMain, $aamenuprop['label'], $aamenuprop['cond'] ?? true, $aamenuprop['href'], $aamenuprop['exact_href'], $aamenuprop['ico']);
            }
        }

        if ($viewlink = $links['view']) {
            unset($links['view']);
        }
        $ret .= join(' ',$links);

        $ret .= '  
                </nav>
                <div class="aa-ui-modules">'.$viewlink.'
                  <form name="aanbform" id="aanbform" enctype="multipart/form-data" method="post" action="'. StateUrl() .'">'."\n";
        $ret .= GetModuleSelection();
        $ret .= "\n</form>
                </div>
              </div>
            </div>
          </div>\n";

        // showMain menu end -----

        $ret .= "\n<div class='aa-ui-main'>";

    // showSub menu -----
        if ($submenu = $smmenus[$activeMain]["submenu"]) {
            $ret .= "<div class='aa-ui-leftmenu'>";
            $ret .= getSubmenu($smmenus[$submenu], $activeSubmenu);
            $ret .= "</div>";
        }
    } else {
        $ret .= "\n<div class='aa-ui-main'>";
    }

    $ret .= "\n <div class=\"aa-ui-content\">";
    return $ret;
}

/** GetMenuLink
 * @param        $active
 * @param        $label
 * @param        $cond
 * @param        $aa_href
 * @param string $exact_href
 * @param string $ico
 * @return string
 */
function GetMenuLink($active, $label, $cond, $aa_href, $exact_href='', $ico='', $add_css='') {

    $cssclass  = $active ? 'nbactive' : 'nbenable';
    $cssclass .= $add_css ? ' '.$add_css : '';

    if (AA::$module_id AND $cond) {
        $href = $exact_href;
        if (!$href) {
            $href = get_aa_url($aa_href);
        }
        if ($ico) {
            $label = GetAAImage($ico,$label,23,23).$label;
        }

        return a_href($href, $label, ['class'=>$cssclass]);
    }
    // maked invisible which is better, I think. Honza 2007-08-02
    // return "<span class=\"nbdisable\">$label</span>";
    return '';
}

/** showSubMenuRows function
 * @param $aamenuitems
 * @param $active
 */
function getSubMenuRows($aamenuitems, $active ): string {
    global $slice_id;
    if ( !isset($aamenuitems) OR !is_array($aamenuitems) ) {
       return '';
    }
    $ret = '';

    foreach ($aamenuitems as $itemshow => $item) {
        $ico = ((is_array($item) AND $item['ico']) ? GetAAImage($item['ico'], $item['title'], 23,23) : '');

        if (substr($itemshow,0,4) == "text") {
            $ret .= "<li class='aa-leftmenu-text aa-menuname-$itemshow'>$item</li>\n";
        } elseif (substr($itemshow,0,6) == "header") {
            if (is_array($item)) {
                if (!isset($item["cond"]) OR $item["cond"]) {
                    $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow' title='" . $item['title'] . "'>" . $item['label'] . "</li>\n";  // $ico not shown for design reasons
                }
            } elseif ($item !== '') {
                $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow'>$item</li>\n";
            }
        } elseif ( $item["function"] ) {
            // call some function to get menu items
            // it is better mainly for left submenus for which we need
            // database access - if we use function, it is called only if
            // submenu should be displayed.
            $function = $item["function"];
            $ret .= getSubMenuRows( $function($item["func_param"] ), $active );
        } else {
            if (!isset($item["cond"])) {
                $item["cond"] = 1;
            }
            $cssclass = ($itemshow == $active) ? 'leftmenua' : 'leftmenuy';
//            if ($itemshow == $active) {
//                $ret .= "<span class=\"leftmenua\">".$item["label"]."</span>\n";
//            } elseif (($slice_id || $item["show_always"]) && $item["cond"]) {
            if (($slice_id || $item["show_always"]) && $item["cond"]) {
                $href = ($item["exact_href"] ?: get_aa_url($item["href"],[],!$item["no_slice_id"]));
                if ($item['js']) {
                    $item['js'] = str_replace("{href}",$href,$item['js']);
                    $item['js'] = str_replace("{exact_href}",$href,$item['js']);
                    $href       = "javascript:".$item['js'];
                }
                $ret .= "<li class='aa-leftmenu-link aa-menuname-$itemshow'><a href=\"".$href.'" class='.$cssclass.'>'.$ico. $item["label"]."</a></li>\n";
            } elseif ( !$item["hide"] ) {
                // no need to show not active menu items 2023-02-15 HM
                // $ret .= '<li class="aa-leftmenu-link"><span class="leftmenun">'.$ico.$item["label"]."</span></li>\n";
            }
        }
    }
    return $ret;
}

// ----------------------------------------------------------------------------------------
//                                SHOW SUBMENU
/** showSubmenu function
 * @param $aamenu
 * @param $active
 */
function getSubmenu($aamenu, $active): string {
    $ret = '<div class="leftmenu noprint">'."\n";

    $aamenuitems = $aamenu["items"];

    if ($submenu = getSubMenuRows( $aamenuitems, $active )) {
        $ret .= "<ul>\n  $submenu\n</ul>";
    }

    $ret .= "</div>\n<div class='aa-ui-copyright'>". _m("Copyright (C) 2001 the <a href=\"https://www.apc.org\">Association for Progressive Communications (APC)</a>") ."</div>\n";
    return $ret;
}

/** CreateMenuItem function
 * @param $label
 * @param $href
 * @param $cond
 * @return array
 */
function CreateMenuItem( $label, $href, $cond = true ) {
    return ['label' => $label, 'href' => $href, 'cond' => $cond];
}
