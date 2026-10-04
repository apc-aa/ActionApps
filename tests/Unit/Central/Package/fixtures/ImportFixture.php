<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\InstallRun;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportReport;
use AA\Central\Package\ImportTarget;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use RuntimeException;

require_once __DIR__ . '/PackageMutations.php';

/** What the writer tests share: TableDb with rows the target had before the import (foreign()), the
 *  example planned and written for an install run, a failing statement. */
trait ImportFixture
{
    use PackageMutations;

    /** foreign modules on the target: a slice, a site, an Alerts module */
    private const FS = 'f5f5f5f5f5f5f5f5f5f5f5f5f5f5f5f5';
    private const FW = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
    private const FA = 'fafafafafafafafafafafafafafafafa';
    /** a foreign set and its condition (owner: the foreign slice) - the set lists the condition in aa_subobjects */
    private const FSET  = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';
    private const FCOND = 'f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2';
    /** a foreign item of the foreign slice */
    private const FITEM = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';

    private const USER = 'importer-uid';
    private const NOW  = 1_790_000_000;

    /** the definitions package as downloaded - the run keeps its fingerprint */
    private const DEFINITIONS = '<aa-app>definitions</aa-app>';

    private FakeImportTarget $target;
    private FakeInstallPermission $authority;
    private FakeAfterCommit $after;
    /** object_text ids of the install runs - far from the import's objects */
    private int $runRows = 900_000;
    /** the id of the last run newRun() made */
    private string $lastRun = '';

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
        require_once __DIR__ . '/TableDb.php';
    }

    protected function setUp(): void
    {
        TableDb::reset();
        // AUTO_INCREMENT starts - far from the source numbers (views 12, 13; e-mails 5, 7; filter 3)
        TableDb::$next       = ['view' => 1000, 'email' => 2000, 'alerts_filter' => 3000, 'site_spot' => 4000, 'profile' => 5000, 'constant' => 6000, 'object_text' => 100, 'object_integer' => 100, 'item' => 100];
        // spare handles: a statement that throws inside DB_AA::sql() never gives its handle back (freeDB()) -
        // AA opens a new connection then; here an empty pool would try a real one
        $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
        $this->target        = new FakeImportTarget();
        $this->authority     = new FakeInstallPermission();
        $this->after         = new FakeAfterCommit();
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        TableDb::reset();
    }

    /** rows the target had before the import - none of them may be touched
     *  @return array<string, list<array<string, mixed>>> */
    private static function foreign(): array
    {
        [$s, $w, $a] = [self::FS, self::FW, self::FA];
        $group       = 'CT_rubriky ';       // lt_groupNames.value: equal to `ct_rubriky` in its collation
        return TableDb::$tables = [
            'module'   => [['id' => $s, 'type' => 'S', 'name' => 'Foreign slice'], ['id' => $w, 'type' => 'W', 'name' => 'Foreign site'], ['id' => $a, 'type' => 'Alerts', 'name' => 'Foreign alerts']],
            'slice'    => [['id' => $s, 'name' => 'Foreign slice']],
            'field'    => [['id' => 'headline........', 'slice_id' => $s]],
            'view'     => [['id' => 1, 'slice_id' => $s, 'name' => 'Foreign view']],
            'email'    => [['id' => 1, 'owner_module_id' => $s], ['id' => 2, 'owner_module_id' => $a]],
            'profile'  => [['id' => 1, 'slice_id' => $s, 'uid' => '*', 'property' => 'input_view', 'value' => '1']],
            'site'     => [['id' => $w, 'state_file' => '/foreign']],
            'site_spot' => [['id' => 1, 'site_id' => $w, 'spot_id' => 1]],
            'relation' => [['source_id' => $w, 'destination_id' => $s, 'flag' => 1]],
            'alerts_collection'          => [['id' => 'Zz001', 'module_id' => $a, 'slice_id' => $s]],
            'alerts_collection_filter'   => [['collectionid' => 'Zz001', 'filterid' => 1, 'myindex' => 0]],
            'alerts_collection_howoften' => [['collectionid' => 'Zz001', 'howoften' => 'daily']],
            'alerts_filter'  => [['id' => 1, 'vid' => 1]],
            'constant'       => [
                ['id' => str_repeat('e', 32), 'group_id' => 'lt_groupNames', 'name' => $group, 'value' => $group],
                ['id' => str_repeat('d', 32), 'group_id' => $group, 'name' => 'Foreign', 'value' => 'x'],
                ['id' => str_repeat('c', 32), 'group_id' => 'ct_rubrikY', 'name' => 'No group row', 'value' => 'y'],
            ],
            'constant_slice' => [['group_id' => $group, 'slice_id' => $s], ['group_id' => 'CT_RUBRIKY', 'slice_id' => null]],
            'object_text'    => [
                ['id' => 1, 'object_id' => str_repeat('b', 32), 'property' => 'aa_owner', 'value' => $s],
                ['id' => 2, 'object_id' => self::FSET, 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set'],
                ['id' => 3, 'object_id' => self::FSET, 'property' => 'aa_owner', 'priority' => 0, 'value' => $s],
                ['id' => 4, 'object_id' => self::FSET, 'property' => 'conds', 'priority' => 0, 'value' => self::FCOND],
                ['id' => 5, 'object_id' => self::FSET, 'property' => 'aa_subobjects', 'priority' => 0, 'value' => self::FCOND],
                ['id' => 6, 'object_id' => self::FCOND, 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Condition'],
                ['id' => 7, 'object_id' => self::FCOND, 'property' => 'aa_owner', 'priority' => 0, 'value' => $s],
            ],
            'object_integer' => [['id' => 1, 'object_id' => self::FSET, 'property' => 'x', 'priority' => 0, 'value' => 1]],
            'item'           => [['id' => self::FITEM, 'short_id' => 1, 'slice_id' => $s, 'status_code' => 1]],
            'content'        => [['item_id' => self::FITEM, 'field_id' => 'headline........', 'number' => 0, 'text' => 'Foreign item', 'flag' => 64]],
        ];
    }

    private function plan(?Package $package = null, bool $transfer = false): ImportPlan
    {
        $package  ??= self::example();
        $plan       = (new Planner($this->target))->plan($package, (new Validator())->validate($package, $transfer), $transfer);
        self::assertTrue($plan->importable(), (string) json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", $plan->ofLevel(LintFinding::ERROR))));
        return $plan;
    }

    private function importer(?ImportTarget $target = null, ?ImportConfirmation $confirmation = null): Importer
    {
        return new Importer($target ?? $this->target, self::USER, null, self::NOW, mayInstall: $this->authority->mayInstall(...), confirmation: $confirmation, afterCommit: $this->after);
    }

    /** A new run in preview, as the preview page leaves it; its id. The statement log starts after it.
     *  @param array<string, string> $filters source slice => ItemFilter text the preview chose */
    private function newRun(string $mode = InstallRun::INSTALL, string $source = '7', array $filters = []): string
    {
        [$next, TableDb::$next['object_text']] = [TableDb::$next['object_text'] ?? 1, $this->runRows];
        $id                                    = InstallRun::preview($mode, false, $source, self::USER, '', self::NOW, '', [], $filters ? new ImportChoices(filters: $filters) : null)->getId();
        [$this->runRows, TableDb::$next['object_text']] = [TableDb::$next['object_text'], $next];
        [TableDb::$log, TableDb::$sent, TableDb::$handles] = [[], [], []];
        return $this->lastRun = $id;
    }

    /** the plan written for $run - a new install run when none is given */
    private function import(ImportPlan $plan, ?Importer $importer = null, ?string $confirmation = null, ?string $run = null): ImportReport
    {
        return ($importer ?? $this->importer())->write($plan, $run ?? $this->newRun(), self::DEFINITIONS, $confirmation);
    }

    /** the tables without the rows of install runs and without empty tables
     *  @return array<string, list<array<string, mixed>>> */
    private static function tables(): array
    {
        $runs   = array_column(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && ($r['value'] === InstallRun::class)), 'object_id');
        $tables = TableDb::$tables;
        if (isset($tables['object_text'])) {
            $tables['object_text'] = array_values(array_filter($tables['object_text'], static fn(array $r): bool => !in_array($r['object_id'], $runs, true)));
        }
        return array_filter($tables);
    }

    private function failure(ImportPlan $plan, ?Importer $importer = null): ImportFailed
    {
        try {
            $this->import($plan, $importer);
        } catch (ImportFailed $e) {
            return $e;
        }
        self::fail('the write did not fail');
    }

    /** the failing statement: false (nohalt) or a thrown DB exception */
    private static function failAt(string $statement, string $mode = 'false'): void
    {
        TableDb::$fail = static fn(string $sql): bool => preg_match($statement, $sql) && (($mode === 'false') ?: throw new RuntimeException('connection lost'));
    }
}
