<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** The report of a written import - plain data, no HTML.
 *  EVERY string in it is package text or derived from it (names, paths, refs, fix-ups, errors quoting
 *  them): the UI escapes all of it (safe()).
 *
 *  A failed import is no report: ImportFailed (the cause; nothing was written). Here all rows were
 *  written - $rows counts them by table; what the import changed or dropped on the way is $ignored
 *  (the FixUps); what AA did after the commit is $finish, each step OK or its errors (failures(): what is
 *  left to do by hand - the import itself stays). The Reader's pruning findings are not in the plan: the
 *  caller adds them from ReadResult::$findings. */
final readonly class ImportReport
{
    /** the admin page of the settings of a slice (the `_` fields) - relative to AA_INSTAL_PATH; change_id
     *  switches the admin to that slice (include/init_page.php) */
    public const SETTINGS_URL = 'admin/slicefieldsedit.php?edit=1&change_id=';

    /**
     * @param array{name: string, version: ?string, description: ?string, source: array<string, string>} $package
     * @param list<array{type: string, name: string, old: string, new: string, existing: bool}> $modules the package's modules,
     *                                                       old => new id; existing: a slice the package went into
     * @param array<string, int>                   $rows     table => rows written (RowStore::written()), by table name
     * @param list<FixUp>                          $ignored  what the import changed or dropped
     * @param array{views: array<int, int>, emails: array<int, int>, filters: array<int, int>, groups: array<string, string>,
     *              collections: array<string, string>, objects: array<string, string>} $maps
     *                                                       old => new
     * @param list<array{string, LintFinding}>     $findings validator + planner, by danger S, E, W, I
     * @param list<array{string, list<string>}>    $finish   the steps after the commit (Importer): [step, errors], none = OK
     * @param list<array{module: string, name: string, fields: array<string, string>, url: string}> $settings
     *                                                       slices with settings fields left empty (ImportPlan::settingsToFill()):
     *                                                       its new id and name, field id => field name, the admin page
     */
    public function __construct(
        public array $package,
        public array $modules,
        public array $rows,
        public array $ignored,
        public array $maps,
        public array $findings,
        public array $finish,
        public array $settings,
    ) {}

    /** @param array<string, int> $written RowStore::written() - table => rows inserted @param list<array{string, list<string>}> $finish */
    public static function of(ImportPlan $plan, IdMap $map, array $written, array $finish): self
    {
        $p = $plan->package;
        [$modules, $objects, $settings] = [[], [], []];
        foreach ($p->modules as $m) {
            $new       = (string) $map->module($m->id);
            $name      = $plan->moduleName($m);
            $modules[] = ['type' => $m->type, 'name' => $name, 'old' => $m->id, 'new' => $new, 'existing' => isset($plan->existing[$m->id])];
            $objects += self::objects($m->objects, $map);
            if ($fields = $plan->settingsToFill($m)) {
                $settings[] = ['module' => $new, 'name' => $name, 'fields' => $fields, 'url' => self::SETTINGS_URL . $new];
            }
        }
        $rows = $written;
        ksort($rows);

        return new self(
            ['name' => $p->name, 'version' => $p->version, 'description' => $p->description, 'source' => $p->source],
            $modules,
            $rows,
            $plan->fixUps,
            ['views' => $map->views(), 'emails' => $map->emails(), 'filters' => $map->filters(), 'groups' => $map->groups(),
                'collections' => $map->collections(), 'objects' => $objects],
            $plan->allFindings(),
            $finish,
            $settings,
        );
    }

    /** @return list<array{string, list<string>}> the steps after the commit with errors - what is left to do by hand */
    public function failures(): array
    {
        return array_values(array_filter($this->finish, static fn(array $s): bool => (bool) $s[1]));
    }

    /** "N settings to fill in" - the settings fields the import left empty */
    public function settingsToFill(): int
    {
        return array_sum(array_map(static fn(array $s): int => count($s['fields']), $this->settings));
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
