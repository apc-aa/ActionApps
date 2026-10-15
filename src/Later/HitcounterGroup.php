<?php

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA\Optimize\AddDurationLog;

/** Groups data in hit_archive table
 *  We store all the hits into the hit_archive table. It is good for counting
 *  hit_1..., ... field statistics as well as for item display statistics.
 *  On the other hand the amount of data is huge, so we group the hit count for
 *  item on day basis (for older hits)
 */
class HitcounterGroup implements LaterInterface
{
    /** Days before today whose hits are still stored one by one; older days are grouped. */
    public const EXACT_DAYS = 7;

    /** @var int|null caches if we use duration logging for speedup */
    private static ?int $duration_log_feature_from = null;

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        if (is_null(self::$duration_log_feature_from)) {
            $duration_helper = new AddDurationLog();
            if ($duration_helper->test()) {
                self::$duration_log_feature_from = $duration_helper->getFromTime();
                // refresh the cached structure from the DB so a just-added 'duration' column is
                // picked up (it is part of the standard metabase now, but may post-date this process)
                AA::Metabase()->loadFromDb(true, ['hit_archive']);
            } else {
                // duration isn't used
                self::$duration_log_feature_from = 0;
            }
        }

        [$begin, $step] = $params;

        $type = 'basic';
        if (self::$duration_log_feature_from && self::$duration_log_feature_from <= ($begin + $step)) {
            $this->groupHitsDuration($begin, $step);
            $type = 'duration';
        } else {
            $this->groupHitsBasic($begin, $step);
        }
        return "$type;$begin;$step";
    }

    /** group code for basic log without duration stats - older code */
    protected function groupHitsBasic(int $begin, int $step): void
    {
        $time_cond = $this->getTimecondition($begin, $step);

        // possible new approach
        // SELECT id, CONCAT(FROM_UNIXTIME(`time`, '%Y-%m-%d'),' ',`id`) as grp, sum(hits) as sum, count(*) as cnt FROM hit_archive WHERE time < 1250000000 GROUP BY grp HAVING cnt > 1

        $hits = DB_AA::select(['id' => 'sum'], 'SELECT `id`, SUM(`hits`) as sum FROM `hit_archive`', $time_cond, null, ['id']);
        DB_AA::delete('hit_archive', $time_cond);
        foreach ($hits as $id => $sum) {
            // row by row: doInsertMulti() names every schema column, and this table may lack `duration`
            AA::Metabase()->doInsert('hit_archive', ['id' => $id, 'hits' => $sum, 'time' => $begin]);
        }
    }

    /**
     * @param  array  $time_cond
     *
     * @return array
     */
    public function countHitsDuration(array $time_cond): array
    {
        return DB_AA::select(
            [],
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
            $time_cond,
            null,
            ['id'],
        );
    }

    public function getTimecondition(int $begin, int $step): array
    {
        return [['time', $begin, '>='],['time', ($begin + $step), '<']];
    }

    /** group code for extended hit log - with duration stats */
    protected function groupHitsDuration(int $begin, int $step): void
    {
        $time_cond = $this->getTimecondition($begin, $step);

        $hits = $this->countHitsDuration($time_cond);

        $to_insert = [];
        foreach ($hits as $hit) {
            if ($hit['sum_0'] > 0) {
                $to_insert[] = ['id' => $hit['id'], 'hits' => $hit['sum_0'], 'time' => $begin, 'duration' => $hit['avg_0']];
            }
            if ($hit['sum_1'] > 0) {
                $to_insert[] = ['id' => $hit['id'], 'hits' => $hit['sum_1'], 'time' => $begin, 'duration' => $hit['avg_1'] + 1];
            }
            if ($hit['sum_2'] > 0) {
                $to_insert[] = ['id' => $hit['id'], 'hits' => $hit['sum_2'], 'time' => $begin, 'duration' => $hit['avg_2'] + 2];
            }
            if ($hit['sum_3'] > 0) {
                $to_insert[] = ['id' => $hit['id'], 'hits' => $hit['sum_3'], 'time' => $begin, 'duration' => $hit['avg_3'] + 3];
            }
        }
        // exit;
        DB_AA::delete('hit_archive', $time_cond);
        AA::Metabase()->doInsertMulti('hit_archive', $to_insert);
    }

    /**
     * From this moment on, every hit_archive row still carries the time of its own
     * hit. Rows before it may already be grouped into one row per item, day and
     * cache state, stamped with the day's midnight, so they cannot be split
     * within a day. plan() groups nothing newer, and a plan from an earlier day
     * reached even less far, so this holds whenever the queue last ran.
     */
    public static function exactSince(int $now): int
    {
        return (int) mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now) - self::EXACT_DAYS, (int) date('Y', $now));
    }

    /** plan grouping tasks to toexecute queue
     *  - by default we plan all the task
     */
    public function plan(int $max_task = 0): void
    {
        $oldest = GetTable2Array("SELECT min( time ) as oldest FROM `hit_archive`", 'aa_first', 'oldest');
        // the day just before exactSince(); mktime(), not "- 86400", which misses midnight on a DST switch
        $grouping_start = mktime(0, 0, 0, date("m"), date("d") - self::EXACT_DAYS - 1, date("Y"));
        $step = 60 * 60 * 24;                        // day

        $toexecute = new Toexecute();
        // run for all the days from $oldest to week ago
        $no_task = 0;
        for ($begin = $grouping_start; $begin + $step >= $oldest; $begin -= $step) {
            $toexecute->laterOnce($this, [$begin, $step], "HitcounterGroup_$begin", 40, time() + 60 * 60 * 24); // once a day

            // by default, we plan all the task
            if (++$no_task === $max_task) {
                break;
            }
        }
    }
}
