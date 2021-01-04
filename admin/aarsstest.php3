<?php
/**
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/tabledit.php3";
require_once __DIR__."/../include/tv_common.php3";

// ----------------------------------------------------------------------------------------

/** tv_field_value function
 * @param $feed_id
 * @param $param
 * @param $var
 * @return string
 */
function tv_field_value($feed_id,$param,$var) {
    return "+'&$param='+escape(document.tv_aarsstest.elements['val[$feed_id][$var]'].value)";
}
/** showFeedActions function
 * @param $feed_id
 * @return string - set of links
 * @noinspection PhpUnused
 */
function showFeedActions($feed_id) {
    $url = "'".AA_INSTAL_URL ."admin/xmlclient.php3?feed_id=$feed_id'".
               tv_field_value($feed_id,'fill','fire').
               tv_field_value($feed_id,'time','newest_item').
               tv_field_value($feed_id,'debugfeed','debug');
    $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if 'write' checkbox is checked\">"._m('feed')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop('https://validator.w3.org/feed/check.cgi?url='+escape($url+'&display=1'))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop($url+'&display=1')\" title=\"displays the source data in new window\">"._m('show')."</a>";
    return $out;
}

$sess->register("tview");
$tview = 'aarss_tv';


/// this must be function - used in ProcessFormData()
/** GetAARSS_tv function
 * @return array
 */
function GetAARSS_tv() {

    $debug_params = [0 => '0 - none', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9 - maximum'];
    return [
        "table" => "external_feeds",
        "join"    => [
            "nodes" => [
                "joinfields" => [
                    "node_name" => "name"
                ],
                "jointype" => "1 to 1"
            ]
        ],
        "type"      => "browse",
        "search"    => false,
        "mainmenu"  => "aaadmin",
        "submenu"   => "aarsstest",
        "readonly"  => false,
        "addrecord" => false,
        "listlen"   => 50,
        "cond"      => IsSuperadmin(),
        "attrs"     => $GLOBALS['attrs_browse'],
        "title"     => _m ("ActionApps RSS Content Exchange"),
        "caption"   => _m("ActionApps RSS Content Exchange"),
        "help"      => _m("RSS feeds testing page."),
        "messages"  => [
            "no_item"  => _m("No ActionApps RSS Exchange is set.")
        ],
        "buttons_down" => ['update_all'=> false, 'delete_all' => false],
        "buttons_left" => ['edit'=> false, 'delete_checkbox' => false],
        "fields"       => [
            "feed_id"     => [  // actions
                "view"    => ["type"=>"userdef", "function" => 'showFeedActions', "html" => true],
                "caption" => _m('Actions')
            ],
            "newest_item" => [
                "view"    => ["type" => 'text', "size" => ["cols"=>15]],
                "caption" => _m('Newest Item'),
                "hint"    => _m('change this value if you want to get older items')
            ],
            "debug"       => [
                "view"    => ["type"=>"select", "source"=>$debug_params],
                "caption" => _m('Messages'),
                "table"   => 'aa_notable',
                "default" => 4
            ],
            "fire"        => [
                "view"    => ["type"=>"checkbox"],
                "caption" => _m('Write'),
                "hint"    => _m('update database'),
                "table"   => 'aa_notable',
                "default" => 1
            ],
            "node_name"   => [
                "view"    => ["readonly" => true],
                "caption" => _m('Node')
            ],
            "remote_slice_name" => [
                "view"    => ["readonly" => true],
                "caption" => _m('Remote slice')
            ],
            "remote_slice_id" => [
                "view"    => ["readonly" => true, "type"=>"userdef", "function" => 'unpack_id'],
                "caption" => _m('Remote slice ID')
            ],
            "slice_id"    => [
                "view"    => ["readonly" => true, "type"=>"userdef", "function" => 'unpack_id'],
                "caption" => _m('Local slice ID')
            ],
            "feed_mode"   => [
                "view"    => ["readonly" => true],
                "caption" => _m('Feed mode')
            ],
            "_server_url_" => [
                "table"   => "nodes",
                "field"   => "server_url",
                "view"    => ["readonly" => true],
                "caption" => _m("Feed url")
            ],
            "_password_"  => [
                "table"   => "nodes",
                "field"   => "password",
                "view"    => ["readonly" => true],
                "caption" => _m("Password")
            ],
            "user_id"     => [
                "view"    => ["readonly" => true],
                "caption" => _m('User')
            ]
        ]
    ];
}

$aarss_tv = GetAARSS_tv();

if (!$aarss_tv["cond"]) {
    MsgPage(StateUrl(self_base() . "index.php3"), _m("You have not permissions to this page"));
    exit;
}

$apage = new AA_Adminpageutil('aaadmin','aarsstest');
$apage->setTitle(_m("ActionApps RSS Content Exchange"));
$apage->setSubtitle(_m("RSS feeds testing page."));
$apage->setForm();
$apage->addRequire(get_aa_url('tabledit.css?v='.AA_JS_VERSION, '', false ));
$apage->printHead($err, $Msg);


ProcessFormData('GetAARSS_tv', $val, $cmd);


$tabledit = new tabledit('aarsstest', StateUrl("aarsstest.php3"), $cmd, $aarss_tv, AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view($where);
if ($err) echo "<b>$err</b>";

$apage->printFoot();
