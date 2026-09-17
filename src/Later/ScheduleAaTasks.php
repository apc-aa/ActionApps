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

use AA\Optimize\MissCacheRouting;
use AA_Mysqlauth_Maintain;

/**
 * Class ScheduleAaTasks - task for planning all the other maintenance tasks
 *
 * @package AA\Later
 */
class ScheduleAaTasks implements LaterInterface
{
    public function planAaTasks(): void
    {
        $toexecute = new Toexecute();

        $display_counter = new HitcounterUpdate();
        $toexecute->laterOnce($display_counter, [], 'HitcounterUpdate', 100, time() + 300);  // run it once in 5 minutes
        // $toexecute->laterOnce($display_counter, array(), 'HitcounterUpdate', 100, time() + 3000);  // run it once in 50 minutes

        $planned_task_scheduler = new PlannedtaskSchedule();
        $toexecute->laterOnce($planned_task_scheduler, [], 'PlannedtaskSchedule', 150, time() + 120);  // run it once in 2 minutes - high priority - the schedule must run even if there is big queue of e-mails/imports/...

        $computedfields_updater = new ComputedfieldsUpdater();
        $computedfields_updater->plan();

        $toexecute->laterOnce(new AA_Mysqlauth_Maintain(), [], 'AA_Mysqlauth_Maintain', 10, now() + (60 * 60 * 24));

        // we plan this tasks for future (tomorrow)
        // it should be enough to clean the logs once a day

        // clean all older than 40 days
        //                                                                what to clean                                                                       when to run
        $toexecute->laterOnce(new LogCleanup(now() - (60 * 60 * 24 * 40), [], ['LOGIN', 'BM_CREATE', 'BM_DELETE', 'BM_RENAME', 'BM_UPDATE', 'SYS_STAT', StateCheck::EVENT]), [], "LogCleanup", 10, now() + (60 * 60 * 24));  // delete all log entries older than 40 days except LOGINs and Bookmark opperations

        $toexecute->laterOnce(new LogSystemstatus(), [], "LogSystemstatus", 10, now() + 600);  // log system status every 10 minutes

        // daily State Check snapshot - the STATE_CHK rows behind admin/aa_statecheck.php
        $toexecute->laterOnce(new StateCheck(), [], 'StateCheck', 10, now() + (60 * 60 * 24));

        $toexecute->laterOnce(new Post2shtmlCleanup(now() - (60 * 60 * 24 * 40)), [], "Post2shtmlCleanup", 10, now() + (60 * 60 * 24));

        // OPTIMIZE TABLE over the AA tables once a week - was the misc/optimize.php cron entry until 2026-09
        $toexecute->laterOnce(new OptimizeTables(), [], 'OptimizeTables', 10, now() + (60 * 60 * 24 * 7));

        $toexecute->laterOnce(new ToexecuteCleanup(), [], "ToexecuteCleanup", 10, now() + (60 * 60 * 24));

        // the sweeps that were cron table rows until 2026-09: alerts (instant + digests), feeds, link check
        foreach (AlertsSweep::SCHEDULE as $howoften => $when) {
            $priority = ($howoften === 'instant') ? 100 : 10;   // readers wait for the instant sweep; the digests may queue behind mails
            $toexecute->laterOnce(new AlertsSweep($howoften), [], "AlertsSweep:$howoften", $priority, (int) strtotime($when));
        }
        $toexecute->laterOnce(new FeedsRun(), [], 'FeedsRun', 100, time() + 900);
        $toexecute->laterOnce(new LinkcheckRun(), [], 'LinkcheckRun', 10, time() + 3600);

        // purge MissCache forge-on-miss image cache: files unused > 30 days, plus a 2 GB size cap
        $toexecute->laterOnce(new MissCachePurge(), [], "MissCachePurge", 10, now() + (60 * 60 * 24));
        // {img} emits cache urls without checking that a miss reaches the dispatcher - put the
        // routing .htaccess back within minutes of an update, not a day (one stat when it is there).
        // Nothing it could throw may stop the queue; State Check reports a routing that fails.
        try {
            MissCacheRouting::ensure();
        } catch (\Throwable) {
        }

        // invalidate cached pages after items expire / get published by the clock - AA\Later\PagecacheDateSweep
        if (PagecacheDateSweep::isEnabled()) {
            $toexecute->laterOnce(new PagecacheDateSweep(), [], 'PagecacheDateSweep', 101, time() + PagecacheDateSweep::interval());
        }
    }

    /** Not used - we call it directly from toxecute.php
     * @inheritDoc
     */
    public function toexecutelater($params = []): string
    {
        $this->planAaTasks();
        return '';
    }
}
