<?php
namespace AA\Util;

class InputVars {
    public static function intPOST(string $name): int       { return (int)$_POST[$name];    }
    public static function boolPOST(string $name): bool     { return (bool)$_POST[$name];   }

    /* @deprecated - use rather stringPOST - without adding artifical qoutes */
    public static function stringSlashedPOST(string $name): string { return addslashes($_POST[$name]); }
    public static function stringPOST(string $name): string { return (string)$_POST[$name]; }
    public static function idPOST(string $name): string     { return is_long_id((string)$_POST[$name]) ? (string)$_POST[$name] : ''; } // long id

    /* @deprecated - use rather arrayPOST - without adding artifical qoutes */
    public static function arraySlashedPOST(string $name): array   { return (array)self::AddslashesArray($_POST[$name]);  }
    public static function arrayPOST(string $name): array         { return (array)$_POST[$name];  }

    public static function intGET(string $name): int        { return (int)$_GET[$name];    }
    public static function boolGET(string $name): bool      { return (bool)$_GET[$name];   }

    /* @deprecated - use rather stringPOST - without adding artifical qoutes */
    public static function stringSlashedGET(string $name): string  { return addslashes($_GET[$name]); }
    public static function stringGET(string $name): string  { return (string)$_GET[$name]; }
    public static function idGET(string $name): string      { return is_long_id((string)$_GET[$name]) ? (string)$_GET[$name] : ''; }  // long id

    /* @deprecated - use rather arrayGET - without adding artifical qoutes */
    public static function arraySlashedGET(string $name): array    { return (array)self::AddslashesArray($_GET[$name]);  }
    public static function arrayGET(string $name): array    { return (array)$_GET[$name];  }

    /** AddslashesArray function
     * function addslashes enhanced by array processing
     * @param $value
     * @return array|string
     */
    public static function AddslashesArray($value) {
        return is_array($value) ? array_map(["\AA\Util\InputVars", "AddslashesArray"], $value) : addslashes($value);
    }

    public static function StripslashesArray($value) {
        return is_array($value) ? array_map(["\AA\Util\InputVars", "StripslashesArray"], $value) : stripslashes($value);
    }

    /** QuoteVars function
     * function for processing posted or get variables
     * adds quotes, if magic_quotes are switched off
     * except of variables in $skip array (usefull for 'encap' for example)
     * @param $method
     * @param $skip
     */
    public static function QuoteVars($method = "get", $skip = '') {
        $arr = ($method == "get") ? $_GET : $_POST;
        foreach ($arr as $k => $v) {
            if (!is_array($skip) or !isset($skip[$k])) {
                $GLOBALS[$k] = InputVars::AddslashesArray($v);
            }
        }
    }
}