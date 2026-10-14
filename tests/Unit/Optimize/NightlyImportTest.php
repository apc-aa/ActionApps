<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\NightlyImport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The payload of the nightly push comes from crawled pages - everything in it is checked. */
final class NightlyImportTest extends TestCase
{
    private const INSTALL = 'https://actionapps.org/aadev/';
    private const NOW  = 1_760_500_000;

    /** @param array<string, mixed> $change  top-level fields replaced */
    private static function payload(array $change = []): string
    {
        return (string) json_encode(array_merge([
            'run'        => '20261014-0330',
            'mode'       => 'full',
            'started'    => 1_760_405_400,
            'finished'   => 1_760_405_549,
            'checks_ran' => [['check' => 'unexpanded-alias', 'spec' => '50-expressions'], ['check' => 'php-error', 'spec' => '30-crawl'], ['check' => 'php-error', 'spec' => '50-expressions']],
            'findings'   => [['check' => 'unexpanded-alias', 'severity' => 'warning', 'page' => '/expression/menu', 'url' => 'https://actionapps.org/expression/menu', 'evidence' => '_#LINK_URL__', 'known' => false, 'known_reason' => '']],
        ], $change));
    }

    /** @param array<string, mixed> $finding */
    private static function withFinding(array $finding): string
    {
        return self::payload(['findings' => [$finding + ['check' => 'unexpanded-alias', 'severity' => 'warning', 'page' => '/expression/menu']]]);
    }

