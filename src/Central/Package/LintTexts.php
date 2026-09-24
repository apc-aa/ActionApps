<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Generator;

/** What ExportLint and ImportLint share (design balicek-v1.md § 8.4): the identity map of an app, the
 *  definition texts ReferenceLint reads - text columns of rows, spot conditions - and the one finding
 *  that is no finding: the group of a field widget, a structured reference (rewriteInputShowFunc()).
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

    /** Text columns of rows - [path, text]; the input_view of the profile is a structured reference.
     *  RawBytes: skipped, or with $raw their bytes (the import lints what it writes; the regexes are
     *  byte-wise and ASCII-anchored, ModuleCharset::STATELESS).
     *  @param array<string, list<array<string, mixed>>> $elements
     *  @return Generator<int, array{string, string}> */
    public function rows(string $path, array $elements, bool $raw = false): Generator
    {
        foreach ($elements as $element => $list) {
            $columns = array_keys($this->schema->rowColumns((string) $element), 'textValue', true);
            foreach ($list as $i => $row) {
                foreach ($columns as $column) {
                    $text = $row[$column] ?? '';
                    $text = ($raw && ($text instanceof Model\RawBytes)) ? $text->bytes : $text;
                    if (is_string($text) && ($text !== '') && !(($element === 'profile') && (($row['property'] ?? '') === 'input_view'))) {
                        yield [$this->schema->rowPath($path, (string) $element, $row, $i) . "/$column", $text];
                    }
                }
            }
        }
    }

    /** the condition variables and values of the spots - [path, text]; the n-th condition `cond[#n]`
     *  @param mixed $spots sitetree::toArray()['spots'] @return Generator<int, array{string, string}> */
    public static function conditions(string $path, mixed $spots): Generator
    {
        foreach (is_array($spots) ? $spots : [] as $spot) {
            foreach (array_keys(is_array($spot['c'] ?? null) ? $spot['c'] : []) as $k => $var) {
                $at = "$path/spot[" . ValueCodec::bytes($spot['id'] ?? '') . "]/cond[#$k]";
                yield ["$at@var", (string) $var];
                yield [$at, ValueCodec::bytes($spot['c'][$var])];
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

    /** ReferenceLint of one text - without the group of a field widget, a structured reference
     *  @return list<LintFinding> */
    public static function lint(ReferenceLint $lint, string $path, string $text): array
    {
        return array_values(array_filter($lint->lint($text), static fn(LintFinding $f): bool => ($f->code !== ReferenceLint::GROUP_OTHER)
            || !str_ends_with($path, '/input_show_func') || ($f->ref !== ReferenceRewriter::inputShowFuncGroup($text))));
    }
}
