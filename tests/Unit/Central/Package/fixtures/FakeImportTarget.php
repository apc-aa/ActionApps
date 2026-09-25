<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ImportTarget;

/** An in-memory target AA for the planner and writer tests: the constant groups and alerts collections
 *  it has, the ids it hands out (scripted first, then counted: 00..0a001, 00..0a002, ...; K0001, K0002, ...).
 *  $groups and $collections are public: a test takes a name "between the plan and the write".
 *  $engines: table => engine of the tables that are not InnoDB. The named lock: $lockFree = false = held
 *  by someone else; $locks logs lock / unlock calls, $onLock / $onUnlock run when it is taken / released. */
final class FakeImportTarget implements ImportTarget
{
    /** @var list<string> every name asked about */
    public array $asked = [];
    /** @var array<string, string> table => engine, the tables that are not InnoDB */
    public array $engines = [];
    public bool $lockFree = true;
    /** thrown by lock() - it could not be asked for */
    public ?\Throwable $lockError = null;
    /** @var list<string> "lock <name> <timeout>" / "unlock <name>" */
    public array $locks = [];
    /** @var null|\Closure(): void */
    public ?\Closure $onLock = null;
    /** @var null|\Closure(): void */
    public ?\Closure $onUnlock = null;
    /** @var list<string> login names taken on the target (users, readers) */
    public array $usernames = [];
    /** @var list<string> reader e-mails taken on the target */
    public array $emails = [];
    private ?string $held = null;
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

    public function usernameTaken(string $username): bool
    {
        return in_array($username, $this->usernames, true);
    }

    public function emailTaken(string $email): bool
    {
        return in_array($email, $this->emails, true);
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

    public function lock(string $name, int $timeout): bool
    {
        $this->locks[] = "lock $name $timeout";
        if ($this->lockError) {
            throw $this->lockError;
        }
        if (!$this->lockFree || ($this->held !== null)) {
            return false;
        }
        $this->held = $name;
        $this->onLock && ($this->onLock)();
        return true;
    }

    public function unlock(string $name): void
    {
        $this->locks[] = "unlock $name";
        if ($this->held === $name) {
            $this->held = null;
            $this->onUnlock && ($this->onUnlock)();
        }
    }

    public function holds(): ?string
    {
        return $this->held;
    }
}
