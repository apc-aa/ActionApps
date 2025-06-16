<?php

namespace AA\Later;

use AA;
use ItemContent;

class ComputedfieldsItemUpdater implements LaterInterface
{

    /**  just plan item updates in chunks
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - number of items computed
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        [$sid, $ids] = $params;
        $count = 0;
        foreach ($ids as $item_id) {
            $count += (new ItemContent($item_id))->updateComputedFields();
        }
        AA::Pagecache()->invalidateFor($sid);
        return $count;
    }
}