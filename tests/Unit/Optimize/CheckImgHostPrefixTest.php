<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckImgHostPrefix;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckImgHostPrefixTest extends TestCase
{
    private const SITE  = '0123456789abcdef0123456789abcdef';
    private const SLICE = 'fedcba9876543210fedcba9876543210';

    private const RSS_IMG = '<![CDATA[<img src="https://vozejkov.cz{img:{img_upload......}:w=330}" width="330">]]>';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function prefixProvider(): array
    {
        return [
            'https host'               => [self::RSS_IMG, ['https://vozejkov.cz']],
            'escaped colon'            => ['<img src="https#://vozejkov.cz{img:{img_upload......}:w=330}">', ['https#://vozejkov.cz']],
            'http'                     => ['http://www.example.org{img:{x}:w=10}', ['http://www.example.org']],
            'trailing slash'           => ['https://example.org/{img:{x}:w=10}', ['https://example.org/']],
            'port'                     => ['https://example.org:8080{img:{x}}', ['https://example.org:8080']],
            'scheme-relative'          => ['src="//example.org{img:{x}}"', ['//example.org']],
            'og:image'                 => ['f__:https://rcpodebrady.cz{img:{ifset:_#this:{a}:{b}}:w=1200&h=630&zc=1&ar=x}', ['https://rcpodebrady.cz']],
            'two places'               => ['https://a.cz{img:{x}} https#://b.cz{img:{y}}', ['https://a.cz', 'https#://b.cz']],
            'same place twice'         => ['https://a.cz{img:{x}} https://a.cz{img:{y}}', ['https://a.cz']],
            'bare {img}'               => ['<img src="{img:{img_upload......}:w=600}">', []],
            'size between two {img}'   => ['{img:{x}::width}x{img:{x}::height}', []],
            'path in front'            => ['https://example.org/images/{img:{x}}', ['https://example.org/images/']],
            'other command in front'   => ['https://example.org{_#SEO_URL_}{img:{x}}', []],
            'AA comment'               => ['{#<img src="https://old.cz{img:{x}}">}', []],
            'unclosed AA comment'      => ['{#<img src="https://old.cz{img:{x}}">', ['https://old.cz']],
            'upper case scheme'        => ['HTTPS://Example.ORG{img:{x}}', ['HTTPS://Example.ORG']],
            'share link'               => ['<a href="https://pinterest.com/pin/create/button/?media={img:{x}:w=600}">', []],
            'facebook sharer'          => ['https://www.facebook.com/sharer/sharer.php?u={img:{x}}', []],
            'own host by {server}'     => ['https://{server:HTTP_HOST}{img:{x}:w=10}', ['https://{server:HTTP_HOST}']],
            'width'                    => ['https://placehold.co/{img:{x}::width}x300', []],
            'whole tag'                => ['https://a.cz{img:{x}:w=10:img}', []],
            'rawurl'                   => ['https://a.cz{img:{x}:w=10:rawurl}', ['https://a.cz']],
            'cache'                    => ['https://a.cz{img:{x}:w=10:cache}', ['https://a.cz']],
            'computed info'            => ['https://a.cz{img:{x}:w=10:{_#INFO____}}', []],
            'word before the scheme'   => ['_#LINKhttps://site.cz{img:{x}}', ['https://site.cz']],
            'unclosed {img}'           => ['https://a.cz{img:{x}', []],
            'call over 500 characters' => ['https://a.cz{img:{ifset:{x}:' . str_repeat('y', 600) . ':z}:w=10}', []],   // a limit, not a wish
            'address over 300 chars'   => ['https://' . str_repeat('a', 310) . '{img:{x}}', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('prefixProvider')]
    public function test_prefixes_finds_a_host_written_before_img(string $code, array $expected): void
    {
        $this->assertSame($expected, CheckImgHostPrefix::prefixes($code));
    }

    public function test_nothing_found_is_ok(): void
    {
        $findings = CheckImgHostPrefix::findingsFor([], []);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_a_host_prefix_is_a_warning_naming_the_module(): void
    {
        [$finding] = CheckImgHostPrefix::findingsFor([self::row(self::SLICE, self::RSS_IMG, '<a href="/field">_#FOTOLRSS</a>')], [self::SLICE => 'vozejkov.cz - fotky']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('vozejkov.cz - fotky', $finding->title);
        $this->assertSame(self::SLICE, $finding->key);
        $this->assertSame(1, $finding->count);
        $this->assertSame('<a href="/field">_#FOTOLRSS</a>', $finding->evidence[0][_m('Where')]);
        $this->assertSame('https://vozejkov.cz', $finding->evidence[0][_m('Written before {img}')]);
        $this->assertStringContainsString('{img:', $finding->advice);
    }

    public function test_evidence_is_escaped(): void
    {
        [$finding] = CheckImgHostPrefix::findingsFor([self::row(self::SLICE, 'https://{server:"&"}{img:{x}}')], [self::SLICE => 'News']);
        $this->assertSame('https://{server:&quot;&amp;&quot;}', $finding->evidence[0][_m('Written before {img}')]);
    }

    public function test_places_of_one_module_are_one_finding(): void
    {
        $rows = [self::row(self::SITE, 'https://a.cz{img:{x}}', 'spot 4'), self::row(self::SITE, 'https#://a.cz{img:{y}}', 'spot 8'), self::row(self::SLICE, 'https://b.cz{img:{z}}')];
        $findings = CheckImgHostPrefix::findingsFor($rows, [self::SITE => 'example.org', self::SLICE => 'News']);
        $this->assertCount(2, $findings);
        $by_key = array_column(array_map(static fn(Finding $f): array => ['key' => $f->key, 'count' => $f->count], $findings), 'count', 'key');
        $this->assertSame([self::SITE => 2, self::SLICE => 1], $by_key + [self::SITE => 0, self::SLICE => 0]);
    }

    public function test_rows_without_a_host_prefix_are_left_out(): void
    {
        $findings = CheckImgHostPrefix::findingsFor([self::row(self::SITE, '<img src="{img:{x}:w=10}">')], [self::SITE => 'example.org']);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_a_module_without_a_name_is_left_out(): void
    {
        // deleted modules are not served - findings() passes live modules only
        $findings = CheckImgHostPrefix::findingsFor([self::row(self::SITE, self::RSS_IMG)], []);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    /** @return array{module: string, where: string, txt: string} */
    private static function row(string $module, string $txt, string $where = 'spot'): array
    {
        return ['module' => $module, 'where' => $where, 'txt' => $txt];
    }
}
