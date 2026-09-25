<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\IO\DB\DB_PDO;
use AA_Metabase;
use RuntimeException;

/** A DB_PDO handle over in-memory tables, for the import writer tests: the real DB_AA::transaction(),
 *  getDB() single-handle rule and AA_Metabase / Cvarset SQL run; the statements are applied here.
 *
 *  Why no SQLite: AA's SQL is MySQL - packed ids as 0x<32 hex> (SQLite reads a 128-bit hex integer
 *  literal: "hex literal too big") and addslashes() escapes (\' is no SQLite escape). So this parses the
 *  statement shapes Cvarset and DB_AA::makeWhere() produce - INSERT, UPDATE ... WHERE, DELETE ... WHERE
 *  with conditions joined by AND (`col = literal`, `col IN (...)`, `col IS NULL`, `((col & n) = n)`, the
 *  `2=1` of an empty IN list) - and SELECT ... WHERE the same way (or from $answer); SELECT COUNT(*) AS x.
 *  Beyond the tables: information_schema.tables from $engines (table => ENGINE; a table not there does
 *  not exist), GET_LOCK() / RELEASE_LOCK() per handle as MySQL's named locks ($locks).
 *
 *  As MySQL: a column the INSERT leaves out gets its default (include/metabase_schema.php), AUTO_INCREMENT
 *  columns numbers from $next (per table; default 1); PRIMARY and UNIQUE indexes hold (a duplicate fails);
 *  conditions compare binary columns byte for byte, the others case-insensitively without trailing spaces
 *  (the usual *_ci collations, PAD SPACE). $sent keeps each INSERT as the statement had it. A transaction snapshots the tables and a
 *  rollback restores them - except the tables $engines names with another engine than InnoDB (mixed engines); $transactions = false makes beginTransaction() fail (DB_AA::transaction() then
 *  runs the block without one - the MyISAM case). $fail decides which statement fails: with halting
 *  (query() of a handle whose halt mode is not 'no') it throws and is kept in $halted - a real DB_PDO
 *  would die() there; with query_halt(.., 'no') or halt mode 'no' it returns false - as DB_PDO. The real handle pool of
 *  include/locsess.php serves these: $GLOBALS['spareDBs'] = [new TableDb(), ...]. */
final class TableDb extends DB_PDO
{
    /** @var array<string, list<array<string, int|float|string|null>>> table => rows; packed ids as lower-case hex */
    public static array $tables = [];
    /** @var array<string, list<array<string, int|float|string|null>>> table => INSERTed rows as sent (no defaults) */
    public static array $sent = [];
    /** @var list<string> every statement (and BEGIN, COMMIT, ROLLBACK), in order */
    public static array $log = [];
    /** @var list<int> spl_object_id of the handle of the statement at the same index */
    public static array $handles = [];
    /** @var array<string, int> table => the next AUTO_INCREMENT number */
    public static array $next = [];
    /** @var null|callable(string): bool true = the statement fails */
    public static $fail = null;
    /** @var null|callable(string): list<array<string, mixed>> rows of a SELECT */
    public static $answer = null;
    public static bool $transactions = true;
    /** rollback() and a ROLLBACK statement fail - the transaction stays open (a live persistent connection) */
    public static bool $rollbackFails = false;
    /** @var list<string> statements that failed on a halting handle - a real DB_PDO die()s there */
    public static array $halted = [];
    /** @var array<string, string> information_schema.tables: table => ENGINE */
    public static array $engines = [];
    /** @var array<string, int> named lock => spl_object_id of the handle holding it */
    public static array $locks = [];

    /** @var array<string, list<array<string, int|float|string|null>>>|null tables at BEGIN */
    private static ?array $snapshot = null;

    private int $lastId = 0;
    private int $affected = 0;
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    private int $pos = 0;

