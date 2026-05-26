<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_Tab extends AbstractManageraction
{
    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        $GLOBALS['r_state']['bin'] = $action_param;
        $manager->go2page(1);
        return false;     // OK - no errors
    }
}
