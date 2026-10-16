<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** banner-<position>-<view>-[<weight field>|norandom] comes from the URL and becomes the parameters of a view */
final class ViewBannerParamTest extends TestCase
{
    /** @return array<string, array{string, int, array<string, mixed>}> banner, position, the banner view's parameters */
    public static function validBanners(): array
    {
        return [
            'weight 1'      => ['2-15', 2, ['vid' => '15', 'set' => ['15' => 'random-1']]],
            'empty field'   => ['2-15-', 2, ['vid' => '15', 'set' => ['15' => 'random-1']]],
            'digits'        => ['0-15-3', 0, ['vid' => '15', 'set' => ['15' => 'random-3']]],
            'weight field'  => ['1-15-hit_7_days......', 1, ['vid' => '15', 'set' => ['15' => 'random-hit_7_days......']]],
            'norandom'      => ['1-15-norandom', 1, ['vid' => '15']],
            'named view'    => ['1-news_box', 1, ['vid' => 'news_box', 'set' => ['news_box' => 'random-1']]],
        ];
    }

    /** the same parameters as the string the banner was written into before */
    #[DataProvider('validBanners')]
    public function test_parseBannerParam_validBanner_givesTheViewParameters(string $banner, int $position, array $expected): void
    {
        $banners = AA_View::parseBannerParam($banner)['banners'];
        parse_str($banners[$position], $params);

        $this->assertSame($expected, $params);
    }

    /** @return array<string, array{string}> */
    public static function injectedBanners(): array
    {
        return [
            'more in the view'  => ['0-15&set[15]=slices-0123456789abcdef0123456789abcdef-norandom'],
            'more in the field' => ['0-15-1&cmd[15][]=x-15-12'],
            'bracket in view'   => ['0-15]-1'],
            'short field'       => ['0-15-headline'],
            'no position'       => ['x-15-1'],
        ];
    }

    #[DataProvider('injectedBanners')]
    public function test_parseBannerParam_anythingElse_isNoBanner(string $banner): void
    {
        $this->assertSame([], AA_View::parseBannerParam($banner));
    }

    public function test_parseBannerParam_listOfBanners_keepsEachPosition(): void
    {
        $this->assertSame([2, 5], array_keys(AA_View::parseBannerParam(['2-15', '5-16-norandom'])['banners']));
    }
}
