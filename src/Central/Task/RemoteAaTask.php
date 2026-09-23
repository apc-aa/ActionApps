<?php

declare(strict_types=1);

namespace AA\Central\Task;

use AA\Central\Actionapps;
use AA\Later\LaterInterface;
use AA_Logentry;
use AA_Logentry_Obj;

/**
 * A Central task queued in toexecute (the Task Manager) which sends something to one remote AA.
 *
 * The queue row keeps only the central_conf id of the AA (aa_id) - the URL and the login are read
 * from central_conf when the task runs, so no password or token is stored in the queue.
 */
abstract class RemoteAaTask implements LaterInterface
{
    /** the class name the task had before AA\Central\Task - the Task Manager log keeps it */
    protected const LOG_NAME = '';

    /** central_conf.id of the AA */
    public ?int $aa_id = null;

    /** The AA object - never serialized. Set when the task is built from an AA (it runs with it when
     *  run directly), or by a row queued before the tasks carried aa_id whose AA has no id. */
    public ?Actionapps $actionapps = null;

    /** sends the task to the AA; @return mixed the remote answer - report lines or an error string */
    abstract protected function send(Actionapps $aa): mixed;

    /**
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged, one line per report line
     */
    final public function toexecutelater($params = []): string
    {
        $aa = $this->actionapps ?? (($this->aa_id === null) ? null : Actionapps::find($this->aa_id));
        if ($aa === null) {
            return _m('Err: no central_conf row for the AA (id %1) - the task is not run', [(string) $this->aa_id]);
        }
        // the return type is string - a TypeError would leave the task in the queue and send it again next run
        return implode("\n", array_filter((array) $this->send($aa), 'is_scalar'));
    }

    /** the Task Manager log (AA_Log::selector()) names the task as before the move */
    public function logSelector(): AA_Logentry
    {
        return AA_Logentry_Obj::make(static::LOG_NAME);
    }

    /** @param Actionapps|int $aa  the AA, or its central_conf id */
    protected function setAa(Actionapps|int $aa): void
    {
        $this->actionapps = ($aa instanceof Actionapps) ? $aa : null;
        $this->aa_id      = ($aa instanceof Actionapps) ? $aa->getId() : $aa;
    }

    /** the AA part of the queue row - the id only
     *  @return array{aa_id: int} */
    protected function serializeAa(): array
    {
        if ($this->aa_id === null) {
            // it would run as "no central_conf row" - an AA not loaded from central_conf can't be queued
            throw new \LogicException(static::LOG_NAME . ': the AA has no central_conf id - the task can not be queued');
        }
        return ['aa_id' => $this->aa_id];
    }

    /** The AA of a queued row: aa_id, or the AA object embedded in rows queued before (AA_Task_*).
     *  An embedded AA with its central_conf id is read again from central_conf when the task runs:
     *  its current URL and login count, and a task whose central_conf row was deleted does not run.
     *  Only an embedded AA without an id is used as it is - there is nothing else to go by. */
    protected function unserializeAa(array $data): void
    {
        $this->aa_id      = isset($data['aa_id']) ? (int) $data['aa_id'] : null;
        $this->actionapps = null;
        $legacy           = $data['actionapps'] ?? null;
        if ($legacy instanceof Actionapps) {
            $id = $legacy->getId();
            if ($id === null) {
                $this->actionapps = $legacy;
            } else {
                $this->aa_id = $id;
            }
        }
    }
}
