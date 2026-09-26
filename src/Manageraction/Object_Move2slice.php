<?php

namespace AA\Manageraction;

use AA;
use AA\Managed_Object;
use AA\Manager;
use AA_Object;
use zids;

/** Manager action Object_Move2slice - quickly adapted Item_Move2slice (for AA_Plannedtask)
 *  Move objects to another slice
 * @param $slice       - slice object - slice, from which we export
 * @param $item_arr    - array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 * @param $akce_param  - unpacked id of slice, where items should be moved
 */
class Object_Move2slice extends \AA\Manageraction\Item_Move2Slice
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Move to another slice');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (!is_long_id($dest_slice_id = $action_param['dest_slice_id'])) {
            return _m('No slice selected');
        }

        if (!IfSlPerm(PS_FULLTEXT, $this->slice_id) || !IfSlPerm(PS_FULLTEXT, $dest_slice_id)) {    // permission to delete items and add items in destination slice?
            return _m("You do not have permissions to move it");
        }

        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Object) || !is_long_id($manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }
        $class = $managed->getClass();    // AA_Plannedtask
        [$zids2change, $refused] = $managed->actionable($manager, $item_zids, PS_FULLTEXT);
        foreach ($zids2change as $id) {
            AA_Object::load($id, $class)?->setOwnerId($dest_slice_id)->update(['aa_owner']);
        }

        AA::Pagecache()->invalidateFor([$this->slice_id, $dest_slice_id]);  // invalidate old cached values
        return $refused ? _m('Some of the selected objects were not moved - they belong to a module you can not change them in.') : false;
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IfSlPerm(PS_FULLTEXT, $this->slice_id);
    }
}
