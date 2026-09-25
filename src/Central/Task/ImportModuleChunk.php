<?php

declare(strict_types=1);

namespace AA\Central\Task;

use AA\Central\Actionapps;
use AA\Central\SerializedProps;
use AA_Definition_Module_Chunk;

/** Imports one chunk of a module definition to a remote AA (Central Copy Slice) */
class ImportModuleChunk extends RemoteAaTask
{
    protected const LOG_NAME = 'AA_Task_Import_Module_Chunk';

    protected ?AA_Definition_Module_Chunk $module_definition_chunk;

    /** @param Actionapps|int $aa  the AA, or its central_conf id */
    public function __construct(AA_Definition_Module_Chunk $module_definition_chunk, Actionapps|int $aa)
    {
        $this->module_definition_chunk = $module_definition_chunk;
        $this->setAa($aa);
    }

    /** the queue row: the chunk and the id of the AA - no login */
    public function __serialize(): array
    {
        return ['module_definition_chunk' => $this->module_definition_chunk] + $this->serializeAa();
    }

    public function __unserialize(array $data): void
    {
        $data                          = SerializedProps::bare($data);
        $this->module_definition_chunk = $data['module_definition_chunk'] ?? null;
        $this->unserializeAa($data);
    }

    protected function send(Actionapps $aa): mixed
    {
        return $this->module_definition_chunk
            ? $aa->importModuleChunk($this->module_definition_chunk)
            : _m('Err: the task has no module definition chunk');
    }
}
