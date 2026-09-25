<?php

declare(strict_types=1);

namespace AA\Widget;

use AA\Util\Langs;
use AA\Util\LogOnce;
use AA\Util\Secret;
use AA_Field;
use ConvertCharset;

/**
 * {ajax} signs the widget type and properties it puts into the page; Get_Widget (central/responder.php)
 * uses them only with a valid signature, as they come from the template author. Without one (a page
 * cached before the signing, or a forged request) the field's own widget is used and the drop is
 * logged once a day per slice and field for State Check (AA\Optimize\CheckAjaxUnsigned).
 * The signature makes the {ajax} arguments trusted - do not put visitor-controlled data into them.
 * slice_field, code and row_code stay AA formats and are expanded again.
 */
final class AjaxSignature
{
    /** log.type of the dropped widget settings - selector slice:field, params unsigned|bad signature */
    public const EVENT = 'WIDGET_KEY';

    public static function sign(string $item_id, string $field_id, string $widget_type, string $widget_properties): string
    {
        return hash_hmac('sha256', $item_id . "\0" . $field_id . "\0" . $widget_type . "\0" . $widget_properties, Secret::key('ajax-widget'));
    }

    /** the onclick attribute of an {ajax} container - arguments escaped so the browser sends exactly what is signed
     *  @param string $charset of the page - signed is the UTF-8 the browser sends
     */
    public static function onclick(string $input_id, string $item_id, string $field_id, string $widget_type, string $widget_properties, string $credentials, string $charset = 'utf-8'): string
    {
        $utf8 = static fn(string $s): string => Langs::isUTF($charset) ? $s : ConvertCharset::singleton()->Convert($s, $charset, 'utf-8');
        $args = [$input_id, $item_id, $field_id, $widget_type, $widget_properties, $credentials, self::sign($item_id, $field_id, $utf8($widget_type), $utf8($widget_properties))];
        return 'onclick="displayInput(' . safe(implode(', ', array_map(self::jsString(...), $args))) . ')"';
    }

    /**
     * Widget type and properties of a Get_Widget request (UTF-8) - as sent when signed, none otherwise
     * @return array{0: ?string, 1: array<array-key, mixed>}
     */
    public static function widgetArgs(AA_Field $field, string $item_id, string $widget_type, string $widget_properties, string $sig): array
    {
        if (($widget_type === '') && ($widget_properties === '')) {
            return [null, []];
        }
        if (($sig !== '') && hash_equals(self::sign($item_id, $field->getId(), $widget_type, $widget_properties), $sig)) {
            return [($widget_type === '') ? null : $widget_type, json2asoc($widget_properties)];
        }
        LogOnce::daily(self::EVENT, ParamImplode([$field->getSliceId(), $field->getId()]), ($sig === '') ? 'unsigned' : 'bad signature');
        return [null, []];
    }

    /** single-quoted JS string literal */
    private static function jsString(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\n" => '\\n', "\r" => '\\r']) . "'";
    }
}
