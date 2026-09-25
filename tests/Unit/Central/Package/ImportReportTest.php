<?php

declare(strict_types=1);

/**
 * After the commit: Importer::finish() - the log entry, the ACL objects, ConstantsChanged(), the page cache,
 * each step isolated, none undone - and the report Importer::write() returns for the example package.
 * AaAfterCommit's log entry over TableDb.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\AaAfterCommit;
use AA\Central\Package\FixUp;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportReport;
use AA_Logentry_App;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class ImportReportTest extends TestCase
{
    use ImportFixture;

    // ---------------------------------------------------------------- finishing

    public function test_the_steps_run_after_the_commit(): void
    {
        $report = $this->importer()->write($this->plan());
        [$w, $s, $a] = [self::id(1), self::id(2), self::id(3)];

        self::assertSame([
            "logInstall Central Test $w,$s,$a",
            "permissions $w importer-uid", "permissions $s importer-uid", "permissions $a importer-uid",
            'constantsChanged',
            "invalidatePagecache $w", "invalidatePagecache $s", "invalidatePagecache $a",
        ], $this->after->calls);
        $commit = array_search('COMMIT', TableDb::$log, true);
        self::assertSame(count(TableDb::$log) - 1, $commit, 'no statement after the commit (the fake makes none)');
        self::assertSame(array_fill(0, 8, $commit + 1), $this->after->at, 'every step after the commit');
        self::assertSame([
            ['log', []], ["permissions[$w]", []], ["permissions[$s]", []], ["permissions[$a]", []], ['constants', []],
            ["pagecache[$w]", []], ["pagecache[$s]", []], ["pagecache[$a]", []],
        ], $report->finish);
        self::assertSame([], $report->failures());
    }

    public function test_a_failed_commit_fails_the_import_and_runs_no_step(): void
    {
        TableDb::$commitFails = true;
        try {
            $this->importer()->write($this->plan());
            self::fail('a failed commit passed');
        } catch (ImportFailed $e) {
            self::assertStringContainsString('commit failed', $e->getMessage());
        }
        self::assertSame([], $this->after->calls, 'nothing after a commit that did not happen');
        self::assertSame(['COMMIT', 'ROLLBACK'], array_slice(TableDb::$log, -2));
        self::assertSame([], array_filter(TableDb::$tables));
    }

    public function test_no_new_group_no_constants_changed(): void
    {
        $package = self::with(self::example(), ['constantGroups' => []]);
        $package = self::cell($package, self::SLICE, 'field', 1, ['input_show_func' => 'fld:']);
        $package = self::cell($package, self::SLICE, 'view', 1, ['odd' => '']);
        $this->importer()->write($this->plan($package));

        self::assertNotContains('constantsChanged', $this->after->calls);
        self::assertCount(7, $this->after->calls);
    }

    public function test_a_failed_step_is_a_row_of_the_report_and_undoes_nothing(): void
    {
        [$w, $s, $a] = [self::id(1), self::id(2), self::id(3)];
        $this->after->fail = ["logInstall Central Test $w,$s,$a", "permissions $s importer-uid", 'constantsChanged', "invalidatePagecache $w"];

        $report = $this->importer()->write($this->plan());

        self::assertCount(8, $this->after->calls, 'the steps after a failed one run');
        self::assertSame([
            ['log', ["RuntimeException: logInstall Central Test $w,$s,$a failed"]],
            ["permissions[$s]", ["RuntimeException: permissions $s importer-uid failed"]],
            ['constants', ['RuntimeException: constantsChanged failed']],
            ["pagecache[$w]", ["RuntimeException: invalidatePagecache $w failed"]],
        ], $report->failures());
        self::assertSame('COMMIT', end(TableDb::$log), 'nothing undone: not a statement after the commit');
        self::assertCount(3, TableDb::rows('module'), 'the import stays');
        self::assertCount(3, TableDb::rows('constant'));
    }

    public function test_the_log_entry_of_aa(): void
    {
        [$w, $s] = [self::id(1), self::id(2)];
        (new AaAfterCommit())->logInstall(self::example(), [$w, $s]);

        $rows   = TableDb::rows('log');
        $params = AA_Logentry_App::make('Central Test', '0.1.0', 'https://actionapps.org/aadev/')->serializeToString();
        self::assertSame([[AaAfterCommit::EVENT, $w, $params], [AaAfterCommit::EVENT, $s, $params]], array_map(static fn(array $r): array => [$r['type'], $r['selector'], $r['params']], $rows));
        self::assertSame('App:Central Test:0.1.0:https#://actionapps.org/aadev/', $params);
        self::assertLessThanOrEqual(10, strlen(AaAfterCommit::EVENT), 'log.type');
    }

    public function test_a_package_without_a_new_module_logs_one_row_of_aa_id(): void
    {
        (new AaAfterCommit())->logInstall(self::example(), []);
        self::assertSame([AA_ID], array_column(TableDb::rows('log'), 'selector'));
    }

    public function test_a_log_row_not_written_is_a_failure(): void
    {
        TableDb::$fail = static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO `log`');
        $this->expectExceptionMessage('INSERT INTO log failed');
        (new AaAfterCommit())->logInstall(self::example(), [self::id(1)]);
    }

    public function test_the_rights_of_aa_the_acl_object_and_a_role_below_super(): void
    {
        class_exists(\AA_Permsystem_Sql::class);         // include/perm_sql.php: AddPermObject(), AddPerm()
        $saved = [$GLOBALS['auth'] ?? null, $GLOBALS['r_superuser'] ?? null, $GLOBALS['permission_uid'] ?? null, $GLOBALS['permission_to'] ?? null];
        try {
            $GLOBALS['auth']           = new class {
                public function getUid(): string
                {
                    return 'u1';
                }
            };
            $GLOBALS['permission_uid'] = 'u1';          // IsSuperadmin() reads the cache of u1
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => 'U'], 'slice' => []];

            $GLOBALS['r_superuser'] = [AA_ID => true];
            (new AaAfterCommit())->permissions(self::id(1), 'u1');
            self::assertSame([], TableDb::rows('perms'), 'a superadmin reaches every module');

            $GLOBALS['r_superuser'] = [];
            (new AaAfterCommit())->permissions(self::id(2), 'u1');
            self::assertSame(
                [['object_type' => 'slice', 'objectid' => self::id(2), 'userid' => 'u1', 'perm' => \AA_Perm::ROLES['ADMINISTRATOR']['id']]],
                array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['object_type', 'objectid', 'userid', 'perm'])), TableDb::rows('perms')),
                'PS_INSTALL_APP below SUPER: the ADMINISTRATOR role',
            );
        } finally {
            [$GLOBALS['auth'], $GLOBALS['r_superuser'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']] = $saved;
        }
    }

    public function test_the_log_entry_fits_its_column_and_one_line(): void
    {
        $entry  = AA_Logentry_App::make("Name\nforged line " . str_repeat('ž', 100), '1.0', 'https://example.org/' . str_repeat('x', 200));
        $params = $entry->serializeToString();
        self::assertLessThanOrEqual(AA_Logentry_App::PARAMS_MAX, strlen($params));
        self::assertStringNotContainsString("\n", $params);
        self::assertSame('', $entry->source, 'the source URL gives up first');
        self::assertStringStartsWith('Name forged line ž', $entry->name);
        self::assertTrue(mb_check_encoding($entry->name, 'UTF-8'));
        self::assertSame('1.0', $entry->version);
    }

    // ---------------------------------------------------------------- the report

    public function test_the_report_of_the_example(): void
    {
        $plan   = $this->plan();
        $report = $this->importer()->write($plan);
        [$w, $s, $a] = [self::id(1), self::id(2), self::id(3)];

        self::assertSame(['name' => 'Central Test', 'version' => '0.1.0', 'description' => self::example()->description, 'source' => self::example()->source], $report->package);
        self::assertSame([
            ['type' => 'site-module', 'name' => 'Central Test - web', 'old' => self::SITE, 'new' => $w],
            ['type' => 'slice-module', 'name' => 'Central Test - články', 'old' => self::SLICE, 'new' => $s],
            ['type' => 'alerts-module', 'name' => 'Upozornění', 'old' => self::ALERTS, 'new' => $a],
        ], $report->modules);

        // every row written, by table - the same as the tables hold
        $written = array_map('count', TableDb::$tables);
        ksort($written);
        self::assertSame($written, $report->rows);
        self::assertSame(3, $report->rows['module']);
        self::assertSame(3, $report->rows['constant'], 'two constants and the lt_groupNames row');

        self::assertSame($plan->fixUps, $report->ignored);
        self::assertContains(FixUp::PUBLISH, array_map(static fn(FixUp $f): string => $f->code, $report->ignored), 'publish = users');
        self::assertSame([
            'views'       => [12 => 1000, 13 => 1001],
            'emails'      => [5 => 2000, 7 => 2001],
            'filters'     => [3 => 3000],
            'groups'      => ['ct_rubriky' => 'ct_rubriky'],
            'collections' => $plan->map->collections(),
            'objects'     => [self::ALIAS => self::id(4), self::SET => self::id(5), self::COND => self::id(6), self::SORT => self::id(7)],
            'items'       => [],
        ], $report->maps);
        self::assertSame($plan->allFindings(), $report->findings);

        self::assertSame([['module' => $s, 'name' => 'Central Test - články', 'fields' => ['_email..........' => $this->fieldName('_email..........')],
            'url' => "admin/slicefieldsedit.php?edit=1&change_id=$s"]], $report->settings);
        self::assertSame(1, $report->settingsToFill());
        self::assertSame(ImportReport::SETTINGS_URL, 'admin/slicefieldsedit.php?edit=1&change_id=');
    }

    public function test_the_report_counts_the_items_by_slice(): void
    {
        $a      = str_repeat('1', 32);
        $b      = str_repeat('2', 32);
        $report = $this->importer()->write($this->plan(self::items(self::example(), [self::item($a), self::item($b)])));

        self::assertSame([self::id(2) => 2], $report->maps['items']);
        self::assertSame(2, $report->rows['item']);
        self::assertCount(2, TableDb::rows('item'));
        self::assertSame(2, $report->rows['content'], 'the headline of each (status_code is a column of item)');
        self::assertCount(2, TableDb::rows('content'));
    }

    public function test_the_report_quotes_package_text_as_it_is(): void
    {
        $package = self::cell(self::example(), self::SLICE, 'module', 0, ['name' => '<script>x</script>']);
        $report  = $this->importer()->write($this->plan($package));
        self::assertSame('<script>x</script>', $report->modules[1]['name'], 'the UI escapes (safe())');
        self::assertSame('<script>x</script>', $report->settings[0]['name']);
    }

    private function fieldName(string $id): string
    {
        foreach (TableDb::rows('field') as $row) {
            if ($row['id'] === $id) {
                return (string) $row['name'];
            }
        }
        self::fail("no field $id");
    }
}
