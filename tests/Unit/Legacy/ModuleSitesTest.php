<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Module;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * The sites a module belongs to: only sites not deleted, the newest first, each once - asked in one query.
 */
final class ModuleSitesTest extends TestCase
{
    private const SLICE = 'cafe0000000000000000000000000001';
    private const NEWER = 'f00d0000000000000000000000000003';
    private const OLDER = 'f00d0000000000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/slice.class.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    public function test_belongsToSites_asksTheSitesNotDeleted_newestFirst_eachOnce(): void
    {
        RecordingDb::reset();
        // the rows as the database orders them; a site related twice comes twice
        RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'FROM relation')
            ? [['unpackid' => self::NEWER], ['unpackid' => self::OLDER], ['unpackid' => self::NEWER]]
            : [];
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        self::assertSame([self::NEWER, self::OLDER], AA_Module::belongsToSites(self::SLICE));
        self::assertCount(1, RecordingDb::$log, 'one query - no list of all the modules');
        self::assertMatchesRegularExpression('/destination_id\W+= 0x' . self::SLICE . '\b.*module\W+deleted\W+= 0\b.*ORDER BY module\.created_at DESC/s', RecordingDb::$log[0]);
    }

    public function test_belongsToSites_moduleOfNoSite(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];

        self::assertSame([], AA_Module::belongsToSites(self::SLICE));
    }
}
