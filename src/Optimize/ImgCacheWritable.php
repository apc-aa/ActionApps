<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\ImgCache;

/** A cache which cannot store still delivers: the dispatcher generates the image and sends it
 *  without keeping it (MissCache::handleRequest()) - on every request, silently, with the load
 *  of a site without the cache. One look at the cache directory tells it.
 */
class ImgCacheWritable extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Image cache can store');
    }

    public static function description(): string
    {
        return _m('Resized images are generated once and kept in the cache directory in IMG_UPLOAD_PATH. Where PHP cannot write there, every request for such an image generates it again.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'cache';
    }

    /** making the directory writable is the server administrator's call - no repair */
    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        if (!ImgCache::enabled()) {
            return [];   // the extended check MissCacheRouting says the cache is off
        }
        $root     = ImgCache::root();
        $evidence = [[_m('Directory') => safe($root)]];
        $findings = [];
        if (is_link($root)) {   // one lstat - MissCache::purge() leaves a linked cache root alone
            $findings[] = new Finding(Finding::WARNING, _m('The image cache directory is a symbolic link'), evidence: $evidence, advice: _m('Resized images are stored through it, but AA neither purges old ones there nor writes the routing rule - the cache grows without limit. Replace the link with a directory, or keep it small by other means.'), key: 'link');
        }
        $findings[] = self::canStore($root)
            ? new Finding(Finding::OK, _m('The image cache can store resized images'), key: 'store')
            : new Finding(Finding::WARNING, _m('The image cache cannot store resized images'), evidence: $evidence, advice: _m('The images show, but each one which is not in the cache yet is generated again on every request - the load and delay of a site without the cache. Let the PHP user write into the directory, or into the upload directory, where AA creates it.'), key: 'store');
        return $findings;
    }

    /** "Test" in the optimize admin: the default passes anything but an error, and this check only warns */
    public function test(): bool
    {
        foreach ($this->findings() as $finding) {
            if ($finding->severity !== Finding::OK) {
                $this->message(safe($finding->title));
                return false;
            }
        }
        return true;
    }

    /** whether PHP can write into the cache root - or, before the first image, create it in the upload directory */
    public static function canStore(string $root): bool
    {
        return is_writable(is_dir($root) ? $root : \dirname($root));
    }
}
