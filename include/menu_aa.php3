<?php
/**
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// defines the left submenu for menu AA Admin (AA)

global $sess;
$aamenus["aaadmin_submenu"] = array (
    "bottom_td" => 300,
    "level"     => "submenu",
    "items"     => array (

    "header0"     => _m("Slices / Modules"),
    "sliceadd"    => array("label"=>_m("Create new"),      "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceadd.php3"),
    "slicewiz"    => array("label"=>_m("Create new Wizard"), "cond"=>IfSlPerm(PS_ADD),    "href"=>"admin/slicewiz.php3"),
    "slicedel"    => array("label"=>_m("Delete"),          "cond"=>IsSuperadmin(),        "href"=>"admin/slicedel.php3"),
    "jumpedit"    => array("label"=>_m("Edit Jump"),       "cond"=>IfSlPerm(PS_ADD),      "exact_href" =>
                   $sess->url(AA_INSTAL_PATH."modules/jump/modedit.php3?edit=1")),
/*    "delete" => array ("label" => _m("Empty trash"), "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php3?Delete=trash"),*/

    "header1"     =>_m("Users"),
    "u_edit"      => array("label"=>_m("Edit User"),       "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_uedit.php3" ),
    "u_new"       => array("label"=>_m("New User"),        "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_uedit.php3?usr_new=1"),

    "header2"     =>_m("Groups"),
    "g_edit"      => array("label"=>_m("Edit Group"),      "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_gedit.php3", "cond"=>1),
    "g_new"       => array("label"=>_m("New Group"),       "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_gedit.php3?grp_new=1", "cond"=>1),

    "header5"     =>_m("Slice structure"),
    "sliceexp"    => array("label"=>_m("Export"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceexp.php3"),
    "sliceimp"    => array("label"=>_m("Import"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceimp.php3"),

    "header7"     =>_m("Wizard"),
    "te_wizard_welcome" => array("label"=>_m("Welcomes"),  "cond"=>IsSuperadmin(),        "href"=>"admin/tabledit.php3?set_tview=email"),
    "te_wizard_template"=> array("label"=>_m("Templates"), "cond"=>IsSuperadmin(),        "href"=>"admin/tabledit.php3?set_tview=wt"),

    "header8"     =>_m("Feeds"),
    "rsstest"     => array("label"=>_m("RSS test"),        "cond"=>IsSuperadmin(),        "href"=>"admin/rsstest.php3"),
    "aarsstest"   => array("label"=>_m("AA RSS test"),     "cond"=>IsSuperadmin(),        "href"=>"admin/aarsstest.php3"),
    "testrss"     => array("label"=>_m("Run feeding"),     "cond"=>IsSuperadmin(),        "href"=>"admin/xmlclient.php3?debugfeed=4"),

    "header9"     =>_m("Misc"),
    "te_cron"     => array("label"=>_m("Cron"),            "cond"=>IsSuperadmin(),        "href"=>"admin/tabledit.php3?set_tview=cron"),
    "log"         => array("label"=>_m("View Log"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_log.php3"),
    "searchlog"   => array("label"=>_m("View SearchLog"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_searchlog.php3"),
    "aafinder"    => array("label"=>_m("AA finder"),       "cond"=>IsSuperadmin(),        "href"=>"admin/aafinder.php3"),
    "xmgettext"   => array("label"=>_m("Mgettext"),        "cond"=>IsSuperadmin(),        "exact_href"=>"../misc/mgettext/index.php3"),
    'optimize'    => array("label"=>_m("Optimize"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_optimize.php3"),
    "summarize"   => array("label"=>_m("Summarize"),       "cond"=>IsSuperadmin(),        "href"=>"admin/summarize.php3"),
    "synchronize" => array("label"=>_m("Synchronize AA"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_synchronize.php3"),
    "history"     => array("label"=>_m("History"),         "cond"=>IfSlPerm(PS_HISTORY),  "href"=>"admin/se_history.php3")
//    "oneoff" => array("label"=>_m("One Off Code"), "cond"=>IsSuperadmin(), "href"=>"admin/oneoff.php3"),
//    "console" => array("label"=>_m("Console"), "cond"=>IsSuperadmin(), "href"=>"admin/console.php3"),
));

?>