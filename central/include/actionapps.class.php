<?php

/**
 * Central's classes which travel between AAs (module definitions, sync actions). One AA
 * installation is AA\Central\Actionapps, the tasks Central queues in toexecute are AA\Central\Task\*.
 *
 * Should be included to other scripts (as /admin/index.php)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

use AA\Central\Actionapps;
use AA\Central\Difference;
use AA\Central\ResponderPolicy;
use AA\Central\Task\ImportModuleChunk;
use AA\Central\Task\Sync;
use AA\Later\Toexecute;

// AA_Actionapps moved to AA\Central\Actionapps (src/Central/Actionapps.php). The old name stays
// for the callers and for the rows queued in toexecute before the move (O:13:"AA_Actionapps"),
// which the queue runner (misc/toexecute.php) unserializes without requiring this file. The class
// below is never declared - it only puts the old name into the composer classmap, so that
// autoloading AA_Actionapps includes this file and so registers the alias.
// The same for the Central tasks, now in AA\Central\Task - rows queued before hold AA_Task_Sync /
// AA_Task_Import_Module_Chunk (with the AA embedded; the tasks take both shapes).
// AA_Difference (now AA\Central\Difference) is not queued - the old name is for callers only.
class_alias(Actionapps::class, 'AA_Actionapps');
class_alias(Difference::class, 'AA_Difference');
class_alias(Sync::class, 'AA_Task_Sync');
class_alias(ImportModuleChunk::class, 'AA_Task_Import_Module_Chunk');
if (false) {   // @phpstan-ignore if.alwaysFalse (never runs - for the classmap only)
    /** @deprecated use AA\Central\Actionapps */
    class AA_Actionapps extends Actionapps {}

    /** @deprecated use AA\Central\Difference */
    class AA_Difference extends Difference {}

    /** @deprecated use AA\Central\Task\Sync */
    class AA_Task_Sync extends Sync {}

    /** @deprecated use AA\Central\Task\ImportModuleChunk */
    class AA_Task_Import_Module_Chunk extends ImportModuleChunk {}
}


class AA_Definition_Module
{
    /** module id is unpacked */
    protected $module_id;
    /** data for the module */
    protected $data;

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
    {
        $this->module_id = null;
        $this->data      = [];
    }

    public function loadForId($module_id, $limited = false): void
    {
        $this->module_id = $module_id;
        $this->clear();
        // should be overloaded in childs
    }

    public function getArray()
    {
        return $this->data;
    }

    /** Parts loadForId() loads: ['definitions' => true, 'items' => true]. Bool true is the pre-2008
     *  form (= definitions only); anything else loads nothing.
     *  @return array<string, true> */
    public static function normalizeLimited($limited): array
    {
        if ($limited === true) {
            return ['definitions' => true];
        }
        $ret = [];
        foreach (['definitions', 'items'] as $part) {
            if (is_array($limited) && !empty($limited[$part])) {
                $ret[$part] = true;
            }
        }
        return $ret;
    }

    public function getId()
    {
        return $this->module_id;
    }

    /** returns module name */
    public function getName()
    {
        return $this->data['module']['name'];
    }

    public function compareWith($dest_def)
    {
        // should be overloaded in childs
    }

    /** used when you want to export module to another AA
        - could be used for the same AA as well, but we instead now have
        moduleImport() method, which do not need special http requests and is
        quicker. Also, there was some problem when there was different encoding
        in the modules.
    */
    public function planModuleImport(Actionapps|int $aa, $replaces = null): int
    {
        $chunks    = $this->_getChunks($replaces);
        $toexecute = new Toexecute();
        foreach ($chunks as $chunk) {
            // the queue row keeps the id of the AA, not its login; the selector keeps its old name
            $toexecute->userQueue(new ImportModuleChunk($chunk, $aa), [], 'AA_Task_Import_Module_Chunk');
        }
        return count($chunks);
    }

    /** special import - to this AA - no need to plan, no need to chunk
     *  @return list<string> report lines of the chunks ('OK: ...', 'Err: ...') */
    public function moduleImport($replaces = null): array
    {
        $report = [];
        foreach ($this->_getChunks($replaces) as $chunk) {
            $report[] = $chunk->importModuleChunk();
        }
        return array_merge(...$report);
    }

