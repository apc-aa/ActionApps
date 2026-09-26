<?php

declare(strict_types=1);

/**
 * CheckCentralCredentials: State Check warns about central_conf rows holding anything but an encrypted
 * token - a password or a plain token lying in the database. No repair: Get Token overwrites them.
 */

namespace Tests\Unit\Optimize;

use AA\Central\Actionapps;
use AA\Optimize\CheckCentralCredentials;
use AA\Optimize\Finding;
use ItemContent;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\CentralConfDb;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

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

    public function test_no_rows_or_only_encrypted_or_unusable_tokens_are_ok(): void
    {
        $aas = [self::aa('1', self::encrypted('1')), self::aa('2', ''), self::aa('3', 'v1:AAAAAAAAAAAAgarbage'), self::aa('4', self::encrypted('4', 'https://old.example.org/'))];
        foreach ([[], $aas] as $rows) {
            $findings = CheckCentralCredentials::assess($rows);
            self::assertCount(1, $findings);
            self::assertSame(Finding::OK, $findings[0]->severity);
        }
    }

    public function test_passwords_and_plain_tokens_are_one_warning_without_repair(): void
    {
        $aas = [
            self::aa('1', 'jwt:' . self::TOKEN),
            self::aa('2', 'hunter2'),
            self::aa('3', self::encrypted('3')),
        ];

        $findings = CheckCentralCredentials::assess($aas);

        self::assertCount(1, $findings);
        self::assertSame(Finding::WARNING, $findings[0]->severity);
        self::assertSame(2, $findings[0]->count);
        self::assertFalse($findings[0]->repairable);
        self::assertStringContainsString('Get Token', $findings[0]->advice);
        self::assertSame(['1', '2'], array_column($findings[0]->evidence, 'id'));
        $shown = (string) json_encode([$findings[0]->title, $findings[0]->evidence, $findings[0]->advice]);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 26), $shown);
        self::assertStringNotContainsString('hunter2', $shown);
        self::assertStringContainsString('AA 2', $shown, 'names the rows');
    }

    public function test_the_check_reads_every_row_and_offers_no_repair(): void
    {
        CentralConfDb::install([self::row('3', 'hunter2') + ['status_code' => '2'], self::row('8', '')]);

        $findings = (new CheckCentralCredentials())->findings();

        self::assertSame(1, $findings[0]->count, 'any bin');
        self::assertSame(['test'], CheckCentralCredentials::actions());
        self::assertSame([], CentralConfDb::updates());
    }
}
