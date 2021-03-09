<?php

namespace AA\Later;

use AA;
use ItemContent;

class ComputedfieldsItemUpdater implements LaterInterface
{

    /**  just plan item updates in chunks
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {
        [$sid, $ids] = $params;
        foreach ($ids as $item_id) {
            $item = new ItemContent($item_id);
            $item->updateComputedFields();
        }
        AA::Pagecache()->invalidateFor($sid);
    }
}