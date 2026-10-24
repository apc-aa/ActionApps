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

    private const NAVY = 0x003366;

    /** red at x 0-9, transparent black at x 10-19, of $w x $h */
    private function halfTransparent(int $w = 20, int $h = 10): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, (int) imagecolorallocate($img, 255, 0, 0));
        imagepng($img, $src = "$this->dir/half.png");
        return $src;
    }

    /** 0xRRGGBB of the pixel at $x, $y of the image $file, rounded to 16 levels a channel against JPEG noise */
    private static function colourAt(string $file, int $x, int $y): int
    {
        $rgb   = imagecolorat(imagecreatefromstring((string) file_get_contents($file)), $x, $y);
        $level = static fn(int $shift): int => ((int) round((($rgb >> $shift) & 0xFF) / 17) * 17) << $shift;
        return $level(16) | $level(8) | $level(0);
    }

    public function test_flattened_small_png_stays_sharp_png_without_alpha(): void
    {
        $this->assertNull(Imgfunc::reencode($this->halfTransparent(), "$this->dir/out", 2000, flatten_on: self::NAVY));
        $this->assertSame([20, 10, IMAGETYPE_PNG], array_slice((array) getimagesize("$this->dir/out"), 0, 3));
        $out = imagecreatefrompng("$this->dir/out");
        $this->assertSame([0xFF0000, self::NAVY], [imagecolorat($out, 2, 5), imagecolorat($out, 17, 5)], 'red stays, transparency becomes the colour');
        $this->assertSame(0, (imagecolorat($out, 17, 5) >> 24) & 0x7F, 'no alpha left');
    }

    public function test_flattened_big_png_becomes_jpeg_on_the_colour(): void
    {
        file_put_contents($src = $this->halfTransparent(3000, 1500), 'TRAILER-XYZ', FILE_APPEND);

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 1600, flatten_on: self::NAVY));
        $this->assertSame([1600, 800, IMAGETYPE_JPEG], array_slice((array) getimagesize("$this->dir/out"), 0, 3));
        $this->assertSame([0xFF0000, self::NAVY], [self::colourAt("$this->dir/out", 100, 400), self::colourAt("$this->dir/out", 1500, 400)]);
        $this->assertStringNotContainsString('TRAILER-XYZ', (string) file_get_contents("$this->dir/out"));
    }

    /** a photo is many times bigger as PNG than as JPEG: small or not, it is written as JPEG */
    public function test_flattened_small_photo_becomes_jpeg(): void
    {
        $img = imagecreatetruecolor(100, 100);
        for ($y = 0; $y < 100; $y++) {
            for ($x = 0; $x < 100; $x++) {
                imagesetpixel($img, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        imagepng($img, $src = "$this->dir/a.png");

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 2000, flatten_on: 0xFFFFFF));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize("$this->dir/out")[2]);
    }

    public function test_flattened_gif_keeps_its_colours_on_the_colour(): void
    {
        $img = imagecreate(20, 10);
        imagecolorallocate($img, 255, 0, 0);
        $clear = imagecolorallocate($img, 0, 0, 0);
        imagecolortransparent($img, (int) $clear);
        imagefilledrectangle($img, 10, 0, 19, 9, (int) $clear);
        imagegif($img, $src = "$this->dir/a.gif");

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 2000, flatten_on: self::NAVY));
        $this->assertSame([0xFF0000, self::NAVY], [self::colourAt("$this->dir/out", 2, 5), self::colourAt("$this->dir/out", 17, 5)]);
    }

    public function test_flattened_webp_with_alpha_is_taken(): void
    {
        if (!(imagetypes() & IMG_WEBP)) {
            $this->markTestSkipped('GD without WebP');
        }
        $img = imagecreatetruecolor(20, 20);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagewebp($img, $src = "$this->dir/a.webp", 100);

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out", 2000, flatten_on: self::NAVY));
        $this->assertSame(self::NAVY, self::colourAt("$this->dir/out", 10, 10));
    }

    public function test_flattened_jpeg_stays_jpeg(): void
    {
        $this->assertNull(Imgfunc::reencode($this->jpeg(50, 40), "$this->dir/out", 2000, flatten_on: 0xFFFFFF));
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
