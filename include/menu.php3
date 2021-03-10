<?php
/**
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// $slice_id - should be defined
// $r_slice_view_url - should be defined
// $editor_page or $usermng_page or $settings_page - should be defined
// $g_modules - should be defined

/*  Top level (navigation bar) menu description:
    label       to be shown
    cond        if not satisfied, don't show the label linked
                slice_id is included in the cond automatically
    href        link, relative to aa/
    exact_href  link, absolute (use either exact_href or href, not both)
*/

use AA\IO\DB\DB_AA;

require_once __DIR__."/menu_util.php3";
require_once __DIR__."/perm_core.php3";
require_once __DIR__."/mgettext.php3";

// I don't want to call AA menus as early as including menu.php3, because some permissions' functions are called. Hence I call get_aamenus in showMenu().

/** get_aamenus function
 *
 */
function get_aamenus() {
    global $auth,
           $slice_id,
           $r_state,
           $bookmarks;

    $profile = AA_Profile::getProfile($auth->auth["uid"], $slice_id); // current user settings
    $aamenus = [];

    $aamenus["view"] = [
        "label"       => GetLabel($profile,'ui_manager','top_view', _m("View site")),
        "exact_href"  => AA_Slice::getModuleProperty($slice_id,'slice_url'),
        "cond"        => ($profile->getProperty('ui_manager', 'top_view') !== ''),
        "level"       => "main"
    ];

    $input_view = (isset($profile) AND $profile->getProperty('input_view')) ?
                  '&vid='.$profile->getProperty('input_view') : '';

    $aamenus["additem"] = [
        "label" => GetLabel($profile,'ui_manager','top_additem', _m("Add Item")),
        "href"  => "admin/itemedit.php3?add=1$input_view",
        "cond"  => ($profile->getProperty('ui_manager', 'top_additem') !== ''),
        "level" => "main"
    ];

    $aamenus["itemmanager"] = [
        "label"   => get_if($profile->getProperty('ui_manager', 'top_itemmanager'), _m("Item Manager")),
        "title"   => _m("Item Manager"),
        "href"    => "admin/index.php3?Tab=app",
        "cond"  => ($profile->getProperty('ui_manager', 'top_itemmanager') !== '') OR IsSuperadmin(),
        "level"   => "main",
        "submenu" => "itemmanager_submenu"
    ];

    $aamenus["sliceadmin"] = [
        "label"   => get_if($profile->getProperty('ui_manager', 'top_sliceadmin'), _m("Slice Admin")),
        "title"   => _m("Slice Administration"),
        "href"    => "admin/se_fields.php3",
        "cond"    => (IfSlPerm(PS_FIELDS) AND ($profile->getProperty('ui_manager', 'top_sliceadmin') !== '')) OR IsSuperadmin(),
        "level"   => "main",
        "submenu" => "sliceadmin_submenu"
    ];

    /** Second-level (left) menu description:
     *  bottom_td       empty space under the menu
     *  items           array of menu items in form item_id => properties
     *                  if item_id is "headerxxx", shows a header,
     *                      be careful that xxx be always a different number
     *                  if item_id is "line", shows a line
     *      label       to be shown
     *      cond        if not satisfied, don't show the label linked
     *                  slice_id is included in the cond automatically
     *      href        link, relative to aa/
     *      exact_href  link, absolute (use either exact_href or href, not both)
     *      js          javascript function to call after click on link
     *                  you can use following aliases as function parameters:
     *                  {href} - alias for href (link, relative to aa/)
     *                  {exact_href} - alias for exact_href (link, absolute)
     *      show_always don't include slice_id in cond
     *      no_slice_id don't add slice_id to the URL
     */

    $aamenus ["sliceadmin_submenu"] = [
        "bottom_td" => 50,
        "level"     => "submenu",

        "items"     => [

        "header1"       => _m("Main settings"),
        "main"          => ["cond"=>IfSlPerm(PS_EDIT),     "href"=>"admin/slicedit.php3",                 "label"=>_m("Slice"), "show_always"=>1], //"href"=>"admin/tabledit.php3?set_tview=sl_edit&cmd[sl_edit][edit][".$slice_id."]=1&slice_id=".$slice_id
        "fields"        => ["cond"=>IfSlPerm(PS_FIELDS),   "href"=>"admin/se_fields.php3",                "label"=>_m("Fields")],
        "slice_fields"  => ["cond"=>IfSlPerm(PS_FIELDS),   "href"=>"admin/se_fields.php3?slice_fields=1", "label"=>_m("Slice Fields")],
        "notify"        => ["cond"=>IfSlPerm(PS_EDIT),     "href"=>"admin/se_notify.php3",                "label"=>_m("Email Notification")],
        //"te_emails"   => array("cond"=>IfSlPerm(PS_FULLTEXT), "href"=>"admin/tabledit.php3?set_tview=email", "label"=>_m("Emails")),

        "header2"       => _m("Permissions"),
        "addusers"      => ["cond"=>IfSlPerm(PS_ADD_USER), "href"=>"admin/se_users.php3?adduser=1",       "label"=>_m("Assign")],
        "users"         => ["cond"=>IfSlPerm(PS_USERS),    "href"=>"admin/se_users.php3",                 "label"=>_m("Change")],

        "header3"       => _m("Design"),
        "compact"       => ["cond"=>IfSlPerm(PS_COMPACT),  "href"=>"admin/se_compact.php3",               "label"=>_m("Index")],
        "fulltext"      => ["cond"=>IfSlPerm(PS_FULLTEXT), "href"=>"admin/se_fulltext.php3",              "label"=>_m("Fulltext")],
        "views"         => ["cond"=>IfSlPerm(PS_FULLTEXT), "href"=>"admin/se_views.php3",                 "label"=>_m("Views")],
        "forms"         => ["cond"=>IfSlPerm(PS_FULLTEXT), "href"=>"admin/se_forms.php3",                 "label"=>_m("Forms")],
        "config"        => ["cond"=>IfSlPerm(PS_CONFIG),   "href"=>"admin/se_admin.php3",                 "label"=>_m("Item Manager")],
        "sets"          => ["cond"=>IfSlPerm(PS_FULLTEXT), "href"=>"admin/se_sets.php3",                  "label"=>_m("Sets of Items")],

        "header4"       => _m("Content Pooling"),
        "nodes"         => ["cond"=>IsSuperadmin(),        "href"=>"admin/se_nodes.php3",                 "label"=>_m("Nodes")],
        "import"        => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_import.php3",                "label"=>_m("Inner Node Feeding")],
        "n_import"      => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_inter_import.php3",          "label"=>_m("Inter Node Import")],
        "n_export"      => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_inter_export.php3",          "label"=>_m("Inter Node Export")],
        "rssfeeds"      => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_rssfeeds.php3",              "label"=>_m("RSS Feeds")],
        "filters"       => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_filters.php3",               "label"=>_m("Filters")],
        "mapping"       => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_mapping.php3",               "label"=>_m("Mapping")],
        "CSVimport"     => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_csv_import.php3",            "label"=>_m("Import CSV")],
        "export"        => ["cond"=>IfSlPerm(PS_FEEDING),  "href"=>"admin/se_export.php",                 "label"=>_m("Export to file")],

        "header5"       => _m("Misc"),
        "history"       => ["cond"=>IfSlPerm(PS_HISTORY),   "href"=>"admin/se_history.php3",              "label"=>_m("History")],
        "field_ids"     => ["cond"=>IfSlPerm(PS_FIELDS),   "href"=>"admin/se_fieldid.php3",               "label"=>_m("Change field IDs")],
        "javascript"    => ["cond"=>IfSlPerm(PS_FIELDS),   "href"=>"admin/se_javascript.php3",            "label"=>_m("Field Triggers")],
        "fileman"       => ["cond"=>FilemanPerms($slice_id), "href"=>"admin/fileman.php3",                "label"=>_m("File Manager")],
        "anonym_wizard" => ["cond"=>IfSlPerm(PS_FIELDS),   "href"=>"admin/anonym_wizard.php3",            "label"=>_m("Anonymous Form Wizard")],
        "email"         => ["cond"=>IfSlPerm(PS_USERS),    "href"=>"admin/tabledit.php3?set_tview=email", "label"=>_m("Email templates")],
        "taskmanager"   => ["cond"=>IfSlPerm(PS_EDIT),     "href"=>"admin/se_taskmanager.php3",           "label"=>_m("Task Manager")],
        "tasks"         => ["cond"=>IsSuperadmin(),        "href"=>"admin/se_tasks.php3",                 "label"=>_m("Planned Tasks")],
        ]
    ];

    $slice = AA_Slice::getModule($slice_id);
    if ( $slice AND $slice->getProperty("mailman_field_lists")) {
        $aamenus ["sliceadmin_submenu"]["items"]["mailman_create_list"] = [
            "cond"  => IfSlPerm(PS_FIELDS),
            "href"  => "admin/mailman_create_list.php3",
            "label" => _m("Mailman: create list")
        ];
    }

    $aamenus["itemmanager_submenu"] = [
        "bottom_td" => 200,
        "level"     => "submenu",
        "items"     => [
            "header1"     => GetLabel($profile,'ui_manager', 'itemmanager_submenu_header1', _m("Folders")),
            "additem"     => ["cond"=> $profile->getProperty('ui_manager', 'itemmanager_submenu_additem'), 'hide' => !$profile->getProperty('ui_manager', 'itemmanager_submenu_additem'),         "label" => $profile->getProperty('ui_manager', 'itemmanager_submenu_additem'), "href"  => "admin/itemedit.php3?add=1$input_view"],
            "app"         => ["cond"=> ($profile->getProperty('ui_manager', 'itemmanager_submenu_app')   !== ''), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_app')   === ''),                           "href"=>"admin/index.php3?Tab1a=1",                                "label"=>GetLabel($profile,'ui_manager', 'itemmanager_submenu_app'  , "<img src='../images/ok.gif' border=0>"._m("Active")." (". $r_state['bin_cnt']['app'] .")")],
            "appb"        => ["cond"=> ($profile->getProperty('ui_manager', 'itemmanager_submenu_appb')  !== ''), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_appb')  === ''),                           "href"=>"admin/index.php3?Tab1b=1",                                "label"=>GetLabel($profile,'ui_manager', 'itemmanager_submenu_appb' , _m("... pending")." (". $r_state['bin_cnt']['pending'] .")"), "show"=>true],
            "appc"        => ["cond"=> ($profile->getProperty('ui_manager', 'itemmanager_submenu_appc')  !== ''), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_appc')  === ''),                           "href"=>"admin/index.php3?Tab1c=1",                                "label"=>GetLabel($profile,'ui_manager', 'itemmanager_submenu_appc' , _m("... expired")." (". $r_state['bin_cnt']['expired'] .")"), "show"=>true],
            "hold"        => ["cond"=> ($profile->getProperty('ui_manager', 'itemmanager_submenu_hold')  !== ''), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_hold')  === ''),                           "href"=>"admin/index.php3?Tab2=1",                                 "label"=>GetLabel($profile,'ui_manager', 'itemmanager_submenu_hold' , "<img src='../images/edit.gif' border=0>"._m("Hold bin")." (". $r_state['bin_cnt']['folder2'] .")")],
            "trash"       => ["cond"=> ($profile->getProperty('ui_manager', 'itemmanager_submenu_trash') !== ''), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_trash') === ''),                           "href"=>"admin/index.php3?Tab3=1",                                 "label"=>GetLabel($profile,'ui_manager', 'itemmanager_submenu_trash', "<img src='../images/delete.gif' border=0>"._m("Trash bin")." (". $r_state['bin_cnt']['folder3'] .")")]
        ]
    ];

    if ( $profile->getProperty('ui_manager', 'itemmanager_submenu_bookmarks') !== false ) {
        $aamenus["itemmanager_submenu"]['items']['headerbookmarks'] = GetLabel($profile,'ui_manager', 'itemmanager_submenu_bookmarks', _m("Bookmarks"));

        foreach ( (array) $bookmarks as $bookid => $bookname ) {
            $aamenus["itemmanager_submenu"]['items']['bookmark'.$bookid] = ["href"=> "admin/index.php3?GoBookmark=$bookid", "label"=>$bookname];
        }
    }

    $aamenus["itemmanager_submenu"]['items'] += [
            "header2"     => GetLabel($profile,'ui_manager', 'itemmanager_submenu_header2', _m("Misc")),
            "slice_fld"   => ["cond"=>(IfSlPerm(PS_EDIT_ALL_ITEMS) AND ($profile->getProperty('ui_manager', 'itemmanager_submenu_slice_fld') !== '')), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_slice_fld')   === ''), "href"=>"admin/slicefieldsedit.php3?edit=1&encap=false&id=$slice_id",   "label"=>GetLabel($profile,'ui_manager','itemmanager_submenu_slice_fld', _m("Setting"))],
            "empty_trash" => ["cond"=>(IfSlPerm(PS_DELETE_ITEMS) AND ($profile->getProperty('ui_manager', 'itemmanager_submenu_empty_trash') !== '')), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_empty_trash')   === ''), "href"=>"admin/index.php3?DeleteTrash=1",                             "label"=>GetLabel($profile,'ui_manager','itemmanager_submenu_empty_trash', "<img src='../images/empty_trash.gif' border=0>"._m("Empty trash")), "js"=>"EmptyTrashQuestion('{href}','"._m("Are You sure to empty trash?")."')"],
            "CSVimport"   => ["cond"=>(IfSlPerm(PS_EDIT_ALL_ITEMS) AND ($profile->getProperty('ui_manager', 'itemmanager_submenu_CSVimport') !== '')), 'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_CSVimport')   === ''), "href"=>"admin/se_csv_import.php3",                                     "label"=>GetLabel($profile,'ui_manager','itemmanager_submenu_CSVimport', _m("Import CSV"))],
            "debug"       => ["cond"=>(IsSuperadmin() AND ($profile->getProperty('ui_manager', 'itemmanager_submenu_debug')   !== '')),                'hide' => ($profile->getProperty('ui_manager', 'itemmanager_submenu_debug')   === '') AND !IsSuperadmin(),                  "js"  =>"ToggleCookie('aa_debug','1')","label"=>GetLabel($profile,'ui_manager','itemmanager_submenu_debug',  ($_COOKIE['aa_debug'] ? _m("Set Debug OFF") : _m("Set Debug ON")))]
    //        "line"        => ""
    ];

    if ($slice_id && IfSlPerm(PS_EDIT_ALL_ITEMS)) {

        $items = &$aamenus["itemmanager_submenu"]["items"];

        // Add associated Alerts to Item Manager submenu
        if ($slice->getProperty("type") == "ReaderManagement" ) {
            $modules2link = DB_AA::select([], 'SELECT module_id as id, module.name as name FROM alerts_collection AC
                INNER JOIN module ON AC.module_id = module.id', [['slice_id', $slice_id, 'l']]);
            AddAlertsModules($items, $modules2link, _m("Alerts"), _m("List of Alerts modules using this slice as Reader Management."));

            $items["header4"] = _m("Bulk Emails") ."&nbsp;&nbsp;&nbsp;".GetAAImage("help50.gif", _m("Send bulk email to selected users or to users in Stored searches"), 16, 12);
            $items["item1"]   = [
                "cond" => 1,
                                    "exact_href" => "javascript:WriteEmailGo()",
                                    "label" => _m("Send emails"),
                                    "no_slice_id"=>1
            ];
        }
        $modules2link = DB_AA::select([], 'SELECT DISTINCT AC.module_id as id, module.name as name FROM alerts_collection AC
            INNER JOIN module ON AC.module_id = module.id
            INNER JOIN alerts_collection_filter ACF ON AC.id = ACF.collectionid
            INNER JOIN alerts_filter AF ON AF.id = ACF.filterid
            INNER JOIN view ON view.id = AF.vid', [['view.slice_id', $slice_id, 'l']]);
        AddAlertsModules($items, $modules2link, _m("Alerts Sent"), _m("List of Alerts modules sending items from this slice."));
    }

    // left menu for aaadmin is common to all modules, so it is shared
    return array_merge($aamenus, GetCommonMenu($profile));
}

/** AddAlertsModules function
 * @param $submenu
 * @param $db
 * @param $header
 * @param $help
 */
function AddAlertsModules(&$submenu, $collections, $header, $help) {
    global $auth;
    if (count($collections)) {
        $submenu["header3"] = $header."&nbsp;&nbsp;&nbsp;". GetAAImage('help50.gif', $help, 16, 12);
        $i = 100;
        foreach ($collections as $col) {
            $submenu["item".$i] = [
                "cond"        => CheckPerms( $auth->auth["uid"], "slice", unpack_id($col['id']), PS_FIELDS),
                "href"        => "modules/alerts/index.php3?module_id=".unpack_id($col['id']),
                "no_slice_id" => 1,
                "label"       => $col['name']
            ];
            $i++;
        }
    }
}


