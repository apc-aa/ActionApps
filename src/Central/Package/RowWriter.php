<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA_Metabase;
use LogicException;

/** Writes the table rows of an import plan (design balicek-v1.md § 7.2 step 5.1-5.5, § 6; C4.4 part 4a)
 *  - inside the transaction Importer opens; objects and items are part 4c.
 *
 *  1. view and email rows of every module WITHOUT id (AUTO_INCREMENT), their texts known()-rewritten
 *     (long ids, alerts field ids, groups) => the view and e-mail maps;
 *  2. alerts_filter rows once all views have numbers (`vid` is a view; FixUps left a shared filter in
 *     its first module only) => the filter map; no text names a filter;
 *  3. the numbers into the texts of step 1: UPDATE of the columns numeric() changed;
 *  4. per module in package order: `module`, then the rows of its type -
 *     slice, field, profile (uid '*'); site (structure = serialize(sitetree::fromArray()) of the
 *     rewritten tree, or the '' of FixUps), site_spot, relation (`uses`); alerts_collection and its
 *     filter / howoften rows;
 *  5. constant groups: a locking re-check of the name (ImportTarget::groupTaken($lock)), lt_groupNames
 *     (name = value = the new name), constant_slice, constant.
 *  Every value through RowStore (numeric(known()), the charset last, the lengths).
 *
 *  Structured references are mapped here, not rewritten: slice.vid, profile input_view, alerts_filter.vid
 *  (views), emailid_* (e-mails), filterid (filters), collection ids, uses / slice_id / mlxctrl / group
 *  owner (modules), constant ids. Outside the package:
 *  - slice.vid, profile input_view: the number stays (the Validator's W - as the rewrite leaves unknown
 *    numbers in texts);
 *  - slice.mlxctrl: '' (§ 6.2 - a slice of the target would control this one's languages);
 *  - alerts_filter.vid, emailid_*, filterid, uses, alerts_collection.slice_id, a group owner: never - the
 *    Validator's E (a new collection mailing a view or template of the target to readers the package
 *    brings); here a LogicException. A 0 / empty view or e-mail is "none" and stays.
 *
 *  Alerts field ids (`alerts1....<cid>`) of a collection the package does not carry stay as they are
 *  (the planner's I Planner::ALERTS_FIELD_KEPT): AA looks such a field up only through a collection whose
 *  reader slice (alerts_collection.slice_id) is this new slice, so it is an inert definition; dropping it
 *  would change the package after the preview and lose the field's item values.
 *
 *  @internal only Importer calls it - the rights and the S confirmation are checked there (Importer::write()). */
final class RowWriter
{
    /** module.type of a package wrapper */
    private const MODULE_TYPES = [Module::SLICE => 'S', Module::SITE => 'W', Module::ALERTS => 'Alerts'];
    /** relation.flag of "uses modules" - REL_FLAG_MODULE_DEPEND, include/constants.php */
    private const USES_FLAG = 1;

    private ImportPlan $plan;
    private IdMap $map;
    private ImportRewrite $rewrite;
    /** @var list<array{string, int, array<string, mixed>, array<string, int|string|null>, string, string, array<string, mixed>}>
     *       step 1 rows: [element, number, package row, fixed, charset, path, values written] */
    private array $numbered = [];

    public function __construct(
        private readonly RowStore $store,
        private readonly Schema $schema,
        private readonly ImportTarget $target,
        private readonly string $user,
        private readonly int $now,
    ) {}

    /** @return IdMap the plan's map with the view, e-mail and filter numbers
     *  @throws WriteError|RewriteFailed|LogicException|\InvalidArgumentException (sitetree::fromArray()) */
    public function write(ImportPlan $plan): IdMap
    {
        [$this->plan, $this->map, $this->numbered] = [$plan, $plan->map, []];
        $this->rewrite = new ImportRewrite($this->map);          // no numbers yet: numeric() changes nothing
        $numbers       = ['view' => [], 'email' => []];
        foreach ($plan->package->modules as $m) {
            foreach (array_keys($numbers) as $element) {
                foreach ($m->rows[$element] ?? [] as $i => $row) {
                    $numbers[$element][(int) ValueCodec::bytes($row['id'] ?? '')] = $this->numbered($m, $element, $row, $i);
                }
            }
        }
        $this->map     = $this->map->withViews($numbers['view'])->withEmails($numbers['email']);
        $this->rewrite = new ImportRewrite($this->map);
        $filters       = [];
        foreach ($plan->package->modules as $m) {
            foreach ($m->rows['alerts_filter'] ?? [] as $i => $row) {
                $fixed  = self::mapped($row, 'vid', fn(string $v): int|string => $this->inPackage($v, $this->map->view((int) $v), 'View'));
                $number = $this->insert($m, 'alerts_filter', array_diff_key($row, ['id' => 1]), $i, $fixed, $row);
                $filters[(int) ValueCodec::bytes($row['id'] ?? '')] = $number;
            }
        }
        $this->map = $this->map->withFilters($filters);
        $this->renumber();
        foreach ($plan->package->modules as $m) {
            $this->module($m);
        }
        foreach ($plan->package->constantGroups as $group) {
            $this->group($group);
        }
        return $this->map;
    }

