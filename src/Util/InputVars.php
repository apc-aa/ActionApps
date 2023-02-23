<?php

namespace AA\Util;

class InputVars {
    protected static function inpuCheck($source, $var, $type, $required) {
        switch ($type) {
            case 'int': $ret = (int)$source[$var]; break;
        }
        return $ret;
    }


    public static function intPOST(string $name, bool $required = false): int {
        return self::inpuCheck($_POST, $name, 'int', $required);
    }

    public static function boolPOST(string $name, bool $required = false): bool {
        return (bool)$_POST[$name];
    }

    /* @deprecated - use rather stringPOST - without adding artificial quotes */
    public static function stringSlashedPOST(string $name, bool $required = false): string {
        return addslashes($_POST[$name]);
    }

    public static function stringPOST(string $name, bool $required = false): string {
        return (string)$_POST[$name];
    }

    public static function idPOST(string $name, bool $required = false): string {
        return is_long_id((string)$_POST[$name]) ? (string)$_POST[$name] : '';
    } // long id

    /* @deprecated - use rather arrayPOST - without adding artificial quotes */
    public static function arraySlashedPOST(string $name, bool $required = false): array {
        return (array)self::AddslashesArray($_POST[$name]);
    }

    // --- GET ---

    public static function arrayPOST(string $name, bool $required = false): array {
        return (array)$_POST[$name];
    }

    public static function intGET(string $name, bool $required = false): int {
        return (int)$_GET[$name];
    }

    public static function boolGET(string $name, bool $required = false): bool {
        return (bool)$_GET[$name];
    }

    /* @deprecated - use rather stringGET - without adding artificial quotes */
    public static function stringSlashedGET(string $name, bool $required = false): string {
        return addslashes($_GET[$name]);
    }

    public static function stringGET(string $name, bool $required = false): string {
        return (string)$_GET[$name];
    }

    /**
     * @throws \Exception
     */
    public static function idGET(string $name, bool $required = false): string {
        $ret = is_long_id((string)$_GET[$name]) ? (string)$_GET[$name] : '';
        if ($required AND !$ret) {
            throw new \Exception('Required, but not filed.');
        }
        return $ret;
    }

    /* @deprecated - use rather arrayGET - without adding artificial quotes */
    public static function arraySlashedGET(string $name, bool $required = false): array {
        return (array)self::AddslashesArray($_GET[$name]);
    }

    // --- POST ---

    public static function arrayGET(string $name, bool $required = false): array {
        return (array)$_GET[$name];
    }

    public static function boolGETPOST(string $name, bool $required = false): bool {
        return isset($_GET[$name]) ? (bool)$_GET[$name] : (bool)$_POST[$name];
    }

    /* @deprecated - use rather stringGETPOST - without adding artificial quotes */
    public static function stringSlashedGETPOST(string $name, bool $required = false): string {
        return isset($_GET[$name]) ? addslashes($_GET[$name]) : addslashes($_POST[$name]);
    }

    public static function stringGETPOST(string $name, bool $required = false): string {
        return isset($_GET[$name]) ? (string)$_GET[$name] : (string)$_POST[$name];
    }

    // --- GET + POST ---

    /**
     * @throws \Exception
     */
    public static function idGETPOST(string $name, bool $required = false): string {
        $ret = is_long_id((string)$_GET[$name]) ? (string)$_GET[$name] : (is_long_id((string)$_POST[$name]) ? (string)$_POST[$name] : '');
        if ($required AND !$ret) {
            throw new \Exception('Required, but not filed.');
        }
        return $ret;
    }

    public static function arrayGETPOST(string $name, bool $required = false): array {
        return isset($_GET[$name]) ? (array)$_GET[$name] : (array)$_POST[$name];
    }

    public static function intGETPOST(string $name, bool $required = false): int {
        return isset($_GET[$name]) ? (int)$_GET[$name] : (int)$_POST[$name] ;
    }


    // --- General function ---

    /** function addslashes enhanced by array processing
     * @param $value
     * @return array|string
     */
    public static function AddslashesArray($value) {
        return is_array($value) ? array_map(["\AA\Util\InputVars", "AddslashesArray"], $value) : addslashes($value);
    }

    public static function StripslashesArray($value) {
        return is_array($value) ? array_map(["\AA\Util\InputVars", "StripslashesArray"], $value) : stripslashes($value);
    }

    /** function for processing posted or get variables
     * adds quotes, if magic_quotes are switched off
     * except of variables in $skip array (useful for 'encap' for example)
     * @param string $method
     * @param array  $skip
     */
    public static function QuoteVars(string $method = "get", array $skip = []) {
        $arr = ($method == "get") ? $_GET : $_POST;
        foreach ($arr as $k => $v) {
            if (!isset($skip[$k])) {
                $GLOBALS[$k] = InputVars::AddslashesArray($v);
            }
        }
    }
}