<?php

//$Id$
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

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/../../include/mail.php";
require_once __DIR__ . "/../../include/mgettext.php";

function GetCollection($slice_id)
{
    $SQL =  "SELECT AC.*, module.name, module.lang_file, module.slice_url
              FROM alerts_collection AC INNER JOIN module ON AC.module_id = module.id";
    $ret = DB_AA::select1([], $SQL, [['module_id', $slice_id, 'l']]);
    if (is_array($ret)) {
        $ret['module_id'] = unpack_id($ret['module_id']);
        $ret['slice_id']  = unpack_id($ret['slice_id']);
    }
    return $ret;
}

/** Build email <select> options for the given type, matching what
 *  modules/alerts/emails.php lists: the accessible emails (per GetUserEmails),
 *  labelled "description (id)" so templates with similar names stay distinct.
 *  The currently-selected $current_id is always included, even if it belongs
 *  to a slice the user can no longer access, so a saved value is never
 *  silently dropped on the next save (backward compatibility).
 *
 * @param  string  $type        email type, e.g. "alerts welcome" / "alerts alert"
 * @param  int     $current_id  currently selected email id (0 = none)
 * @return array                [0 => "-- none --", id => "description (id)", ...]
 */
function alertsEmailOptions(string $type, int $current_id): array
{
    $options = [0 => _m("-- none --")];
    foreach (GetUserEmails($type) as $id => $description) {
        $options[$id] = $description . " (" . $id . ")";
    }
    if ($current_id && !isset($options[$current_id])) {
        $description = DB_AA::select1('description', "SELECT description FROM email", [['id', $current_id, 'i']]);
        $options[$current_id] = ($description !== false ? $description : _m("email")) . " (" . $current_id . ")";
    }
    return $options;
}

function set_collectionid(): void
{
    global $collectionid, $collectionprop, $no_slice_id;

    if (!$no_slice_id) {
        if (!AA::$module_id) {
            echo "Error: no slice ID";
            exit;
        }
        $collectionprop = GetCollection(AA::$module_id);
        if ($collectionprop) {
            $collectionid = $collectionprop['id'];
        } else {
            echo "Can't find collection with module_id=" . AA::$module_id . ". Bailing out.<br>";
            exit;
        }
    }
}

function get_howoften_options(): array
{
    $retval = [];
    $retval["instant"]   = _m("instant");
    $retval["daily"]     = _m("daily");
    $retval["weekly"]    = _m("weekly");
    $retval["twoweeks"]  = _m("twoweeks");
    $retval["monthly"]   = _m("monthly");
    $retval["irregular"] = _m("irregular");
    return $retval;
}

function get_bin_names(): array
{
    return [
        1 => _m("Active"),
        2 => _m("Holding bin"),
        3 => _m("Trash bin"),
    ];
}

function new_collection_id()
{
    do {
        $new_id = gensalt(5);
    } while (DB_AA::test('alerts_collection', [['id', $new_id]]));
    return $new_id;
}

// ----------------------------------------------------------------------------------------

function getAlertsField($field_id, $collection_id): string
{
    return substr($field_id . ".............", 0, 16 - strlen($collection_id)) . $collection_id;
}

// -----------------------------------------------------------------------------------

/** confirm_email function
 *   Confirms email on Reader management slices because the parameter $aw
 *   is sent only in Welcome messages.
 *   Returns true if email exists and not yet confirmed, false otherwise.
 */
function confirm_email($slice_id, $aw): bool
{

    require_once __DIR__ . "/../../include/itemfunc.php";

    $set  = new AA_Set($slice_id, new AA_Condition(FIELDID_ACCESS_CODE, '==', $aw));
    $zids = $set->query();

    if ($zids->count() !== 1) {
        warn("AW not OK: " . $zids->count() . " items");
        return false;
    }
    UpdateField($zids->longids(0), FIELDID_MAIL_CONFIRMED, new AA_Value('1'));
    return true;
}

/** The page of the current Alerts module the user lands on - see AlertsLandingPageFor()
 *  @return string a page of modules/alerts/; '' = none for the user */
function AlertsLandingPage(): string
{
    return AlertsLandingPageFor(AA_Module_Alerts::getOwnModule(AA::$module_id) !== null, IfSlPerm(PS_USERS), IfSlPerm(PS_ALERTS_STATS));
}

