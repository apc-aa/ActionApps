<?php

declare(strict_types=1);

/**
 * CheckCentralCredentials: State Check warns about central_conf rows that keep a password, a plain or a
 * cut legacy token or a token it cannot use, about a column too narrow for the encrypted token, and says
 * when this PHP cannot encrypt at all. Repair widens the column, encrypts the plain tokens and clears the
 * cut ones.
 */

namespace Tests\Unit\Optimize;

use AA\Central\Actionapps;
use AA\Optimize\CheckCentralCredentials;
use AA\Optimize\Finding;
use ItemContent;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\CentralConfDb;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/** the check with the column widening recorded instead of rebuilding the table */
final class WideningCheck extends CheckCentralCredentials
{
    public static int $width = 30;
    public static int $widened = 0;

    protected function widenColumn(): bool
    {
        ++self::$widened;
        self::$width = 1024;
        return true;
    }
}

/** the check with the columns given and the table rebuild recorded; its SQL goes to RecordingDb */
final class ColumnCheck extends CheckCentralCredentials
{
    /** @var array<int, mixed> the column in the database, as AA_MbC keeps it */
    public static array $actual = [];
    public static int $rebuilt = 0;

    public function widen(): bool
    {
        return $this->widenColumn();
    }

    protected function passwordColumns(): array
    {
        return [\AA_MbC::fromArray(self::$actual), \AA_MbC::fromArray(['AA_ADMIN_PWD', 'varbinary(1024)', false, '', '', '', ''])];
    }

    protected function rebuildTable(): bool
    {
        ++self::$rebuilt;
        return true;
    }
}

final class CheckCentralCredentialsTest extends TestCase
{
    private const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI3In0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../../../include/varset.php';
        require_once __DIR__ . '/../../../include/searchlib.php';
        require_once __DIR__ . '/../Legacy/fixtures/central_fixtures.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    private static function aa(string $id, string $stored, string $domain = 'https://r.example.org/'): Actionapps
    {
        $content = new ItemContent();
        foreach (self::row($id, $stored, $domain) as $k => $v) {
            $content->setValue($k, $v);
        }
        return new Actionapps($content);
    }

    /** @return array<string, string> */
    private static function row(string $id, string $stored, string $domain = 'https://r.example.org/'): array
    {
        return ['id' => $id, 'ORG_NAME' => "AA $id", 'AA_HTTP_DOMAIN' => $domain, 'AA_BASE_DIR' => 'aa/', 'AA_ADMIN_PWD' => $stored];
    }

    private static function encrypted(string $id, string $domain = 'https://r.example.org/'): string
    {
        $aa = self::aa($id, '', $domain);
        $aa->setAuthToken(self::TOKEN);
        return (string) $aa->getValue('AA_ADMIN_PWD');
    }

    /** @param Finding[] $findings @return array<string, Finding> by key */
    private static function byKey(array $findings): array
    {
        return array_column(array_map(static fn(Finding $f) => ['k' => $f->key, 'f' => $f], $findings), 'f', 'k');
    }

    public function test_no_rows_or_only_encrypted_tokens_are_ok(): void
    {
        foreach ([[], [self::aa('1', self::encrypted('1')), self::aa('2', '')]] as $aas) {
            $findings = CheckCentralCredentials::assess($aas, true, 1024, 1024);
            self::assertCount(1, $findings);
            self::assertSame(Finding::OK, $findings[0]->severity);
        }
    }

