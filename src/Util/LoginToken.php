<?php

declare(strict_types=1);

namespace AA\Util;

use AA;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

/** The long-lived login of Central to a remote AA: a JWT sent as Authorization: Bearer to
 *  central/responder.php. It carries a tag of the user's credential stamp (AA_Perm::credentialStamp(),
 *  the password hash in SQL) it was issued under, so a password change revokes every token of the user.
 */
final class LoginToken
{
    private const LIFETIME = 365 * 24 * 3600;

    /** @return string the token, '' for an id no backend knows or whose password change it cannot tell */
    public static function issue(string $user_id, ?int $now = null): string
    {
        if (!AA::$perm->getIDsInfo($user_id) || (($stamp = AA::$perm->credentialStamp($user_id)) === null)) {
            return '';
        }
        $now ??= time();
        return JWT::encode(['sub' => $user_id, 'iat' => $now, 'exp' => $now + self::LIFETIME, 'pwd' => self::pwdTag($stamp)], Secret::key('jwt'), 'HS256');
    }

    /** @return array{0: string, 1: string} user id, login name
     *  @throws \Throwable why the token is refused; the message never contains the token */
    public static function verify(string $jwt): array
    {
        $claims = JWT::decode($jwt, new Key(Secret::key('jwt'), 'HS256'));
        $uid    = (string) ($claims->sub ?? '');
        $user   = AA::$perm->getIDsInfo($uid);
        $stamp  = $user ? AA::$perm->credentialStamp($uid) : null;
        if (!isset($claims->exp) || ($stamp === null) || !hash_equals(self::pwdTag($stamp), (string) ($claims->pwd ?? ''))) {
            throw new UnexpectedValueException('Token without exp or pwd, of an unknown user or of a changed password');
        }
        return [$uid, (string) ($user['login'] ?? '')];
    }

    /** A token login lives for one request - its session is neither saved nor sent as a cookie. */
    public static function forgetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        header_remove('Set-Cookie');
    }

    /** keyed, so a leaked token does not help to crack the password hash */
    private static function pwdTag(string $stamp): string
    {
        return substr(hash_hmac('sha256', $stamp, Secret::key('jwt-pwd')), 0, 16);
    }
}
