<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\IO\DB\DB_AA;
use AA_Value;
use ItemContent;

/**
 * Writes an open or a click of a tracked address as an item of the statistics slice of the list.
 * The item id is computed from what the event says, so only the first open and the first click
 * on each link of a recipient count. No IP address and nothing of the request is kept.
 */
class Tracker
{
    /** @return bool this call stored the event of the token; false when the list measures nothing,
     *               the event is there already or another request is storing it right now */
    public function record(TrackToken $token): bool
    {
        $slice = $this->statSliceId($token->list);
        if ($slice === '') {
            return false;
        }
        // a click with images blocked in the mail still means the mail was opened
        if ($token->kind === TrackToken::CLICK) {
            $this->event($slice, $token, TrackToken::OPEN, '');
        }
        return $this->event($slice, $token, $token->kind, $token->url);
    }

    /** @return string long id of the event item: one per list, mailing, reader, kind and link */
    public static function eventId(string $list, string $itemId, string $readerId, string $kind, string $url): string
    {
        // no part may hold a newline (TrackToken), so the joined parts cannot collide
        return md5(implode("\n", [$list, $itemId, $readerId, $kind, $url]));
    }

    private function event(string $slice, TrackToken $token, string $kind, string $url): bool
    {
        $id = self::eventId($token->list, $token->itemId, $token->readerId, $kind, $url);
        // two hits at once (a mail checker and the reader, a double click) would both pass the duplicate
        // check of storeItem() and the second insert would end the request on a duplicate key
        if (!$this->lock($id)) {
            return false;
        }
        try {
            $fields = [
                'relation........' => $token->itemId,
                'relation.......1' => $token->readerId,
                'category........' => ($kind === TrackToken::CLICK) ? 'click' : 'open',
                'publish_date....' => time(),
            ] + (($url === '') ? [] : ['source_href.....' => $url]);

            $content = new ItemContent();
            $content->setSliceID($slice);
            $content->setItemID($id);
            foreach ($fields as $field => $value) {
                $content->setAaValue($field, new AA_Value($value));
            }
            // storeItem() leaves the error of an earlier call
            ItemContent::lastErr(0, '');
            // a hit must stay cheap: no feeding, no events, the cache of the pages keeps
            return $this->store($content, 'insert_if_new', ItemContent::FLAG_JUST_STORE);
        } finally {
            $this->unlock($id);
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** @return string long id of the statistics slice of the list, '' when it measures nothing or is gone */
    protected function statSliceId(string $list): string
    {
        // not AA_Collection: that loads the whole sending code of Alerts at every hit
        $packed = (string) DB_AA::select1('stat_slice_id', 'SELECT stat_slice_id FROM alerts_collection', [['id', $list]]);
        return ($packed === '') ? '' : unpack_id($packed);
    }

    protected function lock(string $eventId): bool
    {
        return DB_AA::lock("mailhit:$eventId");
    }

    protected function unlock(string $eventId): void
    {
        DB_AA::unlock("mailhit:$eventId");
    }

    /** @param list<bool> $flags see ItemContent::storeItem() */
    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        return $content->storeItem($mode, $flags) !== false;
    }
}
