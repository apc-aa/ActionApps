<?php
//$Id$

$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once $GLOBALS[AA_INC_PATH]."formutil.php3";
require_once $GLOBALS[AA_INC_PATH]."varset.php3";
require_once "./constants.php3";
require_once "./util.php3";           // module specific utils
require_once "./cattree.php3";        // for event handler Event_LinkNew

unset($r_state['linkedit']);
$r_state['linkedit']['old'] = $HTTP_POST_VARS;  // in case of bad input
unset($r_msg);
unset($r_err);
# Function definitions --------------------------------------------------------

# Validate new link's data fields
function Validate() {
    global $linkname, $original_name, $description, $initiator, $url, $rate, $type,
           $org_city, $org_street, $org_post_code, $org_phone, $org_fax, $org_email,
           $note,
           $add_proposal_change,
           $sess, $auth, $db, $r_state, $r_err, $r_msg;

    ValidateInput("linkname",      _m('Page name'),          $linkname,      $r_err, true,  "text" );
    ValidateInput("original_name", _m('Original page name'), $original_name, $r_err, false, "text" );
    ValidateInput("description",   _m('Description'),        $description,   $r_err, false, "text" );
    ValidateInput("initiator",     _m('Author'),             $initiator,     $r_err, false, "email");
    ValidateInput("url",           _m('Url'),                $url,           $r_err, true,  "url"  );
    ValidateInput("rate",          _m('Rate'),               $rate,          $r_err, false, "float");
    ValidateInput("type",          _m('Link type'),          $type,          $r_err, false, "text" );
    ValidateInput("org_city",      _m('City'),               $org_city,      $r_err, false, "text" );
    ValidateInput("org_street",    _m('Street'),             $org_street,    $r_err, false, "text" );
    ValidateInput("org_post_code", _m('Post code'),          $org_post_code, $r_err, false, "text" );
    ValidateInput("org_phone",     _m('Phone'),              $org_phone,     $r_err, false, "text" );
    ValidateInput("org_fax",       _m('Fax'),                $org_fax,       $r_err, false, "text" );
    ValidateInput("org_email",     _m('E-mail'),             $org_email,     $r_err, false, "text" );
    ValidateInput("note",          _m('Editor\'s note'),     $note,          $r_err, false, "text" );

    # check for the same link
    if( !$add_proposal_change ) { # it is nonsence to check it in anonymous change
        $SQL = "SELECT id, name FROM links_links
                  LEFT JOIN links_changes on links_links.id=links_changes.proposal_link_id
                 WHERE url = '$url'
                   AND changed_link_id is NULL";   // don't search in proposals
        $db->query($SQL);
        while( $db->next_record()) {
            if( $db->f('id') != $r_state['link_id'] ) {
                $link = ( Links_IsPublic() ?               // anonymous user
                    "<a href='$url'>". $db->f('name').'</a>' :
                    '<a href="'.con_url($sess->url("linkedit.php3"),"lid=".$db->f('id')).'">'.
                $db->f('name').'</a>');
                $r_msg[] = MsgErr(_m('The same url as ') . $link);
            }
        }
    }
}

function FillVarsetData(&$varset) {
    global $linkname, $original_name, $description, $initiator, $url, $rate, $type,
           $org_city, $org_street, $org_post_code, $org_phone, $org_fax, $org_email,
           $note;
    $varset->add("name",          "quoted", $linkname);
    $varset->add("original_name", "quoted", $original_name);
    $varset->add("description",   "quoted", $description);
    $varset->add("initiator",     "quoted", $initiator);
    $varset->add("url",           "quoted", $url);
    $varset->add("type",          "quoted", $type);
    $varset->add("org_city",      "quoted", $org_city);
    $varset->add("org_street",    "quoted", $org_street);
    $varset->add("org_post_code", "quoted", $org_post_code);
    $varset->add("org_phone",     "quoted", $org_phone);
    $varset->add("org_fax",       "quoted", $org_fax);
    $varset->add("org_email",     "quoted", $org_email);
    $varset->add("note",          "quoted", $note);
    // default setting for votes
    $varset->add("rate",          "number", 4);
    $varset->add("votes",         "number", 2);
    $varset->add("plus_votes",    "number", 1);
}

function Regions2Db($inserted_id, $reg) {
    global $db;
    if( isset($reg) AND is_array($reg) ) {
        reset($reg);
        while( list(,$val) = each($reg) ) {
            $SQL = "INSERT INTO links_link_reg (link_id  , region_id)
                    VALUES                    ($inserted_id,   $val)";
            $db->query($SQL);
        }
    }
}

