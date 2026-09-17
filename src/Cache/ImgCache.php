<?php

declare(strict_types=1);

namespace AA\Cache;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

/** The static cache of resized images ({img}) under IMG_UPLOAD_PATH/mC/: one setup for the
 *  page render (AA_Stringexpand_Img), the dispatcher answering a miss (modules/site/site.php),
 *  the daily purge and State Check. It reads config.php once per request - {img} asks for
 *  every image on the page.
 */
final class ImgCache
{
    /** directory under IMG_UPLOAD_PATH, and path segment under IMG_UPLOAD_URL, holding the cache */
    public const SEGMENT = 'mC';

    /** config.php constants the cache cannot do without */
    private const REQUIRED = ['IMG_UPLOAD_URL', 'IMG_UPLOAD_PATH', 'IMG_UPLOAD_DIR_MODE', 'AA_INSTAL_URL'];

    /** @var array<string, mixed>|false|null  the config.php values by constant name, with what derive() adds - false when one is missing, null until read */
    private static array|false|null $config = null;

    /** @var array<int, MissCache>  get() by its $relative argument */
    private static array $instances = [];

    /** Whether config.php defines everything the cache needs - not so outside an initialised
     *  AA context, or on a config.php3 older than these constants. Enough to serve and purge
     *  what is cached; whether {img} emits cache urls at all is enabled(). */
    public static function configured(): bool
    {
        return self::config() !== false;
    }

    /** Whether {img} serves from the cache on this install: configured, not switched off by
     *  AA_MISSCACHE_IMG_DISABLE, and IMG_UPLOAD not at the domain root - there no url prefix
     *  tells an uploaded image from any other file on the server. */
    public static function enabled(): bool
    {
        return self::configured() && self::value('enabled');
    }

    /** IMG_UPLOAD_URL - for a configured() cache only */
    public static function uploadUrl(): string
    {
        return (string) self::value('IMG_UPLOAD_URL');
    }

    /** document-root relative path of the upload directory with a trailing slash ("img_upload/"),
     *  '' when it is the domain root */
    public static function srcBase(): string
    {
        return (string) self::value('src_base');
    }

    /** absolute path of the cache root, without the trailing slash */
    public static function root(): string
    {
        return rtrim((string) self::value('IMG_UPLOAD_PATH'), '/') . '/' . self::SEGMENT;
    }

    /** IMG_UPLOAD_DIR_MODE - for a configured() cache only */
    public static function dirMode(): int
    {
        return (int) self::value('IMG_UPLOAD_DIR_MODE');
    }

    /** The cache - for a configured() one only.
     *  @param bool $relative  urls without the host (the upload directory's path only), for a
     *                         page served by the host of IMG_UPLOAD_URL */
    public static function get(bool $relative = false): MissCache
    {
        return self::$instances[(int) $relative] ??= new MissCache(
            $relative ? '/' . self::srcBase() : self::uploadUrl(),
            (string) self::value('IMG_UPLOAD_PATH'),
            self::SEGMENT,
            self::dirMode(),
            [new PhpThumbPlugin((string) self::value('AA_INSTAL_URL') . 'img.php')],
        );
    }

    /** @internal Tests only: $config (constant name => value, as config.php defines them) instead of
     *  config.php - false as when a constant is missing, null to read config.php again. */
    public static function useConfig(array|false|null $config): void
    {
        self::$config    = \is_array($config) ? self::derive($config) : $config;
        self::$instances = [];
    }

    private static function value(string $name): mixed
    {
        $config = self::config();
        if ($config === false) {
            throw new \LogicException('The image cache is not configured - ask ImgCache::configured() first');
        }
        return $config[$name];
    }

    /** constant(): the values come from config.php, which static analysis of src/ never sees
     *  @return array<string, mixed>|false */
    private static function config(): array|false
    {
        if (self::$config === null) {
            $config = [];
            foreach (self::REQUIRED as $name) {
                if (!defined($name)) {
                    return self::$config = false;
                }
                $config[$name] = constant($name);
            }
            $config['AA_MISSCACHE_IMG_DISABLE'] = defined('AA_MISSCACHE_IMG_DISABLE') && constant('AA_MISSCACHE_IMG_DISABLE');
            self::$config = self::derive($config);
        }
        return self::$config;
    }

    /** $config with what {img} asks about every image, computed once: src_base, enabled
     *  @param array<string, mixed> $config
     *  @return array<string, mixed>|false */
    private static function derive(array $config): array|false
    {
        if ((string) $config['IMG_UPLOAD_PATH'] === '') {
            return false;
        }
        $path               = trim((string) parse_url((string) $config['IMG_UPLOAD_URL'], PHP_URL_PATH), '/');
        $config['src_base'] = ($path === '') ? '' : "$path/";
        $config['enabled']  = !$config['AA_MISSCACHE_IMG_DISABLE'] && ($config['src_base'] !== '');
        return $config;
    }
}
