<?php

namespace AA\Manageraction;

use AA\Manager;
use AA_Object;
use AA_Set;
use zids;

/**  Delete specified object
 */
class Object_Delete extends AbstractManageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Delete row');
    }

    /** @inheritDoc */
    protected function getButtonDef(): array {
        return [
            'label' => _m('Delete'),
            'class' => 'aa-button-delete',
            'attr'  => ['onclick' => "return confirm('" . _m("Do you really want to delete this row?") . "')"],
        ];
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);
        // we delete one after another

        /** @var AA_Object $class */
        if ($class = $manager->getManaged()->getClass()) {
            if ($zids2delete = $class::querySet($class, new AA_Set(), $item_zids)) {  // test if all zids are of correct type
                //huhl($class, $item_zids, $zids2delete);
                $class::deleteObjects($zids2delete->getArray());
            }
        }
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}