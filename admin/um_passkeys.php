<?php
/**
 * The passkeys of the logged-in account: add, rename, delete. A user manager opens another account (?usr=) to
 * delete its passkeys when the owner lost them - the password then logs in again. Adding and deleting need a
 * login of the last minutes (AA_Auth::isFresh()), so a session left open or stolen cannot add a key of its own.
 *
 * PHP version 8.2+
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Central\ResponderPolicy;
use AA\Passkey\Ceremony;
use AA\Passkey\Notice;
use AA\Passkey\Refused;
use AA\Passkey\Store;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";

const PASSKEYS_TOKEN = 'aa-passkeys';

try {
    $usr    = InputVars::stringGETPOST('usr');
    $action = InputVars::stringPOST('passkey_action');
    $answer = InputVars::stringPOST('passkey_new');
    $name   = InputVars::stringPOST('passkey_name');
    $id     = InputVars::intPOST('passkey_id');
    $token  = InputVars::stringPOST('token');
    $done   = InputVars::stringGET('done');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

$uid = ($usr === '') ? AuthGetUid() : $usr;
try {
    $key = AA::$perm->passkeyId($uid);
    $own = ($usr === '') || (($key !== null) && ($key === AA::$perm->passkeyId(AuthGetUid())));
} catch (RuntimeException) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("The directory of the accounts does not answer now - try it again later"), "admin");
}
// deleting the passkeys of another account lets its password in again - the right of setting that password
if (!$own && !IfSlPerm(PS_NEW_USER)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to manage the passkeys of this account"), "admin");
}
// a passkey changes how the account logs in - the right of changing its password, and never a password others know
$may_add  = $own && IfSlPerm(PS_EDIT_SELF_USER_DATA) && AA::$perm->isUserEditable($uid) && !AA_Auth::publicPassword($uid);
$store    = new Store();
$ceremony = Ceremony::here();
$back     = $own ? [] : ['usr' => $uid];
$minutes  = intdiv(AA_Auth::FRESH_SECONDS, 60);

if (($action !== '') && ($key !== null)) {
    if (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::checkPost(PASSKEYS_TOKEN, $token, $_SERVER)) {
        AA_Adminpageutil::MsgPageMenu(get_admin_url('um_passkeys.php', $back), _m('The form has expired; reload the page and try again.'), 'admin');
    }
    // a rename changes nothing about the login - the rest needs a login of a moment ago
    if (($action !== 'rename') && !AuthIsFresh()) {
        go_url(get_admin_url('um_passkeys.php', $back + ['done' => 'stale']));
    }
    $done = 'nothing';
    if (($action === 'add') && $may_add && $ceremony && ($answer !== '')) {
        try {
            Notice::added($ceremony->register($key, $answer, $name));
            $done = 'added';
        } catch (Refused $e) {
            $done = in_array($e->reason, [Refused::DUPLICATE, Refused::CHALLENGE], true) ? $e->reason : 'failed';
        }
    } elseif (($action === 'rename') && $own) {
        $done = $store->rename($key, $id, $name) ? 'renamed' : 'nothing';
    } elseif ($action === 'delete') {
        if ($credential = $store->delete($key, $id)) {
            Notice::deleted($credential);
            $done = 'deleted';
        }
    } elseif (($action === 'reset') && !$own) {
        if ($count = $store->deleteAll($key)) {
            Notice::reset($key, $count);
            $done = 'reset';
        }
    }
    go_url(get_admin_url('um_passkeys.php', $back + ['done' => $done]));
}

// only known words come through the URL - never its text
$messages = [
    'added'            => MsgOk(_m('The passkey is stored. From now on this account logs in only by a passkey.')),
    'renamed'          => MsgOk(_m('The passkey is renamed.')),
    'deleted'          => MsgOk(_m('The passkey is deleted.')),
    'reset'            => MsgOk(_m('All passkeys of the account are deleted - its password logs in again.')),
    'stale'            => MsgErr(_m('Log in again first - adding or deleting a passkey needs a login of the last %1 minutes.', [$minutes])),
    Refused::DUPLICATE => MsgErr(_m('This passkey is stored already.')),
    Refused::CHALLENGE => MsgErr(_m('The page waited too long. Try it again.')),
    'failed'           => MsgErr(_m('The passkey could not be stored. Try it again, or another device.')),
];

$apage = new AA_Adminpageutil('userinfo');
$apage->setTitle(_m('Passkeys'));
$apage->addRequire('aa-passkey@1');
$apage->addRequire('
    .aa-passkeys td form { display: inline; margin: 0; }
    .aa-passkeys input[type="text"] { width: 14em; }
    .aa-passkeys-add { max-width: 44rem; margin-top: 1.5em; }
    .aa-passkeys-add [role="status"] { display: block; margin-top: 0.4em; }
', 'AA_Req_CSScode');
$apage->printHead([], $messages[$done] ?? '');

$info  = AA::$perm->getIDsInfo($uid);
$login = is_array($info) ? (string) ($info['login'] ?? '') : '';
echo '<h2>' . safe($own ? _m('Passkeys of your account %1', [$login]) : _m('Passkeys of the account %1', [$login])) . '</h2>';

if ($key === null) {
    echo '<p>' . _m('This account cannot have passkeys - only the users of the permission system of AA can.') . '</p>';
    $apage->printFoot();
    exit;
}

if ($own) {
    echo '<p>' . _m('A passkey logs you in without a password - by the fingerprint, face or PIN of your phone or computer. Nobody can steal it by a fake page or guess it.') . '</p>';
    echo '<p><strong>' . _m('Once the account has a passkey, the password alone no longer logs it in') . '</strong> - ' . _m('neither here, nor for Central or scripts that log in by it.') . ' ' . _m('Add two passkeys - say in your phone and in your computer - so you keep the access when you lose one. If you lose all of them, a user manager of this AA deletes them and you log in by your password again.') . '</p>';
} else {
    echo '<p>' . _m('Delete the passkeys only when their owner asks for it - lost or stolen device. The account then logs in by its password again, and its owner gets a mail about it.') . '</p>';
}

$passkeys = $store->forUid($key);
$fresh    = AuthIsFresh();
$form     = static fn(string $action, string $inner): string => '<form method="post" action="' . safe(get_admin_url('um_passkeys.php', $back)) . '">' . FormToken::field(PASSKEYS_TOKEN)
    . '<input type="hidden" name="passkey_action" value="' . safe($action) . '">' . $inner . '</form>';

if ($passkeys) {
    echo '<table class="aa-table aa-table-simple aa-w-no-full aa-passkeys"><tr><th>' . _m('Name') . '</th><th>' . _m('Works on') . '</th><th>' . _m('Added') . '</th><th>' . _m('Last used') . '</th><th>' . _m('Copies') . '</th><th></th></tr>';
    foreach ($passkeys as $credential) {
        $id_field = '<input type="hidden" name="passkey_id" value="' . $credential->id . '">';
        $name_html = $own
            ? $form('rename', $id_field . '<input type="text" name="passkey_name" maxlength="100" value="' . safe($credential->name) . '" aria-label="' . safe(_m('Name')) . '"> <button type="submit">' . _m('Rename') . '</button>')
            : safe($credential->name);
        echo '<tr><td>' . $name_html . '</td><td>' . safe($credential->rpId) . '</td><td>' . date('Y-m-d H:i', $credential->created) . '</td><td>'
            . ($credential->lastUsed ? date('Y-m-d H:i', $credential->lastUsed) : _m('not yet')) . '</td><td>'
            . ($credential->backedUp ? _m('in your other devices too') : _m('only in this device')) . '</td><td>'
            . ($fresh ? $form('delete', $id_field . '<button type="submit">' . _m('Delete') . '</button>') : '') . '</td></tr>';
    }
    echo '</table>';
    if (!$own && $fresh) {
        echo $form('reset', '<p><button type="submit">' . _m('Delete all passkeys of the account') . '</button></p>');
    }
} else {
    echo '<p>' . ($own ? _m('You have no passkey yet.') : _m('The account has no passkey.')) . '</p>';
}

if (!$fresh) {
    echo '<p>' . _m('To add or delete a passkey, log in again: it needs a login of the last %1 minutes.', [$minutes]) . ' ' . a_href(get_admin_url('um_passkeys.php', $back + ['relogin' => 'true']), _m('Log in again')) . '</p>';
} elseif ($own && !$may_add) {
    echo '<p>' . _m('A passkey cannot be added to this account: you may not change its data, or its password is public (the free links of a module log in by it).') . '</p>';
} elseif ($own && !$ceremony) {
    echo '<p>' . _m('A passkey can be added only on a page reached by https and by an address of this AA or of one of its sites, not an IP address.') . '</p>';
} elseif ($own) {
    try {
        $options = $ceremony->registrationOptions($key, $login, is_array($info) ? (string) ($info['name'] ?? '') : '');
    } catch (Throwable) {   // no curve or hash in this PHP's openssl
        $options = '';
    }
    echo '<section class="aa-passkeys-add"><h3>' . _m('Add a passkey') . '</h3>';
    if ($options === '') {
        echo '<p>' . _m('This server cannot make passkeys.') . '</p>';
    } else {
        echo '<p>' . _m('The passkey will work on %1. On the other addresses of this AA the login page sends you here.', [safe($ceremony->rpId())]) . '</p>';
        echo $form('add', '<label for="aa-passkey-name">' . _m('Name of the passkey') . '</label> '
            . '<input type="text" id="aa-passkey-name" name="passkey_name" maxlength="100" placeholder="' . safe(_m('e.g. My phone')) . '"> '
            . '<input type="hidden" name="passkey_new" value="">'
            . '<aa-passkey mode="create" field="passkey_new" expires="' . Ceremony::TIMEOUT . '" options="' . safe($options) . '"'
            . ' text-cancelled="' . safe(_m('No passkey was made.')) . '" text-exists="' . safe(_m('This device keeps a passkey of the account already.')) . '" text-failed="' . safe(_m('The browser could not make a passkey.')) . '"'
            . ' text-unsupported="' . safe(_m('This browser cannot make passkeys.')) . '">'
            . '<button type="button" hidden>' . _m('Add a passkey') . '</button><span role="status"></span></aa-passkey>'
            . '<noscript>' . _m('Adding a passkey needs JavaScript.') . '</noscript>');
    }
    echo '</section>';
}

$apage->printFoot();
