<?php

declare(strict_types=1);

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action of the Central list: opens central/optimize.php with the selected AAs - the task is
 *  chosen and started there, and it runs in the task queue (AA\Central\OptimizeRun). */
class Central_OptimizeRun extends AbstractManageraction
{
    public function getName(): string
    {
        return _m('Optimize - run a test or repair...');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        // only the choice - the page asks again before anything runs
        go_url(get_url(self_base() . 'optimize.php', ['aa' => implode(',', array_map('intval', $item_zids->shortids()))], true));
    }

    public function isPerm4Action($manager): bool
    {
        return IsSuperadmin();
    }
}
