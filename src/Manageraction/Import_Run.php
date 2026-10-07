<?php

declare(strict_types=1);

namespace AA\Manageraction;

use AA\Import\Definition;
use AA\Import\Run;
use AA\Import\Service;
use AA\Managed_Object;
use AA\Manager;
use zids;

/** Queues a run of each selected import, as a start by hand of the user. The run works in the queue,
 *  never in this request. */
class Import_Run extends AbstractManageraction
{
    /** the action id; the row of the imports Manager shows its button */
    public const ID = 'Run';

    public function getName(): string
    {
        return _m('Run import');
    }

    protected function getButtonDef(): array
    {
        return [
            'label'   => _m('Run'),
            'attr'    => ['class' => 'aa-button-run'],
            'confirm' => _m('Run the import now? It stores the items of the source into the slice. The run goes on in the background; the import detail shows its progress.'),
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
        $msg = self::startAll(array_map('strval', $defs->longids()), AuthGetUid());
        if ($refused) {
            $msg[] = _m('Some of the selected objects were not run - they belong to a module you can not change them in.');
        }
        return $msg ? implode('<br>', $msg) : false;
    }

    /**
     * Starts a run of each definition of $ids that is stored; Service checks the right of $uid.
     * @param list<string> $ids
     * @return list<string> what happened, for the user - refusals by their codes only, the source's
     *                      own words may carry its data
     */
    public static function startAll(array $ids, string $uid): array
    {
        $queued  = 0;
        // by what is in the way: a running run, one stopped by an error, a finished one whose last step still works
        $busy    = ['running' => 0, 'error' => 0, 'finishing' => 0];
        $refused = [];
        foreach ($ids as $id) {
            // deleted meanwhile - as a delete of it would, it says nothing
            $def = Definition::fresh($id);
            if ($def === null) {
                continue;
            }
            try {
                $r = Service::start($def, 'run', [], 'manual', $uid);
            } catch (\Throwable $e) {
                Definition::logError($id, $e);
                $refused[] = 'FAILED';
                continue;
            }
            match ($r->code) {
                'queued' => ++$queued,
                'busy'   => ++$busy[match ($r->reason) {
                    'LEASE'    => 'finishing',
                    Run::ERROR => 'error',
                    default    => 'running',
                }],
                default  => $refused[] = ($r->reason !== '') ? $r->reason : strtoupper($r->code),
            };
        }
        $msg = [];
        if ($queued) {
            $msg[] = _m('Imports queued to run: %1. The import detail shows their progress.', [$queued]);
        }
        if ($busy['running']) {
            $msg[] = _m('Imports not started - their last run is still running: %1.', [$busy['running']]);
        }
        if ($busy['error']) {
            $msg[] = _m('Imports not started - their last run stopped by an error; continue or end it in the import detail: %1.', [$busy['error']]);
        }
        if ($busy['finishing']) {
            $msg[] = _m('Imports not started - the last step of their previous run is still finishing; try again in a few minutes: %1.', [$busy['finishing']]);
        }
        if ($refused) {
            $msg[] = _m('Imports not started: %1 (%2). The import detail tells more.', [count($refused), safe(implode(', ', array_unique($refused)))]);
        }
        return $msg;
    }

    /** the one rule of imports, on the slice of the page - or on the overview of all slices */
    public function isPerm4Action($manager): bool
    {
        return Definition::mayActOn($manager);
    }
}
