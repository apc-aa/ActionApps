<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:05
 */

namespace AA\IO\Grabber;

use AA\FormArray;
use AA\Widget\Widget;
use AA_Fields;
use AA_Item;
use AA_Slice;
use ItemContent;
use zids;

/** AA\IO\AbstractGrabber\AbstractGrabber\Form - Grabbs data POSTed by AA form
 *
 *  The format of the data is followiing
 *  (this is new format, which allows to fill or modify more items at once
 *
 *   Format is:
 *       aa[i<long_item_id>][modified_field_id][]
 *   Note:
 *      first brackets contain
 *          'u'+long_item_id when item is edited (the field is rewriten, rest
 *                           of item is untouched)
 *          'i'+long_item_id when item is edited (the value is added to current
 *                           value of the field, rest of item is untouched)
 *          'r'+long_item_id when item is edited (the value is removed from current
 *                           value of the field, rest of item is untouched)
 *          'n<number>_long_slice_id' if you want to add the item to slice_id
 *                                    <number> is used to add more than one
 *                                    item at the time
 *          'f_long_slice_id' new item form file upload to slice
 *      modified_field_id is field_id, where all dots are replaced by '_'
 *      we always add [] at the end, so it becames array at the end
 *   Example:
 *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
 *       aa[i63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
 *       aa[f_54343ea876898b6754e3578a8cc544e6][file][fil][var][0]
 *
 *   There could be also compound widgets, which consists from more than one
 *   input - just like date selector. In such case we use following syntax:
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
 *   where "dte" points to the AA\Widget\DteWidget. The method AA\Widget\DteWidget::getValue()
 *   is called to grab the value (or multivalues) from the submitted form
 */
class Form extends AbstractGrabber
{
    private $_items;
    private $_last_store_mode;

