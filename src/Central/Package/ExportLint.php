<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\RawBytes;
use Generator;

/** The export lint of a collected app (design balicek-v1.md § 8.4, § 5.2): ReferenceLint over every
 *  definition text with the identity map of the app, the structured references (§ 6), what e-mails
 *  carry of the source organisation, and the closure - the modules outside the app the references
 *  lead to. Item values are not linted: article texts link anywhere.
 *  @throws RewriteFailed from check() - a regex failed; the export failed */
final class ExportLint
{
    /** E: the alerts collection reads a slice outside the app */
    public const READER_OUTSIDE = 'reader-outside';
    /** I: an alerts module outside the app reads a slice of the app (§ 5.2) */
    public const ALERTS_OUTSIDE = 'alerts-outside';
    /** I: email.attachments - files of the source installation */
    public const EMAIL_ATTACHMENTS = 'email-attachments';
    /** I: a literal address in the e-mail headers - the source organisation's address travels */
    public const EMAIL_ADDRESS = 'email-address';

    private const EMAIL_HEADERS = ['header_from', 'reply_to', 'errors_to', 'sender'];

    /** @var list<array{string, LintFinding}> */
    private array $findings = [];
    /** @var array<string, list<string>> */
    private array $outside = [];

    public function __construct(private readonly Source $source, private readonly Schema $schema) {}

    /**
     * @param array<string, array<string, list<array<string, mixed>>>> $rows     app module id => element => rows (UTF-8)
     * @param array<string, string>                                     $types    app module id => Module::SITE | SLICE | ALERTS
     * @param list<ConstantGroup>                                       $groups
     * @param list<string>                                              $item_ids
     * @return array{list<array{string, LintFinding}>, array<string, list<string>>} findings, outside (CollectResult)
     */
    public function check(array $rows, array $types, array $groups, array $item_ids): array
    {
        $this->findings = $this->outside = [];
        $all            = static function (array $lists, string $element, string $column): array {
            $values = [];
            foreach ($lists as $elements) {
                array_push($values, ...array_column($elements[$element] ?? [], $column));
            }
            return $values;
        };
        $ident = static fn(array $ids): array => $ids ? array_combine($ids, $ids) : [];
        $longs = [...$all(array_map(static fn(ConstantGroup $g): array => $g->rows, $groups), 'constant', 'id'), ...$item_ids];
        $map   = new IdMap(
            $ident(array_keys($types)),
            $ident(array_values(preg_grep('/^[0-9a-f]{32}\z/', $longs))),
            $ident(array_map('intval', $all($rows, 'view', 'id'))),
            $ident(array_map('intval', $all($rows, 'email', 'id'))),
            $ident(array_map(static fn(ConstantGroup $g): string => $g->name, $groups)),
            $ident(array_map('strval', $all($rows, 'alerts_collection', 'id'))),
        );
        $lint  = new ReferenceLint($map);
        $texts = array_map(fn(string $id): Generator => $this->texts("$types[$id][$id]", $rows[$id]), array_keys($rows));
        foreach ($groups as $group) {
            $texts[] = $this->texts("constant-group[$group->name]", $group->rows);
        }
        foreach ($texts as $generator) {
            foreach ($generator as [$path, $text]) {
                foreach ($lint->lint($text) as $finding) {
                    // the group of a widget is a structured reference (rewriteInputShowFunc())
                    if (($finding->code !== ReferenceLint::GROUP_OTHER) || !str_ends_with($path, '/input_show_func') || ($finding->ref !== ReferenceRewriter::inputShowFuncGroup($text))) {
                        $this->findings[] = [$path, $finding];
                    }
                }
            }
        }
        foreach ($rows as $id => $elements) {
            $this->structured("$types[$id][$id]", $elements, $types, $map);
        }
        $slices = array_keys($types, Module::SLICE, true);
        foreach ($slices ? $this->source->select('alerts_collection', [['slice_id', $slices, 'l']]) : [] as $c) {
            if (!isset($types[$c['module_id']])) {
                $this->add(Module::SLICE . "[{$c['slice_id']}]", LintFinding::INFO, self::ALERTS_OUTSIDE, $c['module_id']);
            }
        }
        $this->closure();
        return [$this->findings, $this->outside];
    }

    /** D2: a published app has no warnings - each W becomes E
     *  @param list<array{string, LintFinding}> $findings @return list<array{string, LintFinding}> */
    public static function published(array $findings): array
    {
        return array_map(static fn(array $f): array => ($f[1]->level === LintFinding::WARNING) ? [$f[0], new LintFinding(LintFinding::ERROR, $f[1]->code, $f[1]->ref, $f[1]->offset)] : $f, $findings);
    }

    /** Text columns of rows - [path, text]; the input_view of the profile is a structured reference,
     *  RawBytes (not converted to UTF-8) are not linted.
     *  @param array<string, list<array<string, mixed>>> $elements
     *  @return Generator<int, array{string, string}> */
    public function texts(string $path, array $elements): Generator
    {
        foreach ($elements as $element => $list) {
            $columns = array_keys($this->schema->rowColumns($element), 'textValue', true);
            foreach ($list as $i => $row) {
                foreach ($columns as $column) {
                    $text = $row[$column] ?? '';
                    if (is_string($text) && ($text !== '') && !(($element === 'profile') && ($row['property'] === 'input_view'))) {
                        yield [$this->rowPath($path, $element, $row, $i) . "/$column", $text];
                    }
                }
            }
        }
    }

