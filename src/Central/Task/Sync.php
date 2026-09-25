<?php

declare(strict_types=1);

namespace AA\Central\Task;

use AA\Central\Actionapps;
use AA\Central\SerializedProps;

/** Sends one synchronization action to a remote AA (Central Synchronize) */
class Sync extends RemoteAaTask
{
    protected const LOG_NAME = 'AA_Task_Sync';

    /** @param mixed          $sync_action  serialized AA_Sync_Action - the remote AA gets the string as it is
     *  @param Actionapps|int $aa           the AA, or its central_conf id */
    public function __construct(public $sync_action, Actionapps|int $aa)
    {
        $this->setAa($aa);
    }

    /** the queue row: the action and the id of the AA - no login */
    public function __serialize(): array
    {
        return ['sync_action' => $this->sync_action] + $this->serializeAa();
    }

    public function __unserialize(array $data): void
    {
        $data              = SerializedProps::bare($data);
        $this->sync_action = $data['sync_action'] ?? null;
        $this->unserializeAa($data);
    }

    protected function send(Actionapps $aa): mixed
    {
        return $aa->synchronize([$this->sync_action]);   // one report line per action
    }
}
