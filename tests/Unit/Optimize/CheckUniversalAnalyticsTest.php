<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckUniversalAnalytics;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckUniversalAnalyticsTest extends TestCase
{
    private const SITE  = '0123456789abcdef0123456789abcdef';
    private const SLICE = 'fedcba9876543210fedcba9876543210';

    private const GA_JS = '<script>var pageTracker = _gat._getTracker("UA-12254263-1"); pageTracker._trackPageview();</script>';
    private const GTAG_UA = "<script async src=\"https://www.googletagmanager.com/gtag/js?id=UA-12254254-1\"></script>\n<script>gtag('js', new Date()); gtag('config', 'UA-12254254-1');</script>";
    private const GAQ = "<script>var _gaq = _gaq || []; _gaq.push(['_setAccount', 'UA-12254254-1']); _gaq.push(['_trackPageview']);</script>";
    private const GTAG_G4 = "<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-6H7D0HCDXV\"></script>\n<script>gtag('config', 'G-6H7D0HCDXV');</script>";

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function uaProvider(): array
    {
        return [
            'ga.js'                      => [self::GA_JS, ['UA-12254263-1']],
            'gtag, the id twice'         => [self::GTAG_UA, ['UA-12254254-1']],
            '_gaq'                       => [self::GAQ, ['UA-12254254-1']],
            'analytics.js'               => ["ga('create', 'UA-1234567-12', 'auto');", ['UA-1234567-12']],
            'two properties'             => ["ga('create', 'UA-1234567-1'); ga('create', 'UA-7654321-2');", ['UA-1234567-1', 'UA-7654321-2']],
            'x-ua-compatible'            => ['<meta http-equiv="x-ua-compatible" content="ie=edge">', []],
            'X-UA-Compatible'            => ['<meta http-equiv="X-UA-Compatible" content="IE=edge">', []],
            'lower case'                 => ['ua-1234567-1', []],
            'part of a word'             => ['XUA-1234567-1', []],
            'AA comment'                 => ['{#<!-- old id UA-26764597-1 -->}', []],
            'AA comment with braces'     => ["{#<script>function gtag(){dataLayer.push(arguments);} gtag('config', 'UA-26764597-1');</script>}", []],
            'unclosed AA comment counts' => ["{#<script>gtag('config', 'UA-26764597-1');</script>", ['UA-26764597-1']],
            'after a comment'            => ["{# old }gtag('config', 'UA-26764597-1');", ['UA-26764597-1']],
            'comment with an empty {}'   => ["{#<script>var o = {}; ga('create', 'UA-1234567-1');</script>}", ['UA-1234567-1']],
            'comment after an empty {}'  => ["var o = {}; {# UA-1234567-1 }", []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('uaProvider')]
    public function test_uaIds_finds_universal_analytics_ids(string $code, array $expected): void
    {
        $this->assertSame($expected, CheckUniversalAnalytics::uaIds($code));
    }

    public function test_unclosed_comments_cost_linear_time(): void
    {
        // many unclosed {# with one } at the end - a regex for nested braces backtracks quadratically on it
        $code    = str_repeat('{#aaa', 20000) . "} gtag('config', 'UA-1234567-1');";
        $started = microtime(true);
        $this->assertSame(['UA-1234567-1'], CheckUniversalAnalytics::uaIds($code));
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function newProvider(): array
    {
        return [
            'gtag GA4'             => [self::GTAG_G4, ['G-6H7D0HCDXV']],
            'site setting'         => ['G-W94ZJXRMYL', ['G-W94ZJXRMYL']],
            'Tag Manager'          => ["(window,document,'script','dataLayer','GTM-ABC123');", ['GTM-ABC123']],
            'image name'           => ['<img src="IMG-ABCDEF1.jpg">', []],
            'universal analytics'  => [self::GTAG_UA, []],
            'AA comment'           => ["{#gtag('config', 'G-6H7D0HCDXV');}", []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('newProvider')]
    public function test_newIds_finds_ga4_and_tag_manager_ids(string $code, array $expected): void
    {
        $this->assertSame($expected, CheckUniversalAnalytics::newIds($code));
    }

    public function test_nothing_found_is_ok(): void
    {
        $findings = CheckUniversalAnalytics::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_x_ua_compatible_alone_is_ok(): void
    {
        $findings = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, '<meta http-equiv="x-ua-compatible" content="ie=edge">')], [self::SITE => 'example.org']);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_only_ua_is_a_warning_naming_the_site(): void
    {
        [$finding] = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, self::GA_JS, '<a href="/spot">4</a>')], [self::SITE => 'example.org']);
        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('example.org', $finding->title);
        $this->assertSame(self::SITE, $finding->key);
        $this->assertSame(1, $finding->count);
        $this->assertSame('<a href="/spot">4</a>', $finding->evidence[0][_m('Where')]);
        $this->assertSame('UA-12254263-1', $finding->evidence[0][_m('Old id (UA)')]);
        $this->assertSame('', $finding->evidence[0][_m('New id (GA4, Tag Manager)')]);
        $this->assertStringContainsString('G-', $finding->advice);
    }

    public function test_ua_next_to_ga4_in_the_module_is_info(): void
    {
        $rows = [self::row(self::SITE, self::GAQ, 'spot 4'), self::row(self::SITE, self::GTAG_G4, 'spot 8')];
        [$finding] = CheckUniversalAnalytics::findingsFor($rows, [self::SITE => 'example.org']);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertSame(1, $finding->count);
        $this->assertCount(2, $finding->evidence);
        $this->assertSame('G-6H7D0HCDXV', $finding->evidence[1][_m('New id (GA4, Tag Manager)')]);
        $this->assertStringContainsString('G-6H7D0HCDXV', $finding->advice);
    }

    public function test_ua_and_ga4_in_one_value_is_info(): void
    {
        [$finding] = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, self::GTAG_UA . self::GTAG_G4)], [self::SITE => 'example.org']);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertSame(1, $finding->count);
    }

    public function test_ua_next_to_tag_manager_is_info(): void
    {
        $rows = [self::row(self::SITE, self::GAQ), self::row(self::SITE, "(window,document,'script','dataLayer','GTM-ABC123');")];
        [$finding] = CheckUniversalAnalytics::findingsFor($rows, [self::SITE => 'example.org']);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('GTM-ABC123', $finding->advice);
    }

    public function test_two_values_of_one_settings_object_both_count(): void
    {
        // the site settings object holds ga_id, page404_code, loginpage_code... - one module, several values
        $rows = [self::row(self::SITE, 'UA-12254263-1', 'settings'), self::row(self::SITE, self::GTAG_G4, 'settings')];
        [$finding] = CheckUniversalAnalytics::findingsFor($rows, [self::SITE => 'example.org']);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertCount(2, $finding->evidence);
    }

    public function test_ga4_in_another_module_does_not_count(): void
    {
        $rows  = [self::row(self::SITE, self::GA_JS), self::row(self::SLICE, self::GTAG_G4)];
        $names = [self::SITE => 'example.org', self::SLICE => 'News'];
        $findings = CheckUniversalAnalytics::findingsFor($rows, $names);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::WARNING, $findings[0]->severity);
        $this->assertSame(self::SITE, $findings[0]->key);
    }

    public function test_only_ga4_is_ok(): void
    {
        $findings = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, self::GTAG_G4)], [self::SITE => 'example.org']);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_more_ua_places_in_one_module_are_one_finding(): void
    {
        $rows = [self::row(self::SITE, self::GA_JS, 'spot 4'), self::row(self::SITE, self::GTAG_UA, 'spot 8'), self::row(self::SITE, self::GAQ, 'spot 9')];
        $findings = CheckUniversalAnalytics::findingsFor($rows, [self::SITE => 'example.org']);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->count);
        $this->assertCount(3, $findings[0]->evidence);
    }

    public function test_a_module_without_a_name_is_left_out(): void
    {
        // deleted modules are not served - findings() passes live modules only
        $findings = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, self::GA_JS)], []);
        $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_warnings_come_before_infos(): void
    {
        $rows  = [self::row(self::SLICE, self::GAQ), self::row(self::SLICE, self::GTAG_G4), self::row(self::SITE, self::GA_JS)];
        $names = [self::SITE => 'example.org', self::SLICE => 'News'];
        $findings = CheckUniversalAnalytics::findingsFor($rows, $names);
        $this->assertSame([Finding::WARNING, Finding::INFO], array_map(static fn(Finding $f): string => $f->severity, $findings));
    }

    public function test_title_is_plain_text(): void
    {
        [$finding] = CheckUniversalAnalytics::findingsFor([self::row(self::SITE, self::GA_JS)], [self::SITE => 'Shop <b>']);
        $this->assertStringContainsString('Shop <b>', $finding->title, 'the renderer escapes the title');
    }

    /** @return array{module: string, where: string, txt: string} */
    private static function row(string $module, string $txt, string $where = 'spot'): array
    {
        return ['module' => $module, 'where' => $where, 'txt' => $txt];
    }
}
