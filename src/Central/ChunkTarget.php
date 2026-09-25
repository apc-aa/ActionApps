<?php

declare(strict_types=1);

namespace AA\Central;

/** What the chunk mode (ChunkImporter, design balicek-v1.md § 10.1) asks of the AA it imports into - the
 *  one seam to it: DbChunkTarget reads this AA's database, tests use a fake. Reads only;
 *  the rows are written through AA_Metabase. Ids are unpacked, 32 lower-case hex. */
interface ChunkTarget
{
    /** module.type of the module (S, W, Alerts, ...), null = no such module */
    public function moduleType(string $module_id): ?string;

    /** The owner of an existing constant group: its constant_slice.slice_id ('' = the group exists, in
     *  lt_groupNames or as the group of a constant, with no owning slice); null = no such group. group_id is
     *  varbinary - compared byte for byte; a name in lt_groupNames (char) case-insensitively = exists. */
    public function groupOwner(string $group): ?string;

    /** The slices of the existing items among $item_ids (the others are missing).
     *  @param list<string> $item_ids @return array<string, string> item id => slice id */
    public function itemSlices(array $item_ids): array;

    /** The modules of the existing alerts collections among $ids.
     *  @param list<string> $ids @return array<string, string> collection id => module id */
    public function collectionModules(array $ids): array;

    /** the importing user - module.created_by, slice.created_by */
    public function user(): string;

    /** now - module.created_at, alerts_collection_howoften.last */
    public function now(): int;
}
