<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** The Add buttons of the Related Items window hand the item title to SelectRelations() in the opener.
 *  The page runs only with a login and a session, so the tests read its source and check the expressions
 *  that build the call. */
#[RunClassInSeparateProcess]
final class RelatedSelTest extends TestCase
{
    /** what the Add button hands SelectRelations() as the title - $slice_field is _#HEADLINE by default */
    private const TITLE_ARG = '{str_replace:["_#"]:["_&#35;"]:{safe:{jsonstring:{expand:_#HEADLINE}}}}';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
    }

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/related_sel.php');
    }

    /** a javascript: URL is percent-decoded before it runs, {javascript:} leaves a backslash as it is */
    public function test_button_passesTheTitleAsJsonInOnclick(): void
    {
        $source = self::source();
        self::assertStringNotContainsString('javascript:SelectRelations', $source);
        self::assertStringNotContainsString('{javascript:', $source);
        self::assertStringContainsString('onclick=\"SelectRelations(', $source);
        $arg = str_replace(['"', '_#HEADLINE'], ['\\"', '" . $slice_field . "'], self::TITLE_ARG);
        self::assertStringContainsString("_#ITEM_ID_',$arg);return false;", $source);
    }

    /** @return array<string, array{string}> */
    public static function titles(): array
    {
        return [
            'plain'            => ['Zpráva o stavu'],
            'apostrophe'       => ["Rock'n'roll"],
            'percent-encoded'  => ['%27);alert(1);//'],
            'backslash quote'  => ["a\\');alert(1);//"],
            'double quote'     => ['"quoted" <b>'],
            'line terminators' => ["a\u{2028}b\nc"],
        ];
    }

    /** the browser decodes the attribute, the JS parser then reads exactly one string literal of the title */
    #[DataProvider('titles')]
    public function test_safeJsonstring_isOneStringLiteralInAnAttribute(string $title): void
    {
        $attr = safe((new \AA_Stringexpand_Jsonstring([]))->expand($title));
        self::assertStringNotContainsString('"', $attr);
        self::assertStringNotContainsString("'", $attr);
        $literal = html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertMatchesRegularExpression('/^"(?:[^"\\\\\n\r\x{2028}\x{2029}]|\\\\.)*"$/u', $literal);
        self::assertSame($title, json_decode($literal));
    }

    /** @return array<string, array{string, string}> title => what the expanded _#HEADLINE (f_h) gives */
    public static function rowTitles(): array
    {
        return [
            'plain'              => ['Zpráva o stavu', 'Zpráva o stavu'],
            'alias in the title' => ['_#HEADLINE");alert(1)//', '_#HEADLINE&quot;);alert(1)//'],
            'other alias'        => ['_#FULLTEXT', '_#FULLTEXT'],
        ];
    }

    /** the row is expanded and then its aliases are replaced - a "_#" the title brings must stay text */
    #[DataProvider('rowTitles')]
    public function test_titleArg_inAnExpandedRow_staysOneStringLiteral(string $title, string $expanded): void
    {
        $aliases = (new \AA_Aliases())
            ->addAlias(new \AA_Alias('_#HEADLINE', 'f_h', 'headline........'))
            ->addAlias(new \AA_Alias('_#FULLTEXT', 'f_t', 'full_text.......'));
        $item = new \AA_Item([
            'id..............' => [['value' => md5($title), 'flag' => 0]],
            'headline........' => [['value' => $title, 'flag' => 0]],
            'full_text.......' => [['value' => '"><b>x', 'flag' => 0]],
        ], $aliases);
        $attr = \AA::Un("onclick=\"SelectRelations('v','','','z'," . self::TITLE_ARG . ');return false;"', '', $item);
        self::assertSame(1, preg_match('/^onclick="([^"]*)"$/', $attr, $m), $attr);
        $js = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertSame(1, preg_match('/^SelectRelations\(\'v\',\'\',\'\',\'[^\']*\',("(?:[^"\\\\]|\\\\.)*")\);return false;$/', $js, $lit), $js);
        self::assertSame($expanded, json_decode($lit[1]));
    }
}
