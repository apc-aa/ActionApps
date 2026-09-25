<?php

declare(strict_types=1);

/**
 * Importer + RowWriter, C4.4 part 4a (design balicek-v1.md § 7.2 step 5, § 6): the example package read,
 * validated, planned and written into TableDb - in-memory tables behind the real DB_AA::transaction(),
 * getDB() and AA_Metabase SQL. New ids, rewritten references, the journal; a failure at every statement
 * (rollback, journal), a name taken since the plan, an unconvertible value, a length overflow after the
 * view numbers.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\DbImportTarget;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportJournal;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportTarget;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use AA\Central\Package\WriteError;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class ImporterTest extends TestCase
{
    use PackageMutations;

    private const USER = 'importer-uid';
    private const NOW  = 1_790_000_000;
    /** AUTO_INCREMENT starts - far from the source numbers (views 12, 13; e-mails 5, 7; filter 3) */
    private const NEXT = ['view' => 1000, 'email' => 2000, 'alerts_filter' => 3000, 'site_spot' => 4000, 'profile' => 5000, 'constant' => 6000];

    private FakeImportTarget $target;

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
        require_once __DIR__ . '/fixtures/TableDb.php';
    }

    protected function setUp(): void
    {
        TableDb::reset();
        TableDb::$next       = self::NEXT;
        $GLOBALS['spareDBs'] = [new TableDb(), new TableDb()];      // a second handle is there - and must not be used
        $this->target        = new FakeImportTarget();
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        TableDb::reset();
    }

    private function plan(?Package $package = null): ImportPlan
    {
        $package  ??= self::example();
        $validation = (new Validator())->validate($package);
        self::assertTrue($validation->importable());
        $plan = (new Planner($this->target))->plan($package, $validation);
        self::assertTrue($plan->importable(), json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", $plan->ofLevel(LintFinding::ERROR))));
        return $plan;
    }

    private function importer(?ImportTarget $target = null): Importer
    {
        return new Importer($target ?? $this->target, self::USER, null, self::NOW);
    }

    /** DbImportTarget over TableDb for the questions, FakeImportTarget for the new ids (predictable) */
    private function dbTarget(): ImportTarget
    {
        return new class ($this->target) implements ImportTarget {
            public function __construct(private readonly FakeImportTarget $ids) {}

            public function groupTaken(string $name, bool $lock = false): bool
            {
                return (new DbImportTarget())->groupTaken($name, $lock);
            }

            public function collectionTaken(string $id): bool
            {
                return (new DbImportTarget())->collectionTaken($id);
            }

            public function newId(): string
            {
                return $this->ids->newId();
            }

            public function newCollectionId(): string
            {
                return $this->ids->newCollectionId();
            }
        };
    }

    /** a plan changed after the planning - what the Validator / planner would refuse, to reach the writer's own checks */
    private static function forced(ImportPlan $plan, \Closure $package): ImportPlan
    {
        return new ImportPlan(...array_merge(get_object_vars($plan), ['package' => $package($plan->package), 'findings' => []]));
    }

    /** n-th id of FakeImportTarget::newId() - the plan: modules 1-3, objects 4-7, constants 8-9 */
    private static function id(int $n): string
    {
        return sprintf('%032x', 0xa000 + $n);
    }

    /** the rows of a table, only the named columns */
    private static function cols(string $table, string ...$columns): array
    {
        return array_map(static fn(array $row): array => array_combine($columns, array_map(static fn(string $c): mixed => $row[$c] ?? null, $columns)), TableDb::rows($table));
    }

    // ---------------------------------------------------------------- the example

    public function test_the_example_is_written_with_new_ids_and_rewritten_references(): void
    {
        $result = $this->importer()->write($this->plan());
        [$w, $s, $a] = [self::id(1), self::id(2), self::id(3)];

        self::assertSame([12 => 1000, 13 => 1001], $result->map->views());
        self::assertSame([5 => 2000, 7 => 2001], $result->map->emails());
        self::assertSame([3 => 3000], $result->map->filters());

        self::assertSame([
            ['id' => $w, 'name' => 'Central Test - web', 'type' => 'W', 'lang_file' => 'cz-utf8_site_lang.php', 'deleted' => 0, 'created_by' => self::USER, 'created_at' => self::NOW],
            ['id' => $s, 'name' => 'Central Test - články', 'type' => 'S', 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0, 'created_by' => self::USER, 'created_at' => self::NOW],
            ['id' => $a, 'name' => 'Upozornění', 'type' => 'Alerts', 'lang_file' => 'cz-utf8_alerts_lang.php', 'deleted' => 0, 'created_by' => self::USER, 'created_at' => self::NOW],
        ], self::cols('module', 'id', 'name', 'type', 'lang_file', 'deleted', 'created_by', 'created_at'));

        // views and e-mails: new numbers, the module column; the numbers in the texts (UPDATE), long ids at once
        self::assertSame([
            ['id' => 1000, 'slice_id' => $s, 'name' => 'Seznam článků', 'odd' => '<li><a href="{view.php?vid=1001&cmd[1001]=x-1001-_#ITEM_ID_}">_#HEADLINE</a></li>', 'field3' => 'clanky'],
            ['id' => 1001, 'slice_id' => $s, 'name' => 'Detail', 'odd' => '{constant:ct_rubriky:{category.......1}:name}', 'field3' => ''],
        ], self::cols('view', 'id', 'slice_id', 'name', 'odd', 'field3'));
        self::assertSame([
            ['id' => 2000, 'owner_module_id' => $s, 'body' => "{modulefield:$s:_email..........}"],
            ['id' => 2001, 'owner_module_id' => $a, 'body' => ''],
        ], self::cols('email', 'id', 'owner_module_id', 'body'));
        self::assertSame(1, count(array_filter(TableDb::$log, static fn(string $q): bool => str_starts_with($q, 'UPDATE'))), 'only view 12 names a view');

        self::assertSame([['id' => $s, 'name' => 'Central Test - články', 'vid' => 1000, 'deleted' => 0, 'created_by' => self::USER]], self::cols('slice', 'id', 'name', 'vid', 'deleted', 'created_by'));
        self::assertSame([
            ['id' => 'headline........', 'slice_id' => $s, 'input_show_func' => 'fld:'],
            ['id' => 'category.......1', 'slice_id' => $s, 'input_show_func' => 'sel:ct_rubriky:'],
            ['id' => '_email..........', 'slice_id' => $s, 'input_show_func' => null],
        ], self::cols('field', 'id', 'slice_id', 'input_show_func'));
        self::assertSame([['id' => 5000, 'slice_id' => $s, 'uid' => '*', 'property' => 'input_view', 'value' => '1001']], self::cols('profile', 'id', 'slice_id', 'uid', 'property', 'value'));

        // the site: its tree, spots, uses
        $site = TableDb::rows('site');
        self::assertSame([['id' => $w, 'state_file' => '/cz/home', 'flag' => 1]], self::cols('site', 'id', 'state_file', 'flag'));
        $tree = unserialize((string) $site[0]['structure'], ['allowed_classes' => [\sitetree::class, \spot::class]]);
        self::assertInstanceOf(\sitetree::class, $tree);
        self::assertSame(self::example()->modules[0]->spotTree, $tree->toArray());
        $content = str_replace(['{view:12:', 'vid=12&cmd[12]=x-12-'], ['{view:1000:', 'vid=1000&cmd[1000]=x-1000-'], (string) self::example()->modules[0]->rows['site_spot'][0]['content']);
        self::assertSame([
            ['id' => 4000, 'site_id' => $w, 'spot_id' => 1, 'content' => $content],
            ['id' => 4001, 'site_id' => $w, 'spot_id' => 2, 'content' => '{item:{xid}:_#HEADLINE}'],
        ], self::cols('site_spot', 'id', 'site_id', 'spot_id', 'content'));
        self::assertSame([['source_id' => $w, 'destination_id' => $s, 'flag' => 1]], TableDb::rows('relation'));

        // alerts: collection, its filter and period, the filter row
        self::assertSame([['id' => 'K0001', 'module_id' => $a, 'emailid_welcome' => 2001, 'slice_id' => $s]], self::cols('alerts_collection', 'id', 'module_id', 'emailid_welcome', 'slice_id'));
        self::assertSame([['collectionid' => 'K0001', 'filterid' => 3000, 'myindex' => 0]], TableDb::rows('alerts_collection_filter'));
        self::assertSame([['collectionid' => 'K0001', 'howoften' => 'daily', 'last' => self::NOW]], TableDb::rows('alerts_collection_howoften'));
        self::assertSame([['id' => 3000, 'vid' => 1000, 'description' => 'Všechny články']], self::cols('alerts_filter', 'id', 'vid', 'description'));

        // the group: its name, owner, constants with new ids and ancestors
        self::assertSame([
            ['id' => self::id(10), 'group_id' => 'lt_groupNames', 'name' => 'ct_rubriky', 'value' => 'ct_rubriky', 'ancestors' => null],
            ['id' => self::id(8), 'group_id' => 'ct_rubriky', 'name' => 'Zprávy', 'value' => 'zpravy', 'ancestors' => null],
            ['id' => self::id(9), 'group_id' => 'ct_rubriky', 'name' => 'Domácí', 'value' => 'domaci', 'ancestors' => self::id(8)],
        ], array_map(static fn(array $r): array => $r + ['ancestors' => null], self::cols('constant', 'id', 'group_id', 'name', 'value', 'ancestors')));
        self::assertSame([['group_id' => 'ct_rubriky', 'slice_id' => $s, 'hierarch' => 1]], self::cols('constant_slice', 'group_id', 'slice_id', 'hierarch'));

        // one transaction on one handle
        self::assertSame('BEGIN', TableDb::$log[0]);
        self::assertSame('COMMIT', end(TableDb::$log));
        self::assertNotContains('ROLLBACK', TableDb::$log);
        self::assertCount(1, array_unique(TableDb::$handles), 'every statement on the transaction handle');

        self::assertSame([$w => Module::SITE, $s => Module::SLICE, $a => Module::ALERTS], $result->journal->modules());
        self::assertSame(['view' => [1000, 1001], 'email' => [2000, 2001], 'alerts_filter' => [3000], 'site_spot' => [4000, 4001], 'profile' => [5000]], $result->journal->numbers());
        self::assertSame(['ct_rubriky'], $result->journal->groups());
        self::assertSame(['ct_rubriky' => self::id(10)], $result->journal->groupRows(), 'its lt_groupNames row by id - a name matches case-insensitively');
        self::assertSame(['K0001'], $result->journal->collections());
        self::assertSame([[$w, $s]], $result->journal->relations());
    }

    public function test_every_column_of_the_local_schema_is_written(): void
    {
        $this->importer()->write($this->plan());

        $tables = ['module', 'slice', 'field', 'view', 'email', 'profile', 'site', 'site_spot', 'relation', 'alerts_collection',
            'alerts_collection_filter', 'alerts_collection_howoften', 'alerts_filter', 'constant_slice', 'constant'];
        foreach ($tables as $table) {
            $columns = \AA_Metabase::singleton()->getColumns($table);
            self::assertNotEmpty(TableDb::$sent[$table] ?? [], $table);
            foreach (TableDb::$sent[$table] as $row) {
                foreach (array_diff_key($columns, $row) as $name => $column) {
                    // left to the DB: AUTO_INCREMENT, and a nullable column whose local default is NULL (= what the DB puts)
                    self::assertTrue($column->isAutoIncrement() || ($column->c[2] && ($column->c[4] === null)), "$table.$name is not written");
                }
            }
        }
        self::assertSame(['subject' => '', 'header_from' => '', 'lang' => '', 'html' => 1], array_intersect_key(TableDb::$sent['email'][1], array_flip(['subject', 'header_from', 'lang', 'html'])), 'NOT NULL: the default, else empty');
    }

    public function test_an_explicit_null_is_the_empty_value_not_the_db_default(): void
    {
        // field.text_stored: NULL = a number field, the DB default 1 = text - skipping the NULL would flip it
        $package = self::cell(self::example(), self::SLICE, 'field', 0, ['text_stored' => null]);
        $package = self::cell($package, self::SLICE, 'slice', 0, ['permit_anonymous_post' => null]);

        $this->importer()->write($this->plan($package));

        self::assertSame([0, 1, 1], array_column(TableDb::rows('field'), 'text_stored'), 'the others get the local default');
        self::assertSame(0, TableDb::rows('slice')[0]['permit_anonymous_post']);
    }

    public function test_structured_references_outside_the_package(): void
    {
        $package = self::cell(self::example(), self::SLICE, 'slice', 0, ['vid' => '99', 'mlxctrl' => 'ffffffffffffffffffffffffffffffff']);
        $package = self::cell($package, self::SLICE, 'profile', 0, ['value' => '98']);

        $this->importer()->write($this->plan($package));

        self::assertSame([99, ''], [TableDb::rows('slice')[0]['vid'], TableDb::rows('slice')[0]['mlxctrl']], 'a view number stays (W); a module outside: "" (§ 6.2)');
        self::assertSame('98', TableDb::rows('profile')[0]['value']);
    }

    public function test_mlxctrl_of_the_package_is_mapped(): void
    {
        $this->importer()->write($this->plan(self::cell(self::example(), self::SLICE, 'slice', 0, ['mlxctrl' => self::SITE])));

        self::assertSame(self::id(1), TableDb::rows('slice')[0]['mlxctrl']);
    }

    /** @return array<string, array{\Closure(Package): Package, string}> */
    public static function foreignAlerts(): array
    {
        return [
            'filter view'   => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_filter', 0, ['vid' => '99']), 'View 99'],
            'welcome'       => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_welcome' => '8']), 'E-mail 8'],
            'alert e-mail'  => [static fn(Package $p): Package => self::cell($p, self::ALERTS, 'alerts_collection', 0, ['emailid_alert' => '9']), 'E-mail 9'],
        ];
    }

    /** the Validator's E - the writer never falls back to a number of the target */
    #[DataProvider('foreignAlerts')]
    public function test_an_alerts_reference_outside_the_package_is_never_written(\Closure $mutate, string $message): void
    {
        try {
            $this->importer()->write(self::forced($this->plan(), $mutate));
            self::fail('written');
        } catch (ImportFailed $e) {
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], array_filter(TableDb::$tables));
    }

    public function test_a_unique_index_of_the_target_holds(): void
    {
        $plan = self::forced($this->plan(), static fn(Package $p): Package => self::cell($p, self::SITE, 'site_spot', 1, ['spot_id' => '1']));

        $e = $this->failure($plan);

        self::assertSame([WriteError::DB, 'site-module[' . self::SITE . ']/site_spot[1]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
    }

    public function test_a_module_of_another_charset_gets_its_texts_converted_last(): void
    {
        $package = self::lang(self::lang(self::example(), self::SLICE, 'cz_news_lang.php'), self::ALERTS, 'cz_alerts_lang.php');
        $package = self::cell($package, self::SLICE, 'view', 1, ['before' => new RawBytes("\xE8{view:12}")]);
        $plan    = $this->plan($package);
        self::assertSame('windows-1250', $plan->charsets[self::SLICE]);

        $this->importer()->write($plan);

        $cp = static fn(string $utf): string => (string) iconv('UTF-8', 'windows-1250', $utf);
        self::assertSame($cp('Central Test - články'), TableDb::rows('slice')[0]['name']);
        self::assertSame(['cz_news_lang.php', $cp('Central Test - články')], [TableDb::rows('module')[1]['lang_file'], TableDb::rows('module')[1]['name']]);
        self::assertSame($cp('Seznam článků'), TableDb::rows('view')[0]['name']);
        self::assertSame("\xE8{view:1000}", TableDb::rows('view')[1]['before'], 'RawBytes: rewritten, not converted');
        self::assertSame($cp('Všechny články'), TableDb::rows('alerts_filter')[0]['description']);
        self::assertSame($cp('Zprávy'), TableDb::rows('constant')[1]['name'], 'the group has the charset of its owner');
        self::assertSame('Central Test - web', TableDb::rows('module')[0]['name']);
    }

    public function test_the_fix_ups_of_the_plan_are_what_is_written(): void
    {
        $package = self::module(self::example(), self::SITE, static fn(Module $m): Module => self::with($m, ['spotTree' => null]));
        $package = self::cell($package, self::SLICE, 'email', 0, ['attachments' => '/etc/aa/config.php']);

        $this->importer()->write($this->plan($package));

        self::assertSame('', TableDb::rows('site')[0]['structure'], 'no spot tree: structure = ""');
        self::assertSame('', TableDb::rows('email')[0]['attachments'], 'a local file is never attached');
    }

    public function test_spot_names_variables_and_conditions_are_rewritten_and_converted(): void
    {
        $package = self::lang(self::example(), self::SITE, 'cz_site_lang.php');
        $package = self::tree($package, static function (array $tree): array {
            $tree['spots'][2]['c'] = ['x' => '{ifin:' . self::SLICE . ':1:{view:13}}'];
            $tree['spots'][0]['n'] = 'začátek';
            return $tree;
        });

        $this->importer()->write($this->plan($package));

        $tree = unserialize((string) TableDb::rows('site')[0]['structure'], ['allowed_classes' => [\sitetree::class, \spot::class]])->toArray();
        self::assertSame(['x' => '{ifin:' . self::id(2) . ':1:{view:1001}}'], $tree['spots'][2]['c']);
        self::assertSame(iconv('UTF-8', 'windows-1250', 'začátek'), $tree['spots'][0]['n']);
    }

    public function test_alerts_field_ids_follow_the_collection_map_and_others_stay(): void
    {
        $package = self::rows(self::example(), self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'alerts1....AbC12', 'type' => 'alerts', 'name' => 'How often'],
            ['id' => 'alerts2....Zz999', 'type' => 'alerts', 'name' => 'Filters of a collection not in the package'],
        ]);
        $package = self::cell($package, self::SLICE, 'view', 1, ['odd' => '{alerts1....AbC12}{alerts2....Zz999}']);

        $this->importer()->write($this->plan($package));

        self::assertSame(['headline........', 'category.......1', '_email..........', 'alerts1....K0001', 'alerts2....Zz999'], array_column(TableDb::rows('field'), 'id'));
        self::assertSame('{alerts1....K0001}{alerts2....Zz999}', TableDb::rows('view')[1]['odd']);
    }

    public function test_a_filter_shared_by_two_alerts_modules_is_written_once(): void
    {
        $this->importer()->write($this->plan(self::alerts2(self::example(), '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b', 'XyZ98')));

        self::assertSame([['id' => 3000, 'vid' => 1000]], self::cols('alerts_filter', 'id', 'vid'));
        self::assertSame([['collectionid' => 'K0001', 'filterid' => 3000], ['collectionid' => 'K0002', 'filterid' => 3000]], self::cols('alerts_collection_filter', 'collectionid', 'filterid'));
        self::assertSame(['K0001', 'K0002'], array_column(TableDb::rows('alerts_collection'), 'id'));
    }

    public function test_an_unimportable_plan_is_refused_before_any_statement(): void
    {
        $plan = $this->plan();
        $bad  = new ImportPlan(...array_merge(get_object_vars($plan), ['findings' => [['x', new LintFinding(LintFinding::ERROR, 'charset', 'x', 0)]]]));

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->importer()->write($bad);
        } finally {
            self::assertSame([], TableDb::$log);
        }
    }

    // ---------------------------------------------------------------- failures

    /** @return array<string, array{string, array{modules: array<string, string>, numbers: array<string, list<int>>, groups: list<string>, collections: list<string>, relations: list<array{string, string}>}}> */
    public static function failures(): array
    {
        [$w, $s, $a] = [self::id(1), self::id(2), self::id(3)];
        $none     = ['modules' => [], 'numbers' => [], 'groups' => [], 'collections' => [], 'relations' => []];
        $j        = static fn(array ...$parts): array => array_replace($none, ...$parts);
        $numbered = ['view' => [1000, 1001], 'email' => [2000, 2001], 'alerts_filter' => [3000]];
        $spots    = ['site_spot' => [4000, 4001]];
        $site     = $j(['modules' => [$w => Module::SITE], 'numbers' => $numbered]);
        $sites    = $j($site, ['numbers' => $numbered + $spots, 'relations' => [[$w, $s]]]);
        $slice    = $j($sites, ['modules' => [$w => Module::SITE, $s => Module::SLICE]]);
        $alerts   = $j($sites, ['modules' => [$w => Module::SITE, $s => Module::SLICE, $a => Module::ALERTS], 'numbers' => $numbered + $spots + ['profile' => [5000]]]);
        $all      = $j($alerts, ['collections' => ['K0001']]);
        return [
            'first view'          => ['/^INSERT INTO `view`/', $none],
            'first e-mail'        => ['/^INSERT INTO `email`/', $j(['numbers' => ['view' => [1000, 1001]]])],
            'filter'              => ['/^INSERT INTO `alerts_filter`/', $j(['numbers' => ['view' => [1000, 1001], 'email' => [2000, 2001]]])],
            'view numbers'        => ['/^UPDATE `view`/', $j(['numbers' => $numbered])],
            'first module'        => ['/^INSERT INTO `module`/', $j(['numbers' => $numbered])],
            'site'                => ['/^INSERT INTO `site` /', $site],
            'first spot'          => ['/^INSERT INTO `site_spot`/', $site],
            'uses'                => ['/^INSERT INTO `relation`/', $j($site, ['numbers' => $numbered + $spots])],
            'slice'               => ['/^INSERT INTO `slice`/', $slice],
            'first field'         => ['/^INSERT INTO `field`/', $slice],
            'profile'             => ['/^INSERT INTO `profile`/', $slice],
            'collection'          => ['/^INSERT INTO `alerts_collection` /', $alerts],
            'collection filter'   => ['/^INSERT INTO `alerts_collection_filter`/', $all],
            'collection period'   => ['/^INSERT INTO `alerts_collection_howoften`/', $all],
            'group name'          => ['/^INSERT INTO `constant` /', $all],
            'group owner'         => ['/^INSERT INTO `constant_slice`/', $j($all, ['groups' => ['ct_rubriky']])],
            'second constant'     => ["/^INSERT INTO `constant` .*'Domácí'/s", $j($all, ['groups' => ['ct_rubriky']])],
        ];
    }

    /** a refused statement (nohalt: false) and a DB exception alike: rollback, nothing left, the journal */
    #[DataProvider('failures')]
    public function test_a_failure_rolls_back_and_keeps_the_journal(string $statement, array $journal): void
    {
        foreach (['false', 'throw'] as $mode) {
            $this->setUp();
            $plan         = $this->plan();
            TableDb::$fail = static function (string $sql) use ($statement, $mode): bool {
                if (!preg_match($statement, $sql)) {
                    return false;
                }
                return ($mode === 'false') ?: throw new RuntimeException('connection lost');
            };
            try {
                $this->importer()->write($plan);
                self::fail("$mode: no failure");
            } catch (ImportFailed $e) {
                self::assertInstanceOf(($mode === 'false') ? WriteError::class : RuntimeException::class, $e->getPrevious(), $mode);
                self::assertSame($journal, self::journal($e->journal), $mode);
            }
            self::assertSame('ROLLBACK', end(TableDb::$log), $mode);
            self::assertNotContains('COMMIT', TableDb::$log, $mode);
            self::assertSame([], array_filter(TableDb::$tables), "$mode: nothing stays");
        }
    }

    /** without a transaction (MyISAM, § 13.5) the rows stay - every one of them is found through the
     *  journal, which is what the compensation (part 4b) needs */
    #[DataProvider('failures')]
    public function test_without_a_transaction_the_journal_covers_every_row_written(string $statement, array $journal): void
    {
        TableDb::$transactions = false;
        TableDb::$fail         = static fn(string $sql): bool => (bool) preg_match($statement, $sql);
        try {
            $this->importer()->write($this->plan());
            self::fail('no failure');
        } catch (ImportFailed $e) {
            self::assertSame($journal, self::journal($e->journal));
            self::assertSame([], self::uncovered($e->journal));
        }
        self::assertNotContains('BEGIN', TableDb::$log);
    }

    public function test_the_journal_covers_a_complete_write(): void
    {
        $result = $this->importer()->write($this->plan());
        self::assertSame([], self::uncovered($result->journal));
    }

    /** @return list<string> rows no journal entry leads to */
    private static function uncovered(ImportJournal $j): array
    {
        $modules = array_keys($j->modules());
        $in      = static fn(mixed $v, array $list): bool => in_array($v, $list, true);
        $by      = [
            'module'   => fn(array $r): bool => $in($r['id'], $modules), 'slice' => fn(array $r): bool => $in($r['id'], $modules),
            'site'     => fn(array $r): bool => $in($r['id'], $modules), 'field' => fn(array $r): bool => $in($r['slice_id'], $modules),
            'relation' => fn(array $r): bool => $in([$r['source_id'], $r['destination_id']], $j->relations()),
            'constant' => fn(array $r): bool => $in(($r['group_id'] === 'lt_groupNames') ? $r['value'] : $r['group_id'], $j->groups()),
            'constant_slice'    => fn(array $r): bool => $in($r['group_id'], $j->groups()),
            'alerts_collection' => fn(array $r): bool => $in($r['id'], $j->collections()),
            'alerts_collection_filter'   => fn(array $r): bool => $in($r['collectionid'], $j->collections()),
            'alerts_collection_howoften' => fn(array $r): bool => $in($r['collectionid'], $j->collections()),
        ];
        foreach (['view', 'email', 'alerts_filter', 'site_spot', 'profile'] as $table) {
            $by[$table] = fn(array $r): bool => $in($r['id'], $j->numbers()[$table] ?? []);
        }
        $left = [];
        foreach (TableDb::$tables as $table => $rows) {
            foreach ($rows as $row) {
                if (!isset($by[$table]) || !$by[$table]($row)) {
                    $left[] = "$table " . json_encode($row);
                }
            }
        }
        return $left;
    }

    private static function journal(ImportJournal $j): array
    {
        return ['modules' => $j->modules(), 'numbers' => $j->numbers(), 'groups' => $j->groups(), 'collections' => $j->collections(), 'relations' => $j->relations()];
    }

    // ---------------------------------------------------------------- the plan is no reservation

    public function test_a_group_name_taken_since_the_plan_stops_the_write_before_any_row(): void
    {
        $plan                    = $this->plan();
        $this->target->groups[] = 'ct_rubriky';         // another import or an admin, between preview and write

        $e = $this->failure($plan);

        self::assertSame([WriteError::TAKEN, 'constant-group[ct_rubriky]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertTrue($e->journal->isEmpty());
        self::assertSame(['BEGIN', 'ROLLBACK'], TableDb::$log);
    }

    public function test_a_collection_taken_since_the_plan_stops_the_write_before_any_row(): void
    {
        $plan                         = $this->plan();
        $this->target->collections[] = 'K0001';

        $e = $this->failure($plan);

        self::assertSame([WriteError::TAKEN, 'alerts_collection[AbC12]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertSame(['BEGIN', 'ROLLBACK'], TableDb::$log);
    }

    public function test_the_db_target_answers_inside_the_transaction_and_locks_the_group_name(): void
    {
        $target = $this->dbTarget();
        $plan   = (new Planner($target))->plan(self::example(), (new Validator())->validate(self::example()));
        [TableDb::$log, TableDb::$handles] = [[], []];

        $this->importer($target)->write($plan);

        self::assertCount(1, array_unique(TableDb::$handles), 'the re-checks run on the transaction handle');
        $insert = array_search(array_values(array_filter(TableDb::$log, static fn(string $q): bool => str_contains($q, "'lt_groupNames'") && str_starts_with($q, 'INSERT')))[0], TableDb::$log, true);
        self::assertStringEndsWith('FOR UPDATE', TableDb::$log[$insert - 1], 'a locking read just before the lt_groupNames row');
        self::assertStringContainsString("group_id = 'ct_rubriky'", TableDb::$log[$insert - 1]);
    }

    public function test_a_group_created_in_the_db_since_the_plan_stops_the_write(): void
    {
        $target = $this->dbTarget();
        $plan   = (new Planner($target))->plan(self::example(), (new Validator())->validate(self::example()));
        // lt_groupNames.value is char: its collation ignores case and trailing spaces
        TableDb::$tables['constant'][] = ['id' => str_repeat('e', 32), 'group_id' => 'lt_groupNames', 'name' => 'CT_rubriky ', 'value' => 'CT_rubriky '];
        TableDb::$log = [];

        $e = $this->failure($plan, $target);

        self::assertSame(WriteError::TAKEN, $e->getPrevious()->reason);
        self::assertSame([], TableDb::writes());
    }

    public function test_a_collection_created_in_the_db_since_the_plan_stops_the_write(): void
    {
        $target = $this->dbTarget();
        $plan   = (new Planner($target))->plan(self::example(), (new Validator())->validate(self::example()));
        TableDb::$tables['alerts_collection'][] = ['id' => 'K0001', 'module_id' => str_repeat('e', 32)];

        $e = $this->failure($plan, $target);

        self::assertSame([WriteError::TAKEN, 'alerts_collection[AbC12]'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
    }

    public function test_the_locking_re_check_before_the_group_row_stops_the_write(): void
    {
        $plan   = $this->plan();
        $target = new class ($this->target) implements ImportTarget {
            public function __construct(private readonly FakeImportTarget $ids) {}

            public function groupTaken(string $name, bool $lock = false): bool
            {
                return $lock;       // free at the start, taken once locked - a concurrent import in between
            }

            public function collectionTaken(string $id): bool
            {
                return false;
            }

            public function newId(): string
            {
                return $this->ids->newId();
            }

            public function newCollectionId(): string
            {
                return $this->ids->newCollectionId();
            }
        };

        $e = $this->failure($plan, $target);

        self::assertSame([WriteError::TAKEN, 'constant-group[ct_rubriky]/lt_groupNames'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertSame([], $e->journal->groups());
        self::assertCount(3, $e->journal->modules());
        self::assertSame([], array_filter(TableDb::$tables));
    }

    // ---------------------------------------------------------------- values

    public function test_a_value_the_charset_cannot_hold_throws_before_the_commit(): void
    {
        $plan = $this->plan();
        $bad  = new ImportPlan(...array_merge(get_object_vars($plan), ['charsets' => [self::SLICE => 'iso-8859-1'] + $plan->charsets]));    // `č`

        $e = $this->failure($bad);

        self::assertSame([WriteError::CHARSET, 'slice-module[' . self::SLICE . ']/view[12]/name'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertSame([], array_filter(TableDb::$tables));
    }

    public function test_a_value_too_long_once_the_view_numbers_are_in_throws_before_the_commit(): void
    {
        $name = str_repeat('x', 111) . '{view:12}';                  // varchar(120): 120 characters, 122 with view 1000
        $plan = $this->plan(self::cell(self::example(), self::SLICE, 'view', 0, ['name' => $name]));
        self::assertContains('may-be-too-long', array_map(static fn(array $f): string => $f[1]->code, $plan->findings), 'the plan warned');

        $e = $this->failure($plan);

        self::assertSame([WriteError::TOO_LONG, 'slice-module[' . self::SLICE . ']/view[12]/name'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('122 characters > 120', $e->getMessage());
        self::assertSame('ROLLBACK', end(TableDb::$log));
        self::assertSame([], array_filter(TableDb::$tables));
    }

    public function test_more_bytes_than_a_char_column_has_characters_is_an_error_of_the_plan_and_of_the_writer(): void
    {
        // AA_MbT::getVarset4Data() cuts a varchar(120) value to 120 BYTES: 70 x `č` = 140 bytes would be cut inside a character
        $package = self::cell(self::example(), self::SLICE, 'view', 1, ['name' => str_repeat('č', 70)]);
        $plan    = (new Planner($this->target))->plan($package, (new Validator())->validate($package));
        self::assertFalse($plan->importable());
        self::assertSame(['E too-long 140 bytes > 120'], array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} {$f[1]->ref}", $plan->ofLevel(LintFinding::ERROR)));

        $e = $this->failure(self::forced($plan, static fn(Package $p): Package => $p));

        self::assertSame([WriteError::TOO_LONG, 'slice-module[' . self::SLICE . ']/view[13]/name'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('140 bytes > 120', $e->getMessage());
    }

    private function failure(ImportPlan $plan, ?ImportTarget $target = null): ImportFailed
    {
        try {
            $this->importer($target)->write($plan);
        } catch (ImportFailed $e) {
            self::assertInstanceOf(WriteError::class, $e->getPrevious());
            self::assertNotContains('COMMIT', TableDb::$log);
            return $e;
        }
        self::fail('the write did not fail');
    }
}
