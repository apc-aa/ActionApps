<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\IO\DB\DB_AA;
use AA_Module_Alerts;
use AA_Module_Site;
use AA_Object;
use AA_Slice;
use Closure;
use LogicException;
use Throwable;

/** The cleanup after a failed import (design balicek-v1.md § 7.2 step 6; C4.4 part 4b): removes what the
 *  journal says the import created - always after a failure, also after a successful rollback (MyISAM
 *  tables have none, § 13.5; later parts write outside the transaction). Idempotent within the request: a
 *  second run finds nothing and deletes nothing. Not beyond it: InnoDB before MySQL 8.0 (and older MariaDB)
 *  sets AUTO_INCREMENT to max(id) + 1 after a server restart, so the numbers of rolled back view / email /
 *  ... rows may be handed out again - a journal kept for a later request would then delete foreign rows.
 *
 *  Only by what surely is ours - never by a name or by the `module` table:
 *  1. per new module, by its type IN THE PACKAGE (the journal's; AA_Module::getModuleInfo() caches the
 *     module table per request and takes an unknown id for a slice - a new site would go to the slice
 *     routine): AA_Slice / AA_Module_Site / AA_Module_Alerts::_deleteModules() for that one id, its
 *     objects (AA_Object::getOwnersObjects()), DelPermObject() (a no-op with perm_sql.php:242 - the
 *     rights come after the commit, part 4d; LDAP deletes the ACL object), its `module` row. Not
 *     AA_Module::deleteModules(): it stops at the first error and misses e-mails of Alerts, alerts_filter;
 *  2. AUTO_INCREMENT rows by the numbers of the journal: view, email (Alerts' too), alerts_filter,
 *     site_spot, profile - view and email rows are written before their module row;
 *  3. relation rows of the new sites (source, destination, flag "uses");
 *  4. an alerts collection by its id AND its new Alerts module (a rolled back 5-character id may be taken
 *     by the Alerts admin since); its filters and periods by the collection id - unless a collection of
 *     another module holds the id now: then our filters by the id AND our alerts_filter numbers, and the
 *     other filter rows (filterid 0 included) and the periods (nothing but the id) are reported, not deleted;
 *  5. a constant group by ids: its constants by their NEW ids (the plan's map) and the new group name
 *     (constant.group_id is binary), its lt_groupNames row by the id the journal holds, constant_slice by
 *     our new owner slice - or, for a group without owner (slice_id NULL), by the exact (binary) name and
 *     slice_id IS NULL only when the journal holds our insert of it: the insert fails when a foreign row of
 *     the name came first (MyISAM, no lock of the group admin), and that row stays. Never by the name
 *     otherwise: lt_groupNames.value compares case-insensitively with PAD SPACE, and after a rollback a
 *     foreign group of the name may exist.
 *  Each step runs every action on its own (an exception or a failed statement stops none of the others)
 *  and then counts what is left of it: the report row lists it with its WHERE for a manual action. A
 *  step that cannot even be built (a group not in the map - a bug) is a report row too; run() never throws.
 *
 *  DB_PDO die()s on a failed statement unless its halt mode is 'no', and the legacy routines use halting
 *  statements: the handles of the pool get 'no' for the run (the routines take one getDB() at a time and
 *  give it back - the pool is LIFO, they get these). A routine that nests a second handle while the pool is
 *  empty would get a fresh, halting one. The compensation's own statements are always query_halt('no').
 *
 *  For part 4c (objects, items): they must get fresh ids (the map's new_id()s - an id of the target would
 *  be deleted here with its owner's objects), the package must not set `aa_subobjects` (deleteObjects()
 *  deletes every id that property lists - a package naming a foreign object would delete it), and the item
 *  row goes before its content (AA_Items::deleteItems() of the slice routine finds content by the item). */
final class Compensation
{
    /** the module class of a package wrapper - its _deleteModules() */
    private const ROUTINES = [Module::SLICE => AA_Slice::class, Module::SITE => AA_Module_Site::class, Module::ALERTS => AA_Module_Alerts::class];