    public function test_each_kind_of_problem_is_its_own_finding(): void
    {
        $aas = [
            self::aa('1', 'jwt:' . self::TOKEN),
            self::aa('2', 'jwt:' . self::TOKEN),
            self::aa('3', 'hunter2'),
            self::aa('4', 'v1:AAAAAAAAAAAAgarbage'),
            self::aa('5', self::encrypted('5', 'https://old.example.org/')),
            self::aa('6', self::encrypted('6')),
            self::aa('7', substr('jwt:' . self::TOKEN, 0, 30)),
        ];

        $findings = self::byKey(CheckCentralCredentials::assess($aas, true, 1024, 1024));

        self::assertSame(['legacy', 'cut', 'password', 'unreadable'], array_keys($findings));
        self::assertSame([2, 1, 1, 2], array_map(static fn(Finding $f) => $f->count, array_values($findings)));
        self::assertSame(Finding::WARNING, $findings['legacy']->severity);
        self::assertTrue($findings['legacy']->repairable, 'repair() encrypts them');
        self::assertStringContainsString('address', $findings['legacy']->advice, 'a legacy token is bound to no address');
        self::assertTrue($findings['cut']->repairable, 'repair() clears them');
        self::assertStringContainsString('again', $findings['cut']->advice);
        self::assertStringContainsString('DB_PASSWORD', $findings['unreadable']->advice);
        foreach (['password', 'unreadable'] as $key) {
            self::assertSame(Finding::WARNING, $findings[$key]->severity);
            self::assertFalse($findings[$key]->repairable, 'only a new login helps');
        }
        $shown = json_encode(array_map(static fn(Finding $f) => [$f->title, $f->evidence, $f->advice], $findings));
        self::assertStringNotContainsString(self::TOKEN, (string) $shown);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 26), (string) $shown);
        self::assertStringNotContainsString('hunter2', (string) $shown);
        self::assertStringContainsString('AA 3', (string) $shown, 'names the rows');
    }

    public function test_a_column_narrower_than_the_schema_is_an_error_of_a_central(): void
    {
        $column = self::byKey(CheckCentralCredentials::assess([self::aa('1', '')], true, 30, 1024))['column'] ?? null;

        self::assertSame(Finding::ERROR, $column?->severity);
        self::assertTrue($column->repairable);
        self::assertSame(Finding::OK, CheckCentralCredentials::assess([], true, 30, 1024)[0]->severity, 'no Central here');
    }

    public function test_without_aes_gcm_rows_to_keep_are_an_error(): void
    {
        $findings = self::byKey(CheckCentralCredentials::assess([self::aa('1', 'jwt:' . self::TOKEN)], false, 1024, 1024));

        self::assertSame(Finding::ERROR, $findings['aes-gcm']->severity);
        self::assertFalse($findings['legacy']->repairable, 'nothing to encrypt them with');
        self::assertSame(Finding::OK, CheckCentralCredentials::assess([], false, 1024, 1024)[0]->severity, 'no Central here - nothing to say');
    }

    public function test_repair_widens_the_column_then_encrypts_plain_tokens_and_clears_cut_ones(): void
    {
        $cut = substr('jwt:' . self::TOKEN, 0, 30);
        WideningCheck::$width   = 30;
        WideningCheck::$widened = 0;
        CentralConfDb::install([
            self::row('3', 'jwt:' . self::TOKEN) + ['status_code' => '2'],
            self::row('8', 'hunter2'),
            self::row('9', $cut),
        ], static fn() => WideningCheck::$width);

        (new WideningCheck())->repair();

        self::assertSame(1, WideningCheck::$widened);
        $updates = CentralConfDb::updates();
        self::assertCount(2, $updates, 'a password needs a new login');
        self::assertMatchesRegularExpression("/SET  AA_ADMIN_PWD = 'v1:[\\w-]+' WHERE id = 3 AND /", $updates[0], 'any bin');
        self::assertSame("UPDATE `central_conf` SET  AA_ADMIN_PWD = '' WHERE id = 9 AND AA_ADMIN_PWD = '$cut'", $updates[1]);
    }

    public function test_repair_does_not_widen_a_wide_column(): void
    {
        WideningCheck::$width   = 1024;
        WideningCheck::$widened = 0;
        CentralConfDb::install([self::row('3', '')], static fn() => WideningCheck::$width);

        (new WideningCheck())->repair();

        self::assertSame(0, WideningCheck::$widened);
    }

    public function test_an_unknown_width_is_neither_reported_nor_repaired(): void
    {
        self::assertArrayNotHasKey('column', self::byKey(CheckCentralCredentials::assess([self::aa('1', '')], true, null, 1024)));

        WideningCheck::$widened = 0;
        CentralConfDb::install([self::row('3', '')], static fn() => null);
        (new WideningCheck())->repair();
        self::assertSame(0, WideningCheck::$widened);
    }

    /** SQL of widenColumn() for the column in the database, the backup table there or not */
    private static function widening(array $actual, bool $backup): array
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'information_schema.TABLES') ? [['n' => $backup ? '1' : '0']] : [];
        $GLOBALS['spareDBs'] = array_map(static fn() => new RecordingDb(), range(1, 5));
        ColumnCheck::$actual  = $actual;
        ColumnCheck::$rebuilt = 0;
        self::assertTrue((new ColumnCheck())->widen());
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => !str_starts_with($sql, 'SELECT')));
    }

    public function test_a_column_different_only_in_width_is_altered_in_place(): void
    {
        $sql = self::widening(['AA_ADMIN_PWD', 'varbinary(30)', false, '', '', '', ''], true);

        self::assertSame(['ALTER TABLE `central_conf` MODIFY `AA_ADMIN_PWD` varbinary(1024) NOT NULL '], $sql, 'no copy of the table with its plain tokens');
        self::assertSame(0, ColumnCheck::$rebuilt);
    }

    public function test_a_column_different_otherwise_rebuilds_the_table_and_clears_the_backup(): void
    {
        $nullable = ['AA_ADMIN_PWD', 'varbinary(30)', true, '', null, '', ''];

        self::assertSame(["UPDATE `bck_central_conf` SET `AA_ADMIN_PWD` = ''"], self::widening($nullable, true));
        self::assertSame(1, ColumnCheck::$rebuilt);
        self::assertSame([], self::widening($nullable, false), 'no backup table');
    }
}
