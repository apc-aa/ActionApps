<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckCookieReads;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/** Installation PHP reading cookies directly is invisible to the site page cache, which
 *  varies only on cookies read through PageCache::cookie(). */
final class CheckCookieReadsTest extends TestCase
{
    private string $base = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/aa-cookie-reads-' . bin2hex(random_bytes(4)) . '/';
        foreach (['include/custom/site1', 'modules/site/sites'] as $dir) {
            mkdir($this->base . $dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->base);
    }

    private function put(string $file, string $code): void
    {
        file_put_contents($this->base . $file, $code);
    }

    public function test_clean_installation_is_ok(): void
    {
        $this->put('include/usr_aliasfnc.php', '<?php function usr_x($c) { return \AA\Cache\PageCache::cookie("lang"); }');
        $findings = CheckCookieReads::findingsFor($this->base);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_each_file_reading_cookies_is_a_warning_with_its_lines(): void
    {
        $this->put('include/usr_aliasfnc.php', "<?php\nfunction usr_lang() {\n    return \$_COOKIE['lang'] ?? 'cz';\n}\n");
        $this->put('include/custom/site1/stringexpand.php', "<?php\n\$h = \$_SERVER['HTTP_COOKIE'];\n");
        $this->put('modules/site/sites/site_x.php', "<?php\n// \$_COOKIE only in a comment\n");

        $findings = CheckCookieReads::findingsFor($this->base);

        $this->assertSame(['include/custom/site1/stringexpand.php', 'include/usr_aliasfnc.php'], array_map(static fn(Finding $f): string => $f->key, $findings));
        $this->assertSame(Finding::WARNING, $findings[1]->severity);
        $this->assertSame('3', $findings[1]->evidence[0]['Lines']);
    }

    public function test_installation_files_are_only_the_install_specific_ones(): void
    {
        $this->put('include/usr_aliasfnc.php', '<?php');
        $this->put('include/custom/site1/a.php', '<?php');
        $this->put('include/custom/site1/readme.txt', '');
        $this->put('modules/site/sites/site_x.php', '<?php');
        $this->put('include/config.php', '<?php');

        $this->assertSame(
            ['include/custom/site1/a.php', 'include/usr_aliasfnc.php', 'modules/site/sites/site_x.php'],
            CheckCookieReads::installationFiles($this->base),
        );
    }
}
