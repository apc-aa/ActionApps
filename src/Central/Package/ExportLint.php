<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\RawBytes;
use Generator;

/** The export lint of a collected app: ReferenceLint over every definition text (spot conditions too - a
 *  `{..}` in them is expanded), the structured references, what e-mails carry of the source organisation,
 *  and the modules outside the app the references lead to.
 *  Item values are not linted: article texts link anywhere.
 *  @throws RewriteFailed from check() - a regex failed */
final class ExportLint
{
    /** E: the alerts collection reads a slice outside the app */
    public const READER_OUTSIDE = 'reader-outside';
    /** I: an alerts module outside the app reads a slice of the app */
    public const ALERTS_OUTSIDE = 'alerts-outside';
    /** email.attachments as the import sees them (SecurityLint::attachments()): S URLs or a field, W dropped */
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
     * @param array<string, array{?array<string, mixed>, list<array<string, mixed>>}> $objects app module id =>
     *                                                                     [settings, objects] (ObjectExport, UTF-8)
     * @param array<string, array<string, mixed>>                        $trees    site id => sitetree::toArray() (UTF-8)
     * @param array<string, array<string, mixed>>                        $settingValues slice id => the values of its settings
     *                                                                     fields a transfer carries (Collector::settingValues())
     * @return array{list<array{string, LintFinding}>, array<string, list<string>>} findings, outside (CollectResult)
     */
    public function check(array $rows, array $types, array $groups, array $objects = [], array $trees = [], array $settingValues = []): array
    {
        $this->findings = $this->outside = [];
        $all            = static function (array $lists, string $element, string $column): array {
            $values = [];
            foreach ($lists as $elements) {
                array_push($values, ...array_column($elements[$element] ?? [], $column));
            }
            return $values;
        };
        $values = $object_ids = [];
        foreach ($objects as $id => [$settings, $list]) {
            array_push($values, ...ObjectExport::values($types[$id], $settings, $list, "$types[$id][$id]"));
            array_push($object_ids, ...ObjectExport::ids($list));
        }
        $constants = $all(array_map(static fn(ConstantGroup $g): array => $g->rows, $groups), 'constant', 'id');
        $map       = LintTexts::identity(
            array_keys($types),
            [...$constants, ...$object_ids],
            $all($rows, 'view', 'id'),
            $all($rows, 'email', 'id'),
            array_map(static fn(ConstantGroup $g): string => $g->name, $groups),
            $all($rows, 'alerts_collection', 'id'),
        );
        $lint      = new ReferenceLint($map);
        $of        = new LintTexts($this->schema);       // RawBytes (not UTF-8) are not linted
        $texts     = array_map(static fn(string $id): Generator => $of->rows("$types[$id][$id]", $rows[$id]), array_keys($rows));
        foreach ($groups as $group) {
            $texts[] = $of->rows("constant-group[$group->name]", $group->rows);
        }
        $texts[] = LintTexts::code($values);      // properties that are code - no names or enum values
        foreach ($settingValues as $id => $list) {
            $texts[] = LintTexts::settingValues("$types[$id][$id]", $list);
        }
        foreach ($trees as $id => $tree) {
            $texts[] = LintTexts::conditions("$types[$id][$id]/spot-tree", $tree['spots']);
            foreach ($tree['spots'] as $spot) {          // the tag start the import reports (SecurityLint::SPOT_MARKUP)
                foreach (LintTexts::spotTexts("$types[$id][$id]/spot-tree/spot[{$spot['id']}]", $spot) as $at => $spot_text) {
                    if (preg_match(SecurityLint::SPOT_TAG, $spot_text)) {
                        $this->add($at, LintFinding::WARNING, SecurityLint::SPOT_MARKUP, $spot_text);
                    }
                }
            }
        }
        foreach ($texts as $generator) {
            foreach ($generator as $entry) {
                foreach (LintTexts::lint($lint, ...$entry) as $finding) {
                    $this->findings[] = [$entry[0], $finding];
                }
            }
        }
        foreach ($rows as $id => $elements) {
            $this->structured("$types[$id][$id]", $elements, $types, $map);
        }
        foreach ($values as [$at, $class, $name, $value]) {
            $module_id = in_array($name, ObjectExport::MODULE_PROPS[$class] ?? [], true) && is_string($value) && preg_match('/^[0-9a-f]{32}\z/i', $value);
            if ($module_id && ($map->module($value) === null)) {
                $this->add($at, LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, $value);
            }
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

    /** a published app has no warnings - each W becomes E
     *  @param list<array{string, LintFinding}> $findings @return list<array{string, LintFinding}> */
    public static function published(array $findings): array
    {
        return array_map(static fn(array $f): array => ($f[1]->level === LintFinding::WARNING) ? [$f[0], new LintFinding(LintFinding::ERROR, $f[1]->code, $f[1]->ref, $f[1]->offset)] : $f, $findings);
    }

    /** references in columns the import maps directly, e-mail headers
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
        $text = ValueCodec::bytes(...);
        foreach ($elements['profile'] ?? [] as $i => $profile) {
            if ($profile['property'] === 'input_view') {
                $view($text($profile['value']), "$path/profile[#$i]/value");
            }
        }
        foreach ($elements['alerts_filter'] ?? [] as $filter) {
            $view($filter['vid'], "$path/alerts_filter[{$filter['id']}]/vid");
        }
        foreach ($elements['email'] ?? [] as $email) {
            if (($finding = SecurityLint::attachments($text($email['attachments'] ?? ''))) !== null) {   // as the import sees them
                $this->findings[] = ["$path/email[{$email['id']}]/attachments", $finding];
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

    /** the modules owning the views and e-mails referred to outside the app, and the referred modules that
     *  exist. `+N more` (ReferenceLint's summary) is no reference. */
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
