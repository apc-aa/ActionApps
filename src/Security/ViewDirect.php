<?php

declare(strict_types=1);

namespace AA\Security;

use AA;
use AA\Util\LogOnce;
use AA_Module;
use AA_Module_Site;
use AA_Modulesettings_Site;

/**
 * Views that work only inside pages and templates ({view:...}, {view.php?...}): a request that names the
 * view itself - view.php, jsview.php, discussion.php, a banner their URL picks - shows none of them, and
 * slice.php does not take their templates. Set for one view (VIEW_FLAG_NODIRECT), for the views of a slice,
 * or by a site for the views of every slice it uses. A refused call is logged once a day per view, for
 * State Check - a page that loads the view by its own URL (AJAX, lazy loading) only shows an empty place.
 * The outputs of such calls sit in the page cache, so whoever switches the setting on drops them.
 */
final class ViewDirect
{
    public const EVENT = 'VIEW_NODIR';

    /** the setting of a slice and of a site (AA_Modulesettings_Slice, AA_Modulesettings_Site): '1' = only inside pages */
    public const PROPERTY = 'view_nodirect';

    public const BY_VIEW  = 'view';
    public const BY_SLICE = 'slice';
    public const BY_SITE  = 'site';

    /**
     * Who keeps a view of the slice inside pages.
     * @param bool $view_set the view itself is set so
     * @return ?array{string, string} BY_* and the id of the slice or site that says so ('' for the view); null - nobody
     */
    public static function forbiddenBy(bool $view_set, string $slice_id): ?array
    {
        if ($view_set) {
            return [self::BY_VIEW, ''];
        }
        if (self::sliceForbids($slice_id)) {
            return [self::BY_SLICE, $slice_id];
        }
        $sites = self::sitesForbidding($slice_id);
        return $sites ? [self::BY_SITE, $sites[0]] : null;
    }

    /** @return list<string> the sites that use the slice and keep the views of their slices inside pages */
    public static function sitesForbidding(string $slice_id): array
    {
        if (!is_long_id($slice_id)) {
            return [];
        }
        $sites = AA::Contentcache()->get_result([AA_Module::class, 'belongsToSites'], [$slice_id]);
        return array_values(array_filter((array) $sites, self::siteForbids(...)));
    }

    /**
     * A site was saved: the page cache may hold outputs of view.php for the slices the site now keeps inside
     * pages.
     * @param list<string> $slices the slices the site uses now
     */
    public static function siteSaved(string $site_id, array $slices): void
    {
        if ($slices && self::siteForbids($site_id)) {
            AA::Pagecache()->invalidateFor($slices);
        }
    }

    /** The setting of a site, read from its stored settings: building the site module (AA_Module::getModule())
     *  would make the site a tag of the page being cached, and every save of the site would drop that page. */
    private static function siteForbids(string $site_id): bool
    {
        $settings = AA_Modulesettings_Site::load(AA_Module_Site::getModuleObjectId($site_id));
        return (string) $settings?->getProperty(self::PROPERTY) === '1';
    }

    /** the setting of a slice */
    public static function sliceForbids(string $module_id): bool
    {
        return (string) AA_Module::getModuleProperty($module_id, self::PROPERTY) === '1';
    }

    /**
     * The answer to a refused call: nothing, as for a view that does not exist - and never from the cache,
     * so the view shows again as soon as the setting is off.
     * @param array{string, string} $by    forbiddenBy()
     * @param bool                  $whole the view is the one the request asked for, not a banner inside it
     */
    public static function refuse(int $vid, array $by, bool $whole = true): void
    {
        if ($whole) {
            AA::$headers['status'] = 'HTTP/1.0 404 Not Found';
        }
        AA::Pagecache()->switchCacheNostore();
        LogOnce::daily(self::EVENT, ParamImplode([(string) $vid, $by[0], $by[1]]), [ProtectionLog::requested(), ProtectionLog::referer()]);
    }
}
