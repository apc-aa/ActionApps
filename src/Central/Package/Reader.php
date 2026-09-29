<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Batch;
use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use DOMElement;
use InvalidArgumentException;

/** aa-app XML of an untrusted source => package model.
 *  XmlGuard parses safely, then: root and major version, pruning, XSD, model. The semantic checks and
 *  sitetree::fromArray() are the Validator's.
 *
 *  Pruning: a column unknown to this AA's metabase is always dropped; one this AA knows but a package
 *  never carries (reading_password) stays for the XSD to reject. Elements, attributes and object classes
 *  unknown to this AA's format (Writer::FORMAT) are dropped only in a higher minor version, else the XSD
 *  rejects them. Each drop = a finding.
 *
 *  The model: values strings, SQL NULL null, integers in the shortest decimal form; a column the row leaves
 *  out is ValueCodec::emptyValue() when meta/tables lists it, else absent = this AA's default;
 *  base64 that is valid UTF-8 a string, else RawBytes; properties name => scalar (index 0 only) or
 *  [index => scalar | subobject]; the spot tree as sitetree::toArray(). For packages of this AA's Writer
 *  Writer(Reader(x)) === x; any other reads exactly, but the importer must not serialise the model again
 *  ("absent = default" would become "empty").
 *
 *  Messages and findings quote package text (cut, XmlGuard::quote()) - escape them (safe()) when shown. */
final class Reader
{
    /** LintFinding codes */
    public const UNKNOWN_COLUMN    = 'unknown-column';
    public const UNKNOWN_ELEMENT   = 'unknown-element';
    public const UNKNOWN_ATTRIBUTE = 'unknown-attribute';
    public const UNKNOWN_CLASS     = 'unknown-class';
    public const NEWER_FORMAT      = 'newer-format';
    /** xsi:schemaLocation / noNamespaceSchemaLocation of the root (editors add them): dropped, never loaded */
    public const SCHEMA_LOCATION   = 'schema-location';

    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const CONTAINERS = [Module::SITE, Module::SLICE, Module::ALERTS, 'constant-group'];

    /** attribute naming an element in a finding path, the first one present */
    private const PATH_KEYS = ['id', 'spot_id', 'name', 'module', 'collection', 'n'];

    private const INT_TYPES = ['xs:positiveInteger', 'xs:integer'];

    private readonly Schema $schema;
    private readonly XmlGuard $guard;
    /** @var array<string, list<string>> */
    private readonly array $known;
    /** @var array<string, list<string>> */
    private readonly array $attributes;
    private readonly string $xsd;
    /** @var array<string, array<string, string>> row element => its columns (Schema::rowColumns()) */
    private readonly array $columns;
    /** @var array<string, array<string, int>> row element => every column of its table in this AA */
    private readonly array $tableColumns;
    /** @var array<string, array<string, int>> row element => columns meta/tables lists */
    private array $listed = [];
    private bool $withItems = false;
    /** @var list<array{string, LintFinding}> */
    private array $findings = [];

    /** @param Schema|null $schema the format as this AA knows it; its xsd() equals the repo copy
     *                            Schema::FILE (SchemaTest) - generated, so pruning and XSD share one metabase */
    public function __construct(?Schema $schema = null, ?XmlGuard $guard = null)
    {
        $this->guard      = $guard ?? new XmlGuard();
        $this->schema     = $schema ?? new Schema();
        $this->xsd        = $this->schema->xsd();
        $this->known      = $this->schema->knownElements();
        $this->attributes = $this->schema->knownAttributes();
        $columns          = $table_columns = [];
        foreach (array_keys($this->schema->rowTables()) as $element) {
            $columns[$element]       = $this->schema->rowColumns($element);
            $table_columns[$element] = array_flip($this->schema->tableColumns($element));
        }
        $this->columns      = $columns;
        $this->tableColumns = $table_columns;
    }

    /** @throws PackageRejected the package must not be used */
    public function read(string $xml): ReadResult
    {
        return (clone $this)->parse($xml);
    }

    private function parse(string $xml): ReadResult
    {
        $dom  = $this->guard->load($xml);
        $root = $dom->documentElement;
        if (($root === null) || ($root->namespaceURI !== Schema::NS) || ($root->localName !== 'aa-app')) {
            throw new PackageRejected('This is not an aa-app package (root <aa-app xmlns="' . Schema::NS . '">)');
        }
        $format = $root->getAttribute('format');
        [$major, $minor] = explode('.', Writer::FORMAT);
        if (!preg_match('/^([0-9]{1,9})\.([0-9]{1,9})$/D', $format, $version)) {
            throw new PackageRejected($root->hasAttribute('format')
                ? 'The package format "' . XmlGuard::quote($format) . '" is not MAJOR.MINOR (e.g. "1.0", no spaces)'
                : 'The package has no format version (aa-app/@format, e.g. "1.0")');
        }
        if ((int) $version[1] !== (int) $major) {
            throw new PackageRejected("The package has format $format, this AA reads $major.x");
        }
        $newer = (int) $version[2] > (int) $minor;
        if ($newer) {
            $this->found(LintFinding::INFO, self::NEWER_FORMAT, 'aa-app', $format);
        }
        $this->prune($root, '', false, $newer);
        $this->guard->validate($dom, $this->xsd);
        return new ReadResult($this->package($root), $this->findings, $format);
    }

