<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the import plan (C4.4 part 3, Planner) asks of the target AA - the only seam to it, so the
 *  planner stays pure: DbImportTarget reads this AA's DB, tests use a fake. Reads only. */
interface ImportTarget
{
    /** true: a constant group of this name exists on the target - in lt_groupNames, constant_slice or
     *  as the group of any constant (design balicek-v1.md § 7.1: never merge into it).
     *  $lock (the writer, inside its transaction, just before it creates the group): a locking read
     *  (SELECT ... FOR UPDATE) - InnoDB keeps a concurrent insert of the name out until the commit */
    public function groupTaken(string $name, bool $lock = false): bool;

    /** a new long id, 32 lower-case hex - new_id() */
    public function newId(): string;

    /** a new alerts collection id free on the target, 5 letters or digits - new_collection_id() */
    public function newCollectionId(): string;

    /** true: an alerts collection of this id exists on the target (alerts_collection.id, binary: case
     *  counts) - the writer asks again inside its transaction, the plan is no reservation (ImportPlan) */
    public function collectionTaken(string $id): bool;
}
