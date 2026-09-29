<?php

declare(strict_types=1);

namespace AA\Upload;

use AA\Util\Secret;

/**
 * Proof that AA rendered an editor with uploads for this slice, sent back in the
 * X-AA-Upload-Token header. A foreign page cannot read it, so it cannot make a signed-in
 * editor's browser upload (CSRF). An editor's token names the user and expires; an
 * anonymous one does neither - public pages sit in the page cache, and whether the slice
 * takes anonymous items is checked on every upload anyway.
 */
final class UploadToken
{
    public const TTL = 12 * 3600;

    public static function issue(string $slice_id, string $mode, string $uid, int $now): string
    {
        $user    = ($mode === UploadPolicy::USER);
        $expires = $user ? $now + self::TTL : 0;
        return $mode . '.' . $expires . '.' . self::mac($slice_id, $mode, $expires, $user ? $uid : '');
    }

    /** @return ?string the mode the token grants; null when forged, expired, or for another slice or user */
    public static function verify(string $token, string $slice_id, string $uid, int $now): ?string
    {
        $parts = explode('.', $token);
        if ((count($parts) !== 3) || !in_array($parts[0], [UploadPolicy::USER, UploadPolicy::ANON], true) || !ctype_digit($parts[1])) {
            return null;
        }
        [$mode, $expires, $mac] = [$parts[0], (int) $parts[1], $parts[2]];
        $user = ($mode === UploadPolicy::USER);
        if (!hash_equals(self::mac($slice_id, $mode, $expires, $user ? $uid : ''), $mac)) {
            return null;
        }
        return ($user && ($expires < $now)) ? null : $mode;
    }

    private static function mac(string $slice_id, string $mode, int $expires, string $uid): string
    {
        return hash_hmac('sha256', $slice_id . "\0" . $mode . "\0" . $expires . "\0" . $uid, Secret::key('upload'));
    }
}
