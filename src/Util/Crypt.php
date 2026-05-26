<?php

/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 11.12.18
 * Time: 13:55
 *
 * Code inspired by Spomky-Labs - https://github.com/Spomky-Labs/php-aes-gcm/blob/v1.x/src/AESGCM.php (2018-12-11)
 */

namespace AA\Util;

use Exception;

/**
 * Class Crypt
 *
 * @package AA\Util
 */
final class Crypt
{
    private const AES256GCM_KEYBYTES  = 32;   // = SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES
    private const AES256GCM_NPUBBYTES = 12;   // = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES


    /** Encrypts the text with password using  aes256gcm crypt function. Works from PHP >5.3
     * @param      $text
     * @param      $pass
     * @return string
     * @throws Exception
     */
    public static function encryptAesGcm($text, $pass): string
    {
        $key   = self::createKey($pass);
        $nonce = mb_substr(Txt::bin2url(random_bytes(self::AES256GCM_NPUBBYTES)), 0, self::AES256GCM_NPUBBYTES, '8bit'); // nonce is already base64_url encoded
        $enc = sodium_crypto_aead_aes256gcm_encrypt($text, '', $nonce, $key);
        return $nonce . Txt::bin2url($enc);
    }

    /** Decrypts the text with password using aes256gcm crypt function. Works from PHP >5.3
     * @param      $text
     * @param      $pass
     * @throws Exception
     */
    public static function decryptAesGcm($text, $pass): false|string
    {
        if (strlen($text) < self::AES256GCM_NPUBBYTES) {
            return false;
        }
        $key   = self::createKey($pass);
        $nonce = mb_substr($text, 0, self::AES256GCM_NPUBBYTES, '8bit');
        $enc   = Txt::url2bin(mb_substr($text, self::AES256GCM_NPUBBYTES, null, '8bit'));
        $decrypted = sodium_crypto_aead_aes256gcm_decrypt($enc, '', $nonce, $key);
        return is_string($decrypted) ? $decrypted : false;
    }

    /**
     * @param string $pass
     * @return string - $kay modified to exact self::AES256GCM_KEYBYTES bytes
     */
    private static function createKey($pass): string
    {
        if (($passlen = strlen($pass)) > self::AES256GCM_KEYBYTES) {
            return pack("H*", hash('sha256', $pass));
        }
        if ($passlen === self::AES256GCM_KEYBYTES) {
            return $pass;
        }
        return str_pad($pass, self::AES256GCM_KEYBYTES, pack("H*", hash('sha256', $pass)));
    }
}
