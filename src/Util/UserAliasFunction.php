<?php

declare(strict_types=1);

namespace AA\Util;

use ReflectionFunction;

/**
 * The functions alias function f_u (AA_Item::f_u()) may call. The name is template text - in
 * {alias:<field>:f_u:{..}} even item data - so not any PHP callable, only a function of the installation:
 *  - USER: a usr_* function (usually in include/usr_aliasfnc.php, which include/item.php loads - the
 *    file is not checked, it may include others) - called; an installation names it usr_* on purpose;
 *  - INSTALLATION: another function the installation defines - one defined while include/item.php
 *    requires include/usr_aliasfnc.php(3) (with the helper files that one includes, from anywhere -
 *    addInstallationFunctions()), or one declared in include/usr_aliasfnc.php(3), under include/custom/,
 *    or in modules/site/sites/ (legacy site control files) - still called, so old
 *    sites keep working (NSZM's nszm_prava_conds), but logged as deprecated: rename it to usr_*;
 *  - REJECTED: empty, not a plain function name (an alias, a field id, an expression, a method), not
 *    defined, internal (PHP's own or an extension's), AA core (declared in any other file of the AA base -
 *    src/, include/, vendor/, admin/, ...) or foreign (outside the AA base: a host application,
 *    auto_prepend_file, an include_path library) - not called, logged with the file.
 * The aa-app package security lint takes the name rule (NAME) for its I level; which functions a target
 * defines it cannot know, so any other f_u stays S there.
 */
final class UserAliasFunction
{
    /** a user function name - the regex body, anchored by the users */
    public const NAME = 'usr_[A-Za-z0-9_]{1,60}';

    /** levels of classify() */
    public const USER = 'usr';
    public const INSTALLATION = 'legacy';
    public const REJECTED = 'rejected';

    /** reasons of the REJECTED level */
    public const EMPTY = 'empty';
    public const BAD_NAME = 'name';
    public const UNDEFINED = 'undefined';
    public const INTERNAL = 'internal';
    public const CORE = 'core';
    public const FOREIGN = 'foreign';
    /** logged by f_u itself: the function was called and threw a TypeError (ArgumentCountError is one) -
     *  a wrong signature or a failure inside; the file part of the row says what and where (callFailure()) */
    public const CALL_FAILED = 'call';
    /** logged instead of a name that is not a function name - in {alias:<field>:f_u:{..}} item data picks
     *  the name, it must not make a log key per value */
    public const BAD_NAME_LOGGED = '(bad name)';

    /** log.type of the rows f_u writes for a non-USER call (LogOnce - one row a day per level, slice, field, name) */
    public const LOG_EVENT = 'ALIAS_FU';

    /** a global PHP function as the template may name one - no namespace, no method, no expression */
    private const FUNCTION_NAME = '[A-Za-z_][A-Za-z0-9_]{0,79}';

    /** the installation's files, relative to the AA base: include/usr_aliasfnc.php(3) (include/item.php),
     *  include/custom/ (AA_CUSTOM_DIR - include/locsess.php, include/stringexpand.php), the legacy site
     *  control files modules/site/sites/site_*.php. Not include/config*.php - no user function belongs there */
    private const INSTALLATION_FILES = '~^(?:include/usr_aliasfnc\.php3?|include/custom/.+|modules/site/sites/site_[^/]+\.php)\z~';

    /** @var array<string, true> lower-case names defined by the require of include/usr_aliasfnc.php(3) */
    private static array $installation = [];

    /** @var array<string, array{level:string, function:?string, reason:string, file:string}> classify() of this request */
    private static array $verdicts = [];

    /** Functions the require of include/usr_aliasfnc.php(3) defined (include/item.php diffs
     *  get_defined_functions()['user'] around it) - installation functions wherever their file is.
     *  @param string[] $names */
    public static function addInstallationFunctions(array $names): void
    {
        foreach ($names as $name) {
            self::$installation[strtolower((string) $name)] = true;
        }
        self::$verdicts = [];
    }

    /** The function to call for $name, or null when $name is not a defined usr_* user function */
    public static function resolve(string $name): ?string
    {
        return preg_match('/^' . self::NAME . '\z/', $name) ? self::userFunction($name) : null;
    }

    /** The declared name of the function $name, or null when there is none or it is internal (PHP's own
     *  or an extension's) - no internal function is named usr_*, the check holds if one ever is */
    public static function userFunction(string $name): ?string
    {
        if (!function_exists($name)) {
            return null;
        }
        $function = new ReflectionFunction($name);
        return $function->isInternal() ? null : $function->getName();
    }

    /**
     * What f_u does with $name - see the class comment. Cached for the request, except "undefined"
     * (a later include may define it).
     * @param ?string $base the AA base directory, AA_BASE_PATH when null
     * @return array{level:string, function:?string, reason:string, file:string}  function = what to call
     *         (USER and INSTALLATION), reason = one of the REJECTED reasons, file = where the function is
     *         declared, relative to $base when inside it ('' for USER, which does not need it)
     */
    public static function classify(string $name, ?string $base = null): array
    {
        $base ??= defined('AA_BASE_PATH') ? (string) constant('AA_BASE_PATH') : '';
        $key    = $base . "\0" . $name;
        if (isset(self::$verdicts[$key])) {
            return self::$verdicts[$key];
        }
        $verdict = self::judge($name, $base);
        if ($verdict['reason'] !== self::UNDEFINED) {
            self::$verdicts[$key] = $verdict;
        }
        return $verdict;
    }

