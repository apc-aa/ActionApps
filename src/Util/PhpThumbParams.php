<?php

declare(strict_types=1);

namespace AA\Util;

/** What a phpThumb parameter string ("w=150&h=150&zc=1", '&' or '&amp;' separated) says about
 *  the image it makes. Pure string logic, shared by {img} (AA_Stringexpand_Img) and the State
 *  Check which lists the {img} calls that have to measure their image.
 *
 *  for phpThumb params see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
 */
final class PhpThumbParams
{
    /** <info> of {img} which prints just the url - it never needs the size */
    public const URL_INFOS = ['' => true, 'url' => true, 'cache' => true, 'rawurl' => true, 'im' => true];

    /** Highest quality for AVIF, also img.php's default for it - looks about like the JPEG
     *  at img.php's default q=90 (ssimulacra2). GD maps q linearly to the AV1 quantizer and
     *  from 90 up keeps full colour resolution: q=90 gave 3.4x the bytes of q=83, on photos
     *  as big as the JPEG. */
    public const AVIF_QUALITY = 83;

    /** Parameters which make the output size depend on the source image, so it can't
     *  be predicted from the parameters alone:
     *    wp,hp,wl,hl,ws,hs - replace w/h based on the orientation of the source
     *    sx,sy,sw,sh       - source crop takes precedence over iar/far (ImageMagick backend)
     *    fltr              - 'crop' shrinks the output, 'bord'/'drop'/'fram' grow it (GD backend)
     *    ra,ar             - rotation (90° swaps w and h, other angles grow the canvas)
     *    xto               - outputs the EXIF thumbnail instead of a generated one
     *    new               - generates an image instead of resizing <src>
     */
    private const SIZE_UNPREDICTABLE = ['wp' => true, 'hp' => true, 'wl' => true, 'hl' => true, 'ws' => true, 'hs' => true, 'sx' => true, 'sy' => true, 'sw' => true, 'sh' => true, 'fltr' => true, 'ra' => true, 'ar' => true, 'xto' => true, 'new' => true, 'phpThumbDebug' => true];

    /** parse() results, keyed by the raw parameter string. {img:} is typically expanded once
     *  per listed item with the very same parameters, and every expansion parses them
     *  repeatedly (pinnedSize() plus addAoe4ZoomCrop() in each url) */
    private static array $parsed = [];

    /** @return array<string, mixed> the parameters as parse_str() reads them */
    public static function parse(string $params): array
    {
        if (isset(self::$parsed[$params])) {
            return self::$parsed[$params];
        }
        $parsed = [];
        parse_str(str_replace('&amp;', '&', $params), $parsed);
        if (count(self::$parsed) > 64) {   // a page uses a handful of distinct parameter
            self::$parsed = [];            // strings - guard against templates interpolating values into them
        }
        return self::$parsed[$params] = $parsed;
    }

