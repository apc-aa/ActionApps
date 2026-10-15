<?php
/**
 * Security protections of this install: each is on unless a superadmin switches it off here for an
 * old site whose forms still use the older way. Storage: AA\Security\Settings; State Check warns while
 * one is off (AA\Optimize\CheckSecurityProtections).
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
use AA\Optimize\CheckSecurityProtections;
use AA\Security\LoginThrottle;
use AA\Security\Protection;
use AA\Security\ProtectionLog;
use AA\Security\Settings;
use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\LogOnce;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

const SECURITY_TOKEN = 'aa-security';
const SECURITY_LOGIN_TOKEN = 'aa-security-login';

try {
    $save  = InputVars::boolPOST('save');
    $token = InputVars::stringPOST('token');
    $off   = InputVars::arrayPOST('off');
    $saved = InputVars::intGET('saved');
    $login_name = InputVars::stringGET('login_name');
    $allowed    = InputVars::boolGET('allowed');
    $allow      = InputVars::stringPOST('allow_login');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to change the security protections"), "admin");
}

if ($save) {
    // switching a protection off from a forged form would open the hole for whoever forged it
    if (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::checkPost(SECURITY_TOKEN, $token, $_SERVER)) {
        AA_Adminpageutil::MsgPageMenu(get_admin_url('aa_security.php'), _m('The form has expired; reload the page and try again.'), 'admin');
    }
    $changed = Settings::store(array_values(array_filter(array_map(static fn($v) => is_string($v) ? Protection::tryFrom($v) : null, $off))));
    go_url(get_admin_url('aa_security.php', ['saved' => count($changed)]));
}

if ($allow !== '') {
    if (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::checkPost(SECURITY_LOGIN_TOKEN, $token, $_SERVER)) {
        AA_Adminpageutil::MsgPageMenu(get_admin_url('aa_security.php'), _m('The form has expired; reload the page and try again.'), 'admin');
    }
    LoginThrottle::allow($allow, AuthGetUid());
    go_url(get_admin_url('aa_security.php', ['login_name' => $allow, 'allowed' => 1]) . '#aa-security-login');
}

/** the failed logins of one name and the button that lets it in - for a superadmin helping someone who cannot log in */
function security_login(string $login_name): string
{
    $html = '<section class="aa-security-login" id="aa-security-login"><h2>' . _m('Someone cannot log in after failed attempts') . '</h2>'
        . '<p>' . _m('Type their login name to see its failed logins of the last 12 hours, and let it log in at once.') . '</p>'
        . '<form method="get" action="' . safe(AA_INSTAL_PATH . 'admin/aa_security.php') . '#aa-security-login">'
        . (is_long_id(AA::$module_id) ? '<input type="hidden" name="module_id" value="' . safe(AA::$module_id) . '">' : '')
        . '<label for="aa-security-login-name">' . _m('Login name') . '</label> '
        . '<input type="text" id="aa-security-login-name" name="login_name" value="' . safe($login_name) . '" autocomplete="off" required> '
        . '<button type="submit">' . _m('Show') . '</button></form>';
    if ($login_name === '') {
        return $html . '</section>';
    }
    [$status, $addresses] = LoginThrottle::status($login_name, now());
    $off = !Settings::isOn(Protection::LoginThrottle);
    if (!$status) {
        $html .= '<p>' . _m('No failed login of %1 in the last 12 hours. If the login is refused all the same, other names failed from the same address; letting %1 in lets it through as well.', [safe($login_name)]) . '</p>';
    } else {
        $html .= '<table class="aa-table aa-table-simple aa-w-no-full"><tr><th>' . _m('Address') . '</th><th>' . _m('Failed logins') . '</th><th>' . _m('Last one') . '</th><th>' . _m('Next login') . '</th></tr>';
        foreach ($status as $one) {
            $html .= '<tr><td>' . safe($one['address']) . '</td><td>' . $one['failures'] . '</td><td>' . date('Y-m-d H:i', $one['last']) . '</td><td>'
                . (($one['wait'] && !$off) ? _m('waits %1 min', [(int) ceil($one['wait'] / 60)]) : _m('checked at once')) . '</td></tr>';
        }
        $html .= '</table>';
        if ($addresses > count($status)) {
            $html .= '<p>' . _m('%1 more addresses tried this name.', [$addresses - count($status)]) . '</p>';
        }
    }
    return $html . '<form method="post" action="' . safe(get_admin_url('aa_security.php')) . '">' . FormToken::field(SECURITY_LOGIN_TOKEN)
        . '<input type="hidden" name="allow_login" value="' . safe($login_name) . '">'
        . '<button type="submit">' . _m('Let %1 log in now', [safe($login_name)]) . '</button></form>'
        . '<p class="aa-security-scope">' . _m('Its failed logins until now stop counting, from every address; the log keeps who let it in. After 5 more wrong passwords it waits again.') . '</p></section>';
}

