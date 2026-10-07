<?php

declare(strict_types=1);

/**
 * The settings fields (id `_...`) of a slice an install creates start from their input_default, evaluated as
 * the item form evaluates the default of a new item - only the defaults that depend on no user or request.
 * The report counts what is left to fill; a transfer keeps its values; an existing slice keeps its own, the
 * settings fields it gets start from their defaults too.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\InstallRun;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\SettingDefaults;
use AA\Central\Package\Validator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Central\Package\Fixtures\TableDbTarget;

require_once __DIR__ . '/fixtures/ImportFixture.php';
// the charset AA_Inserter_Trm trims in (Langs::isUTF())
if (!\defined('DEFAULT_LANG_INCLUDE')) {
    \define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
}

final class SettingDefaultsTest extends TestCase
{
    use ImportFixture;

    /** the example's slice with more settings fields: id => [input_default, more columns] */
    private static function withSettings(array $fields): Package
    {
        return self::rows(self::example(), self::SLICE, 'field', static function (array $rows) use ($fields): array {
            foreach ($fields as $id => [$default, $more]) {
                $rows[] = ['id' => $id, 'type' => 'text', 'name' => "Name of $id", 'input_default' => $default] + $more;
            }
            return $rows;
        });
    }

    /** the content rows of the new slice: field => [number, text, flag] */
    private static function settings(string $slice): array
    {
        $out = [];
        foreach (TableDb::rows('content') as $r) {
            if ($r['item_id'] === $slice) {
                $out[$r['field_id']][] = [$r['number'], $r['text'] ?? null, $r['flag']];
            }
        }
        ksort($out);
        return $out;
    }

    /** "<code> <path> <ref>" of the I findings of the defaults @return list<string> */
    private static function skipped(ImportPlan $plan): array
    {
        $of = array_filter($plan->allFindings(), static fn(array $f): bool => $f[1]->code === SettingDefaults::SKIPPED);
        foreach ($of as $f) {
            self::assertSame(LintFinding::INFO, $f[1]->level);
        }
        return array_values(array_map(static fn(array $f): string => "$f[0] {$f[1]->ref}", $of));
    }

    public function test_an_install_fills_the_settings_from_the_safe_defaults(): void
    {
        $package = self::withSettings([
            '_title..........' => ['txt:Hello', ['html_default' => '1']],
            '_old_quote......' => ['qte:Old', []],
            '_count..........' => ['txt:42', ['text_stored' => null]],        // stored as a number
            '_until..........' => ['never', ['text_stored' => null]],
            '_from...........' => ['dte:7', ['text_stored' => null]],
            '_colors.........' => ['mul:red|green', []],
            '_flag...........' => ['txt:yes', ['input_insert_func' => 'boo:']],
            '_empty..........' => ['txt:', []],
        ]);
        $plan   = $this->plan($package);
        $report = $this->import($plan);

        self::assertSame([
            '_colors.........' => [[0, 'red', 64], [1, 'green', 64]],
            '_count..........' => [[42, null, 0]],
            '_email..........' => [[0, 'redakce@example.org', 64]],
            '_flag...........' => [[0, '1', 64]],
            '_from...........' => [[mktime(0, 0, 0, (int) date('m'), (int) date('d') + 7, (int) date('Y')), null, 0]],
            '_old_quote......' => [[0, 'Old', 64]],
            '_title..........' => [[0, 'Hello', 65]],             // html_default: FLAG_HTML as the item form sets it
            '_until..........' => [[MAX_TIME_NEVER, null, 0]],
        ], self::settings(self::id(2)));
        self::assertSame([], self::skipped($plan));
        self::assertSame(['_empty..........' => 'Name of _empty..........'], $report->settings[0]['fields'], 'an empty default leaves the field to fill');
        self::assertSame(1, $report->settingsToFill());
    }

    public function test_now_is_the_time_of_the_install(): void
    {
        $before = time();
        $plan   = $this->plan(self::withSettings(['_since..........' => ['now', ['text_stored' => null]]]));
        $after  = time();
        $this->import($plan);

        $since = self::settings(self::id(2))['_since..........'];
        self::assertCount(1, $since);
        self::assertGreaterThanOrEqual($before, $since[0][0]);
        self::assertLessThanOrEqual($after, $since[0][0]);
    }

    public function test_a_trim_field_gets_its_default_trimmed(): void
    {
        $plan = $this->plan(self::withSettings(['_name...........' => ["txt: Hello \t", ['input_insert_func' => 'trm:']]]));
        $this->import($plan);

        self::assertSame([[0, 'Hello', 64]], self::settings(self::id(2))['_name...........']);
        self::assertSame([], self::skipped($plan));
    }

    public function test_a_text_default_is_converted_to_the_charset_of_the_slice(): void
    {
        $package = self::lang(self::withSettings(['_title..........' => ['txt:Příliš žluťoučký', []]]), self::SLICE, 'cz_news_lang.php');
        $plan    = $this->plan($package);
        self::assertSame('windows-1250', $plan->charsets[self::SLICE]);

        $this->import($plan);

        $cp = static fn(string $utf): string => (string) iconv('UTF-8', 'windows-1250', $utf);
        self::assertSame([[0, $cp('Příliš žluťoučký'), 64]], self::settings(self::id(2))['_title..........']);
    }

    public function test_a_transfer_never_writes_defaults_of_an_install_plan(): void
    {
        $plan = $this->plan(self::example());
        self::assertNotSame([], $plan->settingDefaults);
        $run  = $this->newRun(InstallRun::TRANSFER);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Defaults of settings fields in a transfer');
        $this->import($plan, run: $run);
    }

    public function test_a_default_of_the_user_the_request_or_an_expression_is_skipped_with_an_i(): void
    {
        $package = self::withSettings([
            '_uid............' => ['uid', []],
            '_login..........' => ['log:', []],
            '_code...........' => ['rnd:5', []],
            '_expr...........' => ['var:{date:Y}', []],
            '_secret.........' => ['txt:plain', ['input_insert_func' => 'pwd:']],     // the form would store its hash
            '_unknown........' => ['xyz:1', []],
        ]);
        $plan   = $this->plan($package);
        $report = $this->import($plan);

        self::assertSame(['_email..........'], array_keys(self::settings(self::id(2))));
        $slice = self::S;
        self::assertSame([
            "$slice/field[_uid............]/input_default uid",
            "$slice/field[_login..........]/input_default log:",
            "$slice/field[_code...........]/input_default rnd:5",
            "$slice/field[_expr...........]/input_default var:{date:Y}",
            "$slice/field[_secret.........]/input_default txt:plain",
            "$slice/field[_unknown........]/input_default xyz:1",
        ], self::skipped($plan));
        self::assertSame(6, $report->settingsToFill());
    }

    public function test_the_preview_counts_the_settings_to_fill(): void
    {
        $plan = $this->plan(self::withSettings(['_uid............' => ['uid', []], '_title..........' => ['txt:Hello', []]]));

        self::assertSame([['module' => self::SLICE, 'name' => 'Central Test - články', 'fields' => ['_uid............' => 'Name of _uid............']]], $plan->preview()['settings']);
    }

    public function test_the_content_table_must_be_innodb_once_an_install_fills_settings(): void
    {
        $this->target->engines = ['content' => 'MyISAM'];
        $plan = (new Planner($this->target))->plan(self::example(), (new Validator())->validate(self::example()));
        self::assertFalse($plan->importable(), 'the default is written with the definitions');
        self::assertStringContainsString('content', $plan->ofLevel(LintFinding::ERROR)[0][1]->ref);

        $none = self::withSettings([]);
        $none = self::cell($none, self::SLICE, 'field', 2, ['input_default' => 'uid']);
        $plan = (new Planner($this->target))->plan($none, (new Validator())->validate($none));
        self::assertTrue($plan->importable(), 'no default - the content table is not written');
    }

    public function test_a_transfer_keeps_its_values_and_fills_nothing(): void
    {
        $values  = ['_email..........' => [['value' => 'from the source', 'flag' => 64]]];
        $package = self::module(
            self::withSettings(['_title..........' => ['txt:Hello', []]]),
            self::SLICE,
            static fn(Module $m): Module => self::with($m, ['transfer' => ['setting-values' => $values]]),
        );
        $plan    = (new Planner($this->target))->plan($package, (new Validator())->validate($package, true), true);
        $confirm = new ImportConfirmation('preview-session');

        $report = $this->import($plan, $this->importer(null, $confirm), $confirm->token($plan, self::USER), $this->newRun(InstallRun::TRANSFER));

        self::assertSame(['_email..........' => [[0, 'from the source', 64]]], self::settings(self::id(2)), 'no default next to the values of the source');
        self::assertSame([], $plan->settingDefaults);
        self::assertSame(['_title..........' => 'Name of _title..........'], $report->settings[0]['fields']);
    }

    /** the existing slice T with only the headline and a value of a setting it has no field of @return string T */
    private static function existingSlice(): string
    {
        $t = 'e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0';
        self::foreign();
        TableDb::$tables['module'][] = ['id' => $t, 'type' => 'S', 'name' => 'Existing', 'lang_file' => 'cz-utf8_news_lang.php', 'owner' => '', 'deleted' => 0];
        TableDb::$tables['slice'][]  = ['id' => $t, 'name' => 'Existing', 'type' => 'S', 'deleted' => 0];
        TableDb::$tables['field'][]  = ['id' => 'headline........', 'slice_id' => $t, 'type' => 'headline', 'name' => 'Titulek', 'input_pri' => 100, 'input_show_func' => 'fld:',
            'alias1' => '_#HEADLINE', 'alias1_func' => 'f_h:', 'text_stored' => 1, 'input_insert_func' => ''];
        TableDb::$tables['content'][] = ['item_id' => $t, 'field_id' => '_other..........', 'number' => 0, 'text' => 'mine', 'flag' => 64];
        TableDb::$tables['content'][] = ['item_id' => $t, 'field_id' => '_title..........', 'number' => 0, 'text' => 'kept', 'flag' => 64];
        return $t;
    }

    public function test_the_settings_fields_added_to_an_existing_slice_get_their_defaults(): void
    {
        $t       = self::existingSlice();
        $before  = TableDb::rows('content');
        $package = self::withSettings(['_title..........' => ['txt:Hello', []], '_uid............' => ['uid', []]]);
        $db      = new TableDbTarget($this->target);
        $plan    = (new Planner($db))->plan($package, (new Validator())->validate($package), false, [], [self::SLICE => $t]);
        $confirm = new ImportConfirmation('preview-session');

        $report = $this->import($plan, $this->importer($db, $confirm), $confirm->token($plan, self::USER));

        self::assertSame(['_email..........'], array_keys($plan->settingDefaults[self::SLICE] ?? []), 'a new field with no value; _title has one already');
        self::assertSame(
            [...$before, ['item_id' => $t, 'field_id' => '_email..........', 'number' => 0, 'text' => 'redakce@example.org', 'flag' => 64]],
            array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['item_id', 'field_id', 'number', 'text', 'flag'])), TableDb::rows('content')),
            'the values there stay as they are',
        );
        self::assertSame([self::S . '/field[_uid............]/input_default uid'], self::skipped($plan));
        self::assertSame(['_uid............'], array_keys($report->settings[0]['fields']), 'only the new field that got no value is to fill');
        self::assertSame("admin/slicefieldsedit.php?edit=1&change_id=$t", $report->settings[0]['url']);
    }

    public function test_the_values_of_the_source_reach_the_settings_fields_an_existing_slice_gets(): void
    {
        $t       = self::existingSlice();
        $values  = ['_email..........' => [['value' => 'from the source', 'flag' => 64]], '_title..........' => [['value' => 'source title', 'flag' => 64]]];
        $package = self::module(self::withSettings(['_title..........' => ['txt:Hello', []]]), self::SLICE, static fn(Module $m): Module => self::with($m, ['transfer' => ['setting-values' => $values]]));
        $db      = new TableDbTarget($this->target);

        $plan = (new Planner($db))->plan($package, (new Validator())->validate($package), false, [], [self::SLICE => $t]);

        self::assertSame(['_email..........' => [['value' => 'from the source', 'flag' => 64]]], $plan->settingDefaults[self::SLICE] ?? [], 'a superadmin\'s install; _title has a value there - it stays');
        self::assertSame([], array_values(array_filter($plan->allFindings(), static fn(array $f): bool => $f[1]->code === 'slice-state-kept')), 'an install into an existing slice keeps no state it could report');
    }

    public function test_a_default_added_to_an_existing_slice_is_in_its_charset(): void
    {
        $t = self::existingSlice();
        TableDb::$tables['module'][array_key_last(TableDb::$tables['module'])]['lang_file'] = 'cz_news_lang.php';
        TableDb::$tables['content'] = [];
        $package = self::withSettings(['_title..........' => ['txt:Příliš žluťoučký', []]]);
        $db      = new TableDbTarget($this->target);
        $plan    = (new Planner($db))->plan($package, (new Validator())->validate($package), false, [], [self::SLICE => $t]);
        $confirm = new ImportConfirmation('preview-session');
        self::assertSame('windows-1250', $plan->charsets[self::SLICE]);

        $this->import($plan, $this->importer($db, $confirm), $confirm->token($plan, self::USER));

        self::assertSame([[0, (string) iconv('UTF-8', 'windows-1250', 'Příliš žluťoučký'), 64]], self::settings($t)['_title..........']);
    }

    public function test_a_transfer_into_an_existing_slice_writes_no_defaults(): void
    {
        $t       = self::existingSlice();
        $before  = TableDb::rows('content');
        $db      = new TableDbTarget($this->target);
        $plan    = (new Planner($db))->plan(self::example(), (new Validator())->validate(self::example(), true), true, [], [self::SLICE => $t]);
        $confirm = new ImportConfirmation('preview-session');

        $this->import($plan, $this->importer($db, $confirm), $confirm->token($plan, self::USER), $this->newRun(InstallRun::TRANSFER));

        self::assertSame([], $plan->settingDefaults);
        self::assertSame($before, TableDb::rows('content'), 'no default next to the items of the source');
    }

    public function test_a_value_given_to_an_added_setting_since_the_plan_stops_the_write(): void
    {
        $t       = self::existingSlice();
        $db      = new TableDbTarget($this->target);
        $plan    = (new Planner($db))->plan(self::example(), (new Validator())->validate(self::example()), false, [], [self::SLICE => $t]);
        $confirm = new ImportConfirmation('preview-session');
        TableDb::$tables['content'][] = ['item_id' => $t, 'field_id' => '_email..........', 'number' => 0, 'text' => 'typed meanwhile', 'flag' => 64];

        try {
            $this->import($plan, $this->importer($db, $confirm), $confirm->token($plan, self::USER));
            self::fail('the default written next to the value');
        } catch (ImportFailed $e) {
            self::assertSame(['taken', self::S . '/setting-values/_email..........'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        }
    }
}
