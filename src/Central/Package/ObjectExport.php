<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA_Property;

/** The objects a module owns, as a package carries them (design balicek-v1.md § 5.4; C4.3 part 4):
 *  the module settings (<settings>, the class fixed by the module type, read by the derived id as AA
 *  reads them - string2id(class . module id)), AA_Aliasfunc and AA_Set with its AA_Condition /
 *  AA_Sortorder as nested subobjects. Nothing else ever travels - AA_Form (v1), AA_Plannedtask, tokens
 *  and access of AA\Util\Note, AA_Actionapps, Datovka, AA_Exportsetings, ... Of the owned objects only
 *  the class is read (aa_type, aa_subobjects); the rows of the others are never loaded, and what is left
 *  out is one I finding per class with the count.
 *
 *  Read as AA_Object::load() reads: only the properties of the class (getClassProperties()), a
 *  multi-value property as a list in priority order, a single one as its first value, the object
 *  tables of the class merged text -> integer -> float; an object-typed property holds subobject ids,
 *  read by id (whoever owns them) and carried only when stored as the property's type. Values stay as
 *  stored (the module charset) - map() converts them; object_text.value NULL is ''.
 *
 *  A NEW PROPERTY of a settings class may hold a secret or a state of the installation (as ga_id,
 *  alternate_url): CollectorObjectsTest pins the property names, so it fails until someone decides
 *  whether the property travels (LEFT_OUT). */
final class ObjectExport
{
    /** I: owned objects v1 does not carry (class outside the allowlist, a settings object AA does not
     *  read) - left out; one finding per class, ref = "class (count)" */
    public const OBJECT_DROPPED = 'object-dropped';
    /** W: a subobject a set refers to is gone, of another class, or no object id (serialized state) -
     *  left out, the set changes (AA_Object::load() puts an empty object there); ref = the id, if one */
    public const SUBOBJECT = 'subobject';

    /** the object store in the order AA_Object::load() merges it */
    public const TABLES = ['object_text', 'object_integer', 'object_float'];

    /** settings properties of the source installation (§ 5.4): measuring code, domains; `app_*` is R5 (meta) */
    private const LEFT_OUT = ['AA_Modulesettings_Site' => ['ga_id', 'alternate_url']];
    private const LEFT_OUT_PREFIX = 'app_';

    /** properties holding module ids - long-id map on import, the closure on export (§ 5.2, § 6.3) */
    public const MODULE_PROPS = [
        'AA_Modulesettings_Site'  => ['translate_slice', 'add_aliases'],
        'AA_Modulesettings_Slice' => ['fieldslice'],
        'AA_Set'                  => ['slices'],
    ];

    /** properties that are code (templates, AA expressions) - the lint reads these only; the others
     *  are names, enum values, field ids (a `2` of page404 is no view 2) */
    private const CODE_PROPS = [
        'AA_Aliasfunc'            => ['code'],
        'AA_Condition'            => ['value'],
        'AA_Modulesettings_Site'  => ['page404_code', 'loginpage_code'],
        'AA_Modulesettings_Slice' => ['nocheck_pending', 'nocheck_expired'],
    ];

    /** @var array<string, array<string, array<string, list<mixed>>>> object id => table => property => values */
    private array $store = [];
    /** @var array<string, true> subobjects carried */
    private array $carried = [];
    /** @var list<array{string, LintFinding}> */
    private array $findings = [];

    public function __construct(private readonly Source $source) {}