function Languages2Db($inserted_id, $lang) {
    global $db;
    if( isset($lang) AND is_array($lang) ) {
        reset($lang);
        while( list(,$val) = each($lang) ) {
            $SQL = "INSERT INTO links_link_lang (link_id  , lang_id)
                    VALUES                    ($inserted_id,  $val)";
            $db->query($SQL);
        }
    }
}

function DeleteLinkRegions($lid) {
    global $db;
    $SQL = "DELETE FROM links_link_reg WHERE link_id = $lid";
    $db->query($SQL);
}

function DeleteLinkLanguages($lid) {
    global $db;
    $SQL = "DELETE FROM links_link_lang WHERE link_id = $lid";
    $db->query($SQL);
}


/** Assign link to categories specified in $categs it also deletes all old
 *  category assignments or proposes changes, if you do not have enough
 *  permissions
 *  @param int       $lid     link id to assign
 *  @param int/array $categs  array of category ids to assign the link to
 *  @param array     $states  array with key=caterory_id and value=''highlight'
 *                            hor highlighted links
 */
function Links_UpdateCategoryAssignments($lid, $categs, $states) {
    global $db;

    # prepare $newAssignments array
    if ( isset($categs) AND is_array($categs) ) {
        foreach ( $categs as $cid ) {
            $newAssignments[] = array( "category_id" => $cid,
                                       "path" => GetCategoryPath( $cid ),
                                       "state" => $states[$cid]);
        }
    }

    # old assignments lookup
    $SQL = "SELECT category_id, path, base, state, proposal, proposal_delete
              FROM links_link_cat, links_categories
             WHERE links_categories.id = links_link_cat.category_id
               AND what_id=".$lid;
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $oldAssignments[] = array( "category_id"     => $db->f('category_id'),
                                   "path"            => $db->f('path'),
                                   "base"            => $db->f('base'),
                                   "state"           => $db->f('state'),
                                   "proposal"        => $db->f('proposal'),
                                   "proposal_delete" => $db->f('proposal_delete'));
    }

    # delete all links assignments
    $db->query( "DELETE FROM links_link_cat WHERE what_id = ". $lid );

    # unassign all removed assignments
    for( $i=0; $i < count($oldAssignments); $i++) {
        $assignAgain = false;
        for( $j=0; $j < count($newAssignments); $j++) {
            if( $oldAssignments[$i]['category_id'] == $newAssignments[$j]['category_id']) {
                $assignAgain =  true;
                break;
            }
        }
        if( $assignAgain ) {  # delete candidate
            $newAssignments[$j] = $oldAssignments[$i];
            if( IsCatPerm ( PS_LINKS_ADD_LINK, $oldAssignments[$i]['path'] )) {
                $newAssignments[$j]['proposal_delete'] = 'n';
            }
        } elseif (!IsCatPerm ( PS_LINKS_DELETE_LINK, $oldAssignments[$i]['path'] )) {
            $oldAssignments[$i]['proposal_delete'] = 'y';  // we can't delete it so
            $newAssignments[] = $oldAssignments[$i];       // we must create it again
        }  // else if we have permission to unassign, do not add it again
    }

    # is there still base category ?
    unset($baseDefined);
    for( $j=0; $j < count($newAssignments); $j++) {
        if( $newAssignments[$j] AND $newAssignments[$j]['base'] == 'y'
            AND $newAssignments[$j]['proposal'] == 'n' ) {
            $baseDefined = $j;
            break;
        }
    }

    # modify added links
    for( $j=0; $j < count($newAssignments); $j++) {
        if( $newAssignments[$j]['proposal_delete'] == 'y' )
            continue;    # skip delete candidates
        if( IsCatPerm ( PS_LINKS_ADD_LINK, $newAssignments[$j]['path'] )) {
            if( isset($baseDefined) ) {   # normal - not base link
                $newAssignments[$j]['base'] = (( $baseDefined == $j ) ? 'y' : 'n');
            } else {               # base link - first specified category
                $newAssignments[$j]['base'] = 'y';
                $baseDefined = $j;
            }
            if ($newAssignments[$j]['state']          != 'highlight')
                $newAssignments[$j]['state']           = 'visible';
            $newAssignments[$j]['proposal']            = 'n';
            $newAssignments[$j]['proposal_delete']     = 'n';
        }
        else {
            if($newAssignments[$j]['proposal']        != 'n')
                $newAssignments[$j]['proposal']        = 'y';
            switch($newAssignments[$j]['state']) {
                case 'highlight': $newAssignments[$j]['state'] = 'highlight'; break;
                case 'visible':   $newAssignments[$j]['state'] = 'visible'; break;
                case 'hidden':
                default:          $newAssignments[$j]['state'] = 'hidden'; break;
            }
            if($newAssignments[$j]['base']            != 'y')
                $newAssignments[$j]['base']            = 'n';
            if($newAssignments[$j]['proposal_delete'] != 'y')
                $newAssignments[$j]['proposal_delete'] = 'n';
        }
    }

    # if base category defined for this link, change all state
    #   from hidden to visible
    if( $baseDefined AND ($newAssignments[$baseDefined]['proposal'] == 'n')) {
        for( $j=0; $j < count($newAssignments); $j++) {
            if( $newAssignments[$j]['state'] == 'hidden' )
                $newAssignments[$j]['state'] == 'visible';
        }
    }

    # write assignments to database
    for( $j=0; $j < count($newAssignments); $j++) {
        $foo = $newAssignments[$j];
        Links_AssignLink($foo['category_id'], $lid, $foo['base'],
                   $foo['state'], $foo['proposal'], $foo['proposal_delete']);
    }
}


