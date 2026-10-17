<?php

/**
 * Image manipulation functions - all functions require GD Library
 *
 * Should be included to other scripts
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   ImageManager
 * @version   $Id$
 * @author    Stanislav K�hn <kuhn@changenet.sk>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 * @todo  replace with some library - maybe https://github.com/claviska/SimpleImage or thephpleague/glide
 */

namespace AA\Util;

class Imgfunc
{
    /** An array cross-referencing different ways to refer to images
     * Other img types could be added from http://www.php.cz/manual/en/function.exif-imagetype.php, but it could not be supported by GD
     *   m = mime type,
     *   e = extension for GD functions and files - ImageCreateFromXXX
     *   u = human-readable
     *   b = bitmask
     *   x = exif_imagetype or imageinfo[2]
     *   t = true if we should use truecolor
     *   q = quality for encoding
     */
    protected const IMAGE_TABLE = [
        IMAGETYPE_GIF  => ['m' => 'image/gif',  'e' => 'gif',  'u' => 'GIF',  'b' => IMG_GIF,  'q' => '',  't' => false],
        IMAGETYPE_JPEG => ['m' => 'image/jpeg', 'e' => 'jpeg', 'u' => 'JPEG', 'b' => IMG_JPG,  'q' => 100, 't' => true],
        IMAGETYPE_PNG  => ['m' => 'image/png',  'e' => 'png',  'u' => 'PNG',  'b' => IMG_PNG,  'q' => '',  't' => true],
        IMAGETYPE_BMP  => ['m' => 'image/wbmp', 'e' => 'bmp',  'u' => 'WBMP', 'b' => IMG_WBMP, 'q' => '',  't' => false],
        IMAGETYPE_WEBP => ['m' => 'image/webp', 'e' => 'webp', 'u' => 'WEBP', 'b' => IMG_WEBP, 'q' => '',  't' => true],
        IMAGETYPE_AVIF => ['m' => 'image/avif', 'e' => 'avif', 'u' => 'AVIF', 'b' => IMG_AVIF, 'q' => '',  't' => true],
    ];

    /** Check if GD lib is installed  */
    public static function gdInstalled(): bool
    {
        if (!extension_loaded('gd')) {
            //      if (!dl('gd.so'))
            huhe("GD is not installed, cannot perform image manipulation");
            return false;
        }
        return true;
    }

    /** Copies image if source and destination is not the same
     *
     * @param $simage
     * @param $dimage
     *
     * @return false on success or text string with error message
     */
    public static function copyImage2Destination($simage, $dimage): bool
    {
        if ($simage == $dimage) {
            return false; // no need to copy
        }
        return copy($simage, $dimage) ? false : _m("Cannot copy %1 to %2", [$simage, $dimage]);
    }

    /** ResampleImage function
     *  Resamples image
     *
     * @param $simage
     * @param $dimage
     * @param $new_w
     * @param $new_h
     * @param $exact
     *
     * @return false on success or text string with error message
     */
    public static function resampleImage($simage, $dimage, $new_w, $new_h, $exact): bool
    {

        // if dimensions of new picture are not set, then do not resize
        if (!$new_w && !$new_h) {
            return self::copyImage2Destination($simage, $dimage);
        }

        //determine type, width, height of image.
        [$simg_w, $simg_h, $simg_type] = getimagesize($simage);

        //set ratio of width and height
        if ($new_w != 0) {
            $x = $simg_w / $new_w;
        }
        if ($new_h != 0) {
            $y = $simg_h / $new_h;
        }

        //if image is smaller than limits, do not resample
        if ($x < 1 && $y < 1) {
            return self::copyImage2Destination($simage, $dimage);
        }

        if (!self::isSupportedType($simg_type)) {
            return _m("Type not supported for resize");
        }

        //calculate dimensions to which will image be resampled
        //calculate offset for croping image if necessary when $exact is set
        if ($exact == 1) {
            // calculate dimensions
            if ($x < $y) {
                //use max width and calculate height
                $new_height = $simg_h / $simg_w * $new_w;
                $new_width  = $new_w;
                $ratio      = $x;
            } else {
                //use max height and calculate width
                $new_width  = $simg_w / $simg_h * $new_h;
                $new_height = $new_h;
                $ratio      = $y;
            }
            // calculate offsets
            if ($new_width > $new_w) {
                $offset_w = $ratio * ($new_width - $new_w) / 2;
                $offset_h = 0;
            } elseif ($new_height > $new_h) {
                $offset_w = 0;
                $offset_h = $ratio * ($new_height - $new_h) / 2;
            } else {
                $offset_w = $ratio * ($new_width - $new_w) / 2;
                $offset_h = $ratio * ($new_height - $new_h) / 2;
            }
        } else {
            //do not resize to exact dimensions, maintain aspect ratio
            if ($x > $y) {
                //use max width and calculate height
                $new_height = $simg_h / $simg_w * $new_w;
                $new_width  = $new_w;
                $new_h      = $new_height;
            } else {
                //use max height and calculate width
                $new_width  = $simg_w / $simg_h * $new_h;
                $new_height = $new_h;
                $new_w      = $new_width;
            }
            $offset_w = 0;
            $offset_h = 0;
        }

        $simg_info = self::IMAGE_TABLE[$simg_type];

        // in GD2 ImageCreate goes monochrome
        if ($simg_info['t']) {       // test for is_callable("ImageCreateTrueColor") doesn't work -  GD < 2 have this function, but not implemented
            $dst_img = @imagecreatetruecolor($new_w, $new_h);
        }
        if (!$dst_img) {
            $dst_img = imagecreate($new_w, $new_h);
        }
        $imagetype = $simg_info['e'];
        $f       = "ImageCreateFrom" . $imagetype;
        $src_img = $f($simage);
        if (!$src_img) {
            return _m("ResampleImage unable to %1", [$f]);
        }
        // imagecopyresampled - better quality resizing - works with GD 2.0.1
        imagecopyresampled($dst_img, $src_img, 0, 0, $offset_w, $offset_h, $new_width, $new_height, $simg_w, $simg_h);
        //    imagecopyresized($dst_img,$src_img,0,0,$offset_w,$offset_h,$new_width,$new_height,$imginfo[0],$imginfo[1]);
        $f = "Image" . $imagetype;
        if ($simg_info['q']) {
            $f($dst_img, $dimage, $simg_info['q']);  // for jpeg 100
        } else {
            $f($dst_img, $dimage);
        }

        return false;
    }

