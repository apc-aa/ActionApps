<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What one import has created on the target so far (design balicek-v1.md § 7.2 step 5, "deník") -
 *  the input of the compensation (§ 7.2 step 6, C4.4 part 4b) and of the report. Only what surely is
 *  ours goes in, and only once its row is written: a module after its `module` row (a colliding id
 *  must never lead the compensation to someone else's module), a group after its lt_groupNames row
 *  (with that row's id), a collection after its alerts_collection row. Rows found by those (field, slice, profile, site,
 *  constants, alerts_collection_*) are deleted through them; the AUTO_INCREMENT rows by their numbers -
 *  view and email rows are written before their module (their numbers go into the texts), alerts_filter
 *  and Alerts e-mails have no module column the module routines would find.
 *  Mutable, filled by RowWriter inside the transaction; after a failure ImportFailed carries it. */
final class ImportJournal
{
    /** @var array<string, string> new module id => package wrapper (Module::SLICE, SITE, ALERTS) */
    private array $modules = [];
    /** @var array<string, list<int>> table => AUTO_INCREMENT numbers written (view, email, alerts_filter, site_spot, profile) */
    private array $numbers = [];
    /** @var array<string, string> constant group name created on the target => id of its lt_groupNames row */
    private array $groups = [];
    /** @var list<string> alerts collection ids created on the target */
    private array $collections = [];
    /** @var list<array{string, string}> relation rows [source site, destination module] */
    private array $relations = [];
    /** what the writer was doing last - a finding path (package text: safe()) */
    private string $step = '';

    public function module(string $id, string $type): void
    {
        $this->modules[$id] = $type;
    }

    public function number(string $table, int $id): void
    {
        $this->numbers[$table][] = $id;
    }

    /** @param string $row the id of its lt_groupNames row - the compensation deletes that row by id: a name
     *                     compares in the char collation of lt_groupNames.value (case, trailing spaces) */
    public function group(string $name, string $row): void
    {
        $this->groups[$name] = $row;
    }

    public function collection(string $id): void
    {
        $this->collections[] = $id;
    }

    public function relation(string $source, string $destination): void
    {
        $this->relations[] = [$source, $destination];
    }

    public function step(string $path): void
    {
        $this->step = $path;
    }

    /** @return array<string, string> new module id => package wrapper */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @return array<string, list<int>> table => AUTO_INCREMENT numbers */
    public function numbers(): array
    {
        return $this->numbers;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return array_map('strval', array_keys($this->groups));
    }

    /** @return array<string, string> group name => id of its lt_groupNames row */
    public function groupRows(): array
    {
        return $this->groups;
    }

    /** @return list<string> */
    public function collections(): array
    {
        return $this->collections;
    }

    /** @return list<array{string, string}> */
    public function relations(): array
    {
        return $this->relations;
    }

    public function lastStep(): string
    {
        return $this->step;
    }

    /** true: nothing was written - there is nothing to compensate */
    public function isEmpty(): bool
    {
        return !$this->modules && !$this->numbers && !$this->groups && !$this->collections && !$this->relations;
    }
}
