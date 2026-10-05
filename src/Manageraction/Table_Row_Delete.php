<?php

namespace AA\Manageraction;

use AA;
use AA\Managed_Table;
use AA\Manager;
use zids;

/**  Delete specified table row  */
class Table_Row_Delete extends AbstractManageraction
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Delete row');
    }

    /** @inheritDoc */
    protected function getButtonDef(): array
    {
        return [
            'label'   => _m('Delete'),
            'attr'    => ['class' => 'aa-button-delete'],
            'confirm' => _m("Do you really want to delete this row?"),
        ];
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Table)) {
            return _m('This action does not work on this page.');
        }
        [$ids, $refused] = $managed->actionable($manager, $item_zids);
        $table = $managed->getTable();
        if (count($keys = AA::Metabase()->getKeys($table)) == 1) {
            foreach ($ids as $id) {
                AA::Metabase()->doDelete($table, [$keys[0] => $id]);
            }
        }

        // $err["DB"] = MsgErr("Can't delete view");
        // $Msg = MsgOk(_m("View successfully deleted"));
        // AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        return $refused ? _m('Some of the selected rows were not deleted - they belong to another module.') : false;
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}
