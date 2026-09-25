<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA_Reader;
use RuntimeException;

/** ImportTarget over this AA's database: parameterised DB_AA reads (as DbSource), no writes.
 *  groupTaken() reads without halting: a failed read (lock wait timeout) throws - never "free", never die()
 *  inside the import's transaction. Its locking read holds the X-locks (gap locks for a free name), so two
 *  imports with constant groups wait on each other and the second fails cleanly. READ COMMITTED takes no
 *  gap locks - two imports could then both create one free name (accepted: REPEATABLE READ is the default).
 *  lt_groupNames.value compares case-insensitively in the usual collations: a name differing only in case
 *  counts as taken - one rename too many, never a merge. */
final class DbImportTarget implements ImportTarget
{
    /** InnoDB: the one engine of this AA's tables with transactions (MyISAM, Aria, MEMORY: none) */
    private const TRANSACTIONAL = 'innodb';

    public function groupTaken(string $name, bool $lock = false): bool
    {
        $places = [
            ['constant', [['group_id', 'lt_groupNames'], ['value', $name]]],
            ['constant', [['group_id', $name]]],
            ['constant_slice', [['group_id', $name]]],
        ];
        $db = getDB();
        try {
            foreach ($places as [$table, $where]) {
                // the values quoted by makeWhere(); FOR UPDATE after the LIMIT - DB_AA::test() cannot append it
                $query = 'SELECT group_id FROM ' . DB_AA::quoteIdentifier($table) . ' ' . DB_AA::makeWhere($where) . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
                if (!$db->query_halt($query, 'no')) {
                    throw new RuntimeException('the ' . ($lock ? 'locking ' : '') . "read of the constant group $name failed");
                }
                if ($db->next_record()) {
                    return true;
                }
            }
        } finally {
            freeDB($db);
        }
        return false;
    }

    /** AA::$perm - the permission systems of this AA (users, LDAP, readers); the AA boot (locsess.php) sets it */
    public function usernameTaken(string $username): bool
    {
        return !AA::$perm->isUsernameFree($username);
    }

    public function emailTaken(string $email): bool
    {
        return AA_Reader::email2Id($email, null, AA_BIN_ALL) !== null;
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

    /** information_schema.tables of this AA's database (DB_NAME), values quoted by makeWhere() */
    public function nonTransactional(array $tables): array
    {
        if (!$tables) {
            return [];
        }
        $where   = [['TABLE_SCHEMA', constant('DB_NAME')], ['TABLE_NAME', $tables]];
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], 'SELECT TABLE_NAME, ENGINE FROM information_schema.tables', $where);
        $engines = array_change_key_case($engines);         // lower_case_table_names; AA's table names are lower case
        $not     = [];
        foreach ($tables as $table) {
            $engine = (string) ($engines[strtolower($table)] ?? '');
            if (strtolower($engine) !== self::TRANSACTIONAL) {
                $not[$table] = $engine;
            }
        }
        return $not;
    }
}
