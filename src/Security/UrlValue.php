<?php

declare(strict_types=1);

namespace AA\Security;

/**
 * A value a page takes from its URL for its templates: als[NAME] of a view, slice or site page
 * ({NAME}, {_#NAME}) and the state of a site page ({xqs}, {xseo1}, ...). The URL can carry any
 * text, so the value runs only as text and _#ALIASES; a {...} expression in it is shown as text
 * unless Protection::UrlValuesAsText is switched off. Templates that build such links expand
 * their own {...} before the link is sent. The noitem text of a view the URL calls (view.php,
 * jsview.php, discussion.php with set[<vid>]=noitem-...) becomes the whole output, so its HTML
 * and _#ALIASES are text as well (template()); with the protection off only a noitem holding a
 * {...} expression runs as before.
 */
final class UrlValue
{
    /** log.type of the URL values with an expression - selector refused|allowed:<param>:page */
    public const EVENT = 'URL_EXPR';

    /** the expression to run for the value of $param; null when the value is to be shown as text */
    public static function expression(string $param, string $value): ?string
    {
        if (!self::hasExpression($value)) {
            return $value;
        }
        $allowed = !Settings::isOn(Protection::UrlValuesAsText);
        ProtectionLog::write(Protection::UrlValuesAsText, $allowed, $param, ProtectionLog::requested());
        return $allowed ? $value : null;
    }

    /** the template for a value the page expands as a whole (noitem): text, HTML and _#ALIASES included,
     *  unless it holds an {expression} that expression() lets run - then all of it runs as before */
    public static function template(string $param, string $value): string
    {
        return (self::hasExpression($value) && (self::expression($param, $value) !== null))
            ? $value
            : str_replace('_#', '_&#35;', self::asText($value));   // als[] and cmd[]=d- of the same URL fill aliases with any HTML
    }

    /** a refused value as text that no command turns back into an expression ({foreach}, {expand}, ...) */
    public static function asText(string $value): string
    {
        return strtr(safe(str_replace("\x1E", '', $value)), ['{' => '&#123;', '}' => '&#125;']);
    }

    /** "\x1E" starts the marks of quoted {}:() (QuoteColons()), which some commands turn back into braces */
    private static function hasExpression(string $value): bool
    {
        return strpbrk($value, "{\x1E") !== false;
    }
}
