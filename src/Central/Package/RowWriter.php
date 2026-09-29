<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use LogicException;

/** Writes the table rows of an import plan inside the transaction Importer opens: views and e-mails
 *  first without id (AUTO_INCREMENT numbers them, the texts are then updated), the alerts filters; then
 *  per module `module` and the rows of its type; then the constant groups. Every value through RowStore.
 *
 *  Structured references are mapped, not rewritten. Outside the package: slice.vid and profile input_view
 *  stay (W); slice.mlxctrl = '' (a target slice would control this one's languages); alerts references
 *  to the target are the Validator's E - a new collection must not mail target views to the package's
 *  readers - here a LogicException. 0 / empty is "none".
 *  A transfer brings the state of the source installation (Module::$transfer): the owner, found on the
 *  target by name and e-mail or created, the reading password and the values of the settings fields.
 *  An install gives the settings fields of a new slice, and those it adds to an existing one, their
 *  defaults (ImportPlan::$settingDefaults), and its new modules the owner the preview chose (ImportPlan::$owner).
 *  A slice going into an existing slice (ImportPlan::$existing) gets only its new fields here - the plan
 *  left out the rest; views and e-mails the slice has are numbered in the plan's map already.
 *  @internal only Importer calls it - the rights and the S confirmation are checked there (Importer::write()). */
final class RowWriter
{
    /** relation.flag of "uses modules" - REL_FLAG_MODULE_DEPEND, include/constants.php */
    private const USES_FLAG = 1;

    private ImportPlan $plan;
    private IdMap $map;
    private ImportRewrite $rewrite;
    /** @var list<array{string, int, array<string, mixed>, array<string, int|string|null>, string, string, array<string, mixed>}>
     *       rows numbered() wrote: [element, number, package row, fixed, charset, path, values written] */
    private array $numbered = [];

    public function __construct(
        private readonly RowStore $store,
        private readonly Schema $schema,
        private readonly ImportTarget $target,
        private readonly string $user,
        private readonly int $now,
    ) {}

    /** @param IdMap $map the plan's map, in a copy in the same AA with the package items the texts name
     *  @return IdMap $map with the view, e-mail and filter numbers
     *  @throws WriteError|RewriteFailed|LogicException|\InvalidArgumentException (sitetree::fromArray()) */
    public function write(ImportPlan $plan, IdMap $map): IdMap
    {
        [$this->plan, $this->map, $this->numbered] = [$plan, $map, []];
        $this->rewrite = new ImportRewrite($this->map);          // no numbers yet; renumber() fixes the texts
        $numbers       = ['view' => $map->views(), 'email' => $map->emails()];      // those an existing slice has
        foreach ($plan->package->modules as $m) {
            foreach (array_keys($numbers) as $element) {
                foreach ($m->rows[$element] ?? [] as $i => $row) {
                    $numbers[$element][(int) ValueCodec::bytes($row['id'] ?? '')] = $this->numbered($m, $element, $row, $i);
                }
            }
        }
        $this->map     = $this->map->withNumbers('view', $numbers['view'])->withNumbers('email', $numbers['email']);
        $this->rewrite = new ImportRewrite($this->map);
        $filters       = [];
        foreach ($plan->package->modules as $m) {
            foreach ($m->rows['alerts_filter'] ?? [] as $i => $row) {
                $fixed  = self::mapped($row, 'vid', fn(string $v): int|string => $this->inPackage($v, $this->map->view((int) $v), 'View'));
                $number = $this->insert($m, 'alerts_filter', array_diff_key($row, ['id' => 1]), $i, $fixed, $row);
                $filters[(int) ValueCodec::bytes($row['id'] ?? '')] = $number;
            }
        }
        $this->map = $this->map->withNumbers('filter', $filters);
        $this->renumber();
        foreach ($plan->package->modules as $m) {
            $this->module($m);
        }
        foreach ($plan->package->constantGroups as $group) {
            $this->group($group);
        }
        return $this->map;
    }

    /** a view or e-mail row without its id; its new number */
    private function numbered(Module $m, string $element, array $row, int $i): int
    {
        $table  = $this->schema->rowTables()[$element];
        $path   = $this->schema->rowPath("$m->type[$m->id]", $element, $row, $i);
        $fixed  = [(string) AA_Metabase::getModuleField($table) => $this->moduleId($m)];
        $source = array_diff_key($row, ['id' => 1]);
        $values = $this->store->row($element, $source, $fixed, $this->charset($m), $this->rewrite, $path);
        $number = $this->store->insert($table, $values, $path);
        $this->numbered[] = [$element, $number, $source, $fixed, $this->charset($m), $path, $values];
        return $number;
    }

