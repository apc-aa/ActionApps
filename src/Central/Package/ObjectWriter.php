<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA_Property;
use LogicException;

/** Writes the objects of an import plan (design balicek-v1.md § 5.4, § 7.2 step 5.6) inside the transaction
 *  Importer opens, after RowWriter: per module its settings (id ImportPlan::$settingsIds), then its top-level
 *  objects with their subobjects. Every id is the plan's - never one of the package or the target as it is
 *  (AA_Object::deleteObjects() deletes by `aa_subobjects`); aa_owner = the new module. Values go through
 *  RowStore::value() as the rows (module ids and `{set:<id>}` become the new ones).
 *
 *  The rows AA_Object::save() writes, not save() itself (design § 15, C4.4(4c)): save() first DELETEs the
 *  rows of the ids its stored aa_subobjects lists, die()s on a refused statement and reports no failed row.
 *  ObjectWriterTest proves the rows equal to save()'s: a row per class property the object has (a missing
 *  one gets none - load() reads ''), aa_type, aa_owner, aa_name, aa_subobjects (computed from what is
 *  written, never taken from the package).
 *
 *  Only allowlisted classes and their own properties (never aa_*, never ObjectExport::isLeftOut()): the
 *  Validator's E, here a LogicException before the first row of the object tree.
 *  A "keep module ids" restore mode (§ 7.1) must check the derived settings ids free inside the transaction:
 *  object_text has no key that would refuse a duplicate.
 *  @internal only Importer calls it - the rights and the S confirmation are checked there (Importer::write()). */
final class ObjectWriter
{
    private IdMap $map;
    private ImportRewrite $rewrite;
    private string $charset;
    private string $owner;

    public function __construct(private readonly RowStore $store) {}

    /** @param IdMap $map the map RowWriter returned - with the view and e-mail numbers
     *  @throws WriteError|RewriteFailed|LogicException */
    public function write(ImportPlan $plan, IdMap $map): void
    {
        [$this->map, $this->rewrite] = [$map, new ImportRewrite($map)];
        foreach ($plan->package->modules as $m) {
            if (($m->settings === null) && !$m->objects) {
                continue;
            }
            $settings_class = Schema::SETTINGS_CLASSES[$m->type] ?? throw new LogicException("A $m->type carries no objects (the Validator's E)");
            $this->owner    = $map->module($m->id) ?? throw new LogicException("Module $m->id is not mapped");
            $this->charset  = $plan->charsets[$m->id] ?? throw new LogicException("No charset for module $m->id");
            $path           = "$m->type[$m->id]";
            $convert        = fn(mixed $value, string $at): mixed => $this->store->value($value, $this->charset, $this->rewrite, $at);
            foreach ($m->objects as $object) {
                if (!in_array($object['class'] ?? null, Schema::TOP_CLASSES, true)) {
                    throw new LogicException("$path/object: the class " . ValueCodec::bytes($object['class'] ?? '') . " is not allowed (the Validator's E)");
                }
            }
            [$settings, $objects] = ObjectExport::map($m->type, $m->settings, $m->objects, $path, $convert);
            if ($settings !== null) {
                $id = $plan->settingsIds[$m->id] ?? throw new LogicException("No settings id for module $m->id");
                $this->insert($this->rows($settings_class, $id, $settings, null, "$path/settings", false));
            }
            foreach ($objects as $object) {
                $at = "$path/object[" . ValueCodec::bytes($object['id'] ?? '') . ']';
                $this->insert($this->rows($object['class'], $this->newId($object['id'] ?? '', $at), (array) $object['props'], $object['name'] ?? null, $at, true));
            }
        }
    }

    /** The rows of an object and its subobjects in AA_Object::save() order.
     *  @param class-string        $class an allowlisted class
     *  @param array<string, mixed> $props converted values; a subobject {id (package), class, props}
     *  @param bool                $top  a top-level object - only it may hold subobjects
     *  @return list<array{string, array<string, int|string>, string}> [table, row, path] */
    private function rows(string $class, string $id, array $props, mixed $name, string $path, bool $top): array
    {
        /** @var array<string, AA_Property> $properties */
        $properties = $class::getClassProperties();
        foreach (array_keys($props) as $prop) {
            if (!isset($properties[$prop]) || str_starts_with((string) $prop, 'aa_') || ObjectExport::isLeftOut($class, (string) $prop)) {
                throw new LogicException("$path/$prop: $class has no such property (the Validator's E)");
            }
        }
        $rows = $subobjects = [];
        foreach ($properties as $prop => $property) {
            if (!array_key_exists($prop, $props)) {
                continue;
            }
            $values = $props[$prop];
            $list   = (is_array($values) && !array_key_exists('class', $values)) ? $values : [0 => $values];     // the Reader keeps a lone i=0 scalar
            if (!$property->isMulti() && (array_keys($list) !== [0])) {
                throw new LogicException("$path/$prop: $class has a single value there (the Validator's E)");
            }
            foreach ($list as $i => $value) {
                if ($property->isObject()) {
                    $sub_class = $property->getType();
                    if (!$top || !in_array($sub_class, Schema::SUB_CLASSES, true) || !is_array($value) || (($value['class'] ?? null) !== $sub_class)) {
                        throw new LogicException("$path/{$prop}[$i]: no $sub_class subobject (the Validator's E)");
                    }
                    $at         = "$path/{$prop}[$i]";
                    $sub_id     = $this->newId($value['id'] ?? '', $at);
                    $sub_rows   = $this->rows($sub_class, $sub_id, (array) ($value['props'] ?? []), null, $at, false);
                    $rows       = [...$rows, ...$sub_rows];
                    $subobjects[] = $sub_id;            // a subobject holds none ($top)
                    $value      = $sub_id;
                } elseif (is_array($value)) {
                    throw new LogicException("$path/{$prop}[$i]: a subobject where $class has a value (the Validator's E)");
                }
                $rows[] = self::row(AA_Property::storageType($property->getType()), $id, (string) $prop, (int) $i, $value, "$path/{$prop}[$i]");
            }
        }
        $rows[] = self::row('object_text', $id, 'aa_type', 0, $class, "$path@aa_type");
        $rows[] = self::row('object_text', $id, 'aa_owner', 0, $this->owner, "$path@aa_owner");
        if (is_string($name) && ($name !== '') && ($name !== '0')) {       // save(): if ($this->aa_name)
            $rows[] = self::row('object_text', $id, 'aa_name', 0, $name, "$path@name");
        }
        foreach ($subobjects as $i => $sub_id) {
            $rows[] = self::row('object_text', $id, 'aa_subobjects', $i, $sub_id, "$path@aa_subobjects[$i]");
        }
        return $rows;
    }

    /** @return array{string, array<string, int|string>, string} */
    private static function row(string $table, string $id, string $property, int $priority, mixed $value, string $path): array
    {
        return [$table, ['object_id' => $id, 'property' => $property, 'priority' => $priority, 'value' => is_int($value) ? $value : ValueCodec::bytes($value)], $path];
    }

    /** @param list<array{string, array<string, int|string>, string}> $rows */
    private function insert(array $rows): void
    {
        foreach ($rows as [$table, $row, $at]) {
            $this->store->insert($table, $this->store->tableRow($table, [], $row, $this->charset, $this->rewrite, $at), $at);
        }
    }

    private function newId(mixed $old, string $path): string
    {
        $old = ValueCodec::bytes($old);
        return $this->map->longId($old) ?? throw new LogicException("$path: object $old is not mapped");
    }
}
