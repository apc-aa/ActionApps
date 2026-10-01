<?php

namespace AA\Optimize;

/** Testing if feeds table do not contain relations to non-existent slices
 */
class DbInconsistency extends AbstractOptimize
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_SLOW];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Check database consistency");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Test content table for records without item table reference, test discussion for the same, ...");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool
    {
        $ret = true;


        // test wrong destination slices
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) && count($err)) {
            foreach ($err as $s_id) {
                $this->message(_m('Wrong slice id in item table: %1', [$s_id]));
            }
            $ret = false;
        }

        // test wrong destination slices
        $SQL = "SELECT item_id, text FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'text');
        if (is_array($err) && count($err)) {
            foreach ($err as $item_id => $text) {
                $this->message(_m('Wrong item id in content table: %1 -> %2', [$item_id, safe($text)]));   // content.text is editor input, the report is HTML
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT item_id, subject FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'subject');
        if (is_array($err) && count($err)) {
            foreach ($err as $item_id => $text) {
                $this->message(_m('Wrong item id in discussion table: %1 -> %2', [$item_id, $text]));
            }
            $ret = false;
        }
        if ($ret) {
            $this->message(_m('No wrong references found, hurray!'));
        }
        return $ret;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool
    {
        $db = getDb();

        // test wrong content records
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) && count($err)) {
            foreach ($err as $s_id) {
                $SQL = 'DELETE FROM `item` WHERE `slice_id`=' . xpack_id($s_id);
                $db->query($SQL);
                $this->message(_m('Data for slice id %1 in item table deleted', [$s_id]));
            }
        }

        // test wrong content records
        $SQL = "SELECT item_id FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "", 'unpack:item_id');
        if (is_array($err) && count($err)) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `content` WHERE `item_id`=' . xpack_id($item_id);
                $db->query($SQL);
                $this->message(_m('Data for item id %1 in content table deleted', [$item_id]));
            }
        }

        // test wrong source slices
        $SQL = "SELECT item_id FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:item_id");
        if (is_array($err) && count($err)) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `discussion` WHERE `item_id`=' . xpack_id($item_id);
                $db->query($SQL);
                $this->message(_m('Data for item id %1 in discussion table deleted', [$item_id]));
            }
        }

        freeDb($db);
        return true;
    }
}
