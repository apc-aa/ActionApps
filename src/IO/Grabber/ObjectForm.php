<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:05
 */

namespace AA\IO\Grabber;

use AA\Widget\Widget;
use AA_Content;

/** AA\IO\AbstractGrabber\AbstractGrabber\ObjectForm - Grabbs data POSTed by AA form
 *
 *  The format of the data is followiing
 *  (this is new format, which allows to fill or modify more objects at once
 *
 *   Format is:
 *       aa[i<long_object_id>_<object_type>][modified_field_id][]
 *   Note:
 *      first brackets contain
 *          'u'+long_object_id when object is edited (the field is rewriten, rest
 *                             of object is untouched)
 *          'i'+long_object_id when object is edited (the value is added to current
 *                           value of the field, rest of object is untouched)
 *          'r'+long_item_id when item is edited (the value is removed from current
 *                           value of the field, rest of item is untouched)
 *          'n<number>_long_owner_id' if you want to add the object for an owner
 *                                    (slice_id) <number> is used to add more
 *                                    than one object at the time
 *      object_type - type of object like - AA_Scroller
 *                  - if not specified, AA_Item is used
 *      modified_field_id is field_id, where all dots are replaced by '_'
 *      we always add [] at the end, so it becames array at the end
 *   Example:
 *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
 *       aa[i63556a45e4e67b654a3a986a548e8bc9_AA_Conds][relation_______1][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6_AA_Conds][publish_date____][]
 *
 *   There could be also compound widgets, which consists from more than one
 *   input - just like date selector. In such case we use following syntax:
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][d][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][m][]
 *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][dte][y][]
 *   where "dte" points to the AA\Widget\DteWidget. The method AA\Widget\DteWidget::getValue()
 *   is called to grab the value (or multivalues) from the submitted form
 */
class ObjectForm extends AbstractGrabber
{
    private $_content = [];
    private $_last_store_mode;

    function __construct() {
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
     *  how to store the object.
     * @see also getStoreMode() method
     */
    function getIdMode() {
        return 'old';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the object.
     * @see also getIdMode() method
     */
    function getStoreMode() {
        switch ($this->_last_store_mode) {
            case 'add':
                return 'add';
            case 'update':
                return 'update';
        }
        return 'insert';
    }

    /** Method called by the AA\IO\Saver to get next object from the data input */
    function getContent() {
        if (!($tostore = current($this->_content))) {
            return false;
        }
        next($this->_content);
        $this->_last_store_mode = $tostore[1];
        return $tostore[0];
    }

    /** Possibly preparation of grabber - it is called directly before getObject()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_content = [];
        if (!isset($_POST['aa']) OR !is_array($_POST['aa'])) {
            return;
        }

        /** the object ids are in the form of i<object_id> for edited objects,
         *  or n<number>_<slice_id> for new object.
         *  We have to construct translation table of the ids
         */
        $id_trans_table = [];

        $aa = $_POST['aa'];

        // just prepare ids, in order we can expand
        // You can use _#n1_623553373823736362372726 as value, which stands for
        // object id of the object
        foreach ($aa as $dirty_obj_id => $obj_fields) {
            if ($dirty_obj_id{0} == 'n') {
                $id_trans_table['_#' . $dirty_obj_id] = new_id();
            }
        }
        $trans_obj_alias = array_keys($id_trans_table);
        $trans_obj_ids = array_values($id_trans_table);

        foreach ($aa as $dirty_obj_id => $obj_fields) {

            // common fields
            if ($dirty_obj_id == 'all') {
                continue;
            }

            $ident = ObjectForm::_parseDirtyId($dirty_obj_id, $id_trans_table);

            $id_trans_table[$dirty_obj_id] = $ident['id'];

            // now fill the AA_Content for each obj and tepmorary store it into $this->_content[]
            $content = new AA_Content();
            $content->setId($ident['id']);

            // join common fields (the specific fields win in the battle of common and specific content)
            if (isset($aa['all'])) {
                $obj_fields = array_merge($aa['all'], $obj_fields);
            }
            foreach ($obj_fields as $field_id => $val_array) {
                // get the content of the field (values and flags)
                $aa_value = Widget::getValue($val_array);
                $aa_value->replaceInValues($trans_obj_alias, $trans_obj_ids);
                $content->setAaValue($field_id, $aa_value);
            }
            $this->_content[] = [$content, $ident['store_mode']];
        }

        reset($this->_content);
    }

    /** Function called by AA\IO\Saver after we get the last object from the data
     *  input
     */
    function finish() {
        $this->_content = [];
    }


    /** parses i<long_object_id>_<object_type> id form $aa array to array:
     *    store_mode  - add | update | new
     *    id
     *    class
     *    owner
     */
    private static function _parseDirtyId($dirty_obj_id, $id_trans_table) {
        $ret = [];
        switch ($dirty_obj_id{0}) {
            case 'u':
                $ret['store_mode'] = 'update';
            // no break!
            case 'i':
                $ret['store_mode'] = 'add';
                $ret['id'] = substr($dirty_obj_id, 1, 32);
//            $ret['class']      = substr($dirty_obj_id, 34);
                break;
            case 'n':
                $ret['store_mode'] = 'new';
                $ret['id'] = $id_trans_table['_#' . $dirty_obj_id];
                $owner_start_char = strpos($dirty_obj_id, '_') + 1;
                $ret['owner'] = substr($dirty_obj_id, $owner_start_char, 32);
//            $ret['class']      = substr($dirty_obj_id, $owner_start_char+33);
                break;
        }
        // deafult class is AA_Item
//        if (!$ret['class']) {
//            $ret['class'] = 'AA_Item';
//        }
        return $ret;
    }
}