    /** A parameter list broken over lines: the expansion trims only its ends, and a browser drops
     *  line breaks from an img.php url - but a cache url would carry them encoded, and the
     *  dispatcher refuses control bytes, so the thumbnail would never show. */
    public static function clean(string $params): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $params);
    }

    /** phpThumb's zoom crop (zc) fills the whole w × h box only when enlargement is
     *  allowed - without aoe=1 its ImageMagick backend silently returns the (smaller)
     *  source size instead. Adding aoe=1 makes the result deterministic, which lets
     *  pinnedSize() skip the expensive measuring. Idempotent.
     */
    public static function addAoe4ZoomCrop(string $params): string
    {
        $parsed = self::parse($params);
        if (empty($parsed['zc']) || empty($parsed['w']) || empty($parsed['h']) || isset($parsed['aoe'])) {
            return $params;
        }
        return $params . (str_contains($params, '&amp;') ? '&amp;' : '&') . 'aoe=1';
    }

    /** For img.php: aoe=0 with a box phpThumb fills (zc crops into it, iar stretches) shrinks the
     *  box to fit the source instead of enlarging it, in the box's aspect ratio. phpThumb alone
     *  would enlarge anyway (GD) or return the source in its own aspect ratio (ImageMagick, see
     *  addAoe4ZoomCrop()), which the width and height of the <img> would then stretch. The shrunk
     *  box goes on with aoe=1, so it is filled exactly. A source of unknown size is enlarged as before.
     *
     *  @param array<string, mixed> $get           the request parameters, as phpThumb reads them
     *  @param callable(): ?array{0: int, 1: int} $source_size  width and height of the source, null when unknown - called only when needed
     *  @return array<string, mixed>
     */
    public static function noEnlarge(array $get, callable $source_size): array
    {
        $w = (int) ($get['w'] ?? 0);
        $h = (int) ($get['h'] ?? 0);
        if ((($get['aoe'] ?? null) !== '0') || !$w || !$h || (empty($get['zc']) && empty($get['iar'])) || !empty($get['far']) || self::hasUnpredictable($get)) {
            return $get;
        }
        if ($size = $source_size()) {
            $scale = min(1, $size[0] / $w, $size[1] / $h);
            $get['w'] = (string) min($size[0], max(1, (int) round($w * $scale)));
            $get['h'] = (string) min($size[1], max(1, (int) round($h * $scale)));
        }
        $get['aoe'] = '1';
        return $get;
    }

    /** Output dimensions for the parameter combinations where phpThumb always renders
     *  exactly w × h. For those {img} skips measuring the generated image -
     *  that is a self-HTTP request which also forces the thumbnail to be rendered
     *  (70-110 ms measured), just to learn a size we already know.
     *
     *  The rule is the intersection of both phpThumb backends - GD
     *  (CalculateThumbnailDimensions) and ImageMagick (ImageMagickThumbnailToGD) -
     *  so it holds no matter which one the install uses.
     *
     *  @return array{0: ?int, 1: ?int}  [width, height] - null for a dimension which has to be measured
     */
    public static function pinnedSize(string $params): array
    {
        $parsed = self::parse($params);
        $w      = (int) ($parsed['w'] ?? 0);
        $h      = (int) ($parsed['h'] ?? 0);
        // ImageMagick tests aoe == 1 literally, so aoe=2 does not enable enlargement
        $enlarge = ($parsed['aoe'] ?? null) == 1;

        if ($w && $h) {
            // iar (ignore aspect ratio) and far (fixed aspect ratio) always fill the whole
            // box. zc (zoom crop) does so only with aoe=1, which {img} adds whenever aoe
            // is missing - so only an explicit, different aoe breaks it.
            $fills_box = !empty($parsed['iar']) || !empty($parsed['far'])
                      || (!empty($parsed['zc']) && (!isset($parsed['aoe']) || $enlarge));
            // when both are given without filling the box the image is fitted inside it,
            // so which of the two is met depends on the aspect ratio of the source
            return ($fills_box && !self::hasUnpredictable($parsed)) ? [$w, $h] : [null, null];
        }

        // Only one dimension asked for: with enlargement allowed it is always met exactly,
        // the other one then follows the aspect ratio of the source. iar/far need both
        // dimensions and are no-ops here, but zc turns the missing one into a square box
        // which the source can cap ('h=100&zc=1&aoe=1' gives 50x50 for a 106x50 source).
        if ($enlarge && empty($parsed['zc']) && !self::hasUnpredictable($parsed)) {
            if ($w) {
                return [$w, null];
            }
            if ($h) {
                return [null, $h];
            }
        }
        return [null, null];
    }

    /** The size {img} with this <info> prints, when the parameters alone tell it - for 'width'
     *  and 'height' only that one dimension has to be known. Null means {img} has to measure
     *  the generated image. Meant for an <info> outside URL_INFOS, which needs no size at all.
     *
     *  @return array{0: ?int, 1: ?int}|null
     */
    public static function knownSize(string $info, string $params): ?array
    {
        // 'imgtype' and 'mime' need the output format as well, which no parameter combination tells
        if (($info === 'imgtype') || ($info === 'mime')) {
            return null;
        }
        $size = self::pinnedSize($params);
        $known = match ($info) {
            'width'  => isset($size[0]),
            'height' => isset($size[1]),
            default  => isset($size[0], $size[1]),
        };
        return $known ? $size : null;
    }

    /** Sets (or replaces) the phpThumb output format in a parameter string, keeping
     *  whichever separator it uses - '&' or the HTML escaped '&amp;'. AVIF also gets
     *  its quality capped at AVIF_QUALITY; a lower q of the template is kept.
     */
    public static function setFormat(string $params, string $format): string
    {
        $q      = (int) (self::parse($params)['q'] ?? 0);
        $params = self::set($params, 'f', $format);
        if (($format === 'avif') && (($q < 1) || ($q > self::AVIF_QUALITY))) {
            $params = self::set($params, 'q', (string) self::AVIF_QUALITY);
        }
        return $params;
    }

    /** Replaces every value of the parameter, or appends it. Appending also covers
     *  spellings the regex misses (" q=95", "%71=95") - the last value wins in phpThumb. */
    private static function set(string $params, string $name, string $value): string
    {
        $params = (string) preg_replace("~(^|&(?:amp;)?)$name=[^&]*~", "\\1$name=$value", $params, -1, $count);
        return $count ? $params : $params . (str_contains($params, '&amp;') ? '&amp;' : '&') . "$name=$value";
    }

    /** True when any parameter makes the output size depend on the source image */
    private static function hasUnpredictable(array $parsed): bool
    {
        return (bool) array_intersect_key($parsed, self::SIZE_UNPREDICTABLE);
    }
}
