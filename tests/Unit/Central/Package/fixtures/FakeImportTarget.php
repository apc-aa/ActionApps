<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ImportTarget;
use AA\Central\Package\ItemLint;

/** An in-memory target AA for the planner and writer tests: the constant groups and alerts collections
 *  it has, the ids it hands out (scripted first, then counted: 00..0a001, 00..0a002, ...; K0001, K0002, ...).
 *  $groups and $collections are public: a test takes a name "between the plan and the write".
 *  $engines: table => engine of the tables that are not InnoDB. */
class FakeImportTarget implements ImportTarget
{
    /** @var list<string> every name asked about */
    public array $asked = [];
    /** @var array<string, string> table => engine, the tables that are not InnoDB */
    public array $engines = [];
    /** @var list<string> logins (login names, e-mails) of the target's users */
    public array $admins = [];
    /** @var array<string, array<string, bool>> field => login or e-mail of a target reader => the reader holds AA permissions */
    public array $readers = [];
    /** @var list<string> item ids the target keeps rights or content rows for */
    public array $inUse = [];
    /** @var list<string> hosts a site of the target answers to */
    public array $domains = [];
    /** @var list<string> the hosts the target runs at */
    public array $hosts = ['aa.target.example'];
    /** @var int how many times readerLogins() was asked */
    public int $readerQueries = 0;
    private int $ids = 0;
    private int $collectionCount = 0;

    /**
     * @param list<string> $groups              group names taken on the target
     * @param list<string> $scriptedIds         newId() answers before the counted ones
     * @param list<string> $scriptedCollections newCollectionId() answers before the counted ones
     * @param list<string> $collections         alerts collection ids taken on the target
     */
    public function __construct(
        public array $groups = [],
        private array $scriptedIds = [],
        private array $scriptedCollections = [],
        public array $collections = [],
    ) {}

    public function groupTaken(string $name, bool $lock = false): bool
    {
        $this->asked[] = $name;
        return in_array($name, $this->groups, true);
    }

    public function adminLoginTaken(string $login): bool
    {
        return in_array(ItemLint::loginKey($login), array_map(ItemLint::loginKey(...), $this->admins), true);
    }

    public function readerLogins(string $field, array $values): array
    {
        ++$this->readerQueries;
        $found = [];
        foreach ($this->readers[$field] ?? [] as $login => $perms) {
            if (in_array(ItemLint::loginKey((string) $login), array_map(ItemLint::loginKey(...), $values), true)) {
                $found[ItemLint::loginKey((string) $login)] = $perms;
            }
        }
        return $found;
    }

    public function itemIdsInUse(array $ids): array
    {
        return array_values(array_intersect($ids, $this->inUse));
    }

    /** @var list<string> ids of items this AA has */
    public array $items = [];

    public function existingItems(array $ids): int
    {
        return count(array_intersect($ids, $this->items));
    }

    public function domainTaken(string $host): bool
    {
        $bare = static fn(string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        return in_array($bare(strtolower($host)), array_map($bare, $this->domains), true);
    }

    public function ownHosts(): array
    {
        return $this->hosts;
    }

    public function newId(): string
    {
        return array_shift($this->scriptedIds) ?? sprintf('%032x', 0xa000 + ++$this->ids);
    }

    public function collectionTaken(string $id): bool
    {
        return in_array($id, $this->collections, true);
    }

    public function newCollectionId(): string
    {
        return array_shift($this->scriptedCollections) ?? sprintf('K%04d', ++$this->collectionCount);
    }

    public function nonTransactional(array $tables): array
    {
        $not = [];
        foreach ($tables as $table) {               // in the order asked
            if (isset($this->engines[$table])) {
                $not[$table] = $this->engines[$table];
            }
        }
        return $not;
    }
}
