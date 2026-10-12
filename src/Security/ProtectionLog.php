<?php

declare(strict_types=1);

namespace AA\Security;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use Throwable;

/**
 * What a measured protection did: a row of its Protection::event() once a day per outcome,
 * parameter and page, read by State Check and admin/aa_security.php. The outcome is part of the
 * selector, so a refusal after the protection is switched on again is a new row the same day.
 */
final class ProtectionLog
{
    /** rows a day per protection; the page comes from the request, so a prober could add a row per made-up page */
    public const DAILY_MAX = 500;

    public static function write(Protection $protection, bool $allowed, string $param, string $page): void
    {
        static $full = [];
        $event = $protection->event();
        try {
            if (is_null($event) || ($full[$event] ??= (DB_AA::count('log', [['type', $event], ['time', (int) strtotime('today'), '>=']]) >= self::DAILY_MAX))) {
                return;
            }
        } catch (Throwable) {
            return;
        }
        LogOnce::daily($event, ParamImplode([$allowed ? 'allowed' : 'refused', $param, $page]));
    }

    /** a log row's selector as [refused, parameter, page]
     *  @return array{bool, string, string}
     */
    public static function parse(string $selector): array
    {
        [$outcome, $param, $page] = array_pad(ParamExplode($selector), 3, '');
        return [$outcome !== 'allowed', (string) $param, (string) $page];
    }

    /** the page a form was sent from (Referer without its query), so its template can be found */
    public static function referer(): string
    {
        $referer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        return is_array($referer) ? ($referer['host'] ?? '') . ($referer['path'] ?? '') : '';
    }

    /** the page asked for, without its query */
    public static function requested(): string
    {
        return (string) ($_SERVER['HTTP_HOST'] ?? '') . explode('?', (string) ($_SERVER['REQUEST_URI'] ?? ''), 2)[0];
    }
}
