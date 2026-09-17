<?php

declare(strict_types=1);

namespace AA\Cache;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

/** The static cache of resized images ({img}) under IMG_UPLOAD_PATH/mC/, as every
 *  request-time user needs it: the dispatcher answering a miss (modules/site/site.php)
 *  and the daily purge. The page render builds its own in AA_Stringexpand_Img, which
 *  also emits document-root relative urls.
 */
final class ImgCache
{
    /** directory under IMG_UPLOAD_PATH, and path segment under IMG_UPLOAD_URL, holding the cache */
    public const SEGMENT = 'mC';

    /** Whether config.php defines everything the cache needs - not so outside an initialised
     *  AA context, or on a config.php3 older than these constants. */
    public static function configured(): bool
    {
        return defined('IMG_UPLOAD_URL') && defined('IMG_UPLOAD_PATH') && (IMG_UPLOAD_PATH !== '')
            && defined('IMG_UPLOAD_DIR_MODE') && defined('AA_INSTAL_URL');
    }

    /** constant(): the values come from config.php, which static analysis of src/ never sees */
    public static function get(): MissCache
    {
        return new MissCache(
            constant('IMG_UPLOAD_URL'),
            constant('IMG_UPLOAD_PATH'),
            self::SEGMENT,
            (int) constant('IMG_UPLOAD_DIR_MODE'),
            [new PhpThumbPlugin(constant('AA_INSTAL_URL') . 'img.php')],
        );
    }
}
