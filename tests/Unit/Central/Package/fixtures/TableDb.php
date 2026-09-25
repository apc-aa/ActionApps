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
 *  with `col = literal` conditions joined by AND - and SELECT ... WHERE the same way (or from $answer).
 *
 *  As MySQL: a column the INSERT leaves out gets its default (include/metabase_schema.php), AUTO_INCREMENT
 *  columns numbers from $next (per table; default 1); PRIMARY and UNIQUE indexes hold (a duplicate fails);
 *  conditions compare binary columns byte for byte, the others case-insensitively without trailing spaces
 *  (the usual *_ci collations, PAD SPACE). $sent keeps each INSERT as the statement had it. A transaction snapshots the tables and a
 *  rollback restores them; $transactions = false makes beginTransaction() fail (DB_AA::transaction() then
 *  runs the block without one - the MyISAM case). $fail decides which statement fails: with halting
 *  (query()) it throws, with query_halt(.., 'no') it returns false - as DB_PDO. The real handle pool of
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
        self::$snapshot     = null;
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
            return $this->insert($m[1], explode('`,`', trim($m[2], '`')), new SqlScanner($sql, strlen($m[0])));
        }
        if (preg_match('/^UPDATE `(\w+)` SET /', $sql, $m)) {
            $scan = new SqlScanner($sql, strlen($m[0]));
            $set  = $scan->pairs(',', 'WHERE');
            return $this->apply($m[1], $scan->pairs('AND', null), static fn(array $row): array => $set + $row);
        }
        if (preg_match('/^DELETE (?:LOW_PRIORITY )?FROM [`"](\w+)[`"]\s+WHERE /', $sql, $m)) {
            return $this->apply($m[1], (new SqlScanner($sql, strlen($m[0])))->pairs('AND', null), null);
        }
        if (self::$answer && str_starts_with($sql, 'SELECT')) {
            $this->rows = (self::$answer)($sql);
            return true;
        }
        if (preg_match('/^SELECT .+? FROM [`"](\w+)[`"] WHERE /', $sql, $m)) {
            $where      = (new SqlScanner($sql, strlen($m[0])))->pairs('AND', null);
            $this->rows = array_values(array_filter(self::$tables[$m[1]] ?? [], fn(array $row): bool => self::matches($m[1], $row, $where)));
            return true;
        }
        throw new RuntimeException("TableDb: unknown statement $sql");
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
                if (!in_array(null, $new, true) && self::matches($table, $old, $new)) {
                    return false;           // duplicate PRIMARY / UNIQUE key
                }
            }
        }
        self::$tables[$table][] = $row;
        $this->affected         = 1;
        return true;
    }

    /** UPDATE ($change) or DELETE (null) of the rows matching all $where pairs
     *  @param array<string, mixed> $where @param null|callable(array): array $change */
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

    /** the row has all the $where values - in the collation of each column
     *  @param array<string, mixed> $row @param array<string, mixed> $where */
    private static function matches(string $table, array $row, array $where): bool
    {
        $columns = AA_Metabase::singleton()->getColumns($table);
        foreach ($where as $name => $value) {
            [$a, $b] = [(string) ($row[$name] ?? ''), (string) $value];
            if ((isset($columns[$name]) && !$columns[$name]->isBinary()) ? (strtolower(rtrim($a)) !== strtolower(rtrim($b))) : ($a !== $b)) {
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

    /** `col = literal` pairs joined by $separator, up to the keyword $end (consumed) or the end
     *  @return array<string, int|float|string|null> */
    public function pairs(string $separator, ?string $end): array
    {
        $pairs = [];
        while (true) {
            $this->space();
            if (!preg_match('/\G`?([\w.]+)`?\s*=\s*/', $this->sql, $m, 0, $this->pos)) {
                throw new RuntimeException('SqlScanner: no pair at ' . substr($this->sql, $this->pos, 40));
            }
            $this->pos += strlen($m[0]);
            $pairs[preg_replace('/^\w+\./', '', $m[1])] = $this->literal();
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
