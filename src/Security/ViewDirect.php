<?php

declare(strict_types=1);

namespace AA\Security;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Module;
use AA_Module_Site;
use AA_Modulesettings_Site;

/**
 * Views that work only inside pages and templates ({view:...}, {view.php?...}): view.php and jsview.php
 * show none of them. Set for one view (VIEW_FLAG_NODIRECT), for the views of a slice, or by a site for the
 * views of every slice it uses. A refused call is logged once a day per view, for State Check - a page
 * that loads the view by its own URL (AJAX, lazy loading) only shows an empty place.
 */
final class ViewDirect
{
    public const EVENT = 'VIEW_NODIR';

    /** the setting of a slice and of a site (AA_Modulesettings_Slice, AA_Modulesettings_Site): '1' = only inside pages */
    public const PROPERTY = 'view_nodirect';

    public const BY_VIEW  = 'view';
    public const BY_SLICE = 'slice';
    public const BY_SITE  = 'site';

    /** the scripts that show a view asked for by the URL; discussion.php shows discussions, with their forms */
    private const SCRIPTS = ['view.php', 'view.php3', 'jsview.php', 'jsview.php3'];

    /** the request asks one of the scripts for a view */
    public static function isDirectCall(): bool
    {
        return in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), self::SCRIPTS, true);
    }

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
        if (self::moduleForbids($slice_id)) {
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
        // a deleted site decides nothing
        $sites = AA::Contentcache()->get_result([DB_AA::class, 'select'], ['', 'SELECT LOWER(HEX(relation.source_id)) AS unpackid FROM relation, module', [
            ['relation.destination_id', $slice_id, 'l'], ['relation.flag', REL_FLAG_MODULE_DEPEND, 'i'], ['module.id', 'relation.source_id', 'j'], ['module.deleted', 0, 'i'],
        ]]);
        return array_values(array_filter((array) $sites, static fn(string $site): bool => self::moduleForbids($site)));
    }

    /** A site was saved: view.php may hold outputs of the views of its slices from before the site kept
     *  them inside pages, or from before it took a slice in. Read from the database - the request has the
     *  settings and the slices as they were before the save. */
    public static function siteSaved(string $site_id): void
    {
        $settings = AA_Modulesettings_Site::load(AA_Module_Site::getModuleObjectId($site_id));
        if ((string) $settings?->getProperty(self::PROPERTY) !== '1') {
            return;
        }
        foreach (DB_AA::select('', 'SELECT LOWER(HEX(`destination_id`)) AS unpackid FROM relation', [['source_id', $site_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]) as $slice_id) {
            AA::Pagecache()->invalidateFor($slice_id);
        }
    }

    public static function moduleForbids(string $module_id): bool
    {
        return (string) AA_Module::getModuleProperty($module_id, self::PROPERTY) === '1';
    }

    /**
     * The answer to a refused call: nothing, as for a view that does not exist - and never from the cache,
     * so the view shows again as soon as the setting is off.
     * @param array{string, string} $by forbiddenBy()
     */
    public static function refuse(int $vid, array $by): void
    {
        AA::$headers['status'] = 'HTTP/1.0 404 Not Found';
        AA::Pagecache()->switchCacheNostore();
        LogOnce::daily(self::EVENT, ParamImplode([(string) $vid, $by[0], $by[1]]), [ProtectionLog::requested(), ProtectionLog::referer()]);
    }
}
