<?php

namespace AA\Manageraction;

use AA;
use AA\Manager;
use AA_Object;
use zids;

/**  Delete specified table row  */
class Table_Row_Delete extends AbstractManageraction {

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Delete row');
    }

    /** @inheritDoc */
    protected function getButtonDef(): array {
        return [
            'label' => _m('Delete'),
            'attr'  => ['class'=>'aa-button-delete',
                        'onclick' => "return confirm('" . _m("Do you really want to delete this row?") . "')"],
        ];
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        /** @var AA_Object $class */
        if ($table = $manager->getManaged()->getTable()) {
            if (count($keys = AA::Metabase()->getKeys($table)) == 1) {
                foreach ($item_zids as $id) {
                    AA::Metabase()->doDelete($table, [$keys[0] => $id]);
                }
            }
        }

        // $err["DB"] = MsgErr("Can't delete view");
        // $Msg = MsgOk(_m("View successfully deleted"));
        // AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    function isPerm4Action($manager):bool {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}