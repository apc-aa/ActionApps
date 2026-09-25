<?php

declare(strict_types=1);

namespace AA\Util;

use ReflectionFunction;

/**
 * The user functions alias function f_u (AA_Item::f_u()) may call: a usr_* function the installation
 * defines (usually in include/usr_aliasfnc.php, which include/item.php loads - the file is not
 * checked, it may include others). Not any PHP callable - the name is template text; an installation
 * names a function usr_* on purpose. The aa-app package security lint takes the name rule (NAME) for
 * its I level; which functions a target defines it cannot know.
 */
final class UserAliasFunction
{
    /** a user function name - the regex body, anchored by the users */
    public const NAME = 'usr_[A-Za-z0-9_]{1,60}';

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
}