    protected function _getChunks($replaces): array
    {
        $chunks = [];
        // replaced on a copy - the same definition is imported to more AAs, each with its own new module ids
        $data   = is_array($replaces) ? recursive_array_replace(array_keys($replaces), array_values($replaces), $this->data) : $this->data;

        foreach ($data as $table => $rows) {
            $chunks[] = AA_Definition_Module_Chunk::factoryArray($this->module_id, $table, $rows);
        }
        return array_merge(...$chunks);  // avoiding array_merge() in loop
    }
}

/** AA_Definition_Module could be splited to more pieces (chunks) for easier
 *  transmission and import
 */
class AA_Definition_Module_Chunk
{
    /** module id is unpacked */
    protected $module_id;
    /** part of the data for the module definition */
    protected $data;

    /** Creates array of chunks from given data. The data are split to more chunks, if it is bigger than limit. */
    public static function factoryArray($module_id, $table, $rows): array
    {
        $chunks = [];

        // maximum 1000 rows in one chunk
        $chunks_data = array_chunk($rows, 1000, true);
        foreach ($chunks_data as $chunk_data) {
            $chunks[] = new AA_Definition_Module_Chunk($module_id, $table, $chunk_data);
        }
        return $chunks;
    }

    public function __construct($module_id, $table, $rows)
    {
        $this->module_id = $module_id;
        $this->data      = [$table => $rows];
    }

    public function importModuleChunk(): array
    {

        $metabase    = AA::Metabase();

        // a table gets into ok/err with its first row there - no "Err: 0 field ()" for a clean import
        $msgs = ['ok' => [], 'err' => []];

        foreach ($this->data as $table => $records) {
            if (empty($records)) {
                continue;
            }

            $keys = $metabase->getKeys($table);   // just for reporting
            // now we packing the data in the AA_Metabase::do*() functions
            // $metabase->packIds($table, $records);    // pack ids ...
            foreach ($records as $data) {
                $ident = [];
                foreach ($keys as $col) {
                    $ident[] = $data[$col];
                }

                if ($res = $metabase->doInsert($table, $data, 'nohalt')) {
                    $msgs['ok'][$table][] = $ident;
                } else {
                    $msgs['err'][$table][] = $ident;
                }

            }
        }
        $ret = [];
        if ($msg = $this->_formatReport($msgs['ok'])) {
            $ret[] = 'OK: ' . $msg;
        }
        if ($msg = $this->_formatReport($msgs['err'])) {
            $ret[] = 'Err: ' . $msg;
        }
        return $ret;
    }

    public function _formatReport($arr): string
    {
        $ret = '';
        foreach ($arr as $table => $idents) {
            $ret .= count($idents) . " $table (";
            foreach ($idents as $ident) {
                $ret .= '[' . implode(',', $ident) . ']';
            }
            $ret .= '); ';
        }
        return $ret;
    }
}

class AA_Definition_Module_Slice extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = []): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();
        $limited         = self::normalizeLimited($limited);

        if (isset($limited['definitions'])) {
            $this->data['module']       = $metabase->getModuleRows('module', $module_id);
            $this->data['slice']        = $metabase->getModuleRows('slice', $module_id);
            $this->data['field']        = $metabase->getModuleRows('field', $module_id);
            $this->data['view']         = $metabase->getModuleRows('view', $module_id);
            $this->data['email']        = $metabase->getModuleRows('email', $module_id);
            $this->data['email_notify'] = $metabase->getModuleRows('email_notify', $module_id);
            $this->data['profile']      = $metabase->getModuleRows('profile', $module_id);
            $this->data['rssfeeds']     = $metabase->getModuleRows('rssfeeds', $module_id);

            // @todo - do it better - check the fields setting, and get all the constants used
            $this->data['constant_slice'] = $metabase->getModuleRows('constant_slice', $module_id);
            $this->data['constant']       = $metabase->getModuleRows('constant', $module_id);
            if (is_array($this->data['constant'])) {
                $constant_groups = [];
                foreach ($this->data['constant'] as $k => $v) {
                    $constant_groups[$v['group_id']] = true;   // get list of all groups
                }

                $SQL = "SELECT * FROM constant WHERE group_id='lt_groupNames' AND " . Cvarset::sqlin('value', array_keys($constant_groups));

                $groups = GetTable2Array($SQL, "unpack:id", 'aa_fields');
                if (!is_array($groups)) {
                    $groups = [];
                }
                $metabase->unpackIds('constant', $groups);   // id and ancestors, as getModuleRows() does
                $this->data['constant'] = array_merge($this->data['constant'], $groups);
            }
        }

        if (isset($limited['items'])) {
            $this->data['item']       = $metabase->getModuleRows('item', $module_id);
            $this->data['content']    = $metabase->getModuleRows('content', $module_id);
            $this->data['discussion'] = $metabase->getModuleRows('discussion', $module_id);
        }
    }

    /** @return Difference[] - an INFO row alone when a side is missing; an empty destination gives NEW rows */
    public function compareWith($dest_def): array
    {
        return Difference::compareSlice($this->data, $this->module_id, $dest_def);
    }
}

