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

    /** Items of a slice in the GetItemContent() form, one at a time (a slice may be big), reading
     *  password ignored - the caller has checked the exporter's permissions (Collector).
     *  @param bool $all_bins false = the active bin only (status_code 1)
     *  @return iterable<array{id: string, content: array<string, list<array<string, mixed>>>}> */
    public function items(string $slice_id, bool $all_bins): iterable;
}
