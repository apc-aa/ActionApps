<?php
/** PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."tabledit.php3";
require_once AA_INC_PATH."tv_common.php3";
require_once menu_include();   //show navigation column depending on $show

// ----------------------------------------------------------------------------------------
/** TV_PageBegin function
 * @param $config_arr (by link)
 * @return prints html page beginning
 */
function TV_PageBegin(&$config_arr) {
    global $sess;
    if (! $config_arr["cond"] ) {
        MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to this page"));
        exit;
    }

    HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
    echo '<link rel="StyleSheet" href="'.AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">';
    echo "<title>".$config_arr["title"]."</title></head>";
    showMenu($GLOBALS['aamenus'], $config_arr["mainmenu"], $config_arr["submenu"]);
    echo "<h1><b>" . $config_arr["caption"] . "</b></h1>";
}
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
 * @return set of html links
 */
function showRSSFeedActions($feed_id) {
    $url = "'".get_admin_url('xmlclient.php3'). "&rssfeed_id=$feed_id'".
               tv_field_value($feed_id,'fill','fire').
               tv_field_value($feed_id,'server_url','server_url').
               tv_field_value($feed_id,'debugfeed','debug');
    $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if \"write\" checkbox is checked\">"._m('feed')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop('http://feedvalidator.org/check.cgi?url='+escape(document.tv_rsstest.elements['val[$feed_id][server_url]'].value))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop(document.tv_rsstest.elements['val[$feed_id][server_url]'].value)\" title=\"displays the source data in new window\">"._m('show')."</a>";
    return $out;
}
/** displaySliceName function
 * @param $slice_id
 * @return result of jumpLink() function of the slice class
 */
function displaySliceName($slice_id) {
    $slice = AA_Slice::getModule(unpack_id($slice_id));
    return $slice->jumpLink();
}

$sess->register("tview");
$tview = 'rss_tv';

/** GetRSS_tv function
 * @param $viewID
 * @param $processForm = false
 * @return array
 */
/// this must be function
function GetRSS_tv($viewID, $processForm = false) {

    $debug_params = array( 0 => '0 - none', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9 - maximum');
    return array (
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
        "messages" => array (
            "no_item" => _m("No RSS Feeds set.")),
        "buttons_down" => array( 'update_all'=> false, 'delete_all' => false ),
        "buttons_left" => array( 'edit'=> false, 'delete_checkbox' => false ),
        "fields" => array (
            "feed_id" => array (  // actions
                "view" => array ( "type"=>"userdef", "function" => 'showRSSFeedActions', "html" => true ),
                "caption" => _m('Actions')),
            "debug" => array (
                "view" => array ( "type"=>"select", "source"=>$debug_params ),
                "caption" => _m('Messages'),
                "table" => 'aa_notable',
                "default" => 4),
            "fire" => array (
                "view" => array ( "type"=>"checkbox" ),
                "caption" => _m('Write'),
                "hint" => _m('update database'),
                "table" => 'aa_notable',
                "default" => 1),
            "server_url" => array (
                "view" => array ("type" => 'text'),
                "caption" => _m("Feed url")),
            "name" => array (
                "view" => array ("readonly" => true),
                "caption" => _m('Node')),
            "slice_id" => array (
                "view" => array ( "type"=>"userdef", "function" => 'displaySliceName', "html" => true ),
                "caption" => _m('Local slice'))
        ));
}

$rss_tv = GetRSS_tv('rss_tv');

TV_PageBegin($rss_tv);
FrmJavascriptFile('javascript/js_lib.js');
ProcessFormData('GetRSS_tv', $val, $cmd);

PrintArray($Err);
echo $Msg;

$script = $sess->url("rsstest.php3");

$tabledit = new tabledit ('rsstest', $script, $cmd, $rss_tv, AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view($where);
if ($err) {
    echo "<b>$err</b>";
}

HTMLPageEnd();
page_close ();


?>