/** what the log shows of the protection at work - or that AA does not know */
function security_evidence(Protection $protection): string
{
    $event = $protection->event();
    if (is_null($event)) {
        return '<p class="aa-security-evidence aa-security-unknown">' . _m('Not measured: AA cannot tell whether a site relies on this protection.') . '</p>';
    }
    $days    = CheckSecurityProtections::WINDOW_DAYS;
    $refused = $run = 0;
    // the page comes from the request, so a prober makes a group per made-up page; count them all
    foreach (LogOnce::recent($event, now() - $days * 86400, 10000) as $row) {
        if (ProtectionLog::parse((string) $row['selector'])[0]) {
            $refused += (int) $row['days'];
        } else {
            $run += (int) $row['days'];
        }
    }
    // the log keeps one row per page and day, so that is what the numbers count
    $text = ($refused + $run === 0)
        ? _m('Nothing refused or run in the last %1 days.', [$days])
        : _m('Last %1 days: %2 refused, %3 run (counted once per page and day).', [$days, $refused, $run]);
    $link = ($refused + $run === 0) ? '' : ' <a href="' . safe(AA_Log::getAdminUrl($event, '', 'RLIKE')) . '">' . _m('Log rows') . '</a>';
    return '<p class="aa-security-evidence">' . $text . $link . '</p>';
}

function security_state(Protection $protection): string
{
    if (Settings::isOn($protection)) {
        return '<p class="aa-security-state">' . _m('On') . '</p>';
    }
    $switch = Settings::lastSwitch($protection);
    $since  = isset($switch['time']) ? _m('Off since %1, %2', [date('Y-m-d H:i', (int) $switch['time']), safe(AA::$perm->getUserName((string) ($switch['user'] ?? '')))]) : _m('Off');
    return '<p class="aa-security-state">' . $since . '</p>';
}

$apage = new AA_Adminpageutil('aaadmin', 'security');
$apage->setTitle(_m('Security'));
$apage->setForm();
$apage->addRequire('
    .aa-security { max-width: 56rem; margin: 1.2em 0 0; padding: 0; list-style: none; }
    .aa-security > li { display: grid; grid-template-columns: minmax(0, 3fr) minmax(14rem, 2fr); gap: 0.3em 2em; padding: 1em 0.8em; border-top: 1px solid var(--clr-border); }
    .aa-security > li:last-child { border-bottom: 1px solid var(--clr-border); }
    .aa-security > li.aa-security-off { background: var(--clr-basic-light); }
    .aa-security h2 { margin: 0; font-size: 1.1rem; }
    .aa-security p { margin: 0.2em 0; }
    .aa-security-scope, .aa-security-evidence { font-size: var(--sz-s); }
    .aa-security-state { font-weight: 600; }
    .aa-security-unknown { font-style: italic; }
    .aa-security details { grid-column: 1 / -1; }
    .aa-security summary { cursor: pointer; font-size: var(--sz-s); }
    .aa-security-switch { grid-column: 2; grid-row: 1 / span 2; align-self: start; }
    .aa-security-switch label { display: flex; gap: 0.5em; align-items: center; margin-top: 0.3em; cursor: pointer; }
    .aa-security-switch input { margin: 0; }
    .aa-security-switch .aa-security-note { display: none; margin-top: 0.5em; font-size: var(--sz-s); }
    li:has(.aa-security-switch input:checked) .aa-security-note { display: block; }
    .aa-security-actions { margin-top: 1.2em; }
    .aa-security-login { max-width: 56rem; margin-top: 2.5em; }
    .aa-security-login form { margin: 0.6em 0; }
    @media (max-width: 50rem) {
        .aa-security > li { grid-template-columns: minmax(0, 1fr); }
        .aa-security-switch { grid-column: 1; grid-row: auto; }
    }
', 'AA_Req_CSScode');
if ($saved > 0) {
    $apage->addRequire('aa-jslib');
    $apage->addRequire("AA_Message('" . escape4js(_m('Saved. Changes are written to the log.')) . "', 'ok')", 'AA_Req_Load');
}
if ($allowed && ($login_name !== '')) {
    $apage->addRequire('aa-jslib');
    // no name in it - the link of anyone could put one there
    $apage->addRequire("AA_Message('" . escape4js(_m('Done: the failed logins of the name below no longer count.')) . "', 'ok')", 'AA_Req_Load');
}
$apage->printHead();

echo '<p>' . _m('Each protection is on unless you switch it off here. Switch one off only for an old site whose forms or links still use the older way, and only until they are converted.') . '</p>';

echo '<form method="post" action="' . safe(get_admin_url('aa_security.php')) . '">' . FormToken::field(SECURITY_TOKEN);
echo '<ul class="aa-security">';
foreach (Protection::cases() as $protection) {
    $is_off = !Settings::isOn($protection);
    $id     = 'aa-security-' . $protection->value;
    echo '<li' . ($is_off ? ' class="aa-security-off"' : '') . '>';
    echo '<div><h2>' . safe($protection->label()) . '</h2><p class="aa-security-scope">' . safe($protection->scope()) . '</p></div>';
    echo '<div class="aa-security-switch">'
        . security_state($protection)
        . '<label for="' . $id . '"><input type="checkbox" id="' . $id . '" name="off[]" value="' . safe($protection->value) . '"' . ($is_off ? ' checked' : '') . '> ' . _m('Switch this protection off') . '</label>'
        . '<p class="aa-security-note" role="note">' . _m('State Check reminds you until it is on again.') . '</p>'
        . '</div>';
    echo security_evidence($protection);
    echo '<details><summary>' . safe($protection->migrationTitle()) . '</summary>'
        . '<p>' . safe($protection->migration()) . '</p></details>';
    echo '</li>';
}
echo '</ul>';
echo '<p class="aa-security-actions"><button type="submit" name="save" value="1">' . _m('Save') . '</button></p>';
echo '</form>';

echo security_login($login_name);

$apage->printFoot();