    /** step 1: a view or e-mail row without its id; its number */
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

    /** step 3: the view and e-mail numbers into the texts of the step 1 rows */
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
        $this->insert($m, 'module', $m->rows['module'][0] ?? [], 0, [
            'id'         => $id,
            'type'       => self::MODULE_TYPES[$m->type],
            'lang_file'  => $this->plan->langFiles[$m->id] ?? throw new LogicException("No lang_file for module $m->id"),
            'deleted'    => 0,
            'created_by' => $this->user,
            'created_at' => $this->now,
        ]);
        match ($m->type) {
            Module::SLICE  => $this->slice($m, $id),
            Module::SITE   => $this->site($m, $id),
            Module::ALERTS => $this->alerts($m, $id),
            default        => throw new LogicException("Unknown module type $m->type"),
        };
    }

    private function slice(Module $m, string $id): void
    {
        $slice = $m->rows['slice'][0] ?? [];
        $fixed = ['id' => $id, 'deleted' => 0, 'created_by' => $this->user, 'created_at' => $this->now]
            + self::mapped($slice, 'vid', $this->view(...))
            + self::mapped($slice, 'mlxctrl', fn(string $v): string => $this->map->module($v) ?? '');
        $this->insert($m, 'slice', $slice, 0, $fixed);
        foreach ($m->rows['field'] ?? [] as $i => $row) {
            $this->insert($m, 'field', $row, $i, ['slice_id' => $id]);
        }
        foreach ($m->rows['profile'] ?? [] as $i => $row) {
            $view = (ValueCodec::bytes($row['property'] ?? '') === 'input_view') ? self::mapped($row, 'value', $this->view(...)) : [];
            $this->insert($m, 'profile', $row, $i, ['slice_id' => $id, 'uid' => '*'] + $view);
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

    /** site.structure: the spot tree with its texts through RowStore (names, variables, conditions -
     *  AA expressions, § 6.3), serialize()d as the site admin stores it */
    private function structure(Module $m): string
    {
        $tree = $m->spotTree ?? throw new LogicException("Site $m->id has neither a spot tree nor site.structure");
        $text = fn(mixed $value, string $at): string => (string) $this->store->value(ValueCodec::bytes($value), $this->charset($m), $this->rewrite, $at);
        foreach (is_array($tree['spots'] ?? null) ? $tree['spots'] : [] as $k => $spot) {
            $at        = "$m->type[$m->id]/spot-tree/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']';
            $spot['n'] = $text($spot['n'] ?? '', "$at@name");
            if (is_array($spot['v'] ?? null)) {
                $vars = [];
                foreach (array_values($spot['v']) as $j => $var) {
                    $name        = $text($var, "$at/var[#$j]");
                    $vars[$name] = $name;           // keyed by its name (sitetree::fromArray())
                }
                $spot['v'] = $vars;
            }
            if (is_array($spot['c'] ?? null)) {
                $conds = [];
                foreach (array_keys($spot['c']) as $j => $var) {
                    $conds[$text((string) $var, "$at/cond[#$j]@var")] = $text($spot['c'][$var], "$at/cond[#$j]");
                }
                $spot['c'] = $conds;
            }
            $tree['spots'][$k] = $spot;
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

    /** lt_groupNames, constant_slice, constants of a group - in the charset of the group. The name is read
     *  again with a lock right before it is created: the plan is no reservation, the start of the
     *  transaction (Importer) read without one. */
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

    /** [column => $fn(value)] when the row has the column, NULL staying NULL - a structured reference
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
