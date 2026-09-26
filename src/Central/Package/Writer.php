<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/** Package model => aa-app XML. Pure; toXml() works on a clone.
 *  Text values go through ValueCodec; SQL NULL of a column => `null="true"`, of a property or item value
 *  throws; a column with the empty value of its type is left out. Other texts have no enc/crlf, so a value
 *  XML 1.0 cannot carry throws. The output is deterministic and validated against the XSD. */
final class Writer
{
    public const FORMAT = '1.1';

    private const MODULE_ORDER = [Module::SITE, Module::SLICE, Module::ALERTS];

    /** row order other than by the key attributes */
    private const SORT = ['constant' => ['pri', 'id']];

    /** XSD types of key attributes compared as numbers */
    private const INT_TYPES = ['xs:positiveInteger', 'xs:integer', 'xs:nonNegativeInteger', 'intValue', 'floatValue'];

    private const REQUIRED_SOURCE = ['url', 'aa-version', 'exported'];

    private readonly Schema $schema;
    /** @var array<string, list<string>> */
    private readonly array $known;
    /** @var array<string, list<string>> */
    private readonly array $attributes;
    private DOMDocument $dom;
    /** @var list<array{string, LintFinding}> */
    private array $findings = [];

    public function __construct(?Schema $schema = null)
    {
        $this->schema     = $schema ?? new Schema();
        $this->known      = $this->schema->knownElements();
        $this->attributes = $this->schema->knownAttributes();
    }

    /** @throws InvalidArgumentException the model cannot be written or the XML does not match the XSD */
    public function toXml(Package $package): WriterResult
    {
        return (clone $this)->write($package);
    }

    private function write(Package $package): WriterResult
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');

        $this->dom->formatOutput = true;
        $this->dom->appendChild($this->dom->createComment(' ActionApps app package, format aa-app ' . self::FORMAT . ' '));
        $root = $this->el($this->dom, 'aa-app', ['format' => self::FORMAT]);
        $this->meta($root, $package);

        $modules = $package->modules;
        foreach ($modules as $module) {
            if (!in_array($module->type, self::MODULE_ORDER, true)) {
                throw new InvalidArgumentException("Unknown module type $module->type");
            }
        }
        $rank = array_flip(self::MODULE_ORDER);
        usort($modules, static fn(Module $a, Module $b): int => $rank[$a->type] <=> $rank[$b->type] ?: strcmp($a->id, $b->id));
        foreach ($modules as $module) {
            $this->module($root, $module, $package->withItems);
        }

        $groups = $package->constantGroups;
        usort($groups, static fn($a, $b): int => strcmp($a->name, $b->name));
        foreach ($groups as $group) {
            $path = "constant-group[$group->name]";
            $el   = $this->el($root, 'constant-group', ['name' => $group->name, 'owner' => $group->owner === '' ? null : $group->owner], path: $path);
            $this->container($el, 'constant-group', $group->rows, [], $path);
        }
        $this->el($root, 'signature');   // reserved, empty in format 1.x

