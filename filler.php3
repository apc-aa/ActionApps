<?php
/**
 * Script for submitting items anonymously, without accessing the admin interface
 *
 * See documentation in doc/anonym.html.
 *
 * Parameters (usually from a HTML form):
 * <pre>
 *   my_item_id   - item id, used when editing (not adding a new) item in the
 *                  anonymous form
 *   slice_id     - id of slice into which the item is added
 *   notvalidate  - if true, data input validation is skipped
 *   ok_url       - url where to go, if item is successfully stored in database
 *   err_url      - url where to go, if item is not stored in database (due to
 *                  validation of data, ...)
 *   inline       - the ok url will be send to output directly (by readfile)
 *                  (for AJAX editing)
 *                  ok_url = "http://example.org/aa/view.php3?vid=1374&cmd[1374]=x-1374-_#N1_ID___"
 *   ret_code     - AA expression used as return code for inline (AJAX edit)
 *   force_status_code - you may add this to force to change the status code
 *                       but the new status code must always be higher than bin2fill
 *                       setting (you can't add to the Active bin, for example)
 *   notshown[] - array (form field ID => 1) of unpacked IDs, e.g. v7075626c6973685f646174652e2e2e2e
 *                which are shown in the control panel but not in the anonym form
 *   bool use_post2shtml If true, use the post2shtml script to send the error
 *          description and the values filled to fillform.php3.
 *   bool text_password If true, the password is stored in text form (not encrypted).
 *   bool wap           Variable is set in filler-wap.php
 * </pre>
 *
 * @package UserInput
 * @version $Id$
 * @author Honza Malik, Jakub Adamek, Econnect
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $val the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
//$GLOBALS[debug]=0; $GLOBALS[errcheck] =1;
//error_reporting(E_ALL ^ E_NOTICE);
//ini_set('display_errors', 1);

$debugfill=$_REQUEST['debugfill'];

// ----- input variables normalization - start --------------------------------

// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }
function StripslashesDeep($value) { return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value); }

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
    $_REQUEST = StripslashesDeep($_REQUEST);
}

if (!ini_get('register_globals') OR !get_magic_quotes_gpc()) {
    foreach ($_REQUEST as $k => $v) {
        $$k = AddslashesDeep($v);
    }
}
// ----- input variables normalization - end ----------------------------------


/** APC-AA configuration file */
require_once "include/config.php3";
/** Main include file for using session management function on a page */
require_once AA_INC_PATH."locsess.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH."util.php3";

// not sure if the line bellow works...
$_REQUEST['slice_id'] = trim($_REQUEST['slice_id']);

if ($_REQUEST['convertfrom'] OR $_REQUEST['convertto']) {
    $_POST   = ConvertEncodingDeep($_POST, $_REQUEST['convertfrom'], $_REQUEST['convertto']);
} elseif ($_REQUEST['inline']) {
    if ($_REQUEST['slice_id']) {
        $slice = AA_Slice::getModule($_REQUEST['slice_id']);
        if ($slice->isValid()) {
            $charset = $slice->getCharset();
        }
    } elseif (is_array($_REQUEST['aa'])) {
        $charset = AA_Form_Array::getCharset($_REQUEST['aa']);
    }
    if ($charset AND ($charset != 'utf-8')) {
        $_POST   = ConvertEncodingDeep($_POST, 'utf-8', $charset);
    }
}

require_once AA_INC_PATH."formutil.php3";
/** Defines class for inserting and updating database fields */
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."itemfunc.php3";
/** utility for notifying people of events by email */
require_once AA_INC_PATH."notify.php3";
/** defines PageCache class used for caching informations into database */
require_once AA_INC_PATH."pagecache.php3";
/** date helper functions */
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."zids.php3";
require_once AA_INC_PATH."slice.class.php3";
require_once AA_INC_PATH."grabber.class.php3";
require_once AA_INC_PATH.'scroller.php3';  // we need it, because there coud be scroller stored in session (in manager)


function UseShowResult($txt,$url) {
    // allows to call a script showing the error results from fillform
    $_POST["result"]        = $txt;
    // allows fillform to use this data
    $_POST["oldcontent4id"] = StripslashesArray($GLOBALS["content4id"]);
    if (!$url) huhe("Warning: no Url on anonymous form (could be  ok_url or err_url missing");
    $GLOBALS["shtml_page"] = $url;
    if ($GLOBALS['debugfill']) huhl("Filler:UseShowResult");
    require_once "post2shtml.php3"; // Beware this doesn't just define functions!
    exit;
}

/**
 * Outputs a notification page when an error occurs.
 * If the err_url parameter is passed, redirects to the specified URL,
 * and passes $txt as the URL parameter named "result".
 * else generates an error page with the $txt message.
 * @param string $txt error message to print
 */