    function __construct() {
        $this->_items = [];
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('Form');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Grabbs data POSTed by AA form');
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getStoreMode() method
     */
    function getIdMode() {
        return 'old';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    function getStoreMode() {
        switch ($this->_last_store_mode) {
            case 'add':
                return 'add';
            case 'update':
                return 'update';
        }
        // case 'new':
        return 'insert';
    }

    private function rearangeAaFilesArray(array $files) {
        $ret = [];
        foreach ($files as $type => $items) {
            foreach ($items as $item => $fields) {
                foreach ($fields as $field => $f) {                                                                     
                    foreach ((array)$f['fil']['var'] as $k => $value) {
                        $arr = [$item => [$field => ['fil' => ['var' => ["_$k" => [$type => $value]]]]]];
                        $ret = array_merge_recursive($ret, $arr);

                    }
                }
            }
        }
        return $ret;
    }

    /** process the   aa[f_54343ea876898b6754e3578a8cc544e6][file][fil][var][] type of upload produced by {manager} and
     *  convert it to aa[n1_54343ea876898b6754e3578a8cc544e6][file............][fil][var][]
     */
    private function fileUploadToNewItem(array &$aa) {
        $i = 0;
        foreach ($aa as $type => $file) {
            [$letter, $sid] = explode('_', $type);
            if ($letter{0} == 'f') {
                unset($aa[$type]);   // remove old form and add new one
                if (is_array($file['file']['fil']['var']) AND ($s = AA_Slice::getModule($sid)) AND ($fid = $s->getDefaultUploadField())) {
                    foreach ($file['file']['fil']['var'] as $f) {
                        $aa['n' . (++$i) . "_$sid"] = [$fid => ['fil' => ['var' => [$f]]]];
                    }
                }
            }
        }
    }


    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!($tostore = current($this->_items))) {
            return false;
        }
        next($this->_items);
        $this->_last_store_mode = $tostore[1];
        return $tostore[0];
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {

        $this->_items = [];
        if (!isset($_POST['aa']) OR !is_array($_POST['aa'])) {
            if (!isset($_FILES['aa']) OR !is_array($_FILES['aa'])) {
                return;
            }
        }

        /** the item ids are in the form of i<item_id> for edited items,
         *  or n<number>_<slice_id> for new item.
         *  We have to construct translation table of the ids
         */
        $id_trans_table = [];

        $aa = $_POST['aa'];
        if (!is_array($aa)) {
            $aa = [];
        }

        // uploaded files are not $_POST variable.
        // Also it is stored as array...[name][file___________1][...] array...[type][file___________1][...]
        // so the information about one file is quite far in the structure
        if (isset($_FILES['aa'])) {
            // collect together the informations about one file - array(name, type, tmp_name, error, size)
            //$files = array_merge_recursive($_FILES['aa']['name'], $_FILES['aa']['type'], $_FILES['aa']['tmp_name'], $_FILES['aa']['error'], $_FILES['aa']['size']);
            $files = $this->rearangeAaFilesArray($_FILES['aa']);

            // now process the aa[f_54343ea876898b6754e3578a8cc544e6][file][] type of upload and convert it
            // to aa[n1_54343ea876898b6754e3578a8cc544e6][file............][]
            $this->fileUploadToNewItem($files);

            // add it to POSTed variables
            $aa = array_merge_recursive($aa, $files);
        }

        // just prepare ids, in order we can expand
        // You can use _#n1_623553373823736362372726 as value, which stands for
        // item id of the item
        foreach ($aa as $dirty_item_id => $item_fields) {
            if ($dirty_item_id{0} == 'n') {
                $id_trans_table['_#' . $dirty_item_id] = new_id();
            }
        }
        $trans_item_alias = array_keys($id_trans_table);
        $trans_item_ids = array_values($id_trans_table);

        $UPDATE_MODES = ['u' => 'update', 'i' => 'add', 'r' => 'update'];
        foreach ($aa as $dirty_item_id => $item_fields) {

            // common fields
            if ($dirty_item_id == 'all') {
                continue;
            }

            // 'u': edited item - update = field content is changed to new value
            // 'r': edited item - remove value from field
            // 'i': edited item - insert = field content is added to the existing content of the field
            if ($store_mode = $UPDATE_MODES[$dirty_item_id{0}]) {
                $item_id = substr($dirty_item_id, 1);
                $item = AA_Item::getItem($item_id);
                $item_fields['slice_id________'] = [pack_id($item->getSliceID())];
            } // new items
            else {
                $item_id = $id_trans_table['_#' . $dirty_item_id];
                $store_mode = 'new';

                //grab slice_id of new item
                $item_slice_id = substr($dirty_item_id, strpos($dirty_item_id, '_') + 1);
                // and add slice_id field to the item
                $item_fields['slice_id________'] = [pack_id($item_slice_id)];
            }
            $id_trans_table[$dirty_item_id] = $item_id;

            // now fill the ItemContent for each item and tepmorary store it into $this->_items[]
            $item = new ItemContent();
            $item->setItemID($item_id);

            // join common fields (the specific fields win in the battle of common and specific content)
            if (isset($aa['all'])) {
                $item_fields = array_merge($aa['all'], $item_fields);
            }

            if ($dirty_item_id{0} == 'r') {
                $oldcontent4id = new ItemContent();
                $oldcontent4id->setByItemID(new zids($item_id), true);     // ignore password
                if (!$oldcontent4id->getSliceID() OR !$oldcontent4id->getItemID()) {
                    continue;
                }
            }

            foreach ($item_fields as $dirty_field_id => $val_array) {
                // create full_text......1 from full_text______1
                $field_id = AA_Fields::getFieldIdFromVar($dirty_field_id);

                // get the content of the field (values and flags)
                $aa_value = Widget::getValue($val_array);
                $aa_value->replaceInValues($trans_item_alias, $trans_item_ids);

                if ( ($dirty_item_id{0} == 'r') AND ($field_id != 'slice_id........') ) {
                    $aa_value = $oldcontent4id->getAaValue($field_id)->removeValues($aa_value->getValues());
                }
                $item->setAaValue($field_id, $aa_value);
            }
            $this->_items[] = [$item, $store_mode];
        }
        reset($this->_items);
    }

    /** Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    function finish() {
        $this->_items = [];
    }


    public static function grab() {
    }
}