<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Difference;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;

/** The slices of a package the preview sends into existing slices of the target - so an extension (a new
 *  slice and some fields of a slice the target has) can be installed. What the target has stays as it is:
 *  the module and slice rows, the profiles, the settings and the state a transfer brings; fields by id;
 *  views and e-mails by name, as Synchronize pairs them (Difference::idsByName()); objects by class and
 *  name. What it lacks is added. The package slice's id maps to the existing slice, so every reference of
 *  the package - relations, sets, alerts - goes there. */
final class ExistingSlices
{
    /** I: the slice goes into an existing slice; ref = its id */
    public const INTO_EXISTING = 'into-existing';
    /** E: the chosen slice cannot take it; ref = "<id>: why" - no such slice, may not edit it, no charset, chosen twice, no slice of the package */
    public const TARGET_SLICE = 'target-slice';
    /** E: a transfer in the same AA into the very slice it copies - the items would be copied into it again; ref = the id */
    public const INTO_SOURCE = 'into-source';
    /** E: a Reader Management slice into another kind, or the other way - the readers' logins are handled otherwise; ref = "package => target" */
    public const SLICE_TYPE = 'slice-type-differs';
    /** W: a field the slice has differs from the package's - the slice's stays; ref = the columns */
    public const FIELD_DIFFERS = 'field-differs';
    /** W: a transfer, and a relation field of the slice selects from another slice than the package's points
     *  to - the items' values name items of that one; ref = "the slice selects from X, the items will point to Y" */
    public const RELATION_TARGET = 'relation-target-differs';
    /** E: a transfer, and the slice's field stores its values otherwise (text / number, a password hash) - the items would be broken; ref = the columns */
    public const FIELD_TYPE = 'field-type-differs';
    /** I: the slice has a view of the name - it stays, the package's references go to it; ref = its id */
    public const VIEW_EXISTS = 'view-exists';
    /** I: as VIEW_EXISTS, an e-mail by its description */
    public const EMAIL_EXISTS = 'email-exists';
    /** I: as VIEW_EXISTS, an object by its class and name */
    public const OBJECT_EXISTS = 'object-exists';
    /** W: the slice's view of the name differs from the package's in its type or main format - the slice's stays; ref = the columns */
    public const VIEW_DIFFERS = 'view-differs';
    /** W: a transfer into a slice with fields unique across the slice (unique validation, SEO name, a
     *  sequence) - the items coming may collide with those there; ref = the fields */
    public const UNIQUE_FIELD = 'unique-field';
    /** I: a constant group a kept field names - the package's references go to the group the slice's field names; ref = "package => slice" */
    public const GROUP_KEPT = 'group-kept';
    /** I: as GROUP_KEPT, a group the package does not carry - its references go to the slice's group; ref = "package => slice" */
    public const GROUP_REDIRECTED = 'group-redirected';
    /** W: a view with no name, or a name repeated on either side, is added as new - it may duplicate one; ref = the name */
    public const VIEW_UNMATCHED = 'view-unmatched';
    /** W: as VIEW_UNMATCHED, an e-mail */
    public const EMAIL_UNMATCHED = 'email-unmatched';
    /** W: as VIEW_UNMATCHED, an object; ref = class[:name] */
    public const OBJECT_UNMATCHED = 'object-unmatched';
    /** I: the state of the source slice a transfer brings is not written; ref = its parts */
    public const STATE_KEPT = 'slice-state-kept';
    /** I: a constant group only rows the slice keeps use - not written; ref = its name */
    public const GROUP_LEFT_OUT = 'group-left-out';

    /** field columns that decide how ItemWriter stores an item's values */
    private const STORAGE = ['text_stored', 'input_insert_func'];
    /** view columns whose difference is worth a W: what the view is and what it prints per item */
    private const VIEW_COLUMNS = ['type', 'odd'];
    /** input_validate / input_insert_func of fields unique across the slice (include/validate.php, itemfunc.php) */
    private const UNIQUE_VALIDATE = ['unique', 'e-unique', 'e_unique', 'eunique'];
    private const UNIQUE_INSERT   = ['seo', 'seq', 'unq'];

