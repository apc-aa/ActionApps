<?php

namespace AA\Manageraction;

use AA;
use AA\Managed_Object;
use AA\Manager;
use AA_Object;
use AA_Plannedtask;
use AA_Slice;
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
        $dest_slice_id = is_array($action_param) ? ($action_param['dest_slice_id'] ?? '') : '';
        if (!is_string($dest_slice_id) || !is_long_id($dest_slice_id)) {
            return _m('No slice selected');
        }
        $dest_slice_id = strtolower($dest_slice_id);
        if (!AA_Slice::isStoredSlice($dest_slice_id)) {    // the letters of the rights mean other things in other module types
            return _m('Tasks can be moved only to a slice.');
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
        $msg = $refused ? [_m('Some of the selected objects were not moved - they belong to a module you can not change them in.')] : [];
        $feeding = IfSlPerm(PS_FEEDING, $dest_slice_id);
        foreach ($zids2change as $id) {
            $object = AA_Object::load($id, $class);
            // an import belongs to the users who manage the feeding - also in the slice the task moves to
            if (($object instanceof AA_Plannedtask) && !$feeding && $object->usesImport()) {
                $msg[] = _m('The task %1 was not moved - only a user who manages the feeding of the slice may move a task with {import} there.', [safe((string) $object->getName())]);
                continue;
            }
            $object?->setOwnerId($dest_slice_id)->update(['aa_owner']);
        }

        AA::Pagecache()->invalidateFor([$this->slice_id, $dest_slice_id]);  // invalidate old cached values
        return $msg ? implode('<br>', $msg) : false;
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