    // ---------------------------------------------------------------- pruning

    /** @param bool $row $el is a table row - its children are columns */
    private function prune(DOMElement $el, string $path, bool $row, bool $newer): void
    {
        $name = $el->localName;
        // a list, not keyed by name: xsi:type and type share the local name
        $attributes = [];
        foreach ($el->attributes as $attr) {
            $attributes[] = $attr;
        }
        foreach ($attributes as $attr) {
            $hint = ($path === '') && ($attr->namespaceURI === self::XSI) && in_array($attr->localName, ['schemaLocation', 'noNamespaceSchemaLocation'], true);
            if (($attr->namespaceURI !== null) && !$hint) {   // xsi:type would retype a value, xml:* is no part of the format
                throw new PackageRejected(($path ?: 'aa-app') . ': attribute ' . XmlGuard::quote($attr->nodeName) . ' is not allowed');
            }
            if ($hint || ($newer && !in_array($attr->name, $this->attributes[$name] ?? [], true))) {
                $el->removeAttributeNode($attr);
                $this->found($hint ? LintFinding::INFO : LintFinding::WARNING, $hint ? self::SCHEMA_LOCATION : self::UNKNOWN_ATTRIBUTE, $path ?: 'aa-app', $attr->nodeName);
            }
        }
        foreach (self::children($el) as $child) {
            if ($child->namespaceURI !== Schema::NS) {
                continue;                           // the XSD rejects it (XInclude, ...)
            }
            $child_name = $child->localName;
            $at         = ltrim("$path/" . self::step($child), '/');
            $class      = $child->getAttribute('class');
            [$code, $ref] = match (true) {
                $row    => [isset($this->tableColumns[$name][$child_name]) ? null : self::UNKNOWN_COLUMN, $child_name],
                !$newer => [null, ''],
                !in_array($child_name, $this->known[$name] ?? [], true) => [self::UNKNOWN_ELEMENT, $child_name],
                ($child_name === 'object') && !in_array($class, $name === 'object' ? Schema::SUB_CLASSES : Schema::TOP_CLASSES, true) => [self::UNKNOWN_CLASS, $class],
                default => [null, ''],
            };
            if ($code !== null) {
                $el->removeChild($child);
                $this->found(LintFinding::WARNING, $code, $at, $ref);
            } elseif ($child_name !== 'signature') {    // any content, not read in format 1.0
                $this->prune($child, $at, in_array($name, self::CONTAINERS, true) && isset($this->columns[$child_name]), $newer);
            }
        }
    }

    private function found(string $level, string $code, string $path, string $ref): void
    {
        $this->findings[] = [$path, new LintFinding($level, $code, XmlGuard::quote($ref), 0)];
    }

    // ---------------------------------------------------------------- model

    private function package(DOMElement $root): Package
    {
        $children = self::children($root);
        $meta     = $source = $modules = $groups = [];
        $requires = $batch = null;
        $items    = false;
        foreach (self::children(array_shift($children)) as $el) {   // meta first (XSD): meta/tables before the rows
            if ($el->localName === 'source') {
                foreach ($this->attributes['source'] as $attr) {
                    if ($el->hasAttribute($attr)) {
                        $source[$attr] = $el->getAttribute($attr);
                    }
                }
            } elseif ($el->localName === 'requires') {
                $requires = $el->hasAttribute('aa-version') ? $el->getAttribute('aa-version') : null;
            } elseif ($el->localName === 'contents') {
                $items = $this->withItems = self::bool($el->getAttribute('items'));
            } elseif ($el->localName === 'batch') {
                $batch = new Batch($el->getAttribute('after'), $el->getAttribute('next'), self::bool($el->getAttribute('finished')));
            } elseif ($el->localName === 'tables') {
                foreach (self::children($el) as $table) {
                    $name                = $table->getAttribute('name');
                    $this->listed[$name] = ($this->listed[$name] ?? []) + array_flip(self::words($table->getAttribute('columns')));
                }
            } else {
                $meta[$el->localName] = $el->textContent;
            }
        }
        foreach ($children as $el) {
            if ($el->localName === 'constant-group') {
                $groups[] = new ConstantGroup($el->getAttribute('name'), $el->getAttribute('owner'), $this->rows($el, self::step($el)));
            } elseif ($el->localName !== 'signature') {
                $modules[] = $this->module($el);
            }
        }
        return new Package($meta['name'] ?? '', $source, $modules, $groups, $meta['description'] ?? null, $meta['version'] ?? null, self::published($meta['publish'] ?? ''), $requires, $items, $meta['doc'] ?? null, $batch);
    }

