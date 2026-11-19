<?php

declare(strict_types=1);

namespace AA\Manageraction;

use AA\Central\Actionapps;
use AA\Manager;
use zids;

/** Manager action of the Central list: Get Token for each selected AA with one login - for AAs that
 *  share a superadmin account. Each AA checks the login itself; the password is kept nowhere. */
class Central_GetToken extends AbstractManageraction
{
    /** seconds for the answer of one AA - a login answers at once, and a dead AA must not use up the
     *  time of the request before the AAs after it are asked */
    private const TIMEOUT = 20;

    public function getName(): string
    {
        return _m('Get Token - one login for the selected AAs');
    }

    /** the login, sent with the action as akce_param[] */
    public function htmlSettings(): string
    {
        return '<div>'
            . '<label>' . _m('Username') . ': <input type="text" name="akce_param[username]" size="15" autocomplete="username"></label> '
            . '<label>' . _m('Password') . ': <input type="password" name="akce_param[password]" size="15" autocomplete="current-password"></label>'
            . '</div>';
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, #[\SensitiveParameter] $action_param): string|false
    {
        $username = is_string($action_param['username'] ?? null) ? trim($action_param['username']) : '';
        $password = is_string($action_param['password'] ?? null) ? $action_param['password'] : '';
        if (is_array($_POST['akce_param'] ?? null)) {
            unset($_POST['akce_param']['password']);   // used once, kept nowhere
        }
        if (($username === '') || ($password === '')) {
            return MsgErr(_m('Fill in the username and the password.'));
        }
        $rows = '';
        foreach ($item_zids->shortids() as $id) {
            if ($aa = Actionapps::find((int) $id)) {
                // the reason of a failure is written by the remote AA
                $failure = $aa->authenticate($username, $password, self::TIMEOUT);
                $badge   = ($failure === null)
                    ? '<span class="aa-auth aa-auth-' . Actionapps::BADGE_OK . '">' . _m('OK') . '</span>'
                    : '<span class="aa-auth aa-auth-' . Actionapps::BADGE_GET_TOKEN . '">' . safe($failure) . '</span>';
                $rows .= '<tr><td>' . safe($aa->getName()) . "</td><td>$badge</td></tr>";
            }
        }
        return '<table class="aa-table"><tr><th>' . _m('AA') . '</th><th>' . _m('Auth') . "</th></tr>$rows</table>";
    }

    public function isPerm4Action($manager): bool
    {
        return IsSuperadmin();
    }
}
