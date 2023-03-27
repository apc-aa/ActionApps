<?php
//$Id$

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../modules/links/constants.php";
require_once __DIR__ . "/../../modules/links/util.php";           // module specific utils
require_once __DIR__ . "/../../modules/links/cattree.php";        // for event handler Event_LinkNew
require_once __DIR__ . "/../../modules/links/link.class.php";     // link object

try {
    // array ( 'atabaze-str…' => 4, 'Page name' => 2, 'ge name *' => 1, )
    $aa_name = InputVars::stringPOST('aa_name', true);
    // array ( '' => 7, )
    $answer = InputVars::stringPOST('answer', true); // empty
    // array ( 'aa' => 2, 'aas' => 2, 'Description' => 2, 'cription' => 1, )
    $description = InputVars::stringPOST('description', true); // alpha
    // array ( 1 => 6, 2 => 1, )
    $folder = InputVars::intPOST('folder', true);
    // array ( 'devel@ecn.cz' => 6, 'devel+s@ecn…' => 1, )
    $initiator = InputVars::stringPOST('initiator', true);
    // array ( )
    $lang = InputVars::arrayPOST('lang', true);
    // array ( '' => 4, 1 => 2, 2 => 1, )
    $lid = InputVars::intPOST('lid', true); // empty
    // array ( 'Editor\'s no…' => 4, 'ditor\'s note' => 2, 'or\'s note' => 1, )
    $note = InputVars::stringPOST('note', true);
    // array ( 't' => 4, 'tf' => 2, 'City' => 1, )
    $org_city = InputVars::stringPOST('org_city', true); // alpha
    // array ( '' => 4, 'rrr@dd.dd' => 2, 's@ss.ww' => 1, )
    $org_email = InputVars::stringPOST('org_email', true); // empty
    // array ( '' => 4, 'ttt' => 2, 'Fax' => 1, )
    $org_fax = InputVars::stringPOST('org_fax', true); // alpha, empty
    // array ( 333444555 => 4, '333444555r' => 2, 'Pho' => 1, )
    $org_phone = InputVars::stringPOST('org_phone', true); // alnum
    // array ( 12000 => 4, '12000r' => 2, 'st code' => 1, )
    $org_post_code = InputVars::stringPOST('org_post_code', true);
    // array ( 'test' => 4, 'testg' => 2, 'reet' => 1, )
    $org_street = InputVars::stringPOST('org_street', true); // alpha
    // array ( 'a' => 2, 'ass' => 2, 'riginal pag…' => 2, 'inal page n…' => 1, )
    $original_name = InputVars::stringPOST('original_name', true);
    // array ( )
    $reg = InputVars::arrayPOST('reg', true);
    // array ( 15 => 7, )
    $selcatCount = InputVars::intPOST('selcatCount', true);
    // array ( 1 => 4, 2 => 2, 4 => 1, )
    $selcatSelect0  = InputVars::intPOST('selcatSelect0', true);
    $selcatSelect1  = InputVars::intPOST('selcatSelect1', true); // empty
    $selcatSelect2  = InputVars::intPOST('selcatSelect2', true); // empty
    $selcatSelect3  = InputVars::intPOST('selcatSelect3', true); // empty
    $selcatSelect4  = InputVars::intPOST('selcatSelect4', true); // empty
    $selcatSelect5  = InputVars::intPOST('selcatSelect5', true); // empty
    $selcatSelect6  = InputVars::intPOST('selcatSelect6', true); // empty
    $selcatSelect7  = InputVars::intPOST('selcatSelect7', true); // empty
    $selcatSelect8  = InputVars::intPOST('selcatSelect8', true); // empty
    $selcatSelect9  = InputVars::intPOST('selcatSelect9', true); // empty
    $selcatSelect10 = InputVars::intPOST('selcatSelect10', true); // empty
    $selcatSelect11 = InputVars::intPOST('selcatSelect11', true); // empty
    $selcatSelect12 = InputVars::intPOST('selcatSelect12', true); // empty
    $selcatSelect13 = InputVars::intPOST('selcatSelect13', true); // empty
    $selcatSelect14 = InputVars::intPOST('selcatSelect14', true); // empty
    $selcatState0  = InputVars::stringPOST('selcatState0', true); // alpha, empty
    $selcatState1  = InputVars::stringPOST('selcatState1', true); // alpha, empty
    $selcatState2  = InputVars::stringPOST('selcatState2', true); // empty
    $selcatState3  = InputVars::stringPOST('selcatState3', true); // empty
    $selcatState4  = InputVars::stringPOST('selcatState4', true); // empty
    $selcatState5  = InputVars::stringPOST('selcatState5', true); // empty
    $selcatState6  = InputVars::stringPOST('selcatState6', true); // empty
    $selcatState7  = InputVars::stringPOST('selcatState7', true); // empty
    $selcatState8  = InputVars::stringPOST('selcatState8', true); // empty
    $selcatState9  = InputVars::stringPOST('selcatState9', true); // empty
    $selcatState10 = InputVars::stringPOST('selcatState10', true); // empty
    $selcatState11 = InputVars::stringPOST('selcatState11', true); // empty
    $selcatState12 = InputVars::stringPOST('selcatState12', true); // empty
    $selcatState13 = InputVars::stringPOST('selcatState13', true); // empty
    $selcatState14 = InputVars::stringPOST('selcatState14', true); // empty
    // array ( 'linkedit.php' => 7, )
    $senderUrl = InputVars::stringSlashedPOST('senderUrl', true);
    // array ( ' Submit [^…' => 7, )
    $submitbtn = InputVars::stringSlashedPOST('submitbtn', true);
    // array ( '' => 7, )
    $type = InputVars::nonePOST('type', true); // empty
    // array ( 'https://dat…' => 4, 'https://cdn…' => 2, 'https://www…' => 1, )
    $url = InputVars::stringPOST('url', true);
    $cancel = InputVars::boolPOST('cancel');
    $err = InputVars::arrayGETPOST('err');
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

unset($r_state['linkedit']);
$r_state['linkedit']['old'] = $_POST;  // in case of bad input
unset($r_msg);
unset($r_err);

$senderUrlOK  = ( Links_IsPublic() ? "/podekovani.shtml" : StateUrl(self_base()."index.php3"));      // TODO - poradne
$senderUrlErr = ( Links_IsPublic() ? StateUrl(self_base()."linkedit.php") : StateUrl(self_base()."linkedit.php"));

// this is not used, now I think - cancel is served in linkedit.php
if ($cancel) {
    // page where to go after this script execution
    $cancelUrl = ( Links_IsPublic() ? "/" : StateUrl(self_base() ."index.php3"));         // TODO - poradne
    go_url( $cancelUrl );
}


// trap field for spammer bots
if ( $answer )    {
     echo _m("Not accepted, sorry. Looks like spam.");
     exit;
}

$err = [];  // error array (just for initializing variable)

// we're always sending link id - it prevent us of bug with 'Back' browser button
if ( $lid ) {
   $r_state['link_id'] = $lid;
}

// $r_state['link_id'] is not necessarily filled (for new links)
$new_link = new linkobj($r_state['link_id']);
$new_link->loadFromForm($r_err);

if ( $r_err AND (count($r_err) > 1)) {
    page_close();
    go_url( con_url($senderUrlErr, "getOldV=1"));
}

// old link (the atempt to change old link)
if ( $r_state['link_id'] ) {
    $old_link = new linkobj($r_state['link_id']);
    $old_link->load();
    debug('linkedit2: old link loaded:', $old_link);
    $result = $old_link->tryChange($new_link);
    debug('linkedit2: now call oldlink save. oldlink:', $old_link);
    $r_state['link_id'] = $old_link->save();
    $r_msg[] = MsgOk(($result=='PROPOSAL') ?
               _m('Link change proposal inserted') : _m('Link inserted'));
    // $event->comes('LINK_UPDATED', $r_state["module_id"], 'Links',  $categs2assign, $isGlobalcat, $isGlobalcat);
} else {
    $r_state['link_id'] = $new_link->save();
    $r_msg[] = MsgOk(_m('Link inserted'));
    // $event->comes('LINK_NEW', $r_state["module_id"], 'Links', $categs2assign, Links_IsGlobalCategory($type), false);
}
Links_CountAllLinks();  // update number of links info

page_close();
debug('<a href="'.$senderUrlOK.'">'._m('Continue').'</a>');
go_url( $senderUrlOK );
exit;

