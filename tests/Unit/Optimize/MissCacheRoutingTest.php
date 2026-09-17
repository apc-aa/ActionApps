<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Cache\ImgCache;
use AA\Optimize\MissCacheRouting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The HTTP probes and the config constants need a live install; the rule, its writing and
 *  the reading of the probe answers do not. */
final class MissCacheRoutingTest extends TestCase
{
    private string $tmp;

    /** stands for IMG_UPLOAD_PATH, which exists on any install that has uploads */
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/aa-misscache-routing-' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0o775);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ImgCache::class, 'config'))->setValue(null, null);
        @chmod($this->tmp, 0o775);
        array_map('unlink', glob($this->tmp . '/mC/.htaccess*') ?: []);
        @rmdir($this->tmp . '/mC');
        @rmdir($this->tmp);
    }

    public function test_rule_routes_a_miss_to_the_dispatcher_of_the_install(): void
    {
        $rule = (string) MissCacheRouting::rule('/aa-dev/');
        $this->assertStringContainsString("RewriteCond %{REQUEST_FILENAME} !-f\n", $rule, 'a file already in the cache is served by the web server');
        $this->assertStringContainsString("RewriteRule ^ /aa-dev/modules/site/site.php [L,QSA]\n", $rule);
        $this->assertStringContainsString("RewriteRule ^ /modules/site/site.php [L,QSA]\n", (string) MissCacheRouting::rule('/'), 'AA at the domain root');
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeInstallPaths(): array
    {
        return ['space' => ['/a a/'], 'newline' => ["/aaa/\n"], 'relative' => ['aaa/'], 'no trailing slash' => ['/aaa'], 'quote' => ['/a"a/'], 'parent' => ['/../'], 'empty segment' => ['//']];
    }

    /** Apache parses the file: a space or a newline in the target answers 500 for every cached image */
    #[DataProvider('unsafeInstallPaths')]
    public function test_rule_refuses_an_install_path_apache_would_choke_on(string $install_path): void
    {
        $this->assertNull(MissCacheRouting::rule($install_path));
    }

    public function test_rule_is_written_together_with_the_cache_directory(): void
    {
        $dir  = $this->tmp . '/mC';
        $rule = (string) MissCacheRouting::rule('/aaa/');

        $this->assertTrue(MissCacheRouting::write($dir, $rule, 0o775));
        $this->assertStringEqualsFile("$dir/.htaccess", $rule);
        $this->assertSame(["$dir/.htaccess"], glob("$dir/.htaccess*"), 'no temp file left behind');
        $this->assertSame(0o775, fileperms($dir) & 0o7777, 'the mode asked for, whatever the umask - the other PHP user of the uploads writes here too');
        $this->assertSame(0o644, fileperms("$dir/.htaccess") & 0o777);
    }

    /** Repair points the routing at this tree whatever is there */
    public function test_write_replaces_an_existing_rule(): void
    {
        $dir = $this->tmp . '/mC';
        MissCacheRouting::write($dir, (string) MissCacheRouting::rule('/aaa/'), 0o775);

        $this->assertTrue(MissCacheRouting::write($dir, (string) MissCacheRouting::rule('/aa-dev/'), 0o775));
        $this->assertStringEqualsFile("$dir/.htaccess", (string) MissCacheRouting::rule('/aa-dev/'));
    }

    /** /aaa/ and /aa-dev/ share the upload directory; the task queue of either leaves the rule the other wrote */
    public function test_ensure_never_replaces_an_existing_rule(): void
    {
        (new \ReflectionProperty(ImgCache::class, 'config'))->setValue(null, [
            'IMG_UPLOAD_URL' => 'https://example.org/img_upload/', 'IMG_UPLOAD_PATH' => $this->tmp, 'IMG_UPLOAD_DIR_MODE' => 0o775,
            'AA_INSTAL_URL' => 'https://example.org/aaa/', 'AA_MISSCACHE_IMG_DISABLE' => false,
        ]);
        MissCacheRouting::write($this->tmp . '/mC', (string) MissCacheRouting::rule('/aaa/'), 0o775);

        $this->assertTrue(MissCacheRouting::ensure());
        $this->assertStringEqualsFile($this->tmp . '/mC/.htaccess', (string) MissCacheRouting::rule('/aaa/'));
    }

    /** neither the rule nor its temp file is left where a directory blocks the name */
    public function test_temp_file_is_removed_when_the_rule_cannot_be_placed(): void
    {
        $dir = $this->tmp . '/mC';
        mkdir("$dir/.htaccess", 0o775, true);

        $this->assertFalse(MissCacheRouting::write($dir, (string) MissCacheRouting::rule('/aaa/'), 0o775));
        $this->assertSame(["$dir/.htaccess"], glob("$dir/.htaccess*"), 'no temp file left behind');
        rmdir("$dir/.htaccess");
    }

    public function test_unwritable_upload_directory_is_reported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes anywhere');
        }
        chmod($this->tmp, 0o555);

        $this->assertFalse(MissCacheRouting::write($this->tmp . '/mC', (string) MissCacheRouting::rule('/aaa/'), 0o775));
    }

    /** @return array<string, array{0: int, 1: string, 2: bool, 3: string}> */
    public static function answers(): array
    {
        return [
            'dispatcher answered its blank'     => [200, 'image/jpeg', true, 'ok'],
            'routed by the server config'       => [200, 'image/jpeg', false, 'ok'],
            'catch-all page instead of image'   => [200, 'text/html; charset=utf-8', true, 'ignored'],
            'not found although the rule is in' => [404, 'text/html', true, 'ignored'],
            'tree disabled on this host'        => [403, 'text/html', true, 'ignored'],
            'not found, no rule'                => [404, 'text/html', false, 'missing'],
            'no answer, rule in place'          => [0, '', true, 'unreachable'],
            'no answer, no rule - write it'     => [0, '', false, 'missing'],
        ];
    }

    #[DataProvider('answers')]
    public function test_verdict_on_the_probe_answer(int $status, string $content_type, bool $has_rule, string $expected): void
    {
        $this->assertSame($expected, MissCacheRouting::verdict($status, $content_type, $has_rule));
    }

    /** @return array<string, array{0: int, 1: string, 2: bool}> */
    public static function imgPhpAnswers(): array
    {
        return [
            'its redirect to the blank'    => [302, 'https://example.org/aa/images/blank.gif', true],
            'redirected elsewhere'         => [301, 'https://www.example.org/aa/img.php', false],
            'no answer'                    => [0, '', false],
            'a page instead of a redirect' => [200, '', false],
            'redirect without a location'  => [302, '', false],
        ];
    }

    #[DataProvider('imgPhpAnswers')]
    public function test_img_php_runs(int $status, string $location, bool $expected): void
    {
        $this->assertSame($expected, MissCacheRouting::imgPhpRuns($status, $location, 'https://example.org/aa/images/blank.gif'));
    }
}