    /** the new view and e-mail numbers into the texts of the rows numbered() wrote */
    private function renumber(): void
    {
        foreach ($this->numbered as [$element, $number, $source, $fixed, $charset, $path, $written]) {
            $values  = $this->store->row($element, $source, $fixed, $charset, $this->rewrite, $path);
            $changed = array_filter($values, static fn($v, $k): bool => $v !== ($written[$k] ?? null), ARRAY_FILTER_USE_BOTH);
            $this->store->update($this->schema->rowTables()[$element], ['id' => $number], $changed, $path);
        }
    }

    private function module(Module $m): void
    {
        $id = $this->moduleId($m);
        if (isset($this->plan->existing[$m->id])) {         // the slice stays as it is; it gets only the fields it lacks
            $this->fields($m, $id);
            $this->settingValues($m, $id, $this->plan->settingDefaults[$m->id] ?? []);
            return;
        }
        $owner = isset($m->transfer['owner']) ? $this->owner($m, $m->transfer['owner']) : $this->plan->owner;
        $this->insert($m, 'module', $m->rows['module'][0] ?? [], 0, [
            'id'         => $id,
            'type'       => array_search($m->type, Module::TYPES, true),
            'lang_file'  => $this->plan->langFiles[$m->id] ?? throw new LogicException("No lang_file for module $m->id"),
            'deleted'    => 0,
            'created_by' => $this->user,
            'created_at' => $this->now,
            'owner'      => $owner,
            'slice_url'  => '',          // as the module settings save "no URL"
        ]);
        match ($m->type) {
            Module::SLICE  => $this->slice($m, $id, $owner),
            Module::SITE   => $this->site($m, $id),
            Module::ALERTS => $this->alerts($m, $id),
            default        => throw new LogicException("Unknown module type $m->type"),
        };
    }

    /** @param string $owner module.owner - the slice settings write it to both tables */
    private function slice(Module $m, string $id, string $owner): void
    {
        $slice = $m->rows['slice'][0] ?? [];
        $fixed = [
            'id'          => $id,
            'deleted'     => 0,
            'created_by'  => $this->user,
            'created_at'  => $this->now,
            'owner'       => $owner,
            'slice_url'   => '',
            'fileman_dir' => '',         // as the slice settings save "no directory"
        ]
            + self::mapped($slice, 'vid', $this->view(...))
            + self::mapped($slice, 'mlxctrl', fn(string $v): string => $this->map->module($v) ?? '');
        if (isset($m->transfer['reading-password'])) {
            $fixed['reading_password'] = $this->store->plain($m->transfer['reading-password'], $this->charset($m), "$m->type[$m->id]/reading-password", $this->store->limit('slice', 'reading_password'));
        }
        $this->insert($m, 'slice', $slice, 0, $fixed);
        $this->fields($m, $id);
        foreach ($m->rows['profile'] ?? [] as $i => $row) {
            $view = (ValueCodec::bytes($row['property'] ?? '') === 'input_view') ? self::mapped($row, 'value', $this->view(...)) : [];
            $this->insert($m, 'profile', $row, $i, ['slice_id' => $id, 'uid' => '*'] + $view);
        }
        $this->settingValues($m, $id, ($m->transfer['setting-values'] ?? []) + ($this->plan->settingDefaults[$m->id] ?? []));
    }

    private function fields(Module $m, string $id): void
    {
        foreach ($m->rows['field'] ?? [] as $i => $row) {
            $this->insert($m, 'field', $row, $i, ['slice_id' => $id]);
        }
    }