    private function module(DOMElement $el): Module
    {
        $path     = self::step($el);
        $settings = $tree = $doc = null;
        $objects  = $items = $transfer = [];
        foreach (self::children($el) as $child) {
            $at = "$path/" . $child->localName;
            match ($child->localName) {
                'doc'       => $doc = $child->textContent,
                'spot-tree' => $tree = $this->spotTree($child, "$path/spot-tree"),
                'settings'  => $settings = $this->props($child, "$path/settings"),
                'object'    => $objects[] = $this->object($child, $path . '/' . self::step($child), true),
                'item'      => $items[] = $this->item($child, $path . '/' . self::step($child)),
                'owner'     => $transfer['owner'] = array_combine(['name', 'email'], array_map(fn(DOMElement $v): string|RawBytes|null => $this->value($v, "$at/$v->localName"), self::children($child))),
                'reading-password' => $transfer['reading-password'] = $this->value($child, $at),
                'setting-values'   => $transfer['setting-values'] = $this->item($child, $at)['content'],
                'alternate-url'    => $transfer['alternate-url'][] = $this->value($child, $at),
                default     => null,
            };
        }
        if ($items && !$this->withItems) {    // the flag gates the item import
            throw new PackageRejected("$path: items in a package without items (meta/contents/@items)");
        }
        return new Module($el->localName, $el->getAttribute('id'), $this->rows($el, $path), $settings, $objects, $tree, $items, $doc, $transfer);
    }

    /** @return array<string, list<array<string, string|RawBytes|null>>> row element => rows, document order */
    private function rows(DOMElement $container, string $path): array
    {
        $rows = [];
        foreach (self::children($container) as $el) {
            $element = $el->localName;
            if (!isset($this->columns[$element])) {
                continue;
            }
            $row = [];
            foreach ($this->schema->rowAttributes($element) as $column => $key) {
                $value        = $el->hasAttribute($key[0]) ? $el->getAttribute($key[0]) : (string) ($key[2] ?? '');
                $row[$column] = in_array($key[1], self::INT_TYPES, true) ? self::integer($value) : $value;
            }
            $at    = "$path/" . self::step($el);
            $cells = [];
            foreach (self::children($el) as $cell) {
                $cells[$cell->localName] = $cell;
            }
            foreach ($this->columns[$element] as $column => $type) {
                if (isset($cells[$column])) {
                    $row[$column] = $this->value($cells[$column], "$at/$column", $type);
                } elseif (isset($this->listed[$element][$column])) {
                    $row[$column] = ValueCodec::emptyValue($type);
                }
            }
            $rows[$element][] = $row;
        }
        return $rows;
    }

    /** a value element (column, <p>, <v>) decoded by ValueCodec */
    private function value(DOMElement $el, string $path, string $type = 'textValue'): string|RawBytes|null
    {
        $enc = $el->getAttribute('enc') ?: ValueCodec::TEXT;
        try {
            $value = ValueCodec::decode($el->textContent, $enc, self::bool($el->getAttribute('crlf')), self::bool($el->getAttribute('null')));
        } catch (InvalidArgumentException $e) {
            throw new PackageRejected("$path: " . $e->getMessage(), 0, $e);
        }
        return match (true) {
            $value === null                                                     => null,
            ($enc === ValueCodec::BASE64) && !mb_check_encoding($value, 'UTF-8') => new RawBytes($value),
            $value === ''                                                       => ValueCodec::emptyValue($type),   // <listlen/>
            $type === 'intValue'                                                => self::integer($value),
            default                                                             => $value,
        };
    }

    /** <settings> or an object: name => scalar (only index 0) | [index => scalar | subobject]
     *  @return array<string, mixed> */
    private function props(DOMElement $el, string $path): array
    {
        $props = [];
        foreach (self::children($el) as $child) {
            $name = trim($child->getAttribute('n'));    // xs:NCName collapses whitespace
            $at   = "$path/" . XmlGuard::quote($name);
            $i    = self::int($child->getAttribute('i'), $at);
            $at  .= "[$i]";
            if (isset($props[$name][$i])) {
                throw new PackageRejected("$at twice");
            }
            $props[$name][$i] = $child->localName === 'object' ? $this->object($child, $at, false) : $this->value($child, $at);
        }
        ksort($props, SORT_STRING);
        foreach ($props as $name => $values) {
            ksort($values, SORT_NUMERIC);
            $props[$name] = ((array_keys($values) === [0]) && !is_array($values[0])) ? $values[0] : $values;
        }
        return $props;
    }

