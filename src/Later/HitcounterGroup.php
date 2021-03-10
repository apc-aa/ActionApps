<?php

namespace AA\Later;

use AA\IO\DB\DB_AA;
use CVarset;

/** Groups data in hit_archive table
 *  We store all the hits into the hit_archive table. It is good for counting
 *  hit_1..., ... field statistics as well as for item display statistics.
 *  On the other hand the amount of data is huge, so we group the hit count for
 *  item on day basis (for older hits)
 **/
class HitcounterGroup implements LaterInterface
{

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {
        [$begin, $step] = $params;

        $varset = new CVarset;
        $time_cond = "time >= $begin AND time < " . ($begin + $step);
        $hits = GetTable2Array("SELECT id, sum(hits) as sum FROM hit_archive WHERE $time_cond GROUP BY id", 'id', 'sum');

        DB_AA::sql("DELETE FROM hit_archive WHERE $time_cond");
        if (is_array($hits)) {
            foreach ($hits as $id => $sum) {
                $varset->clear();
                $varset->add('id', 'number', $id);
                $varset->add('hits', 'number', $sum);
                $varset->add('time', 'number', $begin);
                $varset->doInsert('hit_archive');
            }
        }
    }

    /** plan grouping tasks to toexecute queue
     *  - by default we plan all the task
     */
    function plan($max_task = 0)
    {
        $oldest = GetTable2Array("SELECT min( time ) as oldest FROM `hit_archive`", 'aa_first', 'oldest');
        $grouping_start = mktime(0, 0, 0, date("m"), date("d") - 8, date("Y")); // week ago
        $step = 60 * 60 * 24;                        // day

        $toexecute = new Toexecute;
        // run for all the days from $oldest to week ago
        $no_task = 0;
        for ($begin = $grouping_start; $begin + $step >= $oldest; $begin -= $step) {
            $toexecute->laterOnce($this, [$begin, $step], "HitcounterGroup_$begin", 40, time() + 60 * 60 * 24); // once a day

            // by default we plan all the task
            if (++$no_task == $max_task) {
                break;
            }
        }
    }
}