<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;

/** The plan of one import: everything the writer needs and the preview shows, computed with no write.
 *  The plan reserves nothing: another import or an admin may take a group name or a collection id before
 *  the write (constant.group_id is not unique - nothing in the DB would stop a merge), so the writer checks
 *  them again inside the transaction.
 *  Everything here quotes package text - the UI must escape all of it. */
final readonly class ImportPlan
{
    /**
     * @param Package                           $package       the package to write: FixUps applied, still UTF-8 and source ids
     * @param IdMap                             $map           source => target ids; of views and e-mails only
     *                                                         those an existing slice has, no filters
     * @param array<string, string>             $charsets      source module id => target charset (its lang_file)
     * @param array<string, string>             $langFiles     source module id => module.lang_file to write
     * @param array<string, string>             $groupCharsets source group name => charset of its constants
     * @param array<string, string>             $settingsIds   source module id => id of its new settings object
     * @param list<FixUp>                       $fixUps        what FixUps changed, in package order
     * @param list<array{string, LintFinding}>  $validation    Validator's findings (no E - or there is no plan)
     * @param list<array{string, LintFinding}>  $findings      the planner's: group renames (I), charset and length (E, W)
     * @param array<string, ExistingSlice>      $existing      source slice id => the slice of the target it goes
     *                                                         into ($package holds only what that slice lacks)
     * @param array<string, array<string, array<int, array{value: string, flag: int}>>> $settingDefaults
     *                                                         source slice id => the values an install gives its
     *                                                         settings fields (SettingDefaults) - new slices only
     */
    public function __construct(
        public Package $package,
        public IdMap $map,
        public array $charsets,
        public array $langFiles,
        public array $groupCharsets,
        public array $settingsIds,
        public array $fixUps,
        public array $validation,
        public array $findings,
        public array $existing = [],
        public array $settingDefaults = [],
    ) {}

    /** no E: the writer may start once the S findings were confirmed and the rights checked */
    public function importable(): bool
    {
        return !$this->ofLevel(LintFinding::ERROR);
    }

    /** @return list<array{string, LintFinding}> of the validator and the planner */
    public function ofLevel(string $level): array
    {
        return array_values(array_filter($this->allFindings(), static fn(array $f): bool => $f[1]->level === $level));
    }

    /** @return list<array{string, LintFinding}> validator + planner, by danger S, E, W, I */
    public function allFindings(): array
    {
        return FindingList::sort([...$this->validation, ...$this->findings]);
    }

    /** The data of the import preview - no HTML; all strings are package text, escape them.
     *  `confirm`: there are S findings, so the write needs ImportConfirmation::token().
     *  `customCommands`: SecurityLint does not know the commands this AA adds by STRINGEXPAND_INC.
     *  @return array{name: string, description: ?string, version: ?string, doc: ?string, source: array<string, string>,
     *                modules: list<array{type: string, name: string, old: string, new: string, charset: string, doc: ?string}>,
     *                groups: list<array{old: string, new: string}>, fixUps: list<FixUp>, findings: list<array{string, LintFinding}>,
     *                confirm: bool, customCommands: bool, settings: list<array{module: string, name: string, fields: array<string, string>}>}
     *          settings: the slices with settings fields left empty - source id, name, field id => name */
    public function preview(): array
    {
        $modules = $settings = [];
        foreach ($this->package->modules as $m) {
            $name      = $this->moduleName($m);
            $modules[] = ['type' => $m->type, 'name' => $name, 'old' => $m->id,
                'new' => (string) $this->map->module($m->id), 'charset' => $this->charsets[$m->id] ?? '', 'doc' => $m->doc];
            if ($fields = $this->settingsToFill($m)) {
                $settings[] = ['module' => $m->id, 'name' => $name, 'fields' => $fields];
            }
        }
        $groups = [];
        foreach ($this->map->groups() as $old => $new) {
            if ($new !== (string) $old) {
                $groups[] = ['old' => (string) $old, 'new' => $new];
            }
        }
        $p = $this->package;
        return ['name' => $p->name, 'description' => $p->description, 'version' => $p->version, 'doc' => $p->doc, 'source' => $p->source,
            'modules' => $modules, 'groups' => $groups, 'fixUps' => $this->fixUps, 'findings' => $this->allFindings(),
            'confirm' => (bool) $this->ofLevel(LintFinding::SECURITY), 'customCommands' => defined('STRINGEXPAND_INC'), 'settings' => $settings];
    }

    /** the name of a module: an existing slice's own, else the package's */
    public function moduleName(Module $m): string
    {
        return isset($this->existing[$m->id]) ? $this->existing[$m->id]->name : ValueCodec::bytes($m->rows['module'][0]['name'] ?? '');
    }

    /** The settings fields (id starts with `_`) of a slice left empty by the import - neither a value of a
     *  transfer nor a default of an install; a slice going into an existing one: its new fields
     *  @return array<string, string> field id => name */
    public function settingsToFill(Module $m): array
    {
        if ($m->type !== Module::SLICE) {
            return [];
        }
        $fields = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (str_starts_with($id, '_') && !isset($m->transfer['setting-values'][$id]) && !isset($this->settingDefaults[$m->id][$id])) {
                $fields[$id] = ValueCodec::bytes($row['name'] ?? '');
            }
        }
        return $fields;
    }
}
