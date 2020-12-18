<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:45
 */

namespace AA;
use AA_Fields;
use AA_Item;
use AA_Items;
use AA_Slice;
use Exception;
use zids;

/** Collection of static functions used for aa[..][..] form variables handling */
class FormArray
{
    /** ID of the field input - used for name atribute of input tag (or so)
     *   Format is:
     *       aa[u<long_item_id>][modified_field_id][]
     *   Note:
     *      first brackets contain
     *          'u'+long_item_id when item is edited or
     *          'n<number>_long_slice_id' if you want to add the item to slice_id
     *                                    <number> is used to add more than one
     *                                    item at the time
     *      modified_field_id is field_id, where all dots are replaced by '_'
     *      we always add [] at the end, so it becames array at the end
     *   Example:
     *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline_______1][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
     *   Format is:
     *       aa[u<long_item_id>][modified_field_id][]
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
     *          'f_long_slice_id' new item from file upload to slice
     *      modified_field_id is field_id, where all dots are replaced by '_'
     *      we always add [] at the end, so it becames array at the end
     *   Example:
     *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
     *       aa[i63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
     *       aa[r63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
     *
     * @param $property_id string
     * @param $content \AA_Content
     * @param $item_index int
     *@return string
     * @throws Exception
     */
    static public function getName4Form($property_id, $content, $item_index = 1)
    {
        $form_field_id = AA_Fields::getVarFromFieldId($property_id);   // post_date......1  ==>  post_date______1

        $oid = $content->getId();
        if ($oid) {
            return "aa[u$oid][$form_field_id]";
        }

        $oowner = $content->getOwnerID();
        if (!$oowner) {
            throw new Exception('No owner specifield for ' . $form_field_id);
        }
        $item_index = ctype_digit((string)$item_index) ? (int)$item_index : 1;
        return "aa[n${item_index}_$oowner][$form_field_id]";
    }

    static public function formName2Id($name)
    {
        return str_replace([']', '['], ['', '-'], $name);
    }

    /** returns array(item_id,field_id) from name of variable used on AA form */
    static public function parseId4Form($input_name)
    {
        // aa[u<item_id>][<field_id>][]
        $parsed = explode(']', $input_name);
        $item_id = substr($parsed[0], 4);
        $field_id = AA_Fields::getFieldIdFromVar(substr($parsed[1], 1));
        return [$item_id, $field_id];
    }

    static public function getCharset($aa)
    {
        $module_id = self::getOwner($aa);
        if (!$module_id) {
            return '';
        }
        $slice = AA_Slice::getModule($module_id);
        return $slice->getCharset();
    }

    static public function getOwner($aa)
    {
        if (!is_array($aa)) {
            return false;
        }
        foreach ($aa as $key => $foo) {
            if ($key[0] == 'n') return substr($key, strpos($key, '_') + 1);
        }

        $item_id = false;
        foreach ($aa as $key => $foo) {
            if (($key[0] == 'u') OR ($key[0] == 'i')) {
                $item_id = substr($key, 1);
                break;
            }
        }
        if (!$item_id) {
            return false;
        }
        $item = AA_Items::getItem(new zids($item_id, 'l'));
        if (!$item) {
            return false;
        }
        return $item->getSliceID();
    }
}