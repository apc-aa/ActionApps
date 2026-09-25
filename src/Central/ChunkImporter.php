<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\Central\Package\Schema;
use AA\Central\Package\SecurityLint;
use AA_Metabase;

/** Imports a chunk of a module definition (AA_Definition_Module_Chunk: one table of one module, at most 1000
 *  rows) into this AA - Central Copy Slice (responder Do_Import_Module_Chunk, the local import); the wire
 *  class delegates here. The chunk mode of design balicek-v1.md § 10.1:
 *  - only the tables of the module's type; installation state and profiles of users are skipped;
 *  - F4: every row belongs to the chunk's module_id - by its module column, the owner of its constant group
 *    (a new group becomes the module's), the slice of its item, the module of its collection - and the module
 *    exists here (items only into a prepared slice). An older Central names the template module: refused;
 *  - only the columns a package carries, installation columns reset, references out of the module cleared;
 *    an AUTO_INCREMENT number taken here: inserted again without it (references are not rewritten).
 *  Rights: both entry points are superadmin-only, and SUPER includes PS_INSTALL_APP (Q-H2).
 *  Report: today's `OK: ...` / `Err: ...`, then `Err: refused, <reason>: ...`, `Skipped, ...`, `Note: ...`. */
final class ChunkImporter
{
    private const TABLES = [
        'S'      => ['module', 'slice', 'field', 'view', 'email', 'profile', 'constant_slice', 'constant', 'item', 'content', 'discussion'],
        'W'      => ['module', 'site', 'site_spot'],
        'Alerts' => ['module', 'alerts_collection', 'alerts_collection_filter', 'alerts_collection_howoften'],
    ];

    /** state of the source AA (subscribers, feeds; § 6.2) */
    private const DROPPED = ['email_notify', 'rssfeeds'];

    /** the raw items keep every column (R10) */
    private const ITEM_TABLES = ['item', 'content', 'discussion'];

    /** columns kept besides the ones a package carries (Schema): the module column, keys chunk mode does not map */
    private const KEPT = [
        'module' => ['id', 'type'], 'slice' => ['id'], 'field' => ['slice_id'], 'view' => ['slice_id'], 'email' => ['owner_module_id'],
        'profile' => ['slice_id', 'uid'], 'constant_slice' => ['group_id', 'slice_id'], 'constant' => ['group_id'],
        'site' => ['id', 'structure'], 'site_spot' => ['site_id'], 'alerts_collection' => ['module_id'],
    ];

    /** references out of the module (the e-mails of an alerts module do not travel) - cleared unless empty or the module */
    private const FOREIGN = ['slice' => ['mlxctrl'], 'alerts_collection' => ['slice_id', 'emailid_welcome', 'emailid_alert']];

    private const REFUSED = 'Err: refused, not of this module or invalid - is the sending Central up to date?: ';

    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;
    /** @var array<string, array<string, list<array<int, mixed>>>> line prefix => table => idents */
    private array $report = [];
    /** @var list<string> */
    private array $notes = [];
    /** @var array<string, ?string> group name (exact bytes - group_id is varbinary) => its owner here, null = new */
    private array $groups = [];
    /** @var array<string, true> new groups a constant went into */
    private array $filled = [];
    /** @var array<string, string> item / collection id => its module here */
    private array $owners = [];
    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @param AA_Metabase|null $metabase writes the rows; null = AA::Metabase() */
    public function __construct(private readonly ChunkTarget $target, ?AA_Metabase $metabase = null)
    {
        $this->metabase = $metabase ?? AA::Metabase();
        $this->schema   = new Schema();
    }

    /** the wire class's entry - its properties are unserialized, so anything @return list<string> report lines */
    public static function import(mixed $chunk_data, mixed $module_id, ?ChunkTarget $target = null): array
    {
        return (new self($target ?? new DbChunkTarget()))->run(is_string($module_id) ? $module_id : '', is_array($chunk_data) ? $chunk_data : []);
    }

    /** @param array<mixed> $chunk_data table => rows @return list<string> report lines */
    public function run(string $module_id, array $chunk_data): array
    {
        [$this->report, $this->notes] = [['OK: ' => [], 'Err: ' => []], []];
        foreach ($chunk_data as $table => $rows) {
            if (is_array($rows) && $rows) {
                $this->table((string) $table, $rows, strtolower($module_id));
            }
        }
        $lines = [];
        foreach ($this->report as $prefix => $tables) {
            $lines[] = $tables ? $prefix . self::formatReport($tables) : null;
        }
        return array_values(array_filter([...$lines, $this->notes ? 'Note: ' . implode('; ', $this->notes) . '; ' : null]));
    }

