<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;
use function quote;

/** Set flag FLAG_TEXT_STORED for all content, where field is marked as text
 *  field, and reset it for numer fields
 **/
class FixContentColumn extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Set right content column for field");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Set flag FLAG_TEXT_STORED for all content, where field is marked as text field, and reset it for numer fields");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['test', 'repair']; }

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id, slice.id, slice.name FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0", '');
        if (empty($bad_rows)) {
            $this->message(_m('No problem found, hurray'));
            return true;
        }
        $statistic = [];
        foreach ($bad_rows as $index => $row) {
            $statistic[$row['name'] . ' ' . $row['field_id']]++;

            if ($index < 240) {
                $this->message(_m('slice %1: field_id: %2 item_id: %3', [
                    unpack_id($row['name'], $row['item_id']),
                    $row['field_id'],
                ]));
            }
            if ($index == 240) {
                $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We found %1 inconsistent rows in content table', [count($bad_rows)]));
        foreach ($statistic as $field => $count) {
            $this->message("$field: $count problems");
        }
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return false;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0", '');
        foreach ($bad_rows as $index => $row) {
            $SQL = "UPDATE content SET flag = flag | 64 WHERE item_id = '" . quote($row['item_id']) . "' AND field_id = '" . quote($row['field_id']) . "'";
            $db->query($SQL);
            $this->message(_m('fixed (id-field): %1 - %2 (%3)', [unpack_id($row['item_id']), $row['field_id'], $SQL]));
            if ($index > 240) {
                $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We fixed %1 inconsistent rows in content table', [count($bad_rows)]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        freeDb($db);
        return true;
    }
}