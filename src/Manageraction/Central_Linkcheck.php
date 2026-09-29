<?php

namespace AA\Manageraction;

use AA\Central\Actionapps;
use AA\Manager;
use AA\Util\Linkcheck;
use AA_Request;
use zids;

/** AA\Manageraction\Central_Linkcheck - checks if the AA are acessible */
class Central_Linkcheck extends AbstractManageraction
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Check the AA availability');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (count($item_zids) < 1) {
            return false;                                     // OK - no error
        }
        $results   = [];
        $results[] = [
            '<b>' . _m('AA (Organization)') . '</b>',
            '<b>' . _m('URL') . '</b>',
            '<b>' . _m('Status code') . '</b>',
            '<b>' . _m('Description') . '</b>',
            '<b>' . _m('Auth') . '</b>',
        ];
        $linkcheck = new Linkcheck();

        foreach ($item_zids as $aa_id) {
            $aa        = Actionapps::getActionapps($aa_id);
            $url       = $aa->getComunicatorUrl();
            $status    = $linkcheck->check_url($url);
            // a command every AA answers - shows whether the stored token logs in
            $response  = $aa->getResponse(new AA_Request('Get_Aaname'));
            $auth      = $response->isError() ? Actionapps::responseText($response) : _m('OK');
            $results[] = [$aa->getName(), $url, $status['code'], $status['comment'], $auth];
            // $ret[] = $aa->doOptimize($this->optimize_class, $this->optimize_method);
        }
        // 'th' keeps the header row markup, 'td' escapes the data - the checked AA controls the comment and the response
        return GetHtmlTable($results, 'th', '', 'td') . "<br>";                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IsSuperadmin();
    }
}
