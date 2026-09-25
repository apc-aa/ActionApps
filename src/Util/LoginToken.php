<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

/** The long-lived login of Central to a remote AA: a JWT sent as Authorization: Bearer to
 *  central/responder.php. It carries a tag of the password hash it was issued under, so a
 *  password change revokes every token of the user.
 */
final class LoginToken
{
    private const LIFETIME = 365 * 24 * 3600;

    /** @return string the token, '' for a user outside the `users` table (reader, LDAP) */
    public static function issue(string $user_id, ?int $now = null): string
    {
        if (!($user = self::user($user_id))) {
            return '';
        }
        $now ??= time();
        return JWT::encode(['sub' => $user_id, 'iat' => $now, 'exp' => $now + self::LIFETIME, 'pwd' => self::pwdTag($user['password'])], Secret::key('jwt'), 'HS256');
    }

    /** @return array{0: string, 1: string} user id, login name
     *  @throws \Throwable why the token is refused; the message never contains the token */
    public static function verify(string $jwt): array
    {
        $claims = JWT::decode($jwt, new Key(Secret::key('jwt'), 'HS256'));
        $user   = self::user((string) ($claims->sub ?? ''));
        if (!isset($claims->exp) || !$user || !hash_equals(self::pwdTag($user['password']), (string) ($claims->pwd ?? ''))) {
            throw new UnexpectedValueException('Token without exp or pwd, of an unknown user or of a changed password');
        }
        return [(string) $claims->sub, (string) $user['uid']];
    }

    /** A token login lives for one request - its session is neither saved nor sent as a cookie. */
    public static function forgetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        header_remove('Set-Cookie');
    }

    /** @return array{uid: string, password: string}|false */
    private static function user(string $user_id): array|false
    {
        // users.id is a number - a reader's hex id would compare equal to its leading digits
        return ctype_digit($user_id) ? DB_AA::select1(['uid', 'password'], 'SELECT uid, password FROM `users`', [['id', $user_id, 'i'], ['type', 'User']]) : false;
    }

    /** keyed, so a leaked token does not help to crack the password hash */
    private static function pwdTag(string $password_hash): string
    {
        return substr(hash_hmac('sha256', $password_hash, Secret::key('jwt-pwd')), 0, 16);
    }
}
