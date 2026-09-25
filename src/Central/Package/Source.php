<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** Where Collector reads the source AA from: DbSource in AA, an in-memory fake in tests. */
interface Source
{
    /** Rows of a table a package is made of (Schema::rowTables()), all columns; packed id columns
     *  (AA_Metabase::getPacked()) unpacked to lower-case hex, NULL stays NULL.
     *  @param list<array{0: string, 1: int|string|list<int|string>, 2: string}> $where equality
     *         conditions of DB_AA::makeWhere(), joined by AND, at least one: type 'l' long id, 'i'
     *         integer, 's' string; a list value = IN (an empty list matches nothing)
     *  @return list<array<string, mixed>> */
    public function select(string $table, array $where): array;

    /** Ids of the slice's items after $after (unpacked; '' = from the first) in id order, at most $limit -
     *  the cursor of an item batch.
     *  @param bool $all_bins false = the active bin only: status_code 1, pending and expired included
     *  @return list<string> */
    public function itemIds(string $slice_id, bool $all_bins, string $after, int $limit): array;

    /** The items in the GetItemContent() form, reading password ignored - the caller has checked the
     *  exporter's permissions (Collector). Any order.
     *  @param list<string> $ids unpacked
     *  @param bool $with_secrets add the 2FA secrets GetItemContent() leaves out - a transfer only: readers
     *                            must log in after it
     *  @return iterable<array{id: string, content: array<string, list<array<string, mixed>>>}> */
    public function items(array $ids, bool $with_secrets): iterable;

    /** The login names (FIELDID_USERNAME) of the slice's items, as stored.
     *  @return list<string> */
    public function logins(string $slice_id, bool $all_bins): array;

    /** The ids of the objects the module owns (aa_owner) - its settings, objects and, as
     *  AA_Property::save() gives them the owner of the parent, their subobjects.
     *  @return list<string> */
    public function objectIds(string $owner_id): array;

    /** The rows of the object store (object_text, object_integer, object_float) of the objects, in no
     *  particular order; only the given properties, all if none.
     *  @param list<string> $ids @param list<string> $properties
     *  @return list<array{table: string, object_id: string, property: string, priority: int|string|null, value: mixed}> */
    public function objectRows(array $ids, array $properties = []): array;
}
