<?php

declare(strict_types=1);

namespace AA\Cache;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

/** The static cache of resized images ({img}) under IMG_UPLOAD_PATH/mC/: one setup for the
 *  page render (AA_Stringexpand_Img, through build() - its unit test hands in the values),
 *  the dispatcher answering a miss (modules/site/site.php), the daily purge and State Check.
 */
final class ImgCache
{
    /** directory under IMG_UPLOAD_PATH, and path segment under IMG_UPLOAD_URL, holding the cache */
    public const SEGMENT = 'mC';

    /** Whether config.php defines everything the cache needs - not so outside an initialised
     *  AA context, or on a config.php3 older than these constants. Enough to serve and purge
     *  what is cached; whether {img} emits cache urls at all is enabled(). */
    public static function configured(): bool
    {
        return defined('IMG_UPLOAD_URL') && defined('IMG_UPLOAD_PATH') && (IMG_UPLOAD_PATH !== '')
            && defined('IMG_UPLOAD_DIR_MODE') && defined('AA_INSTAL_URL');
    }

    /** Whether {img} serves from the cache on this install: configured, not switched off by
     *  AA_MISSCACHE_IMG_DISABLE, and IMG_UPLOAD not at the domain root (there no url prefix
     *  tells an upload from any other file). The same conditions as AA_Stringexpand_Img::useCache(). */
    public static function enabled(): bool
    {
        return self::configured()
            && !(defined('AA_MISSCACHE_IMG_DISABLE') && constant('AA_MISSCACHE_IMG_DISABLE'))
            && (self::srcBase(constant('IMG_UPLOAD_URL')) !== '');
    }

    /** document-root relative path of the upload directory with a trailing slash ("img_upload/"),
     *  '' when it is the domain root */
    public static function srcBase(string $upload_url): string
    {
        $path = trim((string) parse_url($upload_url, PHP_URL_PATH), '/');
        return ($path === '') ? '' : "$path/";
    }

    /** absolute path of the cache root, without the trailing slash */
    public static function root(): string
    {
        return rtrim(constant('IMG_UPLOAD_PATH'), '/') . '/' . self::SEGMENT;
    }

    /** @param string $base_url  IMG_UPLOAD_URL - or its document-root relative path, for urls a page embeds */
    public static function build(string $base_url, string $upload_path, int $dir_mode, string $instal_url): MissCache
    {
        return new MissCache($base_url, $upload_path, self::SEGMENT, $dir_mode, [new PhpThumbPlugin($instal_url . 'img.php')]);
    }

    /** constant(): the values come from config.php, which static analysis of src/ never sees */
    public static function get(): MissCache
    {
        return self::build(constant('IMG_UPLOAD_URL'), constant('IMG_UPLOAD_PATH'), (int) constant('IMG_UPLOAD_DIR_MODE'), constant('AA_INSTAL_URL'));
    }
}
