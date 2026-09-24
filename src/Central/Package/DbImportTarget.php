<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\IO\DB\DB_AA;

/** ImportTarget over this AA's database: parameterised DB_AA reads (as DbSource), no writes.
 *  lt_groupNames.value is a char column, compared in its collation (case-insensitive in the usual
 *  latin1/utf8 ones): a name differing only in case counts as taken - one rename too many, never a merge. */
final class DbImportTarget implements ImportTarget
{
    public function groupTaken(string $name): bool
    {
        return DB_AA::test('constant', [['group_id', 'lt_groupNames'], ['value', $name]])
            || DB_AA::test('constant', [['group_id', $name]])
            || DB_AA::test('constant_slice', [['group_id', $name]]);
    }

    public function newId(): string
    {
        return new_id();
    }

    public function newCollectionId(): string
    {
        return self::collectionId();
    }

    /** A new alerts collection id: 5 characters of gensalt(), none in alerts_collection yet. Mirrors
     *  new_collection_id() of modules/alerts/util.php - keep the two in step (that file pulls the mail
     *  library, so it is not loaded here; the legacy module does not depend on the classmap for it). */
    public static function collectionId(): string
    {
        do {
            $id = gensalt(5);
        } while (DB_AA::test('alerts_collection', [['id', $id]]));
        return $id;
    }
}
