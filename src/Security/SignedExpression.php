<?php

declare(strict_types=1);

namespace AA\Security;

use AA\Util\LogOnce;
use AA\Util\Secret;
use AA_Item;
use AA_Slice;

/**
 * An AA expression a page hands to the browser for the server to run later: what an {ajax} edit or
 * an inline form shows after the save (filler.php ret_code). The request can carry any text, so AA
 * runs a {...} expression from it only when AA signed it; unsigned, only text, _#ALIASES and the
 * harmless forms of isHarmless() - unless Protection::SignedExpressions is switched off.
 */
final class SignedExpression
{
    /** log.type of the unsigned expressions that needed a signature - selector refused|allowed:parameter:page */
    public const EVENT = 'EXPR_SIGN';

    /** base64url and hex have no '.', and a plain base64 value (the old ret_code_enc) never starts so */
    private const PREFIX = 'aa1.';

    /**
     * URL safe: the AA JS library appends the value to the query unencoded. Sign only what the
     * template author wrote - an expression carrying visitor data ({qs:...}) would hand the visitor
     * a signed command.
     */
    public static function sign(string $expression): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode($expression), '+/', '-_'), '=') . '.' . self::mac($expression);
    }

    public static function isSigned(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** the expression of a value AA signed; null for anything else */
    public static function verify(string $value): ?string
    {
        $expression = self::payload($value);
        return (!is_null($expression) && hash_equals(self::mac($expression), explode('.', $value)[2])) ? $expression : null;
    }

    /**
     * Whether an unsigned expression may run: text and _#ALIASES of the item always; of the {...}
     * forms only those AA generated before it signed them - a field, an f_h alias of a field, a
     * {-quoted text} and a {_#ALIAS___} - so pages cached before keep working.
     * @param callable(string): bool $is_field whether a 16-character id is a field of the item's slice
     */
    public static function isHarmless(string $expression, callable $is_field): bool
    {
        // innermost first, as AA expands them: {-Thanks, {headline........}} is harmless too
        do {
            $expression = (string) preg_replace_callback(
                '/\{(?:alias:([a-z0-9_.]{16}):f_h(?::[^{}:]*)?|([a-z0-9_.]{16})|-[^{}]*|_#[0-9A-Z_]{8})\}/',
                // a 16-character name that is no field could be a command ({include.........})
                static fn(array $m): string => (($field = ($m[1] ?? '') ?: ($m[2] ?? '')) === '') || $is_field($field) ? '' : "\0",
                $expression,
                -1,
                $count,
            );
        } while ($count > 0);
        // the mark of a refused name never forms a new {...} with the text around it
        return !str_contains($expression, '{') && !str_contains($expression, "\0");
    }

    /**
     * The expression to run for a value from the request: a signed one, a harmless one, or - while
     * the protection is off - any; null when it is refused. Refusals and unsigned runs are logged
     * once a day per parameter and page for State Check.
     * @param string                 $param    the request parameter, for the log
     * @param callable(string): bool $is_field see isHarmless()
     */
    public static function fromRequest(string $value, string $param, callable $is_field): ?string
    {
        $signed = self::verify($value);
        if (!is_null($signed)) {
            return $signed;
        }
        // a signature that does not match is a changed value, never text to print
        if (!self::isSigned($value) && self::isHarmless($value, $is_field)) {
            return $value;
        }
        // a new key (AA_SECRET added, DB password changed) breaks the signatures in cached pages; switching the protection off gets them through
        $expression = self::isSigned($value) ? self::payload($value) : $value;
        $allowed    = !is_null($expression) && !Settings::isOn(Protection::SignedExpressions);
        // the outcome is part of the selector: a refusal after the protection is switched on again is a new row the same day
        LogOnce::daily(self::EVENT, ParamImplode([$allowed ? 'allowed' : 'refused', $param, self::page()]));
        return $allowed ? $expression : null;
    }

    /** the field test of isHarmless() for an item: the fields of its slice
     *  @return callable(string): bool
     */
    public static function fieldsOf(?AA_Item $item): callable
    {
        $slice = $item ? AA_Slice::getModule($item->getSliceID()) : null;
        return static fn(string $field_id): bool => !is_null($slice?->getField($field_id));
    }

    /** a log row's selector as [refused, parameter, page]
     *  @return array{bool, string, string}
     */
    public static function logged(string $selector): array
    {
        [$outcome, $param, $page] = array_pad(ParamExplode($selector), 3, '');
        return [$outcome !== 'allowed', (string) $param, (string) $page];
    }

    /** the page the form was on (Referer without its query), so the template can be found */
    private static function page(): string
    {
        $referer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        return is_array($referer) ? ($referer['host'] ?? '') . ($referer['path'] ?? '') : '';
    }

    /** the expression in a signed value, whether the signature matches or not */
    private static function payload(string $value): ?string
    {
        $parts = explode('.', $value);
        if (!self::isSigned($value) || (count($parts) !== 3)) {
            return null;
        }
        $expression = base64_decode(strtr($parts[1], '-_', '+/'), true);
        return ($expression === false) ? null : $expression;
    }

    private static function mac(string $expression): string
    {
        return hash_hmac('sha256', $expression, Secret::key('signed-expression'));
    }
}
