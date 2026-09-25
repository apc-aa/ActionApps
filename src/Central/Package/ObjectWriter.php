<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA_Property;
use LogicException;

/** Writes the objects of an import plan (design balicek-v1.md § 5.4, § 7.2 step 5.6; C4.4 part 4c) -
 *  inside the transaction Importer opens, after RowWriter (the map has the view and e-mail numbers).
 *
 *  Per module in package order: its settings (<settings>, the class of the module type, id = the derived
 *  ImportPlan::$settingsIds - string2id(class . new module id)), then its top-level objects (AA_Aliasfunc,
 *  AA_Set) with their subobjects (AA_Condition in AA_Set.conds, AA_Sortorder in .sort). Every id is the
 *  plan's: the settings id, or IdMap::longId() of the package id - never an id of the package or of the
 *  target as it is (Compensation deletes by these ids, and AA_Object::deleteObjects() by `aa_subobjects`).
 *  Owner (aa_owner) = the new module, subobjects too. Every value goes through RowStore::value() -
 *  numeric(known()), the module charset last, as the rows and as the plan measured it (ObjectExport::map()
 *  gives the same paths) - so module ids in translate_slice, fieldslice, AA_Set.slices and `{set:<id>}` in
 *  code are the new ones; the property holding a subobject gets the subobject's NEW id.
 *
 *  THE ROWS AA_Object::save() WRITES, NOT save() ITSELF (a deviation from § 5.4 "through the AA_Object
 *  API", confirmed in the C4.4(4c) review): save() first deletes every row of its id and of the ids its stored aa_subobjects lists
 *  (delete() - DELETE on the target), writes through Cvarset without `nohalt` (a refused statement die()s
 *  the request instead of throwing) and AA_Property::_saveRow() reports nothing - a half-written object
 *  would go unnoticed. So the rows are built here in save()'s format and inserted through RowStore
 *  (`nohalt`, WriteError, the transaction's handle), ObjectWriterTest proves them equal to what
 *  save() writes (AA saves, ObjectExport exports, this writes back):
 *  - each class property the object has, in getClassProperties() order, in AA_Property::storageType($type)
 *    (object_text, object_integer for bool/int, object_float); a multi-value property one row per value,
 *    priority = its index; a subobject first with all its rows, then the parent's row with its id;
 *  - aa_type (the class), aa_owner, aa_name (a @name PHP takes for true - save() skips '' and '0'), aa_subobjects (the ids of the subobjects
 *    written here, in save()'s order - computed, never taken from the package).
 *  A property the package lacks gets no row - load() reads it as '' (as an object stored before the
 *  property existed); save() would write '' for a missing single-valued one.
 *
 *  Only allowlisted classes (Schema::TOP_CLASSES, SUB_CLASSES in their property, SETTINGS_CLASSES by the
 *  module type) and only properties of the class (never aa_*, never the installation's settings of
 *  ObjectExport::isLeftOut() - ga_id, alternate_url, app_*, which FixUps drop): the Validator's E, here a
 *  LogicException before the first row of the object - the whole object tree is built before it is
 *  written. Each object goes to the journal once one of its rows is written.
 *
 *  Follow-up for part 6: once a "keep module ids" restore mode exists (§ 7.1), the derived settings ids
 *  are no longer those of fresh modules - check them free inside the transaction (object_text has no key
 *  that would refuse a second object of the id, and the compensation deletes by it).
 *
 *  @internal only Importer calls it - the rights and the S confirmation are checked there (Importer::write()). */
final class ObjectWriter
{
    private IdMap $map;
    private ImportRewrite $rewrite;
    private ImportJournal $journal;
    private string $charset;
    private string $owner;

    public function __construct(private readonly RowStore $store) {}

    /** @param IdMap $map the map RowWriter returned - with the view and e-mail numbers
     *  @throws WriteError|RewriteFailed|LogicException */
    public function write(ImportPlan $plan, IdMap $map, ImportJournal $journal): void
    {
        [$this->map, $this->journal, $this->rewrite] = [$map, $journal, new ImportRewrite($map)];
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
     *  @return list<array{string, string, array<string, int|string>, string}> [table, object id, row, path] */
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

    /** @return array{string, string, array<string, int|string>, string} */
    private static function row(string $table, string $id, string $property, int $priority, mixed $value, string $path): array
    {
        return [$table, $id, ['object_id' => $id, 'property' => $property, 'priority' => $priority, 'value' => is_int($value) ? $value : ValueCodec::bytes($value)], $path];
    }

    /** @param list<array{string, string, array<string, int|string>, string}> $rows */
    private function insert(array $rows): void
    {
        foreach ($rows as [$table, $id, $row, $at]) {
            $this->journal->step($at);
            $this->store->insert($table, $this->store->tableRow($table, [], $row, $this->charset, $this->rewrite, $at), $at);
            $this->journal->object($id, $this->owner);
        }
    }

    private function newId(mixed $old, string $path): string
    {
        $old = ValueCodec::bytes($old);
        return $this->map->longId($old) ?? throw new LogicException("$path: object $old is not mapped");
    }
}
