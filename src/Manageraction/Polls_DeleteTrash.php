<?php

/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php) - manipulates with polls
 *
 * Should be included to other scripts (admin/index.php)
 *
 *   Move polls to app/hold/trash based on param
 * @param $status     - static function parameter defined in manager action
 *                    in this case it holds bin number, where the central_confs should go
 * @param $item_arr   - array, where keys are unpacked ids of items prefixed by
 *                    'x' character (javascript purposes only)
 * @param $akce_param - additional parameter for the action - not used here
 *
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Managed_Table;
use AA\Manager;
use AA\Manageraction\AbstractManageraction;
use zids;

/** Manager action Polls_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all AAs in the trash bin
 */
class Polls_DeleteTrash extends AbstractManageraction
{
    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    public $selected;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, $selected = false)
    {
        $this->selected = $selected;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Remove (delete from database)');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (!IfSlPerm(PS_DELETE_ITEMS)) {    // permission to delete items?
            return _m("You do not have permissions to remove polls");
        }

        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Table) || !is_long_id($module = $manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }

        // only the trash of this module - the selected ids come from the POST
        $trash   = [['module_id', $module, 'l'], ['status_code', 3, 'i']];
        $refused = false;
        if ($this->selected) {
            [$ids, $refused] = $managed->actionable($manager, $item_zids);
            $trash[] = ['id', $ids];                  // an empty list matches no poll
        }

        if ($items_to_delete = DB_AA::select('id', 'SELECT id FROM polls', $trash)) {
            DB_AA::delete('polls', [['id', $items_to_delete], ['module_id', $module, 'l']]);
        }
        return $refused ? _m('Some of the selected polls were not removed - they belong to another module.') : false;
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return (IfSlPerm(PS_DELETE_ITEMS) && (!$this->selected || ($manager->getBin() === 'folder3')));
    }
}
