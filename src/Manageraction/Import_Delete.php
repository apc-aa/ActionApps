<?php

declare(strict_types=1);

namespace AA\Manageraction;

use AA\Import\Definition;
use AA\Later\BatchFailed;
use AA\Managed_Object;
use AA\Manager;
use zids;

/** Deletes import definitions together with their runs, stream state, catalog and queued steps -
 *  Object_Delete would delete the definition alone and leave those orphaned. */
class Import_Delete extends AbstractManageraction
{
    public function getName(): string
    {
        return _m('Delete row');
    }

    protected function getButtonDef(): array
    {
        return [
            'label'   => _m('Delete'),
            'attr'    => ['class' => 'aa-button-delete'],
            'confirm' => _m('Do you really want to delete this import? Its runs, its stream state and its catalog are deleted with it; a running import stops.'),
        ];
    }

    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if ($item_zids->isEmpty()) {
            return false;
        }
        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Object) || ($managed->getClass() !== Definition::class) || !is_long_id($manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }
        [$defs, $refused] = $managed->actionable($manager, $item_zids, PS_FEEDING);
        $failed = false;
        foreach ($defs->longids() as $id) {
            // one that fails (a lost right, the database) does not keep the others
            try {
                Definition::fresh((string) $id)?->deleteWithRuns(AuthGetUid());
            } catch (BatchFailed) {
                // a right lost meanwhile - a refusal, no fault to log
                $failed = true;
            } catch (\Throwable $e) {
                $failed = true;
                Definition::logError((string) $id, $e);
            }
        }
        $msg = [];
        if ($failed) {
            $msg[] = _m('Some of the selected imports were not deleted.');
        }
        if ($refused) {
            $msg[] = _m('Some of the selected objects were not deleted - they belong to a module you can not change them in.');
        }
        return $msg ? implode(' ', $msg) : false;
    }

    /** the one rule of imports, on the slice of the page */
    public function isPerm4Action($manager): bool
    {
        return Definition::mayImportInto(AuthGetUid(), (string) $manager->getModuleId());
    }
}
