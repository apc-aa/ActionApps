<?php

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA_Fields;
use AA_Set;
use AA_Slice;

class ComputedfieldsUpdater implements LaterInterface
{
    /** plan filed updater process for future time based on field settings
     *  Slice setting fields (id begins with _) are recomputed by their own task - recomputing the items
     *  would not touch them.
     */
    public function plan(): void
    {

        $fields2count = DB_AA::select([], "SELECT id, slice_id, input_insert_func FROM field WHERE slice_id <> 'AA_Core_Fields..' AND input_insert_func LIKE 'co2:%'");

        $intervals = ['minute' => 5, 'hour' => 4, 'day' => 3, 'week' => 2, 'month' => 1];
        $updaters = ['items' => [], 'settings' => []];

        if ($fields2count) {
            foreach ($fields2count as $to_count) {
                [$fnc, $expand_insert, $expand_update, $expand_delimiter, $recompute] = ParamExplode($to_count["input_insert_func"]);
                if (isset($intervals[$recompute])) {
                    $sid  = unpack_id($to_count['slice_id']);
                    $what = AA_Fields::isSliceField($to_count['id']) ? 'settings' : 'items';
                    // find the shortest interval for the slice
                    $updaters[$what][$sid] = max($updaters[$what][$sid] ?? 0, $intervals[$recompute]);
                }
            }

            $toexecute = new Toexecute();
            foreach ($updaters['items'] as $sid => $interval) {
                $toexecute->laterOnce($this, [$sid], "ComputedfieldsUpdater_$sid", 100, self::nextRun($interval));
            }
            foreach ($updaters['settings'] as $sid => $interval) {
                $toexecute->laterOnce($this, [$sid, true], "ComputedfieldsUpdater_settings_$sid", 100, self::nextRun($interval));
            }
        }
    }

    /** the time of the next recompute for the interval number from plan() */
    private static function nextRun(int $interval): ?int
    {
        switch ($interval) {
            case 1:
                return mktime(mt_rand(0, 5), mt_rand(0, 59), mt_rand(0, 59), date("m") + 1, 1, date("Y"));
            case 2:
                return strtotime("next Monday") + mt_rand(0, (5 * 60 * 60));   // Monday - 0-5 in the morning
            case 3:
                return mktime(mt_rand(0, 4), mt_rand(0, 59), mt_rand(0, 59), date("m"), date("d") + 1, date("Y"));
            case 4:
                return mktime(date("G") + 1, mt_rand(0, 4), mt_rand(0, 59), date("m"), date("d"), date("Y"));
            case 5:
                return mktime(date("G"), date("i") + 1, mt_rand(0, 10), date("m"), date("d"), date("Y"));
        }
        return null;
    }

    /**  just plan item updates in chunks (or recompute the slice setting fields right away)
     *  special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        [$sid, $settings] = $params + [1 => false];
        if ($settings) {
            $slice = AA_Slice::getOwnModule($sid);   // field rows may outlive their slice
            if (!$slice?->isValid() || !$slice->updateComputedSettingFields()) {
                return '0';
            }
            AA::Pagecache()->invalidateFor($sid);
            return '1';
        }

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
        $toexecute = new Toexecute();
        foreach ($chunks as $k => $ids) {
            $toexecute->laterOnce($item_updater, [$sid, $ids], "AA_ComputedfieldsItem_Updater_" . $sid . '_' . $k, 40, time() + 10);
        }
        return _m("Planned %1 chunks with %2 items", [count($chunks), count($long_ids)]);
    }
}
