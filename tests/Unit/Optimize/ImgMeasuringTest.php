<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\ImgMeasuring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The code scan needs a live schema; reading {img} out of a template does not.
 *  The expressions below are taken from real templates (aadb_utf, 2026-09-17). */
final class ImgMeasuringTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m()
    }

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: list<string>}>}> */
    public static function templates(): array
    {
        return [
            'in an attribute' => [
                '<img loading=lazy src="{img:_#this:w=200&h=200&zc=1}" alt="_#HEADLINE">',
                [['{img:_#this:w=200&h=200&zc=1}', ['_#this', 'w=200&h=200&zc=1']]],
            ],
            'nested expressions are one argument' => [
                '{img:{img_upload......}:w=150&h=112&ar=x:imgb:{_#HEADLINE}}',
                [['{img:{img_upload......}:w=150&h=112&ar=x:imgb:{_#HEADLINE}}', ['{img_upload......}', 'w=150&h=112&ar=x', 'imgb', '{_#HEADLINE}']]],
            ],
            'escaped colon' => [
                '{img:x:w=1:img:Title#: subtitle}',
                [['{img:x:w=1:img:Title#: subtitle}', ['x', 'w=1', 'img', 'Title#: subtitle']]],
            ],
            'url keeps its colon' => [
                '{img:https://example.org/a.jpg:w=100&h=100&iar=1:img}',
                [['{img:https://example.org/a.jpg:w=100&h=100&iar=1:img}', ['https://example.org/a.jpg', 'w=100&h=100&iar=1', 'img']]],
            ],
            'two calls' => [
                'a {img:x:w=1} b {img:y::imgb}',
                [['{img:x:w=1}', ['x', 'w=1']], ['{img:y::imgb}', ['y', '', 'imgb']]],
            ],
            'nested call listed on its own' => [
                '{img:{img:x:w=10:url}:w=5:img}',
                [['{img:{img:x:w=10:url}:w=5:img}', ['{img:x:w=10:url}', 'w=5', 'img']], ['{img:x:w=10:url}', ['x', 'w=10', 'url']]],
            ],
            'unclosed is not a call' => ['{img:x:w=1', []],
            'unclosed does not hide a later call' => [
                "{img:{_#IMG_URL_}:w=100:img\n<p>text</p>\n{img:_#this:w=300:img}",
                [['{img:_#this:w=300:img}', ['_#this', 'w=300', 'img']]],
            ],
            'command name in capitals' => ['{IMG:x:w=1}', [['{IMG:x:w=1}', ['x', 'w=1']]]],
            'comment inside an argument' => ['{img:_#this:w=300:img{# measured}}', [['{img:_#this:w=300:img}', ['_#this', 'w=300', 'img']]]],
            'field reference is not a call' => ['{img_upload......}', []],
        ];
    }

    #[DataProvider('templates')]
    public function test_calls_are_split_like_the_expansion_splits_them(string $code, array $expected): void
    {
        $this->assertSame($expected, ImgMeasuring::calls($code));
    }

    /** @return array<string, array{0: string, 1: bool}> expression => measures */
    public static function expressions(): array
    {
        return [
            'url, the standard use'          => ['{img:_#this:w=200&h=200&zc=1}', false],
            'im'                             => ['{img:_#this:w=200:im:_#HEADLINE}', false],
            'size follows from parameters'   => ['{img:_#this:w=295&h=197&zc=1:imgb:_#HEADLINE:class="list-image"}', false],
            'picture with zoom crop'         => ['{img:_#this:w=700&h=700&zc=1:picture:_#HEADLINE}', false],
            'width with enlargement'         => ['{img:_#this:w=200&aoe=1:width}', false],
            'no parameters'                  => ['{img:{img_upload......}::imgb:_#HEADLINE}', true],
            'height only'                    => ['{img:_#this:h=98:imgb:Logo {_#HEADLINE}}', true],
            'auto rotation'                  => ['{img:_#this:w=40&h=40&zc=1&ar=x:picture:_#HEADLINE}', true],
            'format'                         => ['{img:_#this:w=200&h=200&zc=1:mime}', true],
            'computed parameters'            => ['{img:_#this:{ifeq:{x}:1:w=10:w=20}:img}', true],
            'computed info cannot be told'   => ['{img:_#this:w=1:{_#INFO____}}', false],
            'parameters on their own line'   => ["{img:_#this:\n  w=200&h=200&zc=1\n:img}", false],
            'line break inside parameters'   => ["{img:_#this:w=200&\nh=200&zc=1:img}", false],
        ];
    }

    #[DataProvider('expressions')]
    public function test_measuring_calls(string $expression, bool $measures): void
    {
        $found = ImgMeasuring::measuringCalls("<div>$expression</div>");
        $this->assertSame($measures ? [$expression] : [], array_column($found, 0));
        foreach ($found as [, $reason]) {
            $this->assertNotSame('', $reason);
        }
    }
}
