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

/**
 * Class ScheduleAaTasks - task for planning all the other maintenance tasks
 * @package AA\Later
 */
class ScheduleAaTasks implements LaterInterface
{

    public function planAaTasks() {
        $toexecute = new Toexecute;

        $display_counter = new HitcounterUpdate();
        $toexecute->laterOnce($display_counter, [], 'HitcounterUpdate', 100, time() + 300);  // run it once in 5 minutes
        // $toexecute->laterOnce($display_counter, array(), 'HitcounterUpdate', 100, time() + 3000);  // run it once in 50 minutes

        $planned_task_scheduler = new PlannedtaskSchedule();
        $toexecute->laterOnce($planned_task_scheduler, [], 'PlannedtaskSchedule', 150, time() + 120);  // run it once in 2 minutes - high priority - the schedule must run even if there is big queue of e-mails/imports/...

        $computedfields_updater = new ComputedfieldsUpdater;
        $computedfields_updater->plan();

        // we plan this tasks for future (tomorrow)
        // it should be enough to clean the logs once a day

        // clean all older than 40 days
        //                                                                     what to clean        when to run
        $toexecute->laterOnce(new LogCleanup(now() - (60*60*24*40)), [], "LogClenup", 10, now() + (60*60*24));
        // clean all older than 40 days
        $toexecute->laterOnce(new Post2shtmlCleanup(now() - (60*60*24*40)), [], "Post2shtmlCleanup", 10, now() + (60*60*24));

        $toexecute->laterOnce(new ToexecuteCleanup(), [], "ToexecuteCleanup", 10, now() + (60*60*24));
    }

    /** Not used - we call it directly from toxecute.php3
     * @inheritDoc
     */
    public function toexecutelater($params = [])
    {
        $this->planAaTasks();
    }
}