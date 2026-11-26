<?php

namespace AA\Later;

use AA;
use ItemContent;

class ComputedfieldsItemUpdater implements LaterInterface
{
    /**  recomputes the computed fields of one chunk of items
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - number of items with a changed value
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        [$sid, $ids] = $params;
        $count = 0;
        foreach ($ids as $item_id) {
            $content = new ItemContent();
            $content->setByItemID($item_id, true);   // the queue has no reading password - it would compute from error texts
            $count += $content->updateComputedFields();
        }
        if ($count) {
            AA::Pagecache()->invalidateFor($sid);
        }
        return (string) $count;
    }
}
