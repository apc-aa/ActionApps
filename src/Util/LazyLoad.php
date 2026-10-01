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

    /** Value of the first loading attribute in $attrs, lowercase; null without one. */
    private static function loading(string $attrs): ?string
    {
        if ((stripos($attrs, 'loading') === false) || !preg_match_all(self::ATTRIBUTE, $attrs, $tokens, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($tokens as $token) {
            if (strcasecmp($token[1] ?? '', 'loading') === 0) {
                return strtolower(trim($token[2] ?? '', '"\''));
            }
        }
        return null;
    }
}