/** Returns array of category ids which was filled in the input form */
function GetCategoriesFromForm() {
    global $selcatCount;   // selcatSelect* and selcatState* are global too!!!
    for( $i=0; $i<$selcatCount; $i++) {
        $cid = $GLOBALS["selcatSelect$i"];
        if(  strrpos($cid, ',') ) {   // get category id if in path
            $cid = GetCategoryFromPath( $path );
        }
        if( $cid ) {
            $categs2assign[] = $cid;
            $cat_states[$cid] = $GLOBALS["selcatSelect$i"];
        }
    }
    return array($categs2assign,$cat_states);
}

# End of function definitions -------------------------------------------------

$senderUrlOK  = ( Links_IsPublic() ? "/podekovani.shtml" : $sess->url(self_base()."index.php3"));      // TODO - poradne
$senderUrlErr = ( Links_IsPublic() ? $sess->url(self_base()."linkedit.php3") : $sess->url(self_base()."linkedit.php3"));

# page where to go after this script execution
$cancelUrl = ( Links_IsPublic() ? "/" : $sess->url(self_base() ."index.php3"));         // TODO - poradne

if($cancel)
    go_url( $cancelUrl );

$r_err[0] = "";  // error array (just for initializing variable)
$varset = new Cvarset();

$url           = trim($url);
$original_name = trim($original_name);
$linkname      = trim($linkname);
$rate          = trim($rate);
$type          = trim($type);
$description   = trim($description);
$initiator     = trim($initiator);
$org_city      = trim($org_city);
$org_street    = trim($org_street);
$org_post_code = trim($org_post_code);
$org_phone     = trim($org_phone);
$org_fax       = trim($org_fax);
$org_email     = trim($org_email);
$note          = trim($note);

// we allways sending link id - it prevent us of bug with 'Back' browser button
if( $lid )
   $r_state['link_id'] = $lid;

if( $r_state['link_id'] ) {
    $lpath = GetBaseCategoryPath($r_state['link_id']);
    if( $lpath AND !IsCatPerm( PS_LINKS_EDIT_LINKS, $lpath )) {
        # no permission to change link - doesn't matter - propose change
        #   how? - create new link and join it to existing by "changes" table
        $add_proposal_change = true;
    }
    $oldLink = GetLinkInfo( $lid );
}

