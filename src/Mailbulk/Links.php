<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use Closure;
use htmlMimeMail;

/**
 * The web links of a mail turned into tracked ones. Only the href of <a> changes; every other tag,
 * attribute and character stays as the template wrote it. Tags are read as htmlMimeMail reads them.
 */
final class Links
{
    /**
     * @param Closure(string): string $tracked the tracked address of a decoded url; the same url = leave it
     * @param list<string> $skip urls that stay as they are (unsubscribing must not count as a click)
     */
    public static function track(string $html, Closure $tracked, array $skip): string
    {
        return preg_replace_callback(
            '~<a' . htmlMimeMail::TAG_REST . '~i',
            static function (array $tag) use ($tracked, $skip): string {
                $done = false;
                $rest = preg_replace_callback(
                    htmlMimeMail::ATTRIBUTE,
                    static function (array $a) use ($tracked, $skip, &$done): string {
                        if ($done || (strtolower($a[1] ?? '') !== 'href')) {
                            return $a[0];
                        }
                        // a browser follows only the first href
                        $done = true;
                        if (($a[2] ?? '') === '') {
                            return $a[0];
                        }
                        $quote  = in_array($a[2][0], ['"', "'"], true) ? $a[2][0] : '';
                        $raw    = ($quote === '') ? $a[2] : substr($a[2], 1, -1);
                        $url    = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if (!preg_match('~\Ahttps?://~i', $url) || in_array($url, $skip, true) || in_array($raw, $skip, true)) {
                            return $a[0];
                        }
                        $new = $tracked($url);
                        if ($new === $url) {
                            return $a[0];
                        }
                        $quote = ($quote === '') ? '"' : $quote;
                        return substr($a[0], 0, -strlen($a[2])) . $quote . safe($new) . $quote;
                    },
                    $tag[1],
                    flags: PREG_UNMATCHED_AS_NULL,
                ) ?? $tag[1];
                return substr($tag[0], 0, 2) . $rest . '>';
            },
            $html,
        ) ?? $html;
    }
}