    /** @return array<string, mixed> {id, class, name? (top-level only), props} */
    private function object(DOMElement $el, string $path, bool $top): array
    {
        $object = ['id' => $el->getAttribute('id'), 'class' => $el->getAttribute('class')];
        if ($top && $el->hasAttribute('name')) {
            $object['name'] = $el->getAttribute('name');
        }
        return $object + ['props' => $this->props($el, $path)];
    }

    /** @return array{id: string, content: array<string, array<int, array{value: string|RawBytes|null, flag: int}>>} */
    private function item(DOMElement $el, string $path): array
    {
        $content = [];
        foreach (self::children($el) as $v) {
            $field = $v->getAttribute('f');         // 16 characters (XSD)
            $i     = self::int($v->getAttribute('i'), "$path/$field");
            if (isset($content[$field][$i])) {
                throw new PackageRejected("$path: value {$field}[$i] twice");
            }
            $content[$field][$i] = ['value' => $this->value($v, "$path/{$field}[$i]"), 'flag' => self::int($v->getAttribute('flag'), "$path/{$field}[$i]")];
        }
        ksort($content, SORT_STRING);
        foreach ($content as $field => $values) {
            ksort($values, SORT_NUMERIC);
            $content[$field] = $values;
        }
        return ['id' => $el->getAttribute('id'), 'content' => $content];
    }

    /** @return array{start_id: string, spots: list<array<string, mixed>>} */
    private function spotTree(DOMElement $el, string $path): array
    {
        $spots = [];
        foreach (self::children($el) as $spot) {
            $id    = XmlGuard::quote(self::integer($spot->getAttribute('id')));
            $empty = self::words($spot->getAttribute('empty'));
            $lists = array_map(static fn(string $key): ?array => in_array($key, $empty, true) ? [] : null, ['v' => 'v', 'c' => 'c', 'po' => 'po', 'ch' => 'ch']);
            foreach (self::children($spot) as $child) {
                $text = $child->textContent;
                if (in_array($child->localName, ['positions', 'choices'], true)) {
                    $lists[$child->localName === 'positions' ? 'po' : 'ch'] = array_map(self::integer(...), self::words($text));
                    continue;
                }
                // v[name] = name, c[variable] = condition: a second one would overwrite the first
                [$key, $name] = $child->localName === 'var' ? ['v', $text] : ['c', $child->getAttribute('var')];
                if (isset($lists[$key][$name])) {
                    throw new PackageRejected("$path/spot[$id]: " . ($key === 'v' ? 'variable' : 'condition') . ' ' . XmlGuard::quote($name) . ' twice');
                }
                $lists[$key][$name] = $text;
            }
            $spots[] = ['id' => self::integer($spot->getAttribute('id')), 'n' => $spot->getAttribute('name'), 'p' => self::integer($spot->getAttribute('parent')), 'f' => self::int($spot->getAttribute('flag'), "$path/spot[$id]")] + $lists;
        }
        return ['start_id' => self::integer($el->getAttribute('start')), 'spots' => $spots];
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<DOMElement> */
    private static function children(DOMElement $el): array
    {
        $children = [];
        foreach ($el->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }
        return $children;
    }

    /** meta/publish: `all` = published; `no`, and `users` of older packages, are not */
    private static function published(string $publish): bool
    {
        return $publish === 'all';
    }

    /** an element in a finding path: its name and key, e.g. `view[12]` */
    private static function step(DOMElement $el): string
    {
        foreach (self::PATH_KEYS as $attr) {
            if ($el->hasAttribute($attr)) {
                return $el->localName . '[' . XmlGuard::quote($el->getAttribute($attr)) . ']';
            }
        }
        return $el->localName;
    }

    /** xs:boolean */
    private static function bool(string $value): bool
    {
        return in_array(trim($value), ['true', '1'], true);
    }

    /** the shortest decimal form of an XSD integer (' +007 ' => '7'); other text unchanged */
    private static function integer(string $value): string
    {
        if (!preg_match('/^\s*([+-]?)0*([0-9]+)\s*$/D', $value, $m)) {
            return $value;
        }
        return (($m[1] === '-') && ($m[2] !== '0') ? '-' : '') . $m[2];
    }

    /** an index or flag attribute ('' = 0) as a PHP int @throws PackageRejected out of its range */
    private static function int(string $value, string $path): int
    {
        $canonical = self::integer($value === '' ? '0' : $value);
        if ((string) (int) $canonical !== $canonical) {
            throw new PackageRejected("$path: " . XmlGuard::quote($value) . ' is out of range');
        }
        return (int) $canonical;
    }

    /** @return list<string> the items of an xs:list */
    private static function words(string $list): array
    {
        return preg_split('/\s+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