/** The settings for one who may change them, the mailings for an Editor of the module. The type before the
 *  right: in another module type the letter of the mailings means something else.
 *  @return string a page of modules/alerts/; '' = none for the user */
function AlertsLandingPageFor(bool $is_alerts, bool $may_settings, bool $may_mailings): string
{
    return match (true) {
        $may_settings               => 'modedit.php',
        $is_alerts && $may_mailings => 'mailings.php',
        default                     => '',
    };
}

/** The query parameter of an unsubscribe link that unsubscribes only by POST where the page can ask (AA_Collection::oneClickUrl()) */
const ALERTS_ONE_CLICK_PARAM = 'one';

/** Unsubscribes by a link of an Alerts mail - the one decision for every page that reads au.
 *  Mail services and link scanners open the links of a mail by GET, so a link marked with ALERTS_ONE_CLICK_PARAM
 *  (the List-Unsubscribe header, the body link of a mailing) unsubscribes only by POST: RFC 8058 posts
 *  List-Unsubscribe=One-Click with no cookies, the button of unsubscribe_ask() posts the same.
 *  An unmarked link (an Alerts digest) unsubscribes on GET, as it always did.
 *  @param string $one     the ALERTS_ONE_CLICK_PARAM of the URL; never from $_REQUEST, a cookie would override it
 *  @param array  $server  $_SERVER
 *  @param array  $post    $_POST
 *  @param bool   $can_ask false on a fillform (SSI) page: it cannot show the button and a POST does not reach the
 *                         include - there a marked link unsubscribes on GET too
 *  @return bool|null      null = a marked link opened by GET: show unsubscribe_ask() */
function unsubscribe_request($slice_id, $au, $c, $one, array $server, array $post, bool $can_ask = true): ?bool
{
    // the page cache keys by the address and the POST body - a stored answer would skip the next unsubscribe
    AA::Pagecache()->switchCacheNostore();
    $one_click = (strtoupper((string) ($server['REQUEST_METHOD'] ?? '')) === 'POST') && (($post['List-Unsubscribe'] ?? '') === 'One-Click');
    if ($can_ask && !empty($one) && !$one_click) {
        return null;
    }
    return unsubscribe_reader($slice_id, $au, $c);
}

/** The button for a marked link opened by GET. No action - the form posts to the URL of the page, au and c included. */
function unsubscribe_ask(): string
{
    return '<form method="post" class="aa-unsubscribe"><p>' . safe(_m('Do you want to stop receiving the mails of this list?')) . '</p>'
        . '<input type="hidden" name="List-Unsubscribe" value="One-Click">'
        . '<button type="submit">' . safe(_m('Unsubscribe')) . '</button></form>';
}

/** unsubscribe_reader function
 */
function unsubscribe_reader($slice_id, $au, $c): bool
{
    require_once __DIR__ . "/../../include/itemfunc.php";

    $readers = DB_AA::select('iid', 'SELECT LOWER(HEX(item.id)) as iid FROM `content`,`item`', [['content.item_id', 'item.id', 'j'],['item.slice_id',$slice_id,'l'],['content.field_id', FIELDID_ACCESS_CODE],['content.text',$au]]);
    if (count($readers) !== 1) {
        warn("AU not OK: " . count($readers) . " items");
        return false;
    }
    // c comes from the link and names the field written below - a foreign c could name any reader field
    if (!is_string($c) || ($c === '') || !DB_AA::test('alerts_collection', [['id', $c], ['slice_id', $slice_id, 'l']])) {
        warn("Collection not OK for the reader slice");
        return false;
    }
    $item_id   = reset($readers);
    $field_id  = getAlertsField(FIELDID_HOWOFTEN, $c);
    $frequency = DB_AA::select1('text', 'SELECT `text` FROM `content`', [['item_id', $item_id, 'l'], ['field_id', $field_id]]);

    if ($frequency === false) {
        // most readers never chose a frequency; the store creates the row, but silently skips a field the slice lacks
        $slice = AA_Slice::getModule($slice_id);
        if (!($slice instanceof AA_Slice) || !$slice->isField($field_id)) {
            return false;
        }
        $frequency = '';
    }

    if (!str_starts_with($frequency, 'unsub')) {  // left old unsub message and date
        UpdateField($item_id, $field_id, new AA_Value('unsubscribed:' . date('Y-m-d H:i') . ':au')); // just inform text
        debug("<!--OK: f $field_id unsubscribed-->");
    }
    return true;
}
