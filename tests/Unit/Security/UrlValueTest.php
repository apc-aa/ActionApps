<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\UrlValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Values a page takes from its URL (als[NAME], site state) run as text and _#ALIASES. Links built
 * by templates carry text, numbers and ids, sometimes _#ALIASES; those run as before. (A value with
 * {...} goes to Settings and the log, which need the database - ViewNoitemTest runs that path over
 * the recording stand-in.)
 */
final class UrlValueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // safe()
    }

    #[DataProvider('plainValues')]
    public function test_expression_runsAValueWithoutBraces(string $value): void
    {
        self::assertSame($value, UrlValue::expression('als[ROK]', $value));
    }

    public static function plainValues(): array
    {
        return [
            'year'    => ['2026'],
            'item id' => ['4085dabc0123456789abcdef01234567'],
            'text'    => ['Hledat: kotle na dřevo (akce)'],
            'alias'   => ['_#HEADLINE'],
            'empty'   => [''],
        ];
    }

    /** a value expanded as a whole (noitem) is text, its HTML and _#ALIASES too */
    public function test_template_showsAValueWithoutBracesAsText(): void
    {
        self::assertSame('&lt;b&gt;_&#35;HEADLINE&lt;/b&gt;', UrlValue::template('set[32]=noitem-', '<b>_#HEADLINE</b>'));
        self::assertSame('', UrlValue::template('set[32]=noitem-', ''));
    }

    /** a refused value keeps no brace and no quote mark a later command could turn into one */
    public function test_asText_leavesNothingToExpand(): void
    {
        $text = UrlValue::asText("{date:Y} \x1E|~@d x \x1E|~@e <b>");
        self::assertStringNotContainsString('{', $text);
        self::assertStringNotContainsString('}', $text);
        self::assertStringNotContainsString("\x1E", $text);
        self::assertStringNotContainsString('<b>', $text);
        self::assertStringStartsWith('&#123;date:Y&#125;', $text);
    }
}