    /** The settings (props, null = none) and the top-level objects {id, class, name?, props} of a site
     *  or slice module, values as stored; the findings of what was left out.
     *  @param string $type Module::SITE | Module::SLICE
     *  @return array{?array<string, mixed>, list<array<string, mixed>>, list<array{string, LintFinding}>} */
    public function collect(string $module_id, string $type, string $path): array
    {
        $this->findings = $this->carried = [];
        $owned          = $this->source->objectIds($module_id);
        $index          = self::group($this->source->objectRows($owned, ['aa_type', 'aa_subobjects']));
        $class          = static fn(array $tables): string => (string) ($tables['object_text']['aa_type'][0] ?? '');
        $listed         = [];         // subobjects of any owned object - they go (or not) with their parent
        foreach ($index as $tables) {
            array_push($listed, ...array_map('strval', $tables['object_text']['aa_subobjects'] ?? []));
        }
        $settings_class = Schema::SETTINGS_CLASSES[$type];
        $settings_id    = string2id($settings_class . $module_id);
        $top            = [];            // AA_Aliasfunc, AA_Set - no subobject of another
        foreach ($index as $id => $tables) {
            if (in_array($class($tables), Schema::TOP_CLASSES, true) && !in_array((string) $id, $listed, true)) {
                $top[] = (string) $id;
            }
        }
        $this->store    = self::group($this->source->objectRows([$settings_id, ...$top]));
        $subobjects     = [];            // referenced id => the class the property holds
        foreach ($top as $id) {
            foreach (self::objectProperties($class($this->store[$id] ?? [])) as $name => $sub_class) {
                foreach (array_filter(array_map('strval', $this->store[$id]['object_text'][$name] ?? []), self::isId(...)) as $sub) {
                    $subobjects[$sub] ??= $sub_class;
                }
            }
        }
        // by id, whoever owns them (as load() reads them) - the rows only of those of the right class
        $types       = $subobjects ? self::group($this->source->objectRows(array_map('strval', array_keys($subobjects)), ['aa_type'])) : [];
        $right       = array_filter($subobjects, static fn(string $sub_class, string $sub): bool => $class($types[$sub] ?? []) === $sub_class, ARRAY_FILTER_USE_BOTH);
        $this->store += $right ? self::group($this->source->objectRows(array_map('strval', array_keys($right)))) : [];

        $settings = null;
        if ($class($this->store[$settings_id] ?? []) === $settings_class) {
            $settings = $this->props($settings_class, $settings_id, "$path/settings", false);
        }
        $objects = [];
        foreach ($top as $id) {
            $name      = $this->store[$id]['object_text']['aa_name'][0] ?? null;
            $objects[] = ['id' => $id, 'class' => $class($this->store[$id])] + ((($name ?? '') === '') ? [] : ['name' => $name])
                + ['props' => $this->props($class($this->store[$id]), $id, "$path/object[$id]", true)];
        }
        $dropped = [];
        foreach ($index as $id => $tables) {
            $id = (string) $id;
            if (($id !== $settings_id) && !in_array($id, $top, true) && !in_array($id, $listed, true) && !isset($this->carried[$id])) {
                $dropped[$class($tables)] = ($dropped[$class($tables)] ?? 0) + 1;
            }
        }
        ksort($dropped, SORT_STRING);
        foreach ($dropped as $name => $count) {
            $this->findings[] = [$path . '/object', new LintFinding(LintFinding::INFO, self::OBJECT_DROPPED, substr("$name ($count)", 0, ReferenceLint::MAX_REF), 0)];
        }
        return [$settings, $objects, $this->findings];
    }

    /** true: a settings property of the source installation, never carried (LEFT_OUT, `app_*`) */
    public static function isLeftOut(string $class, string $property): bool
    {
        return in_array($property, self::LEFT_OUT[$class] ?? [], true) || (isset(self::LEFT_OUT[$class]) && str_starts_with($property, self::LEFT_OUT_PREFIX));
    }

    /** true: the property of the class is code - the lint reads it */
    public static function isCode(string $class, string $property): bool
    {
        return in_array($property, self::CODE_PROPS[$class] ?? [], true);
    }

