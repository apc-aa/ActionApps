<?php

declare(strict_types=1);

namespace Tests\Unit\IO\DB;

use AA\IO\DB\DB_PDO;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * DB_PDO::quote_identifier() — driver-aware identifier quoting (backticks for
 * MySQL, "double quotes" otherwise; handles dotted names). This is the
 * security-relevant own-logic and is pure — the constructor does NOT connect.
 *
 * DB_PDO::quote() just lazy-connects then delegates to the driver's PDO::quote().
 * We exercise its contract with a reflection-injected in-memory SQLite PDO, so
 * connect() short-circuits before its sqlite-function registration (which needs
 * legacy globals like _sql_rand). The exact escaping is the driver's — SQLite
 * doubles the quote, production MySQL backslash-escapes — so we assert only the
 * driver-agnostic safety contract (value is wrapped and the inner quote escaped).
 *
 * DB_PDO::fetch_first_column() — characterization of what callers get back. It returns
 * the first column by position (every query selecting its id first is the
 * documented invariant), so these pin row order, NULL handling, and that extra
 * trailing columns never displace the id. Exercised against a real in-memory
 * SQLite statement injected into $sth.
 */
final class DbPdoTest extends TestCase
{
    private function dbPdo(string $type, string $database = ''): DB_PDO
    {
        return new DB_PDO(['type' => $type, 'database' => $database]);
    }

    // --- quote_identifier(): identifier-injection defense (pure, no DB) ---

    public function test_quote_identifier_mysql_uses_backticks(): void
    {
        $db = $this->dbPdo('db_pdo:mysql');
        $this->assertSame('`col`', $db->quote_identifier('col'));
        $this->assertSame('`table`.`col`', $db->quote_identifier('table.col'));
    }

    public function test_quote_identifier_non_mysql_uses_double_quotes(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->assertSame('"table"."col"', $db->quote_identifier('table.col'));
    }

    // --- quote(): wraps + escapes (driver-agnostic safety contract) ---

    public function test_quote_wraps_and_escapes_value(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectPdo($db, new PDO('sqlite::memory:'));

        $this->assertSame("'abc'", $db->quote('abc'));

        $quoted = $db->quote("a'b");
        $this->assertStringStartsWith("'", $quoted);
        $this->assertStringEndsWith("'", $quoted);
        // a naive "'a'b'" would let the value break out of the literal → must differ
        $this->assertNotSame("'a'b'", $quoted);
    }

    // --- fetch_first_column(): flat list of one column's values, in row order ---

    public function test_fetch_first_column_returns_flat_values_in_row_order(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, 'SELECT id FROM t ORDER BY srt');
        $this->assertSame(['3', '1', '2'], $db->fetch_first_column());
    }

    /** NULLs are values, not absent keys - they must survive, not shift the list. */
    public function test_fetch_first_column_preserves_nulls(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, 'SELECT val FROM t ORDER BY srt');
        $this->assertSame(['a', null, 'b'], $db->fetch_first_column());
    }

    /** The id is taken by position, so a query carrying extra columns - as
     *  AA_Object::querySet() (include/statestore.php) does when sorting with
     *  a limit - must still yield the id, and never the trailing sort column. */
    public function test_fetch_first_column_returns_first_column_when_query_is_multicolumn(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, 'SELECT id AS objectid, val FROM t ORDER BY srt');
        $this->assertSame(['3', '1', '2'], $db->fetch_first_column());
    }

    public function test_fetch_first_column_returns_empty_array_for_empty_result(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, "SELECT id FROM t WHERE id = 'nothing'");
        $this->assertSame([], $db->fetch_first_column());
    }

    // --- first_col(): same invariant, row by row (the group-limit branch) ---

    /** GetZidsFromSQL()'s group-limit branch walks rows with next_record() and
     *  takes the id positionally, while the group column stays named - so the
     *  trailing sort column must not displace the id. */
    public function test_first_col_returns_first_column_of_current_record(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, 'SELECT id AS itemid, val AS s0 FROM t ORDER BY srt');

        $seen = [];
        while ($db->next_record()) {
            $seen[] = [$db->first_col(), $db->f('s0')];
        }
        $this->assertSame([['3', 'a'], ['1', null], ['2', 'b']], $seen);
    }

    public function test_first_col_returns_null_before_any_row_is_read(): void
    {
        $db = $this->dbPdo('db_pdo:sqlite', ':memory:');
        $this->injectResult($db, 'SELECT id FROM t ORDER BY srt');
        $this->assertNull($db->first_col());
    }

    /** Inject a ready PDO into the protected $dbh so connect() short-circuits. */
    private function injectPdo(DB_PDO $db, PDO $pdo): void
    {
        (new ReflectionProperty(DB_PDO::class, 'dbh'))->setValue($db, $pdo);
    }

    /** Run $sql against a fresh in-memory SQLite fixture and inject the executed
     *  statement into the protected $sth - the only thing fetch_first_column() reads.
     *  `id` is TEXT so the driver yields strings, as MySQL does in production. */
    private function injectResult(DB_PDO $db, string $sql): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id TEXT, val TEXT, srt INTEGER)');
        $pdo->exec("INSERT INTO t VALUES ('3','a',1),('1',NULL,2),('2','b',3)");
        (new ReflectionProperty(DB_PDO::class, 'sth'))->setValue($db, $pdo->query($sql));
    }
}
