<?php

declare(strict_types=1);

namespace AA\Security;

/**
 * A value a page takes from its URL for its templates: als[NAME] of a view, slice or site page
 * ({NAME}, {_#NAME}) and the state of a site page ({xqs}, {xseo1}, ...). The URL can carry any
 * text, so the value runs only as text and _#ALIASES; a {...} expression in it is shown as text
 * unless Protection::UrlValuesAsText is switched off. Templates that build such links expand
 * their own {...} before the link is sent.
 */
final class UrlValue
{
    /** log.type of the URL values with an expression - selector refused|allowed:<param>:page */
    public const EVENT = 'URL_EXPR';

    /** the expression to run for the value of $param; null when the value is to be shown as text */
    public static function expression(string $param, string $value): ?string
    {
        // "\x1E" starts the marks of quoted {}:() (QuoteColons()), which some commands turn back into braces
        if (strpbrk($value, "{\x1E") === false) {
            return $value;
        }
        $allowed = !Settings::isOn(Protection::UrlValuesAsText);
        ProtectionLog::write(Protection::UrlValuesAsText, $allowed, $param, ProtectionLog::requested());
        return $allowed ? $value : null;
    }

    /** a refused value as text that no command turns back into an expression ({foreach}, {expand}, ...) */
    public static function asText(string $value): string
    {
        return strtr(safe(str_replace("\x1E", '', $value)), ['{' => '&#123;', '}' => '&#125;']);
    }
}
