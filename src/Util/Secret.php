<?php

declare(strict_types=1);

namespace AA\Util;

/** Server-side keys for signing and encrypting. AA_ID is no secret (config.php.default
 *  ships one, Centrals store it), so it is never a key by itself. Each use has its own
 *  purpose label: renaming one label invalidates what was made with it and nothing else.
 */
final class Secret
{
    /** a shorter AA_SECRET (the empty example in config.php.default) is ignored */
    public const MIN_LENGTH = 32;

    /** @return string 32 raw bytes */
    public static function key(string $purpose): string
    {
        return hash_hkdf('sha256', self::master(), 32, 'aa:' . $purpose);
    }

    /** AA_SECRET from config.php; without it a value only the server knows, so an install
     *  keeps working after the update with no config change (State Check warns) */
    private static function master(): string
    {
        $secret = defined('AA_SECRET') ? (string) constant('AA_SECRET') : '';
        if (strlen($secret) >= self::MIN_LENGTH) {
            return $secret;
        }
        return hash_hkdf('sha256', constant('DB_PASSWORD') . "\0" . constant('AA_ID') . "\0" . constant('DB_NAME'), 32, 'aa:master');
    }
}
