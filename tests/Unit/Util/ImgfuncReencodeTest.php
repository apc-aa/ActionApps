<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Imgfunc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An anonymous upload is written again from its pixels: nothing else of the file may survive. */
final class ImgfuncReencodeTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m()
    }

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is not loaded');
        }
        $this->dir = sys_get_temp_dir() . '/aa-reencode-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) {
            return;                             // skipped before setUp() made it
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        imagejpeg($img, $file = "$this->dir/src.jpg");
        return $file;
    }

    public function test_big_jpeg_is_shrunk_to_the_limit(): void
    {
        $this->assertNull(Imgfunc::reencode($this->jpeg(3000, 1000), "$this->dir/out.jpg", 2000));
        $info = getimagesize("$this->dir/out.jpg");
        $this->assertSame([2000, 667, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_small_png_keeps_its_size_and_transparency(): void
    {
        $img = imagecreatetruecolor(10, 10);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagepng($img, $src = "$this->dir/t.png");

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out.png", 2000));
        $out = imagecreatefrompng("$this->dir/out.png");
        $this->assertSame([10, 10], [imagesx($out), imagesy($out)]);
        $this->assertSame(127, (imagecolorat($out, 5, 5) >> 24) & 0x7F, 'fully transparent pixel');
    }

    public function test_anything_hidden_after_the_image_data_is_gone(): void
    {
        $src = $this->jpeg(50, 50);
        file_put_contents($src, '<?php echo "x"; ?>', FILE_APPEND);
        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out.jpg", 2000));
        $this->assertStringNotContainsString('<?php', (string) file_get_contents("$this->dir/out.jpg"));
    }

    /** $jpeg with EXIF Orientation $orientation, as a phone stores a photo it took turned */
    private function turned(string $jpeg, int $orientation): string
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('exif is not loaded');
        }
        $bytes = (string) file_get_contents($jpeg);
        // APP1 Exif, big-endian TIFF, IFD0 with one entry: Orientation (0x0112), SHORT, 1, $orientation
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        file_put_contents($src = "$this->dir/rot.jpg", substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
        $this->assertSame($orientation, exif_read_data($src)['Orientation'] ?? null, 'fixture carries the tag');
        return $src;
    }

    /** a phone photo taken upright is stored sideways plus EXIF Orientation 6 - without applying it the re-encoded image lies on its side */
    public function test_exif_orientation_is_applied_before_exif_is_dropped(): void
    {
        $this->assertNull(Imgfunc::reencode($this->turned($this->jpeg(40, 20), 6), "$this->dir/out.jpg", 2000));
        $info = getimagesize("$this->dir/out.jpg");
        $this->assertSame([20, 40], [$info[0], $info[1]]);
    }

    /** the photo is scaled before it is turned: $max_px still bounds the longer side, and the side on top is right */
    #[DataProvider('turns')]
    public function test_exif_orientation_of_a_shrunk_photo(int $orientation, array $top): void
    {
        // left half red, right half blue
        $img = imagecreatetruecolor(400, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 199, imagecolorallocate($img, 0, 0, 255));
        imagejpeg($img, $jpeg = "$this->dir/src.jpg", 95);

        $this->assertNull(Imgfunc::reencode($this->turned($jpeg, $orientation), "$this->dir/out.jpg", 100));
        $out = imagecreatefromjpeg("$this->dir/out.jpg");
        $this->assertSame([50, 100], [imagesx($out), imagesy($out)]);
        $rgb = imagecolorat($out, 25, 10);
        $this->assertSame($top, [($rgb >> 16) > 127, ($rgb & 0xFF) > 127], 'red, blue on top');
    }

    public static function turns(): array
    {
        return [
            'turned right (6)' => [6, [true, false]],
            'turned left (8)'  => [8, [false, true]],
        ];
    }

    public function test_gif_and_non_images_are_refused(): void
    {
        $img = imagecreatetruecolor(5, 5);
        imagegif($img, $gif = "$this->dir/a.gif");
        file_put_contents($txt = "$this->dir/a.txt", 'not an image');
        $this->assertNotNull(Imgfunc::reencode($gif, "$this->dir/out1", 2000));
        $this->assertNotNull(Imgfunc::reencode($txt, "$this->dir/out2", 2000));
        $this->assertFileDoesNotExist("$this->dir/out1");
        $this->assertFileDoesNotExist("$this->dir/out2");
    }

    public function test_avif_is_written_again_as_avif_where_gd_can(): void
    {
        if (!Imgfunc::avifSupported()) {
            $this->markTestSkipped('GD without AVIF');
        }
        $img = imagecreatetruecolor(30, 20);
        imageavif($img, $src = "$this->dir/a.avif");
        file_put_contents($src, 'TRAILER-XYZ', FILE_APPEND);
        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out.avif", 2000));
        $info = getimagesize("$this->dir/out.avif");
        $this->assertSame([30, 20, IMAGETYPE_AVIF], [$info[0], $info[1], $info[2]]);
        $this->assertStringNotContainsString('TRAILER-XYZ', (string) file_get_contents("$this->dir/out.avif"));
    }

    public function test_portable_gif_with_transparency_becomes_png(): void
    {
        $img = imagecreate(4, 4);
        imagecolorallocate($img, 255, 0, 0);
        $clear = imagecolorallocate($img, 0, 255, 0);
        imagecolortransparent($img, (int) $clear);
        imagefilledrectangle($img, 0, 0, 1, 3, (int) $clear);
        imagegif($img, $src = "$this->dir/a.gif");

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 2000, Imgfunc::REENCODE_MAX_PIXELS, true));
        $this->assertSame(IMAGETYPE_PNG, getimagesize("$this->dir/out")[2]);
        $out = imagecreatefrompng("$this->dir/out");
        $this->assertSame([127, 0], [(imagecolorat($out, 0, 0) >> 24) & 0x7F, (imagecolorat($out, 3, 0) >> 24) & 0x7F]);
    }

    public function test_portable_opaque_png_becomes_jpeg(): void
    {
        $img = imagecreatetruecolor(3000, 1500);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 200, 10));
        imagepng($img, $src = "$this->dir/a.png");
        file_put_contents($src, 'TRAILER-XYZ', FILE_APPEND);

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 1600, Imgfunc::REENCODE_MAX_PIXELS, true));
        $info = getimagesize("$this->dir/out");
        $this->assertSame([1600, 800, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        $this->assertStringNotContainsString('TRAILER-XYZ', (string) file_get_contents("$this->dir/out"));
    }

    public function test_portable_webp_with_alpha_becomes_png(): void
    {
        if (!(imagetypes() & IMG_WEBP)) {
            $this->markTestSkipped('GD without WebP');
        }
        $img = imagecreatetruecolor(20, 20);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagewebp($img, $src = "$this->dir/a.webp", 100);

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 2000, Imgfunc::REENCODE_MAX_PIXELS, true));
        $this->assertSame(IMAGETYPE_PNG, getimagesize("$this->dir/out")[2]);
    }

    public function test_portable_jpeg_stays_jpeg(): void
    {
        $this->assertNull(Imgfunc::reencode($this->jpeg(50, 40), "$this->dir/out", 2000, Imgfunc::REENCODE_MAX_PIXELS, true));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize("$this->dir/out")[2]);
    }

    /** a small file can declare a huge canvas; GD would allocate it all */
    public function test_a_decompression_bomb_is_refused_before_decoding(): void
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
        file_put_contents($src = "$this->dir/bomb.png", "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)));
        $this->assertNotNull(Imgfunc::reencode($src, "$this->dir/out.png", 2000));
        $this->assertFileDoesNotExist("$this->dir/out.png");
    }
}
