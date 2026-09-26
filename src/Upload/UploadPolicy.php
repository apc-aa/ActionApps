<?php

declare(strict_types=1);

namespace AA\Upload;

use AA\Util\Imgfunc;
use AA_Auth;
use AA_Slice;

/**
 * Who may upload what through the rich-text editor (api/files.php). Only files the
 * browser shows as files, never what it or the server would run - no html, svg, xml,
 * js, php. The content (finfo) must match the extension, and a name keeps one dot:
 * Apache's mod_mime runs "x.php.jpg" as PHP wherever .php has a handler.
 */
final class UploadPolicy
{
    public const USER = 'user';
    public const ANON = 'anon';
    public const NONE = 'none';

    public const ANON_MAX_BYTES = 5 * 1024 * 1024;
    public const ANON_MAX_PX    = 2000;

    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** extension => the content types finfo may report; the first one is what the browser gets told */
    public const USER_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'pdf'  => ['application/pdf'],
        // old Office files are OLE containers, finfo names them several ways
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2'],
        // new Office and OpenDocument files are zips; some finfo databases say just that
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'rtf'  => ['text/rtf', 'application/rtf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'zip'  => ['application/zip'],
        'mp3'  => ['audio/mpeg'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
    ];

    public const ANON_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /** what the editor offers when AA renders it */
    public static function mode(bool $editor, bool $anonymous): string
    {
        return $editor ? self::USER : ($anonymous ? self::ANON : self::NONE);
    }

    /** what an upload may do: what its token offered, if the request may still do that */
    public static function effective(?string $granted, bool $editor, bool $anonymous): string
    {
        return match ($granted) {
            self::USER => $editor ? self::USER : self::NONE,
            self::ANON => $anonymous ? self::ANON : self::NONE,
            default    => self::NONE,
        };
    }

    /** permit_anonymous_post 1-3 (3 = with an antispam token); permit_anonymous_edit 1-5 (6 = cron only) */
    public static function anonymousAllowed(int $anonymous_post, int $anonymous_edit): bool
    {
        return ($anonymous_post > 0) || (($anonymous_edit >= 1) && ($anonymous_edit <= 5));
    }

    /** @return ?string why not - 'type', 'mime' or 'size' - null when allowed */
    public static function refusal(string $mode, string $filename, string $mime, int $size): ?string
    {
        $types = self::types($mode);
        $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset($types[$ext])) {
            return 'type';
        }
        if (!in_array($mime, $types[$ext], true)) {
            return 'mime';
        }
        if (($mode === self::ANON) && ($size > self::ANON_MAX_BYTES)) {
            return 'size';
        }
        return null;
    }

    /**
     * What an anonymous visitor may upload - every such image is written again, so AVIF
     * only where GD can.
     * @return array<string, list<string>>
     */
    public static function anonTypes(): array
    {
        return Imgfunc::avifSupported() ? self::ANON_TYPES + ['avif' => ['image/avif']] : self::ANON_TYPES;
    }

    /**
     * Image extensions $mode may upload - TinyMCE refuses other pasted and dropped images itself.
     * @return list<string>
     */
    public static function imageTypes(string $mode): array
    {
        return array_values(array_intersect(self::IMAGES, array_keys(self::types($mode))));
    }

    /** @return array<string, list<string>> */
    private static function types(string $mode): array
    {
        return match ($mode) {
            self::USER => self::USER_TYPES,
            self::ANON => self::anonTypes(),
            default    => [],
        };
    }

    /** one dot at most, lower-case extension, no leading dot */
    public static function safeName(string $escaped_name): string
    {
        $name = ltrim($escaped_name, '.');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = str_replace('.', '_', ($ext === '') ? $name : substr($name, 0, -strlen($ext) - 1));
        return (($base === '') ? 'file' : $base) . (($ext === '') ? '' : ".$ext");
    }

    /** a re-encoded anonymous image is named after what it now is */
    public static function anonExtension(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => 'jpg',
        };
    }

    /**
     * Files the link dialog of TinyMCE offers to upload - images go through the image dialog.
     * @return list<array{mimeType: string, extensions: list<string>}>
     */
    public static function documentTypes(): array
    {
        $types = [];
        foreach (self::USER_TYPES as $ext => $mimes) {
            if (!in_array($ext, self::IMAGES, true)) {
                $types[] = ['mimeType' => $mimes[0], 'extensions' => [$ext]];
            }
        }
        return $types;
    }

    /**
     * The viewer of $slice_id in this request.
     * @return ?array{editor: bool, anonymous: bool}  null when there is no such slice
     */
    public static function viewer(string $slice_id): ?array
    {
        $slice = AA_Slice::getModule($slice_id);
        if (!$slice instanceof AA_Slice) {
            return null;
        }
        $auth = $GLOBALS['auth'] ?? null;
        return [
            'editor'    => ($auth instanceof AA_Auth) && $auth->is_user() && (IfSlPerm(PS_EDIT_SELF_ITEMS, $slice_id) || IfSlPerm(PS_EDIT_ALL_ITEMS, $slice_id)),
            'anonymous' => self::anonymousAllowed((int) $slice->getProperty('permit_anonymous_post'), (int) $slice->getProperty('permit_anonymous_edit')),
        ];
    }
}
