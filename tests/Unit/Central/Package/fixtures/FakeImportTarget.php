<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ImportTarget;

/** An in-memory target AA for the planner and writer tests: the constant groups and alerts collections
 *  it has, the ids it hands out (scripted first, then counted: 00..0a001, 00..0a002, ...; K0001, K0002, ...).
 *  $groups and $collections are public: a test takes a name "between the plan and the write". */
final class FakeImportTarget implements ImportTarget
{
    /** @var list<string> every name asked about */
    public array $asked = [];
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
}
