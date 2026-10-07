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
use AA\Security\Protection;
use AA\Security\Settings;
use AA\Security\SignedExpression;
use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\LogOnce;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

const SECURITY_TOKEN = 'aa-security';

try {
    $save  = InputVars::boolPOST('save');
    $token = InputVars::stringPOST('token');
    $off   = InputVars::arrayPOST('off');
    $saved = InputVars::intGET('saved');
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

/** what the log shows of the protection at work - or that AA does not know */
function security_evidence(Protection $protection): string
{
    $event = $protection->event();
    if (is_null($event)) {
        return '<p class="aa-security-evidence aa-security-unknown">' . _m('Not measured: AA cannot tell whether a site relies on this protection.') . '</p>';
    }
    $days    = CheckSecurityProtections::WINDOW_DAYS;
    $refused = $run = 0;
    // the page comes from the Referer, so a prober makes a group per made-up page; count them all
    foreach (LogOnce::recent($event, now() - $days * 86400, 10000) as $row) {
        if (SignedExpression::logged((string) $row['selector'])[0]) {
            $refused += (int) $row['days'];
        } else {
            $run += (int) $row['days'];
        }
    }
    // the log keeps one row per page and day, so that is what the numbers count
    $text = ($refused + $run === 0)
        ? _m('No form sent an unsigned expression in the last %1 days.', [$days])
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
    @media (max-width: 50rem) {
        .aa-security > li { grid-template-columns: minmax(0, 1fr); }
        .aa-security-switch { grid-column: 1; grid-row: auto; }
    }
', 'AA_Req_CSScode');
if ($saved > 0) {
    $apage->addRequire('aa-jslib');
    $apage->addRequire("AA_Message('" . escape4js(_m('Saved. Changes are written to the log.')) . "', 'ok')", 'AA_Req_Load');
}
$apage->printHead();

echo '<p>' . _m('Each protection is on unless you switch it off here. Switch one off only for an old site whose forms still use the older way, and only until they are converted.') . '</p>';

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
    echo '<details><summary>' . _m('How to convert older forms') . '</summary>'
        . '<p>' . safe($protection->migration()) . '</p></details>';
    echo '</li>';
}
echo '</ul>';
echo '<p class="aa-security-actions"><button type="submit" name="save" value="1">' . _m('Save') . '</button></p>';
echo '</form>';

$apage->printFoot();
