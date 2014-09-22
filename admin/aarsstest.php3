<?php
/**
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."tabledit.php3";
require_once AA_INC_PATH."tv_common.php3";
require_once menu_include();   //show navigation column depending on $show

// ----------------------------------------------------------------------------------------
/** TV_PageBegin function
 * @param array $config_arr
 * @return prints beginning of page
 */
function TV_PageBegin(&$config_arr) {
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
    return "+'&$param='+escape(document.tv_aarsstest.elements['val[$feed_id][$var]'].value)";
}
/** showFeedActions function
 * @param $feed_id
 * @return string - set of links
 */
function showFeedActions($feed_id) {
    $url = "'".AA_INSTAL_URL ."admin/xmlclient.php3?feed_id=$feed_id'".
               tv_field_value($feed_id,'fill','fire').
               tv_field_value($feed_id,'time','newest_item').
               tv_field_value($feed_id,'debugfeed','debug');
    $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if \"write\" checkbox is checked\">"._m('feed')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop('http://feedvalidator.org/check.cgi?url='+escape($url+'&display=1'))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop($url+'&display=1')\" title=\"displays the source data in new window\">"._m('show')."</a>";
    return $out;
}

$sess->register("tview");
$tview = 'aarss_tv';


/// this must be function
/** GetAARSS_tv function
 * @param $viewID
 * @param $processForm
 * @return array
 */
function GetAARSS_tv($viewID, $processForm = false) {

    $debug_params = array( 0 => '0 - none', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9 - maximum');
    return array (
        "table" => "external_feeds",
        "join"    => array (
            "nodes" => array (
                "joinfields" => array (
                    "node_name" => "name"),
                "jointype" => "1 to 1")),
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
        "messages"  => array (
            "no_item"  => _m("No ActionApps RSS Exchange is set.")),
        "buttons_down" => array( 'update_all'=> false, 'delete_all' => false ),
        "buttons_left" => array( 'edit'=> false, 'delete_checkbox' => false ),
        "fields"       => array (
            "feed_id"     => array (  // actions
                "view"    => array ( "type"=>"userdef", "function" => 'showFeedActions', "html" => true ),
                "caption" => _m('Actions')),
            "newest_item" => array (
                "view"    => array ("type" => 'text', "size" => array("cols"=>15)),
                "caption" => _m('Newest Item'),
                "hint"    => _m('change this value if you want to get older items')),
            "debug"       => array (
                "view"    => array ( "type"=>"select", "source"=>$debug_params ),
                "caption" => _m('Messages'),
                "table"   => 'aa_notable',
                "default" => 4),
            "fire"        => array (
                "view"    => array ( "type"=>"checkbox" ),
                "caption" => _m('Write'),
                "hint"    => _m('update database'),
                "table"   => 'aa_notable',
                "default" => 1),
            "node_name"   => array (
                "view"    => array ("readonly" => true),
                "caption" => _m('Node')),
            "remote_slice_name" => array (
                "view"    => array ("readonly" => true),
                "caption" => _m('Remote slice')),
            "remote_slice_id" => array (
                "view"    => array ("readonly" => true, "type"=>"userdef", "function" => 'unpack_id'),
                "caption" => _m('Remote slice ID')),
            "slice_id"    => array (
                "view"    => array ("readonly" => true, "type"=>"userdef", "function" => 'unpack_id'),
                "caption" => _m('Local slice ID')),
            "feed_mode"   => array (
                "view"    => array ("readonly" => true),
                "caption" => _m('Feed mode')),
            "_server_url_" => array (
                "table"   => "nodes",
                "field"   => "server_url",
                "view"    => array ("readonly" => true),
                "caption" => _m("Feed url")),
            "_password_"  => array (
                "table"   => "nodes",
                "field"   => "password",
                "view"    => array ("readonly" => true),
                "caption" => _m("Password")),
            "user_id"     => array(
                "view"    => array ("readonly" => true),
                "caption" => _m('User'))
        ));
}

$aarss_tv = GetAARSS_tv('aarss_tv');

TV_PageBegin($aarss_tv);
FrmJavascriptFile('javascript/js_lib.js');
ProcessFormData('GetAARSS_tv', $val, $cmd);

PrintArray($Err);
echo $Msg;

$script = $sess->url("aarsstest.php3");

$tabledit = new tabledit('aarsstest', $script, $cmd, $aarss_tv, AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view($where);
if ($err) echo "<b>$err</b>";

HTMLPageEnd();
page_close();

?>
