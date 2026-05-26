<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Crypt;
use PHPUnit\Framework\TestCase;

final class CryptTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    public function test_encrypt_then_decrypt_roundtrips_plaintext(): void
    {
        $plain  = 'The quick brown fox jumps over the lazy dog';
        $cipher = Crypt::encryptAesGcm($plain, self::PASSWORD);

        $this->assertNotSame($plain, $cipher, 'ciphertext should differ from plaintext');
        $this->assertSame($plain, Crypt::decryptAesGcm($cipher, self::PASSWORD));
    }

    public function test_decrypt_with_wrong_password_returns_false(): void
    {
        $cipher = Crypt::encryptAesGcm('secret payload', self::PASSWORD);

        // sodium_crypto_aead_aes256gcm_decrypt returns false on auth-tag failure (not an exception);
        // Crypt::decryptAesGcm preserves that: `is_string($decrypted) ? $decrypted : false`.
        $this->assertFalse(Crypt::decryptAesGcm($cipher, 'wrong password'));
    }

    public function test_decrypt_of_too_short_input_returns_false(): void
    {
        // shorter than the 12-byte nonce prefix → must short-circuit to false
        $this->assertFalse(Crypt::decryptAesGcm('abc', self::PASSWORD));
    }

    public function test_encrypt_uses_random_nonce_so_repeats_differ(): void
    {
        $plain = 'same plaintext';
        $a = Crypt::encryptAesGcm($plain, self::PASSWORD);
        $b = Crypt::encryptAesGcm($plain, self::PASSWORD);

        $this->assertNotSame($a, $b, 'two encryptions of identical plaintext must differ (random nonce)');
        $this->assertSame($plain, Crypt::decryptAesGcm($a, self::PASSWORD));
        $this->assertSame($plain, Crypt::decryptAesGcm($b, self::PASSWORD));
    }

    public function test_empty_string_roundtrips(): void
    {
        $cipher = Crypt::encryptAesGcm('', self::PASSWORD);
        $this->assertSame('', Crypt::decryptAesGcm($cipher, self::PASSWORD));
    }

    public function test_multibyte_utf8_roundtrips(): void
    {
        $plain  = 'Příliš žluťoučký kůň úpěl ďábelské ódy — 🦊🐉';
        $cipher = Crypt::encryptAesGcm($plain, self::PASSWORD);
        $this->assertSame($plain, Crypt::decryptAesGcm($cipher, self::PASSWORD));
    }

    public function test_short_password_is_padded_and_long_password_is_hashed(): void
    {
        // both < 32 bytes and > 32 bytes must round-trip — exercises both createKey() branches
        $plain = 'payload';

        $shortPw = 'short';
        $longPw  = str_repeat('x', 100);

        $this->assertSame($plain, Crypt::decryptAesGcm(Crypt::encryptAesGcm($plain, $shortPw), $shortPw));
        $this->assertSame($plain, Crypt::decryptAesGcm(Crypt::encryptAesGcm($plain, $longPw), $longPw));
    }
}