    /** GD needs ~4 bytes a pixel: 40 Mpx is 160 MB, bigger canvases are refused before decoding */
    public const REENCODE_MAX_PIXELS = 40_000_000;

    /** GD reads and writes AVIF only when PHP was built with libavif */
    public static function avifSupported(): bool
    {
        return function_exists('imageavif') && !empty(gd_info()['AVIF Support']);
    }

    /**
     * Writes $src again as a new image, at most $max_px on the longer side.
     * Decoding and encoding drops EXIF (the GPS position of a phone photo) and anything
     * hidden after the image data - what an anonymous upload must not keep.
     *
     * @param bool $portable write what every viewer shows - JPEG, or PNG when the image has
     *                       transparency - and take GIF too; otherwise the type of $src
     * @return ?string null on success, otherwise the error
     */
    public static function reencode(string $src, string $dest, int $max_px, int $max_pixels = self::REENCODE_MAX_PIXELS, bool $portable = false): ?string
    {
        $info  = @getimagesize($src);
        $type  = is_array($info) ? (int) $info[2] : 0;
        $types = self::avifSupported() ? [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_AVIF] : [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($portable) {
            $types[] = IMAGETYPE_GIF;
        }
        if (!is_array($info) || !in_array($type, $types, true)) {
            return _m('This type of image cannot be uploaded here');
        }
        if ($info[0] * $info[1] > $max_pixels) {
            return _m('The image has too many pixels');
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if (!$img) {
            return _m('The image cannot be read');
        }
        $w     = imagesx($img);
        $h     = imagesy($img);
        $scale = min(1, $max_px / max($w, $h));
        $out   = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        unset($img);
        // the rotation of a phone photo lives in EXIF, which is about to be dropped; turned after
        // scaling, so a 16 Mpx photo is not held twice
        if (($type === IMAGETYPE_JPEG) && function_exists('exif_read_data')) {
            $exif    = @exif_read_data($src);
            $rotated = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
                3       => imagerotate($out, 180, 0),
                6       => imagerotate($out, -90, 0),
                8       => imagerotate($out, 90, 0),
                default => $out,
            };
            if ($rotated) {
                $out = $rotated;
            }
        }
        $as = match (true) {
            !$portable                  => $type,
            $type === IMAGETYPE_JPEG    => IMAGETYPE_JPEG,
            self::hasTransparency($out) => IMAGETYPE_PNG,
            default                     => IMAGETYPE_JPEG,
        };
        if (($as === IMAGETYPE_JPEG) && ($type !== IMAGETYPE_JPEG)) {
            $out = self::onWhite($out);
        }
        $ok = match ($as) {
            IMAGETYPE_JPEG => imagejpeg($out, $dest, 85),
            IMAGETYPE_PNG  => imagepng($out, $dest),
            IMAGETYPE_AVIF => imageavif($out, $dest),
            default        => imagewebp($out, $dest, 85),
        };
        return $ok ? null : _m('Cannot write %1', [$dest]);
    }

    /**
     * Any transparent part, judged from a 32 px copy: the averaging hides a few transparent pixels,
     * which then turn white - as they look on a white page anyway.
     */
    private static function hasTransparency(\GdImage $img): bool
    {
        $small = imagecreatetruecolor(min(32, imagesx($img)), min(32, imagesy($img)));
        imagealphablending($small, false);
        imagecopyresampled($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), imagesx($img), imagesy($img));
        for ($x = 0; $x < imagesx($small); $x++) {
            for ($y = 0; $y < imagesy($small); $y++) {
                if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** JPEG has no transparency: what was see-through becomes white, not black */
    private static function onWhite(\GdImage $img): \GdImage
    {
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        return $flat;
    }

    public static function printSupportedTypes(): void
    {
        $it = imagetypes();
        foreach (self::IMAGE_TABLE as $v) {
            print($v["u"] . (($it & $v["b"]) ? " " : " Not ") . "Supported \n");
        }
    }

    /** @param $type //type 1-gif, 2-jpeg, 3-png;
     *
     * @return bool
     */
    public static function isSupportedType($type): bool
    {
        if (!self::gdInstalled()) {
            return false;
        }
        if (imagetypes() & self::IMAGE_TABLE[$type]["b"]) {
            return true;
        }
        // Note won't see this warning when run in itemedit cos redirects
        huhe("Warning: GD cant manipulate " . self::imgU($type) . " images");
        return false;
    }

    /**
     * @param $type - type 1-gif, 2-jpeg, 3-png;
     *
     * @return string
     */
    public static function imgU($type): string
    {
        return (self::IMAGE_TABLE[$type]["u"] ?: ("Type " . $type));
    }
}