    /** the values of the settings fields a transfer brings (keyed as Collector::settingValues() gave them),
     *  or the defaults of an install (SettingDefaults): content rows of the slice id - FLAG_TEXT_STORED says
     *  text or number
     *  @param array<string, array<int, array{value: mixed, flag?: int}>> $settings */
    private function settingValues(Module $m, string $id, array $settings): void
    {
        foreach ($settings as $field => $values) {
            foreach ($values as $key => $v) {
                $at    = "$m->type[$m->id]/setting-values/{$field}[$key]";
                $flag  = (int) ($v['flag'] ?? 0);
                $text  = ($flag & FLAG_TEXT_STORED) !== 0;
                $value = $text ? $this->store->value($v['value'], $this->charset($m), $this->rewrite, $at, $this->store->limit('content', 'text')) : $v['value'];
                $row   = ItemWriter::contentRow($id, (string) $field, $text, ValueCodec::bytes($value), (int) $key, $flag);
                $this->store->insert('content', $this->store->tableRow('content', [], $row, $this->charset($m), $this->rewrite, $at), $at);
            }
        }
    }

    private function site(Module $m, string $id): void
    {
        $site  = $m->rows['site'][0] ?? [];
        $fixed = ['id' => $id] + (array_key_exists('structure', $site) ? [] : ['structure' => $this->structure($m)]);
        $this->insert($m, 'site', $site, 0, $fixed);
        foreach ($m->rows['site_spot'] ?? [] as $i => $row) {
            $this->insert($m, 'site_spot', $row, $i, ['site_id' => $id]);
        }
        foreach ($m->rows['uses'] ?? [] as $i => $row) {
            $destination = $this->packageModule(ValueCodec::bytes($row['destination_id'] ?? ''));
            $this->insert($m, 'uses', $row, $i, ['source_id' => $id, 'destination_id' => $destination, 'flag' => self::USES_FLAG]);
        }
    }

    /** module.owner: the target's slice_owner of the name and e-mail, or a new one - the source's id means
     *  nothing here @param array{name: mixed, email: mixed} $owner */
    private function owner(Module $m, array $owner): string
    {
        $path   = "$m->type[$m->id]/owner";
        $values = [];
        foreach (['name', 'email'] as $column) {
            $values[$column] = $this->store->plain($owner[$column], $this->charset($m), "$path/$column", $this->store->limit('slice_owner', $column));
        }
        $id = DB_AA::select1('id', 'SELECT LOWER(HEX(`id`)) AS id FROM `slice_owner`', [['name', $values['name']], ['email', $values['email']]]);
        if (is_string($id) && ($id !== '')) {
            return $id;
        }
        $id = strtolower($this->target->newId());
        $this->store->insert('slice_owner', $this->store->tableRow('slice_owner', [], ['id' => $id] + $values, $this->charset($m), $this->rewrite, $path), $path);
        return $id;
    }

