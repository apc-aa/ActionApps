<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA_Log;
use Throwable;

/**
 * Log rows for something that recurs on every page view - a legacy call AA refuses or still allows
 * with a warning (f_u of a non-usr_ function, an Alertitem send through an unrelated collection).
 * At most one row per event + selector a day: the selector is the identity of the problem (which
 * function in which field of which slice, ...), the row says "it happened on this day", and State
 * Check groups the rows back (recent()). Within one request the key is looked up once - a listing
 * of 50 items with the same broken field asks the database once and writes at most one row.
 *
 * The daily look-up (type + time over the type_time index, then selector) is the persistent
 * dedupe - there is no counter to update. Two requests racing at midnight may both write; that is
 * one extra row, not a flood. Logging never breaks the page: a database error just skips the row.
 */
final class LogOnce
{
    /** widths of log.selector and log.params (include/metabase_schema.php) - an over-long value fails
     *  the INSERT under STRICT_TRANS_TABLES, and the look-up has to match what is stored */
    private const SELECTOR_MAX = 255;
    private const PARAMS_MAX = 128;

    /** @var array<string, true> event + selector handled in this request - in fact in this PHP process:
     *  a long job (cron, a queue run spanning midnight) keeps it and would skip the next day's row, so it
     *  may call reset() between tasks */
    private static array $seen = [];

    /**
     * Writes the row unless this event + selector was logged today (or already handled in this request).
     * @param string          $event    log.type, at most AA_Log::TYPE_MAX bytes
     * @param string          $selector the identity of the problem; cut to the column width
     * @param string|string[] $params   what else is worth knowing; an array is joined as AA_Log joins it
     * @param ?int            $now      unixtime, for tests; "today" is the local calendar day
     * @return bool  a row was written
     */
    public static function daily(string $event, string $selector, string|array $params = '', ?int $now = null): bool
    {
        $selector = mb_strcut($selector, 0, self::SELECTOR_MAX);
        $key      = $event . "\0" . $selector;
        if (isset(self::$seen[$key])) {
            return false;
        }
        self::$seen[$key] = true;

        $params = mb_strcut(is_array($params) ? ParamImplode($params) : $params, 0, self::PARAMS_MAX);
        $today  = (int) strtotime('today', $now ?? time());
        try {
            if (DB_AA::test('log', [['type', $event], ['time', $today, '>='], ['selector', $selector]])) {
                return false;
            }
            return AA_Log::write($event, $selector, $params);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The rows of $event since $since grouped by selector, newest first - what State Check shows.
     * `days` is the number of days it was seen on (daily() writes one row a day).
     * @return list<array{selector:string, days:string, first:string, last:string, params:string}>
     */
    public static function recent(string $event, int $since, int $limit = 50): array
    {
        return DB_AA::select(
            [],
            'SELECT `selector`, COUNT(*) AS days, MIN(`time`) AS first, MAX(`time`) AS last, MAX(`params`) AS params FROM `log`',
            [['type', $event], ['time', $since, '>=']],
            ['last-'],
            ['selector'],
            $limit,
        );
    }

    /** forgets what this request has logged - for tests and long-running jobs */
    public static function reset(): void
    {
        self::$seen = [];
    }
}