    /** "2 field ([a][b]); " - rows per table by their keys */
    public static function formatReport($arr): string
    {
        $ret = '';
        foreach ($arr as $table => $idents) {
            $ret .= count($idents) . " $table (";
            foreach ($idents as $ident) {
                $ret .= '[' . implode(',', $ident) . ']';
            }
            $ret .= '); ';
        }
        return $ret;
    }

    /** @param array<mixed> $rows */
    private function table(string $table, array $rows, string $module): void
    {
        $type   = !self::isId($module) ? null : (($table === 'module') ? self::moduleType($rows, $module) : $this->target->moduleType($module));
        $reason = match (true) {
            in_array($table, self::DROPPED, true)                         => 'Skipped, installation state: ',
            !in_array($table, array_merge(...array_values(self::TABLES)), true) => 'Err: refused, not a table Central copies: ',
            $type === null                                                => "Err: refused, module $module does not exist here - is the sending Central up to date?: ",
            !in_array($table, self::TABLES[$type] ?? [], true)            => "Err: refused, not a table of a module of type $type: ",
            default                                                       => null,
        };
        if ($reason === null) {
            $this->lookUp($table, $rows);
        }
        foreach ($rows as $key => $row) {
            $why = $reason ?? match (true) {
                !$this->belongs($table, $row, $module)                                => self::REFUSED,
                ($table === 'profile') && (self::text($row['uid'] ?? '') !== '*') => 'Skipped, profile of a user: ',
                default                                                               => null,
            };
            if ($why === null) {
                $this->insert($table, $row, $module);
            } else {
                $this->report[$why][$table][] = $this->ident($table, $row, $key);
            }
        }
        if (($reason === null) && ($table === 'constant')) {
            $this->appropriate($module);
        }
    }

    /** the type a `module` chunk creates - of its row of the module ('S' = the column default) @param array<mixed> $rows */
    private static function moduleType(array $rows, string $module): string
    {
        foreach ($rows as $row) {
            if (is_array($row) && (strtolower(self::text($row['id'] ?? '')) === $module)) {
                return self::text($row['type'] ?? 'S');
            }
        }
        return 'S';
    }

    /** what the rows need to know of this AA - asked once per chunk, before any insert @param array<mixed> $rows */
    private function lookUp(string $table, array $rows): void
    {
        [$this->groups, $this->filled, $this->owners] = [[], [], []];
        $rows = array_filter($rows, 'is_array');
        if (in_array($table, ['constant', 'constant_slice'], true)) {
            $limit = $this->schema->tableLimits('constant')['group_id'][0];     // a longer name would be cut into another group
            foreach ($rows as $row) {
                $name = self::groupName($table, $row);
                if (($name !== '') && (strlen($name) <= $limit) && !array_key_exists($name, $this->groups)) {
                    $owner               = $this->target->groupOwner($name);
                    $this->groups[$name] = ($owner === null) ? null : strtolower($owner);
                }
            }
        } elseif (in_array($table, ['content', 'discussion'], true)) {
            $ids          = array_values(array_unique(array_filter(array_map(static fn(array $r): string => strtolower(self::text($r['item_id'] ?? '')), $rows), self::isId(...))));
            $this->owners = $ids ? array_map('strtolower', $this->target->itemSlices($ids)) : [];
        } elseif (str_starts_with($table, 'alerts_collection_')) {
            $this->owners = array_map('strtolower', $this->target->collectionModules(array_values(array_unique(array_map(static fn(array $r): string => self::text($r['collectionid'] ?? ''), $rows)))));
        }
    }

    /** F4 (see the class doc), and only scalar values */
    private function belongs(string $table, mixed $row, string $module): bool
    {
        if (!is_array($row) || array_filter($row, static fn($v): bool => !is_scalar($v) && ($v !== null))) {
            return false;
        }
        if ((($column = AA_Metabase::getModuleField($table)) !== null) && (strtolower(self::text($row[$column] ?? '')) !== $module)) {
            return false;
        }
        $group = self::groupName($table, $row);
        return match ($table) {
            'constant', 'constant_slice' => array_key_exists($group, $this->groups) && in_array($this->groups[$group], [null, $module], true),
            'content', 'discussion'      => ($this->owners[strtolower(self::text($row['item_id'] ?? ''))] ?? null) === $module,
            'alerts_collection_filter', 'alerts_collection_howoften' => ($this->owners[self::text($row['collectionid'] ?? '')] ?? null) === $module,
            default                      => true,
        };
    }

