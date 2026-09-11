<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the global id / SQL-escaping helpers in
 * include/zids.php (loaded via tests/bootstrap.php). They pin current behaviour;
 * several are security-relevant — is_long_id() guards id input, quote()/qquote()
 * are the legacy SQL-escaping helpers.
 */
final class ZidsFunctionsTest extends TestCase
{
    // --- is_long_id: exactly 32 hex chars ---

    public function test_is_long_id_accepts_32_hex(): void
    {
        $this->assertTrue(is_long_id(str_repeat('a', 32)));
        $this->assertTrue(is_long_id(str_repeat('F', 32)));
        $this->assertTrue(is_long_id('0123456789abcdef0123456789ABCDEF'));
    }

    public function test_is_long_id_rejects_non_ids(): void
    {
        $this->assertFalse(is_long_id(''));
        $this->assertFalse(is_long_id('abc'));                // too short
        $this->assertFalse(is_long_id(str_repeat('a', 31)));  // 31 chars
        $this->assertFalse(is_long_id(str_repeat('a', 33)));  // 33 chars
        $this->assertFalse(is_long_id(str_repeat('g', 32)));  // non-hex
    }

    // --- pack_id / unpack_id roundtrip ---

    public function test_pack_unpack_roundtrips_a_long_id(): void
    {
        $id = '0123456789abcdef0123456789abcdef';
        $this->assertSame($id, unpack_id(pack_id($id)));
    }

    public function test_pack_and_unpack_handle_zero(): void
    {
        $this->assertSame('0', pack_id('0'));
        $this->assertSame('0', unpack_id('0'));
    }

    // --- xpack_id: 0x-prefixed hex for valid ids, 0x00 otherwise ---

    public function test_xpack_id_prefixes_a_valid_id(): void
    {
        $id = str_repeat('f', 32);
        $this->assertSame('0x' . $id, xpack_id($id));
    }

    public function test_xpack_id_returns_0x00_for_invalid_input(): void
    {
        $this->assertSame('0x00', xpack_id('not-an-id'));
        $this->assertSame('0x00', xpack_id(''));
    }

    // --- quote(): addslashes, no surrounding quotes ---

    public function test_quote_escapes_single_quote_via_addslashes(): void
    {
        $this->assertSame("O\\'Reilly", quote("O'Reilly"));
        $this->assertSame('100%', quote('100%'));  // % is not escaped by addslashes
    }

    // --- qquote(): wraps in single quotes AND escapes (ready-to-use SQL value) ---

    public function test_qquote_wraps_and_escapes(): void
    {
        $this->assertSame("'O\\'Brien'", qquote("O'Brien"));
        $this->assertSame("'plain'", qquote('plain'));
        $this->assertSame("''", qquote(''));
    }
}
