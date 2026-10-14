<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\IO\DB\DB_AA;

/** Reads module definitions from this AA's database - the logic behind
 *  AA_Definition_Module(_Slice|_Site|_Alerts), which travel between AAs (Get_Module_Defs) and keep
 *  their names and properties; their methods delegate here. */
final class DefinitionStore
{
    /** the definition rows of a slice by table
     *  @param array<string, true> $limited  parts to load - see AA_Definition_Module::normalizeLimited() */
    public static function sliceRows($module_id, array $limited): array
    {
        $data            = [];
        $metabase        = AA::Metabase();

        if (isset($limited['definitions'])) {
            $data['module']       = $metabase->getModuleRows('module', $module_id);
            $data['slice']        = $metabase->getModuleRows('slice', $module_id);
            $data['field']        = $metabase->getModuleRows('field', $module_id);
            $data['view']         = $metabase->getModuleRows('view', $module_id);
            $data['email']        = $metabase->getModuleRows('email', $module_id);
            $data['email_notify'] = $metabase->getModuleRows('email_notify', $module_id);
            $data['profile']      = $metabase->getModuleRows('profile', $module_id);
            $data['rssfeeds']     = $metabase->getModuleRows('rssfeeds', $module_id);

            // @todo - do it better - check the fields setting, and get all the constants used
            $data['constant_slice'] = $metabase->getModuleRows('constant_slice', $module_id);
            $data['constant']       = $metabase->getModuleRows('constant', $module_id);
            // and the rows of their groups (getModuleRows() gives an array)
            $constant_groups = [];
            foreach ($data['constant'] as $k => $v) {
                $constant_groups[$v['group_id']] = true;
            }

            $where  = [['group_id', 'lt_groupNames'], ['value', array_filter(array_keys($constant_groups), static fn($group_id) => $group_id != '')]];
            $groups = [];
            foreach (DB_AA::select([], 'SELECT * FROM constant', $where) as $row) {
                $groups[unpack_id($row['id'])] = $row;
            }
            $metabase->unpackIds('constant', $groups);   // id and ancestors, as getModuleRows() does
            $data['constant'] = array_merge($data['constant'], $groups);
        }

        if (isset($limited['items'])) {
            $data['item']       = $metabase->getModuleRows('item', $module_id);
            $data['content']    = $metabase->getModuleRows('content', $module_id);
            $data['discussion'] = $metabase->getModuleRows('discussion', $module_id);
        }
        return $data;
    }

    /** the definition rows of a site module by table */
    public static function siteRows($module_id): array
    {
        $data            = [];
        $metabase        = AA::Metabase();

        $data['module']    = $metabase->getModuleRows('module', $module_id);
        $data['site']      = $metabase->getModuleRows('site', $module_id);
        $data['site_spot'] = $metabase->getModuleRows('site_spot', $module_id);
        return $data;
    }

    /** the definition rows of an alerts module by table */
    public static function alertsRows($module_id): array
    {
        $data            = [];
        $metabase        = AA::Metabase();

        $data['module']                     = $metabase->getModuleRows('module', $module_id);
        $data['alerts_collection']          = $metabase->getModuleRows('alerts_collection', $module_id);
        $data['alerts_collection_filter']   = $metabase->getModuleRows('alerts_collection_filter', $module_id);
        $data['alerts_collection_howoften'] = $metabase->getModuleRows('alerts_collection_howoften', $module_id);
        // @todo - filter is not copied since it need double jopined tabled in metabase's  getModuleRows()
        // $data['alerts_filter']              = $metabase->getModuleRows('alerts_filter', $module_id);
        return $data;
    }
}
