<?php
//$Id$

require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_BASE_PATH."modules/links/constants.php3";
require_once AA_BASE_PATH."modules/links/util.php3";           // module specific utils
require_once AA_BASE_PATH."modules/links/cattree.php3";        // for event handler Event_LinkNew
require_once AA_BASE_PATH."modules/links/link.class.php3";     // link object

unset($r_state['linkedit']);
$r_state['linkedit']['old'] = $_POST;  // in case of bad input
unset($r_msg);
unset($r_err);

$senderUrlOK  = ( Links_IsPublic() ? "/podekovani.shtml" : $sess->url(self_base()."index.php3"));      // TODO - poradne
$senderUrlErr = ( Links_IsPublic() ? $sess->url(self_base()."linkedit.php3") : $sess->url(self_base()."linkedit.php3"));

// this is not used, now I think - cancel is served in linkedit.php3
if ($cancel) {
    // page where to go after this script execution
    $cancelUrl = ( Links_IsPublic() ? "/" : $sess->url(self_base() ."index.php3"));         // TODO - poradne
    go_url( $cancelUrl );
}


// trap field for spammer bots
if ( $answer )    {
     echo _m("Not accepted, sorry. Looks like spam.");
     exit;
}

$r_err[0] = "";  // error array (just for initializing variable)

// we allways sending link id - it prevent us of bug with 'Back' browser button
if ( $lid ) {
   $r_state['link_id'] = $lid;
}

// $r_state['link_id'] is not necessarily filled (for new links)
$new_link = new linkobj($r_state['link_id']);
$new_link->loadFromForm($r_err);

if ( count($r_err) > 1) {
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
    $r_msg[] = MsgOK(($result=='PROPOSAL') ?
               _m('Link change proposal inserted') : _m('Link inserted'));
    // $event->comes('LINK_UPDATED', $r_state["module_id"], 'Links',  $categs2assign, $isGlobalcat, $isGlobalcat);
} else {
    $r_state['link_id'] = $new_link->save();
    $r_msg[] = MsgOK(_m('Link inserted'));
    // $event->comes('LINK_NEW', $r_state["module_id"], 'Links', $categs2assign, Links_IsGlobalCategory($type), false);
}
Links_CountAllLinks();  // update number of links info

page_close();
debug('<a href="'.$senderUrlOK.'">'._m('Continue').'</a>');
go_url( $senderUrlOK );
exit;
?>
