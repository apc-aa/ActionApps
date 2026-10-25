<?php

declare(strict_types=1);

namespace AA\Security;

use AA\Util\LogOnce;

/**
 * The charset a request names for converting a view, poll or form (convertfrom, convertto of
 * view.php, jsview.php and discussion.php - also as set[<vid>]=convertfrom-..., checked in
 * AA_Showview - modules/polls/poll.php and filler.php). Only charsets in which ASCII stays ASCII
 * and nothing else becomes ASCII are taken: AA prepares the text in ASCII terms (markup, escaping)
 * before converting it. Any other name counts as not given and is logged for State Check. Charsets
 * set by AA itself (slice, feeds, templates) go to ConvertCharset directly.
 */
final class RequestCharset
{
    /** log.type of the refused names - selector script:param, params name:page:referer */
    public const EVENT = 'CSET_DENY';

    /** in lower case - the names of ConvertCharset tables iconv knows too; not us-ascii, which
     *  ConvertCharset transliterates (“ to ") */
    public const ALLOWED = [
        'utf-8',
        'windows-1250', 'windows-1251', 'windows-1252', 'windows-1253', 'windows-1254', 'windows-1255', 'windows-1256', 'windows-1257', 'windows-1258',
        'iso-8859-1', 'iso-8859-2', 'iso-8859-3', 'iso-8859-4', 'iso-8859-5', 'iso-8859-6', 'iso-8859-7', 'iso-8859-8', 'iso-8859-9',
        'iso-8859-10', 'iso-8859-11', 'iso-8859-13', 'iso-8859-14', 'iso-8859-15', 'iso-8859-16',
    ];

    /** a name longer than any charset is cut in the log */
    private const LOGGED_MAX = 40;

    /** the log keeps the name in the characters of charset names; the rest becomes '?' (':' and '#' would shift ParamExplode()) */
    private const LOGGED_CHARS = 'A-Za-z0-9._-';

    /**
     * The charset of $param in lower case; '' when the request gave none or one not in ALLOWED.
     * @param mixed  $value  as the request gave it - a string, an array, null
     * @param string $script the entry script, so the log row names it without trusting the URL
     */
    public static function fromRequest(mixed $value, string $param, string $script): string
    {
        if (empty($value)) {
            return '';
        }
        $charset = is_string($value) ? strtolower($value) : '';
        if (in_array($charset, self::ALLOWED, true)) {
            return $charset;
        }
        LogOnce::daily(self::EVENT, ParamImplode([$script, $param]), [
            is_string($value) ? self::logged($value) : get_debug_type($value),
            ProtectionLog::requested(),
            ProtectionLog::referer(),
        ]);
        return '';
    }

    /** the name as the log keeps it - escaping of the entry script (addslashes) left out */
    private static function logged(string $value): string
    {
        return (string) preg_replace('/[^' . self::LOGGED_CHARS . ']/', '?', substr(stripslashes($value), 0, self::LOGGED_MAX));
    }
}
