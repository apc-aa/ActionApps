<?php
/**
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

namespace AA\Later;

use AA\IO\DB\DB_AA;
use AA_Log;

class LogCleanup implements LaterInterface
{

    var $time;
    var $type;

    function __construct($time, $type='') {
        $this->time = $time;
        $this->type = $type;
    }

    /** just plan item updates in chunks
     *  special function called from AA\Later\Toexecute class - used for queued tasks (run form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {
        $where = [['time', $this->time, '<']];

        // delete also old records post2shtml table
        DB_AA::delete('post2shtml', $where);

        if ( $this->type ) {
            $where[] = ['type',$this->type];
        }
        DB_AA::delete('log', $where);

        // delete task with priority = 0 - considered as undoable (note the priority is decreased on each try of task execution.)
        $undoable_tasks = GetTable2Array('SELECT * FROM toexecute WHERE priority=0', 'NoCoLuMn');
        AA_Log::write('TOEXECUTE', 'cleanup', $undoable_tasks);
        DB_AA::delete('toexecute', [['priority',0,'i']]);
    }
}
