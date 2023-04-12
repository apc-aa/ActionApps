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

// discedit2.php - admin discussion comments
// expected    $item_id for comment's item_id
//             $d_id
// optionaly   $update

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";

try {
    // array ( 'ddd' => 9, )
    $author = InputVars::stringPOST('author'); // alpha
    // array ( 'ffgggf' => 9, )
    $body = InputVars::stringPOST('body'); // alpha
    // array ( 1 => 2, )-array ( 'Storno' => 1, )
    $cancel = InputVars::boolGETPOST('cancel'); // -alpha
    // array ( '8bf7dd29548…' => 6, '5c9c0780a5c…' => 6, 'b660d6672f7…' => 3, '0ae33f40354…' => 15, )-array ( '8bf7dd29548…' => 2, '5c9c0780a5c…' => 1, '0ae33f40354…' => 6, )
    $d_id = InputVars::idGETPOST('d_id'); // -
    // array ( '' => 9, )
    $e_mail = InputVars::stringPOST('e_mail'); // empty
    // array ( '' => 9, )
    $free1 = InputVars::stringPOST('free1'); // empty
    // array ( 'array ( …' => 9, )
    $free2 = InputVars::stringPOST('free2');
    // array ( '653e6beff71…' => 21, )-array ( '653e6beff71…' => 5, 'e>k��B…' => 4, )
    $item_id = InputVars::idGETPOST('item_id'); // @todo - id
    // array ( '84.42.240.38' => 9, )
    $remote_addr = InputVars::stringPOST('remote_addr');
    // array ( 'ffedittt' => 2, 'ffdd' => 1, 'ffq' => 2, 'ffqQ' => 3, 'ffqQW' => 1, )
    $subject = InputVars::stringPOST('subject'); // alpha

    // $tdctr_date_* // not necessary

    $update = InputVars::boolPOST('update');
    // array ( 'http://' => 9, )
    $url_address = InputVars::stringPOST('url_address');
    // array ( '' => 9, )
    $url_description = InputVars::stringPOST('url_description'); // empty
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

$cancel_url = get_url(self_base() . "discedit.php", ['item_id'=>$item_id], true);
if ($cancel) {
    go_url(StateUrl(self_base() . "discedit.php?item_id=".$item_id));
}

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage(_m("You do not have permission to edit items in this slice"));
    exit;
}

require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/discussion.php";
require_once __DIR__ . "/../include/item.php";

$err = [];          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ($update) {
  //update discussion table
    ValidateInput("subject", _m("Subject"), $subject, $err, true, "text");
    ValidateInput("author", _m("Author"), $author, $err, true, "text");
    ValidateInput("e_mail", _m("E-mail"), $e_mail, $err, false, "text");
    ValidateInput("body", _m("Text of discussion comment"), $body, $err, false, "text");
    ValidateInput("url_address", _m("Authors's WWW  - URL"), $url_address, $err, false, "url");
    ValidateInput("url_description", _m("Authors's WWW - description"), $url_description, $err, false, "text");
    ValidateInput("remote_addr", _m("Remote address"), $remote_addr, $err, true, "text");
    ValidateInput("free1", _m("Free1"), $free1, $err, false, "text");
    ValidateInput("free2", _m("Tracking"), $free2, $err, false, "text");

    $datectrl = new datectrl('date');
    $datectrl->update();                   // updates datectrl
    $date     = $datectrl->get_date();


    if (!count($err)) {
        $varset->add("subject", "text", $subject);
        $varset->add("author", "text", $author);
        $varset->add("e_mail", "text", $e_mail);
        $varset->add("body", "text", $body);
        $varset->add("date", "text", $date);
        $varset->add("url_address", "text", $url_address);
        $varset->add("url_description", "text", $url_description);
        $varset->add("remote_addr", "text", $remote_addr);
        $varset->add("free1", "text", $free1);
        $varset->add("free2", "text", $free2);

        $SQL = "UPDATE discussion SET ". $varset->makeUPDATE() . " WHERE id='" .q_pack_id($d_id)."'";
        $db->query($SQL);

        AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        go_url(StateUrl(self_base() . "discedit.php?item_id=".$item_id));
    }
}

// set variables from table discussion
$SQL= " SELECT * FROM discussion WHERE id='".q_pack_id($d_id)."'";
$db->query($SQL);
if ($db->next_record()) {
    RestoreVariables($db->record());
}

$apage = new AA_Adminpageutil();
$apage->setTitle(_m("Items management - Discussion comments management - Edit comment"));
$apage->setForm(['action' => "?d_id=$d_id"]);
$apage->printHead($err, $Msg);

$form_buttons = [
    "update"  => ['type' => 'submit'],
    "cancel"  => ['url'  => $cancel_url],
    "d_id"    => ['type' => 'hidden', 'value'=>$d_id],
    "item_id" => ['type' => 'hidden', 'value'=> unpack_id($item_id)],
];

FrmTabCaption(_m("Edit comment"), $form_buttons);
    FrmStaticText("id", $d_id);
    FrmInputText("subject",_m("Subject"), $subject, 99, 50, true);
    FrmInputText("author",_m("Author"), $author, 60, 25, true);
    FrmInputEmail("e_mail",_m("E-mail"), $e_mail, 60);
    FrmTextarea("body", _m("Text of discussion comment"), $body, 10, 40);
    FrmDate("date", _m('Date'), $date, false, '', '', true);
    FrmInputText("url_address",_m("Authors's WWW  - URL"), $url_address, 99, 50);
    FrmInputText("url_description", _m("Authors's WWW - description"), $url_description, 60);
    FrmInputText("remote_addr",_m("Remote address"), $remote_addr, 60);
    FrmTextarea("free1", _m("Free 1"), $free1, 5, 40);
    FrmTextarea("free2", _m("Tracking"), $free2, 5, 40);
FrmTabEnd($form_buttons);
$apage->printFoot();
