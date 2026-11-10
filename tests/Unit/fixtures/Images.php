<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

/** Image files a visitor's browser may send that GD never gets to decode whole - built from bytes, no GD needed. */
final class Images
{
    /** the PNG signature and a header of $w x $h, nothing else: getimagesize() reads it, GD would allocate the canvas */
    public static function pngHeader(int $w, int $h): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    /** a good header and broken image data: passes every check before GD decodes it */
    public static function damagedPng(): string
    {
        return self::pngHeader(30, 20) . pack('N', 10) . 'IDATgarbage!!!' . "\0\0\0\0";
    }

    /** JPEG to finfo, but no header getimagesize() reads */
    public static function damagedJpeg(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('garbage!', 8);
    }
}
