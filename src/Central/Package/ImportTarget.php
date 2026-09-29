<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the planner and the writer ask of the target AA - the only seam to it, so the planner stays pure:
 *  DbImportTarget reads this AA's DB, tests use a fake. Reads only. */
interface ImportTarget
{
    /** true: a constant group of this name exists on the target - in lt_groupNames, constant_slice or
     *  as the group of any constant (an import never merges into it).
     *  $lock: a locking read (SELECT ... FOR UPDATE) - InnoDB keeps a concurrent insert of the name out
     *  until the commit.
     *  @throws \RuntimeException the read failed (a lock wait timeout) - never "free" */
    public function groupTaken(string $name, bool $lock = false): bool;

    /** a new long id, 32 lower-case hex - new_id() */
    public function newId(): string;

    /** a new alerts collection id free on the target, 5 letters or digits - new_collection_id() */
    public function newCollectionId(): string;

    /** true: an alerts collection of this id exists on the target (alerts_collection.id, binary: case
     *  counts) - the writer asks again inside its transaction, the plan is no reservation (ImportPlan) */
    public function collectionTaken(string $id): bool;

    /** true: a user of this AA logs in with $login (UTF-8) - as the login name or the e-mail, any case, trailing
     *  spaces ignored; the readers left out (AA::$perm->isAdminUsernameFree()). A reader with it would
     *  share the user's login (ItemLint, Planner: E).
     *  @throws \RuntimeException|\LogicException the users cannot be asked - never "free" */
    public function adminLoginTaken(string $login): bool;

    /** The values of the field (the login or the e-mail) readers of this AA's Reader Management slices
     *  have, any bin, as ItemLint::loginKey() => a reader with it holds AA permissions (a perms row).
     *  One query - a batch asks once per field.
     *  @param list<string> $values in the charset the readers are stored in @return array<string, bool> */
    public function readerLogins(string $field, array $values): array;

    /** Of these item ids, those the target uses without an item row: a perms row names it (a reader's
     *  rights), or content rows are left of it - a new item with the id would get them. One query.
     *  @param list<string> $ids @return list<string> */
    public function itemIdsInUse(array $ids): array;

    /** how many of the ids (unpacked) are items of this AA - another AA keeps the item ids, so these cannot come
     *  @param list<string> $ids */
    public function existingItems(array $ids): int;

    /** true: a site of this AA answers to $host - a module's slice_url or a site's alternate_url is one of
     *  the URLs AA_Module_Site::getIdFromUrl() matches for it (http/https, with and without www., slash) */
    public function domainTaken(string $host): bool;

    /** the hosts this AA itself runs at - AA_INSTAL_URL and the admin's (lower case) @return list<string> */
    public function ownHosts(): array;

    /** A slice of this AA a slice of the package may go into; null = no such slice, or it is deleted.
     *  $lock: a locking read of the slice row - the writer's, so nobody deletes it until the commit.
     *  @throws \RuntimeException the read failed */
    public function existingSlice(string $id, bool $lock = false): ?ExistingSlice;

    /** the importing user may change the slice (PS_EDIT, or a superadmin) - only such a slice takes a package;
     *  asked of any module when the planner picks the default owner */
    public function mayEditSlice(string $id): bool;

    /** the owners of this AA (slice_owner) by name, any case - those of a 16-byte id only
     *  @return array<string, string> id => name */
    public function owners(): array;

    /** the owner of each module of this AA, none deleted @return array<string, string> module id => owner id ('' = none) */
    public function moduleOwners(): array;

    /** the slices of this AA (none deleted) of the slice.type @return list<string> ids, lower case */
    public function slicesOfType(string $type): array;

    /** The tables of $tables (the ones an import writes) that are not InnoDB - no transaction covers them,
     *  a failed import would leave its rows there: the planner refuses to plan for them (E).
     *  @param list<string> $tables @return array<string, string> table => its engine ('' = no such table) */
    public function nonTransactional(array $tables): array;
}