    /** @return array{level:string, function:?string, reason:string, file:string} */
    private static function judge(string $name, string $base): array
    {
        $verdict = static fn(string $level, ?string $function = null, string $reason = '', string $file = ''): array => ['level' => $level, 'function' => $function, 'reason' => $reason, 'file' => $file];
        if ($name === '') {
            return $verdict(self::REJECTED, reason: self::EMPTY);
        }
        if (!preg_match('/^' . self::FUNCTION_NAME . '\z/', $name)) {
            return $verdict(self::REJECTED, reason: self::BAD_NAME);
        }
        if (!function_exists($name)) {
            return $verdict(self::REJECTED, reason: self::UNDEFINED);
        }
        $function = new ReflectionFunction($name);
        if ($function->isInternal()) {
            return $verdict(self::REJECTED, reason: self::INTERNAL);
        }
        if (preg_match('/^' . self::NAME . '\z/', $name)) {
            return $verdict(self::USER, $function->getName());     // the usual case - no file look-up
        }
        $file     = (string) $function->getFileName();
        $relative = self::relative($file, $base);
        // the require diff also tags what usr_aliasfnc.php pulls from AA (a required AA file, an autoloaded
        // class file with functions) - a tagged function counts only outside AA or in an installation file
        $tagged = isset(self::$installation[strtolower($function->getName())]) && ($relative === null || preg_match(self::INSTALLATION_FILES, $relative) === 1);
        if ($tagged || self::isInstallationFile($file, $base)) {
            return $verdict(self::INSTALLATION, $function->getName(), file: $relative ?? $file);
        }
        return $verdict(self::REJECTED, reason: ($relative === null) ? self::FOREIGN : self::CORE, file: $relative ?? $file);
    }

    /** Is $file one of the installation's files inside the AA base $base (INSTALLATION_FILES)? A file
     *  outside the base is not - that is a host application's, auto_prepend_file or an include_path
     *  library. Code defined by eval() is neither. */
    public static function isInstallationFile(string $file, string $base): bool
    {
        if ($file === '' || str_contains($file, "eval()'d code") || str_contains($file, '/../') || $base === '') {
            return false;
        }
        $relative = self::relative($file, $base);
        return $relative !== null && preg_match(self::INSTALLATION_FILES, $relative) === 1;
    }

    /** What f_u logs when the call threw: "<class> at <file>:<line>", the file relative to the AA base */
    public static function callFailure(\Throwable $e, ?string $base = null): string
    {
        $base ??= defined('AA_BASE_PATH') ? (string) constant('AA_BASE_PATH') : '';
        return get_class($e) . ' at ' . (self::relative($e->getFile(), $base) ?? $e->getFile()) . ':' . $e->getLine();
    }

    /** $file relative to the AA base $base (as given or resolved - AA may be reached through a symlink),
     *  null when it lies outside */
    private static function relative(string $file, string $base): ?string
    {
        if ($base === '') {
            return null;
        }
        foreach (array_unique([rtrim($base, '/') . '/', rtrim(realpath($base) ?: $base, '/') . '/']) as $root) {
            if (str_starts_with($file, $root)) {
                return substr($file, strlen($root));
            }
        }
        return null;
    }

    /**
     * Logs a call f_u did not make as a plain usr_* call - deprecated, refused or failed - at most once a
     * day per level, slice, field and function name (LogOnce). The log page (admin/aa_log.php) shows the
     * selector, so only name characters get there: the function name keeps [A-Za-z0-9_] (a name that is
     * not a function name is logged as BAD_NAME_LOGGED), the field id [A-Za-z0-9_.], the file part
     * [A-Za-z0-9_./: -]; anything else becomes '?'.
     * @param array{level:string, function:?string, reason:string, file:string} $verdict  classify()
     */
    public static function log(array $verdict, string $name, string $field_id, string $slice_id): void
    {
        LogOnce::daily(
            self::LOG_EVENT,
            ParamImplode([
                $verdict['level'],
                (string) preg_replace('/[^0-9a-f]/', '?', substr($slice_id, 0, 32)),
                (string) preg_replace('/[^A-Za-z0-9_.]/', '?', substr($field_id, 0, 40)),
                ($verdict['reason'] === self::BAD_NAME) ? self::BAD_NAME_LOGGED : (string) preg_replace('/[^A-Za-z0-9_]/', '?', substr($name, 0, 80)),
            ]),
            [$verdict['reason'], (string) preg_replace('~[^A-Za-z0-9_./: -]~', '?', $verdict['file'])],
        );
    }

    /** the parts of a LOG_EVENT selector
     *  @return array{level:string, slice_id:string, field_id:string, name:string} */
    public static function parseLogSelector(string $selector): array
    {
        [$level, $slice_id, $field_id, $name] = array_pad(ParamExplode($selector), 4, '');
        return ['level' => $level, 'slice_id' => $slice_id, 'field_id' => $field_id, 'name' => $name];
    }
}
