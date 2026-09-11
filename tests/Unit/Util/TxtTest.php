<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers of AA\Util\Txt. decoratedName()/keyValTable()/richValue() are not
 * covered here: they reach into Langs/Stringexpander/safe() which need the legacy
 * boot — out of scope for an isolated unit test.
 */
final class TxtTest extends TestCase
{
    // --- shortenMiddle(): elides the middle, keeps head + tail ---

    /**
     * The split is pinned, odd grapheme to the TAIL (length 21 -> 9 + marker + 11,
     * not 10/10): {shorten:<text>:<len>:3} (stringexpand.php) and the option labels
     * at formutil.php:2738 have always rendered it that way.
     *
     * @return array<string, array{0:string, 1:int, 2:string}>
     */
    public static function shortenMiddleProvider(): array
    {
        return [
            'even length, odd grapheme to the tail' => [
                'The quick brown fox jumps over the lazy dog and keeps running', 20, 'The quick…ps running',
            ],
            'odd length keeps the 9/11 split' => [
                'The quick brown fox jumps over the lazy dog and keeps running', 21, 'The quick…eps running',
            ],
            'graphemes, not bytes' => [
                'Příliš žluťoučký kůň úpěl ďábelské ódy a bylo to velmi dlouhé', 20, 'Příliš žl…lmi dlouhé',
            ],
            'exactly at the limit is untouched' => ['abcdefghij', 10, 'abcdefghij'],
            'one over the limit'                => ['abcdefghijk', 10, 'abcd…ghijk'],
        ];
    }

    #[DataProvider('shortenMiddleProvider')]
    public function test_shortenMiddle_pins_the_head_marker_tail_split(string $text, int $len, string $expected): void
    {
        $this->assertSame($expected, Txt::shortenMiddle($text, $len));
    }

    public function test_shortenMiddle_keeps_strings_within_limit(): void
    {
        // regression: without a length guard this returned "short…short"
        $this->assertSame('short', Txt::shortenMiddle('short', 20));
    }

    /**
     * Regression for a defect that was reachable from formutil.php:2736, whose
     * guard counts BYTES (strlen > 100) while shortening counts GRAPHEMES: a
     * diacritic-heavy option label of 60 graphemes / 120 bytes passed the guard
     * and came back DUPLICATED at 201 bytes into an admin select box.
     */
    public function test_shortenMiddle_never_lengthens_a_diacritic_label(): void
    {
        $label = str_repeat('žščř', 15);   // 60 graphemes, 120 bytes

        $this->assertSame($label, Txt::shortenMiddle($label, 100));
    }

    /** Never longer than asked — including odd lengths, which used to raise
     *  "Implicit conversion from float 9.5 to int loses precision". */
    public function test_shortenMiddle_never_exceeds_requested_length(): void
    {
        foreach ([5, 6, 7, 20, 21, 49, 100, 101] as $len) {
            $this->assertLessThanOrEqual($len, Txt::strlen(Txt::shortenMiddle(str_repeat('abcdefghij', 20), $len)));
        }
    }

    /** The marker is configurable — Files::escape() passes a digest, since a
     *  filename may hold no "…". */
    public function test_shortenMiddle_accepts_a_custom_marker(): void
    {
        $out = Txt::shortenMiddle(str_repeat('a', 50), 20, '__c0ffee__');

        $this->assertSame(20, Txt::strlen($out));
        $this->assertStringContainsString('__c0ffee__', $out);
    }

    // --- shorten(): grapheme-aware length check, byte-length ellipsis budget ---

    public function test_shorten_truncates_longer_strings_with_ellipsis(): void
    {
        // default ellipsis "…" is 3 bytes → cut = aa_substr(0, len - 3)
        $this->assertSame('He…', Txt::shorten('Hello World', 5));
        $this->assertSame('ab..', Txt::shorten('abcdef', 4, '..'));
    }

    public function test_shorten_keeps_strings_within_limit(): void
    {
        $this->assertSame('Hi', Txt::shorten('Hi', 5));
        // grapheme-aware: 3 graphemes ≤ 10 → returned unchanged
        $this->assertSame('čřž', Txt::shorten('čřž', 10));
    }

    // --- bin2url() / url2bin(): RFC4648 §5 base64url, no padding ---

    public function test_bin2url_is_url_safe_and_unpadded(): void
    {
        $out = Txt::bin2url("\x00\xff\x10\xfb\xef");
        $this->assertSame('', strpbrk($out, '+/=') ?: '', 'must not contain +, / or =');
        $this->assertSame('', Txt::bin2url(''));
    }

    public function test_bin2url_url2bin_roundtrips_binary(): void
    {
        $bin = random_bytes(64) . "\x00\xff\x10binary";
        $this->assertSame($bin, Txt::url2bin(Txt::bin2url($bin)));
    }

    // --- prefixArrayKeys(): prefixes every key ---

    public function test_prefixArrayKeys_prefixes_keys(): void
    {
        $this->assertSame(['p_a' => 1, 'p_b' => 2], Txt::prefixArrayKeys(['a' => 1, 'b' => 2], 'p_'));
        $this->assertSame([], Txt::prefixArrayKeys([], 'p_'));
    }

    // --- safe()/safexml(): output escaping (moved here from include/util.php) ---

    public function test_safe_escapes_html_special_chars(): void
    {
        $this->assertSame(
            '&lt;b&gt;O&apos;Brien &amp; &quot;x&quot;',
            Txt::safe('<b>O\'Brien & "x"'),
        );
    }

    public function test_safe_preserves_multibyte_passthrough(): void
    {
        // ISO-8859-1 charset escapes only <>&"'; other bytes pass through unchanged
        $this->assertSame('café &lt;i&gt;', Txt::safe('café <i>'));
    }

    public function test_safexml_escapes_for_xml(): void
    {
        $this->assertSame('&lt;a&gt; &amp; &apos; &quot;', Txt::safexml('<a> & \' "'));
    }

    // --- UTF-aware primitives ---

    public function test_strlen_counts_graphemes(): void
    {
        $this->assertSame(3, Txt::strlen('čřž'));
    }

    public function test_strlen_falls_back_to_bytes_on_invalid_utf8(): void
    {
        // grapheme_strlen() returns null (not int|false) for invalid UTF-8.
        // strlen() must not propagate that as a TypeError but fall back to byte length.
        $bad = "bad \xfc\xe9 latin1";
        $this->assertSame(strlen($bad), Txt::strlen($bad));
    }

    public function test_shorten_survives_invalid_utf8(): void
    {
        // Debug logging shortens print_r() dumps that may carry non-UTF-8 bytes
        // (e.g. raw RSS feed content); shorten() must not throw a TypeError.
        $bad = "Feed Object \xfc\xe9 " . str_repeat('x', 300);
        $out = Txt::shorten($bad, 200);
        $this->assertStringEndsWith('…', $out);
        $this->assertLessThan(strlen($bad), strlen($out));
    }

    public function test_substr_is_utf_aware(): void
    {
        $this->assertSame('Pří', Txt::substr('Příliš', 0, 3));
    }

    public function test_strpos_and_strrpos(): void
    {
        $this->assertSame(2, Txt::strpos('hello', 'l'));
        $this->assertSame(3, Txt::strrpos('hello', 'l'));
        $this->assertFalse(Txt::strpos('hello', 'z'));
        // null offset must coerce to 0 (faithful to legacy (int)$offset), not TypeError
        $this->assertSame(2, Txt::strpos('hello', 'l', null));
    }
}
