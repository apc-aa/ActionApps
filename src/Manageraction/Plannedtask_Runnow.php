<?php

declare(strict_types=1);

namespace AA\Manageraction;

use AA\Later\Toexecute;
use AA\Managed_Object;
use AA\Manager;
use AA_Object;
use AA_Plannedtask;
use zids;

/** Queues the selected planned tasks to run at once. A selector of its own keeps the scheduled run as it is. */
class Plannedtask_Runnow extends AbstractManageraction
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Run now');
    }

    /** @inheritDoc */
    protected function getButtonDef(): array
    {
        return [
            'label'   => _m('Run now'),
            'attr'    => ['class' => 'aa-button-run'],
            'confirm' => _m('Run the task now? It may send e-mails or change data.'),
        ];
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if ($item_zids->isEmpty()) {
            return false;
        }
        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Object) || ($managed->getClass() !== AA_Plannedtask::class) || !is_long_id($manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }
        [$zids, $refused] = $managed->actionable($manager, $item_zids, PS_FORMS);
        $msg = $refused ? [_m('Some of the selected tasks were not run - they belong to a module you can not change them in.')] : [];

        $toexecute = new Toexecute();
        foreach ($zids->longids() as $id) {
            $task = AA_Object::load($id, AA_Plannedtask::class);
            if (!($task instanceof AA_Plannedtask)) {
                continue;
            }
            // an import belongs to who manages the feeding of the slice, not to everyone who may edit tasks
            if ($task->usesImport() && !IfSlPerm(PS_FEEDING, $task->getOwnerId())) {
                $msg[] = _m('The task %1 was not run - only a user who manages the feeding of the slice may run a task with {import}.', [safe((string) $task->getName())]);
                continue;
            }
            $selector = AA_Plannedtask::toexecuteSelector($id) . ':now';
            // not laterOnce(): it would move the time of a row a runner has already claimed
            if (Toexecute::scheduledTask($selector)['id']) {
                $msg[] = _m('The task %1 is already waiting in the queue.', [safe((string) $task->getName())]);
                continue;
            }
            $toexecute->later($task, [], $selector, 100);
        }
        return $msg ? implode('<br>', $msg) : false;
    }

    /** @inheritDoc */
    public function isPerm4Action($manager): bool
    {
        return IfSlPerm(PS_FORMS);
    }
}