    public function __construct(bool $persistent = false)
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:', 'persistent' => $persistent]);
    }

    public static function reset(): void
    {
        self::$tables       = [];
        self::$sent         = [];
        self::$log          = [];
        self::$handles      = [];
        self::$next         = [];
        self::$fail         = null;
        self::$answer       = null;
        self::$transactions = true;
        self::$rollbackFails = false;
        self::$snapshot     = null;
        self::$halted       = [];
        self::$engines      = [];
        self::$locks        = [];
    }

    /** @return list<array<string, int|float|string|null>> */
    public static function rows(string $table): array
    {
        return self::$tables[$table] ?? [];
    }

    /** statements that are no SELECT, BEGIN, COMMIT or ROLLBACK */
    public static function writes(): array
    {
        return array_values(array_filter(self::$log, static fn(string $s): bool => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/', $s)));
    }

    public function query($Query_String): bool
    {
        if ($this->run($Query_String)) {
            return true;
        }
        if ($this->get_halt() === 'no') {
            return false;
        }
        self::$halted[] = $Query_String;
        throw new RuntimeException('TableDb: failed ' . substr($Query_String, 0, 60));
    }

    public function query_halt(string $Query_String, string $mode): bool
    {
        if ($mode !== 'no') {
            return $this->query($Query_String);
        }
        return $this->run($Query_String);
    }

    private function run(string $sql): bool
    {
        self::$log[]     = $sql;
        self::$handles[] = spl_object_id($this);
        $this->rows      = [];
        $this->pos       = 0;
        $this->affected  = 0;
        if (self::$fail && (self::$fail)($sql)) {
            return false;
        }
        if ($sql === 'ROLLBACK') {
            return $this->rollback();
        }
        if (preg_match('/^INSERT INTO `(\w+)` \((.*?)\) VALUES \(/s', $sql, $m)) {
            return $this->insert($m[1], explode('`,`', trim($m[2], '`')), new SqlScanner($sql, strlen($m[0])));
        }
        if (preg_match('/^UPDATE `(\w+)` SET /', $sql, $m)) {
            $scan = new SqlScanner($sql, strlen($m[0]));
            $set  = $scan->pairs(',', 'WHERE');
            return $this->apply($m[1], $scan->conditions(), static fn(array $row): array => $set + $row);
        }
        if (preg_match('/^DELETE (?:LOW_PRIORITY )?FROM [`"](\w+)[`"]\s+WHERE /', $sql, $m)) {
            return $this->apply($m[1], (new SqlScanner($sql, strlen($m[0])))->conditions(), null);
        }
        if (self::$answer && str_starts_with($sql, 'SELECT')) {
            $this->rows = (self::$answer)($sql);
            return true;
        }
        if (preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\(/', $sql, $m)) {
            $this->rows = [['result' => $this->namedLock($m[1], (new SqlScanner($sql, strlen($m[0])))->values())]];
            return true;
        }
        if (preg_match('/^SELECT (.+?) FROM (?:[`"]?(\w+)[`"]?|(information_schema\.tables))(?: WHERE |\s*$)/i', $sql, $m)) {
            $table = $m[2] ?: 'information_schema';
            $where = str_ends_with($m[0], 'WHERE ') ? (new SqlScanner($sql, strlen($m[0])))->conditions() : [];
            $rows  = ($table === 'information_schema') ? self::informationSchema() : (self::$tables[$table] ?? []);
            $rows  = array_values(array_filter($rows, static fn(array $row): bool => self::matches($table, $row, $where)));
            $this->rows = preg_match('/^COUNT\(\*\) AS (\w+)$/i', $m[1], $count) ? [[$count[1] => count($rows)]] : $rows;
            return true;
        }
        throw new RuntimeException("TableDb: unknown statement $sql");
    }

    /** GET_LOCK(name, timeout): 1 when free or this handle's (no waiting), else 0; RELEASE_LOCK(name): 1 when
     *  this handle held it, 0 another's, NULL none - MySQL's answers @param list<int|float|string|null> $args */
    private function namedLock(string $function, array $args): ?int
    {
        $name   = (string) $args[0];
        $holder = self::$locks[$name] ?? null;
        if ($function === 'GET_LOCK') {
            if (($holder !== null) && ($holder !== spl_object_id($this))) {
                return 0;
            }
            self::$locks[$name] = spl_object_id($this);
            return 1;
        }
        if ($holder === null) {
            return null;
        }
        if ($holder !== spl_object_id($this)) {
            return 0;
        }
        unset(self::$locks[$name]);
        return 1;
    }

    /** @return list<array<string, string>> rows of information_schema.tables, both spellings of the columns */
    private static function informationSchema(): array
    {
        $rows = [];
        foreach (self::$engines as $table => $engine) {
            $rows[] = ['table_schema' => DB_NAME, 'table_name' => $table, 'TABLE_SCHEMA' => DB_NAME, 'TABLE_NAME' => $table, 'ENGINE' => $engine];
        }
        return $rows;
    }

    /** @param list<string> $columns */
    private function insert(string $table, array $columns, SqlScanner $scan): bool
    {
        $row                   = array_combine($columns, $scan->values());
        self::$sent[$table][]  = $row;
        $this->lastId          = 0;
        foreach (AA_Metabase::singleton()->getColumns($table) as $name => $column) {
            if ($column->isAutoIncrement() && !isset($row[$name])) {
                $row[$name]   = $this->lastId = self::$next[$table] ?? 1;
                self::$next[$table] = $this->lastId + 1;
            } elseif (!array_key_exists($name, $row)) {
                $default    = $column->c[4];
                $row[$name] = (($default === null) || ($column->getFieldType() !== 'int')) ? $default : (int) $default;
            }
        }
        foreach (AA_Metabase::singleton()->tables[$table]->i as $index) {
            if (!in_array($index->s, ['P', 'U'], true)) {
                continue;
            }
            $key = array_map(static fn($c): string => is_array($c) ? $c[0] : $c, array_values($index->c));
            $new = array_intersect_key($row, array_flip($key));
            foreach (self::$tables[$table] ?? [] as $old) {
                if (!in_array(null, $new, true) && self::matches($table, $old, SqlScanner::equal($new))) {
                    return false;           // duplicate PRIMARY / UNIQUE key
                }
            }
        }
        self::$tables[$table][] = $row;
        $this->affected         = 1;
        return true;
    }

    /** UPDATE ($change) or DELETE (null) of the rows matching all $where conditions
     *  @param list<array{string, string, mixed}> $where SqlScanner::conditions() @param null|callable(array): array $change */
    private function apply(string $table, array $where, ?callable $change): bool
    {
        $kept = [];
        foreach (self::$tables[$table] ?? [] as $row) {
            if (self::matches($table, $row, $where)) {
                ++$this->affected;
                if ($change === null) {
                    continue;
                }
                $row = $change($row);
            }
            $kept[] = $row;
        }
        self::$tables[$table] = $kept;
        return true;
    }

    /** the row meets all the $where conditions - values in the collation of each column (information_schema:
     *  case-insensitive, as its column names)
     *  @param array<string, mixed> $row @param list<array{string, string, mixed}> $where SqlScanner::conditions() */
    private static function matches(string $table, array $row, array $where): bool
    {
        $columns = ($table === 'information_schema') ? [] : AA_Metabase::singleton()->getColumns($table);
        $equal   = static function (string $name, mixed $value) use ($table, $columns, $row): bool {
            [$a, $b] = [(string) ($row[$name] ?? ''), (string) $value];
            $ci      = ($table === 'information_schema') || (isset($columns[$name]) && !$columns[$name]->isBinary());
            return $ci ? (strtolower(rtrim($a)) === strtolower(rtrim($b))) : ($a === $b);
        };
        foreach ($where as [$op, $name, $value]) {
            $ok = match ($op) {
                '='     => $equal($name, $value),
                '<>'    => !$equal($name, $value),
                'in'    => (bool) array_filter($value, static fn($v): bool => $equal($name, $v)),
                'not in' => !array_filter($value, static fn($v): bool => $equal($name, $v)),
                'null'  => !isset($row[$name]),
                'set'   => (((int) ($row[$name] ?? 0)) & $value) === $value,
                'false' => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function next_record(): bool
    {
        $this->Record = $this->rows[$this->pos++] ?? [];
        return (bool) $this->Record;
    }

    public function num_rows(): int
    {
        return count($this->rows);
    }

    public function affected_rows(): int
    {
        return $this->affected;
    }

    public function last_insert_id(): false|string
    {
        return (string) $this->lastId;
    }

    public function beginTransaction(): bool
    {
        if (!self::$transactions) {
            return false;
        }
        self::$log[]     = 'BEGIN';
        self::$handles[] = spl_object_id($this);
        self::$snapshot  = self::$tables;
        return true;
    }

    public function commit(): bool
    {
        self::$log[]     = 'COMMIT';
        self::$handles[] = spl_object_id($this);
        self::$snapshot  = null;
        return true;
    }

    public function rollback(): bool
    {
        if (self::$rollbackFails) {
            return false;
        }
        self::$log[]     = 'ROLLBACK';
        self::$handles[] = spl_object_id($this);
        if (self::$snapshot !== null) {
            $restored = self::$snapshot;
            foreach (self::$engines as $table => $engine) {
                if (strtolower($engine) !== 'innodb') {
                    $restored[$table] = self::$tables[$table] ?? [];     // no transaction: its rows stay
                }
            }
            self::$tables = $restored;
        }
        self::$snapshot = null;
        return true;
    }
}

/** Reads the SQL literals and `column = literal` pairs of the statements TableDb applies. */
final class SqlScanner
{
    public function __construct(private readonly string $sql, private int $pos) {}

    /** comma-separated literals up to the closing `)` @return list<int|float|string|null> */
    public function values(): array
    {
        $values = [];
        do {
            $values[] = $this->literal();
            $this->space();
        } while ($this->eat(','));
        return $values;
    }

    /** conditions joined by AND, up to anything else (LIMIT, FOR UPDATE, the end): `col = literal`,
     *  `col <> literal`, `col [NOT] IN (literals)`, `col IS NULL`, `((col & n) = n)`, `2=1` (makeWhere() of an empty list), `0`
     *  @return list<array{string, string, mixed}> [operator (= in null set false), column, value] */
    public function conditions(): array
    {
        $conditions = [];
        do {
            $this->space();
            if (preg_match('/\G(?:2=1|0\b)/', $this->sql, $m, 0, $this->pos)) {
                $conditions[] = ['false', '', null];
            } elseif (preg_match('/\G\(\(`?(\w+)`? & (\d+)\) = \2\)/', $this->sql, $m, 0, $this->pos)) {
                $conditions[] = ['set', $m[1], (int) $m[2]];
            } elseif (preg_match('/\G`?([\w.]+)`? <> /', $this->sql, $m, 0, $this->pos)) {
                $this->pos   += strlen($m[0]);
                $conditions[] = ['<>', preg_replace('/^\w+\./', '', $m[1]), $this->literal()];
                continue;
            } elseif (preg_match('/\G`?([\w.]+)`? IS NULL/', $this->sql, $m, 0, $this->pos)) {
                $conditions[] = ['null', preg_replace('/^\w+\./', '', $m[1]), null];
            } elseif (preg_match('/\G`?([\w.]+)`? (NOT )?IN \(/', $this->sql, $m, 0, $this->pos)) {
                $this->pos   += strlen($m[0]);
                $conditions[] = [($m[2] ?? '') ? 'not in' : 'in', preg_replace('/^\w+\./', '', $m[1]), $this->values()];
                $this->space();
                $this->eat(')') || throw new RuntimeException('SqlScanner: no ) at ' . substr($this->sql, $this->pos, 40));
                continue;
            } else {
                foreach ($this->pairs('AND', null, true) as $name => $value) {
                    $conditions[] = ['=', $name, $value];
                }
                continue;
            }
            $this->pos += strlen($m[0]);
        } while ($this->andNext());
        return $conditions;
    }

    /** the next token is AND (consumed) */
    private function andNext(): bool
    {
        $this->space();
        return $this->eat('AND ');
    }

    /** `col = value` conditions of an array @param array<string, mixed> $row @return list<array{string, string, mixed}> */
    public static function equal(array $row): array
    {
        return array_map(static fn(string $name, mixed $value): array => ['=', $name, $value], array_keys($row), $row);
    }

    /** `col = literal` pairs joined by $separator, up to the keyword $end (consumed) or the end ($one: just one)
     *  @return array<string, int|float|string|null> */
    public function pairs(string $separator, ?string $end, bool $one = false): array
    {
        $pairs = [];
        while (true) {
            $this->space();
            if (!preg_match('/\G`?([\w.]+)`?\s*=\s*/', $this->sql, $m, 0, $this->pos)) {
                throw new RuntimeException('SqlScanner: no pair at ' . substr($this->sql, $this->pos, 40));
            }
            $this->pos += strlen($m[0]);
            $pairs[preg_replace('/^\w+\./', '', $m[1])] = $this->literal();
            if ($one) {
                return $pairs;
            }
            $this->space();
            if ($this->eat($separator)) {
                continue;
            }
            if (($end !== null) && !$this->eat($end)) {
                throw new RuntimeException("SqlScanner: no $end at " . substr($this->sql, $this->pos, 40));
            }
            return $pairs;
        }
    }

    private function literal(): int|float|string|null
    {
        $this->space();
        $rest = substr($this->sql, $this->pos, 1);
        if ($rest === "'") {
            $out = '';
            for ($i = $this->pos + 1; $this->sql[$i] !== "'"; ++$i) {
                if ($this->sql[$i] === '\\') {
                    ++$i;       // addslashes(): \' \" \\ and NUL as \0
                    $out .= ($this->sql[$i] === '0') ? "\0" : $this->sql[$i];
                } else {
                    $out .= $this->sql[$i];
                }
            }
            $this->pos = $i + 1;
            return $out;
        }
        if (!preg_match('/\GNULL|\G0x([0-9a-fA-F]*)|\G-?\d+(\.\d+)?/', $this->sql, $m, 0, $this->pos)) {
            throw new RuntimeException('SqlScanner: no literal at ' . substr($this->sql, $this->pos, 40));
        }
        $this->pos += strlen($m[0]);
        return match (true) {
            $m[0] === 'NULL'              => null,
            str_starts_with($m[0], '0x')  => strtolower($m[1]),
            isset($m[2])                  => (float) $m[0],
            default                       => (int) $m[0],
        };
    }

    private function space(): void
    {
        $this->pos += strspn($this->sql, " \t\n", $this->pos);
    }

    private function eat(string $token): bool
    {
        if (substr($this->sql, $this->pos, strlen($token)) === $token) {
            $this->pos += strlen($token);
            return true;
        }
        return false;
    }
}
