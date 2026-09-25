<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use RuntimeException;

require_once __DIR__ . '/PackageMutations.php';

/** What CompensationTest and ImportGuardTest share: TableDb with rows the target had before the import
 *  (foreign()), the example planned and written, a failing statement. */
trait CompensationFixture
{
    use PackageMutations;

    private const LOCK = 'aa_central_import.aa_test';
    /** foreign modules on the target: a slice, a site, an Alerts module */
    private const FS = 'f5f5f5f5f5f5f5f5f5f5f5f5f5f5f5f5';
    private const FW = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
    private const FA = 'fafafafafafafafafafafafafafafafa';
    /** the last failure point of ImporterTest::failures() - everything but one constant is written */
    private const LAST = "/^INSERT INTO `constant` .*'Domácí'/s";

    private FakeImportTarget $target;

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
        require_once __DIR__ . '/TableDb.php';
        require_once __DIR__ . '/../../../../../include/perm_sql.php';        // DelPermObject()
    }

    protected function setUp(): void
    {
        TableDb::reset();
        TableDb::$next       = ['view' => 1000, 'email' => 2000, 'alerts_filter' => 3000, 'site_spot' => 4000, 'profile' => 5000, 'object_text' => 100];
        // spare handles: a statement that throws inside DB_AA::sql() never gives its handle back (freeDB()) -
        // AA opens a new connection then; here an empty pool would try a real one
        $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
        $this->target        = new FakeImportTarget();
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
            'object_text'    => [['id' => 1, 'object_id' => str_repeat('b', 32), 'property' => 'aa_owner', 'value' => $s]],
        ];
    }

    private function plan(?Package $package = null): ImportPlan
    {
        $package  ??= self::example();
        $plan       = (new Planner($this->target))->plan($package, (new Validator())->validate($package));
        self::assertTrue($plan->importable());
        return $plan;
    }

    private function importer(): Importer
    {
        return new Importer($this->target, 'importer-uid', null, 1_790_000_000);
    }

    private function failure(ImportPlan $plan, ?Importer $importer = null): ImportFailed
    {
        try {
            ($importer ?? $this->importer())->write($plan);
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

    /** @return list<string> the statements after the import's end (ROLLBACK, or its last write) */
    private static function compensationStatements(): array
    {
        $at = array_search('ROLLBACK', TableDb::$log, true);
        return array_values(array_filter(array_slice(TableDb::$log, ($at === false) ? 0 : $at + 1), static fn(string $q): bool => str_starts_with($q, 'DELETE')));
    }
}