    /** The properties of the class the object has, as AA_Object::load() reads them.
     *  @return array<string, mixed> */
    private function props(string $class, string $id, string $path, bool $subobjects): array
    {
        /** @var array<string, AA_Property> $properties */
        $properties = $class::getClassProperties();
        $used       = ['object_text', ...array_map(static fn(AA_Property $p): string => AA_Property::storageType($p->getType()), $properties)];
        $values     = [];
        foreach (array_intersect(self::TABLES, $used) as $table) {
            $values = array_merge($values, $this->store[$id][$table] ?? []);
        }
        $props = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            if (!isset($values[$name]) || in_array($name, self::LEFT_OUT[$class] ?? [], true) || str_starts_with($name, self::LEFT_OUT_PREFIX)) {
                continue;
            }
            $list = [];
            foreach ($property->isMulti() ? $values[$name] : [$values[$name][0]] as $value) {
                $value = $value ?? '';
                if ($property->isObject()) {
                    $at  = "$path/{$name}[" . count($list) . ']';
                    $sub = (string) $value;
                    if (!$subobjects || !in_array($property->getType(), Schema::SUB_CLASSES, true) || !self::isId($sub)
                        || (($this->store[$sub]['object_text']['aa_type'][0] ?? null) !== $property->getType())) {
                        $this->findings[] = [$at, new LintFinding(LintFinding::WARNING, self::SUBOBJECT, self::isId($sub) ? $sub : '', 0)];
                        continue;
                    }
                    $this->carried[$sub] = true;
                    $value               = ['id' => $sub, 'class' => $property->getType(), 'props' => $this->props($property->getType(), $sub, $at, false)];
                }
                $list[] = $value;
            }
            if ($list) {
                $props[$name] = $property->isMulti() ? $list : $list[0];
            }
        }
        return $props;
    }

    /** the object-typed properties of an allowlisted top class => their class @return array<string, string> */
    private static function objectProperties(string $class): array
    {
        if (!in_array($class, Schema::TOP_CLASSES, true)) {
            return [];
        }
        $ret = [];
        foreach ($class::getClassProperties() as $name => $property) {
            if ($property->isObject()) {
                $ret[(string) $name] = $property->getType();
            }
        }
        return $ret;
    }

    /** rows by object, table, property; values in priority order (stable: equal priorities as read)
     *  @param list<array<string, mixed>> $rows @return array<string, array<string, array<string, list<mixed>>>> */
    private static function group(array $rows): array
    {
        usort($rows, static fn(array $a, array $b): int => (int) $a['priority'] <=> (int) $b['priority']);
        $store = [];
        foreach ($rows as $row) {
            $store[(string) $row['object_id']][(string) $row['table']][(string) $row['property']][] = $row['value'];
        }
        ksort($store, SORT_STRING);
        return $store;
    }

    private static function isId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}\z/', $id);
    }

    /** The settings and objects with fn(value, path, class, property) applied to every scalar property
     *  value - in the paths Writer reports (`<module>/settings/<p>[i]`, `<module>/object[<id>]/<p>[i]`,
     *  a subobject's `.../<p>[i]/<sub p>[j]`) - and to the name of an object (property `@name`, path
     *  `<module>/object[<id>]@name`; a result that is no string drops the name: @name is an attribute).
     *  @param array<string, mixed>|null $settings @param list<array<string, mixed>> $objects
     *  @param callable(mixed, string, string, string): mixed $fn
     *  @return array{?array<string, mixed>, list<array<string, mixed>>} */
    public static function map(string $type, ?array $settings, array $objects, string $path, callable $fn): array
    {
        if ($settings !== null) {
            $settings = self::mapProps(Schema::SETTINGS_CLASSES[$type], $settings, "$path/settings", $fn);
        }
        foreach ($objects as $k => $object) {
            $at                   = "$path/object[{$object['id']}]";
            $objects[$k]['props'] = self::mapProps($object['class'], $object['props'], $at, $fn);
            if (isset($object['name'])) {
                $name = $fn($object['name'], "$at@name", $object['class'], '@name');
                if (is_string($name)) {
                    $objects[$k]['name'] = $name;
                } else {
                    unset($objects[$k]['name']);
                }
            }
        }
        return [$settings, $objects];
    }

    /** Every scalar property value: [path, class, property, value] (map())
     *  @param array<string, mixed>|null $settings @param list<array<string, mixed>> $objects
     *  @return list<array{string, string, string, mixed}> */
    public static function values(string $type, ?array $settings, array $objects, string $path): array
    {
        $values = [];
        self::map($type, $settings, $objects, $path, static function (mixed $value, string $at, string $class, string $name) use (&$values): mixed {
            $values[] = [$at, $class, $name, $value];
            return $value;
        });
        return $values;
    }

    /** the ids of the objects and their subobjects @param list<array<string, mixed>> $objects @return list<string> */
    public static function ids(array $objects): array
    {
        $ids = [];
        foreach ($objects as $object) {
            $ids[] = (string) $object['id'];
            foreach ($object['props'] as $values) {
                foreach (is_array($values) ? $values : [] as $value) {
                    if (is_array($value)) {
                        $ids[] = (string) $value['id'];
                    }
                }
            }
        }
        return $ids;
    }

    /** @param array<string, mixed> $props @return array<string, mixed> */
    private static function mapProps(string $class, array $props, string $path, callable $fn): array
    {
        foreach ($props as $name => $values) {
            $multi = is_array($values);
            foreach ($multi ? $values : [$values] as $i => $value) {
                $at  = "$path/{$name}[$i]";
                if (is_array($value)) {
                    $value['props'] = self::mapProps($value['class'], $value['props'], $at, $fn);
                    $new            = $value;
                } else {
                    $new = $fn($value, $at, $class, (string) $name);
                }
                if ($multi) {
                    $props[$name][$i] = $new;
                } else {
                    $props[$name] = $new;
                }
            }
        }
        return $props;
    }
}