    /** site.structure: the spot tree with its texts through RowStore, serialize()d as the site admin stores it */
    private function structure(Module $m): string
    {
        $tree = $m->spotTree ?? throw new LogicException("Site $m->id has neither a spot tree nor site.structure");
        $text = fn(mixed $value, string $at): string => (string) $this->store->value(ValueCodec::bytes($value), $this->charset($m), $this->rewrite, $at);
        foreach (is_array($tree['spots'] ?? null) ? $tree['spots'] : [] as $k => $spot) {
            $tree['spots'][$k] = LintTexts::mapSpot("$m->type[$m->id]/spot-tree/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']', $spot, $text);
        }
        return serialize(\sitetree::fromArray($tree));
    }

    private function alerts(Module $m, string $id): void
    {
        $collection = $m->rows['alerts_collection'][0] ?? null;
        if ($collection !== null) {
            $cid   = $this->collection($collection['id'] ?? '');
            $email = fn(string $v): int|string => $this->inPackage($v, $this->map->email((int) $v), 'E-mail');
            $fixed = ['id' => $cid, 'module_id' => $id]
                + self::mapped($collection, 'emailid_welcome', $email) + self::mapped($collection, 'emailid_alert', $email)
                + self::mapped($collection, 'slice_id', fn(string $v): string => ($v === '') ? '' : $this->packageModule($v));
            $this->insert($m, 'alerts_collection', $collection, 0, $fixed);
        }
        foreach ($m->rows['alerts_collection_filter'] ?? [] as $i => $row) {
            $filter = fn(string $v): int|string => $this->inPackage($v, $this->map->filter((int) $v), 'Filter');
            $fixed  = ['collectionid' => $this->collection($row['collectionid'] ?? '')] + self::mapped($row, 'filterid', $filter);
            $this->insert($m, 'alerts_collection_filter', $row, $i, $fixed);
        }
        foreach ($m->rows['alerts_collection_howoften'] ?? [] as $i => $row) {
            $fixed = ['collectionid' => $this->collection($row['collectionid'] ?? ''), 'last' => $this->now];
            $this->insert($m, 'alerts_collection_howoften', $row, $i, $fixed);
        }
    }

    /** lt_groupNames, constant_slice, constants of a group - in the charset of the group. The name is
     *  re-checked with a lock: the plan reserves nothing. */
    private function group(ConstantGroup $g): void
    {
        $name    = $this->map->group($g->name) ?? throw new LogicException("Constant group $g->name is not mapped");
        $charset = $this->plan->groupCharsets[$g->name] ?? throw new LogicException("No charset for constant group $g->name");
        $path    = "constant-group[$g->name]";
        if ($this->target->groupTaken($name, true)) {
            throw new WriteError(WriteError::TAKEN, "$path/lt_groupNames", "the constant group $name was created since the plan");
        }
        $row_id = strtolower($this->target->newId());
        $fixed  = ['id' => $row_id, 'group_id' => 'lt_groupNames', 'name' => $name, 'value' => $name, 'pri' => 100];
        $this->store->insert('constant', $this->store->row('constant', [], $fixed, $charset, $this->rewrite, $path), "$path/lt_groupNames");
        $owner = ($g->owner === '') ? [] : ['slice_id' => $this->packageModule($g->owner)];
        foreach ($g->rows['constant_slice'] ?? [] as $i => $row) {
            $this->writeRow($path, 'constant_slice', $row, $i, ['group_id' => $name] + $owner, $charset);
        }
        foreach ($g->rows['constant'] ?? [] as $i => $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            $id = $this->map->longId($id) ?? throw new LogicException("Constant $id is not mapped");
            $this->writeRow($path, 'constant', $row, $i, ['group_id' => $name, 'id' => $id], $charset);
        }
    }

    /** a row of a module in its charset; the AUTO_INCREMENT number or 0
     *  @param array<string, mixed>           $row
     *  @param array<string, int|string|null> $fixed
     *  @param array<string, mixed>|null      $keyed the row its path names (with its key) */
    private function insert(Module $m, string $element, array $row, int $i, array $fixed, ?array $keyed = null): int
    {
        return $this->writeRow("$m->type[$m->id]", $element, $row, $i, $fixed, $this->charset($m), $keyed);
    }

    /** @param array<string, mixed> $row @param array<string, int|string|null> $fixed @param array<string, mixed>|null $keyed */
    private function writeRow(string $path, string $element, array $row, int $i, array $fixed, string $charset, ?array $keyed = null): int
    {
        $at = $this->schema->rowPath($path, $element, $keyed ?? $row, $i);
        $table = $this->schema->rowTables()[$element];
        return $this->store->insert($table, $this->store->row($element, $row, $fixed, $charset, $this->rewrite, $at), $at);
    }

    /** [column => $fn(value)] when the row has the column, NULL staying NULL
     *  @param array<string, mixed> $row @param callable(string): (int|string) $fn @return array<string, int|string|null> */
    private static function mapped(array $row, string $column, callable $fn): array
    {
        if (!array_key_exists($column, $row)) {
            return [];
        }
        return [$column => ($row[$column] === null) ? null : $fn(ValueCodec::bytes($row[$column]))];
    }

    /** a view number of slice.vid / profile input_view: mapped, or as it is (W) */
    private function view(string $number): int|string
    {
        return $this->map->view((int) $number) ?? $number;
    }

    /** a number that must be of the package: mapped; 0 / empty = none, as it is */
    private function inPackage(string $number, ?int $new, string $what): int|string
    {
        if (((int) $number) <= 0) {
            return $number;
        }
        return $new ?? throw new LogicException("$what $number is not in the package (the Validator's E)");
    }

    /** a module id that must be of the package */
    private function packageModule(string $id): string
    {
        return $this->map->module($id) ?? throw new LogicException("Module $id is not in the package (the Validator's E)");
    }

    private function collection(mixed $old): string
    {
        $old = ValueCodec::bytes($old);
        return $this->map->collection($old) ?? throw new LogicException("Alerts collection $old is not mapped");
    }

    private function moduleId(Module $m): string
    {
        return $this->map->module($m->id) ?? throw new LogicException("Module $m->id is not mapped");
    }

    private function charset(Module $m): string
    {
        return $this->plan->charsets[$m->id] ?? throw new LogicException("No charset for module $m->id");
    }
}
