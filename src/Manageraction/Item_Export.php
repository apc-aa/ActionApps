<?php

namespace AA\Manageraction;

use AA\Manager;
use AA_Object;
use zids;

/** Manager action Export - Exports selected items to Excel file */
class Item_Export extends AbstractManageraction {

    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Export to file');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {

        if ($item_zids->count() > 0) {
            $exportset = AA_Object::factory('AA_Exportsetings', [
                'grabber_type' => 'AA\IO\Grabber\Slice',
                'format'       => 'AA_Exporter_Excel',
                'type'         => 'human',
                'bins'         => AA_BIN_ALL,
            ]);
            $exportset->setOwnerId($manager->getModuleId());
            $exportset->export($item_zids);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}