<?php

declare(strict_types=1);

/**
 * {avatar:<img_url>:<name>:<size>} - the picture through {img:…:imgb:<name>}, or a coloured box with the initials.
 * The name is item content: it is neither parsed as an expression nor split at its colons.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandAvatarTest extends TestCase
{
    private const IMAGE = 'https://example.org/img_upload/portrait.jpg';

    public static function setUpBeforeClass(): void
    {
        // separate process: the constant config.php gives does not leak into other tests
        if (!\defined('AA_HTTP_DOMAIN')) {
            \define('AA_HTTP_DOMAIN', 'https://example.org/');
        }
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    private static function avatar(string $image, string $name, string $size = ''): string
    {
        return (new \AA_Stringexpand_Avatar([]))->expand($image, $name, $size);
    }

    /** @return array<string, array{string, string}> */
    public static function ordinaryNames(): array
    {
        return [
            'name'          => ['Jan Novák', 'Jan Novák'],
            'e-mail'        => ['jan.novak@example.org', 'jan.novak'],
        ];
    }

    /** the output templates of live sites rely on */
    #[DataProvider('ordinaryNames')]
    public function test_expand_withImage_isTheImgOutput(string $name, string $title): void
    {
        $img = (new \AA_Stringexpand_Img([]))->expand(self::IMAGE, 'w=48&h=48&iar=1', 'imgb', $title);
        self::assertStringStartsWith('<img src="', $img);
        self::assertSame($img, self::avatar(self::IMAGE, $name));
    }

    public function test_expand_withoutImage_givesInitials(): void
    {
        self::assertSame('<div class="dis-color' . ((crc32('Jan Novák') % 8) + 1) . '" title="Jan Novák">JN</div>', self::avatar('', 'Jan Novák'));
    }

    public function test_expand_nameIsNotAnExpression(): void
    {
        $out = self::avatar(self::IMAGE, '{date:Y}');
        self::assertStringContainsString('title="{date:Y}"', $out);
        self::assertStringContainsString('title="{date:Y}"', self::avatar('', '{date:Y}'));
    }

    public function test_expand_nameKeepsItsColons(): void
    {
        self::assertStringContainsString(' title="Firma: Novák" alt="Firma: Novák"', self::avatar(self::IMAGE, 'Firma: Novák'));
    }

    public function test_expand_withoutImage_escapesTheName(): void
    {
        $out = self::avatar('', '"><script>x</script>');
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('title="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $out);
        self::assertStringContainsString('>&quot;&gt;</div>', $out);
    }
}
