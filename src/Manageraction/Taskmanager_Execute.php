<?php

namespace AA\Manageraction;

use AA\Later\Toexecute;
use AA\Manager;
use zids;

/** Taskmanager_Execute - runs the selected tasks of the queue now */
class Taskmanager_Execute extends AbstractManageraction
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Execute');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {

        if (!IfSlPerm(PS_EDIT)) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You do not have permissions to remove items");
        }

        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        $toexecute = new Toexecute();
        $toexecute->executeTask($item_zids->shortids(), self::expectedPriorities($manager->getPostedChb()));
        return $toexecute->report();
    }

    /** Parse the priorities the UI displayed from the manager checkbox values
     *  ('p-<n>' token, see GetToexecuteManagerSettings()) - task_id => priority.
     *  The value is a token string so managers can pass more hints later;
     *  values without the token (select-all, row buttons) yield no expectation.
     * @param array $chb - Manager::getPostedChb(): 'x<task_id>' => 'p-<priority>'
     */
    private static function expectedPriorities(array $chb): array
    {
        $expected = [];
        foreach ($chb as $key => $value) {
            if (is_string($value) && preg_match('/\bp-(\d+)\b/', $value, $m)) {
                $key = (string) $key;
                $id = rawurldecode(str_starts_with($key, 'x') ? substr($key, 1) : $key);
                $expected[(int) $id] = (int) $m[1];
            }
        }
        return $expected;
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
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return IfSlPerm(PS_EDIT);
    }


    /** @inheritDoc */
    protected function getButtonDef(): array
    {
        return [
            'label' => $this->getName(),
            'attr'  => ['class' => 'aa-button-run'],
        ];
    }
}
