<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\Central\Task\ImportModuleChunk;
use AA\Later\Toexecute;
use AA_Definition_Module_Chunk;
use Cvarset;

/** Reads module definitions from this AA's database and imports them - the logic behind
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
                $constant_groups[$v['group_id']] = true;   // get list of all groups
            }

            $SQL = "SELECT * FROM constant WHERE group_id='lt_groupNames' AND " . Cvarset::sqlin('value', array_keys($constant_groups));

            $groups = GetTable2Array($SQL, "unpack:id", 'aa_fields');
            if (!is_array($groups)) {
                $groups = [];
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

    /** the definition split into chunks for import, with the ids in $replaces replaced - in the rows and in
     *  the module the chunks name (the chunk mode writes only into it; an older Central named the template -
     *  such chunks are refused now)
     *  @return AA_Definition_Module_Chunk[] */
    public static function chunks($module_id, $data, $replaces): array
    {
        $chunks = [];
        // replaced on a copy - the same definition is imported to more AAs, each with its own new module ids
        $data      = is_array($replaces) ? recursive_array_replace(array_keys($replaces), array_values($replaces), $data) : $data;
        $module_id = (is_array($replaces) && is_scalar($module_id)) ? ($replaces[$module_id] ?? $module_id) : $module_id;

        foreach ($data as $table => $rows) {
            $chunks[] = AA_Definition_Module_Chunk::factoryArray($module_id, $table, $rows);
        }
        return array_merge(...$chunks);  // avoiding array_merge() in loop
    }

    /** queues the import of the chunks to another AA (Task Manager); @return int number of the tasks */
    public static function queueImport(array $chunks, Actionapps|int $aa): int
    {
        $toexecute = new Toexecute();
        foreach ($chunks as $chunk) {
            // the queue row keeps the id of the AA, not its login; the selector keeps its old name
            $toexecute->userQueue(new ImportModuleChunk($chunk, $aa), [], 'AA_Task_Import_Module_Chunk');
        }
        return count($chunks);
    }

    /** imports the chunks to this AA (chunk mode, each into its module; $target: tests only) @return list<string> report lines */
    public static function import(array $chunks, ?ChunkTarget $target = null): array
    {
        $report = [];
        foreach ($chunks as $chunk) {
            $report[] = $chunk->importModuleChunk($target);
        }
        return array_merge(...$report);
    }
}
