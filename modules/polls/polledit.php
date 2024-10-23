<?php
/**
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
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
* @author    Pavel Jisl <pavel@cetoraz.info>, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
*/
/* Based on phpPolls 1.0.3 from http://phpwizards.net
   also distributed under GPL v2.

   Rewrite and APC-AA integration as module by pavelji (pavel@cetoraz.info)

*/
 // used in init_page.php script to include config.php from the right directory
use AA\Util\InputVars;

$directory_depth = '../';

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/mgettext.php";

try {
    // array ( 'e37db0bc86b…' => 1, )
    $poll_id = InputVars::idGETPOST('poll_id');
    $headline = InputVars::stringPOST('headline');
    $publish_date = InputVars::intPOST('publish_date');
    $expiry_date  = InputVars::intPOST('expiry_date');
    $design_id= InputVars::stringPOST('design_id');
    $aftervote_design_id  = InputVars::stringPOST('aftervote_design_id');
    $locked   = InputVars::boolPOST('locked');
    $logging  = InputVars::boolPOST('logging');
    $ip_locking   = InputVars::boolPOST('ip_locking');
    $ip_lock_timeout  = InputVars::intPOST('ip_lock_timeout');
    $set_cookies  = InputVars::boolPOST('set_cookies');
    $params   = InputVars::stringPOST('params');

    $answers  = InputVars::arraySlashedPOST('answers');

    $insert = InputVars::boolPOST('insert');
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

// Check permissions for this page.

if (!CheckPerms( AuthGetUid(), "slice", AA::$module_id, PS_MODP_ADD_POLL)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("No permission to add/edit poll."), "admin");
    exit;
}

// fill code for handling the operations managed on this page

if ($insert  || $update ) {

    $datectrl  = new datectrl('publish_date');
    $datectrl->update();                   // updates datectrl
    $publish_date = $datectrl->get_date();

    $datectrl  = new datectrl('expiry_date');
    $datectrl->update();                   // updates datectrl
    $expiry_date   = $datectrl->get_date();

    $varset = new CVarset();

    $data = [
        //'poll_id' => $poll_id,
        'module_id' => AA::$module_id,
        'headline' => $headline,
        'publish_date' => $publish_date,
        'expiry_date' => $expiry_date,
        'design_id' => $design_id,
        'aftervote_design_id' => $aftervote_design_id,
        'locked' => $locked,
        'logging' => $logging,
        'ip_locking' => $ip_locking,
        'ip_lock_timeout' => $ip_lock_timeout,
        'set_cookies' => $set_cookies,
        'params' => $params
    ];

    if ($insert) {
        $data['status_code'] = 1;
        $poll_id = new_id();
        $data['id'] = $poll_id;
        if (false === AA::Metabase()->doInsert('polls', $data)) {
            $err["DB"] = MsgErr(_m("Can't insert new poll"));
            exit;
        }
    } elseif ($update ) {
        $data['id'] = $poll_id;
        if (false === AA::Metabase()->doUpdate('polls', $data)) {
            $err["DB"] = MsgErr(_m("Can't update poll with id ".$poll_id));
            exit;
        }
    }

//    $varset->add("module_id",          "unpacked", AA::$module_id);
//    $varset->add("headline",           "text", $headline);
//    $varset->add("publish_date",       "number", $publish_date);
//    $varset->add("expiry_date",        "number", $expiry_date);
//    $varset->add("design_id"    ,      "text", $design_id);
//    $varset->add("aftervote_design_id","text", $aftervote_design_id);
//
//    $varset->add("locked",             "number", ($locked ? 1 : 0));
//    $varset->add("logging",            "number", ($logging ? 1 : 0));
//    $varset->add("ip_locking",         "number", ($ip_locking ? 1 : 0));
//    $varset->add("ip_lock_timeout",    "number", $ip_lock_timeout);
//    $varset->add("set_cookies",        "number", ($set_cookies ? 1 : 0));
//    // $varset->add("cookies_prefix",     "text", $cookies_prefix);  // not used
//
//    $varset->add("params",             "text", $params);
//
//    if ($insert) {
//        $varset->add("status_code","number", 1);
//        $poll_id = new_id();
//        $varset->add("id",    "text", $poll_id);
//        $SQL = $varset->makeINSERT('polls');
//    } elseif ($update ) {
//        $SQL = "UPDATE polls SET ". $varset->makeUPDATE() ." WHERE (module_id='$p_module_id' AND id='$poll_id')";
//    }
//    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
//        $err["DB"] = MsgErr(($update ? _m("Can't update poll with id ".$poll_id) : _m("Can't insert new poll")));
//        exit;
//    }

    $SQL         = "SELECT * FROM polls_answer WHERE (poll_id='$poll_id')";
    $answertable = GetTable2Array($SQL, 'id', 'aa_fields');

    if (is_array($answers)) {
        $i=1;
        $answers2store = [];
        foreach ( $answers as $v) {
            $v = stripslashes($v);    // it is quoted
            if (isset($answertable[substr($v, 1, 32)])) { // old answer
                $answ = $answertable[substr($v, 1, 32)];
                $answ['answer'] = substr($v, 34);
            } else {
                $answ = ['id'=>new_id(), 'answer'=>$v, 'votes'=>0];
            }
            $answ['poll_id']  = $poll_id;
            $answ['priority'] = $i;
            $answers2store[]  = $answ;
            $i++;
        }
    }

    $varset->clear();
    $varset->doDeleteWhere('polls_answer', "(poll_id='$poll_id')");

    foreach ($answers2store as $record) {
        $varset->resetFromRecord($record);
        $varset->doINSERT('polls_answer');
    }
    // clear cache
    AA::Pagecache()->invalidateFor([$poll_id,AA::$module_id]);    // invalidate this concrete poll and also the list of polls in this module, if used

    go_url(StateUrl(self_base() . "./index.php"));  // back to poll manager
}

