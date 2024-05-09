<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;
use function freeDB;
use function getDB;

/** Whole pagecache will be invalidated and deleted */
class CopyItemsFromBackup extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Undelete items from backup tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Copy data from content2 and item2 tables for slice XY which was deleted in item and content table. Used for recovery content table on Ecn server. Not useful for any other users, I think.");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['test', 'repair']; }

    /** @inheritDoc */
    public static function types(): array { return ['private_fix']; }

    protected function getDeletedItemIds(string $module_id, string $item_table_bck) {
        if (!DB_AA::exists_table($item_table_bck)) {
            return [];
        }
        $bck_ids = DB_AA::select('unpackid', "SELECT LOWER(HEX(`id`)) AS unpackid FROM `$item_table_bck`", [['slice_id',$module_id,'l']]);
        $cur_ids = DB_AA::select('unpackid', "SELECT LOWER(HEX(`id`)) AS unpackid FROM `item`", [['slice_id',$module_id,'l']]);

        $diff_ids = array_diff($bck_ids, $cur_ids);
        huhl(count($bck_ids), reset($bck_ids), next($bck_ids), next($bck_ids), count($cur_ids), $cur_ids[0], $cur_ids[1], $cur_ids[2], count($diff_ids), $diff_ids[0], $diff_ids[1], $diff_ids[2]);

        return $diff_ids;
    }

    protected function copyFomBackup(array $ids, string $item_table_bck, string $content_table_bck, bool $delete=true): bool {
        if (!$ids OR !DB_AA::exists_table($item_table_bck) OR !DB_AA::exists_table($content_table_bck)) {
            return false;
        }

        if ($delete) {
            $deleted_item    = DB_AA::delete('item',[['id',$ids,'l']]);
            $deleted_content = DB_AA::delete('content',[['item_id',$ids,'l']]);
        }

        $ins_item    = DB_AA::sql("INSERT INTO `item` SELECT `$item_table_bck`.* FROM `$item_table_bck`", [['id',$ids,'l']]);
        $ins_content = DB_AA::sql("INSERT INTO `content` SELECT `$content_table_bck`.* FROM `$content_table_bck`", [['item_id',$ids,'l']]);

        $this->message(_m('<br>Deleted items: %1, <br>Deleted content: %2, <br>Inserted items: %3, <br>Inserted content: %4', [$deleted_item, $deleted_content, $ins_item, $ins_content]));
        return true;
    }


    /** Test function - @return true if everything is OK - no action needed
     */
    public function test(): bool {
        $diff_ids = $this->getDeletedItemIds('6cf969ebbf5d8794f1304e55744cf0d7', 'item20240109');
        $this->message(_m('Deleted items to be recreated: %1.', [count($diff_ids)]));
        return true;
    }

    /**
     * @return bool
     */
    public function repair(): bool {
        $diff_ids = $this->getDeletedItemIds(AA::$module_id, 'item20240109');
        $this->copyFomBackup($diff_ids, 'item20240109', 'content20240109');
        return true;
    }
}