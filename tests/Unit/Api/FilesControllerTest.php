<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Controller\FilesController;
use AA\Api\JsonResponse;
use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Stands in for the request context and the file system calls that need a real upload. */
final class FakeFilesController extends FilesController
{
    /** @var ?array{editor: bool, anonymous: bool} */
    public ?array $viewer = ['editor' => true, 'anonymous' => false];
    public ?string $dir = null;
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    public array $logged = [];
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $refused = [];
    /** @var list<array{selector: string, params: string}> */
    public array $anonLastHour = [];
    public bool $storeFails = false;

    protected function viewer(string $slice_id): ?array
    {
        return $this->viewer;
    }
    protected function uid(): string
    {
        return 'u1';
    }
    protected function ip(): string
    {
        return '192.0.2.1';
    }
    protected function escape(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.\-]/u', '_', $name);
    }
    protected function destinationDir(string $slice_id): ?string
    {
        return $this->dir;
    }
    protected function store(array $file, string $dir, string $name): string
    {
        if ($this->storeFails) {
            throw new \RuntimeException("Can't move /srv/secret/path/$name");
        }
        copy((string) $file['tmp_name'], $path = "$dir/$name");
        return $path;
    }
    protected function storeReencoded(string $tmp, string $dir, string $name): string
    {
        $error = \AA\Util\Imgfunc::reencode($tmp, $path = "$dir/$name", UploadPolicy::ANON_MAX_PX, UploadPolicy::ANON_MAX_PIXELS);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        return $path;
    }
    protected function urlOf(string $slice_id, string $name): string
    {
        return "/img/$name";
    }
    protected function thumbOf(string $url): string
    {
        return "thumb:$url";
    }
    protected function log(string $event, string $selector, array $params): void
    {
        $this->logged[] = [$event, $selector, $params];
    }
    protected function logRefusal(string $slice_id, string $reason, string $detail): void
    {
        $this->refused[] = [$slice_id, $reason, $detail];
    }
    protected function anonUploadsLastHour(): array
    {
        return $this->anonLastHour;
    }
}

