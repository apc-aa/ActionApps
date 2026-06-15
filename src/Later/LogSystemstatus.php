<?php

/**
 *
 * PHP version 8.1+
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
use AA_Logentry_Sys;

/** logs system state which can help us to identify problems in AA (speed, stability) after upgrades */
class LogSystemstatus implements LaterInterface
{
    public function __construct() {}

    /** a special function called from AA\Later\Toexecute class - used for queued tasks (run form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        return AA_Log::writeIfChanged('SYS_STAT', 'basic', new AA_Logentry_Sys($this->getSystemInfo()));
    }

    public function getSystemInfo(): array
    {
        return [
            'aa'  => aa_version('aa'),
            'svn' => aa_version('svn2') . ' (' . aa_version('svn') . ')',
            'php' => PHP_VERSION,
            'db' => DB_AA::select1('ver', 'SELECT VERSION() as ver') . '/' . DB_AA::getDbType(),
            'mem' => trim(shell_exec("free -m | awk 'NR==2{print $2 \" MB\"}'")),
            'cpu' => trim(str_replace('Model name:', '', shell_exec("lscpu | grep 'Model name:'"))),
        ];
        //'load' => sys_getloadavg()[1] ?? 'N/A',            // 5 min avg
        //'memory_usage' => memory_get_usage(true) . '/' . ini_get('memory_limit'),
    }
}
