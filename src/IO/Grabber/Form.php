<?php

/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:05
 */

namespace AA\IO\Grabber;

use AA\FormArray;
use AA\Optimize\CheckHtmlFlagRefusals;
use AA\Util\LogOnce;
use AA\Widget\EdtWidget;
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
    /** @var array<string, array{0: bool, 1: bool}> slice id => the current user edits [all items, own items] */
    private array $editor_perms = [];
    /** @var array<string, bool> slice id:field id => anyone may choose HTML there */
    private array $allows_html = [];

    /** the fields holding the item's own ids - never taken from the request */
    private const ITEM_IDS = ['slice_id........', 'u_slice_id......', 'id..............', 'unpacked_id.....'];

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
        foreach ($aa as $dirty_item_id => $item_fields) {

            // common fields
            if ($dirty_item_id === 'all') {
                continue;
            }

            // 'u': edited item - update = field content is changed to new value
            // 'r': edited item - remove value from field
            // 'i': edited item - insert = field content is added to the existing content of the field
            $stored = null;
            if ($store_mode = ($UPDATE_MODES[$dirty_item_id[0]] ?? null)) {
                $item_id                         = substr($dirty_item_id, 1);
                if (! ($item_slice_id = AA_Slice::getSliceIdFromItemId($item_id))) {
                    continue;
                }
                $oldcontent4id = $this->storedItem($item_id);
                // if ($dirty_item_id[0] === 'r') {
                if (!$oldcontent4id->getSliceID() || !$oldcontent4id->getItemID()) {
                    continue;
                }
                $stored = $oldcontent4id;

            } // new items
            elseif ($dirty_item_id[0] === 'n') {
                $item_id    = $id_trans_table['_#' . $dirty_item_id];
                $store_mode = 'new';

                //grab slice_id of new item
                $item_slice_id = substr($dirty_item_id, strpos($dirty_item_id, '_') + 1);
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
            $editor = null;   // asked only when a field needs it

            foreach ($item_fields as $dirty_field_id => $val_array) {
                // create full_text......1 from full_text______1
                $field_id = AA_Fields::getFieldIdFromVar($dirty_field_id);

                // the ids are set by the key of the item - a posted one would move it to another slice
                if (in_array($field_id, self::ITEM_IDS, true)) {
                    continue;
                }

                // if field is not found, skip it
                // This is new - 2025-02-25 - see if it do not break something, but it should not
                if (!($field = $slice->getField($field_id))) {
                    continue;
                }
                $default_val = ($store_mode === 'new') ? $field->getDefault() : $oldcontent4id->getAaValue($field_id);

                // Get the content of the field (values and flags)
                // Convert to array - designers sometimes set (wrongly) as scalar:
                //    <input type="hidden" name="aa[n1_f803d8dc...2b][relation________]" value="{_#ITEM_ID_}">  - right is ..aa[n1_f803d8dc...2b][relation________][0]...
                //  (which somehow works in PHP<8) - now we typecasting it to array to be sure

                // default used just for HTML flag, right now
                $aa_value = Widget::getValue((array) $val_array, $default_val);
                $aa_value->replaceInValues($trans_item_alias, $trans_item_ids);

                if ($stored && $dirty_item_id[0] === 'r') {
                    // removing values leaves the stored value with its flag - it adds no content
                    $aa_value = $stored->getAaValue($field_id)->removeValues($aa_value->getValues());
                } elseif (!$this->allowsHtml($item_slice_id, $field) && !($editor ??= $this->editsItem($item_slice_id, $stored))) {
                    if (!self::useDefaultHtmlFlag($aa_value, $field, $item_slice_id, $stored, $dirty_item_id[0] === 'i', (array) $val_array)) {
                        continue;
                    }
                }
                $item->setAaValue($field_id, $aa_value);
            }
            $item->setAaValue('slice_id........', new AA_Value(pack_id($item_slice_id)));
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

    /** May the current user choose HTML for the fields of the item? Editors of the slice may - the
     *  inline editing of {editable} posts [flag] for plain-text fields, too.
     *  @param ?ItemContent $stored  the item as stored; null for a new item
     */
    private function editsItem(string $slice_id, ?ItemContent $stored): bool
    {
        // nobody logged in - IfSlPerm() would just load the (empty) permissions of such user from the database
        if (in_array($uid = AuthGetUid(), ['', 'nobody'], true)) {
            return false;
        }
        class_exists('AA_Perm');   // IfSlPerm() is in include/perm_core.php, which filler.php need not load
        [$all, $own] = $this->editor_perms[$slice_id] ??= [IfSlPerm(PS_EDIT_ALL_ITEMS, $slice_id), IfSlPerm(PS_EDIT_SELF_ITEMS, $slice_id)];
        return $all || ($own && (!$stored || ((string) $stored->getValue('posted_by.......') === $uid)));
    }

    /** Anyone may choose HTML in the field - by its HTML/plain switch, or by the rich-text editor as
     *  its widget, which always posts HTML. The configured widget counts, not the one the request names.
     */
    private function allowsHtml(string $slice_id, AA_Field $field): bool
    {
        return $this->allows_html[$slice_id . ':' . $field->getId()] ??= ($field->getProperty('html_show') || is_a(Widget::parseClassProperties((string) $field->getProperty('input_show_func'))['class'], EdtWidget::class, true));
    }

    /** Gives the value the HTML flag of the field default, as the old input format does. Each widget
     *  sets the flag its own way (the file one always HTML), so the grabbed value is corrected, not the
     *  request.
     *  @param ?ItemContent $stored      the item as stored; null for a new item
     *  @param bool         $add         the value is added to the stored one
     *  @param array        $data4field  the posted data of the field
     *  @return bool  false - the value must not be stored
     */
    private static function useDefaultHtmlFlag(AA_Value $aa_value, AA_Field $field, string $slice_id, ?ItemContent $stored, bool $add, array $data4field): bool
    {
        $html         = $field->getDefaultFormatterFlag() & FLAG_HTML;
        $stored_value = $stored?->getAaValue($field->getId());
        $before       = ($stored_value && !$stored_value->isEmpty()) ? ($stored_value->getFlag() & FLAG_HTML) : $html;
        $log          = static fn(string $mode) => LogOnce::daily(CheckHtmlFlagRefusals::EVENT, ParamImplode([$slice_id, $field->getId()]), [mb_strcut(AuthGetUid(), 0, 120), $mode]);

        // all the values of a field are shown with the flag of the first one
        if ($add && ($before !== $html)) {
            $log('insert');
            return false;
        }
        $aa_value->setFlag(($aa_value->getFlag() & ~FLAG_HTML) | $html);

        // AA's own forms post the stored flag back - only another one means a wrong form or a forgery
        $asked = is_scalar($data4field['flag'] ?? null) ? ((int) $data4field['flag'] & FLAG_HTML) : 0;
        if ($asked && !$html && ($asked !== $before)) {
            $log($stored ? 'update' : 'new');
        }
        return true;
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
