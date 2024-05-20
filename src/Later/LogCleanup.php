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

class LogCleanup implements LaterInterface {

    /** @var int delete older than $time in seconds */
    protected int   $time;

    /** @var array delete only this log types */
    protected array $type;

    /** @var array delete all log types except this ones */
    protected array $except;

    function __construct(int $time, array $type=[], array $except=[]) {
        $this->time   = $time;
        $this->type   = $type;
        $this->except = $except;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (run form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {
        $where = [['time', $this->time, '<']];
        if ( $this->type ) {
            $where[] = ['type',$this->type];
        }
        if ( $this->except ) {
            $where[] = ['type',$this->except, 's<>'];
        }
        return (new TableCleaner('log', $where))->clean();
    }
}
