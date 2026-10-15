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
 *  statement shapes Cvarset and DB_AA::makeWhere() produce - INSERT (one row or doInsertMulti()'s several), UPDATE ... WHERE, DELETE ... WHERE
 *  with conditions joined by AND (`col = literal`, `col IN (...)`, `col > literal`, `col < literal`) - and
 *  SELECT ... WHERE the same way (or from $answer); `SELECT count(*) as x` (DB_AA::count()) counts the
 *  matching rows, `SELECT MIN(col) AS x` takes their least value. A count over
 *  INNER / LEFT JOINs (join()) takes ON `a`.`c` = `b`.`d` [AND ...], UNHEX(`b`.`d`) too, and WHERE
 *  conditions of qualified columns: = 0x.., IN (0x..), LIKE '..', > '', IS NULL.
 *  Beyond the tables: information_schema.tables from $engines (table => ENGINE; a table not there does
 *  not exist).
 *
 *  As MySQL: a column the INSERT leaves out gets its default (include/metabase_schema.php), AUTO_INCREMENT
 *  columns numbers from $next (per table; default 1); PRIMARY and UNIQUE indexes hold (a duplicate fails);
 *  conditions compare binary columns byte for byte, the others case-insensitively without trailing spaces
 *  (the usual *_ci collations, PAD SPACE). $sent keeps each INSERT as the statement had it. A transaction
 *  snapshots the tables and a rollback restores them (InnoDB); $transactions = false makes
 *  beginTransaction() fail, $commitFails commit(). $fail decides which statement fails: with halting (query()) it throws, with
 *  query_halt(.., 'no') it returns false - as DB_PDO. The real handle pool of
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
    /** @var null|callable(string): (list<array<string, mixed>>|null) rows of a SELECT; null = read the tables */
    public static $answer = null;
    public static bool $transactions = true;
    /** commit() fails (returns false, as PDO in silent mode) */
    public static bool $commitFails = false;
    /** @var array<string, string> information_schema.tables: table => ENGINE */
    public static array $engines = [];

    /** @var array<string, list<array<string, int|float|string|null>>>|null tables at BEGIN */
    private static ?array $snapshot = null;

    private int $lastId = 0;
    private int $affected = 0;
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    private int $pos = 0;

    public function __construct()
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
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
        self::$commitFails  = false;
        self::$snapshot     = null;
        self::$engines      = [];
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
        if (!$this->run($Query_String)) {
            throw new RuntimeException('TableDb: failed ' . substr($Query_String, 0, 60));
        }
        return true;
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
        if (preg_match('/^INSERT INTO `(\w+)` \((.*?)\) VALUES \(/s', $sql, $m)) {
            $scan     = new SqlScanner($sql, strlen($m[0]));
            $affected = 0;
            do {                                    // AA_Metabase::doInsertMulti(): (...), (...) - row by row
                if (!$this->insert($m[1], explode('`,`', trim($m[2], '`')), $scan->values())) {
                    return false;
                }
                ++$affected;
            } while ($scan->nextTuple());
            $this->affected = $affected;
            return true;
        }
        if (preg_match('/^UPDATE `(\w+)` SET /', $sql, $m)) {
            $scan = new SqlScanner($sql, strlen($m[0]));
            $set  = $scan->pairs(',', 'WHERE');
            return $this->apply($m[1], $scan->conditions(), static fn(array $row): array => $set + $row);
        }
        if (preg_match('/^DELETE (?:LOW_PRIORITY )?FROM [`"](\w+)[`"]\s+WHERE /', $sql, $m)) {
            return $this->apply($m[1], (new SqlScanner($sql, strlen($m[0])))->conditions(), null);
        }
        if (self::$answer && str_starts_with($sql, 'SELECT') && (($rows = (self::$answer)($sql)) !== null)) {
            $this->rows = $rows;
            return true;
        }
        if (str_contains($sql, ' JOIN ')) {
            $this->rows = self::join($sql);
            return true;
        }
        if (preg_match('/^SELECT (.+?) FROM (?:[`"]?(\w+)[`"]?|(information_schema\.tables))(?: WHERE |\s*$)/i', $sql, $m)) {
            $table = $m[2] ?: 'information_schema';
            $where = str_ends_with($m[0], 'WHERE ') ? (new SqlScanner($sql, strlen($m[0])))->conditions() : [];
            $rows  = ($table === 'information_schema') ? self::informationSchema() : (self::$tables[$table] ?? []);
            $rows  = array_values(array_filter($rows, static fn(array $row): bool => self::matches($table, $row, $where)));
            $this->rows = match (true) {
                (bool) preg_match('/^count\(\*\) as (\w+)$/i', $m[1], $agg) => [[$agg[1] => count($rows)]],
                (bool) preg_match('/^min\(`?(\w+)`?\) as (\w+)$/i', $m[1], $agg) => [[$agg[2] => $rows ? min(array_column($rows, $agg[1])) : null]],
                default => $rows,
            };
            return true;
        }
        throw new RuntimeException("TableDb: unknown statement $sql");
    }

    /** `SELECT COUNT(*) AS x FROM t JOIN ... WHERE ...` over the tables: rows are alias => row while joining
     *  @return list<array<string, int>> */
    private static function join(string $sql): array
    {
        if (!preg_match('/^SELECT COUNT\(\*\) AS (\w+) FROM `(\w+)`(.*?) WHERE (.*?)(?:\s+LIMIT\s+\d+)?\s*$/is', $sql, $m)) {
            throw new RuntimeException("TableDb: unknown join $sql");
        }
        $rows = array_map(static fn(array $row): array => [$m[2] => $row], self::$tables[$m[2]] ?? []);
        preg_match_all('/ (INNER|LEFT) JOIN `(\w+)`(?: AS `(\w+)`)? ON (.*?)(?= (?:INNER|LEFT) JOIN |$)/s', $m[3], $joins, PREG_SET_ORDER);
        foreach ($joins as [, $kind, $table, $alias, $on]) {
            $alias = $alias ?: $table;
            $conds = array_map(static fn(string $c): array => preg_match('/^`(\w+)`\.`(\w+)` = (UNHEX\()?`(\w+)`\.`(\w+)`\)?$/', trim($c), $p) ? $p : throw new RuntimeException("TableDb: ON $c"), explode(' AND ', $on));
            $joined = [];
            foreach ($rows as $row) {
                $found = false;
                foreach (self::$tables[$table] ?? [] as $other) {
                    $ok = true;
                    foreach ($conds as [, $a, $ac, $unhex, $b, $bc]) {
                        [$mine, $theirs] = ($a === $alias) ? [$other[$ac] ?? null, $row[$b][$bc] ?? null] : [$row[$a][$ac] ?? null, $other[$bc] ?? null];
                        $theirs = $unhex ? (preg_match('/^[0-9a-f]{32}$/i', (string) $theirs) ? strtolower((string) $theirs) : null) : $theirs;
                        $ok     = $ok && ($theirs !== null) && ((string) $mine === (string) $theirs);
                    }
                    if ($ok) {
                        $joined[] = $row + [$alias => $other];
                        $found    = true;
                    }
                }
                if (!$found && ($kind === 'LEFT')) {
                    $joined[] = $row + [$alias => null];
                }
            }
            $rows = $joined;
        }
        foreach (explode(' AND ', $m[4]) as $cond) {
            if (!preg_match("/^(\w+)\.(\w+) (=|IN|LIKE|>|IS NULL)\s*(.*)$/s", trim($cond), $c)) {
                throw new RuntimeException("TableDb: WHERE $cond");
            }
            [, $alias, $column, $op, $arg] = $c;
            $rows = array_filter($rows, static function (array $row) use ($alias, $column, $op, $arg): bool {
                $value = ($row[$alias] ?? null) === null ? null : ($row[$alias][$column] ?? null);
                return match ($op) {
                    'IS NULL' => $value === null,
                    '>'       => trim((string) $value) !== '',
                    'LIKE'    => (bool) preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote(trim($arg, "'"), '/')) . '$/is', (string) $value),
                    default   => in_array(strtolower((string) $value), array_map(static fn(string $h): string => strtolower(substr(trim($h), 2)), explode(',', trim($arg, '()'))), true),
                };
            });
        }
        return [[$m[1] => count($rows)]];
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

    /** @param list<string> $columns @param list<int|float|string|null> $values */
    private function insert(string $table, array $columns, array $values): bool
    {
        $row                   = array_filter(array_combine($columns, $values), static fn($v) => $v !== SqlScanner::DEFAULT);   // as if left out
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
                '='  => $equal($name, $value),
                'in' => (bool) array_filter($value, static fn($v): bool => $equal($name, $v)),
                '>'  => ($row[$name] ?? 0) > $value,
                '<'  => ($row[$name] ?? 0) < $value,
                '>=' => ($row[$name] ?? 0) >= $value,
                '<=' => ($row[$name] ?? 0) <= $value,
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
        if (self::$commitFails) {
            return false;
        }
        self::$snapshot = null;
        return true;
    }

    public function rollback(): bool
    {
        self::$log[]     = 'ROLLBACK';
        self::$handles[] = spl_object_id($this);
        self::$tables    = self::$snapshot ?? self::$tables;
        self::$snapshot  = null;
        return true;
    }
}

