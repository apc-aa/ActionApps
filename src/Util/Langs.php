<?php

namespace AA\Util;

use AA;
use AA_Value;

class Langs {
    protected static $LANGS = [
        'cz-utf8' => ['utf-8', 'Cestina (Unicode)'],
        'en-utf8' => ['utf-8', 'English (Unicode)'],
        'es-utf8' => ['utf-8', 'Espanol (Unicode)'],
        'sk-utf8' => ['utf-8', 'Slovencina (Unicode)'],
        'vn'      => ['utf-8', 'Vietnamese (Unicode)'],
        'bg'      => ['windows-1251', 'Bulgarian (windows-1251)'],
        'cz'      => ['windows-1250', 'Cestina (windows-1250)'],
        'de'      => ['iso-8859-1', 'Deutsch (iso-8859-1)'],
        'en'      => ['iso-8859-1', 'English (iso-8859-1)'],
        'es'      => ['iso-8859-1', 'Espanol (iso-8859-1)'],
        'fr'      => ['iso-8859-1', 'Fran�ais (iso-8859-1)'],
        'hr'      => ['windows-1250', 'Hrvatski (windows-1250)'],
        'ja'      => ['EUC-JP', 'Japanian (EUC-JP)'],
        'hu'      => ['iso-8859-2', 'Magyar (iso-8859-2)'],
        'ro'      => ['iso-8859-2', 'Romanian (iso-8859-2)'],
        'ru'      => ['windows-1251', 'Russian (windows-1251)'],    // cyrillic
        'sk'      => ['windows-1250', 'Slovencina (windows-1250)'],
    ];

    public static function getFiles($type = 'news'): array
    {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$k . '_' . $type . '_lang.php'] = $l[1];
        }
        return $arr;
    }

    public static function getCharsets(): array
    {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$l[0]] = $l[0];
        }
        return $arr;
    }

    public static function getNames(): array
    {
        return array_map(fn ($l) => $l[1], self::$LANGS);
    }

    /** returns lang code ('cz', 'en', 'en-utf8', 'de',...) from given $lang_file or from default
     */
    public static function getLang($lang_file): string {
        $lang_code = substr($lang_file, 0, strpos($lang_file . '_', '_'));
        return isset(self::$LANGS[$lang_code]) ? $lang_code : substr(DEFAULT_LANG_INCLUDE, 0, strpos(DEFAULT_LANG_INCLUDE, '_') ?: 'en-utf');
    }

    /** returns lang code ('cz', 'en', 'en-utf8', 'de',...) from given $charset or from default - used in mail templates
     */
    public static function fixLang($charset): string {
        if (isset(self::$LANGS[$charset])) {
            return $charset;
        }
        $lang = substr(self::currentLang(), 0, 2);
        return (stripos($charset, 'utf') !== false) ? $lang . '-utf8' : $lang;
    }

    /** get current lang
     */
    public static function currentLang(): string {
        global $mgettext_lang;
        return $mgettext_lang ?? substr(DEFAULT_LANG_INCLUDE, 0, strpos(DEFAULT_LANG_INCLUDE, '_')) ?: 'en-utf';
    }

    /** returns charset - like 'windows-1250' from given lang code or current lang
     *  usage: AA\Util\Langs::getCharset();
     */
    public static function getCharset($lang_code = null): string
    {
        if (!$lang_code || !is_array($ret = self::$LANGS[$lang_code])) {
            $ret = self::$LANGS[(self::currentLang())];
        }
        return $ret[0] ?? 'utf-8';
    }

    /** translate displayed lang name to standardized language code - cs -> cz
     */
    public static function getLangCode2Name($code) {
        $LANGS = ['cs' => 'cz'];
        return $LANGS[$code] ?: $code;
    }

    /** converts two letter lang code into number used for translation fields in $content4id array
     *  cz -> 78000000, en -> 118000000, ...
     *  you can use any two (smallcaps) letter for language
     */
    public static function getLangName2Num($lang) {
        $lang = strtolower($lang);
        $ret  = strlen($lang) ? AA_Value::MAX_INDEX * ((ord($lang[0]) - 97) * 26 + (ord($lang[1]) - 97 + 1)) : 0;
        return max(0, $ret);
    }

    /** test for two letters from a-z range */
    public static function isValidLangName(string $lang): bool {
        return strlen($lang) === 2 && $lang[0] >= 'a' AND $lang[0] <= 'z' && $lang[1] >= 'a' && $lang[1] <= 'z';   // only en, de - not cz-utf8, EN, ...
    }

    public static function getSql4Langs(array $langs, string $table): string {
        $sql = '';
        foreach ($langs as $lang) {
            // two letters from a-z range
            if ( !self::isValidLangName($lang) ) {
                warn("Wrong language name '$lang'.");
                continue;
            }
            $n = self::getLangName2Num($lang);
            if ($n) {
                $sql .= ($sql ? ' OR ' : '') . "(`$table`.`number` BETWEEN $n AND " . ($n + AA_Value::MAX_INDEX - 1) . ')';
            }
        }
        return $sql ? " AND ($sql) " : '';
    }

    /** reverse function to getLangNumber
     *  78000000 -> cz, 78000001 -> cz, 118000000 -> en...
     */
    public static function getLangNum2Name($langnumber): string {
        if ($langnumber < AA_Value::MAX_INDEX) {
            return '';
        }
        $num = $langnumber / AA_Value::MAX_INDEX - 1;
        return chr((int) ($num / 26) + 97) . chr(($num % 26) + 97);
    }

    /** combine getLangNum2Name and getLangName2Code
     *  78000000 -> cs, 78000001 -> cs, 118000000 -> en...
     */
    public static function getLangNum2Code($langnumber): string {
        return self::getLangName2Code(self::getLangNum2Name($langnumber));
    }

    /** translate displayed lang name to standardized language code - cz -> cs
     */
    public static function getLangName2Code($lang): string
    {
        $lang = substr($lang, 0, 2);
        $LANGS = ['cz' => 'cs'];
        return $LANGS[$lang] ?: $lang;
    }

    /** is current (or provided) $charset Unicode?
     */
    public static function isUTF(string $charset = ''): bool {
        return (stripos(($charset ?: self::getCharset()), 'utf-8') !== false);
    }
}