    /** an AUTO_INCREMENT number taken here: once more without it @param array<mixed> $row */
    private function insert(string $table, array $row, string $module): void
    {
        $data   = $this->clean($table, $row, $module);
        $result = $this->metabase->doInsert($table, $data, 'nohalt');
        $auto   = $this->autoColumn($table);
        if (!$result && ($auto !== null) && isset($data[$auto]) && ($result = $this->metabase->doInsert($table, array_diff_key($data, [$auto => 0]), 'nohalt'))) {
            $this->notes[] = "$table $auto {$data[$auto]} taken here → " . ((is_int($result) && ($result < PHP_INT_MAX)) ? $result : 'new') . ' (references not rewritten)';
        }
        $this->report[$result ? 'OK: ' : 'Err: '][$table][] = $this->ident($table, $row, 0);
        if ($result && ($table === 'constant')) {
            $this->filled[self::groupName($table, $row)] = true;
        }
    }

    /** the row with only the columns chunk mode copies (see the class doc) @param array<mixed> $row @return array<mixed> */
    private function clean(string $table, array $row, string $module): array
    {
        $data = array_intersect_key($row, array_flip($this->columns($table)));
        foreach (self::FOREIGN[$table] ?? [] as $column) {
            if (!in_array($value = strtolower(self::text($data[$column] ?? '')), ['', '0', $module], true)) {
                unset($data[$column]);
                $this->notes[] = "$table of $module: $column $value cleared - not of this module";
            }
        }
        switch ($table) {
            case 'slice':
                if (self::text($row['reading_password'] ?? '') !== '') {
                    $data['reading_password'] = bin2hex(random_bytes(8));
                    $this->notes[]            = "slice $module: a new random reading password - set it in the slice settings";
                }
                $data += ['deleted' => 0, 'created_by' => $this->target->user(), 'created_at' => $this->target->now()];
                break;
            case 'module':
                $data += ['deleted' => 0, 'created_by' => $this->target->user(), 'created_at' => $this->target->now()];
                break;
            case 'email':
                if (SecurityLint::attachments(self::text($data['attachments'] ?? ''))?->code === SecurityLint::ATTACHMENTS_DROPPED) {
                    $data['attachments'] = '';
                    $this->notes[]       = 'email ' . self::text($row['id'] ?? '') . ': attachments cleared (a local file or a computed value)';
                }
                break;
            case 'site':
                if (Schema::controlFile($data)) {
                    unset($data['state_file']);
                    $this->notes[] = "site $module: control file state_file dropped";
                }
                break;
            case 'alerts_collection_howoften':
                $data['last'] = $this->target->now();
                break;
        }
        return $data;
    }

    /** a constant into a new group: the group becomes the module's - a later chunk of it is then the module's own */
    private function appropriate(string $module): void
    {
        foreach ($this->groups as $name => $owner) {
            if (($owner === null) && isset($this->filled[$name])) {
                if ($this->metabase->doInsert('constant_slice', ['group_id' => (string) $name, 'slice_id' => $module], 'nohalt')) {
                    $this->notes[] = "constant group $name now owned by $module";
                } else {
                    $this->report['Err: ']['constant_slice'][] = [$name];
                }
            }
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= in_array($table, self::ITEM_TABLES, true)
            ? array_map('strval', array_keys(AA_Metabase::singleton()->getColumns($table)))
            : [...array_keys($this->schema->rowColumns($table)), ...array_keys($this->schema->rowAttributes($table)), ...(self::KEPT[$table] ?? [])];
    }

    /** the AUTO_INCREMENT column the chunk carries - view.id, email.id, item.short_id (the others are not copied) */
    private function autoColumn(string $table): ?string
    {
        foreach (AA_Metabase::singleton()->getColumns($table) as $name => $column) {
            if (($column->c[5] === 'auto_increment') && in_array((string) $name, $this->columns($table), true)) {
                return (string) $name;
            }
        }
        return null;
    }

    /** the row by its table keys, as today's report; a table unknown to Central by the row's key @return array<int, mixed> */
    private function ident(string $table, mixed $row, int|string $key): array
    {
        if (!in_array($table, [...self::DROPPED, ...array_merge(...array_values(self::TABLES))], true)) {
            return [$key];
        }
        return array_map(static fn(string $k): mixed => (is_array($row) && is_scalar($row[$k] ?? null)) ? $row[$k] : '', $this->metabase->getKeys($table));
    }

    /** the group of a constant row - a row of lt_groupNames names it in `value` @param array<mixed> $row */
    private static function groupName(string $table, array $row): string
    {
        $group = self::text($row['group_id'] ?? '');
        return (($table === 'constant') && ($group === 'lt_groupNames')) ? self::text($row['value'] ?? '') : $group;
    }

    private static function isId(string $id): bool
    {
        return (strlen($id) === 32) && ctype_xdigit($id);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
