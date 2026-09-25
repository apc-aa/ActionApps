<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;

/** The report of a written import (design balicek-v1.md § 7.2 step 9; C4.4 part 5) - plain data, no HTML.
 *  EVERY string in it is package text or derived from it (names, paths, refs, fix-ups, errors quoting
 *  them): the UI escapes all of it (safe()).
 *
 *  A failed import is no report: ImportFailed (the cause, the journal, the compensation). Here all rows
 *  were written - $rows counts them by table; what the import changed or dropped on the way is $ignored
 *  (the FixUps: e-mail attachments, lang_file, dropped site settings, spot markup, publish = users, a shared
 *  filter); what AA did after the commit is $finish, each step OK or its errors (FinishReport::failures():
 *  what is left to do by hand - the import itself stays). The Reader's pruning findings (§ 3.2) are not in the plan: the
 *  caller adds them from ReadResult::$findings. */
final readonly class ImportReport
{
    /** the admin page of the settings of a slice (the `_` fields) - relative to AA_INSTAL_PATH; change_id
     *  switches the admin to that slice (include/init_page.php) */
    public const SETTINGS_URL = 'admin/slicefieldsedit.php?edit=1&change_id=';

    /**
     * @param array{name: string, version: ?string, description: ?string, source: array<string, string>} $package
     * @param list<array{type: string, name: string, old: string, new: string}> $modules the package's modules, old => new id
     * @param array<string, int>                   $rows     table => rows written (from the package; the
     *                                                       AUTO_INCREMENT tables from the journal)
     * @param list<FixUp>                          $ignored  what the import changed or dropped
     * @param array{views: array<int, int>, emails: array<int, int>, filters: array<int, int>, groups: array<string, string>,
     *              collections: array<string, string>, objects: array<string, string>, items: array<string, int>} $maps
     *                                                       old => new; items: new slice => items written
     * @param list<array{string, LintFinding}>     $findings validator + planner, by danger S, E, W, I
     * @param FinishReport                         $finish   the steps after the commit: steps() [step, errors], none = OK
     * @param list<array{module: string, name: string, fields: array<string, string>, url: string}> $settings
     *                                                       new slices with settings fields to fill: its new
     *                                                       id and name, field id => field name, the admin page
     */
    public function __construct(
        public array $package,
        public array $modules,
        public array $rows,
        public array $ignored,
        public array $maps,
        public array $findings,
        public FinishReport $finish,
        public array $settings,
    ) {}

    /** @param array<string, string> $rowTables Schema::rowTables() - row element => table */
    public static function of(ImportPlan $plan, IdMap $map, ImportJournal $journal, FinishReport $finish, array $rowTables): self
    {
        $p = $plan->package;
        [$modules, $rows, $objects, $settings] = [[], [], [], []];
        foreach ($p->modules as $m) {
            $new       = (string) $map->module($m->id);
            $name      = ValueCodec::bytes($m->rows['module'][0]['name'] ?? '');
            $modules[] = ['type' => $m->type, 'name' => $name, 'old' => $m->id, 'new' => $new];
            foreach ($m->rows as $element => $list) {
                $table        = $rowTables[$element] ?? $element;
                $rows[$table] = ($rows[$table] ?? 0) + count($list);
            }
            $objects += self::objects($m->objects, $map);
            if (($m->type === Module::SLICE) && ($fields = self::settingsFields($m))) {
                $settings[] = ['module' => $new, 'name' => $name, 'fields' => $fields, 'url' => self::SETTINGS_URL . $new];
            }
        }
        foreach ($p->constantGroups as $g) {
            foreach ($g->rows as $element => $list) {
                $table        = $rowTables[$element] ?? $element;
                $rows[$table] = ($rows[$table] ?? 0) + count($list);
            }
        }
        if ($journal->groups()) {
            $rows['constant'] = ($rows['constant'] ?? 0) + count($journal->groups());     // the lt_groupNames rows
        }
        foreach ($journal->numbers() as $table => $numbers) {
            $rows[$table] = count($numbers);            // exact: a filter shared by Alerts modules is one row
        }
        $items = array_map('count', $journal->items());
        if ($items) {
            $rows['item'] = array_sum($items);
        }
        if ($journal->contentRows()) {
            $rows['content'] = $journal->contentRows();
        }
        ksort($rows);

        return new self(
            ['name' => $p->name, 'version' => $p->version, 'description' => $p->description, 'source' => $p->source],
            $modules,
            $rows,
            $plan->fixUps,
            ['views' => $map->views(), 'emails' => $map->emails(), 'filters' => $map->filters(), 'groups' => $map->groups(),
                'collections' => $map->collections(), 'objects' => $objects, 'items' => $items],
            $plan->allFindings(),
            $finish,
            $settings,
        );
    }

    /** "N settings to fill" - the settings fields of the new slices; the package carries no values (§ 9.2) */
    public function settingsToFill(): int
    {
        return array_sum(array_map(static fn(array $s): int => count($s['fields']), $this->settings));
    }

    /** @return array<string, string> the slice's settings fields (id starts with `_`): id => name */
    private static function settingsFields(Module $m): array
    {
        $fields = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (str_starts_with($id, '_')) {
                $fields[$id] = ValueCodec::bytes($row['name'] ?? '');
            }
        }
        return $fields;
    }

    /** objects and their subobjects: old id => new id
     *  @param list<array<string, mixed>> $objects @return array<string, string> */
    private static function objects(array $objects, IdMap $map): array
    {
        $ids = [];
        foreach ($objects as $o) {
            if (is_string($o['id'] ?? null) && ($new = $map->longId($o['id']))) {
                $ids[$o['id']] = $new;
            }
            foreach ($o['props'] ?? [] as $value) {
                if (is_array($value)) {
                    $ids += self::objects(array_values(array_filter($value, static fn(mixed $v): bool => is_array($v))), $map);
                }
            }
        }
        return $ids;
    }
}
