<?php

namespace AA\Util;

use RuntimeException;

class InputVars
{
    /** @throws RuntimeException */
    private static function inputCheck(string $name, $value, string $type, bool $required): string|int|bool|array
    {
        $valid = !$required || match ($type) {
            'int'    => is_numeric($value) && (int) $value == $value,
            'bool'   => in_array((string) $value, ['0', '1'], true),
            'string' => is_string($value) && (trim($value) !== ''),
            'quoted' => is_string($value) && (trim($value) !== ''),    // @deprecated - use string
            'id'     => is_long_id((string) $value),
            'array'  => is_array($value),
            'qarray' => is_array($value),                             // @deprecated - use array
            default  => false,
        };
        if (!$valid) {
            $report_val = Txt::shorten(is_array($value) ? print_r($value, true) : (string) $value, 20);
            throw new RuntimeException("$name/$type ($report_val): Required, but not filed.");
        }
        return match ($type) {
            'int'    => (int) $value,
            'bool'   => (bool) $value,
            'string' => (string) $value,
            'quoted' => addslashes((string) $value),                      // @deprecated - use string
            'id'     => is_long_id((string) $value) ? (string) $value : '',
            'array'  => is_array($value) ? $value : [],
            'qarray' => (array) self::AddslashesArray($value),            // @deprecated - use array
            default  => $value, // fallback for other types
        };
    }

    // --- POST ---

    /** @throws RuntimeException */
    public static function intPOST(string $name, bool $required = false): int
    {
        return self::inputCheck($name, $_POST[$name], 'int', $required);
    }

    /** @throws RuntimeException */
    public static function boolPOST(string $name, bool $required = false): bool
    {
        return self::inputCheck($name, $_POST[$name], 'bool', $required);
    }

    /** @deprecated - use rather stringPOST - without adding artificial quotes
     *  @throws RuntimeException
     */
    public static function stringSlashedPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_POST[$name], 'quoted', $required);
    }

    /** @throws RuntimeException */
    public static function stringPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_POST[$name], 'string', $required);
    }

    /** Long ID
     *  @throws RuntimeException
     */
    public static function idPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_POST[$name], 'id', $required);
    } // long id

    /** @deprecated - use rather arrayPOST - without adding artificial quotes
     *  @throws RuntimeException
     */
    public static function arraySlashedPOST(string $name, bool $required = false): array
    {
        return self::inputCheck($name, $_POST[$name], 'qarray', $required);
    }

    /** @throws RuntimeException */
    public static function arrayPOST(string $name, bool $required = false): array
    {
        return self::inputCheck($name, $_POST[$name], 'array', $required);
    }

    // --- GET ---

    /** @throws RuntimeException */
    public static function intGET(string $name, bool $required = false): int
    {
        return self::inputCheck($name, $_GET[$name], 'int', $required);
    }

    /** @throws RuntimeException */
    public static function boolGET(string $name, bool $required = false): bool
    {
        return self::inputCheck($name, $_GET[$name], 'bool', $required);
    }

    /** @deprecated - use rather stringGET - without adding artificial quotes
     *  @throws RuntimeException
     */
    public static function stringSlashedGET(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name], 'quoted', $required);
    }

    /** @throws RuntimeException */
    public static function stringGET(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name], 'string', $required);
    }

    /** Long ID
     *  @throws RuntimeException
     */
    public static function idGET(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name], 'id', $required);
    }

    /** @deprecated - use rather arrayGET - without adding artificial quotes
     *  @throws RuntimeException
     */
    public static function arraySlashedGET(string $name, bool $required = false): array
    {
        return self::inputCheck($name, $_GET[$name], 'qarray', $required);
    }

    /** @throws RuntimeException */
    public static function arrayGET(string $name, bool $required = false): array
    {
        return self::inputCheck($name, $_GET[$name], 'array', $required);
    }

    // --- GET + POST ---

    /** @throws RuntimeException */
    public static function boolGETPOST(string $name, bool $required = false): bool
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'bool', $required);

    }

    /** @throws RuntimeException */
    public static function intGETPOST(string $name, bool $required = false): int
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'int', $required);
    }

    /** @deprecated - use rather stringGETPOST - without adding artificial quotes
     *  @throws RuntimeException
     */
    public static function stringSlashedGETPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'quoted', $required);
    }

    /** @throws RuntimeException */
    public static function stringGETPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'string', $required);
    }

    /** Long ID
     *  @throws RuntimeException
     */
    public static function idGETPOST(string $name, bool $required = false): string
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'id', $required);
    }

    /** @throws RuntimeException */
    public static function arrayGETPOST(string $name, bool $required = false): array
    {
        return self::inputCheck($name, $_GET[$name] ?? $_POST[$name], 'array', $required);
    }

    /** Status message passed in the URL after a redirect, as HTML ready to print ('' if none).
     *  Senders pass plain text, but anyone can craft the link, so it is escaped here.
     */
    public static function messageGETPOST(): string
    {
        $msg = $_GET['Msg'] ?? $_POST['Msg'] ?? '';
        return (is_string($msg) && trim($msg) !== '') ? \MsgOk(Txt::safe($msg)) : '';
    }

    // --- General function ---

    /** function addslashes enhanced by array processing
     * @param $value
     * @return array|string
     */
    public static function AddslashesArray($value): array|string
    {
        return is_array($value) ? array_map([__CLASS__, "AddslashesArray"], $value) : addslashes($value);
    }

    public static function StripslashesArray($value): array|string
    {
        return is_array($value) ? array_map([__CLASS__, "StripslashesArray"], $value) : stripslashes($value);
    }

    /** function for processing posted or get variables
     * adds quotes, if magic_quotes are switched off
     * except of variables in $skip array (useful for 'encap' for example)
     * @param string $method
     * @param array  $skip
     */
    public static function QuoteVars(string $method = "get", array $skip = []): void
    {
        $arr = ($method === "get") ? $_GET : $_POST;
        foreach ($arr as $k => $v) {
            if (!isset($skip[$k])) {
                $GLOBALS[$k] = self::AddslashesArray($v);
            }
        }
    }
}
