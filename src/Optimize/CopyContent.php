<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;

/** Whole pagecache will be invalidated and deleted */
class CopyContent extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Copy Content Table");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Copy data for all items newer than short_id=1941629 from content table to content2 table. Used for recovery content table on Ecn server. Not useful for any other users, I think.");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();

        $SQL = "INSERT INTO content2 SELECT content.* FROM content
                LEFT JOIN item on content.item_id=item.id
                WHERE item.short_id>1941629";

        /** Situation was:
         *     Content table was corrupted, so we replace i from backup. The last item in backup was short_id=1941629;
         *     After one day we found, that we restore the table from backup by wrong way, so it is corrupted for UTF slices
         *     So we decided to import old backup of content table to content2 table, and copy theer new items from content table
         *
         *
         *  First of all we insert new content, which is missing in content2 table
         *  INSERT INTO content2 SELECT content.* FROM content LEFT JOIN item on content.item_id=item.id WHERE item.short_id>1941629;
         *
         *  Then we switch from backup conten2 to content
         *  RENAME TABLE content TO contentblb, content2 TO content;
         *
         *  And now we update all content of the item, which was updated after the first switch (one day before)
         *  DELETE FROM content USING content, item WHERE content.item_id=item.id AND item.last_edit>1165360279 AND item.last_edit<1165500000 AND item.last_edit<>item.post_date AND item.short_id<1941629;
         *  INSERT INTO content SELECT contentblb.* FROM contentblb LEFT JOIN item on contentblb.item_id=item.id WHERE item.last_edit>1165360279 AND item.last_edit<1165500000 AND item.last_edit<>item.post_date AND item.short_id<1941629;
         *
         *
         *  $db->query($SQL);
         */
        $this->message(_m('Coppied'));

        freeDb($db);
        return true;
    }
}