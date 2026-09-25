<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What one import has created on the target so far (design balicek-v1.md § 7.2 step 5, "deník") -
 *  the input of the compensation (§ 7.2 step 6, C4.4 part 4b) and of the report. Only what surely is
 *  ours goes in, and only once its row is written: a module after its `module` row (a colliding id
 *  must never lead the compensation to someone else's module), a group after its lt_groupNames row
 *  (with that row's id), its constant_slice row after its insert (an unowned one is found by nothing
 *  else), a collection after its alerts_collection row (with its Alerts module). Rows found by those (field, slice, profile, site,
 *  constants, alerts_collection_*) are deleted through them; the AUTO_INCREMENT rows by their numbers -
 *  view and email rows are written before their module (their numbers go into the texts), alerts_filter
 *  and Alerts e-mails have no module column the module routines would find.
 *  Objects (ObjectWriter) by their ids once their first row is written - object_text has no unique key, so
 *  no insert proves an id ours: they are the plan's new_id()s and the derived settings ids of new modules,
 *  never an id of the package or the target; items (ItemWriter) once their `item` row is written (PRIMARY:
 *  a colliding id fails there), their content rows after it - found by the item id.
 *  Mutable, filled by RowWriter, ObjectWriter and ItemWriter inside the transaction; after a failure
 *  ImportFailed carries it. */
final class ImportJournal
{
    /** @var array<string, string> new module id => package wrapper (Module::SLICE, SITE, ALERTS) */
    private array $modules = [];
    /** @var array<string, list<int>> table => AUTO_INCREMENT numbers written (view, email, alerts_filter, site_spot, profile) */
    private array $numbers = [];
    /** @var array<string, string> constant group name created on the target => id of its lt_groupNames row */
    private array $groups = [];
    /** @var array<string, true> groups whose constant_slice row this import inserted (it may fail: the name
     *                           is taken since the check - a foreign row of the name then stays) */
    private array $groupSlices = [];
    /** @var array<string, string> alerts collection id created on the target => its new Alerts module */
    private array $collections = [];
    /** @var list<array{string, string}> relation rows [source site, destination module] */
    private array $relations = [];
    /** @var array<string, array<string, true>> owner module => ids of the objects with a row written */
    private array $objects = [];
    /** @var array<string, array<string, true>> slice => ids of the items whose `item` row is written */
    private array $items = [];
    /** content rows written - a count for the report only: the compensation finds them by the item ids */
    private int $contentRows = 0;
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

    public function groupSlice(string $name): void
    {
        $this->groupSlices[$name] = true;
    }

    public function collection(string $id, string $module): void
    {
        $this->collections[$id] = $module;
    }

    public function relation(string $source, string $destination): void
    {
        $this->relations[] = [$source, $destination];
    }

    /** an object (or subobject) of the new module $owner - once one of its rows is written; again = nothing */
    public function object(string $id, string $owner): void
    {
        $this->objects[$owner][$id] = true;
    }

    /** an item of the new slice - once its `item` row is written */
    public function item(string $id, string $slice): void
    {
        $this->items[$slice][$id] = true;
    }

    /** a content row of an item is written */
    public function contentRow(): void
    {
        ++$this->contentRows;
    }

    public function contentRows(): int
    {
        return $this->contentRows;
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

    /** @return list<string> groups with our constant_slice row */
    public function groupSlices(): array
    {
        return array_map('strval', array_keys($this->groupSlices));
    }

    /** @return list<string> */
    public function collections(): array
    {
        return array_map('strval', array_keys($this->collections));
    }

    /** @return array<string, string> collection id => its Alerts module */
    public function collectionModules(): array
    {
        return $this->collections;
    }

    /** @return list<array{string, string}> */
    public function relations(): array
    {
        return $this->relations;
    }

    /** @return array<string, list<string>> owner module => object ids, in the order of their first row */
    public function objects(): array
    {
        return array_map(static fn(array $ids): array => array_map('strval', array_keys($ids)), $this->objects);
    }

    /** @return array<string, list<string>> slice => item ids, in write order */
    public function items(): array
    {
        return array_map(static fn(array $ids): array => array_map('strval', array_keys($ids)), $this->items);
    }

    public function lastStep(): string
    {
        return $this->step;
    }

    /** true: nothing was written - there is nothing to compensate */
    public function isEmpty(): bool
    {
        return !$this->modules && !$this->numbers && !$this->groups && !$this->collections && !$this->relations && !$this->objects && !$this->items;
    }
}
