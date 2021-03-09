<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:06
 */

namespace AA\IO\Grabber;

use AA_Slice;
use ItemContent;
use zids;

/** AA\IO\AbstractGrabber\AbstractGrabber\Slice - grabs data from slice based on AA_Set
 *  Right now we use it mainly for apc-aa/admin/se_export.php
 */
class Slice extends AbstractGrabber
{

    protected $set;
    /** AA_Set specifies the slice, conds and sort */
    protected $restrict_zids;
    /** possible subset of ids grabbed */
    protected $restrict_fields;
    /** possible subset of fields - used in {updateitem:...} for field value update */
    private $_longids;
    /** list if files to grab - internal array */
    private $_content_cache;
    /**  */
    private $_index;

    /**  */

    function __construct($set, $restrict_zids = null, $restrict_fields = null) {
        $this->set = $set;
        $this->restrict_zids = $restrict_zids;
        $this->restrict_fields = is_array($restrict_fields) ? $restrict_fields : false;
        $this->_longids = [];
        $this->_content_cache = [];
        $this->_index = 0;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name() : string {
        return _m('Items from slice');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('grabs items from some slice');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $zids = $this->set->query($this->restrict_zids);
        $this->_longids = $zids->longids();
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
        $this->_content_cache = GetItemContent(new zids(array_slice($this->_longids, $this->_index, 100), 'l'), '', false, $this->restrict_fields);
    }

    function getCharset() {
        $modules = $this->set->getModules();
        if ($module = AA_Slice::getModule($modules[0])) {
            return $module->getCharset();
        }
        return 'UTF-8';
    }

    function finish() {
        $this->_longids = [];
    }
}