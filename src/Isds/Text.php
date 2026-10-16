<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * Texts of a message (subject, file names, ...) without control characters. ISDS changes some other
 * characters - a no-break space (U+00A0, U+202F) and U+2028/2029 become a space, a soft hyphen and
 * the invisible U+200B-200F, 202A-202E, 2061-206F are dropped (WS_manipulace_s_datovymi_zpravami.pdf
 * 1.3.5) - so only a printable-ASCII text ($ascii) is sure to come back as it was sent. Rare ones it
 * refuses (an undefined code point, 1225) pass here: ISDS then refuses the message, nothing is sent.
 */
final class Text
{
    /**
     * Control characters (C0 with NUL, DEL, C1) are refused, not passed on: ISDS drops or replaces
     * some and answers 1225 to others (WS_manipulace_s_datovymi_zpravami.pdf 1.3.5), a NUL cuts a
     * file name, and U+FFFE/U+FFFF are no XML characters at all.
     */
    private const FORBIDDEN = '/[\x{0}-\x{1F}\x{7F}-\x{9F}\x{FFFE}\x{FFFF}]/u';

    /**
     * $value with spaces and line ends around it removed
     * @param bool $ascii printable ASCII only - for a text looked up later, which ISDS must not change
     * @throws \InvalidArgumentException
     */
    public static function check(string $what, string $value, int $max, bool $ascii = false): string
    {
        $value = trim($value, " \t\r\n");
        if (!mb_check_encoding($value, 'UTF-8') || (preg_match(self::FORBIDDEN, $value) === 1)) {
            throw new \InvalidArgumentException("$what is not plain UTF-8 text");
        }
        if ($ascii && (preg_match('/^[\x20-\x7E]*\z/', $value) !== 1)) {
            throw new \InvalidArgumentException("$what may only have letters, digits and signs of ASCII");
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException("$what is longer than $max characters");
        }
        return $value;
    }
}
