<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\PhpThumbParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The size rules were measured against the live img.php on 4 source shapes (portrait,
 *  landscape, square, tiny), both enlarging and shrinking - misc/ZAVERY 2026-07-31. */
final class PhpThumbParamsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array{0: ?int, 1: ?int}}> */
    public static function pinnedSizes(): array
    {
        return [
            'zoom crop, aoe added by {img}' => ['w=200&h=100&zc=1', [200, 100]],
            'zoom crop with aoe=1'          => ['w=200&h=100&zc=1&aoe=1', [200, 100]],
            'zoom crop, aoe=2 is not 1'     => ['w=200&h=100&zc=1&aoe=2', [null, null]],   // ImageMagick tests aoe == 1 literally
            'zoom crop, enlarging refused'  => ['w=200&h=100&zc=1&aoe=0', [null, null]],
            'ignore aspect ratio'           => ['w=200&h=100&iar=1', [200, 100]],
            'fixed aspect ratio'            => ['w=200&h=100&far=C', [200, 100]],
            'fitted into the box'           => ['w=200&h=100', [null, null]],
            'one dimension'                 => ['w=200', [null, null]],
            'width, enlarging allowed'      => ['w=200&aoe=1', [200, null]],
            'height, enlarging allowed'     => ['h=100&aoe=1', [null, 100]],
            'zc turns one into a square'    => ['h=100&zc=1&aoe=1', [null, null]],        // 50x50 for a 106x50 source
            'neutral parameters'            => ['w=200&h=100&zc=1&f=webp&q=80', [200, 100]],
            'html escaped separator'        => ['w=200&amp;h=100&amp;zc=1', [200, 100]],
            'zero width'                    => ['w=0&h=100&iar=1', [null, null]],
            'orientation dependent width'   => ['w=200&h=100&zc=1&wp=300', [null, null]],
            'source crop'                   => ['w=200&h=100&iar=1&sx=10', [null, null]],
            'filter'                        => ['w=200&h=100&zc=1&fltr[]=crop|5', [null, null]],
            'rotation'                      => ['w=200&h=100&far=1&ra=90', [null, null]],
        ];
    }

    #[DataProvider('pinnedSizes')]
    public function test_pinned_size(string $params, array $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::pinnedSize($params));
    }

    /** @return array<string, array{0: string, 1: string, 2: ?array}> */
    public static function knownSizes(): array
    {
        return [
            'img, pinned'                 => ['img', 'w=200&h=100&zc=1', [200, 100]],
            'img, not pinned'             => ['img', 'w=200', null],
            'picture, pinned'             => ['picture', 'w=200&h=100&iar=1', [200, 100]],
            'width needs only the width'  => ['width', 'w=200&aoe=1', [200, null]],
            'height of a width-only pin'  => ['height', 'w=200&aoe=1', null],
            'hw needs both'               => ['hw', 'w=200&aoe=1', null],
            'imgtype always measures'     => ['imgtype', 'w=200&h=100&zc=1', null],
            'mime always measures'        => ['mime', 'w=200&h=100&zc=1', null],
        ];
    }

    #[DataProvider('knownSizes')]
    public function test_known_size(string $info, string $params, ?array $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::knownSize($info, $params));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function zoomCrops(): array
    {
        return [
            'added'                  => ['w=200&h=100&zc=1', 'w=200&h=100&zc=1&aoe=1'],
            'escaped separator kept' => ['w=200&amp;h=100&amp;zc=1', 'w=200&amp;h=100&amp;zc=1&amp;aoe=1'],
            'idempotent'             => ['w=200&h=100&zc=1&aoe=1', 'w=200&h=100&zc=1&aoe=1'],
            'explicit aoe respected' => ['w=200&h=100&zc=1&aoe=2', 'w=200&h=100&zc=1&aoe=2'],
            'no zoom crop'           => ['w=200&h=100', 'w=200&h=100'],
            'one dimension only'     => ['w=200&zc=1', 'w=200&zc=1'],
        ];
    }

    #[DataProvider('zoomCrops')]
    public function test_add_aoe_for_zoom_crop(string $params, string $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::addAoe4ZoomCrop($params));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function formats(): array
    {
        return [
            'appended'             => ['w=150&h=99', 'webp', 'w=150&h=99&f=webp'],
            'replaced'             => ['w=150&f=png&h=99', 'webp', 'w=150&f=webp&h=99'],
            'replaced at the end'  => ['w=150&f=png', 'webp', 'w=150&f=webp'],
            'escaped separator'    => ['w=150&amp;h=99', 'webp', 'w=150&amp;h=99&amp;f=webp'],
            'escaped and replaced' => ['w=150&amp;f=png', 'webp', 'w=150&amp;f=webp'],
            'webp keeps its q'     => ['w=150&q=90', 'webp', 'w=150&q=90&f=webp'],
        ];
    }

    #[DataProvider('formats')]
    public function test_set_format_keeps_one_output_format_and_the_separator(string $params, string $format, string $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::setFormat($params, $format));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function avifQualities(): array
    {
        return [
            'default q added'        => ['w=150&h=99', 'w=150&h=99&f=avif&q=83'],
            'higher q lowered'       => ['w=150&q=90&h=99', 'w=150&q=83&h=99&f=avif'],
            'lower q kept'           => ['w=150&q=60', 'w=150&q=60&f=avif'],
            'empty q replaced'       => ['w=150&q=', 'w=150&q=83&f=avif'],
            'escaped and replaced'   => ['w=150&amp;f=png', 'w=150&amp;f=avif&amp;q=83'],
            'q-like name untouched'  => ['w=150&sq=90', 'w=150&sq=90&f=avif&q=83'],
            'q first'                => ['q=95&w=1', 'q=83&w=1&f=avif'],
            'every q replaced'       => ['w=1&q=40&q=95', 'w=1&q=83&q=83&f=avif'],
            'last lower q kept'      => ['w=1&q=95&q=40', 'w=1&q=95&q=40&f=avif'],
            'non-numeric q'          => ['w=1&q=abc', 'w=1&q=83&f=avif'],
            'spaced name appended'   => ['w=1& q=95', 'w=1& q=95&f=avif&q=83'],
            'encoded name appended'  => ['w=1&%71=95', 'w=1&%71=95&f=avif&q=83'],
        ];
    }

    #[DataProvider('avifQualities')]
    public function test_set_format_caps_avif_quality(string $params, string $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::setFormat($params, 'avif'));
    }

    public function test_clean_drops_control_bytes_only(): void
    {
        $this->assertSame('w=300&h=200&zc=1', PhpThumbParams::clean("w=300&h=200\r\n\t&zc=1\x00\x7F"));
        $this->assertSame('w=300&fltr[]=wmt|Ahoj světe', PhpThumbParams::clean('w=300&fltr[]=wmt|Ahoj světe'));
    }

    public function test_parse_reads_either_separator(): void
    {
        $this->assertSame(['w' => '150', 'h' => '99'], PhpThumbParams::parse('w=150&amp;h=99'));
        $this->assertSame(['w' => '150', 'h' => '99'], PhpThumbParams::parse('w=150&h=99'));
    }
}