if( !$r_state['link_id'] OR $add_proposal_change ) {
    #----------------------------------------------------------------------
    # new link or not perm to change
    #----------------------------------------------------------------------

    # everybody has the permission to create a link
    //  if(!IsGlobalPerm( PS_CREATE_LINK )) {      // no permission to add link data
    //    $r_err[] = MsgErr(L_NO_PS_CREATE_LINK);
    //    page_close();
    //    go_url( $sess->url(self_base() . $senderUrl));
    //  }
    Validate();
    if( count($r_err) > 1) {
        page_close();
        go_url(con_url($senderUrlErr,"getOldV=1"));
    }

    # insert data into link table
    FillVarsetData($varset);     # set varset name, description, url ...
    $r_state['linkedit']['last_autors_email'] = $initiator;
    $r_state['linkedit']['last_rate'] = $rate;
    $now_date = now();
    $varset->add("created_by", "quoted", $auth->auth["uid"]);
    $varset->add("created", "date", $now_date);
    $varset->add("checked_by", "quoted", $auth->auth["uid"]);
    $varset->add("checked", "date", $now_date);
    $varset->add("edited_by", "quoted", $auth->auth["uid"]);
    $varset->add("last_edit", "quoted", $now_date);
    $db->query("INSERT INTO links_links ". $varset->makeINSERT());
    # get inserted link id
    $db->query( "select LAST_INSERT_ID() as id" );
    if(!$db->next_record()) {
        huh("Error - Last inserted ID is lost");
        exit;
    }
    $inserted_id = $db->f('id');

    # fill region and language data from posted vars
    Regions2Db($inserted_id, $reg);
    Languages2Db($inserted_id, $lang);
    # fill assignments (anonymous => proposals)

    // prepare categories array to assign ($categs2assign[]=$cid, ...)
    list($categs2assign,$cat_states) = GetCategoriesFromForm();

    if( $add_proposal_change ) { # not new link, but proposal to change existing
        $SQL = "INSERT INTO links_changes
                   SET changed_link_id  = '". $r_state['link_id'] ."',
                       proposal_link_id = '$inserted_id',
                       rejected='n'";
        $db->query( $SQL );
        $r_msg[] = MsgOK(_m('Link change proposal inserted'));

        // Event handler called, but general category assignment action is not
        // performed - type is not changed (it is just proposal)
        $isGlobalcat = Links_IsGlobalCategory($oldLink['type']);
        $event->comes('LINK_UPDATED', $r_state["module_id"], 'Links',
                      $categs2assign, $isGlobalcat, $isGlobalcat);

        Links_UpdateCategoryAssignments($r_state['link_id'], $categs2assign, $cat_states );
    } else {                     // new link
        $r_msg[] = MsgOK(_m('Link inserted'));

        // $categs2assign could be changed by handler of following event
        // - used for 'general categories'
        $event->comes('LINK_NEW', $r_state["module_id"], 'Links', $categs2assign,
                      Links_IsGlobalCategory($type), false);

        Links_Assign2Category($inserted_id, $categs2assign, $add_proposal_change);
    }
    Links_CountLinksInCategories($categs2assign);  // update number of links info

    page_close();
    go_url( $senderUrlOK );
    exit;
}

#----------------------------------------------------------------------
# changed link - you have permissions
#----------------------------------------------------------------------
Validate();
if( count($r_err) > 1) {
    page_close();
    go_url( con_url($senderUrlErr, "getOldV=1"));
}

$now_date = now();
# insert data into link table
FillVarsetData($varset);     # set varset name, description, url ...
$varset->add("edited_by", "quoted", $auth->auth["uid"]);
$varset->add("last_edit", "quoted", $now_date);
  // if user with permissions to edit the link updates it, the link should be also checked
$varset->add("checked_by", "quoted", $auth->auth["uid"]);
$varset->add("checked", "date", $now_date);
$db->query("UPDATE links_links SET ". $varset->makeUPDATE() ."
             WHERE id=". $r_state['link_id']);

# fill region and language data from posted vars
DeleteLinkRegions($r_state['link_id']);
DeleteLinkLanguages($r_state['link_id']);
Regions2Db($r_state['link_id'], $reg);
Languages2Db($r_state['link_id'], $lang);
$r_msg[] = MsgOK(_m('Link changed'));

# hide all changes proposal
$SQL = "UPDATE links_changes SET rejected='y' WHERE changed_link_id='".$r_state['link_id']."'";
$db->query($SQL);

list($categs2assign,$cat_states) = GetCategoriesFromForm();

// $categs2assign could be changed by handler of following event
// - used for 'general categories'
$event->comes('LINK_UPDATED', $r_state["module_id"], 'Links', $categs2assign,
        Links_IsGlobalCategory($type), Links_IsGlobalCategory($oldLink['type']));

Links_UpdateCategoryAssignments($r_state['link_id'], $categs2assign, $cat_states );
Links_CountLinksInCategories($categs2assign);  // update number of links info

$r_msg[] = MsgOK(_m('Link assigned to category'));
page_close();
go_url( $senderUrlOK );
?>
