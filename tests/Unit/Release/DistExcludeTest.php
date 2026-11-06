<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** dev/release/dist-exclude.txt is an rsync filter, so a build from a working tree copies whatever an
 *  installation keeps there - configs, certificates, its own code - unless the filter names it. */
final class DistExcludeTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3) . '/';
    }

    /** @return list<string> */
    private static function lines(string $file): array
    {
        return file(self::root() . $file, FILE_IGNORE_NEW_LINES) ?: [];
    }

    /** The per-install section of .gitignore: the lines after its comment up to the next comment
     *  @return list<string> */
    private static function perInstall(): array
    {
        $patterns = [];
        $in = false;
        foreach (self::lines('.gitignore') as $line) {
            if (str_starts_with($line, '#')) {
                if ($in) {
                    break;
                }
                $in = str_contains($line, 'per-install');
                continue;
            }
            if ($in && trim($line) !== '') {
                $patterns[] = trim($line);
            }
        }
        return $patterns;
    }

    /** @return list<array{string, string}> [sign, pattern] in file order */
    private static function rules(): array
    {
        $rules = [];
        foreach (self::lines('dev/release/dist-exclude.txt') as $line) {
            if (preg_match('/^([+-]) (\S+)$/', $line, $m)) {
                $rules[] = [$m[1], $m[2]];
            }
        }
        return $rules;
    }

    /** rsync's first-match-wins over the path and each of its parent directories; an excluded
     *  directory takes everything under it. Covers the pattern forms the file uses. */
    private static function excluded(string $path): bool
    {
        $parts = explode('/', $path);
        for ($i = 1, $n = count($parts); $i <= $n; $i++) {
            $prefix = implode('/', array_slice($parts, 0, $i));
            $is_dir = $i < $n;
            foreach (self::rules() as [$sign, $pattern]) {
                $dir_only = str_ends_with($pattern, '/');
                $p = rtrim($pattern, '/');
                if ($dir_only && !$is_dir) {
                    continue;
                }
                $match = str_starts_with($p, '/')
                    ? fnmatch(substr($p, 1), $prefix, FNM_PATHNAME | FNM_PERIOD)
                    : fnmatch($p, $parts[$i - 1], FNM_PERIOD);
                if ($match) {
                    if ($sign === '-') {
                        return true;
                    }
                    continue 2;
                }
            }
        }
        return false;
    }

    public function test_per_install_section_is_found(): void
    {
        $this->assertContains('/include/config.php', self::perInstall());
        $this->assertContains('/config/certs/*', self::perInstall());
    }

    public function test_every_per_install_pattern_is_excluded_from_dist(): void
    {
        $excludes = array_map(static fn(array $r): string => $r[1], array_filter(self::rules(), static fn(array $r): bool => $r[0] === '-'));
        foreach (self::perInstall() as $pattern) {
            if (str_starts_with($pattern, '!')) {
                $this->assertContains(['+', substr($pattern, 1)], self::rules(), "versioned $pattern must still ship");
                continue;
            }
            $this->assertContains($pattern, $excludes, "$pattern is per-install in .gitignore, so dist-exclude.txt must leave it out");
        }
    }

    public function test_dist_gitignore_hides_every_per_install_pattern(): void
    {
        $dist = self::lines('dev/release/dist-gitignore');
        foreach (self::perInstall() as $pattern) {
            if (!str_starts_with($pattern, '!')) {
                $this->assertContains($pattern, $dist);
            }
        }
    }

    public function test_every_kept_file_exists_and_comes_before_its_exclude(): void
    {
        $rules = self::rules();
        foreach ($rules as $i => [$sign, $pattern]) {
            if ($sign !== '+') {
                continue;
            }
            $this->assertFileExists(self::root() . ltrim($pattern, '/'), "stale keep rule $pattern");
            $later = array_filter(array_slice($rules, $i + 1), static fn(array $r): bool => $r[0] === '-' && fnmatch(ltrim($r[1], '/'), ltrim($pattern, '/'), FNM_PATHNAME));
            $this->assertNotEmpty($later, "keep rule $pattern guards nothing - no later exclude matches it");
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function pathProvider(): array
    {
        return [
            'config'               => ['include/config.php', true],
            'per-database config'  => ['include/config.ecn.php', true],
            'config template'      => ['include/config.php.default', false],
            'own code'             => ['include/custom/site.php', true],
            'user alias functions' => ['include/usr_aliasfnc.php', true],
            'certificate key'      => ['config/certs/isds.key', true],
            'certificates readme'  => ['config/certs/README', false],
            'config htaccess'      => ['config/.htaccess', false],
            'site control file'    => ['modules/site/sites/site_mine.php', true],
            'site example'         => ['modules/site/sites/site_ecn_as_example.php', false],
            'site external example' => ['modules/site/sites/external_controlfile_example.php', false],
            'editorial importer'   => ['misc/file2slice/importer.php', false],
            'developer one-off'    => ['misc/file2slice/array2constants.php', true],
            'install own import'   => ['misc/file2slice/mine.php', true],
            'datovka config'       => ['misc/datovka/datovka_conf.php', true],
            'runtime code'         => ['include/util.php', false],
            'nested git'           => ['include/custom/.git/config', true],
            'tests'                => ['tests/bootstrap.php', true],
        ];
    }

    #[DataProvider('pathProvider')]
    public function test_path_in_dist(string $path, bool $excluded): void
    {
        $this->assertSame($excluded, self::excluded($path), $path . ($excluded ? ' must stay out of dist' : ' must ship in dist'));
    }
}
