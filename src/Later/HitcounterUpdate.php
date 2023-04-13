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

/** Used as object for toexecute - updates item.display_count and hit_archive
 *  based on hit log in hit_short_id and hit_long_id tables
 *  It also plans the hit_x..... field counting into toexecute queue
 */
class HitcounterUpdate implements LaterInterface
{

    /** updateDisplayCount - updates item.display_count and hit_archive based
     *  on hit log in hit_short_id and hit_long_id tables
     *                     - it also plans the the hit_x..... field counting
     *                       into toexecute queue
     *  Special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {

        // we can't count with current second, since the records for current
        // second could grow. Two seconds back should be OK.
        $time = time() - 2;

        // first look to the short id hit table;
        $hits_s = GetTable2Array("SELECT id, count(*) as count FROM hit_short_id WHERE time < $time GROUP BY id", 'id', 'count');

        // now look for long ids hits
        $hits_l = GetTable2Array("SELECT item.short_id, count(*) as count FROM hit_long_id INNER JOIN item ON hit_long_id.id=item.id
                                   WHERE hit_long_id.time < $time GROUP BY item.short_id", 'short_id', 'count');

        if (is_array($hits_s)) {
            foreach ( $hits_s as $short_id => $count ) {
                // add long ids count
                if ( isset($hits_l[$short_id]) ) {
                    $count += $hits_l[$short_id];
                    unset($hits_l[$short_id]);
                }
                if ( $count > 0 ) {
                    DB_AA::sql( "UPDATE item SET display_count=(display_count+$count) WHERE short_id = $short_id");
                }
            }
        }

        // Now the rest long_ids
        if (is_array($hits_l)) {
            foreach ( $hits_l as $short_id => $count ) {
                if ( $count > 0 ) {
                    DB_AA::sql( "UPDATE item SET display_count=(display_count+$count) WHERE short_id = $short_id");
                }
            }
        }

        DB_AA::sql("INSERT INTO hit_archive (id, time) SELECT id, time FROM hit_short_id WHERE time < $time");
        DB_AA::sql("DELETE FROM hit_short_id WHERE time < $time");

        DB_AA::sql("INSERT INTO hit_archive (id, time) SELECT item.short_id, hit_long_id.time FROM hit_long_id INNER JOIN item ON hit_long_id.id=item.id WHERE time < $time");
        DB_AA::sql("DELETE FROM hit_long_id WHERE time < $time");

        // once a day plan 3 new grouping (which means, that each day is counted
        // 3 times - in case it fails in one or two cases. If it fails three
        // times it is not so big problem - we can plan(0) sometime in the future
        $grouper = new HitcounterGroup;
        $grouper->plan(3);

        $this->updateDisplayStatistics();
        return "S:".count($hits_s)." L:".count($hits_l);
    }

    /** Plan task for counting statistics fields (hit_1, hit_7, ...)
     *  It is planed as AA\Later\Toexecute jobs
     */
    function updateDisplayStatistics() {
        $stats2count = GetTable2Array("SELECT id, slice_id FROM field WHERE slice_id <> 'AA_Core_Fields..' AND id LIKE 'hit_%'", '');

        if (is_array($stats2count)) {

            $toexecute = new Toexecute;
            $timeshift = 0;
            foreach ($stats2count as $to_count) {
                $count_slice_id = unpack_id($to_count['slice_id']);
                $field_id       = $to_count['id'];
                $stats_counter  = new HitcounterStats($count_slice_id, $field_id);

                // we plan this tasks for future
                // hit_1  (day)   plan +/- 50   minutes later
                // hit_7  (week)  plan +/- 350  minutes later (5,8 hours)
                // hit_30 (month) plan +/- 1500 minutes later (25 hours)
                $time2execute   = time() + ($stats_counter->getDays() * 300 * (10 + $timeshift++));
                $toexecute->laterOnce($stats_counter, [], "Count_". $count_slice_id.'_'.$field_id, 100, $time2execute);
            }
        }
    }
}