$apage = new AA_Adminpageutil('pollsmanager', 'main');
$apage->setModuleMenu('modules/polls');
$apage->setTitle($poll_id ? _m("Edit poll") : _m("Add poll"));
$apage->addRequire(get_aa_url('javascript/inputform.min.js', ['v'=>AA_JS_VERSION] ));
$apage->setForm();
$apage->printHead($err, $Msg);

if ($poll_id) {
    $form_buttons = [
        "update",
        "poll_id" => ['type' => 'hidden', 'value'=>$poll_id],
        "cancel" => ["url"=>"index.php"]
    ];
} else {
    $form_buttons = [
        "insert",
        "cancel" => ["url"=>"index.php"]
    ];
}

?>
<form name="inputform" method="post" onSubmit="BeforeSubmit();" action="<?php echo StateUrl() ?>">
<?php
FrmTabCaption(_m("Insert question and answers"), $form_buttons);

$where = $poll_id ? "id='$poll_id'" : "module_id = '$p_module_id' AND status_code = 0";
$SQL  = "SELECT design_id, aftervote_design_id, params, set_cookies, ip_lock_timeout, locked, ip_locking, logging, expiry_date, publish_date, headline FROM polls WHERE $where";
$poll_fields = GetTable2Array($SQL, 'aa_first');
if (is_array($poll_fields)) {
    extract($poll_fields);
}

if ($poll_id) {

    $SQL         = "SELECT id, answer AS text FROM polls_answer WHERE (poll_id='$poll_id') ORDER BY priority";
    $answertable = GetTable2Array($SQL, '', 'aa_fields');
    $polltext    = [];
    if ( is_array($answertable) ) {
        foreach ($answertable as $answ) {
            $polltext[':'.$answ['id'].':'.$answ['text']] = $answ['text'];
        }
    }

    FrmInputText("headline", _m("Headline"), $headline, 255, 40, true, _m("Question"));
    FrmInputMultiText('answers[]',_m("Insert new answers and choose their order"), $polltext,  "", 10, true);
    FrmDate('publish_date',       _m('Publish Date'),                              $publish_date, true, "", "", true);
    FrmDate('expiry_date',        _m('Expiry Date'),                               $expiry_date,   true, "", "", true);

} else {

    FrmInputText("headline", _m("Headline"), "", 255, 40, true, _m("Question"));
    FrmInputMultiText('answers[]', _m("Insert new answers and choose their order"), [], "", 10, true);
    FrmDate('publish_date',        _m('Publish Date'),                              now(), true, "", "", true);
    FrmDate('expiry_date',         _m('Expiry Date'),                               now()+604800, true, "", "", true);   // 604800 - 60*60*24*7 - week
}

FrmTabSeparator(_m("Polls settings"));

FrmInputChBox("locked",         _m("Poll is locked"),     $locked);
FrmInputChBox("logging",        _m("Use logging"),        $logging);
FrmInputChBox("ip_locking",     _m("Use IP locking"),     $ip_locking);
FrmInputText("ip_lock_timeout", _m("IP Locking timeout"), $ip_lock_timeout, 15, 10, false, _m("time in seconds"));
FrmInputChBox("set_cookies",    _m("Use cookies"),        $set_cookies);
// FrmInputText("cookies_prefix",  _m("Cookies prefix"),     $cookies_prefix);  // not used
FrmInputText("params", _m("Parameters"), $params);

FrmTabSeparator(_m("Polls design templates"));

$SQL     = "SELECT id, name FROM polls_design WHERE (module_id='$p_module_id')";
$designs = GetTable2Array($SQL, 'id', 'name');

$designs = \AA\IO\DB\DB_AA::select(['id'=>'name'], 'SELECT id, name FROM polls_design', [['module_id', AA::$module_id, 'l']]);


FrmInputSelect("design_id",           _m("Select design type - before vote"), $designs, $design_id, true);
FrmInputSelect("aftervote_design_id", _m("Select design type - after vote"), $designs, $aftervote_design_id, false, _m('If the design after vote should look differently, then specify it here.'));

FrmTabEnd($form_buttons);

?>
</form>
<?php

$apage->printFoot();
