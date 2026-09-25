<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\DbImportTarget;
use AA\Central\Package\ImportTarget;

/** DbImportTarget over TableDb for the questions - its SQL on the DB handle of the transaction - and
 *  FakeImportTarget for the new ids (predictable). TableDb has no engines: nonTransactional() is empty. */
final class TableDbTarget implements ImportTarget
{
    private readonly DbImportTarget $db;

    public function __construct(private readonly FakeImportTarget $ids)
    {
        $this->db = new DbImportTarget();
    }

    public function groupTaken(string $name, bool $lock = false): bool
    {
        return $this->db->groupTaken($name, $lock);
    }

    public function collectionTaken(string $id): bool
    {
        return $this->db->collectionTaken($id);
    }

    public function nonTransactional(array $tables): array
    {
        return [];
    }

    public function adminLoginTaken(string $login): bool
    {
        return $this->db->adminLoginTaken($login);
    }

    public function readerLogins(string $field, array $values): array
    {
        return $this->db->readerLogins($field, $values);
    }

    public function itemIdsInUse(array $ids): array
    {
        return $this->db->itemIdsInUse($ids);
    }

    public function domainTaken(string $host): bool
    {
        return $this->db->domainTaken($host);
    }

    public function ownHosts(): array
    {
        return $this->ids->ownHosts();
    }

    public function newId(): string
    {
        return $this->ids->newId();
    }

    public function newCollectionId(): string
    {
        return $this->ids->newCollectionId();
    }
}