        $xml = (string) $this->dom->saveXML();
        $this->validate($xml);
        return new WriterResult($xml, $this->findings);
    }

    private function meta(DOMElement $root, Package $p): void
    {
        $source = $p->source;
        if ($unknown = array_diff(array_keys($source), $this->attributes['source'])) {
            throw new InvalidArgumentException('meta/source: unknown attribute ' . implode(', ', $unknown));
        }
        foreach (self::REQUIRED_SOURCE as $attr) {
            if ((string) ($source[$attr] ?? '') === '') {
                throw new InvalidArgumentException("meta/source: attribute $attr is required");
            }
        }
        $meta = $this->el($root, 'meta');
        foreach ($this->known['meta'] as $child) {
            $text = match ($child) {
                'name'        => $p->name,
                'description' => $p->description,
                'version'     => $p->version,
                'publish'     => $p->published ? 'all' : null,
                'doc'         => $p->doc,
                default       => null,
            };
            match ($child) {
                'source'   => $this->el($meta, 'source', array_map(static fn(string $a): ?string => $source[$a] ?? null, array_combine($this->attributes['source'], $this->attributes['source'])), path: 'meta/source'),
                'requires' => $p->requires === null ? null : $this->el($meta, 'requires', ['aa-version' => $p->requires], path: 'meta/requires'),
                'contents' => $this->el($meta, 'contents', ['items' => $p->withItems ? 'true' : 'false']),
                'batch'    => $p->batch === null ? null : $this->el($meta, 'batch', ['after' => $p->batch->after ?: null, 'next' => $p->batch->next ?: null, 'finished' => $p->batch->finished ? 'true' : 'false'], path: 'meta/batch'),
                'tables'   => $this->tables($this->el($meta, 'tables')),
                default    => $text === null ? null : $this->el($meta, $child, text: $text, path: "meta/$child"),
            };
        }
    }

    /** meta/tables: the columns this AA knows per row element */
    private function tables(DOMElement $tables): void
    {
        foreach (array_keys($this->schema->rowTables()) as $element) {
            if ($columns = $this->known[$element]) {
                $this->el($tables, 'table', ['name' => $element, 'columns' => implode(' ', $columns)]);
            }
        }
    }

    private function module(DOMElement $root, Module $m, bool $with_items): void
    {
        $path = "$m->type[$m->id]";
        if ($m->items && !$with_items) {
            throw new InvalidArgumentException("$path: items in a package without items (Package::\$withItems)");
        }
        $el = $this->el($root, $m->type, ['id' => $m->id], path: $path);
        $this->container($el, $m->type, $m->rows, array_filter([
            'doc'       => $m->doc,
            'spot-tree' => $m->spotTree,
            'settings'  => $m->settings,
            'object'    => $m->objects ?: null,
            'item'      => $m->items ?: null,
        ] + $m->transfer, static fn($part): bool => $part !== null), $path);
    }

    /** Children of a module or constant group in XSD order: the rows and the other parts.
     *  @param array<string, list<array<string, mixed>>> $rows @param array<string, mixed> $parts */
    private function container(DOMElement $el, string $container, array $rows, array $parts, string $path): void
    {
        $children  = $this->known[$container];
        $row_names = array_intersect($children, array_keys($this->schema->rowTables()));
        if ($wrong = array_merge(array_diff(array_keys($rows), $row_names), array_diff(array_keys($parts), $children))) {
            throw new InvalidArgumentException("$path: $container cannot hold " . implode(', ', $wrong));
        }
        foreach ($children as $child) {
            if (in_array($child, $row_names, true)) {
                $list = $this->sorted($child, $rows[$child] ?? []);
                foreach ($list as $i => $row) {
                    // a keyless row that repeats (profile) is known by its position
                    $this->row($el, $child, $row, $path, count($list) > 1 ? "#$i" : '');
                }
                continue;
            }
            $part = $parts[$child] ?? null;
            match ($part === null ? null : $child) {
                'doc'       => $this->el($el, 'doc', text: $part, path: "$path/doc"),
                'spot-tree' => $this->spotTree($el, $part, "$path/spot-tree"),
                'settings'  => $this->props($this->el($el, 'settings', ['class' => Schema::SETTINGS_CLASSES[$container]]), $part, "$path/settings"),
                'object'    => $this->objects($el, $part, $path),
                'item'      => $this->items($el, $part, $path),
                'owner'     => $this->owner($this->el($el, 'owner'), $part, "$path/owner"),
                'reading-password' => $this->value($this->el($el, 'reading-password'), $part, "$path/reading-password"),
                'setting-values'   => $this->content($this->el($el, 'setting-values'), $part, "$path/setting-values"),
                'alternate-url'    => array_map(fn(string|RawBytes $url) => $this->value($this->el($el, 'alternate-url'), $url, "$path/alternate-url"), $part),
                default     => null,
            };
        }
    }

    /** @param array<string, mixed> $row DB column => value @param string $position of a keyless row */
    private function row(DOMElement $parent, string $element, array $row, string $path, string $position): void
    {
        $columns = $this->schema->rowColumns($element);
        $keys    = $this->schema->rowAttributes($element);
        if ($extra = array_diff_key($row, $columns, $keys)) {
            throw new InvalidArgumentException("$path: column $element." . implode(", $element.", array_keys($extra)) . ' is not carried by a package (Schema::rowColumns())');
        }
        foreach ($row as $column => $value) {
            $raw_ok = ($value instanceof RawBytes) && (($columns[$column] ?? '') === 'textValue');
            if ($value !== null && !is_scalar($value) && !$raw_ok) {
                throw new InvalidArgumentException("$path/$element: column $column must be a scalar or null (RawBytes in a text column), " . get_debug_type($value) . ' given');
            }
        }
        $attrs = [];
        foreach ($keys as $column => $key) {
            $value          = isset($row[$column]) ? (string) $row[$column] : null;
            $attrs[$key[0]] = $value === ($key[2] ?? null) ? null : $value;
        }
        $path .= "/$element" . ($keys ? '[' . implode(',', array_filter($attrs, 'is_string')) . ']' : ($position === '' ? '' : "[$position]"));
        $el    = $this->el($parent, $element, $attrs, path: $path);
        foreach (array_intersect_key($columns, $row) as $column => $type) {
            $value = $row[$column];
            if ($value === null) {
                $this->el($el, $column, ['null' => 'true']);
            } elseif ($value instanceof RawBytes) {
                $this->value($this->el($el, $column), $value, "$path/$column");
            } elseif (!ValueCodec::isEmpty($type, $value = (string) $value)) {
                if ($type === 'textValue') {
                    $this->value($this->el($el, $column), $value, "$path/$column");
                } else {
                    $this->el($el, $column, text: $value, path: "$path/$column");
                }
            }
        }
    }

    /** Scalars as <p n i>, subobjects as <object n i>, by name and index; index 0 is the default.
     *  @param array<string, mixed> $props name => scalar | [index => scalar | subobject] */
    private function props(DOMElement $el, array $props, string $path): void
    {
        ksort($props, SORT_STRING);
        foreach ($props as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            ksort($values, SORT_NUMERIC);
            foreach ($values as $i => $value) {
                $attrs = ['n' => (string) $name, 'i' => $i === 0 ? null : (string) $i];
                if (is_array($value)) {
                    $this->object($el, $value, $attrs, "$path/{$name}[$i]");
                } elseif (is_scalar($value) || ($value instanceof RawBytes)) {
                    $this->value($this->el($el, 'p', $attrs, path: "$path/{$name}[$i]"), is_scalar($value) ? (string) $value : $value, "$path/{$name}[$i]");
                } else {
                    throw new InvalidArgumentException("$path/{$name}[$i]: a property value must be a scalar or a subobject");
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $objects */
    private function objects(DOMElement $el, array $objects, string $path): void
    {
        usort($objects, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        foreach ($objects as $object) {
            $name = (string) ($object['name'] ?? '');
            $this->object($el, $object, ['name' => $name === '' ? null : $name], "$path/object[" . ($object['id'] ?? '') . ']');
        }
    }

    /** @param array<string, mixed> $object {id, class, name?, props} @param array<string, ?string> $attrs */
    private function object(DOMElement $parent, array $object, array $attrs, string $path): void
    {
        $el = $this->el($parent, 'object', ['id' => (string) ($object['id'] ?? ''), 'class' => (string) ($object['class'] ?? '')] + $attrs, path: $path);
        $this->props($el, $object['props'] ?? [], $path);
    }

    /** sitetree::toArray() form; po/ch keep their order (the sequence), v/c the stored order. `false`
     *  (as stored by old trees and sitetree::addInSequence()) counts as null.
     *  @param array<string, mixed> $tree */
    private function spotTree(DOMElement $parent, array $tree, string $path): void
    {
        $el    = $this->el($parent, 'spot-tree', ['start' => (string) ($tree['start_id'] ?? '')], path: $path);
        $spots = $tree['spots'] ?? [];
        usort($spots, static fn(array $a, array $b): int => self::compare($a['id'] ?? null, $b['id'] ?? null, true));
        foreach ($spots as $spot) {
            $at = "$path/spot[" . ($spot['id'] ?? '') . ']';
            foreach (['v', 'c', 'po', 'ch'] as $key) {
                $spot[$key] = ($spot[$key] ?? false) === false ? null : $spot[$key];
                if ($spot[$key] !== null && (!is_array($spot[$key]) || array_filter($spot[$key], static fn($v): bool => !is_scalar($v)))) {
                    throw new InvalidArgumentException("$at: $key must be an array of scalars, null or false");
                }
            }
            $s = $this->el($el, 'spot', [
                'id'     => (string) ($spot['id'] ?? ''),
                'name'   => (string) ($spot['n'] ?? ''),
                'parent' => (string) ($spot['p'] ?? ''),
                'flag'   => empty($spot['f']) ? null : (string) $spot['f'],
                'empty'  => implode(' ', array_filter(['v', 'c', 'po', 'ch'], static fn(string $k): bool => $spot[$k] === [])) ?: null,
            ], path: $at);
            foreach ($spot['v'] ?? [] as $key => $var) {
                if ((string) $key !== (string) $var) {
                    throw new InvalidArgumentException("$at: variable key '$key' differs from its name '$var' (v[name] = name)");
                }
                $this->el($s, 'var', text: (string) $var, path: "$at/var");
            }
            foreach ($spot['c'] ?? [] as $var => $cond) {
                $this->el($s, 'cond', ['var' => (string) $var], (string) $cond, "$at/cond");
            }
            foreach (['po' => 'positions', 'ch' => 'choices'] as $key => $name) {
                if ($spot[$key]) {
                    $this->el($s, $name, text: implode(' ', $spot[$key]), path: "$at/$name");
                }
            }
        }
    }

    /** @param array<string, string|RawBytes> $owner {name, email} */
    private function owner(DOMElement $el, array $owner, string $path): void
    {
        foreach (['name', 'email'] as $child) {
            $this->value($this->el($el, $child), $owner[$child] ?? throw new InvalidArgumentException("$path: no $child"), "$path/$child");
        }
    }

    /** GetItemContent() form: field id => [key => {value, flag}], the key a position or a translation
     *  number (>= AA_Value::MAX_INDEX); fields by id, values by key, key 0 is the default
     *  @param list<array<string, mixed>> $items */
    private function items(DOMElement $parent, array $items, string $path): void
    {
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        foreach ($items as $item) {
            $at      = "$path/item[" . ($item['id'] ?? '') . ']';
            $this->content($this->el($parent, 'item', ['id' => (string) ($item['id'] ?? '')], path: $at), $item['content'] ?? [], $at);
        }
    }

    /** <v> of each value of GetItemContent() form: fields by id, values by key
     *  @param array<string, array<int, array<string, mixed>>> $content */
    private function content(DOMElement $el, array $content, string $at): void
    {
        ksort($content, SORT_STRING);
        foreach ($content as $field => $values) {
            ksort($values, SORT_NUMERIC);
            foreach ($values as $i => $v) {
                $value = $v['value'] ?? throw new InvalidArgumentException("$at/{$field}[$i]: a content value cannot be NULL");
                $flag  = (int) ($v['flag'] ?? 0);
                $attrs = ['f' => (string) $field, 'i' => $i === 0 ? null : (string) $i, 'flag' => $flag ? (string) $flag : null];
                $this->value($this->el($el, 'v', $attrs, path: $at), ($value instanceof RawBytes) ? $value : (string) $value, "$at/{$field}[$i]");
            }
        }
    }

    /** a text value encoded by ValueCodec into its (empty) element; RawBytes always as base64 */
    private function value(DOMElement $el, string|RawBytes $value, string $path): void
    {
        $enc = ($value instanceof RawBytes) ? ValueCodec::encode($value->bytes, raw: true) : ValueCodec::encode($value);
        if ($enc['finding']) {
            $this->findings[] = [$path, $enc['finding']];
        }
        if ($enc['enc'] !== ValueCodec::TEXT) {
            $el->setAttribute('enc', $enc['enc']);
        }
        if ($enc['crlf']) {
            $el->setAttribute('crlf', 'true');
        }
        if ($enc['text'] !== '') {
            $el->appendChild($enc['cdata'] ? $this->dom->createCDATASection($enc['text']) : $this->dom->createTextNode($enc['text']));
        }
    }

    /** @param array<string, string|null> $attrs null = left out */
    private function el(DOMNode $parent, string $name, array $attrs = [], ?string $text = null, string $path = ''): DOMElement
    {
        $el = $this->dom->createElementNS(Schema::NS, $name);
        $parent->appendChild($el);
        foreach ($attrs as $attr => $value) {
            if ($value !== null) {
                $el->setAttribute($attr, $this->plain($value, "$path@$attr"));
            }
        }
        if ($text !== null && $text !== '') {
            $el->appendChild($this->dom->createTextNode($this->plain($text, $path)));
        }
        return $el;
    }

    /** a text of an element without enc/crlf, or an attribute: XML 1.0 must carry it as it is */
    private function plain(string $text, string $path): string
    {
        return ValueCodec::isPlain($text) ? $text : throw new InvalidArgumentException("$path: XML 1.0 cannot carry the value (invalid UTF-8 or a control character)");
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function sorted(string $element, array $rows): array
    {
        $types = array_merge(array_map(static fn(array $key): string => $key[1], $this->schema->rowAttributes($element)), $this->schema->rowColumns($element));
        $by    = [];
        foreach (array_merge(self::SORT[$element] ?? array_keys($this->schema->rowAttributes($element)), array_keys($this->schema->rowColumns($element))) as $column) {
            $by[$column] = in_array($types[$column], self::INT_TYPES, true);
        }
        usort($rows, static function (array $a, array $b) use ($by): int {
            foreach ($by as $column => $numeric) {
                if ($c = self::compare($a[$column] ?? null, $b[$column] ?? null, $numeric)) {
                    return $c;
                }
            }
            return 0;
        });
        return $rows;
    }

    /** A total order: NULL first; in a number column numbers by value before other strings; the rest bytewise */
    private static function compare(mixed $x, mixed $y, bool $numeric): int
    {
        $x  = ($x instanceof RawBytes) ? $x->bytes : $x;
        $y  = ($y instanceof RawBytes) ? $y->bytes : $y;
        $nx = $numeric && is_numeric($x);
        $ny = $numeric && is_numeric($y);
        return [$x !== null, !$nx, $nx ? +$x : 0] <=> [$y !== null, !$ny, $ny ? +$y : 0] ?: strcmp((string) $x, (string) $y);
    }

    /** the same check the reader makes */
    private function validate(string $xml): void
    {
        try {
            (new XmlGuard())->validateXml($xml, $this->schema->xsd());
        } catch (PackageRejected $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