    /** what each routine removes - checked after it: table => the module column */
    private const LEFT = [
        Module::SLICE  => ['slice' => 'id', 'field' => 'slice_id', 'view' => 'slice_id', 'email' => 'owner_module_id', 'profile' => 'slice_id',
            'constant_slice' => 'slice_id', 'alerts_collection' => 'slice_id'],
        Module::SITE   => ['site' => 'id', 'site_spot' => 'site_id'],
        Module::ALERTS => ['alerts_collection' => 'module_id'],
    ];

    private CompensationReport $report;

    /** @param ImportPlan $plan the plan written - its package and map (the new constant ids, group owners) */
    public function __construct(private readonly ImportPlan $plan) {}

    public function run(ImportJournal $journal): CompensationReport
    {
        $this->report = new CompensationReport();
        if ($journal->isEmpty()) {
            return $this->report;
        }
        $restore = self::quiet();
        try {
            foreach ($journal->modules() as $id => $type) {
                $this->module($id, $type);
            }
            foreach ($journal->numbers() as $table => $ids) {
                $this->remove("$table " . implode(', ', $ids), [[$table, [['id', $ids, 'i']]]]);
            }
            foreach ($journal->relations() as [$source, $destination]) {
                $where = [['source_id', $source, 'l'], ['destination_id', $destination, 'l'], ['flag', RowWriter::USES_FLAG, 'i']];
                $this->remove("relation $source $destination", [['relation', $where]]);
            }
            foreach ($journal->collectionModules() as $id => $module) {
                $filters = $journal->numbers()['alerts_filter'] ?? [];
                $this->guarded("alerts_collection $id", fn() => $this->collection((string) $id, $module, $filters));
            }
            $rows = $journal->groupRows();
            foreach ($this->plan->package->constantGroups as $g) {
                $name = $this->plan->map->group($g->name);
                if (($name !== null) && isset($rows[$name])) {
                    $slice = in_array($name, $journal->groupSlices(), true);
                    $this->guarded("constant-group[$name]", fn() => $this->group($g, $name, $rows[$name], $slice));
                }
            }
        } finally {
            $restore();
        }
        return $this->report;
    }

    private function module(string $id, string $type): void
    {
        $class = self::ROUTINES[$type] ?? null;
        $left  = [];
        foreach (self::LEFT[$type] ?? [] as $table => $column) {
            $left[] = [$table, [[$column, $id, 'l']]];
        }
        $this->step($type . "[$id]", [
            static fn(): bool => ($class ?? throw new LogicException("Unknown module type $type"))::_deleteModules([$id]),
            static fn(): bool => AA_Object::deleteObjects(AA_Object::getOwnersObjects($id)),
            static fn(): bool => function_exists('DelPermObject')
                ? \DelPermObject($id, 'slice')
                : throw new LogicException('No permission library loaded - DelPermObject() did not run'),
            static fn(): bool => self::delete('module', [['id', $id, 'l']]),
        ], [...$left, ['object_text', [['property', 'aa_owner'], ['value', $id]]], ['module', [['id', $id, 'l']]]]);
    }

    /** @param list<int> $filters our alerts_filter numbers */
    private function collection(string $id, string $module, array $filters): void
    {
        $collection = ['alerts_collection', [['id', $id], ['module_id', $module, 'l']]];
        $filtered   = ['alerts_collection_filter', [['collectionid', $id]]];
        $periods    = ['alerts_collection_howoften', [['collectionid', $id]]];
        if (self::count('alerts_collection', [['id', $id], ['module_id', $module, 'l<>']]) === 0) {
            $this->remove("alerts_collection $id", [$filtered, $periods, $collection]);    // the id is ours: all its rows
            return;
        }
        // another module holds the id now: our filters by their numbers; the rest may be its - reported
        $this->remove("alerts_collection $id", [['alerts_collection_filter', [['collectionid', $id], ['filterid', $filters, 'i']]], $collection]);
        $others = ['alerts_collection_filter', [['collectionid', $id], ['filterid', array_map('strval', $filters), 's<>']]];
        $this->step("alerts_collection $id: the id is another module's now", [], [$periods, $others]);
    }

