<?php

declare(strict_types=1);

namespace AA\Widget;

use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use AA\Util\Langs;

/**
 * The HTML of <aa-richtext> (javascript/widgets/aa-richtext.js) around a textarea - for the
 * widgets (EdtWidget, TxtWidget) and the legacy AA_Inputfield::textarea() alike.
 */
final class RichtextMarkup
{
    /**
     * @param string                $textarea the named <textarea> HTML
     * @param string                $mode     rich|code|plain - the view it opens in
     * @param array<string, string> $attrs    further attributes, escaped here
     */
    public static function wrap(string $textarea, string $mode, array $attrs = []): string
    {
        return '<aa-richtext' . htmlattr(['mode' => $mode] + $attrs) . '>' . $textarea . '</aa-richtext>';
    }

    /**
     * The upload-* attributes for a viewer who may upload in $mode; none for UploadPolicy::NONE.
     * @return array<string, string>
     */
    public static function uploadAttrs(string $slice_id, string $mode, string $uid, int $now, string $api_url): array
    {
        if (($mode !== UploadPolicy::USER) && ($mode !== UploadPolicy::ANON)) {
            return [];
        }
        $attrs = [
            'upload'       => $mode,
            'upload-url'   => $api_url,
            'upload-token' => UploadToken::issue($slice_id, $mode, $uid, $now),
            'slice'        => $slice_id,
        ];
        $attrs['upload-images'] = implode(',', UploadPolicy::imageTypes($mode));
        if ($mode === UploadPolicy::USER) {
            $attrs['upload-types'] = (string) json_encode(UploadPolicy::documentTypes(), JSON_UNESCAPED_SLASHES);
        }
        return $attrs;
    }

    /**
     * What depends on the request: who views the slice, the UI language, the texts.
     * @return array<string, string>
     */
    public static function contextAttrs(string $slice_id): array
    {
        $viewer = is_long_id($slice_id) ? UploadPolicy::viewer($slice_id) : null;
        $mode   = $viewer ? UploadPolicy::mode($viewer['editor'], $viewer['anonymous']) : UploadPolicy::NONE;
        $api    = (defined('AA_INSTAL_PATH') ? AA_INSTAL_PATH : '/') . 'api/files.php';
        return self::uploadAttrs($slice_id, $mode, AuthGetUid(), time(), $api) + [
            'ui-lang' => Langs::getLangName2Code(Langs::currentLang()),
            'l10n'    => (string) json_encode(self::labels(), JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'editor'    => _m('Editor'),
            'source'    => _m('Source'),
            'search'    => _m('Search'),
            'upload'    => _m('Upload'),
            'close'     => _m('Close'),
            'loading'   => _m('Loading...'),
            'empty'     => _m('No files'),
            'uploading' => _m('Uploading...'),
            'failed'    => _m('Upload failed'),
        ];
    }
}