class AA_Definition_Module_Site extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = false): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();

        $this->data['module']    = $metabase->getModuleRows('module', $module_id);
        $this->data['site']      = $metabase->getModuleRows('site', $module_id);
        $this->data['site_spot'] = $metabase->getModuleRows('site_spot', $module_id);
    }

    public function compareWith($dest_def) {}
}

class AA_Definition_Module_Alerts extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = false): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();

        $this->data['module']                     = $metabase->getModuleRows('module', $module_id);
        $this->data['alerts_collection']          = $metabase->getModuleRows('alerts_collection', $module_id);
        $this->data['alerts_collection_filter']   = $metabase->getModuleRows('alerts_collection_filter', $module_id);
        $this->data['alerts_collection_howoften'] = $metabase->getModuleRows('alerts_collection_howoften', $module_id);
        // @todo - filter is not copied since it need double jopined tabled in metabase's  getModuleRows()
        // $this->data['alerts_filter']              = $metabase->getModuleRows('alerts_filter', $module_id);
    }

    public function compareWith($dest_def) {}
}

class AA_Identifier
{
    /**  $path[0] ~ module_id, [1] ~ table, [2] ~ row, [3] ~ column */
    private array $path = [];

    public function __construct($module_id = null, $table = null, $row = null, $column = null)
    {
        if ($module_id) {
            $this->path[0] = $module_id;
            if ($table) {
                $this->path[1] = $table;
                if ($row) {
                    $this->path[2] = $row;
                    if ($column) {
                        $this->path[3] = $column;
                    }
                }
            }
        }
    }

    public function getModuleId()
    {
        return $this->path[0] ?? null;
    }
    public function getTable()
    {
        return $this->path[1] ?? null;
    }
    public function getRow()
    {
        return $this->path[2] ?? null;
    }
    public function getColumn()
    {
        return $this->path[3] ?? null;
    }

    /** Parses the identifier string (like "Configuraci�n->field->category........")
     *  static member function - called like $idf = AA_Identifier::factoryFromString($idf_string)
     */
    public function factoryFromString($idf): AA_Identifier
    {
        [$module_id, $table, $row, $column] = explode('->', $idf);
        return new AA_Identifier($module_id, $table, $row, $column);
    }

    /** creates identifier which identifies one part of the current idenftifier
     *  say '534633'->view->32  ---> .sub('name') ---> '534633'->view->32->name
     */
    public function sub($sub_id): void
    {
        $this->path[] = $sub_id;
        return;
    }

    public function toString(): string
    {
        return implode('->', $this->path);
    }
}

/** Class which defines synchronization actions */
class AA_Sync_Action
{
    /** The only tables an action may write - the ones compareWith() compares. Metabase itself
     *  would accept ~50, including toexecute (unserialized later), users and central_conf. */
    public const TABLES = ['module', 'slice', 'field', 'view', 'email', 'constant_slice', 'constant'];

