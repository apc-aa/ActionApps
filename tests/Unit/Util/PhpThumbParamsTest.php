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

    /** @return array<string, array{0: array<string, string>, 1: ?array{0: int, 1: int}, 2: array<string, string>}> */
    public static function noEnlargeBoxes(): array
    {
        $crop = ['src' => '/up/p.jpg', 'w' => '1600', 'h' => '900', 'zc' => '1', 'aoe' => '0'];
        $iar  = ['src' => '/up/p.jpg', 'w' => '1600', 'h' => '900', 'iar' => '1', 'aoe' => '0'];
        return [
            'source big enough'          => [$crop, [4000, 3000], array_replace($crop, ['aoe' => '1'])],
            'source narrower'            => [$crop, [1000, 667], array_replace($crop, ['w' => '1000', 'h' => '563', 'aoe' => '1'])],
            'source lower'               => [$crop, [2000, 800], array_replace($crop, ['w' => '1422', 'h' => '800', 'aoe' => '1'])],
            'tiny source'                => [$crop, [15, 16], array_replace($crop, ['w' => '15', 'h' => '8', 'aoe' => '1'])],
            'stretched (iar) side by side' => [$iar, [1000, 667], array_replace($iar, ['w' => '1000', 'h' => '667', 'aoe' => '1'])],
            // {avatar}: the 2x of a 48 x 48 box from a 200 x 40 source must not drop to 40 x 40
            'iar keeps the longer side'  => [array_replace($iar, ['w' => '96', 'h' => '96']), [200, 40], array_replace($iar, ['w' => '96', 'h' => '40', 'aoe' => '1'])],
            'unknown source enlarges'    => [$crop, null, array_replace($crop, ['aoe' => '1'])],
        ];
    }

    /** aoe=0 with zc or iar: the box shrinks to the source in its own aspect ratio, then phpThumb gets aoe=1 to fill it exactly
     *  @param array<string, string> $get
     *  @param ?array{0: int, 1: int} $source
     *  @param array<string, string> $expected */
    #[DataProvider('noEnlargeBoxes')]
    public function test_no_enlarge_shrinks_the_box(array $get, ?array $source, array $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::noEnlarge($get, static fn(): ?array => $source));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function noEnlargeUntouched(): array
    {
        $crop = ['src' => '/up/p.jpg', 'w' => '1600', 'h' => '900', 'zc' => '1', 'aoe' => '0'];
        return [
            'enlarging allowed'       => [array_replace($crop, ['aoe' => '1'])],
            'aoe not given'           => [array_diff_key($crop, ['aoe' => 1])],
            'fitted into the box'     => [array_diff_key($crop, ['zc' => 1])],   // phpThumb itself never enlarges a fit
            'one dimension'           => [array_diff_key($crop, ['h' => 1])],
            'unpredictable size'      => [['fltr' => ['crop|5']] + $crop],
            'fixed aspect ratio'      => [['far' => 'C'] + array_diff_key($crop, ['zc' => 1])],
        ];
    }

    /** @param array<string, mixed> $get */
    #[DataProvider('noEnlargeUntouched')]
    public function test_no_enlarge_leaves_other_requests_alone_without_reading_the_source(array $get): void
    {
        $this->assertSame($get, PhpThumbParams::noEnlarge($get, fn(): never => $this->fail('the source size is read only when needed')));
    }

    /** @return array<string, array{0: string, 1: int, 2: array<int, string>}> */
    public static function srcsets(): array
    {
        return [
            'thumbnail: 1x, 1.5x, 2x' => ['w=343&h=193&zc=1', 343, [
                343 => 'w=343&h=193&zc=1',
                515 => 'w=515&h=290&zc=1&aoe=0&dpr=1.5',
                686 => 'w=686&h=386&zc=1&aoe=0&dpr=2',
            ]],
            'big image: 0.75x for phones' => ['w=1200&h=675&zc=1', 1200, [
                900  => 'w=900&h=506&zc=1&aoe=0',
                1200 => 'w=1200&h=675&zc=1',
                1800 => 'w=1800&h=1013&zc=1&aoe=0&dpr=1.5',
                2400 => 'w=2400&h=1350&zc=1&aoe=0&dpr=2',
            ]],
            'none wider than 2560' => ['w=1600&h=900&zc=1', 1600, [
                1200 => 'w=1200&h=675&zc=1&aoe=0',
                1600 => 'w=1600&h=900&zc=1',
                2400 => 'w=2400&h=1350&zc=1&aoe=0&dpr=1.5',
            ]],
            'quality of the template kept' => ['w=200&f=avif&q=70', 200, [
                200 => 'w=200&f=avif&q=70',
                300 => 'w=300&f=avif&q=70&aoe=0&dpr=1.5',
                400 => 'w=400&f=avif&q=70&aoe=0&dpr=2',
            ]],
            'aoe=1 of the template replaced' => ['w=200&amp;h=100&amp;zc=1&amp;aoe=1', 200, [
                200 => 'w=200&amp;h=100&amp;zc=1&amp;aoe=1',
                300 => 'w=300&amp;h=150&amp;zc=1&amp;aoe=0&amp;dpr=1.5',
                400 => 'w=400&amp;h=200&amp;zc=1&amp;aoe=0&amp;dpr=2',
            ]],
            'height only, width measured' => ['h=100', 150, [
                150 => 'h=100',
                225 => 'h=150&aoe=0&dpr=1.5',
                300 => 'h=200&aoe=0&dpr=2',
            ]],
            'no size asked'             => ['f=webp', 640, []],
            'zc with one dimension'     => ['h=100&zc=1', 100, []],
            'fixed aspect ratio'        => ['w=200&h=100&far=C', 200, []],
            'filter'                    => ['w=200&h=100&zc=1&fltr[]=usm|80|0.5|3', 200, []],
            'auto rotation'             => ['w=200&h=100&zc=1&ar=x', 200, []],
            'unknown width'             => ['w=200&h=100&zc=1', 0, []],
        ];
    }

    /** @param array<int, string> $expected */
    #[DataProvider('srcsets')]
    public function test_srcset_candidates(string $params, int $width, array $expected): void
    {
        $this->assertSame($expected, PhpThumbParams::srcsetCandidates($params, $width));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?array{0: int, 1: int}, 2: ?string}> */
    public static function densities(): array
    {
        $crop = ['src' => '/up/p.jpg', 'w' => '1600', 'h' => '900', 'zc' => '1', 'aoe' => '0', 'dpr' => '2'];
        $fit  = ['src' => '/up/p.jpg', 'w' => '600', 'h' => '600', 'aoe' => '0', 'dpr' => '2'];
        $big  = [4000, 3000];
        return [
            '2x'                              => [$crop, $big, '80'],
            '1.5x'                            => [['dpr' => '1.5'] + $crop, $big, '85'],
            'avif 2x'                         => [['f' => 'avif'] + $crop, $big, '65'],
            'avif 1.5x'                       => [['f' => 'AVIF', 'dpr' => '1.5'] + $crop, $big, '74'],
            'higher q of the template'        => [['q' => '95', 'dpr' => '1.5'] + $crop, $big, '88'],
            'lower q of the template kept'    => [['q' => '60'] + $crop, $big, '60'],
            'png keeps its compression'       => [['f' => 'png'] + $crop, $big, null],
            'unknown source'                  => [$crop, null, '80'],
            // the box shrinks to 1200 x 675, 1.5 times the 1x image
            'source between 1x and 2x'        => [$crop, [1200, 900], '85'],
            // the box shrinks to 800 x 450 - the very pixels of the 1x image
            'source no larger than 1x'        => [$crop, [800, 600], null],
            'fit filled by one side'          => [$fit, [400, 1000], '80'],
            'fit smaller than the box'        => [$fit, [200, 300], null],
            'fit by width'                    => [['src' => '/up/p.jpg', 'w' => '400', 'aoe' => '0', 'dpr' => '2'], [200, 100], null],
        ];
    }

    /** a denser candidate gets a lower quality - as much lower as the pixels its source really has; dpr never reaches phpThumb
     *  @param array<string, mixed> $get
     *  @param ?array{0: int, 1: int} $source */
    #[DataProvider('densities')]
    public function test_density_quality_follows_the_pixels_of_the_source(array $get, ?array $source, ?string $q): void
    {
        $expected = array_diff_key($get, ['dpr' => 1]);
        if ($q !== null) {
            $expected['q'] = $q;
        }
        $this->assertSame($expected, PhpThumbParams::densityQuality($get, static fn(): ?array => $source));
    }

    /** @return array<string, array{0: mixed}> */
    public static function foreignDensities(): array
    {
        return ['none' => [null], 'not a number' => ['x'], 'an array' => [['2']], 'infinite' => ['1e999'], 'another spelling' => ['2.0'], 'denser' => ['3']];
    }

    /** a dpr srcsetCandidates() never writes stays for phpThumb to refuse - no cache url gets a free field */
    #[DataProvider('foreignDensities')]
    public function test_density_quality_leaves_other_requests_alone_without_reading_the_source(mixed $dpr): void
    {
        $get = ['src' => '/up/p.jpg', 'w' => '1600', 'h' => '900', 'zc' => '1', 'q' => '70'] + (($dpr === null) ? [] : ['dpr' => $dpr]);
        $this->assertSame($get, PhpThumbParams::densityQuality($get, fn(): never => $this->fail('the source size is read only when needed')));
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
