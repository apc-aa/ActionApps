<?php
/** PHP version 7.2+
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
 *
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
    return "+'&$param='+escape(document.tv_rsstest.elements['val[$feed_id][$var]'].value)";
}
/** showRSSFeedActions function
 * @param $feed_id
 * @return string - set of html links
 */
function showRSSFeedActions($feed_id) {
    $url = "'".get_admin_url('xmlclient.php3'). "&rssfeed_id=$feed_id'".
               tv_field_value($feed_id,'fill','fire').
               tv_field_value($feed_id,'server_url','server_url').
               tv_field_value($feed_id,'debugfeed','debug');
    $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if \"write\" checkbox is checked\">"._m('feed')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop('https://validator.w3.org/feed/check.cgi?url='+escape(document.tv_rsstest.elements['val[$feed_id][server_url]'].value))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop(document.tv_rsstest.elements['val[$feed_id][server_url]'].value)\" title=\"displays the source data in new window\">"._m('show')."</a>";
    return $out;
}
/** displaySliceName function
 * @param $slice_id
 * @return string
 */
function displaySliceName($slice_id) {
    $slice = AA_Slice::getModule(unpack_id($slice_id));
    return $slice->jumpLink();
}

$sess->register("tview");
$tview = 'rss_tv';

/** GetRSS_tv function
 * @return array
 */
/// this must be function
function GetRSS_tv() {

    $debug_params = [0 => '0 - none', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9 - maximum'];
    return [
        "table" => "rssfeeds",
        "type" => "browse",
        "search" => false,
        "mainmenu" => "aaadmin",
        "submenu" => "rsstest",
        "readonly" => false,
        "addrecord" => false,
        "listlen" => 50,
        "cond" => IsSuperadmin(),
        "attrs" => $GLOBALS['attrs_browse'],
        "title" => _m ("RSS Feed import test"),
        "caption" => _m("RSS Feed import test"),
        "help" => _m("RSS feeds testing page."),
        "messages" => [
            "no_item" => _m("No RSS Feeds set.")
        ],
        "buttons_down" => ['update_all'=> false, 'delete_all' => false],
        "buttons_left" => ['edit'=> false, 'delete_checkbox' => false],
        "fields" => [
            "feed_id" => [  // actions
                "view" => ["type"=>"userdef", "function" => 'showRSSFeedActions', "html" => true],
                "caption" => _m('Actions')
            ],
            "debug" => [
                "view" => ["type"=>"select", "source"=>$debug_params],
                "caption" => _m('Messages'),
                "table" => 'aa_notable',
                "default" => 4
            ],
            "fire" => [
                "view" => ["type"=>"checkbox"],
                "caption" => _m('Write'),
                "hint" => _m('update database'),
                "table" => 'aa_notable',
                "default" => 1
            ],
            "server_url" => [
                "view" => ["type" => 'text'],
                "caption" => _m("Feed url")
            ],
            "name" => [
                "view" => ["readonly" => true],
                "caption" => _m('Node')
            ],
            "slice_id" => [
                "view" => ["type"=>"userdef", "function" => 'displaySliceName', "html" => true],
                "caption" => _m('Local slice')
            ]
        ]
    ];
}

$rss_tv = GetRSS_tv();

if (!$rss_tv["cond"]) {
    MsgPage(StateUrl(self_base() . "index.php3"), _m("You have not permissions to this page"));
    exit;
}


$apage = new AA_Adminpageutil('aaadmin','rsstest');
$apage->setTitle(_m("RSS Feed import test"));
$apage->setForm();
$apage->addRequire(get_aa_url('tabledit.css?v='.AA_JS_VERSION, '', false ));
$apage->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false ));
$apage->printHead($err, $Msg);

ProcessFormData('GetRSS_tv', $val, $cmd);

$tabledit = new tabledit ('rsstest', StateUrl("rsstest.php3"), $cmd, $rss_tv, AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view($where);
if ($err) {
    echo "<b>$err</b>";
}

$apage->printFoot();
