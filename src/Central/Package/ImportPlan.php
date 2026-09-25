<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** The plan of one import (design balicek-v1.md § 7.2 step 3; C4.4 part 3, Planner): everything the
 *  writer (part 4) needs and the preview shows - computed with no write.
 *
 *  For the writer:
 *  - write $package (the FixUps applied; texts UTF-8, source ids) with $map; module.lang_file = $langFiles;
 *  - rewrite each value with ImportRewrite - numeric(known($value)), RawBytes too - then convert a text
 *    with ModuleCharset::fromUtf8() to $charsets[module] / $groupCharsets[group] and THROW on null
 *    (the plan proved it converts - a null is a bug); check the length again after numeric()
 *    (TargetCharset: the contract) and fail before the write;
 *  - the view, e-mail and filter maps are empty: withViews() / withEmails() / withFilters() after the inserts.
 *  THE PLAN IS NO RESERVATION. Between the preview and the write another import or an admin may take a
 *  group name or a collection id; constant.group_id is not unique, so nothing in the DB would stop a
 *  merge (§ 7.1). The writer re-checks ImportTarget::groupTaken() for every mapped group name and that
 *  every collection id is free inside the transaction and aborts when one is taken.
 *
 *  Everything here quotes package text - names, docs, paths, finding refs, fix-ups: the UI must
 *  escape all of it (safe()). */
final readonly class ImportPlan
{
    /**
     * @param Package                           $package       the package to write: FixUps applied, still UTF-8 and source ids
     * @param IdMap                             $map           source => target ids; views, e-mails, filters empty
     * @param array<string, string>             $charsets      source module id => target charset (its lang_file)
     * @param array<string, string>             $langFiles     source module id => module.lang_file to write (ModuleCharset::ofModule():
     *                                                         `.php3` read as `.php`)
     * @param array<string, string>             $groupCharsets source group name => charset of its constants
     * @param array<string, string>             $settingsIds   source module id => id of its new settings object
     *                                                         (string2id(class . new module id)); site and slice modules
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

    /** no E of the validator (there is no plan then) or of the planner: the writer may start - once the
     *  S findings were confirmed in the preview (ImportConfirmation) and the rights checked (ImportAuthority);
     *  Importer::write() refuses without them */
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

    /** The data of the import preview (§ 7.2 step 3) - no HTML. ALL strings are package text: safe().
     *  `confirm`: S findings - the preview asks for an explicit confirmation and hands the token of
     *  ImportConfirmation::token() to the write only when it was given (§ 3.3 point 3). The preview also
     *  says that the package installs code running with the rights of this AA, where it comes from
     *  (`source`), and - `customCommands` - that the SecurityLint does not know the commands this AA adds
     *  by STRINGEXPAND_INC (include/custom/<dir>/stringexpand.php).
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
