<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA;
use AA\Security\ViewDirect;
use AA_Module;
use AA_Module_Site;
use AA_Slice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * Who keeps the views of a slice inside pages: the view, the slice, or an active site that uses the slice.
 */
final class ViewDirectTest extends TestCase
{
    private const SLICE   = 'cafe0000000000000000000000000001';
    private const SITE    = 'f00d0000000000000000000000000003';
    private const DELETED = 'dead0000000000000000000000000004';

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        AA::Contentcache()->clear();
        (new ReflectionProperty(AA_Module::class, '_modules'))->setValue(null, []);
    }

    /** @param list<string> $forbidding the modules whose settings keep views inside pages */
    private static function database(array $forbidding): void
    {
        // AA keeps the types of the modules for the whole process (a static of AA_Module::getModuleInfo()):
        // after another test asked for them, a site of this test is taken for a slice - so its settings
        // answer under the id of either class
        $settings = [];
        foreach ($forbidding as $module) {
            $settings[AA_Slice::getModuleObjectId($module)]       = [$module, 'AA_Modulesettings_Slice'];
            $settings[AA_Module_Site::getModuleObjectId($module)] = [$module, 'AA_Modulesettings_Site'];
        }
        RecordingDb::reset();
        AA::Contentcache()->clear();
        (new ReflectionProperty(AA_Module::class, '_modules'))->setValue(null, []);
        RecordingDb::$answer = static function (string $sql) use ($settings): array {
            if (str_contains($sql, '`type` FROM `module`')) {
                return [['unpackid' => self::SLICE, 'type' => 'S'], ['unpackid' => self::SITE, 'type' => 'W'], ['unpackid' => self::DELETED, 'type' => 'W']];
            }
            if (str_contains($sql, 'FROM relation')) {
                // the deleted site only when the query does not leave deleted modules out
                return preg_match('/module\W+deleted\W+= 0/', $sql) ? [['unpackid' => self::SITE]] : [['unpackid' => self::DELETED], ['unpackid' => self::SITE]];
            }
            foreach ($settings as $object_id => [$module, $class]) {
                if (str_contains($sql, "object_id = '$object_id'")) {
                    return [
                        ['tbl' => 'object_text', 'property' => 'aa_type', 'value' => $class, 'priority' => 0],
                        ['tbl' => 'object_text', 'property' => 'aa_owner', 'value' => $module, 'priority' => 0],
                        ['tbl' => 'object_text', 'property' => ViewDirect::PROPERTY, 'value' => '1', 'priority' => 0],
                    ];
                }
            }
            return [];
        };
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    /** @return array<string, array{bool, list<string>, ?array{string, string}}> the view is set, modules set, who forbids */
    public static function settings(): array
    {
        return [
            'nobody'                       => [false, [], null],
            'the view'                     => [true, [], [ViewDirect::BY_VIEW, '']],
            'the view first'               => [true, [self::SLICE, self::SITE], [ViewDirect::BY_VIEW, '']],
            'the slice'                    => [false, [self::SLICE], [ViewDirect::BY_SLICE, self::SLICE]],
            'the slice before the site'    => [false, [self::SLICE, self::SITE], [ViewDirect::BY_SLICE, self::SLICE]],
            'a site using the slice'       => [false, [self::SITE], [ViewDirect::BY_SITE, self::SITE]],
            'a deleted site says nothing'  => [false, [self::DELETED], null],
        ];
    }

    /**
     * @param list<string>           $forbidding
     * @param ?array{string, string} $by
     */
    #[DataProvider('settings')]
    public function test_forbiddenBy(bool $view_set, array $forbidding, ?array $by): void
    {
        self::database($forbidding);

        self::assertSame($by, ViewDirect::forbiddenBy($view_set, self::SLICE));
    }

    public function test_forbiddenBy_viewSet_asksNoDatabase(): void
    {
        self::database([]);

        ViewDirect::forbiddenBy(true, self::SLICE);

        self::assertSame([], RecordingDb::$log);
    }

    public function test_sitesForbidding_asksTheSitesOnceARequest(): void
    {
        self::database([self::SITE]);

        self::assertSame([self::SITE], ViewDirect::sitesForbidding(self::SLICE));
        self::assertSame([self::SITE], ViewDirect::sitesForbidding(self::SLICE));
        self::assertCount(1, array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM relation')));
    }

    public function test_sitesForbidding_asksOnlySitesNotDeleted(): void
    {
        self::database([self::SITE]);

        ViewDirect::sitesForbidding(self::SLICE);

        $asked = array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM relation')));
        self::assertMatchesRegularExpression('/module\W+deleted\W+= 0\b.*ORDER BY module\.created_at DESC/s', $asked[0]);
        if (getenv('AA_SHOW_SQL')) {
            fwrite(STDERR, "\n" . $asked[0] . "\n");
        }
    }

    /** @return array<string, array{list<string>, list<string>, bool}> sites set so, the slices of the site, the cache is dropped */
    public static function savedSites(): array
    {
        return [
            'the site keeps views inside' => [[self::SITE], [self::SLICE], true],
            'the site does not'           => [[], [self::SLICE], false],
            'a site without slices'       => [[self::SITE], [], false],
        ];
    }

    /**
     * @param list<string> $forbidding
     * @param list<string> $slices
     */
    #[DataProvider('savedSites')]
    public function test_siteSaved_dropsTheCachedOutputsOfItsSlices(array $forbidding, array $slices, bool $dropped): void
    {
        self::database($forbidding);

        ViewDirect::siteSaved(self::SITE, $slices);

        $cache = array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'pagecache'));
        self::assertSame($dropped, $cache !== []);
    }
}
