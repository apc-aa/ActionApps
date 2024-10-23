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

use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/tabledit.php";
require_once __DIR__ . "/../include/tv_common.php";

try {
    $cancel = InputVars::boolPOST('cancel');
    $Msg    = InputVars::stringGETPOST('Msg');
} catch (Exception) {}
// ----------------------------------------------------------------------------------------

/** tv_field_value function
 * @param $feed_id
 * @param $param
 * @param $var
 * @return string
 */
function tv_field_value($feed_id,$param,$var) {
    return "+'&$param='+encodeURIComponent(document.tv_aarsstest.elements['val[$feed_id][$var]'].value)";
}
/** showFeedActions function
 * @param $feed_id
 * @return string - set of links
 * @noinspection PhpUnused
 */
function showFeedActions($feed_id) {
    $url = "'".AA_INSTAL_URL ."admin/xmlclient.php?feed_id=$feed_id'".
               tv_field_value($feed_id,'fill','fire').
               tv_field_value($feed_id,'time','newest_item').
               tv_field_value($feed_id,'debugfeed','debug');
    $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if 'write' checkbox is checked\">"._m('feed')."</a>&nbsp;";
    $out .= "<a href=\"javascript:OpenWindowTop('https://validator.w3.org/feed/check.cgi?url='+encodeURIComponent($url+'&display=1'))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
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
    MsgPage(_m("You have not permissions to this page"));
    exit;
}

$apage = new AA_Adminpageutil('aaadmin','aarsstest');
$apage->setTitle(_m("ActionApps RSS Content Exchange"));
$apage->setSubtitle(_m("RSS feeds testing page."));
$apage->setForm();
$apage->addRequire(get_aa_url('tabledit.css', ['v'=>AA_JS_VERSION], false ));
$apage->addRequire(get_aa_url('javascript/js_lib.min.js', ['v'=>AA_JS_VERSION], false ));  // for OpenWindowTop
$apage->printHead($err, $Msg);


ProcessFormData('GetAARSS_tv', $val, $cmd);
$tabledit = new tabledit('aarsstest', StateUrl("aarsstest.php"), $cmd, $aarss_tv, AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view($where);
if ($err) echo "<b>$err</b>";

// @todo - rewrite tabledit to Manager (better to do it on configured AAs)

//    $actions   = new AA\Manageractions;
//    // $actions->addAction(new  AA\Manageraction\View_Move2slice(   'Move2slice',    $module_id));
//
//    //$switches  = new AA\Manageractions;  // we do not need switches here
//
//    $manager_settings = AA::Metabase()->getManagerConf('external_feeds', $actions);
//    //$manager_settings['itemview']['format']['compact_top'] = '
//    //                                        <div class="aa-table">
//    //                                          <table>
//    //                                            <tbody><tr>
//    //                                              <th width="30">&nbsp;</th>
//    //                                              <th>'._m('Actions').'</th>
//    //                                              <th colspan="2">vid=</th>
//    //                                              <th>'._m('Name').'</th>
//    //                                              <th>'._m('Type').'</th>
//    //                                            </tr>';
//    //$manager_settings['itemview']['format']['odd_row_format'] = '
//    //                                      <tr><td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
//    //                                        <td>
//    //                                          <a href="./se_view.php?module_id=_#SLICE_ID&view_id=_#ID______" class="aa-button-edit">'. _m('Edit') .'</a>
//    //                                          <a href="'.AA_INSTAL_URL.'view.php?vid=_#ID______" class="aa-button-show" title="'. _m("show this view") .'">'. _m('Show') .'</a>
//    //                                          <a href="javascript:;" class="aa-button-delete" onclick="if (confirm(\''._m("Are you sure you want to delete selected view?").'\')) {window.location=\'./se_views.php?module_id=_#SLICE_ID&vid=_#ID______&del=1\';}">'. _m("Delete") .'</a>
//    //                                        </td>
//    //                                        <td><a href="./se_view.php?module_id=_#SLICE_ID&view_id=_#ID______">_#FIELD3__</a></td>
//    //                                        <td><a href="./se_view.php?module_id=_#SLICE_ID&view_id=_#ID______">_#ID______</a></td>
//    //                                        <td>_#NAME____</td>
//    //                                        <td>{ifeq:{_#TYPE____}'. $view_types_ifeq.'}</td>
//    //                                    </tr>';                      // <td>_#OBJECT__</td><td>_#EXECUTE_</td>
//    //
//    //$manager_settings['show'] = MGR_ALL & ~MGR_SB_BOOKMARKS & ~MGR_SB_SEARCHROWS & ~MGR_SB_ORDERROWS;    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
//    //$manager_settings['searchbar']['default_sort'] =  [0 => ['id' => 'd']];
//    //$manager_settings['actions'] =  $actions;
//
//    $manager = new AA\Manager('external_feeds', $manager_settings);
//    $manager->performActions();
//
//    $aa_set  = $manager->getSet();
//    // $aa_set->setModules($module_id);
//    $aa_set->addCondition(new AA_Condition('slice_id', '==', xpack_id($module_id)));
//
//    $zids    = AA::Metabase()->queryZids(['table'=>'external_feeds'], $aa_set);
//
//    //$manager->displayPage($zids, 'aaadmin', 'views');
//    $manager->display($zids);


$apage->printFoot();
