<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:06
 */

namespace AA\IO\Grabber;

use ItemContent;
use zids;

/** AA\IO\AbstractGrabber\AbstractGrabber\Discussion - grabs data from slices discussion based on AA_Set
 *  Right now we use it mainly for apc-aa/admin/se_export.php
 */
class Discussion extends AbstractGrabber
{

    protected $set;
    /** AA_Set specifies the slice, conds and sort */
    private $_longids;
    /** list if files to grab - internal array */
    private $_content_cache;
    /**  */
    private $_index;

    /**  */

    function __construct($set) {
        $this->set = $set;
        $this->_longids = [];
        $this->_content_cache = [];
        $this->_index = 0;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('Discussion from slice');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('grabs discussion comments for items from some slice');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        // get all discussion comments ids
        $item_zids = $this->set->query();
        $item_long_ids = $item_zids->longids();
        $this->_longids = [];
        foreach ($item_long_ids as $item_id) {
            $zids = QueryDiscussionZIDs($item_id);
            $this->_longids = array_merge($this->_longids, $zids->longids());
        }
        $this->_content_cache = [];
        $this->_index = 0;
        reset($this->_longids);   // go to first long id
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!($longid = $this->_longids[$this->_index])) {
            return false;
        }
        if (empty($this->_content_cache[$longid])) {
            $this->_fillCache();
        }

        $this->_index++;
        return new ItemContent($this->_content_cache[$longid]);
    }

    /** speedup */
    function _fillCache() {
        // read next 100 items (we can laborate with cache size in future to get even better performance)
        $this->_content_cache = GetDiscussionContent(new zids(array_slice($this->_longids, $this->_index, 100), 'l'));
    }

    function finish() {
        $this->_longids = [];
    }
}