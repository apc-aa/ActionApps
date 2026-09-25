<?php

declare(strict_types=1);

namespace AA\Central\Package;

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
     * @param IdMap                             $map           source => target ids; views, e-mails, filters empty
     * @param array<string, string>             $charsets      source module id => target charset (its lang_file)
     * @param array<string, string>             $langFiles     source module id => module.lang_file to write
     * @param array<string, string>             $groupCharsets source group name => charset of its constants
     * @param array<string, string>             $settingsIds   source module id => id of its new settings object
     * @param list<FixUp>                       $fixUps        what FixUps changed, in package order
     * @param list<array{string, LintFinding}>  $validation    Validator's findings (no E - or there is no plan)
     * @param list<array{string, LintFinding}>  $findings      the planner's: group renames (I), charset and length (E, W)
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
     *                confirm: bool, customCommands: bool} */
    public function preview(): array
    {
        $modules = [];
        foreach ($this->package->modules as $m) {
            $modules[] = ['type' => $m->type, 'name' => ValueCodec::bytes($m->rows['module'][0]['name'] ?? ''), 'old' => $m->id,
                'new' => (string) $this->map->module($m->id), 'charset' => $this->charsets[$m->id] ?? '', 'doc' => $m->doc];
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
            'confirm' => (bool) $this->ofLevel(LintFinding::SECURITY), 'customCommands' => defined('STRINGEXPAND_INC')];
    }
}
