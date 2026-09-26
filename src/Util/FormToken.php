<?php

declare(strict_types=1);

namespace AA\Util;

/** CSRF token for admin POST forms, derived from the session id instead of
 *  stored in it - nothing to lose or clean up. The purpose keeps a token of
 *  one form from being replayed on another.
 */
final class FormToken
{
    public static function get(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, session_id() . constant('AA_ID'));
    }

    public static function check(string $purpose, string $token): bool
    {
        return ($token !== '') && hash_equals(self::get($purpose), $token);
    }

    /** Gate of a write: a POST carrying this form's token. A link from another site is a GET
     *  (the SameSite=Lax cookie rides it), and a cross-site POST cannot know the token.
     *  @param array $server $_SERVER */
    public static function checkPost(string $purpose, string $token, array $server): bool
    {
        return (($server['REQUEST_METHOD'] ?? '') === 'POST') && self::check($purpose, $token);
    }

    /** the hidden input for a form */
    public static function field(string $purpose): string
    {
        return '<input type="hidden" name="token" value="' . safe(self::get($purpose)) . '">';
    }
}
