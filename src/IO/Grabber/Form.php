<?php

/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:05
 */

namespace AA\IO\Grabber;

use AA\FormArray;
use AA\Optimize\CheckAnonymousEditRefusals;
use AA\Util\LogOnce;
use AA\Widget\PwdWidget;
use AA\Widget\TfaWidget;
use AA\Widget\Widget;
use AA_Content;
use AA_Field;
use AA_Fields;
use AA_Item;
use AA_Slice;
use AA_Value;
use Exception;
use ItemContent;
use RuntimeException;
use zids;

/** AA\IO\AbstractGrabber\AbstractGrabber\Form - Grabs data POSTed by AA form
 *
 *  The format of the data is following
 *  (this is new format, which allows to fill or modify more items at once
 *
 *   Format is:
 *       aa[i<long_item_id>][modified_field_id][]
 *   Note:
 *      first brackets contain
 *          'u'+long_item_id when item is edited (the field is rewritten, rest
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
 *      we always add [] at the end, so it became array at the end
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
    private $_items = [];
    private $_last_store_mode;

    public function __construct() {}

    /** Name of the grabber - used for grabber selection box */
    public static function name(): string
    {
        return _m('Form');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in HTML
     */
    public static function description(): string
    {
        return _m('Grabs data POSTed by AA form');
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getStoreMode() method
     */
    public function getIdMode(): string
    {
        return 'old';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    public function getStoreMode(): string
    {
        switch ($this->_last_store_mode) {
            case 'add':
                return 'add';
            case 'update':
                return 'update';
        }
        // case 'new':
        return 'insert';
    }

    private function rearangeAaFilesArray(array $files): array
    {
        $ret = [];
        foreach ($files as $type => $items) {
            foreach ($items as $item => $fields) {
                foreach ($fields as $field => $f) {
                    foreach ((array) $f['fil']['var'] as $k => $value) {
                        $arr = [$item => [$field => ['fil' => ['var' => ["_$k" => [$type => $value]]]]]];
                        $ret = array_merge_recursive($ret, $arr);
                    }
                }
            }
        }
        return $ret;
    }

    private static function getNewItemSlices(): array
    {
        $slices = [];
        $aa_ops = array_merge(array_keys($_POST['aa'] ?? []), array_keys($_FILES['aa'] ?? []));
        foreach ($aa_ops as $dirty_item_id) {
            if ($dirty_item_id[0] === 'n' or $dirty_item_id[0] === 'f') {
                [$letter, $sid] = explode('_', $dirty_item_id);
                $slices[$sid] = true;
            }
        }
        return array_keys($slices);
    }

    /** If any of the slices used by new items requires form tokens, return true
     *  (so the Saver will check the form token)
     *  @todo get slices also from edited items
     */
    public static function useFormTokens(): bool
    {
        $slices = self::getNewItemSlices();
        foreach ($slices as $sid) {
            if (AA_Slice::getModule($sid)?->useFormTokens()) {
                return true;
            }
        }
        return false;
    }

    /** process the   aa[f_54343ea876898b6754e3578a8cc544e6][file][fil][var][] type of upload produced by {manager} and RimWidget and
     *  convert it to aa[n1_54343ea876898b6754e3578a8cc544e6][file............][fil][var][]
     * @return true|string - string is error
     */
    private function fileUploadToNewItem(array &$aa): true|string
    {
        $i = 0;
        foreach ($aa as $type => $file) {
            [$letter, $sid] = explode('_', $type);
            if ($letter[0] === 'f') {
                unset($aa[$type]);   // remove old form and add new one
                if (is_array($file['file']['fil']['var'])) {
                    if (!($s = AA_Slice::getModule($sid))) {
                        return _m('No slice found: %1', [$sid]);
                    }
                    if (!($fid = $s->getDefaultUploadField())) {
                        return _m('No file, img_upload, or img_src field type found in slice %1', [$sid]);
                    }
                    foreach ($file['file']['fil']['var'] as $f) {
                        $aa['n' . (++$i) . "_$sid"] = [$fid => ['fil' => ['var' => [$f]]]];
                    }
                }
            }
        }
        return true;
    }


    /** Method called by the AA\IO\Saver to get next item from the data input */
    public function getItem(): ?AA_Content
    {
        if (!($tostore = current($this->_items))) {
            return null;
        }
        next($this->_items);
        $this->_last_store_mode = $tostore[1];
        return $tostore[0];
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     * @throws Exception
     */
    public function prepare()
    {

        $this->_items = [];
        if (!isset($_POST['aa']) || !is_array($_POST['aa'])) {
            if (!isset($_FILES['aa']) || !is_array($_FILES['aa'])) {
                $this->message(['err' => _m('no POST nor FILES sent')]);
                return false;
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
        // Also, it is stored as array...[name][file___________1][...] array...[type][file___________1][...]
        // so the information about one file is quite far in the structure
        if (isset($_FILES['aa'])) {

            // collect together the information about one file - array(name, type, tmp_name, error, size)
            //$files = array_merge_recursive($_FILES['aa']['name'], $_FILES['aa']['type'], $_FILES['aa']['tmp_name'], $_FILES['aa']['error'], $_FILES['aa']['size']);
            $files = $this->rearangeAaFilesArray($_FILES['aa']);

            // now process the aa[f_54343ea876898b6754e3578a8cc544e6][file][] type of upload and convert it
            // to aa[n1_54343ea876898b6754e3578a8cc544e6][file............][]
            if (($msg = $this->fileUploadToNewItem($files)) !== true) {
                throw new RuntimeException($msg);
            }

            // add it to POSTed variables
            $aa = array_merge_recursive($aa, $files);
        }

        // just prepare ids, in order we can expand
        // You can use _#n1_623553373823736362372726 (not _#N1_ID___ here!) as value, which stands for
        // item id of the item
        foreach (array_keys($aa) as $dirty_item_id) {
            if ($dirty_item_id[0] === 'n') {
                $id_trans_table['_#' . $dirty_item_id] = new_id();
            }
        }
        $trans_item_alias = array_keys($id_trans_table);
        $trans_item_ids   = array_values($id_trans_table);

        $UPDATE_MODES = ['u' => 'update', 'i' => 'add', 'r' => 'update'];
        $anonymous    = self::anonymous();
        foreach ($aa as $dirty_item_id => $item_fields) {

            // common fields
            if ($dirty_item_id === 'all') {
                continue;
            }

            // 'u': edited item - update = field content is changed to new value
            // 'r': edited item - remove value from field
            // 'i': edited item - insert = field content is added to the existing content of the field
            $oldcontent4id = null;
            if ($store_mode = ($UPDATE_MODES[$dirty_item_id[0]] ?? null)) {
                $item_id                         = substr($dirty_item_id, 1);
                if (! ($item_slice_id = AA_Slice::getSliceIdFromItemId($item_id))) {
                    continue;
                }
                $item_fields['slice_id________'] = [pack_id($item_slice_id)];

                $oldcontent4id = $this->storedItem($item_id);
                // if ($dirty_item_id[0] === 'r') {
                if (!$oldcontent4id->getSliceID() || !$oldcontent4id->getItemID()) {
                    continue;
                }

            } // new items
            elseif ($dirty_item_id[0] === 'n') {
                $item_id    = $id_trans_table['_#' . $dirty_item_id];
                $store_mode = 'new';

                //grab slice_id of new item
                $item_slice_id = substr($dirty_item_id, strpos($dirty_item_id, '_') + 1);
                // and add slice_id field to the item
                $item_fields['slice_id________'] = [pack_id($item_slice_id)];
            } else {
                continue;
            }
            $id_trans_table[$dirty_item_id] = $item_id;

            // now fill the ItemContent for each item and tepmorary store it into $this->_items[]
            $item = new ItemContent();
            $item->setItemID($item_id);

            // join common fields (the specific fields win in the battle of common and specific content)
            if (isset($aa['all']) && is_array($aa['all'])) {
                $item_fields = array_merge($aa['all'], $item_fields);
            }


            // This is new - 2025-02-25 - see if it do not break something, but it should not
            if (!($slice = AA_Slice::getModule($item_slice_id))) {
                continue;
            }
            $anonymous_edit = $anonymous && $oldcontent4id;

            foreach ($item_fields as $dirty_field_id => $val_array) {
                // create full_text......1 from full_text______1
                $field_id = AA_Fields::getFieldIdFromVar($dirty_field_id);

                // if field is not found, skip it
                // This is new - 2025-02-25 - see if it do not break something, but it should not
                if (!($field = $slice->getField($field_id))) {
                    continue;
                }

                // a form without login could take over the reader's account or the item - the field stays as stored
                if ($anonymous_edit && self::isAccountField($slice, $field)) {
                    self::refused($item_slice_id, $field_id, $dirty_item_id[0]);
                    continue;
                }
                $default_val = $oldcontent4id ? $oldcontent4id->getAaValue($field_id) : $field->getDefault();

                // Get the content of the field (values and flags)
                // Convert to array - designers sometimes set (wrongly) as scalar:
                //    <input type="hidden" name="aa[n1_f803d8dc...2b][relation________]" value="{_#ITEM_ID_}">  - right is ..aa[n1_f803d8dc...2b][relation________][0]...
                //  (which somehow works in PHP<8) - now we typecasting it to array to be sure

                // default used just for HTML flag, right now
                $aa_value = Widget::getValue((array) $val_array, $default_val);
                $aa_value->replaceInValues($trans_item_alias, $trans_item_ids);

                if ($oldcontent4id && $dirty_item_id[0] === 'r' && $field_id !== 'slice_id........') {
                    $aa_value = $oldcontent4id->getAaValue($field_id)->removeValues($aa_value->getValues());
                }
                $item->setAaValue($field_id, $aa_value);
            }
            // in another slice its fields would be checked by that slice - the item stays where it is
            $moved_to = (string) $item->getValue('slice_id........');
            if ($anonymous_edit && ($moved_to !== '') && ($moved_to !== pack_id($item_slice_id))) {
                self::refused($item_slice_id, 'slice_id........', $dirty_item_id[0]);
                $item->setAaValue('slice_id........', new AA_Value(pack_id($item_slice_id)));
            }
            $this->_items[] = [$item, $store_mode];
        }
        reset($this->_items);
        return true;
    }

    /** the item as stored - an empty ItemContent when there is none */
    protected function storedItem(string $item_id): ItemContent
    {
        $oldcontent4id = new ItemContent();
        $oldcontent4id->setByItemID(new zids($item_id), true);     // ignore password
        return $oldcontent4id;
    }

    /** No user is logged in. filler.php opens the session only when the visitor has its cookie, so
     *  $auth may be missing; a logged-in reader is a user.
     */
    private static function anonymous(): bool
    {
        $auth = $GLOBALS['auth'] ?? null;
        return !is_object($auth) || !$auth->is_user();
    }

    /** the field of the item was left as stored - logged once a day, never the value */
    private static function refused(string $slice_id, string $field_id, string $mode): void
    {
        LogOnce::daily(CheckAnonymousEditRefusals::EVENT, ParamImplode([$slice_id, $field_id]), [$mode, basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))]);
    }

    /** Fields an anonymous edit of an existing item must not change: the flags and the author of any
     *  item, a password and a 2FA secret, and the login of a reader - they give the account or the item
     *  away.
     */
    private static function isAccountField(AA_Slice $slice, AA_Field $field): bool
    {
        $field_id = $field->getId();
        // a password field of another number is found by its type (firstOfTypeFieldId())
        if (in_array($field_id, ['flags...........', 'posted_by.......'], true) || str_starts_with($field_id, 'password') || str_starts_with($field_id, 'secret')) {
            return true;
        }
        // headline........ is an ordinary title outside Reader Management
        if (($field_id === FIELDID_USERNAME) && ($slice->type() === 'ReaderManagement')) {
            return true;
        }
        // the widget of a field of a fieldslice is not in input_show_func
        $widget   = $field->getWidget();
        [$insert] = explode(':', strtolower((string) $field->getProperty('input_insert_func')), 2);
        return ($widget instanceof PwdWidget) || ($widget instanceof TfaWidget) || in_array($insert, ['pwd', '2fa'], true);
    }

    /** Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    public function finish(): void
    {
        $this->_items = [];
    }

    public static function grab() {}
}
