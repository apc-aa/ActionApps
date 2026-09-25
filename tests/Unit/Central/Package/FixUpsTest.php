<?php

declare(strict_types=1);

/**
 * FixUps: what the importer changes in a package - each change applied to a copy and listed for the preview.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FixUp;
use AA\Central\Package\FixUps;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Schema;
use AA\Central\Package\SecurityLint;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class FixUpsTest extends TestCase
{
    use PackageMutations;

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    /** @return array{Package, list<array{string, string, string, string}>} the copy, [path, code, old, new] */
    private static function apply(Package $package): array
    {
        [$fixed, $fixUps] = (new FixUps(new Schema()))->apply($package);
        return [$fixed, array_map(static fn(FixUp $f): array => [$f->path, $f->code, $f->old, $f->new], $fixUps)];
    }

    private static function of(Package $p, string $id): Module
    {
        return array_values(array_filter($p->modules, static fn(Module $m): bool => $m->id === $id))[0];
    }

    public function test_the_example_only_gets_publish_users(): void
    {
        [$fixed, $fixUps] = self::apply(self::example());

        self::assertSame([['meta/publish', FixUp::PUBLISH, 'no', 'users']], $fixUps);
        self::assertSame('users', $fixed->publish);
        self::assertEquals(self::with(self::example(), ['publish' => 'users']), $fixed, 'nothing else changes');
    }

    /** @return array<string, array{?string, list<array{string, string, string, string}>}> */
    public static function publish(): array
    {
        return [
            'all'   => ['all', [['meta/publish', FixUp::PUBLISH, 'all', 'users']]],
            'none'  => [null, [['meta/publish', FixUp::PUBLISH, '', 'users']]],
            'users' => ['users', []],
        ];
    }

    #[DataProvider('publish')]
    public function test_publish_is_users_on_the_target(?string $publish, array $expected): void
    {
        [$fixed, $fixUps] = self::apply(self::with(self::example(), ['publish' => $publish]));

        self::assertSame('users', $fixed->publish, 'Q-H1');
        self::assertSame($expected, $fixUps);
    }

    /** @return array<string, array{Closure(Package): Package, list<array{string, string, string, string}>, Closure(Package): mixed, mixed}>
     *          mutation, fix-ups besides publish, what to read from the copy, its value */
    public static function mutations(): array
    {
        $S = self::S;
        $W = self::W;
        return [
            // SecurityLint::attachments(): a local path or a computed value would read the target's files
            'local attachment'        => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '/etc/passwd']),
                [["$S/email[5]/attachments", FixUp::ATTACHMENTS, '/etc/passwd', '']],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['email'][0]['attachments'], '',
            ],
            'computed attachment'     => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{include:x}']),
                [["$S/email[5]/attachments", FixUp::ATTACHMENTS, '{include:x}', '']],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['email'][0]['attachments'], '',
            ],
            'URL attachment stays'    => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => 'https://example.org/a.pdf']),
                [],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['email'][0]['attachments'], 'https://example.org/a.pdf',
            ],
            'field attachment stays'  => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'email', 0, ['attachments' => '{file............}']),
                [],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['email'][0]['attachments'], '{file............}',
            ],
            // Validator W lang-file-replaced
            'unknown slice lang_file' => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['lang_file' => 'xx_news_lang.php']),
                [["$S/slice[#0]/lang_file", FixUp::LANG_FILE, 'xx_news_lang.php', 'cz-utf8_news_lang.php']],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['slice'][0]['lang_file'], 'cz-utf8_news_lang.php',
            ],
            'known slice lang_file'   => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['lang_file' => 'en_news_lang.php']),
                [],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['slice'][0]['lang_file'], 'en_news_lang.php',
            ],
            'empty slice lang_file'   => [
                static fn(Package $p): Package => self::cell($p, self::SLICE, 'slice', 0, ['lang_file' => '']),
                [],
                static fn(Package $p): mixed => self::of($p, self::SLICE)->rows['slice'][0]['lang_file'], '',
            ],
            // Validator W settings-dropped
            'site settings dropped'   => [
                static fn(Package $p): Package => self::setting(self::setting(self::setting($p, self::SITE, 'ga_id', 'G-1'), self::SITE, 'alternate_url', ['a.cz', 'b.cz']), self::SITE, 'app_version', '1'),
                [["$W/settings/app_version", FixUp::SETTINGS, '1', ''], ["$W/settings/alternate_url", FixUp::SETTINGS, 'a.cz, b.cz', ''], ["$W/settings/ga_id", FixUp::SETTINGS, 'G-1', '']],
                static fn(Package $p): mixed => self::of($p, self::SITE)->settings, ['page404' => '2', 'translate_slice' => self::SLICE, 'web_languages' => 'cz'],
            ],
            // SecurityLint W spot-markup: the site tree admin prints names, variables and conditions unescaped
            'spot markup'             => [
                static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][1]['n'] = '<b onclick="x()">Článek</b>';
                    $t['spots'][0]['v'] = ['<i>x</i>' => '<i>x</i>', 'y' => 'y'];
                    $t['spots'][2]['c'] = ['<i>x</i>' => '<b>rubrika</b>', 'y' => '{if:{y}:<:3:a:b}'];
                    return $t;
                }),
                [
                    ["$W/spot-tree/spot[1]/var[#0]", FixUp::SPOT_MARKUP, '<i>x</i>', 'x'],
                    ["$W/spot-tree/spot[2]@name", FixUp::SPOT_MARKUP, '<b onclick="x()">Článek</b>', 'Článek'],
                    ["$W/spot-tree/spot[3]/cond[#0]@var", FixUp::SPOT_MARKUP, '<i>x</i>', 'x'],
                    ["$W/spot-tree/spot[3]/cond[#0]", FixUp::SPOT_MARKUP, '<b>rubrika</b>', 'rubrika'],
                ],
                static fn(Package $p): mixed => array_map(static fn(array $s): array => [$s['n'], $s['v'] ?? null, $s['c'] ?? null], self::of($p, self::SITE)->spotTree['spots']),
                [['start', ['x' => 'x', 'y' => 'y'], null], ['Článek', null, null], ['rubrika', null, ['x' => 'rubrika', 'y' => '{if:{y}:<:3:a:b}']]],
            ],
            'nested tag start'        => [
                static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][1]['n'] = '<<b>script>x';
                    return $t;
                }),
                [["$W/spot-tree/spot[2]@name", FixUp::SPOT_MARKUP, '<<b>script>x', 'x']],
                static fn(Package $p): mixed => self::of($p, self::SITE)->spotTree['spots'][1]['n'], 'x',
            ],
            'no tag start stays'      => [
                static fn(Package $p): Package => self::tree($p, static function (array $t): array {
                    $t['spots'][1]['n'] = 'a < b, x<3';
                    return $t;
                }),
                [],
                static fn(Package $p): mixed => self::of($p, self::SITE)->spotTree['spots'][1]['n'], 'a < b, x<3',
            ],
            // no <spot-tree> = site.structure ''
            'no spot tree'            => [
                static fn(Package $p): Package => self::tree($p, static fn(): ?array => null),
                [["$W/site[#0]/structure", FixUp::NO_TREE, '', '']],
                static fn(Package $p): mixed => self::of($p, self::SITE)->rows['site'][0], ['state_file' => '/cz/home', 'flag' => '1', 'structure' => ''],
            ],
        ];
    }

    /** @param Closure(Package): Package $mutate @param Closure(Package): mixed $read */
    #[DataProvider('mutations')]
    public function test_fix_up(Closure $mutate, array $expected, Closure $read, mixed $value): void
    {
        [$fixed, $fixUps] = self::apply($mutate(self::example()));

        self::assertSame([...$expected, ['meta/publish', FixUp::PUBLISH, 'no', 'users']], $fixUps);
        self::assertSame($value, $read($fixed));
    }

    /** nothing SecurityLint flags as spot-markup survives into the plan */
    public function test_no_spot_markup_survives(): void
    {
        $tags    = ['<script>', '<<b>i>', '</x', '<!--', '<?php', '<IMG src=x>', '<a href="{x}">'];
        $package = self::tree(self::example(), static function (array $t) use ($tags): array {
            foreach ($t['spots'] as $k => $spot) {
                $tag                   = $tags[$k % count($tags)];
                $t['spots'][$k]['n']   = "{$tag}n$k";
                $t['spots'][$k]['v']   = ["{$tag}v" => "{$tag}v", 'plain' => 'plain'];
                $t['spots'][$k]['c']   = ["{$tag}v" => "a{$tag}", 'plain' => $tags[($k + 1) % count($tags)]];
            }
            return $t;
        });
        $markup = static fn(Package $p): array => array_filter((new SecurityLint(new Schema()))->package($p), static fn(array $f): bool => $f[1]->code === SecurityLint::SPOT_MARKUP);
        self::assertNotEmpty($markup($package));

        [$fixed] = self::apply($package);
        self::assertSame([], $markup($fixed));
        foreach (self::of($fixed, self::SITE)->spotTree['spots'] as $spot) {
            self::assertSame(array_keys($spot['v']), array_keys($spot['c']), 'variables and their conditions renamed alike');
        }
    }
}
