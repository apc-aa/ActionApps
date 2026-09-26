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

    /** a short token (another AA pulling an app) lives a day at most: it may transfer password hashes */
    public const SHORT_LIFETIME_MAX = 24 * 3600;
    private const SHORT_LIFETIME_MIN = 60;

    /** every command of the responder - what the token of Central always was */
    public const SCOPE_ADMIN = 'admin';
    /** only the commands another AA needs to pull an app (ResponderPolicy::scopeAllows()) - a leaked
     *  one cannot change this AA */
    public const SCOPE_APPS = 'apps';

    /** @param ?int $ttl seconds of a short token, within a minute and SHORT_LIFETIME_MAX; null = a year (a day for apps)
     *  @return string the token, '' for an id no backend knows or whose password change it cannot tell, or an unknown scope */
    public static function issue(string $user_id, ?int $now = null, ?int $ttl = null, string $scope = self::SCOPE_ADMIN): string
    {
        if (!in_array($scope, [self::SCOPE_ADMIN, self::SCOPE_APPS], true) || !AA::$perm->getIDsInfo($user_id) || (($stamp = AA::$perm->credentialStamp($user_id)) === null)) {
            return '';
        }
        $now ??= time();
        // an apps token may transfer password hashes - a day at most, whatever was asked
        $ttl    ??= ($scope === self::SCOPE_APPS) ? self::SHORT_LIFETIME_MAX : null;
        $lifetime = ($ttl === null) ? self::LIFETIME : min(max($ttl, self::SHORT_LIFETIME_MIN), self::SHORT_LIFETIME_MAX);
        $claims   = ['sub' => $user_id, 'iat' => $now, 'exp' => $now + $lifetime, 'pwd' => self::pwdTag($stamp)];
        // no claim for admin: the tokens issued before scopes existed are admin ones
        return JWT::encode(($scope === self::SCOPE_APPS) ? $claims + ['scp' => $scope] : $claims, Secret::key('jwt'), 'HS256');
    }

    /** @return array{0: string, 1: string, 2: string} user id, login name, scope
     *  @throws \Throwable why the token is refused; the message never contains the token */
    public static function verify(string $jwt): array
    {
        $claims = JWT::decode($jwt, new Key(Secret::key('jwt'), 'HS256'));
        $uid    = (string) ($claims->sub ?? '');
        $user   = AA::$perm->getIDsInfo($uid);
        $stamp  = $user ? AA::$perm->credentialStamp($uid) : null;
        $scope  = $claims->scp ?? self::SCOPE_ADMIN;
        if (!isset($claims->exp) || ($stamp === null) || !hash_equals(self::pwdTag($stamp), (string) ($claims->pwd ?? '')) || !in_array($scope, [self::SCOPE_ADMIN, self::SCOPE_APPS], true)) {
            throw new UnexpectedValueException('Token without exp or pwd, of an unknown user or scope, or of a changed password');
        }
        return [$uid, (string) ($user['login'] ?? ''), $scope];
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
