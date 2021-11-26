<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;

/** Whole pagecache will be invalidated and deleted */
class ClearPagecache extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Clear Pagecache");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Whole pagecache will be invalidated and deleted");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Test function
     * @return bool
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
        $db->query('CREATE TABLE IF NOT EXISTS pagecache_new LIKE pagecache');
        $this->message(_m('Table pagecache_new created'));
        $db->query('CREATE TABLE IF NOT EXISTS pagecache_str2find_new LIKE pagecache_str2find');
        $this->message(_m('Table pagecache_str2find_new created'));
        $db->query('RENAME TABLE pagecache_str2find TO pagecache_str2find_bak, pagecache TO pagecache_bak');
        $this->message(_m('Renamed tables pagecache_* to pagecache_*_bak'));
        $db->query('RENAME TABLE pagecache_str2find_new TO pagecache_str2find, pagecache_new TO pagecache');
        $this->message(_m('Renamed tables pagecache_*_new to pagecache_*'));
        $db->query('DROP TABLE pagecache_str2find_bak, pagecache_bak');
        $this->message(_m('Old pagecache_*_bak tables dropped'));
        freeDb($db);
        return true;
    }
}