<?php

/**
 * Stand-ins for the item-store characterization test (ItemStoreFieldsTest).
 *
 * The inserter chain (AA_Inserter → StoreToContent → Cvarset → getDB()) is exercised
 * for real; only the three seams that would touch a database are replaced:
 *  - RecordingDb    the DB_PDO handle - records every statement, keeps an in-memory
 *                   `content` table the statements are applied to, answers other
 *                   SELECTs from a scripted callback, never connects
 *  - FakeFields     AA_Fields backed by an in-memory id => AA_Field map
 *  - FakeSlice      AA_Slice registered in AA_Module's module cache, so
 *                   AA_Slice::getModule() (used by AA_Field_Writer) finds it
 *
 * The real include/locsess.php is loaded here on purpose (the test file defines the
 * config constants it needs first): its getDB()/freeDB() serve handles from
 * $GLOBALS['spareDBs'], which the test prefills with RecordingDb objects. Loading it
 * before anything else also keeps the getDB() stub of the transaction tests from
 * being declared in a process where the real one arrives later.
 */

declare(strict_types=1);

namespace Tests\Unit\Legacy\Fixtures;

use AA\IO\DB\DB_PDO;
use AA_Field;
use AA_Fields;
use AA_Module;
use AA_Slice;

require_once __DIR__ . '/../../../../include/locsess.php';

final class RecordingDb extends DB_PDO
{
    /** `content` columns as the in-memory table names them */
    private const COLUMN_NAMES = ['item_id' => 'item', 'field_id' => 'field'];

    /** @var string[] every statement, in order, across all handles */
    public static array $log = [];
    /** @var int[] spl_object_id of the handle that ran the statement at the same index */
    public static array $handles = [];
    /**
     * The `content` table as the statements so far would have left it - a list of
     * rows [item, field, number, text, flag], in insertion order. INSERT/DELETE on
     * `content` are applied to it and the inserters' own look-ups on it are answered
     * from it, so a store behaves exactly as against a real, initially empty table.
     */
    public static array $content = [];
    /** @var null|callable(string): array  rows for SELECTs on OTHER tables (constant, item, ...) */
    public static $answer = null;
    /** a statement starting with this string throws - simulates a failing write mid-transaction
     *  (with halting off it returns false instead) */
    public static ?string $fail_on = null;
    /** @var array<string, array> table => columns as DB_PDO::metadata() lists them (name, type) */
    public static array $metadata = [];

    private array $rows = [];
    private int $pos    = 0;

