<?php

declare(strict_types=1);

namespace AA\Central;

/** When central/index.php runs a Manager action (akce). The actions reach remote AAs with the stored
 *  superadmin login, so a forged cross-site link or form must not start them. Pure rules, no state. */
class ManagerActionPolicy
{
    /** The bulk actions come only from the POSTed items form. A row button (Activate, Hold, Trash,
     *  Delete) is a GET link of the same page, so it may come by GET - but not from another site.
     *  @param array $server     $_SERVER
     *  @param bool  $row_button the action has a per-row button (AbstractManageraction::getButton()) */
    public static function mayPerform(array $server, bool $row_button): bool
    {
        if (ResponderPolicy::isForeignBrowserRequest($server)) {
            return false;
        }
        return $row_button || (($server['REQUEST_METHOD'] ?? '') === 'POST');
    }
}
