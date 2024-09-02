<?php

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA\Optimize\AddDurationLog;
use CVarset;

/** Groups data in hit_archive table
 *  We store all the hits into the hit_archive table. It is good for counting
 *  hit_1..., ... field statistics as well as for item display statistics.
 *  On the other hand the amount of data is huge, so we group the hit count for
 *  item on day basis (for older hits)
 **/
class HitcounterGroup implements LaterInterface
{
    /** @var bool|null caches if we use duration logging for speedup */
    static ?int $duration_log_feature_from = null;

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {
        if (is_null(self::$duration_log_feature_from)) {
            $duration_helper = new AddDurationLog();
            if ($duration_helper->test()) {
                self::$duration_log_feature_from = $duration_helper->getFromTime();
                // reload table structure from database - in standard metabase we do not have extended hit_archive table
                AA::Metabase()->loadFromDb(true,['hit_archive']);  // it is not standard table - we have to read it
            } else {
                // duration not used
                self::$duration_log_feature_from = 0;
            }
        }

        [$begin, $step] = $params;

        if (self::$duration_log_feature_from AND (self::$duration_log_feature_from < $begin)) {
            $this->groupHitsDuration($begin, $step);
        } else {
            $this->groupHitsBasic($begin, $step);
        }
    }

    /** group code for basic log without duration stats - older code */
    protected function groupHitsBasic(int $begin, int $step) {
        $time_cond = $this->getTimecondition($begin, $step);

        // possible new approach
        // SELECT id, CONCAT(FROM_UNIXTIME(`time`, '%Y-%m-%d'),' ',`id`) as grp, sum(hits) as sum, count(*) as cnt FROM hit_archive WHERE time < 1250000000 GROUP BY grp HAVING cnt > 1

        $varset = new CVarset;
        $hits = DB_AA::select(['id'=>'sum'], 'SELECT `id`, SUM(`hits`) as sum FROM `hit_archive`', $time_cond, null, ['id']);
        DB_AA::delete('hit_archive', $time_cond);
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

    /**
     * @param  array  $time_cond
     *
     * @return array
     */
    public function countHitsDuration(array $time_cond): array
    {
        return DB_AA::select([],
            'SELECT `id`,
            AVG(CASE WHEN (`duration` & 3) = 0 THEN `duration` >> 2 END) << 2 AS avg_0,
            AVG(CASE WHEN (`duration` & 3) = 1 THEN `duration` >> 2 END) << 2 AS avg_1,
            AVG(CASE WHEN (`duration` & 3) = 2 THEN `duration` >> 2 END) << 2 AS avg_2,
            AVG(CASE WHEN (`duration` & 3) = 3 THEN `duration` >> 2 END) << 2 AS avg_3,
            SUM(CASE WHEN (`duration` & 3) = 0 THEN `hits` END) AS sum_0,
            SUM(CASE WHEN (`duration` & 3) = 1 THEN `hits` END) AS sum_1,
            SUM(CASE WHEN (`duration` & 3) = 2 THEN `hits` END) AS sum_2,
            SUM(CASE WHEN (`duration` & 3) = 3 THEN `hits` END) AS sum_3
            FROM `hit_archive`',
            $time_cond, null, ['id']);
    }

    public function getTimecondition(int $begin, int $step): array
    {
        return [['time', $begin, '>='],['time', ($begin + $step), '<']];
    }

    /** group code for extended hit log - with duration stats */
    protected function groupHitsDuration(int $begin, int $step) {
        $time_cond = $this->getTimecondition($begin, $step);

        $hits = $this->countHitsDuration($time_cond);

        $to_insert = [];
        foreach ($hits as $hit) {
            if ($hit['sum_0']>0) {
                $to_insert[] = ['id'=>$hit['id'], 'hits'=>$hit['sum_0'], 'time'=>$begin, 'duration'=>$hit['avg_0']];
            }
            if ($hit['sum_1']>0) {
                $to_insert[] = ['id'=>$hit['id'], 'hits'=>$hit['sum_1'], 'time'=>$begin, 'duration'=>$hit['avg_1']+1];
            }
            if ($hit['sum_2']>0) {
                $to_insert[] = ['id'=>$hit['id'], 'hits'=>$hit['sum_2'], 'time'=>$begin, 'duration'=>$hit['avg_2']+2];
            }
            if ($hit['sum_3']>0) {
                $to_insert[] = ['id'=>$hit['id'], 'hits'=>$hit['sum_3'], 'time'=>$begin, 'duration'=>$hit['avg_3']+3];
            }
        }
        // exit;
        DB_AA::delete('hit_archive', $time_cond);
        AA::Metabase()->doInsertMulti('hit_archive', $to_insert);
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

            // by default, we plan all the task
            if (++$no_task == $max_task) {
                break;
            }
        }
    }
}