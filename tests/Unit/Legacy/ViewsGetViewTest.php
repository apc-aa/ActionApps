<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA;
use AA_View;
use AA_Views;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * AA_Views::getView(): a view by its number, or by its name (view.field3) - looked up in the given module,
 * else in the module of the request. The views are in the cache of AA_Views, the database names only views.
 */
final class ViewsGetViewTest extends TestCase
{
    private const SLICE = 'cafe0000000000000000000000000001';
    private const OTHER = 'beef0000000000000000000000000002';
    private const SITE  = 'f00d0000000000000000000000000003';
    private const VID   = 41;

    /** @var array{mixed, mixed, mixed} AA::$site_id, $module_id and $slice_id before the test */
    private array $saved = [null, null, null];

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
        // the view photo_list is in SLICE only; no module is a site
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_starts_with($sql, 'SELECT id FROM view') && str_contains($sql, '0x' . self::SLICE) && str_contains($sql, "'photo_list'") => [['id' => (string) self::VID]],
            default => [],
        };
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $this->saved = [AA::$site_id, AA::$module_id, AA::$slice_id];
        [AA::$site_id, AA::$module_id, AA::$slice_id] = [null, null, null];
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, [self::VID => new AA_View(self::VID, ['id' => (string) self::VID, 'slice_id' => pack_id(self::SLICE), 'deleted' => '0'])]);
    }

    protected function tearDown(): void
    {
        $views = AA_Views::singleton();
        (new ReflectionProperty($views, 'a'))->setValue($views, []);
        [AA::$site_id, AA::$module_id, AA::$slice_id] = $this->saved;
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        AA::Contentcache()->clear();
    }

    /** @return list<string> the views the database was asked for */
    private static function askedViews(): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM view')));
    }

    public function test_getView_number_needsNoModule(): void
    {
        $this->assertSame(self::VID, AA_Views::getView(' 41 ')?->getId());
        $this->assertSame([], self::askedViews());
    }

    public function test_getView_name_inTheGivenSlice(): void
    {
        $this->assertSame(self::VID, AA_Views::getView('photo_list', self::SLICE)?->getId());
        $this->assertNull(AA_Views::getView('photo_list', self::OTHER));
    }

    public function test_getView_name_givenSliceBeatsTheSiteOfTheRequest(): void
    {
        AA::$site_id = self::SITE;
        $this->assertSame(self::VID, AA_Views::getView('photo_list', self::SLICE)?->getId());
        $this->assertCount(1, self::askedViews());
    }

    public function test_getView_name_inTheModuleOfTheRequest(): void
    {
        AA::$slice_id = self::SLICE;
        $this->assertSame(self::VID, AA_Views::getView('photo_list')?->getId());
        AA::$module_id = self::OTHER;   // the admin module comes first
        $this->assertNull(AA_Views::getView('photo_list'));
    }

    public function test_getView_name_withNoModule_isNone(): void
    {
        $this->assertNull(AA_Views::getView('photo_list'));
        $this->assertSame([], self::askedViews());
    }

    public function test_getView_unknownName_isNone(): void
    {
        $this->assertNull(AA_Views::getView('no_such_view', self::SLICE));
        $this->assertCount(1, self::askedViews());   // the name only - no view 0 loaded
    }
}
