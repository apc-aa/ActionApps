<?php

declare(strict_types=1);

/**
 * Which module include/init_page.php falls back to when the request brings none: the page's own
 * type first (by its directory in AA_Moduleregistry), else the old choice - first slice, else the
 * last module. A fallback slice must stay usable on a module page: the insert POST creating the
 * first Alerts module comes with no module id at all. Jump pages count as slice pages - Jump has
 * no admin of its own (it uses the slice menu).
 *
 * No DB: both methods are pure (the registry is built from the module classes).
 */

namespace {
    // slice.class.php pulls include/constants.php through the autoloader (see ModuleNameClassMismatchTest)
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Module;
    use AA_Moduleregistry;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ModuleFallbackTest extends TestCase
    {
        private const CORE_FIELDS = '41415f436f72655f4669656c64732e2e';
        private const SLICE_1     = '11111111111111111111111111111111';
        private const SLICE_2     = '22222222222222222222222222222222';
        private const SITE_1      = '33333333333333333333333333333333';
        private const SITE_2      = '44444444444444444444444444444444';
        private const JUMP        = '55555555555555555555555555555555';

        /** id => type, in menu order */
        private const ALL = [self::CORE_FIELDS => 'S', self::SLICE_1 => 'S', self::SITE_1 => 'W', self::SLICE_2 => 'S', self::SITE_2 => 'W', self::JUMP => 'J'];

        /** @return array<string, array{string, string}> */
        public static function scripts(): array
        {
            return [
                'site index'       => ['modules/site/index.php', 'W'],
                'site modedit'     => ['modules/site/modedit.php', 'W'],
                'jump: slice menu' => ['modules/jump/modedit.php', 'S'],
                'polls'            => ['modules/polls/index.php', 'P'],
                'links'            => ['modules/links/linkedit.php', 'Links'],
                'alerts'           => ['modules/alerts/index.php', 'Alerts'],
                'slice admin'      => ['admin/index.php', 'S'],
                'outside a module' => ['central/index.php', 'S'],
                'module template'  => ['modules/module_TEMPLATE/index.php', 'S'],
                'prefix only'      => ['modules/sitemap/index.php', 'S'],
            ];
        }

        #[DataProvider('scripts')]
        public function testTypeOfScriptFollowsModuleDirectory(string $script, string $type): void
        {
            self::assertSame($type, AA_Moduleregistry::typeOfScript($script));
        }

        /** @return array<string, array{array<string, array{type: string}>, string, string}> */
        public static function fallbacks(): array
        {
            // the modules of the given types, in menu order
            $m = static fn(string ...$types) => array_map(static fn($t) => ['type' => $t], array_intersect(self::ALL, $types));
            $core_and_jump = [self::CORE_FIELDS => ['type' => 'S'], self::JUMP => ['type' => 'J']];
            return [
                // old behaviour, pinned
                'slice page: first slice, core fields skipped' => [$m('S', 'W', 'J'), 'S', self::SLICE_1],
                'slice page: no slice -> last module'          => [$m('W', 'J'), 'S', self::JUMP],
                'slice page: only core fields slice -> last'   => [$core_and_jump, 'S', self::JUMP],
                // new: the page's own type first
                'site page: first site'                        => [$m('S', 'W', 'J'), 'W', self::SITE_1],
                'page of another type: its module'             => [$m('S', 'W', 'J'), 'J', self::JUMP],
                // no module of the page's type -> old behaviour
                'site page, no site: first slice'              => [$m('S', 'J'), 'W', self::SLICE_1],
                'page of another type, none: first slice'      => [$m('S', 'W'), 'J', self::SLICE_1],
                'site page, no site, no slice: last module'    => [$core_and_jump, 'W', self::JUMP],
            ];
        }

        /** @param array<string, array{type: string}> $modules */
        #[DataProvider('fallbacks')]
        public function testFallbackModuleId(array $modules, string $type, string $expected): void
        {
            self::assertSame($expected, AA_Module::getFallbackModuleId($modules, $type));
        }
    }
}
