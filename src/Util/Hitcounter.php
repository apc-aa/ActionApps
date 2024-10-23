<?php
/**
 * Class ItemContent.
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
 * @package   UserInput
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Util;

use AA_Logentry_H;
use CVarset;
use zids;

/** used for packing duration to 1B int tttttttcn
 *   - ttttttt - time index (0-63) - 6 bits - logaritmical scale from 0.002s (0) to 80s (63))
 *   - c - cached - 1 bit
 *   - n - not modified - 1 bit
 *
 *   there could be the "duration" column in the hit_archive table
 *      - if present, the duration is counted (for each of 4 groups separately)
 *          0 - NOT cached + modified,
 *          1 - NOT cached + not modified,
 *          2 - cached + modified,
 *          3 - cached + not modified
 *      - there is special Optimize function "Add hit duration log feature" for adding the column (so switching this feature on)
 */
class AA_Packtime {
    protected int  $index;
    protected bool $cached;
    protected bool $not_modified;

    public function __construct(float $time, bool $cached, bool $not_modified)
    {
        $this->index        = (new LogarithmIndex(.002,80,63))->getIndex($time);
        $this->cached       = $cached;
        $this->not_modified = $not_modified;
    }

    public function getAsInt(): int {
        return $this->index*4 + ($this->cached ? 2 : 0) + ($this->not_modified ? 1 : 0);
    }

    public static function factoryFromInt(int $int): self {
        $x = new self(0, ($int & 2) !== 0, ($int & 1) !== 0);
        $x->index = $int >> 2;
        return $x;
    }
}

/**
 * Hits logged to two temporary tables - hit_short_id (for short item ids) and
 * hit_long_id (for long item ids). With COUNTHIT_PROBABILITY
 * (eg. default 1000 ==> probability 0.001) we recount logged hits into table
 * item and move the hits to hit_archive table. hit_archive is then used for
 * making statistics - like "10 the most read items in last week, ..."
 *
 * The statistics is quite powerfull - it is counted only for the slices, where
 * you add the hit_1..........., hit_7........... or hit_30.......... field.
 * The hit_1.. field holds display count one day back, hit_7.. one week and
 * hit_30.. one month (no problem to add new field like hit_14, or hit_100 if
 * you need another time period). The statistics counting is quite demanding
 * task - you need to update all the items in the slice. That's why we use
 * periodical updater with different period for each field: Currently the time
 * periods are:
 *    hit_1  (day)   plan +/- 5   minutes
 *    hit_7  (week)  plan +/- 35  minutes
 *    hit_30 (month) plan +/- 150 minutes
 * For the "statistics counting" task we use toexecute table, so if you have
 * problems with statistics, check, if the script misc/toexecute.php is runned
 * form the aa cron (see AA -> Cron admin page)
 *
 * Why we use this approach? MySQL lock the item table for updte when someone do
 * a search in that table. If we want to view any fulltext, we can't, because we
 * have to wait for item.display_count update (which is locked). That's why we
 * log the hit into temporary table and from time to time
 * (with probability 1:1000) we update item table based on logs.
 *
 * Spliting into three tables we make increase the speed of the database
 * operations, which are often used in this case
 *
 * @param string $id id - short, long
 */
class Hitcounter
{

    /** Stores one item hot to temporary table and with some probability
     *  invokes the updateDisplayCount() method.
     *
     *  Static class function
     *
     *  We use two temporary tables - hit_short_id (for short item ids) and
     *  hit_long_id (for long item ids).
     * @param zids $zids
     * @param bool $cached
     */
    static function hit($zids, bool $cached = false, bool $not_modified = false)
    {
        if (!is_object($zids) or $zids->isEmpty()) {
            return;
        }

        // do not count hits from Bots
        $agent = strtolower($_SERVER["HTTP_USER_AGENT"]);
        if ((str_contains($agent, 'bot')) ||
            (str_contains($agent, 'crawl')) ||
            (str_contains($agent, 'check')) ||
            (str_contains($agent, 'spider')) ||
            (str_contains($agent, 'download'))
        ) {
            return;
        }

        $time = timestartdiff();
        $info = (new AA_Logentry_H([
            "cached"        => $cached,
            "not_modified"  => $not_modified,
            "time"          => $time,
            "packtime"      => (new AA_Packtime($time, $cached, $not_modified))->getAsInt(),
            "mem_usage"     => sprintf('%.3g',(memory_get_usage() / (1024*1024))),
            'uri'           => $_SERVER["REQUEST_URI"],
        ]))->serializeToString();

        $varset = new CVarset;
        $varset->add('time', 'number', time());
        $varset->add('agent', 'text', substr($_SERVER["HTTP_USER_AGENT"], 0, 255));  // @todo - convert to metabase and check the length using metabase real field length
        $varset->add('info', 'text', substr($info, 0, 255)); // @todo - convert to metabase and check the length using metabase real field length

        if ($zids->use_short_ids()) {
            $varset->add('id', 'number', $zids->id());
            $varset->doInsert('hit_short_id');
        } else {
            $varset->add('id', 'unpacked', $zids->longids(0));
            $varset->doInsert('hit_long_id');
        }

        // no longer necessary to plan it here - we plan the task in toexecute.php which must be called anyway if we want to tun tasks
        // it is not necessary to check, if the  AA\Later\HitcounterUpdate is planed
        // on each hit. We check it only once for 1000 (COUNTHIT_PROBABILITY)
        // if (COUNTHIT_PROBABILITY and rand(0, COUNTHIT_PROBABILITY) == 1) {
        //     (new Toexecute)->laterOnce(new ScheduleAaTasks, [], 'ScheduleAaTasks', 111, time());  // plan for now;  111 - quite urgent
        // }
    }
}


