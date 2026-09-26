<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\RawBytes;
use AA_MbC;
use AA_Metabase;
use LogicException;

/** One row of an import to the database, completed to every column of the local table, written by
 *  AA_Metabase with `nohalt` (inside DB_AA::transaction() on its handle); a refused statement throws
 *  WriteError::DB.
 *
 *  A value is rewritten first and converted to the charset LAST. A value too long for its column is
 *  WriteError::TOO_LONG, never cut: AA_MbT::getVarset4Data() would cut an (N) column to N bytes.
 *  An explicit NULL is written as the empty value of the type: getVarset4Data() skips NULL, and the DB
 *  default would flip field.text_stored NULL (number) to 1 (text). AUTO_INCREMENT columns are never set. */
final class RowStore
{
    /** @var array<string, array<string, array{int, bool}>> table => Schema::tableLimits() */
    private array $limits = [];
    /** @var array<string, int> table => rows inserted */
    private array $written = [];

    public function __construct(private readonly AA_Metabase $metabase, private readonly Schema $schema) {}

    /** The values to write of a package row (source ids, UTF-8) of the row element.
     *  @param array<string, mixed>           $row
     *  @param array<string, int|string|null> $fixed column => value written as it is
     *  @return array<string, int|float|string|null>
     *  @throws WriteError|RewriteFailed */
    public function row(string $element, array $row, array $fixed, string $charset, ImportRewrite $rewrite, string $path): array
    {
        $group = ReferenceRewriter::groupColumn($element, $row);
        return $this->values($this->schema->rowTables()[$element], $row, $fixed, $charset, $rewrite, $path, $group);
    }

    /** row() for a table that is no row element of the package - item, content (ItemWriter), object_*
     *  (ObjectWriter): $row as its values, $fixed as they are, completed to every column of the table
     *  @param array<string, mixed> $row @param array<string, int|string|null> $fixed
     *  @return array<string, int|float|string|null>
     *  @throws WriteError|RewriteFailed */
    public function tableRow(string $table, array $row, array $fixed, string $charset, ImportRewrite $rewrite, string $path): array
    {
        return $this->values($table, $row, $fixed, $charset, $rewrite, $path, null);
    }

    /** @param array<string, mixed> $row @param array<string, int|string|null> $fixed @param string|null $group ReferenceRewriter::groupColumn() of the row
     *  @return array<string, int|float|string|null> */
    private function values(string $table, array $row, array $fixed, string $charset, ImportRewrite $rewrite, string $path, ?string $group): array
    {
        $limits = $this->limits[$table] ??= $this->schema->tableLimits($table);
        foreach ($fixed as $column => $value) {
            if (is_string($value) && isset($limits[$column]) && (strlen($value) > $limits[$column][0])) {
                throw new LogicException("The writer's value of $path/$column is longer than its column");
            }
        }
        $values = $fixed;
        foreach ($row as $column => $value) {
            if (!array_key_exists($column, $fixed)) {
                $values[$column] = $this->value($value, $charset, $rewrite, "$path/$column", $limits[$column] ?? null, ($column === $group) ? $group : null);
            }
        }
        foreach ($this->metabase->getColumns($table) as $name => $column) {
            if ($column->isAutoIncrement()) {
                unset($values[$name]);
            } elseif (!array_key_exists($name, $values)) {
                $values[$name] = $column->c[4] ?? ($column->c[2] ? null : self::emptyValue($column));
            } elseif ($values[$name] === null) {
                $values[$name] = self::emptyValue($column);
            }
        }
        return $values;
    }

    /** A value to write (see the class) - a row column, a spot text.
     *  @param array{int, bool}|null $limit Schema::lengthLimits() of its column
     *  @throws WriteError|RewriteFailed */
    public function value(mixed $value, string $charset, ImportRewrite $rewrite, string $path, ?array $limit = null, ?string $groupColumn = null): mixed
    {
        if (!(is_string($value) || ($value instanceof RawBytes)) || ($value === '')) {
            return $value;
        }
        return $this->plain($rewrite->numeric($rewrite->known($value, $groupColumn)), $charset, $path, $limit);
    }

    /** value() without the reference rewrite - a password or a name is no template
     *  @param array{int, bool}|null $limit Schema::lengthLimits() of its column
     *  @throws WriteError */
    public function plain(string|RawBytes $value, string $charset, string $path, ?array $limit = null): string
    {
        $measured = TargetCharset::measure($value, $charset);
        if ($measured === null) {
            throw new WriteError(WriteError::CHARSET, $path, "the value does not convert to $charset");
        }
        if (($limit !== null) && (($too_long = TargetCharset::tooLong($measured, $limit)) !== null)) {
            throw new WriteError(WriteError::TOO_LONG, $path, $too_long);
        }
        return $measured[0];
    }

    /** Schema::tableLimits() of a column of a table @return array{int, bool}|null */
    public function limit(string $table, string $column): ?array
    {
        return ($this->limits[$table] ??= $this->schema->tableLimits($table))[$column] ?? null;
    }

    /** INSERT; the AUTO_INCREMENT number of the new row, 0 for a table without one
     *  @param array<string, int|float|string|null> $values @throws WriteError */
    public function insert(string $table, array $values, string $path): int
    {
        $id = $this->metabase->doInsert($table, $values, 'nohalt');
        if (!is_int($id)) {
            throw new WriteError(WriteError::DB, $path, "INSERT INTO $table failed");
        }
        $this->written[$table] = ($this->written[$table] ?? 0) + 1;
        if (!array_filter($this->metabase->getColumns($table), static fn(AA_MbC $c): bool => $c->isAutoIncrement())) {
            return 0;
        }
        if ($id === PHP_INT_MAX) {
            throw new WriteError(WriteError::DB, $path, "INSERT INTO $table gave no AUTO_INCREMENT number");
        }
        return $id;
    }

    /** @return array<string, int> table => rows inserted so far */
    public function written(): array
    {
        return $this->written;
    }

    /** UPDATE of the $changes of the row with the (primary) $keys; nothing to change = no statement
     *  @param array<string, int|string> $keys @param array<string, int|float|string|null> $changes @throws WriteError */
    public function update(string $table, array $keys, array $changes, string $path): void
    {
        if ($changes && !$this->metabase->doUpdate($table, $keys + $changes, 'nohalt')) {
            throw new WriteError(WriteError::DB, $path, "UPDATE $table failed");
        }
    }

    /** 0 or '' by the type of the column */
    private static function emptyValue(AA_MbC $column): int|string
    {
        return in_array($column->getFieldType(), ['int', 'float'], true) ? 0 : '';
    }
}
