<?php

declare(strict_types=1);

/**
 * Global-namespace stand-ins for include/util.php helpers (util.php itself
 * cannot load in the unit suite: it pulls the whole locsess boot). A shim
 * declared inside a namespaced test class lands in that namespace and never
 * satisfies a global call from include/, hence this file. Guarded so it
 * coexists with whatever an earlier test in the same run has already defined.
 */
if (!defined('AA_INSTAL_URL')) {
    define('AA_INSTAL_URL', 'https://example.org/aa/');
}
if (!function_exists('ParamImplode')) {
    function ParamImplode($param): string
    {
        if (!is_array($param)) {
            return (string) $param;
        }
        array_walk($param, static function (&$v) {
            $v = str_replace(':', '#:', $v);
        });
        return implode(':', $param);
    }
}
if (!function_exists('warn')) {
    function warn($msg = ''): void {}
}
if (!function_exists('get_class_name')) {
    function get_class_name($object, bool $add_id = false): string
    {
        $classname = is_object($object) ? get_class($object) : $object;
        if ($pos = strrpos($classname, '\\')) {
            $classname = substr($classname, $pos + 1);
        }
        if ($add_id && method_exists($object, 'getId')) {
            $classname .= ':' . $object->getId();
        }
        return $classname;
    }
}
if (!function_exists('safe')) {
    function safe($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5);
    }
}
// the real _m(): include/mgettext.php loads on its own and is pulled in anyway
// by include/widget.class.php, which would then redeclare a shim
if (!function_exists('_m')) {
    require_once __DIR__ . '/../../../include/mgettext.php';
}
