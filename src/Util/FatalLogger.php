<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;

/**
 * Records PHP fatals and uncaught exceptions (TypeError, ArgumentCountError, …) as
 * `PHP_FATAL` rows in the `log` table, so an install without shell access can be
 * checked from the admin log / a DB query. Enabled per install by
 * `define('AA_LOG_PHP_FATALS', true)` in config.php; locsess.php calls register().
 *
 * The DB write is best-effort: if AA is not booted far enough (no AA_Log, no DB)
 * or the fatal struck inside DB_AA::transaction() (the row would go to the
 * transaction's handle and be rolled back with it), the entry only goes to
 * error_log(). Never changes how the fatal itself is
 * reported — the previous exception handler (or PHP's default) still runs.
 */
final class FatalLogger
{
    public const EVENT = 'PHP_FATAL';

    /** `log.selector` is varbinary(255), `log.params` varbinary(128) */
    private const SELECTOR_MAX = 255;
    private const PARAMS_MAX = 128;

    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR | E_USER_ERROR;

    private static bool $logged = false;

    /** @var callable|null previous exception handler, chained after ours */
    private static $previousHandler = null;

    public static function register(): void
    {
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && self::isFatal($e['type'])) {
                self::log($e['message'], $e['file'], $e['line']);
            }
        });
        self::$previousHandler = set_exception_handler(static function (\Throwable $t): void {
            self::log(get_class($t) . ': ' . $t->getMessage(), $t->getFile(), $t->getLine());
            if (self::$previousHandler !== null) {
                (self::$previousHandler)($t);
                return;
            }
            throw $t; // let PHP report it exactly as before (Uncaught … fatal)
        });
    }

    public static function isFatal(int $type): bool
    {
        return ($type & self::FATAL_TYPES) !== 0;
    }

    /**
     * Squeeze a fatal into the `log` columns: selector = "relative/file.php:line"
     * (base path stripped), params = single-line message. Both cut to the column
     * size in BYTES on a UTF-8 boundary.
     *
     * @return array{selector: string, params: string}
     */
    public static function format(string $message, string $file, int $line, string $base = ''): array
    {
        if ($base !== '' && str_starts_with($file, $base)) {
            $file = substr($file, strlen($base));
        }
        $suffix   = ':' . $line;
        $selector = $file . $suffix;
        if (strlen($selector) > self::SELECTOR_MAX) {
            // the tail in bytes, minus what is left of a cut character; mb_strcut() would move the
            // cut back to the start of the character and overshoot the column by up to 3 bytes -
            // the INSERT then fails and the fatal is not logged at all
            $tail     = substr($file, -(self::SELECTOR_MAX - strlen($suffix)));
            $selector = ltrim((string) preg_replace('/^[\x80-\xBF]+/', '', $tail), '/') . $suffix;
        }
        // mb_scrub(): preg_replace() with /u gives up on invalid UTF-8 and the message would be lost
        $params = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($message, 'UTF-8')));

        return [
            'selector' => $selector,
            'params'   => mb_strcut($params, 0, self::PARAMS_MAX),
        ];
    }

    /** the entry may go to the `log` table: AA is booted far enough and no transaction is open */
    public static function toDb(): bool
    {
        return class_exists(\AA_Log::class, false) && function_exists('AuthGetUid') && !DB_AA::inTransaction();
    }

    private static function log(string $message, string $file, int $line): void
    {
        if (self::$logged) {
            return; // a rethrown exception also surfaces in error_get_last()
        }
        self::$logged = true;
        $row = self::format($message, $file, $line, defined('AA_BASE_PATH') ? (string) AA_BASE_PATH : '');
        try {
            if (self::toDb()) {
                \AA_Log::write(self::EVENT, $row['selector'], $row['params']);
                return;
            }
        } catch (\Throwable) {
            // fall through to error_log
        }
        error_log(self::EVENT . ' ' . $row['selector'] . ' ' . $row['params']);
    }
}