    /** @var array<string, ExistingSlice> source slice id => the slice it goes into */
    private array $targets = [];
    /** @var array<int, int> view ids of the package => the slice's views of the name */
    private array $views = [];
    /** @var array<int, int> */
    private array $emails = [];
    /** @var array<string, string> object ids of the package => the slice's objects */
    private array $objects = [];
    /** @var array<string, list<array{int, array<string, mixed>}>> source slice => [index, row] of the fields the slice has */
    private array $kept = [];
    /** @var array<string, list<array{int, array<string, mixed>, int}>> source slice => [index, row, id there] of the views the slice has */
    private array $keptViews = [];
    /** @var array<string, string> groups of the package kept fields name => the group the slice's field names */
    private array $groupMap = [];

    public function __construct(private readonly ImportTarget $target, private readonly Schema $schema, private readonly FindingList $findings) {}

    /** @return array<string, ExistingSlice> source slice id => the slice it goes into */
    public function targets(): array
    {
        return $this->targets;
    }

    /** @return array<int, int> */
    public function views(): array
    {
        return $this->views;
    }

    /** @return array<int, int> */
    public function emails(): array
    {
        return $this->emails;
    }

    /** @return array<string, string> */
    public function objects(): array
    {
        return $this->objects;
    }

    /** @return array<string, string> package group => the slice's group (IdMap groups) */
    public function groupMap(): array
    {
        return $this->groupMap;
    }

    /** The package without what the chosen slices keep.
     *  @param array<string, string> $into   source slice id => the id of a slice of this AA
     *  @param bool                  $sameAa the source is this AA - a slice of the package is here too */
    public function prune(Package $package, array $into, bool $transfer, bool $sameAa): Package
    {
        $chosen = [];
        $own    = array_map(static fn(Module $m): string => strtolower($m->id), array_filter($package->modules, static fn(Module $m): bool => $m->type === Module::SLICE));
        foreach ($into as $old => $id) {
            [$old, $id] = [strtolower((string) $old), strtolower($id)];
            $found      = array_values(array_filter($package->modules, static fn(Module $m): bool => strtolower($m->id) === $old));
            $slice      = $this->choose($found[0] ?? null, $old, $id, $chosen, ($transfer && $sameAa) ? $own : []);
            if ($slice !== null) {
                $this->targets[$found[0]->id] = $slice;
            }
            $chosen[] = $id;
        }
        if (!$this->targets) {
            return $package;
        }
        $modules = array_map(fn(Module $m): Module => isset($this->targets[$m->id]) ? $this->into($m, $this->targets[$m->id], $transfer) : $m, $package->modules);
        return new Package(...array_merge(get_object_vars($package), ['modules' => $modules]));
    }

    /** The constant groups of $full that only what the slices keep uses are left out of $pruned (I).
     *  A group nothing uses stays - an app may bring one on purpose. */
    public function groups(Package $full, Package $pruned, GroupPlan $plan): Package
    {
        if (!$this->targets) {
            return $pruned;
        }
        [$before, $after] = [$plan->usedNames($full), $plan->usedNames($pruned)];
        $groups           = [];
        foreach ($pruned->constantGroups as $group) {
            if (isset($this->groupMap[$group->name])) {
                $this->add("constant-group[$group->name]", LintFinding::INFO, self::GROUP_KEPT, "$group->name => {$this->groupMap[$group->name]}");
            } elseif (isset($before[$group->name]) && !isset($after[$group->name])) {
                $this->add("constant-group[$group->name]", LintFinding::INFO, self::GROUP_LEFT_OUT, $group->name);
            } else {
                $groups[] = $group;
            }
        }
        $carried = array_map(static fn($g): string => $g->name, $pruned->constantGroups);
        foreach ($this->groupMap as $group => $its) {
            if (!in_array($group, $carried, true) && isset($after[$group])) {
                $this->add("constant-group[$group]", LintFinding::INFO, self::GROUP_REDIRECTED, "$group => $its");
            }
        }
        return new Package(...array_merge(get_object_vars($pruned), ['constantGroups' => $groups]));
    }