    public function __construct()
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
    }

    public static function reset(): void
    {
        self::$log     = [];
        self::$handles = [];
        self::$content  = [];
        self::$snapshot = [];
        self::$answer   = null;
        self::$fail_on  = null;
        self::$metadata = [];
    }

    /** number of statements of one kind sent so far */
    public static function count(string $verb): int
    {
        return count(array_filter(self::$log, static fn($s) => str_starts_with($s, $verb)));
    }

    public function query($Query_String): bool
    {
        if (self::$fail_on !== null && str_starts_with($Query_String, self::$fail_on)) {
            throw new \RuntimeException('RecordingDb: simulated failure of ' . self::$fail_on);
        }
        self::$log[]     = $Query_String;
        self::$handles[] = spl_object_id($this);
        $this->pos       = 0;
        $this->Record    = [];
        $this->rows      = self::applyToContent($Query_String)
            ?? ((self::$answer && str_starts_with($Query_String, 'SELECT')) ? (self::$answer)($Query_String) : []);
        return true;
    }

    /** Applies a `content` statement to the table; null when the statement is about another table. */
    private static function applyToContent(string $sql): ?array
    {
        if (($inserted = self::parseInsert($sql, 'content')) !== null) {
            foreach ($inserted as $values) {
                $row = ['item' => null, 'field' => null, 'number' => null, 'text' => null, 'flag' => null];
                foreach ($values as $col => $val) {
                    $row[self::COLUMN_NAMES[$col] ?? $col] = $val;
                }
                self::$content[] = $row;
            }
            return [];
        }
        if (preg_match('/^DELETE FROM `content`\s+WHERE (.*)$/s', $sql, $m)) {
            $cond = self::conditions($m[1]);
            self::$content = array_values(array_filter(self::$content, static fn($row) => !self::matches($row, $cond)));
            return [];
        }
        if (preg_match('/^SELECT item_id FROM "content" WHERE (.*) LIMIT/s', $sql, $m)) {
            $cond = self::conditions($m[1]);
            $hits = array_filter(self::$content, static fn($row) => self::matches($row, $cond));
            return $hits ? [['item_id' => reset($hits)['item']]] : [];
        }
        return null;
    }

    /** Rows the INSERTs so far sent into $table, each [column => value as decode() gives it]. */
    public static function inserts(string $table): array
    {
        $rows = [];
        foreach (self::$log as $sql) {
            array_push($rows, ...(self::parseInsert($sql, $table) ?? []));
        }
        return $rows;
    }

    /** The rows of an INSERT into $table; null when the statement is anything else. */
    private static function parseInsert(string $sql, string $table): ?array
    {
        if (!preg_match('/^INSERT INTO `' . preg_quote($table, '/') . '` \((.*?)\) VALUES (.*)$/s', $sql, $m)) {
            return null;
        }
        $cols = explode('`,`', trim($m[1], '`'));
        preg_match_all('/\((?:[^()\']|\'(?:[^\'\\\\]|\\\\.)*\')*\)/', $m[2], $tuples);
        $rows = [];
        foreach ($tuples[0] as $tuple) {
            preg_match_all('/\'(?:[^\'\\\\]|\\\\.)*\'|NULL|0x[0-9a-fA-F]+|-?\d+(?:\.\d+)?/', $tuple, $vals);
            $rows[] = array_map(self::decode(...), array_combine($cols, $vals[0]));
        }
        return $rows;
    }

    /** WHERE → [column => value | value list] for the shapes the store path emits */
    private static function conditions(string $where): array
    {
        $cond = [];
        if (preg_match('/item_id\s*=\s*0x([0-9a-fA-F]+)/', $where, $m)) {
            $cond['item'] = strtolower($m[1]);
        }
        if (preg_match('/field_id\s*=\s*\'([^\']+)\'/', $where, $m)) {
            $cond['field'] = $m[1];
        } elseif (preg_match('/field_id\s+IN\s*\(([^)]*)\)/i', $where, $m)) {
            $cond['field'] = array_map(static fn($f) => trim($f, " '"), explode(',', $m[1]));
        }
        foreach (['text', 'number'] as $col) {
            if (preg_match('/\b' . $col . '\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|-?\d+)/', $where, $m)) {
                $cond[$col] = self::decode($m[1]);
            }
        }
        return $cond;
    }

    private static function matches(array $row, array $cond): bool
    {
        foreach ($cond as $col => $want) {
            $have = $row[$col];
            if (is_array($want) ? !in_array($have, $want, true) : (string) $have !== (string) $want) {
                return false;
            }
        }
        return true;
    }

    private static function decode(string $sql_value): int|string|null
    {
        if ($sql_value === 'NULL') {
            return null;
        }
        if (str_starts_with($sql_value, '0x')) {
            return strtolower(substr($sql_value, 2));
        }
        if ($sql_value[0] === "'") {
            return stripslashes(substr($sql_value, 1, -1));
        }
        return (int) $sql_value;
    }

    /** column list of a table - DB_PDO would ask the database (CopyTableRows() does) */
    public function metadata(string $table = ""): array|false
    {
        return self::$metadata[$table] ?? [];
    }

    /** with halting off a failing statement returns false, as DB_PDO does */
    public function query_halt(string $Query_String, string $mode): bool
    {
        if ($mode === 'no' && self::$fail_on !== null && str_starts_with($Query_String, self::$fail_on)) {
            return false;
        }
        return $this->query($Query_String);
    }

    public function next_record(): bool
    {
        if (isset($this->rows[$this->pos])) {
            $this->Record = $this->rows[$this->pos++];
            return true;
        }
        $this->Record = [];
        return false;
    }

    public function affected_rows(): int
    {
        return 1;
    }

    public function num_rows(): int
    {
        return count($this->rows);
    }

    public function last_insert_id(): false|string
    {
        return '1';
    }

    /** the `content` table as it was at BEGIN - what a rollback returns to */
    private static array $snapshot = [];

    public function beginTransaction(): bool
    {
        self::$log[]    = 'BEGIN';
        self::$snapshot = self::$content;
        return true;
    }

    public function commit(): bool
    {
        self::$log[] = 'COMMIT';
        return true;
    }

    public function rollback(): bool
    {
        self::$log[]   = 'ROLLBACK';
        self::$content = self::$snapshot;
        return true;
    }
}

