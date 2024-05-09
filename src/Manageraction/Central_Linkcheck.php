<?php

namespace AA\Manageraction;

use AA\Manager;
use AA\Util\Linkcheck;
use AA_Actionapps;
use zids;

/** AA\Manageraction\Central_Linkcheck - checks if the AA are acessible */
class Central_Linkcheck extends AbstractManageraction {

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Check the AA availability');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
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
        $linkcheck = new Linkcheck;

        foreach ($item_zids as $aa_id) {
            $aa        = AA_Actionapps::getActionapps($aa_id);
            $url       = $aa->getComunicatorUrl();
            $status    = $linkcheck->check_url($url);
            $resp      = $aa->_authenticate();
            $results[] = [$aa->getName(), $url, $status['code'], $status['comment'], print_r($resp, true)];
            // $ret[] = $aa->doOptimize($this->optimize_class, $this->optimize_method);
        }
        return GetHtmlTable($results) . "<br>";                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}