    /** W or E for the fields and views the slices keep that differ from the package's - compared as the
     *  package's would be written: references mapped, in UTF-8 */
    public function differences(IdMap $map, bool $transfer): void
    {
        $rewrite = new ImportRewrite($map);
        foreach ($this->kept as $old => $rows) {
            $slice = $this->targets[$old];
            foreach ($rows as [$i, $row]) {
                $have   = $slice->fields[ValueCodec::bytes($row['id'] ?? '')];
                $group  = ReferenceRewriter::groupColumn('field', $row);
                $differ = [];
                foreach (array_unique([...array_keys($row), ...self::STORAGE]) as $column) {
                    if (in_array($column, ['id', 'slice_id'], true)) {
                        continue;
                    }
                    $mine = self::column($row, $column, $this->schema->columnDefault('field', $column));
                    $mine = ($mine === '') ? '' : $rewrite->known($mine, ($column === $group) ? $group : null);
                    if (!self::same($mine, self::column($have, $column, $this->schema->columnDefault('field', $column)), $slice->charset)) {
                        $differ[] = $column;
                    }
                }
                $relation = $transfer ? self::relation($rewrite->known(self::column($row, 'input_show_func', null)), self::column($have, 'input_show_func', null)) : null;
                if ($relation !== null) {
                    $this->add($this->schema->rowPath(Module::SLICE . "[$old]", 'field', $row, $i), LintFinding::WARNING, self::RELATION_TARGET, "the slice selects from $relation[1], the items will point to $relation[0]");
                    $differ = array_values(array_diff($differ, ['input_show_func']));
                }
                if ($differ) {
                    $breaks = $transfer && ($this->storage($row) !== $this->storage($have));
                    $this->add($this->schema->rowPath(Module::SLICE . "[$old]", 'field', $row, $i), $breaks ? LintFinding::ERROR : LintFinding::WARNING, $breaks ? self::FIELD_TYPE : self::FIELD_DIFFERS, implode(', ', $differ));
                }
            }
        }
        foreach ($this->keptViews as $old => $views) {
            $slice = $this->targets[$old];
            foreach ($views as [$i, $row, $id]) {
                $differ = [];
                foreach (self::VIEW_COLUMNS as $column) {
                    $mine = self::column($row, $column, null);
                    $mine = ($mine === '') ? '' : $rewrite->numeric($rewrite->known($mine));
                    if (!self::same($mine, self::column($slice->views[$id] ?? [], $column, null), $slice->charset)) {
                        $differ[] = $column;
                    }
                }
                if ($differ) {
                    $this->add($this->schema->rowPath(Module::SLICE . "[$old]", 'view', $row, $i), LintFinding::WARNING, self::VIEW_DIFFERS, implode(', ', $differ));
                }
            }
        }
    }

    /** "name (short id)" of a slice to choose - several slices may share a name */
    public static function choiceLabel(string $name, string $id): string
    {
        return "$name (" . substr($id, 0, 8) . ')';
    }

    /** The name an object is paired by: its aa_name; an alias function without one by its alias - two of
     *  one alias would clash in {_:alias} anyway */
    public static function objectName(string $class, string $name, string $alias): string
    {
        return (($name === '') && ($class === 'AA_Aliasfunc')) ? $alias : $name;
    }

