<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Generates the tags for external JS/CSS resources - both the <script>/<link>
 * form used in the page head and the loader-call form used when a form arrives
 * over AJAX and there is no head to write into.
 *
 * Extracted from AA_Req_Lib / AA_Req_Css: include/stringexpand.php cannot be
 * loaded in a test (it cascades into perm_core.php -> util.php -> locsess.php
 * and boots the session, the DB and the cron fallback), so that string logic
 * had no way of being covered.
 */
final class ScriptTag
{
    /**
     * Escaping flags for values embedded into generated JavaScript.
     *
     * HEX_APOS keeps a quote from closing the single-quoted URL literal,
     * HEX_QUOT does the same for the double-quoted variant, and HEX_TAG plus
     * HEX_AMP keep "</script>" from ending the block the snippet lives in.
     */
    private const JS_JSON = JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;

    /** <script> tag for the page head. */
    public static function head(string $url, string $integrity = '', string $loadtype = ''): string
    {
        if ($url === '') {
            return '';
        }
        $attrs = match ($loadtype) {
            'module' => ' type="module"',
            ''       => '',
            default  => ' ' . $loadtype,     // defer | async
        };

        return '<script' . $attrs . ' src="' . Txt::safe($url) . '"' . self::integrityAttrs($integrity) . '></script>';
    }

    /** <link rel=stylesheet> tag for the page head. */
    public static function css(string $url, string $integrity = '', string $loadtype = ''): string
    {
        if ($url === '') {
            return '';
        }
        // "async": load as a print stylesheet, then switch it to all on load.
        $media = match ($loadtype) {
            'async' => ' media="print" onload="this.media=\'all\'"',
            ''      => '',
            default => ' media="' . Txt::safe($loadtype) . '"',
        };

        return '<link rel="stylesheet" href="' . Txt::safe($url) . '"' . self::integrityAttrs($integrity) . $media . '>';
    }

    /** AA_LoadJs() call used when the markup arrives over AJAX. */
    public static function ajaxJs(string $url, string $integrity = '', string $loadtype = ''): string
    {
        if (trim($url) === '') {
            return '';
        }
        $options = [];
        if ($loadtype === 'module') {
            $options['type'] = 'module';
        }
        if ($integrity !== '') {
            $options['integrity'] = $integrity;
        }
        // The 4th argument sets script.async = false: CodeMirror 5 addons did
        // not load without the insertion order being kept.
        $args = "'load_once',AA_IsLoaded," . self::jsString($url) . ',true';
        if ($options !== []) {
            $args .= ',' . json_encode($options, self::JS_JSON);
        }

        return "AA_LoadJs($args);";
    }

    /** AA_LoadCss() call used when the markup arrives over AJAX. */
    public static function ajaxCss(string $url, string $loadtype = ''): string
    {
        if ($loadtype === 'print' || trim($url) === '') {
            return '';
        }

        return 'AA_LoadCss(' . self::jsString($url) . ');';
    }

    private static function integrityAttrs(string $integrity): string
    {
        return $integrity === '' ? '' : ' integrity="' . Txt::safe($integrity) . '" crossorigin="anonymous"';
    }

    /** Quotes a value for use inside a single-quoted JavaScript string literal. */
    private static function jsString(string $value): string
    {
        // Slice the JSON delimiters rather than trimming them - trim('"') would
        // also eat an escaped quote at the end of the value and leave the
        // literal open.
        return "'" . substr((string) json_encode($value, self::JS_JSON), 1, -1) . "'";
    }
}
