<?php

namespace AA\Later;

use AA_Set;

class ComputedfieldsUpdater implements LaterInterface
{

    /** plan filed updater process for future time based on field settings
     */
    function plan()
    {

        $fields2count = GetTable2Array("SELECT id, slice_id, input_insert_func FROM field WHERE slice_id <> 'AA_Core_Fields..' AND input_insert_func LIKE 'co2:%'", '');

        $intervals = ['minute' => 5, 'hour' => 4, 'day' => 3, 'week' => 2, 'month' => 1];
        $updaters = [];

        if (is_array($fields2count)) {
            foreach ($fields2count as $to_count) {
                [$fnc, $expand_insert, $expand_update, $expand_delimiter, $recompute] = ParamExplode($to_count["input_insert_func"]);
                if (isset($intervals[$recompute])) {
                    $sid = unpack_id($to_count['slice_id']);
                    // find the shortest interval for the slice
                    $updaters[$sid] = $updaters[$sid] ? max($updaters[$sid], $intervals[$recompute]) : $intervals[$recompute];
                }
            }

            if (count($updaters)) {
                $toexecute = new Toexecute;
                foreach ($updaters as $sid => $interval) {
                    $time = null;
                    switch ($interval) {
                        case 1:
                            $time = mktime(mt_rand(0, 5), mt_rand(0, 59), mt_rand(0, 59), date("m") + 1, 1, date("Y"));
                            break;
                        case 2:
                            $time = strtotime("next Monday") + mt_rand(0, (5 * 60 * 60));
                            break;// Monday - 0-5 in the morning
                        case 3:
                            $time = mktime(mt_rand(0, 4), mt_rand(0, 59), mt_rand(0, 59), date("m"), date("d") + 1, date("Y"));
                            break;
                        case 4:
                            $time = mktime(date("G") + 1, mt_rand(0, 4), mt_rand(0, 59), date("m"), date("d"), date("Y"));
                            break;
                        case 5:
                            $time = mktime(date("G"), date("i") + 1, mt_rand(0, 10), date("m"), date("d"), date("Y"));
                            break;
                    }
                    $toexecute->laterOnce($this, [$sid], "ComputedfieldsUpdater_$sid", 100, $time); // once a day
                }
            }
        }
    }

    /**  just plan item updates in chunks
     *  special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {
        [$sid] = $params;
        $aa_set = new AA_Set();
        $aa_set->setModules($sid);
        $zids = $aa_set->query();

        $long_ids = $zids->longids();

        // randomize the order
        shuffle($long_ids);

        // create chunks - we will work with 20 items in one shot
        // we can later estimate better the count of items based on speed of the operation
        $chunks = array_chunk($long_ids, 20);

        $item_updater = new ComputedfieldsItemUpdater();
        $toexecute = new Toexecute;
        foreach ($chunks as $k => $ids) {
            $toexecute->laterOnce($item_updater, [$sid, $ids], "AA_ComputedfieldsItem_Updater_" . $sid . '_' . $k, 40, time() + 10);
        }
    }
}