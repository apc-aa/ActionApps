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
 * @author Honza Malík, Jakub Adámek, Econnect
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

$debugfill=$GLOBALS['debugfill'];

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

/** APC-AA configuration file */
require_once "include/config.php3";
require_once AA_INC_PATH."convert_charset.class.php3";

function ConvertEncodingDeep($value, $from=null, $to=null) {
    $encoder = ConvertCharset::singleton($from, $to);
    return is_array($value) ? array_map('ConvertEncodingDeep', $value) : $encoder->Convert($value);
}

if ($_REQUEST['convertfrom'] OR $_REQUEST['convertto']) {
    $_POST   = ConvertEncodingDeep($_POST, $_REQUEST['convertfrom'], $_REQUEST['convertto']);
}

// global variables should be quoted (since old AA code rely on that fact),
// however the new code should use $_POST, which are NOT quoted
if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

if ( get_magic_quotes_gpc() ) {
    $_POST   = StripslashesDeep($_POST);
    $_GET    = StripslashesDeep($_GET);
    $_COOKIE = StripslashesDeep($_COOKIE);
}

/** Main include file for using session management function on a page */
require_once AA_INC_PATH."locsess.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH."util.php3";
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
          <card id="carta1" title="apc.org" ontimer="'.$GLOBALS["err_url"].'">
            <timer value="1"/>
            </card>
        </wml>
        ';
        exit;
    }
    if ( !$GLOBALS["err_url"] ) {
        if ($GLOBALS['debugfill']) huhl("SendErrorPage with no url and txt=",$txt," err_url=",$GLOBALS["err_url"] );
        echo HtmlPageBegin("");
        echo "</head><body>";
        if (is_array($txt)) {
            PrintArray($txt);
        } else {
            echo $txt;
        }
        echo "</body></html>";
    } else {
        if (!$GLOBALS["use_post2shtml"]) {
            $posturl = get_url($GLOBALS["err_url"], "result=".substr(serialize($txt),0,1000));
            if ($GLOBALS['debugfill']) huhl("Going to post2shtml posturl=",$posturl);
            go_url($posturl);
        } else {
            if ($GLOBALS['debugfill']) huhl("Show result with url=",$GLOBALS["err_url"], " txt=",$txt);
            UseShowResult($txt,$GLOBALS["err_url"]);
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
          <card id="carta1" title="apc.org" ontimer="'.$GLOBALS["ok_url"].'">
            <timer value="1"/>
          </card>
        </wml>
        ';
        exit;
    }
    if ($debugfill) huhl("Filler:SendOkPage:",$txt);

    // we can use something like:
    //    ok_url = "/aa/view.php3?vid=1374&cmd[1374]=x-1374-_#N1_ID___"
    if ($GLOBALS["ok_url"]) {
        $GLOBALS["ok_url"] = str_replace('_#N1_ID___', $new_ids[0], $GLOBALS["ok_url"]);
    }
    if ($GLOBALS["inline"]) {
        readfile($GLOBALS["ok_url"]);
        exit;
    }
    if (!$GLOBALS["ok_url"]) {
        go_url($_SERVER['HTTP_REFERER']);
    } elseif (!$GLOBALS["use_post2shtml"]) {
        go_url($GLOBALS["ok_url"]);
    } else {
        UseShowResult($txt,$GLOBALS["ok_url"]);
    }
}

// trap field for spammer bots
if ( $answer )    {
    SendErrorPage(array ("fatal"=>_m("Not allowed to post comments")));
}

// new version of filling - through aa[] array allowing multiple items to store
//      aa[i63556a45e4e67b654a3a986a548e8bc9][headline_______1][]
//      aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
if ( isset($_POST['aa']) ) {
    $grabber = new AA_Grabber_Form();
    $translations = null;
    $saver        = new AA_Saver($grabber, $translations, null, 'by_grabber');
    $saver->run();
    SendOkPage( array("success" => "insert" ), $saver->newIds());
    exit;
}

//$debugfill=1;
if ($debugfill) huhl("DEBUGGING FILL PLEASE COME BACK LATER");

// init used objects
//if ($debugfill) huhl("Filler: Globals=",$GLOBALS);
if ( !$slice_id ) {
    SendErrorPage(array ("fatal"=>_m("Slice ID not defined")));
}

$slice      = AA_Slices::getSlice($slice_id);
$p_slice_id = q_pack_id($slice_id);

if (!$slice) {
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
    $zids = new zids();
    foreach ( $err_valid as $field_zid => $msg) {
        $zids->refill(substr($field_zid,1));  // remove first 'v' in the name
        if ($debugfill) huhl("Debugfill $zids=",$zids, '-', $zids->packedids(0));
        $result["validate"][$zids->packedids(0)] = $msg;
    }
}

// prepare content4id array before calling StoreItem (content4id is QUOTED!)
$content4id    = GetContentFromForm( $slice, $oldcontent4id, $insert );

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
    for ($i = 0; $i < $zids->count(); $i ++) {
        $field_id = $zids->packedids($i);
        $content4id[$field_id] = $oldcontent4id[$field_id];
    }
}

// put the item into the right bin
$bin2fill = $slice->getProperty("permit_anonymous_post");
if ($debugfill) huhl("bin2fill=",$bin2fill, " force_status_code=",$force_status_code);
if ( $bin2fill < 1 ) SendErrorPage(array("fatal"=>_m("Anonymous posting not admitted.")));
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
      if ($debugfill) huhl("Checking Password");
        $permok = false;
        reset ($fields);
        while (list ($fid) = each($fields))
            if (substr ($fid,0,14) == "password......") {
              $password = $content4id[$fid][0]['value'];
              $crypt_password = crypt($password, 'xx');
              $old_password = $oldcontent4id[$fid][0]['value'];
              if ($debugfill) huhl("Checking password field=$fid = new=$password old=$old_password text_password=$text_password crypt=$crypt_password");
                $permok = (
                           // Old check, based on text_password flag
                  ($text_password
                   ? ($password == $old_password)
                   : ($crypt_password == $old_password))
                  // Heuristic based on if old looks encrypted
                  || ( (substr($old_password,0,2) != 'xx')
                       && ($old_password == $password)));
                if ($debugfill) huhl("permok=$permok");
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
} elseif (!StoreItem( $my_item_id, $slice_id, $content4id, $fields, $insert, true, true, $oldcontent4id )) { // insert, invalidatecache, feed
    if ($debugfill) huhl("Filler: sending error");
    SendErrorPage( array("store" => _m("Some error in store item.")));
} else {
    if ($debugfill) huhl("Filler: Sending ok");
    SendOkPage( array("success" => $insert ? "insert" : "update" ), array($my_item_id));
}

?>