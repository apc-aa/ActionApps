<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\LazyLoad;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LazyLoad keeps browsers from downloading images nobody sees yet - the hidden
 * {rotator} frames - while letting a template mark its hero image eager.
 */
final class LazyLoadTest extends TestCase
{
    #[DataProvider('markups')]
    public function test_markup_makes_every_image_and_iframe_lazy(string $html, string $expected): void
    {
        self::assertSame($expected, LazyLoad::markup($html));
    }

    /** @return array<string, array{string, string}> */
    public static function markups(): array
    {
        return [
            'img'                          => ['<img src="a.jpg">', '<img loading=lazy src="a.jpg">'],
            'iframe'                       => ['<iframe src="v"></iframe>', '<iframe loading=lazy src="v"></iframe>'],
            'uppercase tag'                => ['<IMG SRC=a.jpg>', '<IMG loading=lazy SRC=a.jpg>'],
            'every tag in the frame'       => ['<p><img src=a><img src=b></p>', '<p><img loading=lazy src=a><img loading=lazy src=b></p>'],
            'inside a quoted JS string'    => ['document.write("<iframe src=v>")', 'document.write("<iframe loading=lazy src=v>")'],
            'bare and self-closing'        => ['<img><img />', '<img loading=lazy><img loading=lazy />'],
            // in <b x=a<img/onerror=...> the space would turn part of b's value into an onerror attribute
            "'/' right after the name"     => ['<img/src=a><b x=a<img/onerror=f()>', '<img/src=a><b x=a<img/onerror=f()>'],
            // the first attribute wins, and nothing is removed - no quote or escape of the markup changes
            'own eager comes second'       => ['<img src=a loading=eager>', '<img loading=lazy src=a loading=eager>'],
            'own lazy not doubled'         => ['<img loading="lazy" src=a><img src=b LOADING = \'Lazy\'>', '<img loading="lazy" src=a><img src=b LOADING = \'Lazy\'>'],
            'data-loading is not loading'  => ['<img data-loading=lazy src=a>', '<img loading=lazy data-loading=lazy src=a>'],
            'text in a value is not loading' => ['<img alt="x loading=lazy" src=a>', '<img loading=lazy alt="x loading=lazy" src=a>'],
            "'/' in an unquoted value"     => ['<img src=/img/loading=lazy.jpg>', '<img loading=lazy src=/img/loading=lazy.jpg>'],
            "'>' in a quoted value"        => ['<img alt="a > b" src=x loading=lazy>', '<img alt="a > b" src=x loading=lazy>'],
            "'<' in a quoted value"        => ['<iframe srcdoc="<p>x</p>" data-x=\'<img src=b>\'>', '<iframe loading=lazy srcdoc="<p>x</p>" data-x=\'<img src=b>\'>'],
            'no space after a quoted value' => ['<img class="a"loading=lazy src=x>', '<img class="a"loading=lazy src=x>'],
            'apostrophe in an unquoted value' => ["<img alt=Tom's loading=lazy src=x>", "<img alt=Tom's loading=lazy src=x>"],
            'escaped quotes in a JS string' => ['show("<img src=x loading=\\"eager\\">")', 'show("<img loading=lazy src=x loading=\\"eager\\">")'],
            'other tags untouched'         => ['<picture><source srcset=a></picture><imgx>', '<picture><source srcset=a></picture><imgx>'],
            'plain text'                   => ['no images', 'no images'],
        ];
    }

    /** An unclosed '<img' must not make the scan quadratic - the replace would then hit PCRE's backtrack limit. */
    public function test_markup_handles_unclosed_tags_within_a_tight_backtrack_limit(): void
    {
        $limit = ini_set('pcre.backtrack_limit', '1000');
        try {
            self::assertSame(
                str_repeat('<img ', 5000) . '<img loading=lazy src=a>',
                LazyLoad::markup(str_repeat('<img ', 5000) . '<img src=a>'),
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    /** Nothing is scanned twice, so hostile markup costs linear time even without the PCRE JIT. */
    #[DataProvider('hostileMarkups')]
    public function test_markup_stays_linear_without_jit(string $html): void
    {
        $jit = ini_set('pcre.jit', '0');
        try {
            $start = hrtime(true);
            LazyLoad::markup($html);
            LazyLoad::attr($html);
            self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);   // ~10 ms linear, seconds quadratic
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }
    }

    /** @return array<string, array{string}> */
    public static function hostileMarkups(): array
    {
        return [
            'spaces in a tag'         => ['<img ' . str_repeat(' ', 100000) . '/'],
            'spaces before loading'   => [str_repeat(' ', 100000) . '/loading'],
            'unclosed tags'           => [str_repeat('<img ', 20000)],
            'unquoted values'         => [str_repeat('<img/x=', 20000)],
            'unclosed quoted values'  => [str_repeat('<img a="', 10000) . str_repeat('<img b=\'', 10000)],
            'stray equals signs'      => ['<img ' . str_repeat('=', 100000) . ' loading'],
        ];
    }

    #[DataProvider('attrs')]
    public function test_attr_is_lazy_unless_the_attributes_set_loading(string $attrs, string $expected): void
    {
        self::assertSame($expected, LazyLoad::attr($attrs));
    }

    /** @return array<string, array{string, string}> */
    public static function attrs(): array
    {
        return [
            'none'                  => ['', 'loading=lazy'],
            'other attributes'      => ['class="big" width=300', 'loading=lazy'],
            'eager'                 => ['loading=eager', ''],
            'eager among others'    => ['class=x loading="eager" fetchpriority=high', ''],
            'uppercase with spaces' => ['LOADING = eager', ''],
            'data-loading'          => ['data-loading=eager', 'loading=lazy'],
            'loading in a value'    => ['alt="Page loading = slow" title=\'x loading=1\'', 'loading=lazy'],
            'loading in a URL path' => ['data-src=/p/loading=1', 'loading=lazy'],
            'after a quoted value'  => ['class="hero"loading=eager', ''],
            'after an apostrophe'   => ["alt=Tom's loading=eager", ''],
            'name starting with ='  => ['=loading=eager', 'loading=lazy'],
        ];
    }

    #[DataProvider('lazies')]
    public function test_is_lazy_unless_the_attributes_choose_another_loading(string $attrs, bool $expected): void
    {
        self::assertSame($expected, LazyLoad::isLazy($attrs));
    }

    /** @return array<string, array{string, bool}> */
    public static function lazies(): array
    {
        return [
            'none'           => ['class=x', true],
            'lazy'           => ['LOADING="Lazy"', true],
            'eager'          => ['loading=eager fetchpriority=high', false],
            'auto'           => ['loading=auto', false],
            'in a value'     => ['alt="loading=eager"', true],
        ];
    }

    #[DataProvider('attributes')]
    public function test_attribute_reads_the_first_value_unquoted(string $attrs, string $name, ?string $expected): void
    {
        self::assertSame($expected, LazyLoad::attribute($attrs, $name));
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function attributes(): array
    {
        return [
            'double quoted'      => ['class=x sizes="(max-width: 600px) 50vw, 300px"', 'sizes', '(max-width: 600px) 50vw, 300px'],
            'single quoted'      => ["SIZES='100vw'", 'sizes', '100vw'],
            'unquoted, first'    => ['sizes=50vw sizes=100vw', 'sizes', '50vw'],
            'without value'      => ['srcset', 'srcset', ''],
            'missing'            => ['alt="sizes=100vw"', 'sizes', null],
            'longer name'        => ['data-sizes=100vw', 'sizes', null],
        ];
    }
}
