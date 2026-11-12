<?php

declare(strict_types=1);

/**
 * Which module include/init_page.php falls back to when the request brings none: the page's own
 * type first (by its directory in AA_Moduleregistry), else the old choice - first slice, else the
 * last module - init_page.php then redirects to that module's own page. Jump pages count as slice
 * pages - Jump has no admin of its own (it uses the slice menu).
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

        /** @return array<string, array{string, string, bool}> */
        public static function refusals(): array
        {
            $own = 'modules/site/index.php';
            return [
                'a generic page: to the module page' => ['admin/se_tasks.php', '/aa/admin/se_tasks.php', false],
                'the module page itself'             => [$own, "/aa/$own", true],
                'a page that could not be named'     => ['', '/aa/admin/se_tasks.php', true],
                // an auto_prepend_file inside the AA tree is resolved first - the server still runs the module page
                'a prepend in the tree, module page' => ['waf-prepend.php', "/aa/$own", true],
                'a prepend in the tree, other page'  => ['waf-prepend.php', '/aa/admin/se_tasks.php', false],
                'a look-alike address'               => ['admin/se_tasks.php', '/aa/xmodules/site/index.php', false],
            ];
        }

        /**
         * A refused page goes to the module's own page - unless it is that page, as resolved or as
         * the server runs it: sent there, it would be refused again, in a circle. Both answers
         * refuse, so a wrong SCRIPT_NAME opens nothing.
         */
        #[DataProvider('refusals')]
        public function testRefuseInPlace_whenTheRedirectWouldComeBack(string $script, string $script_name, bool $expected): void
        {
            self::assertSame($expected, AA_Module::refuseInPlace($script, $script_name, 'modules/site/index.php'));
        }

        /**
         * The page init_page.php runs for is the first included file inside the AA tree, not the
         * first included file: under php-cgi an auto_prepend_file comes before the page, and a
         * page taken for "somewhere else" has no module type - a site page opened with no module
         * chosen would run on the first slice rather than on a site module.
         */
        public function testPageScript_isTheFirstIncludedFileInsideTheAaTree(): void
        {
            $base  = sys_get_temp_dir() . '/aa-pagescript-' . getmypid();
            $root  = $base . '/aa';
            $files = ['prepend.php', 'aa-other/admin/x.php', 'aa/admin/se_users.php', 'aa/include/init_page.php'];
            foreach ($files as $file) {
                @mkdir(dirname("$base/$file"), 0777, true);
                touch("$base/$file");
            }
            @symlink($root, "$base/link");
            $root = (string) realpath($root);   // what init_page.php passes: dirname(__DIR__), symlinks resolved
            try {
                $included = ["$base/prepend.php", "$base/aa-other/admin/x.php", "$base/aa/admin/se_users.php", "$base/aa/include/init_page.php"];
                self::assertSame('admin/se_users.php', AA_Module::pageScript($included, $root), 'a prepend and a look-alike directory are not the page');
                self::assertSame('admin/se_users.php', AA_Module::pageScript(["$base/link/admin/se_users.php"], $root), 'a path through a symlink is the same page');
                self::assertSame('', AA_Module::pageScript(["$base/prepend.php", "$base/missing.php"], $root), 'nothing inside AA - no page to name');
            } finally {
                @unlink("$base/link");
                foreach ($files as $file) {
                    @unlink("$base/$file");
                }
                foreach (['aa-other/admin', 'aa-other', 'aa/admin', 'aa/include', 'aa', ''] as $dir) {
                    @rmdir("$base/$dir");
                }
            }
        }
    }
}
