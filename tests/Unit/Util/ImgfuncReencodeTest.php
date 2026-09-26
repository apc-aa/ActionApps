<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Imgfunc;
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

    /** a phone photo taken upright is stored sideways plus EXIF Orientation 6 - without applying it the re-encoded image lies on its side */
    public function test_exif_orientation_is_applied_before_exif_is_dropped(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('exif is not loaded');
        }
        $jpeg = (string) file_get_contents($this->jpeg(40, 20));
        // APP1 Exif, big-endian TIFF, IFD0 with one entry: Orientation (0x0112), SHORT, 1, value 6
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        file_put_contents($src = "$this->dir/rot.jpg", substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
        $this->assertSame(6, exif_read_data($src)['Orientation'] ?? null, 'fixture carries the tag');

        $this->assertNull(Imgfunc::reencode($src, "$this->dir/out.jpg", 2000));
        $info = getimagesize("$this->dir/out.jpg");
        $this->assertSame([20, 40], [$info[0], $info[1]]);
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

    /** a small file can declare a huge canvas; GD would allocate it all */
    public function test_a_decompression_bomb_is_refused_before_decoding(): void
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
        file_put_contents($src = "$this->dir/bomb.png", "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)));
        $this->assertNotNull(Imgfunc::reencode($src, "$this->dir/out.png", 2000));
        $this->assertFileDoesNotExist("$this->dir/out.png");
    }
}
