<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Closure;
use Generator;

/** What ExportLint and ImportLint share: the identity map of an app, the
 *  definition texts ReferenceLint reads - text columns of rows, spot conditions - and the one finding
 *  that is no finding: the group of a group column, a structured reference (ReferenceRewriter::groupColumn()).
 *  Also the texts Collector::groups() searches for {constant(s)/options:<g>} and Planner mirrors. */
final class LintTexts
{
    public function __construct(private readonly Schema $schema) {}

    /** each id of the app mapped to itself - what the export carries, what the import plan maps
     *  @param list<string> $modules @param list<string> $longs constants, objects, subobjects, items
     *  @param list<int|string> $views @param list<int|string> $emails @param list<string> $groups @param list<string> $collections */
    public static function identity(array $modules, array $longs, array $views, array $emails, array $groups, array $collections): IdMap
    {
        $ident = static fn(array $ids): array => $ids ? array_combine($ids, $ids) : [];
        return new IdMap(
            $ident($modules),
            $ident(array_values(preg_grep('/^[0-9a-f]{32}\z/', $longs))),
            $ident(array_map('intval', $views)),
            $ident(array_map('intval', $emails)),
            $ident(array_values(preg_grep(IdMap::GROUP_RE, $groups))),
            $ident(array_map('strval', $collections)),
        );
    }

    /** Text columns of rows - [path, text, the group of a group column (ReferenceRewriter::groupColumn()) or
     *  null]; the input_view of the profile is a structured reference.
     *  RawBytes: skipped, or with $raw their bytes (the import lints what it writes; the regexes are
     *  byte-wise and ASCII-anchored, ModuleCharset::STATELESS).
     *  @param array<string, list<array<string, mixed>>> $elements
     *  @return Generator<int, array{string, string, ?string}> */
    public function rows(string $path, array $elements, bool $raw = false): Generator
    {
        foreach ($elements as $element => $list) {
            $columns = array_keys($this->schema->rowColumns((string) $element), 'textValue', true);
            foreach ($list as $i => $row) {
                $group = ReferenceRewriter::groupColumn((string) $element, $row);
                foreach ($columns as $column) {
                    $text = $row[$column] ?? '';
                    $text = ($raw && ($text instanceof Model\RawBytes)) ? $text->bytes : $text;
                    if (is_string($text) && ($text !== '') && !(($element === 'profile') && (($row['property'] ?? '') === 'input_view'))) {
                        yield [$this->schema->rowPath($path, (string) $element, $row, $i) . "/$column", $text, ($column === $group) ? ReferenceRewriter::rowGroup((string) $element, $row) : null];
                    }
                }
            }
        }
    }

    /** The text values of the settings fields a transfer brings (Module::$transfer['setting-values']) - templates
     *  like any definition; RawBytes as rows() - [path, text]
     *  @return Generator<int, array{string, string}> */
    public static function settingValues(string $path, mixed $values, bool $raw = false): Generator
    {
        foreach (is_array($values) ? $values : [] as $field => $list) {
            foreach (is_array($list) ? $list : [] as $key => $v) {
                $text = is_array($v) ? ($v['value'] ?? '') : '';
                $text = ($raw && ($text instanceof Model\RawBytes)) ? $text->bytes : $text;
                if (is_string($text) && ($text !== '')) {
                    yield ["$path/setting-values/{$field}[$key]", $text];
                }
            }
        }
    }

    /** The texts of a spot (sitetree::toArray() form), each through $fn(text, path) and back into the spot:
     *  the name `<at>@name`, the variables `<at>/var[#k]` (keyed by their name), the conditions - variable
     *  `<at>/cond[#k]@var` and value `<at>/cond[#k]`.
     *  @param array<mixed> $spot @param Closure(mixed, string): mixed $fn @return array<mixed> */
    public static function mapSpot(string $at, array $spot, Closure $fn): array
    {
        $spot['n'] = $fn($spot['n'] ?? '', "$at@name");
        if (is_array($spot['v'] ?? null)) {
            $vars = [];
            foreach (array_values($spot['v']) as $k => $var) {
                $name                           = $fn($var, "$at/var[#$k]");
                $vars[ValueCodec::bytes($name)] = $name;
            }
            $spot['v'] = $vars;
        }
        if (is_array($spot['c'] ?? null)) {
            $conds = [];
            foreach (array_keys($spot['c']) as $k => $var) {
                $conds[ValueCodec::bytes($fn((string) $var, "$at/cond[#$k]@var"))] = $fn($spot['c'][$var], "$at/cond[#$k]");
            }
            $spot['c'] = $conds;
        }
        return $spot;
    }

    /** the texts of a spot (mapSpot()): path => text @param array<mixed> $spot @return array<string, string> */
    public static function spotTexts(string $at, array $spot): array
    {
        $texts = [];
        self::mapSpot($at, $spot, static function (mixed $text, string $path) use (&$texts): mixed {
            $texts[$path] = ValueCodec::bytes($text);
            return $text;
        });
        return $texts;
    }

    /** the condition variables and values of the spots - [path, text]
     *  @param mixed $spots sitetree::toArray()['spots'] @return Generator<int, array{string, string}> */
    public static function conditions(string $path, mixed $spots): Generator
    {
        foreach (is_array($spots) ? $spots : [] as $spot) {
            $at = "$path/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']';
            foreach (self::spotTexts($at, (array) $spot) as $text_at => $text) {
                if (str_starts_with($text_at, "$at/cond[")) {
                    yield [$text_at, $text];
                }
            }
        }
    }

    /** the object properties that are code (ObjectExport::isCode()) - [path, text]
     *  @param list<array{string, string, string, mixed}> $values ObjectExport::values()
     *  @return Generator<int, array{string, string}> */
    public static function code(array $values, bool $raw = false): Generator
    {
        foreach ($values as [$at, $class, $name, $value]) {
            $value = ($raw && ($value instanceof Model\RawBytes)) ? $value->bytes : $value;
            if (ObjectExport::isCode($class, $name) && is_string($value) && ($value !== '')) {
                yield [$at, $value];
            }
        }
    }

    /** ReferenceLint of one text - without the group of a group column, a structured reference the import rewrites
     *  @param string|null $group rows()' third value
     *  @return list<LintFinding> */
    public static function lint(ReferenceLint $lint, string $path, string $text, ?string $group = null): array
    {
        return array_values(array_filter($lint->lint($text), static fn(LintFinding $f): bool => ($f->code !== ReferenceLint::GROUP_OTHER) || ($f->ref !== $group)));
    }
}
