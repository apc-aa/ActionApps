<?php

/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:37
 */

namespace AA\Widget;

use AA\FormArray;
use AA_Content;
use AA_Property;
use AA_Session;
use AA_Value;
use Exception;
use ItemContent;

/** Manager widget */
class MngWidget extends Widget
{
    protected $const;
    protected $related_field;
    protected $row_code;
    protected $row_edit;
    protected $mng_buttons;
    protected $sort_by;

    /** \AA\Util\NamedInterface function */
    public static function name(): string
    {
        return _m('Manager');
    }   // widget name

    /** \AA\Util\NamedInterface function */
    public static function description(): string
    {
        return _m('Best for galleries and other tabular data which is multivalue and different for each item');
    }   // widget name

    protected function requireLibs($aa_property, $winfo): array
    {
        return array_merge(['aa-jslib@3'], parent::requireLibs($aa_property, $winfo));  // aa-jslib@3 for  AA__SiteUpdateParts
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array
    {
        return Widget::propertiesShop(['const', 'related_field', 'row_code', 'row_edit', 'mng_buttons', 'sort_by']);
    }

    /** The stored connector, or '' for a value that cannot be one - it goes into the {manager} expression below
     *  and into its condition (where "-" separates, and AAnoCONDITION drops the condition).
     *  Connectors are new_id(); a plain token is kept, so data stored some other way still finds its rows.
     */
    private static function connector(string $value): string
    {
        return (preg_match('~^[0-9A-Za-z_.]+$~D', $value) && ($value !== 'AAnoCONDITION')) ? $value : '';
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *
     * @param  AA_Property  $aa_property
     * @param  AA_Content   $content
     * @param  string       $type  normal|live|ajax
     *
     *  @return array
     *  @throws Exception
     */
    public function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array
    {
        $property_id = $aa_property->getId();
        $item_id     = $content->getId();
        $base_name   = FormArray::getName4Form($property_id, $content, $this->item_index);
        $base_id     = FormArray::formName2Id($base_name);
        $input_name  = $base_name . "[0]";
        $stored      = (string) $content->getAaValue($property_id)->getValue();
        $input_value = self::connector($stored);

        // we store the connector id to cookie in order we have it during AJAX call - Parts renewal
        $sess_var = get_short_hash($base_id);
        AA_Session::ensureStarted();

        // We are using artificial ID - say connector - and not real item ID of current item
        // This allows us to use manager even for nonstored items and maybe other features (shared constants) in future
        if (!strlen($input_value)) {
            if (IsAjaxCall()) {
                if (!($_SESSION['AA_4ajax'] && $_SESSION['AA_4ajax']['w_mng'] && ($input_value = $_SESSION['AA_4ajax']['w_mng'][$sess_var]))) {
                    warn("No cookie value for connector");
                }
            } else {
                $input_value = new_id();
                //if (($type === 'ajax') OR ($type === 'live')) {
                // a refused value is replaced only when the item is saved, not by showing the form
                if (is_long_id($item_id) && ($stored === '')) {
                    // we have to store the connectorID to the database immediately
                    UpdateField($item_id, $property_id, new AA_Value($input_value, FLAG_HTML), ItemContent::FLAG_JUST_STORE);
                }
            }
        }
        // @todo - delete whole $_SESSION['AA_4ajax'] on any - non ajax page call (or just this hash upon form store)
        $_SESSION['AA_4ajax']['w_mng'][$sess_var] = $input_value;
        // $cookie->set($cookie_name, $input_value, 60*60*24*2, parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));   // 2 days, only this path

        $widget = "<input type=\"hidden\" name=\"$input_name\" id=\"$base_id\" value=\"$input_value\">";

        // ($slices='', $field=null, $item_id=null, $code=null, $conds=null, $sort=null, $mode=null, $edit_code=null)
        if (!($mng_slice = $this->getConstSlice())) {
            warn("No Slice parameter provided");
            return [];
        }
        $mng_field  = $this->getProperty('related_field', 'relation........');
        $mng_code   = $this->getProperty('row_code', '_#ROW_CODE');
        $mng_conds  = '';
        $mng_sort   = $this->getProperty('sort_by', '');
        $mng_mode   = $this->getProperty('mng_buttons', '');    // we left it to defaults for {manager}
        $mng_edit_code   = $this->getProperty('row_edit', '');  // we left it to defaults for {manager}
        //$mng_field=null, $mng_item_id=null, $mng_code=null, $mng_conds=null, $mng_sort=null, $mng_mode=null, $mng_edit_code=null)

        $widget .= \AA::Un("{manager:$mng_slice:$mng_field:$input_value:$mng_code:$mng_conds:$mng_sort:$mng_mode:$mng_edit_code}");
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}
