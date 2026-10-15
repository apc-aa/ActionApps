<?php

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;
use AA\Util\LogOnce;

/**
 * filldisc.php - writes a discussion item into the discussion table
 * expected parameters (usually from an HTML form):
 *          $d_item_id
 *          $d_parent_id
 *          $d_subject
 *          $d_author
 *          $d_e_mail
 *          $d_body
 *          $d_state            1 = hide the comment until a moderator approves it
 *          $d_free1
 *          $d_free2
 *          $d_url_address
 *          $d_url_description
 *
 * date and remote address(IP) of client is set by script.
 *
 * @package UserInput
 * @version $Id$
 * @author
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

// Inputs are read explicitly below (InputVars for the validated fields, $_REQUEST for the
// optional ones) — the old $$k = AddslashesDeep() register_globals emulation was removed
// (finding N14, 2026-09). Values are kept RAW and escaped by the DB layer at insert time.

/** APC-AA configuration file */
require_once __DIR__ . "/./include/config.php";
/** Main include file for using session management function on a page */
require_once __DIR__ . "/include/locsess.php";
/** Set of useful functions used on most pages */
require_once __DIR__ . "/include/util.php";
/** Defines class for inserting and updating database fields */
require_once __DIR__ . "/include/varset.php";
/** discussion utility functions */
require_once __DIR__ . "/include/discussion.php";
/** defines class that prints the items (news, discussions, calendar...) */
require_once __DIR__ . "/include/itemview.php";
/**  Defines class for item manipulation (shows item in compact or fulltext format, replaces aliases ...) */
require_once __DIR__ . "/include/item.php";
require_once __DIR__ . "/include/event.class.php";
require_once __DIR__ . "/include/validate.php";

