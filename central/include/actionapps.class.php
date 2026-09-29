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
use AA\Central\DefinitionStore;
use AA\Central\Difference;
use AA\Central\ResponderPolicy;
use AA\Central\SyncExecutor;
use AA\Central\Task\Sync;

// AA_Actionapps moved to AA\Central\Actionapps (src/Central/Actionapps.php). The old name stays
// for the callers and for the rows queued in toexecute before the move (O:13:"AA_Actionapps"),
// which the queue runner (misc/toexecute.php) unserializes without requiring this file. The class
// below is never declared - it only puts the old name into the composer classmap, so that
// autoloading AA_Actionapps includes this file and so registers the alias.
// The same for the Central task, now in AA\Central\Task - rows queued before hold AA_Task_Sync
// (with the AA embedded; the task takes both shapes).
// AA_Difference (now AA\Central\Difference) is not queued - the old name is for callers only.
class_alias(Actionapps::class, 'AA_Actionapps');
class_alias(Difference::class, 'AA_Difference');
class_alias(Sync::class, 'AA_Task_Sync');
if (false) {   // @phpstan-ignore if.alwaysFalse (never runs - for the classmap only)
    /** @deprecated use AA\Central\Actionapps */
    class AA_Actionapps extends Actionapps {}

    /** @deprecated use AA\Central\Difference */
    class AA_Difference extends Difference {}

    /** @deprecated use AA\Central\Task\Sync */
    class AA_Task_Sync extends Sync {}
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
}

class AA_Definition_Module_Slice extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = []): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $this->data      = DefinitionStore::sliceRows($module_id, self::normalizeLimited($limited));
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
        $this->data      = DefinitionStore::siteRows($module_id);
    }

    public function compareWith($dest_def) {}
}

class AA_Definition_Module_Alerts extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = false): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $this->data      = DefinitionStore::alertsRows($module_id);
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
     *  browsers post back as U+FFFD - the action would no longer unserialize.
     *  @param bool $preselect  false: unchecked by default (the caller knows both rows, the action
     *                          does not - and it is serialized to the remote AA, so no flag in it) */
    public function printToForm(bool $preselect = true): void
    {
        $form_value = base64_encode(serialize($this));
        echo '<div>';
        // a single value is updated with its row; deleting is the user's choice
        $default = $preselect && !in_array($this->type, ['UPDATE_VALUE', 'DELETE'], true);
        $state   = isset($_POST['sync']) ? in_array($form_value, (array) $_POST['sync'], true) : $default;
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
        return SyncExecutor::dataArray($this->identifier, $this->params);
    }

    /** do synchronization action in destination slice */
    public function doAction()
    {
        return SyncExecutor::run($this->type, $this->identifier, $this->params);
    }
}
