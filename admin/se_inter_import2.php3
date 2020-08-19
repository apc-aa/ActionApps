<?php
/** se_inter_import2.php3 - Inter node feed import settings
 *
 *             $slice_id
 *   optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

require_once __DIR__."/../include/init_page.php3";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
    exit;
}

require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/xml_fetch.php3";
require_once __DIR__."/../include/xml_rssparse.php3";
require_once __DIR__."/../include/csn_util.php3";

$db->query("SELECT server_url, password FROM nodes WHERE name='$rem_nodes'");
if ($db->next_record()) {
    $server_url = $db->f('server_url');
    $password   = $db->f('password');
}

if (!($data = xml_fetch($server_url, ORG_NAME, $password, $auth->auth["uname"],"",0,""))) {
    MsgPage(StateUrl(self_base() . "se_inter_import.php3"), _m("Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.") );
}

// find out first character of fetched data: if it is not '<' exit
if (substr($data,0,1) != "<") {
    AA_Log::write("CSN", AA_Log::context(), "Establishing mode: $data");
    switch ($data) {
        case ERR_NO_SLICE : $err_msg = _m("No slices available. You have not permissions to import any data of that node. Contact the administrator of the remote slice and check, that he obtained your correct username."); break;
        case ERR_PASSWORD : $err_msg = _m("Invalid password for the node name:") . " ".ORG_NAME . ". "._m("Contact the administrator of the local node."); break;
        default:            $err_msg = _m("Remote server returns following error:") . " $data"; break;
    }
    MsgPage(StateUrl(self_base() . "se_inter_import.php3"), $err_msg); // $data contains error message
}                                                                   // from the server module

// try to parse xml document
if (!($aa_rss = aa_rss_parse($data))) {
    AA_Log::write("CSN", AA_Log::context(), "Establishing mode: Unable to parse XML data");
    MsgPage(StateUrl(self_base() . "se_inter_import.php3"), _m("Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.") );
}

foreach ($aa_rss['channels'] as $id => $foo) {
    $chan[$id] = $aa_rss['channels'][$id]['title'];
}

$err = [];          // error array (Init - just for initializing variable

$apage = new AA_Adminpageutil('sliceadmin','n_import');
$apage->setTitle(_m("Inter node import settings"));
$apage->setForm(['action'=>'se_inter_import3.php3']);
$apage->printHead($err, $Msg);

$form_buttons = [
    "new_in_feed"      => ['type'=>'submit', 'value'=>_m("Choose slice")],
                      "cancel"           => ['url'=>'se_inter_import.php3'],
                      "remote_node_name" => ['type' => 'hidden', 'value' => $rem_nodes],
                      "aa"               => ['type' => 'hidden', 'value' => myspecialchars(serialize($aa_rss))]
];

FrmTabCaption(_m("List of available slices from the node ") . "<b>$rem_nodes</b>", $form_buttons);
FrmInputMultiSelect('f_slices[]', _m('Slice to import'), $chan, '', 5, false, true);
FrmInputChBox('exact_copy', _m("Exact copy"), $exact_copy, false, '', 1, false,
           _m('The slice will be exact copy of the remote slice. All items will be copied including holdingbin and trash bin items. Also on anychange in the remote item, the content will be copied to local copy of the item. The items will have the same long ids (not the short ones!). It make no sence to change items in local copy - it will be overwriten from remote master.'));

FrmTabEnd($form_buttons);

$apage->printFoot();
