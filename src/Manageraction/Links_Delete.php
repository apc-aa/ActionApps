<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_Delete extends AbstractManageraction
{
    /** Constructor - fills the information about the target bin */
    public function __construct($id)
    {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Remove from category');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        foreach ($item_zids as $lid) {
            Links_DeleteLink($lid);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm(PS_LINKS_DELETE_LINK, $GLOBALS['r_state']['cat_path']);
    }
}