    /** a row by its key attributes, a keyless row by its position (`profile[#0]`) @param array<string, mixed> $row */
    public function rowPath(string $path, string $element, array $row, int $i): string
    {
        $keys = array_keys($this->schema->rowAttributes($element));
        return "{$path}/{$element}[" . ($keys ? implode(',', array_map(static fn(string $k): string => (string) ($row[$k] ?? ''), $keys)) : "#$i") . ']';
    }

    /** references in columns the import maps directly (§ 8.2 "strukturované", § 6), e-mail headers
     *  @param array<string, list<array<string, mixed>>> $elements @param array<string, string> $types */
    private function structured(string $path, array $elements, array $types, IdMap $map): void
    {
        $view = function (mixed $vid, string $at) use ($map): void {
            if (((int) $vid > 0) && ($map->view((int) $vid) === null)) {
                $this->add($at, LintFinding::WARNING, ReferenceLint::VIEW_OUTSIDE, (string) (int) $vid);
            }
        };
        foreach ($elements['slice'] ?? [] as $i => $slice) {
            $view($slice['vid'] ?? 0, "$path/slice[#$i]/vid");
            if ((($mlx = (string) ($slice['mlxctrl'] ?? '')) !== '') && ($map->module($mlx) === null)) {
                $this->add("$path/slice[#$i]/mlxctrl", LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, $mlx);
            }
        }
        $text = static fn(mixed $value): string => ($value instanceof RawBytes) ? $value->bytes : (string) $value;
        foreach ($elements['profile'] ?? [] as $i => $profile) {
            if ($profile['property'] === 'input_view') {
                $view($text($profile['value']), "$path/profile[#$i]/value");
            }
        }
        foreach ($elements['alerts_filter'] ?? [] as $filter) {
            $view($filter['vid'], "$path/alerts_filter[{$filter['id']}]/vid");
        }
        foreach ($elements['email'] ?? [] as $email) {
            if (($attachments = $text($email['attachments'] ?? '')) !== '') {
                $this->add("$path/email[{$email['id']}]/attachments", LintFinding::INFO, self::EMAIL_ATTACHMENTS, $attachments);
            }
            foreach (self::EMAIL_HEADERS as $column) {
                $value = $text($email[$column] ?? '');
                if (str_contains($value, '@') && !str_contains($value, '{') && !str_contains($value, '_#')) {
                    $this->add("$path/email[{$email['id']}]/$column", LintFinding::INFO, self::EMAIL_ADDRESS, $value);
                }
            }
        }
        foreach ($elements['alerts_collection'] ?? [] as $c) {
            $at = "$path/alerts_collection[{$c['id']}]";
            foreach (['emailid_welcome', 'emailid_alert'] as $column) {
                if (((int) $c[$column] > 0) && ($map->email((int) $c[$column]) === null)) {
                    $this->add("$at/$column", LintFinding::WARNING, ReferenceLint::EMAIL_OUTSIDE, (string) $c[$column]);
                }
            }
            if ((($reader = (string) $c['slice_id']) !== '') && (($types[$reader] ?? '') !== Module::SLICE)) {
                $this->add("$at/slice_id", LintFinding::ERROR, self::READER_OUTSIDE, $reader);
                $this->refer($reader, "$at/slice_id");
            }
        }
    }

    /** § 5.2: the modules owning the views and e-mails referred to from outside the app, and the
     *  referred modules that exist. `+N more` (ReferenceLint's summary) is no reference. */
    private function closure(): void
    {
        $refs = ['view' => [], 'email' => [], 'module' => []];
        foreach ($this->findings as [$path, $finding]) {
            $kind = [ReferenceLint::VIEW_OUTSIDE => 'view', ReferenceLint::EMAIL_OUTSIDE => 'email', ReferenceLint::MODULE_OUTSIDE => 'module'][$finding->code] ?? null;
            if (($kind !== null) && !str_starts_with($finding->ref, '+')) {
                $refs[$kind][($kind === 'module') ? strtolower($finding->ref) : (int) $finding->ref][] = $path;
            }
        }
        $owners = [
            ['view', 'id', 'i', 'slice_id'],
            ['email', 'id', 'i', 'owner_module_id'],
            ['module', 'id', 'l', 'id'],
        ];
        foreach ($owners as [$table, $key, $type, $owner]) {
            foreach ($refs[$table] ? $this->source->select($table, [[$key, array_map('strval', array_keys($refs[$table])), $type]]) : [] as $row) {
                foreach ($refs[$table][($type === 'l') ? (string) $row[$key] : (int) $row[$key]] ?? [] as $path) {
                    $this->refer((string) $row[$owner], $path);
                }
            }
        }
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings[] = [$path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0)];
    }

    /** a module outside the app referred to at $path */
    private function refer(string $module_id, string $path): void
    {
        if (!in_array($path, $this->outside[$module_id] ?? [], true)) {
            $this->outside[$module_id][] = $path;
        }
    }
}
