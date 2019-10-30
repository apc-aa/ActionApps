<?php
/**
 *
 * PHP versions 5 and 7
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

namespace AA\Util;


/**
 * Interface LaterInterface - enable to plan this object execution for later time
 * Ussage:
 *   $cache_purger  = new AA_Pagecache_Purge();
 *   $toexecute     = new AA_Toexecute;
 *   $toexecute->laterOnce($cache_purger, array($this->cacheTime), 'AA_Pagecache_Purge', 101, now() + 300);  // run it once in 5 minutes
 * @package AA\Util
 */
interface LaterInterface {

    /** special function called from AA_Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     */
    public function toexecutelater($params=array());

}