    public function test_validate_valid_normalizes(): void
    {
        $push = NightlyImport::validate(self::payload(), self::INSTALL, self::NOW);

        $this->assertSame('20261014-0330', $push['run']);
        $this->assertSame('full', $push['mode']);
        $this->assertSame(1_760_405_400, $push['started']);
        $this->assertSame(['unexpanded-alias:50-expressions', 'php-error:30-crawl', 'php-error:50-expressions'], array_keys($push['ran']));
        $this->assertSame([[
            'check'    => 'unexpanded-alias',
            'spec'     => '50-expressions',
            'severity' => 'warning',
            'page'     => '/expression/menu',
            'url'      => 'https://actionapps.org/expression/menu',
            'evidence' => '_#LINK_URL__',
            'known'    => false,
            'reason'   => '',
        ]], $push['findings']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refused(): array
    {
        $big = array_fill(0, 1001, ['check' => 'unexpanded-alias', 'severity' => 'warning', 'page' => '/x']);
        return [
            'not json'              => ['{run:', 'not valid JSON'],
            'a list'                => ['[1,2]', 'JSON object'],
            'too deep'              => [self::payload(['findings' => [[[[[[[[[1]]]]]]]]]]), 'not valid JSON'],
            'run with a slash'      => [self::payload(['run' => '../x']), 'run must'],
            'run too long'          => [self::payload(['run' => str_repeat('a', 25)]), 'run must'],
            'mode'                  => [self::payload(['mode' => 'partial']), 'mode must'],
            'started as string'     => [self::payload(['started' => '1760405400']), 'unixtimes'],
            'finished before start' => [self::payload(['finished' => 1_760_405_000]), 'unixtimes'],
            'finished in future'    => [self::payload(['finished' => self::NOW + 7200]), 'unixtimes'],
            'no checks ran'         => [self::payload(['checks_ran' => []]), 'checks_ran must'],
            'check id uppercase'    => [self::payload(['checks_ran' => [['check' => 'PHP', 'spec' => '30-crawl']]]), 'checks_ran[0].check must match'],
            'spec id with dot'      => [self::payload(['checks_ran' => [['check' => 'php-error', 'spec' => '30-crawl.spec.ts']]]), 'checks_ran[0].spec must match'],
            'findings not a list'   => [self::payload(['findings' => ['a' => 1]]), 'findings must be a list'],
            'too many findings'     => [self::payload(['findings' => $big]), 'at most 1000'],
            'finding not object'    => [self::payload(['findings' => ['x']]), 'findings[0] must be an object'],
            'check did not run'     => [self::withFinding(['check' => 'xss']), 'not in checks_ran'],
            'spec did not run'      => [self::withFinding(['spec' => '30-crawl']), 'did not run this check'],
            'spec ambiguous'        => [self::withFinding(['check' => 'php-error']), 'spec is needed'],
            'severity'              => [self::withFinding(['severity' => 'fatal']), 'severity must'],
            'page missing'          => [self::payload(['findings' => [['check' => 'unexpanded-alias', 'severity' => 'warning']]]), 'page must be a string'],
            'page blank'            => [self::withFinding(['page' => " \n "]), 'page is empty'],
            'evidence not string'   => [self::withFinding(['evidence' => ['a']]), 'evidence must be a string'],
            'known not bool'        => [self::withFinding(['known' => 'yes']), 'known must be'],
        ];
    }

    #[DataProvider('refused')]
    public function test_validate_refuses(string $json, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        NightlyImport::validate($json, self::INSTALL, self::NOW);
    }

    public function test_validate_message_never_echoes_the_value(): void
    {
        try {
            NightlyImport::validate(self::withFinding(['severity' => '<script>']), self::INSTALL, self::NOW);
            $this->fail('refused expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('<script>', $e->getMessage());
        }
    }

    public function test_validate_spec_inferred_or_named(): void
    {
        $push = NightlyImport::validate(self::payload(['findings' => [
            ['check' => 'unexpanded-alias', 'severity' => 'warning', 'page' => '/a'],
            ['check' => 'php-error', 'spec' => '30-crawl', 'severity' => 'error', 'page' => 'admin/x.php'],
        ]]), self::INSTALL, self::NOW);

        $this->assertSame(['50-expressions', '30-crawl'], array_column($push['findings'], 'spec'));
    }

    public function test_validate_caps_text_and_drops_control_characters(): void
    {
        $push = NightlyImport::validate(self::withFinding([
            'page'     => str_repeat('p', 250),
            'evidence' => "Fatal error:\n  in x.php\ton line 3" . str_repeat('e', 200),
        ]), self::INSTALL, self::NOW);
        $finding = $push['findings'][0];

        $this->assertSame(200, mb_strlen($finding['page']));
        $this->assertSame(120, mb_strlen($finding['evidence']));
        $this->assertStringStartsWith('Fatal error: in x.php on line 3', $finding['evidence']);
    }

    public function test_validate_turns_invisible_format_characters_into_spaces(): void
    {
        // a bidi override, a zero-width space, a C1 control and a line separator could disguise what a row says
        $push = NightlyImport::validate(self::withFinding(['evidence' => "a\u{202E}b\u{200B}c\u{0085}d\u{2028}e\u{2029}f  g"]), self::INSTALL, self::NOW);

        $this->assertSame('a b c d e f g', $push['findings'][0]['evidence']);
    }

    public function test_validate_long_multibyte_page_fits_the_selector(): void
    {
        $push = NightlyImport::validate(self::withFinding(['page' => str_repeat('ě', 200)]), self::INSTALL, self::NOW);

        $this->assertLessThanOrEqual(255, strlen($push['findings'][0]['page']));
        $this->assertTrue(mb_check_encoding($push['findings'][0]['page'], 'UTF-8'), 'never cut inside a character');
    }

    public function test_validate_reason_kept_only_for_known(): void
    {
        $known = NightlyImport::validate(self::withFinding(['known' => true, 'known_reason' => 'deferred']), self::INSTALL, self::NOW);
        $other = NightlyImport::validate(self::withFinding(['known' => false, 'known_reason' => 'deferred']), self::INSTALL, self::NOW);

        $this->assertSame('deferred', $known['findings'][0]['reason']);
        $this->assertSame('', $other['findings'][0]['reason']);
    }

    public function test_validate_empty_findings_is_a_clean_run(): void
    {
        $this->assertSame([], NightlyImport::validate(self::payload(['findings' => []]), self::INSTALL, self::NOW)['findings']);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function urls(): array
    {
        return [
            'same origin'      => ['https://actionapps.org/aadev/admin/x.php?a=1', 'https://actionapps.org/aadev/admin/x.php?a=1'],
            'host other case'  => ['https://ActionApps.org/x', 'https://ActionApps.org/x'],
            'default port'     => ['https://actionapps.org:443/x', 'https://actionapps.org:443/x'],
            'other scheme'     => ['http://actionapps.org/x', ''],
            'other port'       => ['https://actionapps.org:8443/x', ''],
            'other host'       => ['https://evil.example/actionapps.org', ''],
            'host as subdomain' => ['https://actionapps.org.evil.example/', ''],
            'javascript'       => ['javascript:alert(1)//actionapps.org', ''],
            'relative'         => ['/aadev/admin/x.php', ''],
            'userinfo'         => ['https://actionapps.org@evil.example/', ''],
            'user on our host' => ['https://u:p@actionapps.org/', ''],
            'space'            => ['https://actionapps.org/a b', ''],
            'too long'         => ['https://actionapps.org/' . str_repeat('a', 240), ''],
            'not a string'     => [['https://actionapps.org/'], ''],
        ];
    }

    #[DataProvider('urls')]
    public function test_url_only_on_the_install_origin(mixed $url, string $expected): void
    {
        $this->assertSame($expected, NightlyImport::url($url, self::INSTALL));
    }

    public function test_url_dropped_keeps_the_finding(): void
    {
        $push = NightlyImport::validate(self::withFinding(['url' => 'https://evil.example/']), self::INSTALL, self::NOW);

        $this->assertSame('', $push['findings'][0]['url']);
        $this->assertSame('/expression/menu', $push['findings'][0]['page']);
    }
}
