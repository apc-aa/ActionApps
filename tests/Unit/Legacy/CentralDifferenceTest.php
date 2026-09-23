<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Difference;
use AA_Identifier;
use PHPUnit\Framework\TestCase;

/**
 * AA_Difference describes what Central got from a remote AA (the rows of central/synchronize3.php;
 * the rest of that page is not covered here). The remote may be hostile, so its values, column
 * names and row ids must end up in the descriptions as inert text: no HTML, and no AA template
 * command - `{…}` would run in the Central admin if it went through AA::Un(). (AA::Un() cannot
 * run here at all: it lives in locsess.php, the full boot.)
 */
final class CentralDifferenceTest extends TestCase
{
    private const HOSTILE_VALUE = '{htmltoggle:x}{user}<img src=x onerror=alert(1)>';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/central_shims.php';
        // class declarations only - no side effect on load
        require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';
    }

    /** @param AA_Difference[] $diffs */
    private static function render(array $diffs): string
    {
        ob_start();
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
        return (string) ob_get_clean();
    }

    public function test_compareArray_differentValue_keeps_remote_value_inert(): void
    {
        $diffs = AA_Difference::_compareArray(['name' => 'Template value'], ['name' => self::HOSTILE_VALUE], new AA_Identifier('mod', 'field', 'row'), []);
        $html  = self::render($diffs);

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('{htmltoggle:x}{user}', $html, 'the template command must stay literal text');
        $this->assertStringContainsString('<details>', $html, 'the two values sit behind a native toggle');
        $this->assertStringContainsString('Template value', $html);
    }

    public function test_compareArray_escapes_remote_keys_and_row_ids(): void
    {
        $identifier = new AA_Identifier('mod', 'field', '<b>row</b>');
        $diffs      = AA_Difference::_compareArray(['<b>tkey</b>' => 'a', 'same' => 'x', 'diff' => '1'], ['<i>dkey</i>' => 'b', 'same' => 'x', 'diff' => '2'], $identifier, []);
        $html       = self::render($diffs);

        foreach (['<b>', '<i>'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $row = 'mod-&gt;field-&gt;&lt;b&gt;row&lt;/b&gt;';
        $this->assertStringContainsString("(&lt;b&gt;tkey&lt;/b&gt;) in destination slice for $row", $html);
        $this->assertStringContainsString("(&lt;i&gt;dkey&lt;/i&gt;) in template slice for $row-&gt;&lt;i&gt;dkey&lt;/i&gt;", $html);
        $this->assertStringContainsString("The record for $row is different", $html);
        $this->assertStringContainsString("key diff in $row array", $html);
        $this->assertStringContainsString('&nbsp;&nbsp;&nbsp;-&nbsp;', $html, 'intentional indentation is not escaped twice');
    }

    public function test_compareArray_escapes_identifier_in_whole_record_messages(): void
    {
        $identifier = new AA_Identifier('mod', 'view', '<script>x</script>');
        $html       = self::render(array_merge(
            AA_Difference::_compareArray('', ['a' => 1], $identifier, []),   // DELETED
            AA_Difference::_compareArray(['a' => 1], '', $identifier, []),   // NEW
            AA_Difference::_compareArray('', '', $identifier, []),           // INFO not defined
            AA_Difference::_compareArray(['a' => 1], ['a' => 1], $identifier, []), // INFO identical
        ));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(4, substr_count($html, 'mod-&gt;view-&gt;&lt;script&gt;x&lt;/script&gt;'));
    }
}
