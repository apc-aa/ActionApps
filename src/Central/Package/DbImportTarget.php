<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\IO\DB\DB_AA;
use AA\IO\DB\DB_PDO;
use AA\Optimize\Chain;
use AA_Reader;
use Closure;
use LogicException;
use RuntimeException;

/** ImportTarget over this AA's database: parameterised DB_AA reads (as DbSource), no writes.
 *  The locking read of groupTaken() locks nothing on MyISAM tables (§ 13.5): there two imports at once
 *  may still create one group name twice - the residual risk until the tables are InnoDB. On InnoDB it
 *  blocks a concurrent insert of a free name only by gap locks, i.e. under REPEATABLE READ (the default),
 *  not READ COMMITTED; two imports claiming the same free name may deadlock - one fails and rolls back.
 *  lt_groupNames.value is a char column, compared in its collation (case-insensitive in the usual
 *  latin1/utf8 ones): a name differing only in case counts as taken - one rename too many, never a merge. */
final class DbImportTarget implements ImportTarget
{
    /** InnoDB: the one engine of this AA's tables with transactions (MyISAM, Aria, MEMORY: none) */
    private const TRANSACTIONAL = 'innodb';

    /** the connection holding the named lock. A MySQL named lock belongs to its connection (session):
     *  RELEASE_LOCK() must run there, and a pooled or persistent connection (persistent: all DB_PDO share
     *  one) would keep the lock past a request that died - so a connection of its own, never persistent,
     *  never pooled, as AA\Optimize\Chain::tryLock(): the end of the script closes it and frees the lock */
    private ?DB_PDO $lockDb = null;

    /** @param Closure(): DB_PDO|null $lockConnection opens the lock connection (tests); null = Chain::lockConnectionParams() -
     *                                     config.php, never persistent */
    public function __construct(private readonly ?Closure $lockConnection = null) {}

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

    /** GET_LOCK() on a connection of its own (see $lockDb)
     *  @throws RuntimeException no connection, or GET_LOCK() gave no 0 / 1 (NULL: an error of the server) */
    public function lock(string $name, int $timeout): bool
    {
        if ($this->lockDb !== null) {
            throw new LogicException('DbImportTarget holds a lock already');
        }
        $db = $this->lockConnection ? ($this->lockConnection)() : new DB_PDO(Chain::lockConnectionParams());
        if (!$db->query_halt('SELECT GET_LOCK(' . qquote($name) . ', ' . max(0, $timeout) . ') AS result', 'no')) {
            throw new RuntimeException('no connection to the database for the import lock');
        }
        $result = $db->next_record() ? $db->record('result') : null;
        if (($result === null) || !in_array((string) $result, ['0', '1'], true)) {
            throw new RuntimeException('GET_LOCK() of the import lock failed (NULL)');
        }
        $this->lockDb = ((string) $result === '1') ? $db : null;         // not got: the last reference - closes the connection
        return $this->lockDb !== null;
    }

    public function unlock(string $name): void
    {
        $this->lockDb?->query_halt('SELECT RELEASE_LOCK(' . qquote($name) . ') AS result', 'no');
        $this->lockDb = null;
    }
}