    /** @param bool $slice the journal holds our constant_slice row of an unowned group */
    private function group(ConstantGroup $g, string $name, string $row, bool $slice): void
    {
        $ids = [];
        foreach ($g->rows['constant'] ?? [] as $constant) {
            $ids[] = $this->plan->map->longId(ValueCodec::bytes($constant['id'] ?? ''))
                ?? throw new LogicException("Constant of $name is not mapped");
        }
        $parts = [['constant', [['id', $ids, 'l'], ['group_id', $name]]]];
        if ($g->owner !== '') {
            $owner   = $this->plan->map->module($g->owner) ?? throw new LogicException("The owner of $name is not mapped");
            $parts[] = ['constant_slice', [['slice_id', $owner, 'l'], ['group_id', $name]]];
        } elseif ($slice) {
            $parts[] = ['constant_slice', [['group_id', $name], ['slice_id', null, 'ISNULL']]];
        }
        $parts[] = ['constant', [['id', $row, 'l'], ['group_id', 'lt_groupNames']]];
        $this->remove("constant-group[$name]", $parts);
    }

    /** $build runs a step; what it throws before the step (a bug) is a report row of $name */
    private function guarded(string $name, Closure $build): void
    {
        try {
            $build();
        } catch (Throwable $e) {
            $this->report->add($name, [get_class($e) . ': ' . $e->getMessage()]);
        }
    }

    /** a step that deletes $parts and then checks them
     *  @param list<array{string, list<array<mixed>>}> $parts [table, makeWhere() conditions] */
    private function remove(string $name, array $parts): void
    {
        $this->step($name, array_map(static fn(array $p): Closure => static fn(): bool => self::delete(...$p), $parts), $parts);
    }

    /** runs each action on its own (false or an exception = an error, the next one still runs), then counts
     *  the rows of $left - one report row
     *  @param list<Closure(): bool> $actions @param list<array{string, list<array<mixed>>}> $left */
    private function step(string $name, array $actions, array $left): void
    {
        $errors = [];
        foreach ($actions as $action) {
            try {
                if ($action() === false) {
                    $errors[] = 'a statement failed';
                }
            } catch (Throwable $e) {
                $errors[] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        foreach ($left as [$table, $where]) {
            try {
                $count = self::count($table, $where);
            } catch (Throwable $e) {
                [$count, $errors[]] = [0, get_class($e) . ': ' . $e->getMessage()];
            }
            if ($count !== 0) {
                $errors[] = "$table: " . (($count === null) ? 'cannot count the rows' : "$count rows left") . ': ' . DB_AA::makeWhere($where);
            }
        }
        $this->report->add($name, array_values(array_unique($errors)));
    }

    /** @param list<array<mixed>> $where makeWhere() conditions - never none (that would be every row) */
    private static function delete(string $table, array $where): bool
    {
        $sql = 'DELETE FROM ' . DB_AA::quoteIdentifier($table) . ' ' . self::where($where);
        $db  = getDB();
        try {
            return $db->query_halt($sql, 'no');
        } finally {
            freeDB($db);
        }
    }

    /** the rows matching $where; null = the statement failed
     *  @param list<array<mixed>> $where */
    private static function count(string $table, array $where): ?int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . DB_AA::quoteIdentifier($table) . ' ' . self::where($where);
        $db  = getDB();
        try {
            return ($db->query_halt($sql, 'no') && $db->next_record()) ? (int) $db->record('cnt') : null;
        } finally {
            freeDB($db);
        }
    }

    /** @param list<array<mixed>> $where */
    private static function where(array $where): string
    {
        $sql = DB_AA::makeWhere($where);
        return ($sql === '') ? throw new LogicException('A compensation statement without a condition') : $sql;
    }

    /** the handles of the pool to halt mode 'no' (see the class) @return Closure(): void back to their modes */
    private static function quiet(): Closure
    {
        if (!($GLOBALS['spareDBs'] ?? [])) {
            $db = getDB();
            freeDB($db);
        }
        $modes = [];
        foreach ($GLOBALS['spareDBs'] ?? [] as $db) {
            $modes[] = [$db, $db->get_halt()];
            $db->set_halt('no');
        }
        return static function () use ($modes): void {
            foreach ($modes as [$db, $mode]) {
                $db->set_halt($mode);
            }
        };
    }
}
