<?php

namespace AA\Later;

use AA\IO\DB\DB_AA;

/** Updates the hit_x..... fields for all items from the slice based on the data
 *  in hit_archive table.
 */
class HitcounterStats implements LaterInterface
{

    /** For which slice we will cout the hits */
    var $slice_id;

    /** For which field we will cont the hits (hit_1...., hit_2...) */
    var $field_id;

    function __construct($slice_id, $field_id)
    {
        $this->slice_id = $slice_id;
        $this->field_id = $field_id;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {
        $dbgtime = [microtime(true)];

        $days = $this->getDays();
        $time = time() - ($days * 86400);
        $qp_slice_id = q_pack_id($this->slice_id);
        $hits = GetTable2Array("SELECT item.id, sum(hits) as count FROM hit_archive INNER JOIN item ON hit_archive.id=item.short_id
                                        WHERE hit_archive.time > $time AND item.slice_id = '$qp_slice_id' GROUP BY hit_archive.id", 'id', 'count');
        $dbgtime[] = microtime(true);
        $item_ids = DB_AA::select('', 'SELECT id FROM item', [['slice_id', $this->slice_id, 'l']]);
        $dbgtime[] = microtime(true);

        if (is_array($item_ids)) {
            // shuffle - if there are a lot of items, so we reach timelimit, it is better to
            // count hits in random order, so each item will be counted after some time period
            shuffle($item_ids);
            $field = DB_AA::select1('', 'SELECT `id`,`text_stored` FROM `field`', [
                ['slice_id', $this->slice_id, 'l'],
                ['id', $this->field_id]
            ]);

            foreach ($item_ids as $id) {
                //$db->query("DELETE FROM content WHERE item_id ='".quote($id)."' AND field_id = '". quote($this->field_id)."'");
                $value = $hits[$id] ?: 0;
                StoreToContent(unpack_id($id), $field['id'], $field["text_stored"], ['value' => $value, 'flag' => 0], [], true);
            }
        }
        $dbgtime[] = microtime(true);
        // for AA_Log
        return count($item_ids) . '-' . number_format($dbgtime[1] - $dbgtime[0], 2) . '-' . number_format($dbgtime[2] - $dbgtime[1], 2) . '-' . number_format($dbgtime[3] - $dbgtime[2], 2);
    }

    function getDays()
    {
        return (int)substr($this->field_id, 4);
    }

    /** used as identifier for AA_Log when run from toexecute */
    function getId()
    {
        return $this->slice_id . "/" . $this->field_id;
    }
}