    /** Columns of the installation, not of the slice definition: ids of its users and of its other
     *  rows (slice_owner, view, the MLX control slice), its URLs and paths, its menu order and
     *  deleted state, its reading password, its own numbering. compareWith() skips them and
     *  doAction() does not update them. */
    public const INSTALLATION_COLUMNS = [
        'module'   => ['owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'priority'],
        'slice'    => ['owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'mlxctrl', 'vid', 'fileman_dir', 'reading_password'],
        'constant' => ['short_id'],
    ];

    /** action type  - DELETE | INSERT | UPDATE_ROW | UPDATE_VALUE */
    private string $type;

    /** AA_Identifier object holding something like 'My Slice->view->678->name' */
    private AA_Identifier $identifier;

    /** action parameters (field's data). Could be scalar as well as array */
    private $params;

    public function __construct(string $type, AA_Identifier $identifier, $params = null)
    {
        $this->type       = $type;
        $this->identifier = $identifier;
        $this->params     = $params;
    }

    /** The checkbox value is base64: serialize() wraps private property names in NUL bytes, which
     *  browsers post back as U+FFFD - the action would no longer unserialize. */
    public function printToForm(): void
    {
        $form_value = base64_encode(serialize($this));
        echo '<div>';
        $state = isset($_POST['sync']) ? in_array($form_value, (array) $_POST['sync'], true) : ($this->type !== 'UPDATE_VALUE');
        FrmChBoxEasy('sync[]', $state, '', $form_value);
        switch ($this->type) {
            case 'DELETE': echo _m("Delete");
                break;
            case 'INSERT': echo _m("Create new");
                break;
            case 'UPDATE_ROW':
            case 'UPDATE_VALUE': echo _m("Update");
                break;
        }
        echo '</div>';
    }

    /** Action from a printToForm() checkbox value; null for anything else - the value is request input. */
    public static function fromFormValue(string $form_value): ?AA_Sync_Action
    {
        $serialized = base64_decode($form_value, true);
        if ($serialized === false) {
            return null;
        }
        try {
            // @: malformed data only warns and returns false, rejected below
            $action = @unserialize($serialized, ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);
        } catch (\Throwable) {  // e.g. a foreign object in the typed $identifier
            return null;
        }
        return ($action instanceof self && isset($action->type, $action->identifier)) ? $action : null;
    }

    /** returns data array as we need it for $metabase->doInsert/Update */
    public function _getDataArray()
    {
        $idf    = $this->identifier;
        $column = $idf->getColumn();
        return $column ? [$column => $this->params] : $this->params;
    }

    /** $data without the auto_increment columns which are not keys (constant.short_id) - each
     *  installation numbers its rows itself */
    private static function withoutOwnNumbering(string $table, array $data): array
    {
        foreach (AA::Metabase()->getColumns($table) as $colname => $column) {
            if ($column->isAutoIncrement() && !$column->isKey()) {
                unset($data[$colname]);
            }
        }
        return $data;
    }

    /** do synchronization action in destination slice */
    public function doAction()
    {

        // commands are stored as tree - like:
        // 6353636737->field->category........
        // 6353636737 is the id of the destination slice (compareWith() builds the actions for it)
        $idf         = $this->identifier;
        $module_id   = $idf->getModuleId();
        $table       = $idf->getTable();
        $row         = $idf->getRow();
        //$column      = $idf->getColumn();

        if (!in_array($table, self::TABLES, true)) {
            return _m('Wrong command - this table is not synchronized');
        }
        $metabase    = AA::Metabase();

        if (!$row) {
            return _m('Wrong command - row is not defined - %1', [$idf->toString()]);
        }
        // nohalt: a DB error must not die() in the middle of the batch - it is reported instead
        if ($this->type === 'UPDATE_ROW' || $this->type === 'UPDATE_VALUE') {
            // an older Central (or a queued AA_Task_Sync) still sends the installation columns
            $data = array_diff_key(self::withoutOwnNumbering($table, (array) $this->_getDataArray()), array_flip(self::INSTALLATION_COLUMNS[$table] ?? []));
            $metabase->fillKeys($data, $idf);
            if (!array_diff_key($data, array_flip($metabase->getKeys($table)))) {
                return _m('%1 %2 in slice %3: nothing to update', [$table, $row, $module_id]);
            }
            return !$metabase->doUpdate($table, $data, 'nohalt')
                ? _m('Error: %1 %2 in slice %3 not updated', [$table, $row, $module_id])
                : _m('%1 %2 in slice %3 updated', [$table, $row, $module_id]);
        }
        if ($this->type === 'INSERT') {
            $data = self::withoutOwnNumbering($table, (array) $this->params);
            if ($table === 'module' || $table === 'slice') {
                $data['deleted'] = 0;   // a template flagged deleted must not create a deleted slice
            }
            $metabase->fillKeys($data, $idf);
            return !$metabase->doInsert($table, $data, 'nohalt')
                ? _m('Error: %1 %2 not inserted into slice %3', [$table, $row, $module_id])
                : _m('%1 %2 inserted into slice %3', [$table, $row, $module_id]);  // field xy inserted into slice yz
        }
        if ($this->type === 'DELETE') {
            $data = [];
            $metabase->fillKeys($data, $idf);
            return !$metabase->doDelete($table, $data, 'nohalt')
                ? _m('Error: %1 %2 not deleted from slice %3', [$table, $row, $module_id])
                : _m('%1 %2 deleted from slice %3', [$table, $row, $module_id]);
        }
        return _m("Unknown action (%1) for field %2 in slice %3", [$this->type, $row, $module_id]);
    }
}
