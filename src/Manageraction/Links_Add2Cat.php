<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_Add2Cat extends AbstractManageraction
{
    /** Constructor - fills the information about the target bin */
    public function __construct($id, string $url)
    {
        parent::__construct($id);
        $this->setOpenUrl($url);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Add to category');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (ctype_digit((string) $action_param)) {
            foreach ($item_zids as $lid) {
                Links_Assign2Category($lid, (int) $action_param);
            }
        }
        return false;                                     // OK - no error
    }
}