/** Reads the SQL literals and `column = literal` pairs of the statements TableDb applies. */
final class SqlScanner
{
    /** the DEFAULT keyword of doInsertMulti() - the column takes its default, as if left out */
    public const DEFAULT = "\0DEFAULT";

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
     *  `col IN (literals)`, `col > literal`, `col < literal`, `col >= literal`, `col <= literal`
     *  @return list<array{string, string, mixed}> [operator (= in > < >= <=), column, value] */
    public function conditions(): array
    {
        $conditions = [];
        do {
            $this->space();
            if (preg_match('/\G`?([\w.]+)`? ([<>]=?) /', $this->sql, $m, 0, $this->pos)) {
                $this->pos   += strlen($m[0]);
                $conditions[] = [$m[2], preg_replace('/^\w+\./', '', $m[1]), $this->literal()];
            } elseif (preg_match('/\G`?([\w.]+)`? IN \(/', $this->sql, $m, 0, $this->pos)) {
                $this->pos   += strlen($m[0]);
                $conditions[] = ['in', preg_replace('/^\w+\./', '', $m[1]), $this->values()];
                $this->space();
                $this->eat(')') || throw new RuntimeException('SqlScanner: no ) at ' . substr($this->sql, $this->pos, 40));
            } else {
                foreach ($this->pairs('AND', null, true) as $name => $value) {
                    $conditions[] = ['=', $name, $value];
                }
            }
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
        if (!preg_match('/\GNULL|\GDEFAULT|\G0x([0-9a-fA-F]*)|\G-?\d+(\.\d+)?/', $this->sql, $m, 0, $this->pos)) {
            throw new RuntimeException('SqlScanner: no literal at ' . substr($this->sql, $this->pos, 40));
        }
        $this->pos += strlen($m[0]);
        return match (true) {
            $m[0] === 'NULL'              => null,
            $m[0] === 'DEFAULT'           => self::DEFAULT,
            str_starts_with($m[0], '0x')  => strtolower($m[1]),
            isset($m[2])                  => (float) $m[0],
            default                       => (int) $m[0],
        };
    }

    /** after the values of a row of INSERT: true = another row follows, `, (` eaten */
    public function nextTuple(): bool
    {
        $this->space();
        $this->eat(')');
        $this->space();
        if (!$this->eat(',')) {
            return false;
        }
        $this->space();
        return $this->eat('(');
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