try {
    // only the item is required - the other fields may be left empty (the spam trap 'answer' must be)
    $answer = InputVars::stringPOST('answer'); // empty
    // array ( 'HM' => 2, 'ee' => 1, '' => 2, 'rr' => 1, )
    $d_author = InputVars::stringPOST('d_author'); // alpha, empty
    // array ( 'opravené d…' => 2, 'errr' => 1, '' => 1, 'rr' => 1, 'f' => 1, )
    $d_body = InputVars::stringPOST('d_body'); // empty
    // array ( 'devel@ecn.cz' => 2, '' => 4, )
    $d_e_mail = InputVars::stringPOST('d_e_mail'); // empty
    // array ( '8de4c6fa4e6…' => 6, )
    $d_item_id = InputVars::idPOST('d_item_id', true);
    // array ( '' => 3, 'f0583c0321e…' => 1, 'd40e80bf3bb…' => 2, )
    $d_parent = InputVars::idPOST('d_parent'); // empty       // parent id
    // array ( 'opravené d…' => 2, '' => 4, )
    $d_subject = InputVars::stringPOST('d_subject'); // empty
    // array ( 'http://zpra…' => 6, )
    $url = InputVars::stringPOST('url');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// Optional discussion fields. Read RAW; escaped by the DB layer at insert (type "text").
// $d_free2 stays null when absent so the cookie-dump fallback at insert still fires.
$optional          = static fn(string $name): string => is_string($_REQUEST[$name] ?? null) ? $_REQUEST[$name] : '';
$d_free1           = $optional('d_free1');
$d_free2           = isset($_REQUEST['d_free2']) ? $optional('d_free2') : null;
$d_url_address     = $optional('d_url_address');
$d_url_description = $optional('d_url_description');
// a comment is shown (0) or hidden (1) by the moderators; a form may only ask to hide it until approved
$d_state           = ($optional('d_state') === '1') ? 1 : 0;

$err = [];       // error array (Init - just for initializing variable)

// trap field for spammer bots
if ($answer) {
    echo _m("Not accepted, sorry. Looks like spam.");
    exit;
}

if (ctype_digit((string) $_REQUEST['respuesta'])) {
    if (($_REQUEST['varA'] + $_REQUEST['varB']) != $_REQUEST['respuesta']) {
        // $varA + $varB must be equal to $respuesta, if provided
        echo _m("Wrong result, not possible to post comments.");
        exit;
    }
}

//$slice_id = unpack_id(GetTable2Array("SELECT slice_id FROM item WHERE id='".q_pack_id($d_item_id)."'", 'aa_first', 'slice_id'));
$slice_id = DB_AA::select1('usid', 'SELECT LOWER(HEX(`slice_id`)) as usid FROM item', [['id', $d_item_id, 'l']]);
$slice    = AA_Slice::getModule($slice_id);
if (empty($slice)) {
    echo _m("Comment to wrong item - item's slice not found.");
    exit;
}
if ($slice->getProperty('flag') & DISCUS_ADD_DISABLED) {
    echo _m("New comments disabled.");
    exit;
}
// only where the slice shows comments - its discussion view (or one set for its fulltext);
// comments to items of other slices were spam nobody saw
if (!$slice->getProperty('vid') && !DB_AA::test('view', [['slice_id', $slice_id, 'l'], ['type', 'discus']])) {
    LogOnce::daily('DISC_DENY', $slice_id, 'no discussion view');
    echo _m("New comments disabled.");
    exit;
}
// a reply belongs to a comment of the same item, else it starts a new thread
if ($d_parent && !DB_AA::test('discussion', [['id', $d_parent, 'l'], ['item_id', $d_item_id, 'l']])) {
    $d_parent = '';
}

// test for spam
$discussion_fields = [
    'd_parent'         => 0,   // allowed number of 'http' substings
    'd_item_id'        => 0,
    'd_subject'        => 0,
    'd_author'         => 0,
    'd_e_mail'         => 0,
    'd_body'           => 4,
    'd_free1'          => 4,
    'd_free2'          => 4,
    'd_url_address'    => 1,
    'd_url_description' => 0,
];
foreach ($discussion_fields as $field => $tolerance) {
    if (IsSpamText($$field, $tolerance)) {
        echo get_if($slice->getProperty('_msg_spam.......'), _m("Not accepted, sorry. Looks like spam."));
        exit;
    }
}

// test if the sender IP is not blocked by special slice
$ip_address         = $_SERVER['REMOTE_ADDR'];
$ip_banned_slice_id = $slice->getProperty('_ip_banned......');
$found_evil         = null;
if ($ip_banned_slice_id) {
    $subnet_ip_address = substr($ip_address, 0, strrpos($ip_address, '.'));
    $aa_set = new AA_Set($ip_banned_slice_id, new AA_Condition('ip..............', '=', "\"$ip_address\" OR \"$subnet_ip_address\""));
    $zids   = $aa_set->query();
    if (!$zids->isEmpty()) {
        $found_evil = $zids->slice(0);
    } else {
        // try text analysis
        // test all phrases from all keywords........ fields in $ip_banned_slice_id slice
        // (keywords........ could be multi-field)

        $format  = "{@keywords........:_AA_DeLiM_}";
        $set     = new AA_Set($ip_banned_slice_id, new AA_Condition('keywords........', 'NOTNULL', '1'));
        $kw_item = GetFormattedItems($set->query(), $format);

        $teststring = implode('_AA~#^', [$d_author, $d_e_mail, $d_body, $d_free1, (string) $d_free2, $d_url_address, $d_url_description]);
        foreach ($kw_item as $kw_id => $kw_string) {
            $bad_phrases = array_filter(explode('_AA_DeLiM_', $kw_string));
            foreach ($bad_phrases as $bph) {
                if (str_contains($teststring, $bph)) {
                    $found_evil = new zids($kw_id, 'l');
                    break 2;
                }
            }
        }
    }
    if ($found_evil) {
        $ban_msg = $slice->getProperty('_msg_banned.....');
        if ($ban_msg) {
            $discitem = AA_Item::getItem($found_evil);
            echo $discitem ? $discitem->unalias($ban_msg) : $ban_msg;
        } else {
            echo _m("Not accepted, your IP address is banned.");
        }
        exit;
    }
}

// do not accept comments for not existing items or the items which
// are in thrash or holding bin
$item_status = DB_AA::select1('status_code', 'SELECT status_code FROM item', [['id', $d_item_id, 'l']]);
if ($item_status <> 1) {
    echo _m("Not accepted, item is not published or does not exist.");
    exit;
}

$new_id = new_id();

$cookie = new CookieManager();
$cookie->set('d_author', $d_author, 60 * 60 * 24 * 90);   // 90 days
$cookie->set('d_e_mail', $d_e_mail, 60 * 60 * 24 * 90);   // 90 days
$cookie->set('d_url_address', $d_url_address, 60 * 60 * 24 * 90);   // 90 days
$cookie->set('d_url_description', $d_url_description, 60 * 60 * 24 * 90);   // 90 days

// All user-supplied values arrive RAW (InputVars::stringPOST / $_REQUEST / $_SERVER) - Metabase
// escapes them once; never pass pre-slashed values here.
$discussion = [
    'id'              => $new_id,
    'parent'          => pack_id($d_parent),   // not a packed column for Metabase, but holds packed ids
    'item_id'         => $d_item_id,
    'subject'         => $d_subject,
    'author'          => $d_author,
    'e_mail'          => $d_e_mail,
    'body'            => $d_body,
    'state'           => $d_state,
    'flag'            => 0,
    'free1'           => $d_free1,
    // the poster's cookies, but no session id - free2 is shown to the discussion's admins and templates
    'free2'           => $d_free2 ?? var_export(array_filter($_COOKIE, static fn($name) => stripos((string) $name, 'sess') === false, ARRAY_FILTER_USE_KEY), true),
    'url_address'     => $d_url_address,
    'url_description' => $d_url_description,
    'date'            => time(),
    'remote_addr'     => $ip_address,
];

if (!AA::Metabase()->doInsert('discussion', $discussion)) {  // not necessary - we have set the halt_on_error
    $err["DB"] .= MsgErr("Can't add discussion comment");
}

aaevent::singleton()->comes('ITEM_NEW_COMMENT', $d_item_id, 'Item', $new_id);

send2mailList($d_item_id, $new_id);

if ($_REQUEST['send_reactions'] && AA_Validate::doValidate($d_e_mail, 'email')) {
    AddNotification('ITEM_NEW_COMMENT', 'Item', $d_item_id, 'email', $d_e_mail);
}

// invalidate cache
$slice_id = unpack_id(DB_AA::select1('slice_id', 'SELECT slice_id FROM item', [['id', $d_item_id, 'l']]));
AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values

updateDiscussionCount($d_item_id);        // update a count of the comments belong to the item

// special discussion setting
if ($_REQUEST['all_ids']) {
    $url = str_replace('&sh_itm', "&all_ids=" . $_REQUEST['all_ids'] . '&sh_itm', $url);
}
go_url($url);