    /** the slice $old goes into, or null with an E */
    /** @param list<string> $chosen slices chosen for other slices of the package
     *  @param list<string> $own    slices no slice of the package may go into: those a transfer in the same AA copies */
    private function choose(?Module $m, string $old, string $id, array $chosen, array $own): ?ExistingSlice
    {
        $path = ($m === null) ? Module::SLICE . "[$old]" : "$m->type[$m->id]";
        if (($m === null) || ($m->type !== Module::SLICE)) {
            return $this->refuse($path, "$id: not a slice of the package");
        }
        if (in_array($id, $chosen, true)) {
            return $this->refuse($path, "$id: chosen twice");
        }
        $slice = $this->target->existingSlice($id);
        if ($slice === null) {
            return $this->refuse($path, "$id: no such slice");
        }
        if (!$this->target->mayEditSlice($id)) {
            return $this->refuse($path, "$id: you may not edit it");
        }
        if ($slice->charset === '') {
            return $this->refuse($path, "$id: its language names no charset");
        }
        if (in_array($id, $own, true)) {
            $this->add($path, LintFinding::ERROR, self::INTO_SOURCE, $id);
            return null;
        }
        $type = ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '');
        if (($type === ItemLint::READER_SLICE) !== ($slice->type === ItemLint::READER_SLICE)) {
            $this->add($path, LintFinding::ERROR, self::SLICE_TYPE, "$type => $slice->type");
            return null;
        }
        $this->add($path, LintFinding::INFO, self::INTO_EXISTING, $id);
        return $slice;
    }

    /** E TARGET_SLICE - no slice to go into */
    private function refuse(string $path, string $why): null
    {
        $this->add($path, LintFinding::ERROR, self::TARGET_SLICE, $why);
        return null;
    }

    /** the module with only what the slice lacks */
    private function into(Module $m, ExistingSlice $slice, bool $transfer): Module
    {
        $path = "$m->type[$m->id]";
        $rows = array_diff_key($m->rows, array_flip(['module', 'slice', 'profile']));
        [$rows['field'], $lacks] = [[], []];
        foreach ($m->rows['field'] ?? [] as $i => $row) {
            $id   = ValueCodec::bytes($row['id'] ?? '');
            $have = $slice->fields[$id] ?? null;
            if ($have === null) {
                [$rows['field'][], $lacks[$id]] = [$row, true];
                continue;
            }
            $this->kept[$m->id][] = [$i, $row];
            $group = ReferenceRewriter::rowGroup('field', $row);
            $its   = ReferenceRewriter::rowGroup('field', $have);
            // only a group the target has: a free name could also be given to another group of the package
            if (($group !== null) && ($its !== null) && !isset($this->groupMap[$group]) && IdMap::isAppGroup($group) && IdMap::renamable($group, $its) && $this->target->groupTaken($its)) {
                $this->groupMap[$group] = $its;
            }
        }
        if ($transfer) {
            $this->uniques($path, [...array_values($slice->fields), ...$rows['field']]);
        }
        [$rows['view'], $views]   = $this->byName($path, 'view', $m->rows['view'] ?? [], $slice->views, self::VIEW_EXISTS, self::VIEW_UNMATCHED);
        [$rows['email'], $emails] = $this->byName($path, 'email', $m->rows['email'] ?? [], $slice->emails, self::EMAIL_EXISTS, self::EMAIL_UNMATCHED);
        foreach ($m->rows['view'] ?? [] as $i => $row) {
            if (isset($views[$number = (int) ValueCodec::bytes($row['id'] ?? '')])) {
                $this->keptViews[$m->id][] = [$i, $row, $views[$number]];
            }
        }
        $this->views  += $views;
        $this->emails += $emails;
        // an install: the values for the settings fields the slice gets and has no value of; the state a transfer
        // brings stays the slice's own
        $values = [];
        if (!$transfer) {
            $values = array_diff_key(array_intersect_key($m->transfer['setting-values'] ?? [], $lacks), array_flip($slice->settings));
        } elseif ($m->transfer) {
            $this->add($path, LintFinding::INFO, self::STATE_KEPT, implode(', ', array_keys($m->transfer)));
        }
        return new Module($m->type, $m->id, array_filter($rows), null, $this->objectsOf($path, $m->objects, $slice), $m->spotTree, $m->items, $m->doc, $values ? ['setting-values' => $values] : []);
    }

    /** W UNIQUE_FIELD for the fields of the slice as it will be whose values must be unique in it
     *  @param list<array<string, mixed>> $fields */
    private function uniques(string $path, array $fields): void
    {
        $unique = [];
        foreach ($fields as $row) {
            $validate = strtolower(explode(':', self::text($row['input_validate'] ?? ''), 2)[0]);
            $insert   = strtolower(explode(':', self::text($row['input_insert_func'] ?? ''), 2)[0]);
            if (in_array($validate, self::UNIQUE_VALIDATE, true) || in_array($insert, self::UNIQUE_INSERT, true)) {
                $unique[] = self::text($row['id'] ?? '');
            }
        }
        if ($unique) {
            $this->add($path, LintFinding::WARNING, self::UNIQUE_FIELD, implode(', ', $unique));
        }
    }

    /** The rows the slice has no row of the name of; the others' numbers.
     *  @param list<array<string, mixed>> $rows @param array<int, array<string, mixed>> $existing id => row
     *  @return array{list<array<string, mixed>>, array<int, int>} */
    private function byName(string $path, string $element, array $rows, array $existing, string $found, string $unmatched): array
    {
        $plain = array_map(static fn(array $row): array => array_map(static fn(mixed $v): ?string => ($v === null) ? null : ValueCodec::bytes($v), $row), $rows);
        $mine  = Difference::idsByName($element, $plain);
        $name  = [];
        foreach ($mine as $key => $indexes) {
            foreach ($indexes as $i) {
                $name[$i] = (string) $key;
            }
        }
        $theirs = Difference::idsByName($element, $existing);
        [$add, $map] = [[], []];
        foreach ($rows as $i => $row) {
            $key = $name[$i];
            $at  = $this->schema->rowPath($path, $element, $row, $i);
            if (($key === '') || (count($mine[$key]) > 1) || (count($theirs[$key] ?? []) > 1)) {
                $this->add($at, LintFinding::WARNING, $unmatched, $key);
                $add[] = $row;
            } elseif (isset($theirs[$key])) {
                $map[(int) ValueCodec::bytes($row['id'] ?? '')] = (int) $theirs[$key][0];
                $this->add($at, LintFinding::INFO, $found, (string) $theirs[$key][0]);
            } else {
                $add[] = $row;
            }
        }
        return [$add, $map];
    }

    /** the objects the slice has none of the class and name of @param list<array<string, mixed>> $objects @return list<array<string, mixed>> */
    private function objectsOf(string $path, array $objects, ExistingSlice $slice): array
    {
        $key    = static fn(string $class, string $name): string => ($name === '') ? '' : "$class:$name";
        $theirs = [];
        foreach ($slice->objects as $id => [$class, $name]) {
            $theirs[$key($class, $name)][] = (string) $id;
        }
        $keys = array_map(static fn(array $o): string => $key((string) $o['class'], self::objectName((string) $o['class'], self::text($o['name'] ?? ''), self::text($o['props']['alias'] ?? ''))), $objects);
        $mine = array_count_values($keys);
        $add  = [];
        foreach ($objects as $k => $object) {
            $at = "$path/object[" . ValueCodec::bytes($object['id'] ?? '') . ']';
            if (($keys[$k] === '') || ($mine[$keys[$k]] > 1) || (count($theirs[$keys[$k]] ?? []) > 1)) {
                $this->add($at, LintFinding::WARNING, self::OBJECT_UNMATCHED, ($keys[$k] === '') ? (string) $object['class'] : $keys[$k]);
                $add[] = $object;
            } elseif (isset($theirs[$keys[$k]])) {
                $this->objects[ValueCodec::bytes($object['id'] ?? '')] = $theirs[$keys[$k]][0];
                $this->add($at, LintFinding::INFO, self::OBJECT_EXISTS, $theirs[$keys[$k]][0]);
            } else {
                $add[] = $object;
            }
        }
        return $add;
    }

    /** [the package's, the slice's] slices two relation widgets select from, when they differ; null = no two relations
     *  @return array{string, string}|null */
    private static function relation(string|RawBytes $mine, string|RawBytes $theirs): ?array
    {
        $of = static fn(string|RawBytes $value): ?string => ReferenceRewriter::relationSlice(ValueCodec::bytes($value));
        [$to, $from] = [$of($mine), $of($theirs)];
        return (($to !== null) && ($from !== null) && ($to !== $from)) ? [$to, $from] : null;
    }

    /** [text stored, a password] of a field row */
    private function storage(array $row): array
    {
        $value = fn(string $column): string => ValueCodec::bytes(self::column($row, $column, $this->schema->columnDefault('field', $column)));
        return [(int) $value('text_stored') !== 0, TargetSlice::isPassword($value('input_insert_func'))];
    }

    /** a column of a row - $default when the row has none, '' for NULL */
    private static function column(array $row, string $column, ?string $default): string|RawBytes
    {
        $value = array_key_exists($column, $row) ? $row[$column] : $default;
        return ($value instanceof RawBytes) ? $value : (string) $value;
    }

    /** the package's value equals the slice's; a value of the slice its charset cannot read (RawBytes)
     *  equals only the same bytes */
    private static function same(string|RawBytes $mine, string|RawBytes $theirs, string $charset): bool
    {
        if (($theirs instanceof RawBytes) && !($mine instanceof RawBytes)) {
            return ModuleCharset::fromUtf8($mine, $charset) === ValueCodec::bytes($theirs);
        }
        return ValueCodec::bytes($mine) === ValueCodec::bytes($theirs);
    }

    private static function text(mixed $value): string
    {
        return (is_string($value) || ($value instanceof RawBytes)) ? ValueCodec::bytes($value) : '';
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}