function SendErrorPage($txt) {
    // $wap variable is set in filler-wap.php
    if ($GLOBALS['wap']) {
        header("Content-type: text/vnd.wap.wml");
        echo '
        <?xml version="1.0" encoding="iso-8859-1"?>
        <!DOCTYPE wml PUBLIC "-//WAPFORUM//DTD WML 1.3//EN" "http://www.wapforum.org/DTD/wml13.dtd" >
        <wml>
          <card id="carta1" title="apc.org" ontimer="'.$_REQUEST["err_url"].'">
            <timer value="1"/>
            </card>
        </wml>
        ';
        exit;
    }
    if ( !$_REQUEST["err_url"] ) {
        if ($GLOBALS['debugfill']) huhl("SendErrorPage with no url and txt=",$txt," err_url=",$_REQUEST["err_url"] );
        echo HtmlPageBegin();
        echo "</head><body>";
        if (is_array($txt)) {
            PrintArray($txt);
        } else {
            echo $txt;
        }
        echo "</body></html>";
    } else {
        if (!$_REQUEST["use_post2shtml"]) {
            $posturl = get_url($_REQUEST["err_url"], "result=".substr(serialize($txt),0,1000));
            if ($GLOBALS['debugfill']) huhl("Going to post2shtml posturl=",$posturl);
            go_url($posturl);
        } else {
            if ($GLOBALS['debugfill']) huhl("Show result with url=",$_REQUEST["err_url"], " txt=",$txt);
            UseShowResult($txt,$_REQUEST["err_url"]);
        }
    }
    exit;
}

/**
 * Loads a page if posting is successful. If the ok_url parameter is passed,
 * redirects to the specified URL, else returns to the calling page.
 */
function SendOkPage($txt, $new_ids = array()) {


    global $debugfill;
    // $wap variable if set in filler-wap.php
    if ($GLOBALS['wap']) {
        header("Content-type: text/vnd.wap.wml");
        echo '
        <?xml version="1.0" encoding="iso-8859-1"?>
        <!DOCTYPE wml PUBLIC "-//WAPFORUM//DTD WML 1.3//EN" "http://www.wapforum.org/DTD/wml13.dtd" >
        <wml>
          <card id="carta1" title="apc.org" ontimer="'.$_REQUEST["ok_url"].'">
            <timer value="1"/>
          </card>
        </wml>
        ';
        exit;
    }
    if ($debugfill) huhl("Filler:SendOkPage:",$txt);

    // we can use something like:
    //    ok_url = "/aa/view.php3?vid=1374&cmd[1374]=x-1374-_#N1_ID___"
    // You can use also _#N1_ID___, _#N2_ID___,.., _#N245_ID___, ...)
    //    the ids goes from N1, 2, 3..  in order of filling in the database and
    //    DO NOT correspond with nXXX in aa[n100_536366d6ee723][..]
    //    there are new ids as well as updated (aa[u6376353533...]) - the new ones
    //    goes first, then updated
    if ($_REQUEST["ok_url"]) {
        // you can use aliases in ok_url which is then computed from the (first) inserted item.
        // It is better to use extra # in it, in order it is not expanded when the form is displayed:
        // use _##ITEM_ID_ instead of _#ITEM_ID_, ...
        $_REQUEST["ok_url"] = str_replace('_##', '_#', $_REQUEST["ok_url"]);
        if (false !== strpos($_REQUEST["ok_url"],'_#N')) {
            $new_als = array();
            foreach ($new_ids as $k => $v) {
                $new_als[] = '_#N'.($k+1).'_ID___';
            }
            $_REQUEST["ok_url"] = str_replace($new_als, $new_ids, $_REQUEST["ok_url"]);
        }
        if (false !== strpos($_REQUEST["ok_url"], '_#')) {
            $item = AA_Item::getItem(new zids(reset($new_ids), 'l'));
            $_REQUEST["ok_url"] = AA_Stringexpand::unalias($_REQUEST["ok_url"], '', $item);
        }
    }
    if ($_REQUEST["inline"]) {
        if ($_REQUEST["ok_url"]) {
            ReadFileSafe($_REQUEST["ok_url"]);
        } else {
            $retcode = $_REQUEST["ret_code"] ? $_REQUEST["ret_code"] : base64_decode($_REQUEST["ret_code_enc"]);
            $ret     = array();
            if ($txt['report']) {
                $ret['report'] = $txt['report'];
            }
            foreach($new_ids as $long_id) {
                $item = AA_Item::getItem(new zids($long_id, 'l'));
                $text = AA_Stringexpand::unalias($retcode, '', $item);
                if ($text AND $item) {
                    $slice = AA_Slice::getModule($item->getSliceID());
                    if (!empty($slice)) {
                        $charset = $slice->getCharset();
                        if ($charset != 'utf-8') {
                            $encoder = ConvertCharset::singleton();
                            $text    = $encoder->Convert($text, $charset,'utf-8');
                        }
                    }
                }
                $ret[$long_id] = $text;
            }
            $ret = json_encode($ret);
            if ($_REQUEST["ret_code_js"]) {
                echo getFrmJavascript(str_replace('AA_ITEM_JSON', $ret, $_REQUEST["ret_code_js"]));
            } else {
                header("Content-type: application/json");  // standard header based on IANA
                echo $ret;
            }
        }
        exit;
    }

    if (!$_REQUEST["ok_url"]) {
        go_url($_SERVER['HTTP_REFERER']);
    } elseif (!$_REQUEST["use_post2shtml"]) {
        go_url($_REQUEST["ok_url"]);
    } else {
        UseShowResult($txt,$_REQUEST["ok_url"]);
    }
}



