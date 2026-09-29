<?php

namespace AA\Manageraction;

use AA\Managed_Object;
use AA\Manager;
use AA_Object;
use zids;

/**  Delete specified object
 */
class Object_Delete extends AbstractManageraction
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
            'label' => _m('Delete'),
            'attr'  => ['class' => 'aa-button-delete',
                'onclick' => "return confirm('" . _m("Do you really want to delete this row?") . "')"],
        ];
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {

        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);
        // we delete one after another

        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Object) || !is_long_id($manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }
        [$zids2delete, $refused] = $managed->actionable($manager, $item_zids, PS_EDIT);
        if (!$zids2delete->isEmpty()) {
            /** @var class-string<AA_Object> $class */
            $class = $managed->getClass();
            $class::deleteObjects($zids2delete->getArray());
        }
        return $refused ? _m('Some of the selected objects were not deleted - they belong to a module you can not change them in.') : false;
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param  Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}
