<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\LintFinding;
use AA\Central\Package\ValueCodec;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueCodecTest extends TestCase
{
    /** pieces the random values are made of - the edge cases and some plain text */
    private const PIECES = [
        "\r", "\n", "\r\n", ']]>', ']]', ']', '>', '<', '&', '&amp;', "\t", ' ', "\x00", "\x01", "\x0B", "\x1F", "\x7F",
        'a', 'Zprávy', '🙂', "\u{FFFE}", "\u{FFFF}", "\u{FDD0}", "\xC3", "\xFF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xC0\xAF",
        '<![CDATA[', '{view:12}', '"', "'",
    ];

    /** @return list<string> seeded random values - a few thousand, incl. random bytes */
    private static function randomValues(int $count = 3000): array
    {
        mt_srand(20260924);
        $values = [];
        for ($n = 0; $n < $count; $n++) {
            $value = '';
            if ($n % 10 === 0) {
                for ($i = mt_rand(0, 20); $i > 0; $i--) {
                    $value .= chr(mt_rand(0, 255));
                }
            } else {
                for ($i = mt_rand(0, 12); $i > 0; $i--) {
                    $value .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
                }
            }
            $values[] = $value;
        }
        mt_srand();
        return $values;
    }

    public function test_decode_of_encode_is_the_identity(): void
    {
        foreach (self::randomValues() as $value) {
            $e = ValueCodec::encode($value);
            $this->assertSame($value, ValueCodec::decode($e['text'], $e['enc'], $e['crlf']), bin2hex($value));
        }
    }

    /** the same through XML: CDATA / text node, saveXML(), loadXML(), textContent */
    public function test_values_survive_the_xml_round_trip(): void
    {
        $values = self::randomValues();
        $dom    = new DOMDocument('1.0', 'UTF-8');
        $root   = $dom->appendChild($dom->createElement('r'));
        foreach ($values as $value) {
            $e  = ValueCodec::encode($value);
            $el = $dom->createElement('v');
            $root->appendChild($el);
            if ($e['enc'] !== ValueCodec::TEXT) {
                $el->setAttribute('enc', $e['enc']);
            }
            if ($e['crlf']) {
                $el->setAttribute('crlf', 'true');
            }
            $el->appendChild($e['cdata'] ? $dom->createCDATASection($e['text']) : $dom->createTextNode($e['text']));
        }
        $read = new DOMDocument();
        $this->assertTrue($read->loadXML((string) $dom->saveXML(), LIBXML_NONET));
        foreach ($read->getElementsByTagName('v') as $i => $el) {
            assert($el instanceof DOMElement);
            $decoded = ValueCodec::decode($el->textContent, $el->getAttribute('enc') ?: ValueCodec::TEXT, $el->getAttribute('crlf') === 'true');
            $this->assertSame($values[$i], $decoded, bin2hex($values[$i]));
        }
    }

    public function test_raw_bytes_are_always_base64_without_a_finding(): void
    {
        $this->assertSame(['text' => base64_encode("\xC3\x81"), 'enc' => ValueCodec::BASE64, 'crlf' => false, 'cdata' => false, 'finding' => null], ValueCodec::encode("\xC3\x81", raw: true));
        $this->assertSame(ValueCodec::BASE64, ValueCodec::encode("a\xFF", raw: true)['enc']);
    }

    public function test_only_invalid_utf8_is_a_finding(): void
    {
        foreach (self::randomValues() as $value) {
            $finding = ValueCodec::encode($value)['finding'];
            $this->assertSame(!mb_check_encoding($value, 'UTF-8'), $finding instanceof LintFinding);
            if ($finding) {
                $this->assertSame(ValueCodec::INVALID_BYTES, $finding->code);
                $this->assertTrue(mb_check_encoding(substr($value, 0, $finding->offset), 'UTF-8'), 'valid before the offset');
                $this->assertSame(substr($value, $finding->offset, 16), $finding->ref);
            }
        }
    }

    /** @return array<string, array{string, int}> value, offset of the first invalid byte */
    public static function invalidUtf8(): array
    {
        return [
            'lone continuation'   => ["ab\x80", 2],
            'truncated at end'    => ["ž\xC5", 2],
            'overlong'            => ["\xC0\xAF", 0],
            'surrogate'           => ["x\xED\xA0\x80", 1],
            'above U+10FFFF'      => ["\xF4\x90\x80\x80", 0],
            'bad second byte'     => ["🙂\xE2\x28\xA1", 4],
            'after long ASCII'    => [str_repeat('a', 100000) . "\xFE", 100000],
        ];
    }

    #[DataProvider('invalidUtf8')]
    public function test_offset_of_the_first_invalid_byte(string $value, int $offset): void
    {
        $this->assertSame($offset, ValueCodec::encode($value)['finding']?->offset);
    }

    /** @return array<string, array{string, string, bool, bool}> text, enc, crlf, null */
    public static function brokenElements(): array
    {
        return [
            'null with text'    => ['x', ValueCodec::TEXT, false, true],
            'broken base64'     => ['not base64!', ValueCodec::BASE64, false, false],
            'base64 with crlf'  => ['eA==', ValueCodec::BASE64, true, false],
            'unknown encoding'  => ['x', 'hex', false, false],
        ];
    }

    #[DataProvider('brokenElements')]
    public function test_decode_rejects(string $text, string $enc, bool $crlf, bool $null): void
    {
        $this->expectException(InvalidArgumentException::class);
        ValueCodec::decode($text, $enc, $crlf, $null);
    }

    public function test_decode_null_and_base64_of_zero(): void
    {
        $this->assertNull(ValueCodec::decode('', null: true));
        $this->assertSame('0', ValueCodec::decode(base64_encode('0'), ValueCodec::BASE64));
        $this->assertSame('', ValueCodec::decode('', ValueCodec::BASE64));
    }

    /** @return array<string, array{string, string, bool}> type, value, empty */
    public static function emptyValues(): array
    {
        return [
            'text ""'     => ['textValue', '', true],
            'text "0"'    => ['textValue', '0', false],
            'text " "'    => ['textValue', ' ', false],
            'id ""'       => ['idValue', '', true],
            'int ""'      => ['intValue', '', true],
            'int "0"'     => ['intValue', '0', true],
            'int "-0"'    => ['intValue', '-0', true],
            'int "00"'    => ['intValue', '00', true],
            'int "1"'     => ['intValue', '1', false],
            'float "0.0"' => ['floatValue', '0.0', true],
            'float "0.5"' => ['floatValue', '0.5', false],
        ];
    }

    #[DataProvider('emptyValues')]
    public function test_isEmpty(string $type, string $value, bool $empty): void
    {
        $this->assertSame($empty, ValueCodec::isEmpty($type, $value));
        if ($empty) {
            $this->assertTrue(ValueCodec::isEmpty($type, ValueCodec::emptyValue($type)), 'the empty value is empty');
        }
    }

    public function test_isPlain(): void
    {
        $this->assertTrue(ValueCodec::isPlain("Zprávy\r\n\t<&>"));
        $this->assertFalse(ValueCodec::isPlain("a\x01"));
        $this->assertFalse(ValueCodec::isPlain("a\xFF"));
        $this->assertFalse(ValueCodec::isPlain("\u{FFFE}"));
    }
}