final class FakeFields extends AA_Fields
{
    /** @var array<string, AA_Field> */
    private array $map = [];

    /** @param AA_Field[] $fields */
    public function __construct(string $slice_id, array $fields)
    {
        parent::__construct($slice_id);
        foreach ($fields as $field) {
            $this->map[$field->getId()] = $field;
        }
    }

    public function getField(string $field_id): ?AA_Field
    {
        return $this->map[$field_id] ?? null;
    }

    public function isField(string $field_id): bool
    {
        return isset($this->map[$field_id]);
    }

    public function count(): int
    {
        return count($this->map);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->map);
    }

    public function getPriorityArray(): array
    {
        return array_keys($this->map);
    }

    public function getSafeFieldsArray(): array
    {
        return array_keys(array_filter($this->map, static fn(AA_Field $f) => $f->isSafeStored()));
    }
}

final class FakeSlice extends AA_Slice
{
    private FakeFields $fake_fields;
    /** the `module.deleted` flag - a soft-deleted slice must store like a live one */
    public bool $deleted = false;

    public function __construct(string $slice_id)
    {
        // no parent::__construct() - it would load the fields from the database
        $this->module_id   = $slice_id;
        $this->fake_fields = new FakeFields($slice_id, []);
        AA_Module::$_modules[$slice_id] = $this;    // what AA_Slice::getModule() returns
    }

    public function setFields(FakeFields $fields): void
    {
        $this->fake_fields = $fields;
    }

    public function getId(): string
    {
        return $this->module_id;
    }

    public function deleted()
    {
        return $this->deleted;
    }

    public function isValid(): bool
    {
        return !$this->deleted;
    }

    public function getField($field_id): ?AA_Field
    {
        return $this->fake_fields->getField($field_id);
    }

    public function isField($fname): bool
    {
        return $this->fake_fields->isField($fname);
    }

    public function getFields($dynamic_fields = false): AA_Fields
    {
        return $this->fake_fields;
    }

    /** slice settings storeItem() reads - history logging off, nothing else set */
    public function getProperty($prop)
    {
        return $prop === 'historylog' ? '0' : null;
    }
}

/** What AA::$perm / $GLOBALS['auth'] must answer for AA_Inserter_2fa to store a secret. */
final class FakeAuth
{
    public function __construct(private string $uid) {}

    public function getUid(): string
    {
        return $this->uid;
    }

    public function authenticateUsername($username, $password, $code2fa): string|false
    {
        return ($username === 'honza' && $password === 'pwd') ? $this->uid : false;
    }

    /** CheckPerms() primes the permission cache through this; the fake user has no permissions */
    public function cache($user_id): void
    {
        $GLOBALS['permission_uid'] = $user_id;
        $GLOBALS['permission_to']  = [];
    }
}
