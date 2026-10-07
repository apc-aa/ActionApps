<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Native lazy loading of images and iframes in markup AA generates.
 *
 * A browser downloads an <img> even inside a display:none element; only
 * loading=lazy postpones it until the element is shown near the viewport.
 * The hero image must not be lazy - that delays the page's main paint (LCP).
 */
final class LazyLoad
{
    /**
     * An <img> or <iframe> tag. As in the HTML tokenizer, a quote opens a value only right
     * after '=', so '<' or '>' inside a quoted value stays in the tag. The name must be
     * followed by a space or '>': after '/' the tag may sit inside another tag's unquoted
     * value, which the inserted space would split into new attributes. Possessive: a scan
     * never backtracks.
     */
    private const TAG = '~<(img|iframe)(?=[\s>])((?:[^<>=]++|=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s<>]*+))*+)>~i';

    /**
     * One attribute (name, value) or one stray character, read in order (\G), so a 'loading='
     * inside a value never counts. As in HTML, a name may start with '=' ('=loading' is not loading).
     */
    private const ATTRIBUTE = '~\G\s*+(?:(=[^\s/>=]*+|[^\s/>=]++)(?:\s*+=\s*+("[^"]*+"|\'[^\']*+\'|[^\s>]*+))?|.)~s';

    /**
     * Makes every <img> and <iframe> lazy - for markup nobody sees yet (hidden rotator frames),
     * where even an eager hero must wait: loading=lazy goes first, and the first one wins.
     * Only inserts, never removes: ' loading=lazy' holds no quote or backslash, so markup
     * inside a JS string or an attribute value stays intact.
     */
    public static function markup(string $html): string
    {
        return preg_replace_callback(
            self::TAG,
            static fn(array $m): string => (self::loading($m[2]) === 'lazy') ? $m[0] : '<' . $m[1] . ' loading=lazy' . substr($m[0], \strlen($m[1]) + 1),
            $html,
        ) ?? $html;
    }

    /**
     * Loading attribute for a tag carrying the template's extra attributes $attrs:
     * 'loading=lazy', or '' when $attrs choose it themselves (loading=eager for a hero image).
     */
    public static function attr(string $attrs): string
    {
        return (self::loading($attrs) === null) ? 'loading=lazy' : '';
    }

    /** Whether the tag ends up lazy - attr() makes it so unless $attrs choose another loading.
     *  Only a lazy image may let the browser pick from its srcset by layout (sizes="auto"). */
    public static function isLazy(string $attrs): bool
    {
        return \in_array(self::loading($attrs), [null, 'lazy'], true);
    }

    /** Value of the first attribute $name in $attrs, unquoted; null without one. */
    public static function attribute(string $attrs, string $name): ?string
    {
        if ((stripos($attrs, $name) === false) || !preg_match_all(self::ATTRIBUTE, $attrs, $tokens, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($tokens as $token) {
            if (strcasecmp($token[1] ?? '', $name) === 0) {
                return trim($token[2] ?? '', '"\'');
            }
        }
        return null;
    }

    /** Value of the first loading attribute in $attrs, lowercase; null without one. */
    private static function loading(string $attrs): ?string
    {
        $value = self::attribute($attrs, 'loading');
        return ($value === null) ? null : strtolower($value);
    }
}