bind_mgettext_domain(AA_INC_PATH.'lang/'.DEFAULT_LANG_INCLUDE);

// trap field for spammer bots
if ( $_REQUEST['answer'] )    {
    SendErrorPage(array ("fatal"=>_m("Not allowed to post comments")));
}

if (is_numeric($_REQUEST['respuesta'])) {
    if (($_REQUEST['varA'] + $_REQUEST['varB']) != $_REQUEST['respuesta']) {
        // $varA + $varB must be equal to $respuesta, if provided
        SendErrorPage(array ("fatal"=>_m("Wrong result, not posible to post comments.")));
    }
}




// new version of filling - through aa[] array allowing multiple items to store
//      aa[i63556a45e4e67b654a3a986a548e8bc9][headline_______1][]
//      aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
if ( isset($_POST['aa']) OR isset($_FILES['aa']) ) {

    if ($_COOKIE['AA_Sess']) {
        require_once AA_INC_PATH."request.class.php3";
        require_once AA_BASE_PATH."modules/site/router.class.php";
        $options = array(
            'aa_url'          => AA_INSTAL_URL,
            'cookie_lifetime' => 60*60*24*365  // one year
        );
        // $client_auth - global - used in AA_Generator_Uid
        $client_auth = new AA_Client_Auth($options);
        if ($usr = $client_auth->checkAuth()) {
            $auth = $client_auth->getRemoteAuth();
            $GLOBALS['apc_state']['xuser'] = $usr;
        }
    } elseif ($_SESSION['AA_CP_Session']) {
        page_open(array("sess" => "AA_CP_Session"));
        // this defines $auth object so, the "Last Changed By" is set to correct user
    }

    $grabber = new AA_Grabber_Form();
    $saver   = new AA_Saver($grabber, null, null, 'by_grabber');
    $saver->run();
    SendOkPage( array("report" => $saver->report() ), $saver->changedIds());
    exit;
}

// global variables should be quoted (since old AA code rely on that fact),
// however the new code should use $_POST, which are NOT quoted
if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_REQUEST as $k => $v) {
        $$k = AddslashesDeep($v);
    }
}

//$debugfill=1;
if ($debugfill) huhl("DEBUGGING FILL PLEASE COME BACK LATER");

// init used objects
//if ($debugfill) huhl("Filler: Globals=",$GLOBALS);
if ( !$slice_id ) {
    SendErrorPage(array ("fatal"=>_m("Slice ID not defined")));
}

$slice      = AA_Slice::getModule($slice_id);
$p_slice_id = q_pack_id($slice_id);

if (!is_object($slice) OR !$slice->isValid()) {
    SendErrorPage(array ("fatal"=>_m("Bad slice ID")));
}

// if you want to edit an item from an anonymous form, prepare its ID into
// the my_item_id hidden field
if (!$my_item_id) {
    $my_item_id = new_id();
    $insert     = true;
} else {
    $db->query("SELECT id FROM item WHERE id='".q_pack_id($my_item_id)."'");
    $insert = ! $db->next_record();
}
if ($debugfill) huhl("Debugfill insert=",$insert);

// Fills also global variable $oldcontent4id (which is NOT! DB quoted)
// (so $oldcontent4id is incompatible with $content4id - should be fixed
// by using ItemContent object in near future)
ValidateContent4Id($err_valid, $slice, $insert ? "insert" : "update", $my_item_id, !$notvalidate, $notshown);
list($fields, $prifields) = $slice->fields();


if (!(isset($prifields) AND is_array($prifields))) {
    SendErrorPage(array ("fatal"=>_m("No fields defined for this slice")));
}

if ($debugfill) huhl("Debugfill err_valid=",$err_valid);