final class FilesControllerTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';
    private string $dir;
    private FakeFilesController $c;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), Secret::key() constants
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aa-files-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->c      = new FakeFilesController();
        $this->c->dir = $this->dir;
        $_POST = ['slice' => self::SLICE];
        $_GET  = [];
        $_FILES = [];
        $_SERVER['HTTP_HOST']   = 'aa.example';
        $_SERVER['HTTP_ORIGIN'] = 'https://aa.example';
        unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_REFERER']);
        $this->token(UploadPolicy::USER);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function token(string $mode, string $uid = 'u1'): void
    {
        $_SERVER['HTTP_X_AA_UPLOAD_TOKEN'] = UploadToken::issue(self::SLICE, $mode, $uid, time());
    }

    private function file(string $name, string $content): void
    {
        file_put_contents($tmp = tempnam(sys_get_temp_dir(), 'aa-up'), $content);
        $_FILES['file'] = ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'application/octet-stream'];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function read(JsonResponse $response): array
    {
        $ref = new \ReflectionObject($response);
        return ['status' => $ref->getProperty('status')->getValue($response), 'body' => $ref->getProperty('payload')->getValue($response)];
    }

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    public function test_editor_uploads_a_pdf(): void
    {
        $this->file('Zpráva roku.pdf', self::PDF);
        $r = $this->read($this->c->upload());
        $this->assertSame(200, $r['status']);
        $this->assertSame('Zpr_va_roku.pdf', $r['body']['name']);   // the fake escape(): one _ per non-ASCII character
        $this->assertSame('/img/Zpr_va_roku.pdf', $r['body']['location']);
        $this->assertSame('application/pdf', $r['body']['type']);
        $this->assertFileExists("$this->dir/Zpr_va_roku.pdf");
    }

    public function test_cross_host_request_is_refused(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $this->file('a.pdf', self::PDF);
        $this->assertSame(401, $this->read($this->c->upload())['status']);
    }

    public function test_token_of_another_user_is_refused_and_logged(): void
    {
        $this->token(UploadPolicy::USER, 'someone-else');
        $this->file('a.pdf', self::PDF);
        $this->assertSame(403, $this->read($this->c->upload())['status']);
        $this->assertSame([[self::SLICE, 'rights', '']], $this->c->refused);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function dangerous(): array
    {
        return [
            'html' => ['a.html', '<!doctype html><html><body>x</body></html>'],
            'php'  => ['a.php', "<?php echo 1;\n"],
            'svg'  => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_editor_cannot_upload_what_a_browser_or_php_runs(string $name, string $content): void
    {
        $this->file($name, $content);
        $this->assertSame(415, $this->read($this->c->upload())['status']);
        $this->assertSame([self::SLICE, 'type', pathinfo($name, PATHINFO_EXTENSION)], $this->c->refused[0]);
        $this->assertSame([], glob("$this->dir/*"));
    }

    public function test_content_must_match_the_extension(): void
    {
        $this->file('a.jpg', self::PDF);
        $this->assertSame(415, $this->read($this->c->upload())['status']);
        $this->assertSame([[self::SLICE, 'mime', 'jpg application/pdf']], $this->c->refused);
    }

    public function test_a_double_extension_keeps_one_dot(): void
    {
        $this->file('x.php.pdf', self::PDF);
        $this->assertSame('x_php.pdf', $this->read($this->c->upload())['body']['name']);
    }

    public function test_anonymous_image_is_reencoded_and_logged(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is not loaded');
        }
        $this->c->viewer = ['editor' => false, 'anonymous' => true];
        $this->token(UploadPolicy::ANON);
        $img = imagecreatetruecolor(30, 20);
        ob_start();
        imagepng($img);
        $this->file('Foto.png', (string) ob_get_clean() . '<?php junk');
        $r = $this->read($this->c->upload());
        $this->assertSame(200, $r['status']);
        // a name the uploader cannot choose: no password-reset.jpg on the organisation's domain
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}\.png$/', $r['body']['name']);
        $this->assertStringNotContainsString('<?php', (string) file_get_contents("$this->dir/" . $r['body']['name']));
        $this->assertSame(FilesController::EVENT_ANON, $this->c->logged[0][0]);
        $this->assertSame([$r['body']['name'], (string) $r['body']['size'], '192.0.2.1'], $this->c->logged[0][2]);
    }

    private function anonymous(): void
    {
        $this->c->viewer = ['editor' => false, 'anonymous' => true];
        $this->token(UploadPolicy::ANON);
    }

    /** @return list<array{selector: string, params: string}> */
    private function uploads(int $n, string $slice, string $ip): array
    {
        return array_fill(0, $n, ['selector' => $slice, 'params' => ParamImplode(['x.png', '10', $ip])]);
    }

    public function test_anonymous_uploads_are_limited_per_address(): void
    {
        $this->anonymous();
        $this->c->anonLastHour = $this->uploads(FilesController::ANON_PER_IP_HOUR, str_repeat('f', 32), '192.0.2.1');
        $this->file('a.png', 'x');
        $this->assertSame(429, $this->read($this->c->upload())['status']);
        $this->assertSame([[self::SLICE, 'rate', '192.0.2.1']], $this->c->refused);
    }

    public function test_anonymous_uploads_are_limited_per_slice(): void
    {
        $this->anonymous();
        $this->c->anonLastHour = $this->uploads(FilesController::ANON_PER_SLICE_HOUR, self::SLICE, '198.51.100.7');
        $this->file('a.png', 'x');
        $this->assertSame(429, $this->read($this->c->upload())['status']);
    }

    public function test_anonymous_image_with_too_many_pixels_is_refused_before_decoding(): void
    {
        $this->anonymous();
        $ihdr = 'IHDR' . pack('NNCCCCC', 5000, 4000, 8, 2, 0, 0, 0);
        $this->file('big.png', "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)));
        $this->assertSame(413, $this->read($this->c->upload())['status']);
        $this->assertSame([], glob("$this->dir/*"));
    }

    public function test_unknown_slice_is_refused_without_a_log_row(): void
    {
        $this->c->viewer = null;
        $this->file('a.pdf', self::PDF);
        $this->assertSame(404, $this->read($this->c->upload())['status']);
        $this->assertSame([], $this->c->refused);
    }

    public function test_a_failed_store_hides_server_paths_and_logs_them(): void
    {
        $this->c->storeFails = true;
        $this->file('a.pdf', self::PDF);
        $r = $this->read($this->c->upload());
        $this->assertSame(500, $r['status']);
        $this->assertStringNotContainsString('/srv/secret', (string) json_encode($r['body']));
        $this->assertSame('store', $this->c->refused[0][1]);
        $this->assertStringContainsString('/srv/secret', $this->c->refused[0][2]);
    }

    public function test_files_with_names_that_are_not_utf8_are_left_out_of_the_list(): void
    {
        touch("$this->dir/ok.pdf");
        touch("$this->dir/" . "\xE8ern\xE1.pdf");        // cp1250 "černá" from an old upload
        $this->assertSame(['ok.pdf'], array_column(FilesController::filesIn($this->dir, false, '', 10), 'name'));
    }

    public function test_anonymous_cannot_upload_a_pdf(): void
    {
        $this->c->viewer = ['editor' => false, 'anonymous' => true];
        $this->token(UploadPolicy::ANON);
        $this->file('a.pdf', self::PDF);
        $this->assertSame(415, $this->read($this->c->upload())['status']);
    }

    public function test_anonymous_token_on_a_slice_that_no_longer_takes_anonymous_items(): void
    {
        $this->c->viewer = ['editor' => false, 'anonymous' => false];
        $this->token(UploadPolicy::ANON);
        $this->file('a.png', 'x');
        $this->assertSame(403, $this->read($this->c->upload())['status']);
    }

    public function test_body_over_post_max_size_says_too_big(): void
    {
        $_POST = [];
        $_SERVER['CONTENT_LENGTH'] = '999999999';
        $this->assertSame(413, $this->read($this->c->upload())['status']);
    }

    public function test_unwritable_directory_is_logged_for_state_check(): void
    {
        $this->c->dir = null;
        $this->file('a.pdf', self::PDF);
        $this->assertSame(500, $this->read($this->c->upload())['status']);
        $this->assertSame([[self::SLICE, 'dir', '']], $this->c->refused);
    }

    public function test_list_for_editors_newest_first_filtered(): void
    {
        foreach (['old.jpg' => 100, 'new.png' => 300, 'doc.pdf' => 200, '.hidden.jpg' => 400] as $name => $mtime) {
            touch("$this->dir/$name", $mtime);
        }
        $_GET = ['slice' => self::SLICE, 'kind' => 'image'];
        $r = $this->read($this->c->list());
        $this->assertSame(200, $r['status']);
        $this->assertSame(['new.png', 'old.jpg'], array_column($r['body']['files'], 'name'));
        $this->assertSame('thumb:/img/new.png', $r['body']['files'][0]['thumb']);

        $_GET = ['slice' => self::SLICE, 'q' => 'DOC'];
        $this->assertSame(['doc.pdf'], array_column($this->read($this->c->list())['body']['files'], 'name'));
    }

    public function test_list_is_refused_to_anonymous(): void
    {
        $this->c->viewer = ['editor' => false, 'anonymous' => true];
        $this->token(UploadPolicy::ANON);
        $_GET = ['slice' => self::SLICE];
        $this->assertSame(403, $this->read($this->c->list())['status']);
    }
}
