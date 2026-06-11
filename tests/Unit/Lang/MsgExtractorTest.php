<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use AA\Lang\MsgExtractor;
use PHPUnit\Framework\TestCase;

final class MsgExtractorTest extends TestCase
{
    private MsgExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MsgExtractor();
    }

    /** @return array<string, int[]> */
    private function messages(string $code): array
    {
        $result = $this->extractor->extractFromSource($code, 'test.php');
        return $result['messages'];
    }

    /** @return string[] */
    private function warnings(string $code): array
    {
        $result = $this->extractor->extractFromSource($code, 'test.php');
        return $result['warnings'];
    }

    // --- basic extraction ---

    public function test_extracts_double_quoted_message(): void
    {
        $this->assertSame(['Hello' => [2]], $this->messages("<?php\necho _m(\"Hello\");"));
    }

    public function test_extracts_single_quoted_message(): void
    {
        $this->assertSame(['Hello' => [1]], $this->messages("<?php echo _m('Hello');"));
    }

    public function test_extracts_message_with_params_argument(): void
    {
        $this->assertSame(
            ['Hello %1' => [1]],
            $this->messages("<?php echo _m('Hello %1', [\$name]);"),
        );
    }

    public function test_extracts_concatenated_message(): void
    {
        $this->assertSame(
            ['Hello World' => [1]],
            $this->messages("<?php echo _m('Hello ' . \"World\");"),
        );
    }

    public function test_extracts_multiline_concatenated_message(): void
    {
        $code = "<?php echo _m('line one '\n  .'line two');";
        $this->assertSame(['line one line two' => [2]], $this->messages($code));
    }

    public function test_collects_multiple_occurrences_with_rows(): void
    {
        $code = "<?php\n_m('Hi');\nfoo();\n_m('Hi');\n";
        $this->assertSame(['Hi' => [2, 4]], $this->messages($code));
    }

    public function test_whitespace_between_m_and_paren(): void
    {
        $this->assertSame(['Hi' => [1]], $this->messages("<?php _m ( 'Hi' );"));
    }

    // --- things that must NOT be extracted ---

    public function test_ignores_mdelayed_and_other_identifiers(): void
    {
        $code = "<?php _mdelayed('No'); my_m('No'); \$x->m('No'); m_('No');";
        $this->assertSame([], $this->messages($code));
        $this->assertSame([], $this->warnings($code));
    }

    public function test_ignores_m_in_comments(): void
    {
        $code = "<?php\n// _m('No comment')\n/* _m('No block') */\n# _m('No hash')\n";
        $this->assertSame([], $this->messages($code));
    }

    public function test_ignores_m_inside_string_literals(): void
    {
        $code = "<?php \$js = 'alert(_m(\"No\"))';";
        $this->assertSame([], $this->messages($code));
    }

    public function test_ignores_function_definition(): void
    {
        $code = "<?php function _m(\$id, \$params=[]) { return \$id; }";
        $this->assertSame([], $this->messages($code));
        $this->assertSame([], $this->warnings($code));
    }

    public function test_ignores_method_style_dispatches(): void
    {
        $code = "<?php \$x->_m('No'); \$x?->_m('No'); Foo::_m('No');";
        $this->assertSame([], $this->messages($code));
        $this->assertSame([], $this->warnings($code));
        $this->assertFalse($this->extractor->sourceUsesM($code));
    }

    public function test_extracts_m_call_in_inline_html_php_tag(): void
    {
        $code = "<input value=\"<?php echo _m('Login now') ?>\">";
        $this->assertSame(['Login now' => [1]], $this->messages($code));
    }

    // --- warnings ---

    public function test_variable_argument_warns_and_skips(): void
    {
        $code = "<?php _m(\$variable);";
        $this->assertSame([], $this->messages($code));
        $this->assertSame(['test.php, row 1: using variable in _m is not allowed'], $this->warnings($code));
    }

    public function test_interpolated_string_warns(): void
    {
        $code = "<?php _m(\"Hello \$name\");";
        $this->assertSame([], $this->messages($code));
        $this->assertCount(1, $this->warnings($code));
    }

    public function test_concatenated_variable_warns(): void
    {
        $code = "<?php _m('Hello ' . \$name);";
        $this->assertSame([], $this->messages($code));
        $this->assertCount(1, $this->warnings($code));
    }

    // --- escape-sequence decoding (replaces the old eval()-based decoding) ---

    public function test_decode_double_quoted_escapes(): void
    {
        $this->assertSame("a\nb\tc", MsgExtractor::decodeStringLiteral('"a\nb\tc"'));
        $this->assertSame('say "hi"', MsgExtractor::decodeStringLiteral('"say \"hi\""'));
        $this->assertSame('5$ bill', MsgExtractor::decodeStringLiteral('"5\$ bill"'));
        $this->assertSame('back\\slash', MsgExtractor::decodeStringLiteral('"back\\\\slash"'));
        $this->assertSame('A', MsgExtractor::decodeStringLiteral('"\x41"'));
        $this->assertSame('A', MsgExtractor::decodeStringLiteral('"\101"'));
        $this->assertSame('€', MsgExtractor::decodeStringLiteral('"\u{20AC}"'));
    }

    public function test_decode_preserves_unknown_escapes(): void
    {
        // PHP keeps the backslash for unknown sequences — important for the \% convention in _m()
        $this->assertSame('100\%', MsgExtractor::decodeStringLiteral('"100\%"'));
        $this->assertSame('a\b', MsgExtractor::decodeStringLiteral('"a\b"'));
    }

    public function test_decode_single_quoted(): void
    {
        $this->assertSame("it's", MsgExtractor::decodeStringLiteral("'it\\'s'"));
        $this->assertSame('no\nnewline', MsgExtractor::decodeStringLiteral("'no\\nnewline'"));
        $this->assertSame('back\\slash', MsgExtractor::decodeStringLiteral("'back\\\\slash'"));
    }

    // --- multi-file aggregation ---

    public function test_extract_from_files_aggregates_locations(): void
    {
        $dir = sys_get_temp_dir() . '/msgext_' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/a.php', "<?php _m('Shared'); _m('OnlyA');");
        file_put_contents($dir . '/b.php', "<?php\n_m('Shared');");

        $result = $this->extractor->extractFromFiles($dir, ['a.php', 'b.php']);

        $this->assertSame(['a.php' => [1], 'b.php' => [2]], $result['messages']['Shared']);
        $this->assertSame(['a.php' => [1]], $result['messages']['OnlyA']);

        unlink($dir . '/a.php');
        unlink($dir . '/b.php');
        rmdir($dir);
    }

    // --- coverage helper ---

    public function test_source_uses_m(): void
    {
        $this->assertTrue($this->extractor->sourceUsesM("<?php _m('x');"));
        $this->assertFalse($this->extractor->sourceUsesM('<?php echo "no calls";'));
        $this->assertFalse($this->extractor->sourceUsesM("<?php _mdelayed('x'); my_m('y');"));
        $this->assertFalse($this->extractor->sourceUsesM("<?php function _m(\$id) {}"));
    }
}