if (count($err_valid) > 1) {
    unset($err_valid["Init"]);
    $zids = new zids(null, 'l');
    foreach ( $err_valid as $field_zid => $msg) {
        $zids->add(substr($field_zid,1));  // remove first 'v' in the name
        if ($debugfill) huhl("Debugfill $zids=",$zids, '-', $zids->packedids(0));
        $result["validate"][$zids->packedids(0)] = $msg;
    }
}

// prepare content4id array before calling StoreItem (content4id is QUOTED!)
$c4id = new ItemContent();
$c4id->setItemID($my_item_id);
$c4id->setFromForm( $slice, $oldcontent4id, $insert );

$content4id = $c4id->getContent();

// test for spam
foreach ($content4id as $field) {
    if (is_array($field)) {
        foreach ($field as $value) {
            if ( IsSpamText($value['value']) ) {
                SendErrorPage(array ("spam"=>_m("Not accepted, sorry. Looks like spam.")));
            }
        }
    }
}

// copy old values for fields not shown in the form
if (! $insert && is_array($notshown)) {
    foreach ( $notshown as $vfield_id => $foo) {
        $field_ids[] = substr($vfield_id,1);  // remove first 'v'
    }
    $zids = new zids($field_ids,'l');
    for ($i=0, $ino=$zids->count(); $i<$ino; $i++ ) {
        $field_id = $zids->packedids($i);
        $content4id[$field_id] = $oldcontent4id[$field_id];
    }
}

// put the item into the right bin
if (SC_NO_BIN == ($bin2fill = $slice->allowed_bin_4_user())) {
    SendErrorPage(array("fatal"=>_m("Anonymous posting not admitted.")));
}
// you may force to put the item into a higher bin (active < hold < trash)
$bin2fill = max ($bin2fill, $force_status_code);
// Allow setting status code in form, but only below force or bin2fill
$content4id["status_code....."][0]['value'] = max($bin2fill,$content4id["status_code....."][0]['value'] );

if ($insert) {
    $content4id["flags..........."][0]['value'] = ITEM_FLAG_ANONYMOUS_EDITABLE;
} elseif (!is_array($result)) {
  if ($debugfill) huhl("Perms=",$slice->getProperty("permit_anonymous_edit"));
    // Proove we are permitted to update this item.
    switch ($slice->getProperty("permit_anonymous_edit")) {
    case ANONYMOUS_EDIT_NOT_ALLOWED: $permok = false; break;
    case ANONYMOUS_EDIT_ALL:         $permok = true; break;
    case ANONYMOUS_EDIT_ONLY_ANONYMOUS:
    case ANONYMOUS_EDIT_NOT_EDITED_IN_AA:
        $oldflags = $oldcontent4id["flags..........."][0]['value'];
        // are we allowed to update this item?
        $permok = (($oldflags & ITEM_FLAG_ANONYMOUS_EDITABLE) != 0);
        $content4id["flags..........."][0]['value'] = $oldflags;
        break;
    case ANONYMOUS_EDIT_HTTP_AUTH:
        // For HTTP_AUTH permissions the reader is found in fillform.php3.
        // Here we don't get the $_SERVER["PHP_AUTH_USER"] information.
        $permok = true;
        break;
    case ANONYMOUS_EDIT_PASSWORD:
        $permok = false;
        reset ($fields);
        while (list ($fid) = each($fields))
            if (substr ($fid,0,14) == "password......") {
              $password = $content4id[$fid][0]['value'];
              $old_password = $oldcontent4id[$fid][0]['value'];
              $permok = (
                           // Old check, based on text_password flag
                  ($text_password ? ($password == $old_password) : AA_Perm::comparePwds($password, $old_password))
                  // Heuristic based on if old looks encrypted
                  || ( !in_array(substr($old_password,0,2), array('xx','$2')) && ($old_password == $password)));
                break;
            }
        break;
    }

    if (!$permok) {
        $result["permissions"] = _m("You are not allowed to update this item.");
    }
}

if ($debugfill) huhl("result=",$result);

// See doc/anonym.html for structure of $result, which is intended
// for fillform.php3 to interpret and display

// if ($debugfill) exit;

if ($debugfill) huhl("Going to Store Item");
if ($debugfill) huhl("content4id=",$content4id);
if (is_array($result)) {
    SendErrorPage( $result );
} elseif (!StoreItem( $my_item_id, $slice_id, $content4id, $insert, true, true )) { // insert, invalidatecache, feed
    if ($debugfill) huhl("Filler: sending error");
    SendErrorPage( array("store" => _m("Some error in store item.")));
} else {
    if ($debugfill) huhl("Filler: Sending ok");
    SendOkPage( array("success" => $insert ? "insert" : "update" ), array($my_item_id));
}

?>