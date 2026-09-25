<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\IO\DB\DB_AA;

/** ImportTarget over this AA's database: parameterised DB_AA reads (as DbSource), no writes.
 *  The locking read of groupTaken() locks nothing on MyISAM tables (§ 13.5): there two imports at once
 *  may still create one group name twice - the residual risk until the tables are InnoDB. On InnoDB it
 *  blocks a concurrent insert of a free name only by gap locks, i.e. under REPEATABLE READ (the default),
 *  not READ COMMITTED; two imports claiming the same free name may deadlock - one fails and rolls back.
 *  lt_groupNames.value is a char column, compared in its collation (case-insensitive in the usual
 *  latin1/utf8 ones): a name differing only in case counts as taken - one rename too many, never a merge. */
final class DbImportTarget implements ImportTarget
{
    public function groupTaken(string $name, bool $lock = false): bool
    {
        $places = [
            ['constant', [['group_id', 'lt_groupNames'], ['value', $name]]],
            ['constant', [['group_id', $name]]],
            ['constant_slice', [['group_id', $name]]],
        ];
        foreach ($places as [$table, $where]) {
            // the values quoted by makeWhere(); FOR UPDATE after the LIMIT - DB_AA::test() cannot append it
            $query = 'SELECT group_id FROM ' . DB_AA::quoteIdentifier($table) . ' ' . DB_AA::makeWhere($where) . ' LIMIT 1';
            if (DB_AA::select('', $query . ($lock ? ' FOR UPDATE' : ''))) {
                return true;
            }
        }
        return false;
    }

    public function newId(): string
    {
        return new_id();
    }

    public function newCollectionId(): string
    {
        return self::collectionId();
    }

    public function collectionTaken(string $id): bool
    {
        return DB_AA::test('alerts_collection', [['id', $id]]);
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
