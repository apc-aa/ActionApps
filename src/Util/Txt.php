<?php

namespace AA\Util;

use AA;

class Txt {
    public static function decoratedName(string $name, bool $deleted = false): string {
        return $deleted ? (Langs::isUTF() ? "××× $name ×××" : "xxx $name xxx")  : $name;
    }

    /** shorten with possible ellipsis character */
    public static function shorten(string $str, int $len=20, string $ellipsis="…"): string
    {
        return (aa_strlen($str) > $len) ? aa_substr($str,0,$len-strlen($ellipsis)).$ellipsis : $str;
    }

    /** small key -> value table for logs, ... */
    public static function keyValTable(array $arr, bool $html=false): string
    {
        $css = <<<CSS
            .aa-log-entry { display: flex;gap: .5rem;
              div { position: relative;display: flex;flex-direction: column;align-items: flex-start;
                label { font-size: 0.7rem; color: #666; margin-bottom: -.5rem; line-height: .9rem;}
                span { font-size: .8rem; }
            }}
            CSS;
        AA::Stringexpander()->addRequire($css, 'AA_Req_CSScode');

        $ret = '';
        foreach ($arr as $name => $val) {
            $name = $html ? $name : safe($name);
            $val  = $html ? $val : safe(trim($val));
            if ( $val !== '') {
                $ret .= "<div class=aa-log-entry><label>$name</label><span>$val</span></div>";
            }
        }
        return '<div class=aa-log-entry>'.$ret.'</div>';
    }

    /** enhance value - item_id, date */
    public static function richValue(mixed $value): string
    {
        if (is_long_id($value)) {
            return Adminlink::itemLink($value);
        }
        if ((int)$value >= 883609200 && (int)$value <= 2208985200) {   // 1998 - 2040
            return $value.self::additionalInfo([date("Y-m-d H:i", (int)$value)]);
        }
        return safe($value);
    }

    /** Prints additional info box */
    public static function additionalInfo(array $texts): string
    {
        return '<div class="tabhlp">'.implode(' | ', $texts)."</div>";
    }

    /** Prefixes all  */
    public static function prefixArrayKeys(array $arr, string $prefix): array
    {
        $ret = [];
        foreach ($arr as $k => $v) {
            $ret[$prefix.$k] = $v;
        }
        return $ret;
    }

    /** Base64url encode (RFC 4648 §5, no padding). */
    public static function bin2url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Base64url decode. Tolerant: accepts missing padding and mixed +/-_ alphabets.
     *  Returns false on undecodable input (matches base64_decode semantics).
     */
    public static function url2bin(string $data): false|string
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '='));
    }
}