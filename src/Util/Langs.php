<?php

namespace AA\Util;

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

    public static function getFiles($type = 'news') {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$k . '_' . $type . '_lang.php'] = $l[1];
        }
        return $arr;
    }

    public static function getCharsets() {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$l[0]] = $l[0];
        }
        return $arr;
    }

    public static function getNames() {
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
    public static function getCharset($lang_code = null) {
        if (!$lang_code or !is_array($ret = self::$LANGS[$lang_code])) {
            $ret = self::$LANGS[(self::currentLang())];
        }
        return $ret[0];
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

    /** reverse function to getLangNumber
     *  78000000 -> cz, 78000001 -> cz, 118000000 -> en...
     */
    public static function getLangNum2Name($langnumber) {
        if ($langnumber < AA_Value::MAX_INDEX) {
            return '';
        }
        $num = $langnumber / AA_Value::MAX_INDEX - 1;
        return chr(intval($num / 26) + 97) . chr(($num % 26) + 97);
    }

    /** combine getLangNum2Name and getLangName2Code
     *  78000000 -> cs, 78000001 -> cs, 118000000 -> en...
     */
    public static function getLangNum2Code($langnumber): string {
        return self::getLangName2Code(self::getLangNum2Name($langnumber));
    }

    /** translate displayed lang name to standardized language code - cz -> cs
     */
    public static function getLangName2Code($lang) {
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