<?php

/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Cache\CacheStr2find;
use AA\Util\Langs;
use AA\Util\Searchfields;
use AA\Util\Txt;
use AA\Widget\CodWidget;
use AA\Widget\Widget;

/** for AA_Generator_*   */
require_once __DIR__ . "/itemfunc.php";

class AA_Field implements ArrayAccess, Countable, IteratorAggregate
{
    use AA\Util\DataArrayAccessTrait;

    /** @var string[] list of field table rows manager by this class - other are unused */
    public const USED_FIELDS = ['id', 'slice_id', 'name', 'input_pri', 'input_help', 'input_morehlp', 'input_default', 'required', 'feed', 'multiple', 'input_show_func', 'search_pri', 'alias1', 'alias1_func', 'alias1_help', 'alias2', 'alias2_func', 'alias2_help', 'alias3', 'alias3_func', 'alias3_help', 'input_before', 'content_edit', 'html_default', 'html_show', 'in_item_tbl', 'input_validate', 'input_insert_func', 'input_show', 'text_stored'];

    /** associative array of field data as defined in field table - @see USED_FIELDS */
    protected array $data = [];

    /** array of enabled aliases (not X#.. )  */
    protected ?AA_Aliases $aliases = null;

    /** class and properties for slice defined fields (replaces input_show_func functionality)  */
    protected array $widget_params = [];

    /** cache array of all used widgets for this field
     *  @var Widget[]
     */
    protected array $widget = [];

    /**
     * @param  array|AA_Item  $data
     */
    public function __construct($data)
    {
        $this->data = $data;
        $this->sanitizeData();  // check for misconfigured mandatory fields
    }

    public static function factoryFromDb(string $field_id, string $slice_id): ?AA_Field
    {
        if (!self::isValidFieldId($field_id)) {
            return null; // invalid field id
        }
        $flds = implode(',', array_map(static fn($value) => "`$value`", self::USED_FIELDS));
        $data = DB_AA::select1('', "SELECT $flds FROM `field`", [['slice_id', $slice_id, 'l'], ['id', $field_id]]);
        return $data ? new AA_Field($data) : null;
    }

    public static function isValidFieldId(string $field_id): bool
    {
        // changed this from [a-z_]+\.+[0-9]*$ because of alerts[12]....abcde
        return strlen($field_id) === 16 && preg_match('/^[a-z0-9_]+\.+[0-9A-Za-z]*$/', $field_id);
    }

    /**
     * @param string $fid - field id possibly with |lang1|lang2 suffix  - like 'headline........|en|de'
     * @return [string clean_field_id, array langs_to_search_in[]] - like ['headline........',['en','de']]
     */
    public static function parseWithLang(string $fid): array
    {
        if (!str_contains($fid, '|')) {
            return [$fid, []];
        }
        $arr = explode('|', $fid);
        $fid = array_shift($arr);
        $langs = array_filter($arr, static fn($l) => Langs::isValidLangName($l));
        return [$fid, $langs];
    }

    /** Check for misconfigured mandatory fields */
    protected function sanitizeData(): void
    {
        if ($this->data['id'] === 'id..............') {
            $this->data['input_validate'] = 'packedid';
            $this->data['search_pri'] = 100;  // standard value (redefining 0 from DB)
        }
    }

    /** storageColumn function
     *  @return string - the table and column, where the field is stored
     */
    public function storageColumn(): string
    {
        return $this->data['in_item_tbl'] ?: ($this->data['text_stored'] ? 'text' : 'number');
    }

    /** storageTable function
     *  @return string - the table and column, where the field is stored
     */
    public function storageTable(): string
    {
        return $this->data['in_item_tbl'] ? 'item' : 'content';
    }

    /**
     * @return bool is this field protected for reading to ItemContent and for writing to it?
     */
    public function isSafeStored(): bool
    {
        // the content_edit is not yet used - we plan to make able the field to be secured
        return (($this->data['content_edit'] & FIELD_UNREADABLE) || (AA_Fields::getFieldType($this->getId()) === 'secret'));     // @todo add also password
        // @todo mark all secure and password fields as FIELD_UNREADABLE
    }

    /** password, 2FA secret, a copy of a password or an unreadable field - its value never goes into a widget */
    public function isProtected(): bool
    {
        $type     = AA_Fields::getFieldType($this->getId());
        [$insert] = explode(':', strtolower((string) $this->data['input_insert_func']), 2);
        return str_starts_with($type, 'password') || str_starts_with($type, 'secret') || in_array($insert, ['pwd', '2fa'], true) || $this->isSafeStored() || $this->isPasswordCopy();
    }

    /** the form checks a unique value before the insert function, the store not at all */
    public function isUniqueValidated(): bool
    {
        $validate = (string) $this->data['input_validate'];
        if (stripos($validate, 'unique') === false) {   // cheap - a field slice asks it for each of its fields
            return false;
        }
        $validator = AA_Validate::factoryByString($validate);
        return ($validator instanceof AA_Validate_Unique) || ($validator instanceof AA_Validate_Eunique);
    }

    /** Why the Trim insert function would break the field, '' - it breaks nothing. It must not read the other
     *  fields of the slice: a field slice asks it while it builds them (isProtected() would loop).
     *  @param bool $read_constants false: constants of a group are not read, the field is refused */
    public function trimRefusal(bool $read_constants = true): string
    {
        if ($this->isUniqueValidated()) {
            // " jan" would pass next to "jan" and be stored as the same
            return _m('unique value - it is checked before it is trimmed, so a duplicate could slip through');
        }
        // the widget parameters, not the widget: most widgets have no const property to ask for
        $params = $this->widgetParams();
        if (is_a($params['class'] ?? '', CodWidget::class, true)) {
            return _m('code - its indentation and the last new line are kept on purpose');
        }
        $const = (string) ($params['const'] ?? '');
        if (str_starts_with($const, '#sLiCe-')) {
            // ids of the related items have no spaces; with use_name the text of the item is stored
            return empty($params['use_name']) ? '' : _m('stores the text of the selected items');
        }
        if (($const === '') && empty($params['const_arr'])) {
            return '';
        }
        if (!$read_constants && ($const !== '')) {
            return _m('constants of a group');
        }
        // a trimmed value would no longer match its constant
        foreach (($read_constants ? $this->getWidget()->getFormattedOptions() : (array) $params['const_arr']) as $value => $name) {
            foreach ([(string) $value, (string) $name] as $text) {
                if (AA_Inserter_Trm::trimValue($text) !== $text) {
                    return _m('the constant "%1" has spaces or new lines around - trim the constants first', [$text]);
                }
            }
        }
        return '';
    }

    /** the field is the bck_field (copy of the password) of a pwd inserter in its slice */
    protected function isPasswordCopy(): bool
    {
        foreach (AA_Slice::getModule($this->getSliceId())?->getFields() ?? [] as $field) {
            $insert = (string) $field->getProperty('input_insert_func');
            if (str_starts_with(strtolower($insert), 'pwd:') && ((AA_Inserter::parseClassProperties($insert)['bck_field'] ?? '') === $this->getId())) {
                return true;
            }
        }
        return false;
    }

    /** getProperty function
     * @param string $property
     * @return mixed - field data
     */
    public function getProperty(string $property)
    {
        return $this->data[$property];
    }

    /**
     * @return string - id of the field
     */
    public function getId(): string
    {
        return $this->getProperty('id');
    }

    /**
     * @return string - name of the field
     */
    public function getName(): string
    {
        return $this->getProperty('name');
    }

    /**
     * @return string - long id of the slice of this field
     */
    public function getSliceId(): string
    {
        return unpack_id($this->getProperty('slice_id'));
    }

    /**
     * @return boolean value if the field is required  (must be filled)
     */
    public function required(): bool
    {
        return (bool) $this->getProperty('required');
    }

    /**
     * @param $widget_type - wi2|sel|...
     *                       used, when we want to use another widget, than the default one
     *                       usually not used
     * @param $properties - array of properties to redefine for $widget_type
     * @return Widget|null
     */
    public function getWidget($widget_type = null, $properties = []): ?Widget
    {
        $widget_hash = get_hash($widget_type, $properties);

        // just for cache. maybe it is not necessary...
        if (!isset($this->widget[$widget_hash])) {

            $params       = $this->widgetParams();
            $widget_class = $widget_type ? Widget::constructClassName($widget_type) : $params['class'];

            if (!class_exists($widget_class) && !class_exists($widget_class = $params['class'])) {
                $widget_class = 'AA\Widget\NulWidget';
            }
            $w = AA_Object::factory($widget_class, $params);

            if ($properties) {
                // for security reasons we do not want to redefine const (Constants/Slices)
                unset($properties['const'], $properties['bin_filter'], $properties['filter_conds']);
                $w->setProperties($properties);
            }
            $this->widget[$widget_hash] = $w;
        }
        return $this->widget[$widget_hash];
    }

    /** the class and properties of the widget - from input_show_func, or from the field slice */
    protected function widgetParams(): array
    {
        $definition = (string) $this->data['input_show_func'];
        if ($definition === '') {
            return $this->widget_params;
        }
        // legacy - in dates we used ' as delimiter some time in history of AA
        if (str_starts_with($definition, 'dte:') && strpos($definition, "'")) {
            $definition = str_replace("'", ":", $definition);
        }
        return Widget::parseClassProperties($definition);
    }


    /** getDefault function
     *
     * @param  bool  $with_profile
     *
     * @return AA_Value
     */
    public function getDefault(bool $with_profile = false): AA_Value
    {
        if ($with_profile) {
            $profile   = AA_Profile::getProfile(AuthGetUid(), $this->getSliceId()); // current user settings
            if ($profile_value = $profile->getProperty('predefine', $this->getId())) {
                return $profile->parseContentProperty($profile_value);
            }
        }

        // all default should have fnc:param format
        $val = ($generator = AA_Generator::factoryByString($this->data['input_default'])) ? $generator->generate() : new AA_Value();
        return $val->setFlag($this->getDefaultFormatterFlag());
    }

    /**
     * @return int
     */
    public function getDefaultFormatterFlag(): int
    {
        return ($this->data['html_default'] > 0) ? AA_Formatters::RAW : AA_Formatters::TEXT2HTML;    // RAW = 1 = FLAG_HTML
    }

    /**
     * @return string date|pwd|bool|text|int|id
     */
    public function getType(): string
    {
        [$validate_type] = explode(":", $this->getProperty('input_validate'), 2);
        [$insert_type]   = explode(":", $this->getProperty('input_insert_func'), 2);

        if ($insert_type === 'pwd') {
            return 'pwd';
        }
        if ($validate_type === 'date') {
            return 'date';
        }
        if ($insert_type === 'boo') {
            return 'bool';
        }
        if ($insert_type === 'ids' || $validate_type === 'id' || $validate_type === 'packedid') {
            return 'id';
        }
        if ($this->getProperty('text_stored')) {  // not used from here down - it is possible to add more types - ids, ...
            return 'text';
        }
        return 'int';
    }


    /**
     * @param  AA_Content|null  $oldcontent4id
     * @param  bool             $insert
     *
     * @return AA_Value|null
     */
    public function readValueFromOldtypeForm(?AA_Content $oldcontent4id, bool $insert): ?AA_Value
    {

        $fid = $this->getId();
        // "v" prefix - database field var
        $varname          = 'v' . unpack_id($fid);
        $htmlvarname      = $varname . 'html';
        $presence_varname = $varname . 'p';


        // GLOBAL - was used for default field value set by ValidateContent4Id()
        // $var = isset($_REQUEST[$varname]) ? $_REQUEST[$varname] : $GLOBALS[$varname];
        $type = $this->getType();

        $var = $_REQUEST[$varname];

        if ($type === 'pwd') {
            $change_var = $_REQUEST[$varname . 'a'];
            $retype_var = $_REQUEST[$varname . 'b'];
            $delete_var = $_REQUEST[$varname . 'd'];

            if ($change_var && ($change_var == $retype_var)) {
                $var = ParamImplode(['AA_PASSWD', $change_var]);
            } elseif ($delete_var) {
                $var = '';
            } elseif (!$insert && $oldcontent4id) {
                // store the original password to use it in
                // insert_fnc_pwd when it is not changed
                // $$varname = $oldcontent4id[$pri_field_id][0]['value'];
                $var = ParamImplode(['AA_PASSWD_CRYPTED', $oldcontent4id->getValue($fid)]);
            } else {
                return null;
            }
            return new AA_Value($var, FLAG_HTML);
        }

        if ($type === 'date') {
            $datectrl = new datectrl($varname);
            if ($datectrl->isUpdatable()) {
                $datectrl->update();                   // updates datectrl
                return new AA_Value($datectrl->get_date(), FLAG_HTML);
            }

            if (isset($var)) {
                // hidden date field ('hid' widget)
                return new AA_Value($_REQUEST[$varname], FLAG_HTML);
            }
            return null;
        }

        if ($type === 'bool') {
            // $ret = $this->content[$fid] = new AA_Value(($var ? 1 : 0), FLAG_HTML);  // replaced by the line below - I think it is mistake: $this->content[$fid]
            return new AA_Value(($var ? 1 : 0), FLAG_HTML);
        }

        if (isset($var)) {
            if (!is_array($var)) {
                $var = [0 => $var];
            }
            $flag = $this->getProperty('html_show') ? ($_REQUEST[$htmlvarname] === "h" ? FLAG_HTML : 0) : $this->getDefaultFormatterFlag();
            return new AA_Value($var, $flag);
        }

        if ($_REQUEST[$presence_varname] === 'P') {           //  elseif (in_array($input_type, ['mse','wi2','iso','hco'])) {  // uses listbox - no value means reset content - never used
            return new AA_Value('', $this->getDefaultFormatterFlag());
        }
        return null;
    }

    /**
     * @returns AA_Aliases - just enabled aliases (not X#...))
     */
    public function getAliases(): AA_Aliases
    {
        // maybe filled here in previous call, or filled in constructor for item defined fields
        if ($this->aliases) {
            return $this->aliases;
        }
        $this->aliases = new AA_Aliases();
        $f = $this->data;

        // fld used in PrintAliasHelp to point to alias editing page
        $this->aliases->addAlias(AA_Alias::factory($f['alias1'], $f['alias1_func'], $f['id'], $f['alias1_help']));
        $this->aliases->addAlias(AA_Alias::factory($f['alias2'], $f['alias2_func'], $f['id'], $f['alias2_help']));
        $this->aliases->addAlias(AA_Alias::factory($f['alias3'], $f['alias3_func'], $f['id'], $f['alias3_help']));
        return $this->aliases;
    }

    /**  function finds group_id in field.input_show_func parameter */
    public function getConstantGroup(): false|string
    {
        // does this field use constants? Isn't it slice?
        [$field_type, $field_add] = $this->getSearchType();
        return ($field_type === 'constants') ? $field_add : false;
    }

    /** getSearchType function
     * @return string[] - [text | numeric | date | constants, [relation,<slice_id>], [constants,<constants_name>], [numconstants,<constants_name>]]
     */
    public function getSearchType(): array
    {
        $field_type = 'numeric';
        $field_add  = '';
        if ($this->data['text_stored']) {
            $field_type = 'text';
        }
        if (str_starts_with($this->data['input_validate'], 'date')) {
            $field_type = 'date';
        }
        $r = $this->getRelation();

        if (!empty($r)) {
            $field_add  = $r[1];
            $field_type = ($field_type === 'numeric') ? 'numconstants' : $r[0];
        }

        return [$field_type, $field_add];
    }

    /** getTranslations
     *  Returns array of two letters shortcuts for languages used in this slice for translations - array('en','cz','es')
     */
    public function getTranslations(): array
    {
        return $this->isMultilingual() ? AA_Slice::getModule($this->getSliceId())->getTranslations() : [];
    }

    /**
     *  @return int bitfield of all shown formatters set for this field
     */
    public function getFormatters(): int
    {
        return ($this->getProperty('html_show') ? AA_Formatters::STANDARD_FORMATTERS : 0);
    }

    /**
     *  @return bool if the field is multivalue (not multilingual - it is independent)
     */
    public function isMultiple(): bool
    {
        return $this->getWidget()::multiple();
    }

    /**
     *  @return bool if the field is multilingual
     */
    public function isMultilingual(): bool
    {
        return ($this->data['multiple'] & 2);
    }

    /** getAaProperty function
     * @param array $widget_properties ['multiple'=> , 'required'=> , 'name'=> , 'input_help'=> , 'html_show' => , 'html_default' => ]
     * @return AA_Property
     */
    public function getAaProperty($widget_properties = []): AA_Property
    {
        $translations = $this->getTranslations();

        $widget = $this->getWidget();
        $validator = $widget ? $widget->getUpgradedValidator($this->data['input_validate']) : AA_Validate::factoryByString($this->data['input_validate']); // ids for iso widget could be marked - prepended by x, y or z
        //      moved to IsoWidget
        //        if ( (substr($validator_string,0,2)==='id') AND (substr($this->data['input_show_func'],0,3)==='iso') ) {
        //            $validator_string = 'markedid';
        //        }

        // AA_Property($id='', $name='', $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perms=null, $default=null, $translations=null) {
        return new AA_Property(
            $this->getId(),
            $widget_properties['name'] ?? $this->getName(),
            $this->getProperty('in_item_tbl') ? AA::Metabase()->getFieldType('item', $this->getProperty('in_item_tbl')) : ($this->getProperty('text_stored') ? 'text' : 'int'),
            $widget_properties['multiple'] ?? $this->isMultiple(),
            false,                   // persistent @todo
            $validator, // null,
            isset($widget_properties['required']) ? (bool) $widget_properties['required'] : $this->required(),
            $widget_properties['input_help'] ?? $this->getProperty('input_help'),
            $widget_properties['input_morehlp'] ?? $this->getProperty('input_morehlp'),
            null,               // $example;
            isset($widget_properties['show_formatters']) ? (int) $widget_properties['show_formatters'] : $this->getFormatters(),
            isset($widget_properties['default_formatter']) ? (int) $widget_properties['default_formatter'] : $this->getDefaultFormatterFlag(),
            null,               // perms
            $widget_properties['default'] ?? $this->getDefault(),
            $translations,
        );
    }



    /** widget for in place editing ({ajax}) of this field in $content
     *  A protected field shows only its own pwd/tfa widget, which never gets the value, or nothing.
     * @param string|null $widget_type       another widget than the field's own - wi2|sel|...
     * @param array       $widget_properties properties to redefine for the widget - ['columns' => 1]
     */
    public function getWidgetAjaxHtml(AA_Content $content, ?string $widget_type = null, array $widget_properties = []): string
    {
        if ($this->isProtected()) {
            $widget = $this->getWidget();
            if (!($widget instanceof AA\Widget\PwdWidget) && !($widget instanceof AA\Widget\TfaWidget)) {
                return '';
            }
            $widget_properties = [];
            // pwd only needs to know there is a value (it asks for the current password then) - note: this
            // overwrites the value in the caller's $content
            if (!$content->getAaValue($this->getId())->isEmpty()) {
                $content->setAaValue($this->getId(), new AA_Value('*'));
            }
        } else {
            $widget = $this->getWidget($widget_type, $widget_properties)->setLiteral(array_diff(array_keys($widget_properties), ['const', 'bin_filter', 'filter_conds']));
        }
        $widget_properties['multiple'] = $widget::multiple();
        return $widget->getAjaxHtml($this->getAaProperty($widget_properties), $content);
    }

    /** getWidgetNewHtml function
     * @param       $required // redefine default settings of required
     * @param null  $function
     * @param       $widget_type - wi2|sel|...
     *                       used, when we want to use another widget, than the default one
     *                       usualy not used - right now we use it just for constants_sel.php
     * @param array $widget_properties - array of properties to redefine for $widget_type
     * @param AA_Value|null $preset_value
     * @param int|null      $item_index - used to identify number of the item - aa[n1_...], aa[n2_...]
     * @param bool  $widget_only
     *
     * Usage: $field->getWidgetNewHtml(null, null, 'mch', array('columns' => 1));
     * @return string
     * @throws Exception
     */
    public function getWidgetNewHtml($required = null, $function = null, $widget_type = null, $widget_properties = [], $preset_value = null, $item_index = null, $widget_only = false): string
    {
        $widget  = $this->getWidget($widget_type, $widget_properties);
        if (!is_null($item_index)) {
            $widget->setIndex($item_index);
        }
        $content = new AA_Content();
        $content->setOwnerId($this->getSliceId());
        if (is_object($preset_value)) {
            $content->setAaValue($this->getId(), $preset_value);
        } else {
            $def = $this->getDefault(true);
            if (!$def->isEmpty()) {
                $content->setAaValue($this->getId(), $def);
            }
        }
        $widget_properties['multiple'] =  $widget::multiple();
        $widget_properties['required'] =  $required;
        //$widget_properties['name'], $widget_properties['input_help'], ... could be set already in the $widget_properties array
        $aa_property = $this->getAaProperty($widget_properties);

        return $widget_only ? $widget->getOnlyHtml($aa_property, $content, $function) : $widget->getHtml($aa_property, $content, $function);
    }

    /**
     *  @return string[] - [relation,<slice_id>] or [constants,<constants_name>]
     */
    public function getRelation(): array
    {
        $showfunc = Widget::parseClassProperties($this->data['input_show_func']);
        if (!$showfunc['const']) {
            return [];
        }
        // prefix indicates select from items
        return (str_starts_with($showfunc['const'], "#sLiCe-")) ? ['relation', substr($showfunc['const'], 7)] : ['constants', (string) $showfunc['const']];
    }

    /** get ids of field to store thumbnails
     *  @return string[] - field_ids
     */
    public function getInsertOtherFields(): array
    {
        $inserter_params = AA_Inserter::parseClassProperties($this->data['input_insert_func']);
        if (array_key_exists('otherfield', $inserter_params) && trim($inserter_params['otherfield']) !== '') {
            return array_filter(array_map('trim', explode("##", $inserter_params['otherfield'])), 'strlen');
        }
        return [];
    }

    /** isMultiline - @return bool - if the default widget allows block elements  */
    public function isMultiline(): bool
    {
        $params = Widget::parseClassProperties($this->data['input_show_func']);
        return in_array($params['class'], ['AA\Widget\TxtWidget', 'AA\Widget\TprWidget', 'AA\Widget\EdtWidget']);
    }

    /** fields which use Manager Widget prevents using "Insert as new" itemedit action (it makes mess) - use duplicate item in the Itemmanager instead */
    public function disable_insert_as_new(): bool
    {
        return Widget::parseClassProperties($this->data['input_show_func'])['class'] === 'AA\Widget\MngWidget';
    }

    public function cloneWithId($id): AA_Field|static
    {
        $new = clone $this;
        $new->data['id'] = $id;
        return $new;
    }

    public function linkToField(string $text, string $fragment = '', array $attrs = [])
    {
        $url = get_admin_url("se_inputform.php", ['module_id' => $this->getSliceId(), 'fid' => $this->getId()]);
        if ($fragment) {
            $url .= '#alias' . $fragment;
        }
        return a_href($url, $text, $attrs);
    }

    /** prints admin row for the field in AA UI
     * @param bool $analyze
     */
    public function showFieldAdminRow(bool $analyze): string
    {
        $ret = '';
        $id          = $this->getId();
        $name        = $this->getName();
        $pri         = $this->getProperty('input_pri');
        $required    = $this->required();
        $show        = $this->getProperty('input_show');

        $aliases = [];
        $this->getProperty('alias1') && $aliases[] = [1, new AA_Alias($this->getProperty('alias1'), $this->getProperty('alias1_func'), $this->getProperty('id'), $this->getProperty('alias1_help'))];
        $this->getProperty('alias2') && $aliases[] = [2, new AA_Alias($this->getProperty('alias2'), $this->getProperty('alias2_func'), $this->getProperty('id'), $this->getProperty('alias2_help'))];
        $this->getProperty('alias3') && $aliases[] = [3, new AA_Alias($this->getProperty('alias3'), $this->getProperty('alias3_func'), $this->getProperty('id'), $this->getProperty('alias3_help'))];

        //$aliases     = array_filter([$this->getProperty('alias1'),$this->getProperty('alias2'),$this->getProperty('alias3')]);
        $separate    = (str_contains($this->getProperty('input_before'), '{formbreak'));
        $type        = $this->getProperty('in_item_tbl') ? "in_item_tbl" : "";

        $name = safe($name);
        $pri = safe($pri);

        $rowclass = ((str_starts_with($id, "alerts")) ? 'tabtxt_field_alerts' : '');
        if ($separate) {
            $rowclass .= ' separator';
        }
        $ret .= "<tr class=\"$rowclass\">
      <td><input type=\"Text\" name=\"name[$id]\" size=50 maxlength=254 value=\"$name\"></td>";
        $ret .= "<td>$id</td>";
        $ret .= "
        <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
        <td><input type=\"checkbox\" name=\"req[$id]\"" . ($required ? " checked" : "") . "></td>
        <td><input type=\"checkbox\" name=\"shw[$id]\"" . ($show ? " checked" : "") . "></td>";
        $ret .= "<td>" . $this->linkToField(_m("Edit"), '', ['class' => 'aa-button-edit']) . "</td>";
        if ($type === "in_item_tbl") {
            $ret .= "<td></td>";
        } else {
            $ret .= "<td><a class=aa-button-delete href=\"javascript:DeleteField('$id')\">" . _m("Delete") . "</a></td>";
        }

        $alias_list = implode(' ', array_map(
            function (array $arr) {
                [$pos, $a] = $arr;
                $attrs = ['data-fld' => $a->getField() . "|$pos", 'title' => substr($a->getFuncDef(), 0, 100)];
                $aname = $a->getAlias();
                if ($a->isMoveable()) {
                    $aname .= '<i class="ico move" title="' . _m('Move to another field') . '"></i>';
                    $attrs['class'][] = 'aa-moveable';
                }
                if ($a->isDisabled()) {
                    $attrs['class'][] = 'disabled';
                    $attrs['title'] = _m('disabled') . ': ' . $attrs['title'];
                }
                return $this->linkToField($aname, substr($a->getAlias(), 2), $attrs);
            },
            $aliases,
        ));
        $ret .= "<td class=\"aa-flds-aliases\" data-fld=\"$id\">$alias_list</td>";
        $ret .= "</tr>\n";

        if ($analyze && $type !== 'in_item_tbl') {
            $ret .= $this->getAnalysis();
        }
        return $ret;
    }

    // Array Access implementation is in the DataArrayAccessTrait  --------------------------
    /**
     */
    public function getAnalysis(): string
    {
        $slice_id    = $this->getSliceId();
        $id          = $this->getId();
        $text_stored = (bool) $this->getProperty('text_stored');

        $items       = DB_AA::count('item', [['item.slice_id', $slice_id, 'l']]);
        $items_field = DB_AA::select1('', 'SELECT count(*) as cnt, count(DISTINCT text) as cntval,  count(DISTINCT number) as cntnum, count(DISTINCT item_id) as cntitm  FROM content, item', [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $slice_id, 'l'],
            ['field_id', $id],
        ]);
        $text_rows   = DB_AA::count(['content','item'], [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $slice_id, 'l'],
            ['field_id', $id],
            ['(content.flag & ' . FLAG_TEXT_STORED . ')', 64, 'i'],
        ]);
        $num_rows    = DB_AA::count(['content','item'], [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $slice_id, 'l'],
            ['field_id', $id],
            ['(content.flag & ' . FLAG_TEXT_STORED . ')', 0, 'i'],
        ]);
        $num_html    = DB_AA::count(['content','item'], [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $slice_id, 'l'],
            ['field_id', $id],
            ['(content.flag & ' . FLAG_HTML . ')', 1, 'i'],
        ]);
        $empty       = DB_AA::count(['content','item'], [
            ['content.item_id', 'item.id', 'j'],
            ['item.slice_id', $slice_id, 'l'],
            ['field_id', $id],
            ['text', ''],
        ]);
        $empty_text  = ($items_field['cnt'] == $empty && $items > 0) ? "<b title=\"All empty - maybe you can delete the field\">Empty fields</b>" : "Empty fields";

        $text_vals = '';
        if ($items_field['cntval'] < 5) {
            $arr       = DB_AA::select('txt', 'SELECT `text`, SUBSTRING(text,1,12) as txt FROM content, item ', [['content.item_id', 'item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['field_id', $id]], null, ['text']);
            $text_vals = ' (' . implode(', ', array_map(static fn($str) => '"' . ((strlen($str) === 12) ? substr($str, 0, 11) . '…' : $str) . '"', $arr)) . ')';
        }
        $distinct_val  = $text_stored ? "<u title='field is marked as text stored'>text:$items_field[cntval]</u>$text_vals,num:$items_field[cntnum]"
                                        : "text:$items_field[cntval]$text_vals,<u title='field is marked as number stored'>num:$items_field[cntnum]</u>";
        // old: $ret .= "<tr><td colspan=8><small title=\"Items\">Items:$items</small> / <small title=\"Items with field\">Fields:$items_field[cnt]</small> / <small>$distinct_text</small> / <small title=\"Distinct Items with field\">Distinct Items with field:$items_field[cntitm]</small> / <small title=\"Text fields\">Text fields:$text_rows</small> / <small title=\"Numeric fields\">Numeric fields:$num_rows</small> / <small title=\"Empty fields\">$empty_text:$empty</small> / <small title=\"fields amrked as HTML\">$distinct_html</small></td></tr>";

        $indexes = '';
        if ($text_stored) {
            $arr = DB_AA::select(['number' => 'cnt'], 'SELECT `number`, COUNT(*) as cnt FROM content, item ', [['content.item_id', 'item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['field_id', $id], ['text', '', 'FILLED']], null, ['number']);
            $indexes = implode(', ', array_map(static fn($idx, $cnt) => "$idx ($cnt)", array_keys($arr), $arr));
        }

        $arr = ['Items'                     => $items,
            'Content values'            => $items_field['cnt'],
            'Distinct values'           => ($items_field['cntitm'] == $items && $items_field[$text_stored ? 'cntval' : 'cntnum'] == 1 && $items > 1) ? "<b title=\"All the values are the same - maybe you can delete the field\">Distinct: $distinct_val</b>" : $distinct_val,
            'Distinct items with field' => $items_field['cntitm'],
            'Text values'               => $text_rows,
            'Numeric values'            => $num_rows,
            'Empty fields'              => $empty_text . ":" . $empty,
            'Fields marked as HTML'     => ($items_field['cntitm'] == $items && $items == $num_html && $items > 1) ? "<b title=\"All values marked as HTML\">HTML</b>:$num_html" : "HTML:$num_html",
            'Indexes'                   => $indexes,
        ];
        return "<tr><td colspan=8>" . Txt::keyValTable($arr, true) . "</td></tr>";
    }
}

/** AA_Field defined by slice */
class AA_Field_Slicedef extends AA_Field
{
    public function __construct(zids $zid, string $sli_id)
    {

        $this->data = AA::Metabase()->getEmptyRowArray('field');  // preset all data values (empty)

        // $fid = $data->get_alias_subst('_#FLD_ID__'); now by parameter
        if (!($data = AA_Items::getItem($zid))) {
            return;
        }
        if (false === ($fnumber = $data->getval(FIELDID_DYNAMIC_ID))) {
            warn("No FIELDID_DYNAMIC_ID - source.......... filed defined in field definition slice");
            return;
        }
        $fid = AA_Fields::createFieldId('text', $fnumber);

        // tmp array for alias substitution
        $fld_eval = [];
        $fld_eval['_#FLD_NAME'] = (($fname = $data->get_alias_subst('_#FLD_NAME', 'AA_NULL')) === 'AA_NULL') ? $data->get_alias_subst('_#HEADLINE', $fid) : $fname;
        $fld_eval['_#FLD_PRI_'] = (int) $data->get_alias_subst('_#FLD_PRI_', 100) ?: 100;
        $fld_eval['_#FLD_HELP'] = $data->get_alias_subst('_#FLD_HELP', '');
        $fld_eval['_#FLD_DEFA'] = $data->get_alias_subst('_#FLD_DEFA', '');  // txt
        $fld_eval['_#FLD_REQ_'] = (int) $data->get_alias_subst('_#FLD_REQ_', 0) ? 1 : 0;
        $fld_eval['_#FLD_MULT'] = (int) $data->get_alias_subst('_#FLD_MULT', 0);  // 0-2
        $fld_eval['_#FLD_PARM'] = $data->get_alias_subst('_#FLD_PARM', '');
        $fld_eval['_#FLD_TYPE'] = $data->get_alias_subst('_#FLD_TYPE', 'fld') ?: 'fld';
        $fld_eval['_#FLD_VALI'] = $data->get_alias_subst('_#FLD_VALI', '');
        $fld_eval['_#FLD_INS_'] = $data->get_alias_subst('_#FLD_INS_', ($fld_eval['_#FLD_TYPE'] === 'fil') ? 'fil' : 'qte');
        $fld_eval['_#FLD_SHOW'] = (int) $data->get_alias_subst('_#FLD_SHOW', 1) ? 1 : 0;
        $fld_eval['_#FLD_STOR'] = (int) $data->get_alias_subst('_#FLD_STOR', 1) ? 1 : 0;

        $this->data['id']                = $fid;
        $this->data['name']              = $fld_eval['_#FLD_NAME'];
        $this->data['slice_id']          = pack_id($sli_id);
        $this->data['input_pri']         = $fld_eval['_#FLD_PRI_'];
        $this->data['input_help']        = $fld_eval['_#FLD_HELP'];
        $this->data['input_default']     = $fld_eval['_#FLD_DEFA'];
        $this->data['required']          = $fld_eval['_#FLD_REQ_'];
        $this->data['multiple']          = $fld_eval['_#FLD_MULT'];
        $this->data['input_show_func']   = '';
        $this->widget_params             = (array) json_decode($fld_eval['_#FLD_PARM'], true);
        // equivalent of: $params[$name] = $property->isMulti() ? json2asoc($value) : $value;  // const_arr for widget uses this, for example
        if (array_key_exists('const_arr', $this->widget_params) && is_string($this->widget_params['const_arr'])) {
            $this->widget_params['const_arr'] = json2asoc($this->widget_params['const_arr']);
        }
        $this->widget_params['class']    = Widget::constructClassName($fld_eval['_#FLD_TYPE']);

        $this->data['search_pri']        = $this->data['input_pri'];
        $this->data['html_default']      = 1;
        $this->data['input_validate']    = $fld_eval['_#FLD_VALI'];
        $this->data['input_insert_func'] = $fld_eval['_#FLD_INS_'];
        $this->data['input_show']        = $fld_eval['_#FLD_SHOW'];
        $this->data['text_stored']       = $fld_eval['_#FLD_STOR'];

        // we want to replace _#FLD_STOR as well as {_#FLD_STOR}
        $fld_eval_plus = [];
        foreach ($fld_eval as $k => $v) {
            $fld_eval_plus['{' . $k . '}'] = $v;
            $fld_eval_plus[$k] = $v;
        }

        $this->aliases = new AA_Aliases();
        $field_aliases = array_filter($data->getAliasNames(), static fn($name) => str_starts_with($name, '_#FLD_AN'));   // $filtered_array = array_filter($array, function ($element) use ($my_value) { return ($element != $my_value); } );
        foreach ($field_aliases as $al) {
            if (IsAlias($als = '_#' . $data->get_alias_subst($al, ''))) {
                // you can use _#FLD_* aliases in the alias definition - we simply replace it here
                if ($a_alias = $data->getAlias($av = str_replace('_#FLD_AN', '_#FLD_A_', $al))) {    // new and possibly better way to specify alias - no need to {packid:7B}@_#FLD_ID__:, {packid:7D}...
                    $this->aliases->addAlias(new AA_Alias($als, strtr($a_alias->getFuncDef(), $fld_eval_plus), $fid));
                } else {
                    // old variant - will be probably removed in future
                    $this->aliases->addAlias(new AA_Alias($data->get_alias_subst($als), 'f_t:' . $data->get_alias_subst(str_replace('_#FLD_AN', '_#FLD_AV', $al)), $fid));
                }
            }
        }
    }

    /** prints admin row for the field in AA UI
     * @param bool $analyze
     */
    public function showFieldAdminRow(bool $analyze): string
    {  // do nothing - this field is not editable this way (at least now)
        return '';
    }
}



class AA_Fields implements IteratorAggregate, Countable
{
    /** @var string[] list of text fields in fields table of database
     *  @todo grab it form AA_Metabase
     */
    public const FIELDS_TEXT = ["id", "type", "slice_id", "name", "input_help", "input_morehlp", "input_default",
        "input_show_func", "content_id", "search_type", "search_help",
        "search_before", "search_more_help", "alias1", "alias1_func", "alias1_help",
        "alias2", "alias2_func", "alias2_help", "alias3","alias3_func", "alias3_help",
        "input_before", "aditional", "input_validate", "input_insert_func", "in_item_tbl",
    ];

    /** @var string[] list of numeric fields in fields table of database
     *  @todo grab it form AA_Metabase
     */
    public const FIELDS_NUM = [ "input_pri", "required", "feed", "multiple",
        "search_pri", "search_show", "search_ft_show", "search_ft_default",
        "content_edit", "html_default", "html_show", "input_show", "text_stored",
    ];

    /**  @var AA_Field[] - array of object of AA_Field type */
    protected array $fields = [];

    /** id of slice/module ... for which the fields are used */
    protected string $master_id;

    /** collection - each id could have multiple fieldsets.
     *  In fact, we do not use this feature yet, it is just abstraction for
     *  "slice fields" - slice has two field sets - normal fields and
     *  "slice (setting) fields", where id of those fields begins with '_'
     */
    protected int $collection;

    /** Array of aliases - for caching purposes */
    protected ?array $aliases = null;

    protected bool $loaded_all = false;
    protected int $lazy_loaded_no = 0;

    /** AA_Fields function
     * @param string $master_id
     * @param int    $collection
     */
    public function __construct(string $master_id, int $collection = 0)
    {
        $this->master_id      = $master_id;
        $this->collection     = $collection;
    }

    /** use instead of constructor - there was multiple AA_Fields for the same slice - in getSearchFields() */
    public static function factoryFor(string $master_id, int $collection = 0): static
    {
        static $field_sets = [];
        $key = $master_id . '_' . $collection;
        if (!isset($field_sets[$key])) {
            $field_sets[$key] = new self($master_id, $collection);
        }
        return $field_sets[$key];
    }

    /** load function
     * @param string $fid
     */
    public function loadField(string $fid): void
    {
        if ($this->loaded_all || !is_null($this->fields[$fid] ?? null) || !AA_Field::isValidFieldId($fid)) {
            return;
        }

        if (++$this->lazy_loaded_no > 9) {   // if we are asking for more than 9 times, we should probably load all the fields
            $this->load();
            return;
        }

        if (str_starts_with($fid, 'text..') && is_long_id($fields_slice = AA_Slice::getModuleProperty($this->master_id, 'fieldslice'))) {
            $set = new AA_Set([$fields_slice], new AA_Condition(FIELDID_DYNAMIC_ID, '==', self::getFieldNo($fid)));     // FIELDID_DYNAMIC_ID = 'source..........'
            $zids = $set->query();
            $this->fields[$fid] = $zids->isEmpty() ? '' : new AA_Field_Slicedef($zids->zid(0), $this->master_id);
        } else {
            $this->fields[$fid] = AA_Field::factoryFromDb($fid, $this->master_id) ?? '';
        }
    }

    /** load all fields function  */
    public function load(): void
    {
        if ($this->loaded_all) {
            return;
        }
        $this->fields = [];
        $flds = implode(',', array_map(static fn($value) => "`$value`", AA_Field::USED_FIELDS));
        $a = DB_AA::select(['id' => []], "SELECT $flds FROM `field`", [['`slice_id`', $this->master_id, 'l'], ['`id`', '_', ($this->collection == 0) ? 'NOT BEGIN' : 'BEGIN']], ['input_pri']);
        foreach ($a as $fid => $data) {
            $this->fields[$fid] = new AA_Field($data);
        }
        if (is_long_id($fields_slice = AA_Slice::getModuleProperty($this->master_id, 'fieldslice'))) {
            $str2find = new CacheStr2find($fields_slice);
            $sf = AA::Pagecache()->cacheDb(['AA_Fields','loadFieldFromSlice'], [$fields_slice, $this->master_id], $str2find);
            // $sf = AA_Fields::loadFieldFromSlice($fields_slice, $this->master_id);
            $this->fields = array_merge($this->fields, $sf);
        }

        $this->loaded_all = true;
    }

    public static function loadFieldFromSlice($fields_slice, $actual_slice): array
    {
        $fields = [];
        $set = new AA_Set($fields_slice);
        $zids = $set->query();
        AA_Items::preload($zids);
        foreach ($zids as $zid) {
            $field = new AA_Field_Slicedef(new zids($zid), $actual_slice);
            $fields[$field->getId()] = $field;
        }
        return $fields;
    }


    /**
     * @return AA_Field|null - the field
     */
    public function getField(string $field_id): ?AA_Field
    {
        if (!AA_Field::isValidFieldId($field_id)) {
            return null;
        }
        $this->loadField($field_id);
        return $this->fields[$field_id] ?: null;  // if the field does not exist, it returns null - could be empty string, but we want to return null
    }

    /**
     * @param  string  $field_id
     *
     * @return bool - if the field exists
     */
    public function isField(string $field_id): bool
    {
        $this->loadField($field_id);
        return is_object($this->fields[$field_id]);
    }

    /** getProperty function
     *
     * @param  string  $field_id
     * @param          $property
     *
     * @return mixed|null
     */
    public function getProperty(string $field_id, $property)
    {
        $this->loadField($field_id);
        return is_object($this->fields[$field_id]) ? $this->fields[$field_id]->getProperty($property) : null;
    }

    /** getAliases function
     *
     * @param $additional
     * @param $type
     *
     * @return array|null
     */
    public function getAliases($additional = null, $type = ''): ?array
    {
        AA::$debug & 32 && AA::$dbg->tracestart('Fields->getAliases', $type . ' ' . count($this->fields)) ;

        if (!is_null($this->aliases)) {
            AA::$debug & 32 && AA::$dbg->traceend('Fields->getAliases', 'cache ' . count($this->aliases));
            return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
        }
        $this->load();

        $this->aliases = is_array($additional) ? $additional : [];

        //  Standard aliases
        $this->aliases["_#ID_COUNT"] = GetAliasDef("f_e:itemcount", "id..............", _m("number of found items"));
        $this->aliases["_#ITEMINDX"] = GetAliasDef("f_e:itemindex", "id..............", _m("index of item within whole listing (begins with 0)"));
        $this->aliases["_#PAGEINDX"] = GetAliasDef("f_e:pageindex", "id..............", _m("index of item within a page (it begins from 0 on each page listed by pagescroller)"));
        $this->aliases["_#GRP_INDX"] = GetAliasDef("f_e:groupindex", "id..............", _m("index of a group on page (it begins from 0 on each page)"));
        $this->aliases["_#IGRPINDX"] = GetAliasDef("f_e:itemgroupindex", "id..............", _m("index of item within a group on page (it begins from 0 on each group)"));
        $this->aliases["_#ITEM_ID_"] = GetAliasDef("f_1", "unpacked_id.....", _m("alias for Item ID"));
        $this->aliases["_#SITEM_ID"] = GetAliasDef("f_1", "short_id........", _m("alias for Short Item ID"));

        if ($type === 'justids') {  // it is enough for view of urls
            AA::$debug & 32 && AA::$dbg->traceend('Fields->getAliases', count($this->aliases) . ' ' . count($additional));
            // maybe we should make $this->aliases = null (to be recounted next time with all aliases), but there was no problem so far, so we left here quicker solution. Honza 2016-10-20
            return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
        }

        $this->aliases["_#EDITITEM"] = GetAliasDef("f_e", "id..............", _m("alias used on admin page index.php for itemedit url"));
        $this->aliases["_#ADD_ITEM"] = GetAliasDef("f_e:add", "id..............", _m("alias used on admin page index.php for itemedit url"));
        $this->aliases["_#EDITDISC"] = GetAliasDef("f_e:disc", "id..............", _m("Alias used on admin page index.php for edit discussion url"));
        $this->aliases["_#RSS_TITL"] = GetAliasDef("f_r", "SLICEtitle", _m("Title of Slice for RSS"));
        $this->aliases["_#RSS_LINK"] = GetAliasDef("f_r", "SLICElink", _m("Link to the Slice for RSS"));
        $this->aliases["_#RSS_DESC"] = GetAliasDef("f_r", "SLICEdesc", _m("Short description (owner and name) of slice for RSS"));
        $this->aliases["_#RSS_DATE"] = GetAliasDef("f_r", "SLICEdate", _m("Date RSS information is generated, in RSS date format"));
        $this->aliases["_#SLI_NAME"] = GetAliasDef("f_e:slice_info", "name", _m("Slice name"));

        $this->aliases["_#MLX_LANG"] = GetAliasDef("f_e:mlx_lang", MLX_CTRLIDFIELD, _m("Current MLX language"));
        $this->aliases["_#MLX_DIR_"] = GetAliasDef("f_e:mlx_dir", MLX_CTRLIDFIELD, _m("HTML markup direction tag (e.g. DIR=RTL)"));

        // database stored aliases
        foreach ($this->fields as $field) {
            $fals = $field->getAliases()->getArray();
            foreach ($fals as $key => $value) {   // converted from array_merge which was really slow on many (dynamic) fields
                $this->aliases[$key] = $value;
            }
        }

        AA::$debug & 32 && AA::$dbg->traceend('Fields->getAliases', count($this->aliases));
        return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
    }

    /** returns first field id of given field_type (category........, file............, headline........)
     * @param  string  $field_type
     * @return string
     */
    public function firstOfTypeFieldId(string $field_type = 'category'): string
    {
        $this->load();
        return ($keys = array_filter(array_keys($this->fields), static fn($key) => str_starts_with($key, $field_type))) ? min($keys) : '';
    }

    /** find group_id for constants of the slice
     * @param  string  $field_id
     * @return string|null
     */
    public function getCategoryGroup(string $field_id = ''): ?string
    {
        $fld = $this->getField($field_id ?: $this->firstOfTypeFieldId());
        $input_func_str = $fld?->getProperty('input_show_func');
        return is_string($input_func_str) ? Widget::parseClassProperties($input_func_str)['const'] : null;
    }

    public function getSafeFieldsArray(): array
    {
        $this->load();
        return array_keys(array_filter($this->fields, static fn($fld) => $fld->isSafeStored()));
    }

    /** getNameArray function */
    public function getNameArray(): array
    {
        $this->load();
        $ret = [];
        foreach ($this->fields as $fid => $fld) { // in priority order
            $ret[$fid] = $fld->getProperty('name');
        }
        return $ret;
    }

    /** getPriorityArray function
     *
     */
    public function getPriorityArray(): array
    {
        $this->load();
        return array_keys($this->fields);
    }

    /** getSearchArray function
     * @param int $related_cnt - return also $related_cnt related fields (we do not want it for recurrent call)
     * @return AA\Util\Searchfields
     */
    public function getSearchfields($related_cnt = 8): Searchfields
    {
        $this->load();
        $i = 0;
        $related = 0;
        $searchfields = new AA\Util\Searchfields();
        foreach ($this->fields as $field_id => $field) { // in priority order
            $field_name = $field->getProperty('name');
            [$field_type, $values_slice_or_const] = $field->getSearchType();
            if ($field_type === 'relation') {
                $field_type = 'constants';
                if ($related_cnt) {
                    $related_fields = self::factoryFor($values_slice_or_const)->getSearchfields(0);
                    $related += 1000;
                    $opt_group = $field_name;

                    // add "-- any text field --" search option
                    if ($related_fields) {
                        $searchfields->add('all_fields@' . $field_id, ' > ' . _m('-- any text field --'), 'all_fields@' . $field_id, 'text', '', $related++, 0, $opt_group);
                    }
                    $i = 0;
                    foreach ($related_fields as $r_fid => $r_fld) {
                        if ($r_fld['search_pri'] == 0) {
                            continue;
                        }
                        $r_fld['opt_group']   = $opt_group;
                        $r_fld['search_pri'] += $related;
                        $r_fld['order_pri']   = 0;           // ordering by remote field is not supported in QueryZids  //  if ($r_fld['order_pri']>0) { $r_fld['order_pri'] += $related; }
                        $r_fld['name']        = ' > ' . $r_fld['name'];
                        $field_expr           = $r_fid . '@' . $field_id;
                        $r_fld['field']       = $field_expr;
                        $searchfields->addArray($field_expr, $r_fld);
                        if (++$i > $related_cnt) {  // the number of fields in related search is limited to first $related_cnt (just not to be so long for some heavy related slices)
                            break;
                        }
                    }
                }
            }

            // we can hide the field, if we put in fields.search_pri=0
            $search_pri = ($field->getProperty('search_pri') ? ++$i : 0);
            //             $name,        $field,   $operators, $table, $search_pri, $order_pri
            $searchfields->add($field_id, $field_name, $field_id, $field_type, false, $search_pri, $search_pri);
        }

        // we are constructing something like:
        //   (case 1 - above - link from this slice)
        //     $fields['headline........@relation.......2']=array('search_pri'=>1,'name'=>'headline........@relation.......2', 'operators'=>'text');
        //   (case 2 - below - link from other slice to this one)
        //     $fields['headline........@de0cfa6d391d6365181855801925ffe6/relation.......2']=array('search_pri'=>1,'name'=>'headline........@de0cfa6d391d6365181855801925ffe6/relation.......2', 'operators'=>'text');

        if ($related_cnt) {

            // add all fields of slices which points to this slice
            // this query is slow, but hard to improve
            $related_fields = DB_AA::select([], 'SELECT id, name, LOWER(HEX(slice_id)) as sid FROM `field`', [['input_show_func','___:#sLiCe-' . $this->master_id . '%','LIKE']]);
            foreach ($related_fields as $rf_def) {
                $related_fields = self::factoryFor($rf_def['sid'])->getSearchfields(0);
                $related += 1000;
                $opt_group = (AA_Module::getModule($rf_def['sid'])?->getName() ?? '') . ' / ' . $rf_def['name'];   // raw name - getFrmSelectEasy() safe()s the optgroup label
                // add "-- any text field --" search option
                // This does not work - all_fields@5cae6644f58fe31c4e704e3887e18524/relation........ is probably not implemented, yet
                // if ($related_fields) {
                //     $ret['all_fields@' . $rf_def['sid'] . '/' . $rf_def['id']] = array('opt_group'=>$opt_group, 'search_pri'=> $related++, 'order_pri'=>0, 'name' => ' > ' . _m('-- any text field --'), 'field'=>'all_fields@' . $rf_def['sid'] . '/' . $rf_def['id']);
                // }
                $i = 0;
                foreach ($related_fields as $r_fid => $r_fld) {
                    if ($r_fld['search_pri'] == 0) {
                        continue;
                    }
                    $r_fld['opt_group'] = $opt_group;
                    $r_fld['search_pri'] += $related;
                    $r_fld['order_pri'] = 0;           // ordering by remote field is not supported in QueryZids  //  if ($r_fld['order_pri']>0) { $r_fld['order_pri'] += $related; }
                    $r_fld['name'] = ' > ' . $r_fld['name'];
                    $field_expr = $r_fid . '@' . $rf_def['sid'] . '/' . $rf_def['id'];
                    $r_fld['field'] = $field_expr;
                    $searchfields->addArray($field_expr, $r_fld);
                    if (++$i > 8) {  // the number of fields in related search is limited to forst 8 (just not to be so long for some heavy related slices)
                        break;
                    }
                }
            }
        }
        return $searchfields;
    }

    /** isSliceField function
     *  Returns true, if the passed field id looks like slice setting field
     *  "slice fields" are not used for items, but rather for slice setting.
     *  Such fields are destinguished by underscore on first letter of field_id
     * @param string $field_id
     * @return bool
     */
    public static function isSliceField($field_id): bool
    {
        return str_starts_with((string) $field_id, '_');
    }

    /** createFieldId function
     *  Create field id from type and number
     * @param string $ftype  field type - headline, abstract, ...
     * @param string $no     field number
     * @param string $id_type  '.' | '_'
     */
    public static function createFieldId($ftype, $no = "0", $id_type = '.'): string
    {
        $no = (int) $no;
        if ($no < 0 || $no > 99999) {   // maybe the upper boundary could be bigger?
            return '';
        }
        if ($no == 0) {
            $no = "";    // id for 0 is "xxxxx..........."
        }
        return $ftype . substr(str_pad($no, 16, $id_type, STR_PAD_LEFT), -(16 - strlen($ftype)));
    }

    /** Slices the Fields page (admin/se_fields.php) offers new fields from: AA_Core_Fields.., this slice
     *  and the slices where the user may change fields - the others would disclose their field settings
     *
     * @return string[] slice id => label
     */
    public static function templateSlices(string $slice_id): array
    {
        $core   = unpack_id('AA_Core_Fields..');
        $slices = AA_Module::getUserModules('S', PS_FIELDS);
        $name   = $slices[$slice_id] ?? AA_Slice::getModule($slice_id)?->getName() ?? '';
        unset($slices[$core], $slices[$slice_id]);
        return [$core => '* Action Aplication Core', $slice_id => '* ' . $name . _m(' (this)')] + $slices;
    }

    /** Stores the rows of the Fields page (admin/se_fields.php) - name, priority, required and show,
     *  keyed by field id. The New_Field row adds a copy of the template field $ftype ("<slice_id>-<field_id>")
     *  taken from one of $template_slices.
     *  The keys come from the request, so only existing fields of $slice_id are changed, other keys are ignored.
     *
     * @param string   $slice_id
     * @param array    $names           field id => name
     * @param array    $pri             field id => priority
     * @param array    $req             field id => required checkbox
     * @param array    $shw             field id => show checkbox
     * @param string   $ftype           template of the new field
     * @param bool     $slice_fields    the new field is a slice (setting) field
     * @param string[] $template_slices slices the template may come from - @see templateSlices()
     * @return string[] errors - nothing is stored, if the rows do not validate
     */
    public static function storeAdminRows(string $slice_id, array $names, array $pri, array $req, array $shw, string $ftype, bool $slice_fields, array $template_slices): array
    {
        $own  = DB_AA::select('id', 'SELECT `id` FROM `field`', [['slice_id', $slice_id, 'l']]);
        $rows = [];
        $err  = [];
        foreach ($names as $fid => $name) {
            $fid = (string) $fid;
            if (($fid !== 'New_Field') && !in_array($fid, $own, true)) {
                continue;
            }
            if (($fid === 'New_Field') && ($name === '')) {
                continue;
            }
            if (!is_string($name)) {
                $err['val'] = MsgErr(_m("Error in") . ' ' . _m("Field") . " ($fid)");
                break;
            }
            $prior = is_string($pri[$fid] ?? null) ? $pri[$fid] : '';
            if (!ValidateInput("val", _m("Field"), $name, $err, false, "text") || !ValidateInput("prior", _m("Priority"), $prior, $err, true, "number")) {
                break;
            }
            $rows[$fid] = ['name' => $name, 'input_pri' => (int) $prior, 'required' => empty($req[$fid]) ? 0 : 1, 'input_show' => empty($shw[$fid]) ? 0 : 1];
        }
        if ($err) {
            return $err;
        }

        foreach ($rows as $fid => $row) {
            if ($fid !== 'New_Field') {
                DB_AA::update('field', [['name', $row['name'], 's'], ['input_pri', $row['input_pri'], 'i'], ['required', $row['required'], 'i'], ['input_show', $row['input_show'], 'i']], [['id', $fid], ['slice_id', $slice_id, 'l']]);
                continue;
            }
            [$tpl_slice, $tpl_id] = array_pad(explode('-', $ftype, 2), 2, '');
            $template = in_array($tpl_slice, $template_slices, true) ? DB_AA::select1('', 'SELECT * FROM `field`', [['slice_id', $tpl_slice, 'l'], ['id', $tpl_id]]) : false;
            // a field bound to an item column (slice_id, status_code, ...) must stay the only one
            if (!$template || !in_array((string) ($template['in_item_tbl'] ?? ''), ['', '0'], true)) {
                $err['DB'] = MsgErr(_m("Can't copy field"));
                continue;
            }
            // AA_Core_Fields.. identifies its templates by bare type ('text'), user added templates by full id
            $ftype_base = ($slice_fields ? '_' : '') . self::getFieldType($tpl_id);
            $max = -1;
            foreach ($own as $own_fid) {
                if (str_starts_with($own_fid, $ftype_base)) {
                    $max = max($max, (int) self::getFieldNo($own_fid));
                }
            }
            $new_fid = self::createFieldId($ftype_base, $max + 1);
            if (!AA_Field::isValidFieldId($new_fid)) {
                $err['DB'] = MsgErr(_m("Can't copy field"));
                continue;
            }
            // unset template columns are empty, not the database defaults (text_stored defaults to 1)
            $data = [];
            foreach (self::FIELDS_TEXT as $col) {
                $data[$col] = (string) ($template[$col] ?? '');
            }
            foreach (self::FIELDS_NUM as $col) {
                $data[$col] = (int) ($template[$col] ?? 0);
            }
            $data = array_merge($data, $row, ['id' => $new_fid, 'slice_id' => $slice_id]);
            if (!AA::Metabase()->doInsert('field', $data)) {
                $err['DB'] = MsgErr(_m("Can't copy field"));
            }
        }
        return $err;
    }

    /** Experimental field - now used just for history records
     * @param $fid string
     */
    public static function createShortId(string $fid): string
    {
        return preg_replace("/\.+/", ".", $fid);
    }

    /** get field type from id (works also for AA_Core_Fields (without dots))
     *  - static class function
     * @param $id
     */
    public static function getFieldType($id): string
    {
        $id = ltrim($id, "_");  // slice (module) fields are prefixed by underscore
        $dot_pos = strpos($id, ".");
        return ($dot_pos === false) ? $id : substr($id, 0, $dot_pos);
    }

    /** get field number from id ('.', '0', '1', '12', ... )
     * @param $id
     * @return string
     */
    public static function getFieldNo($id): string
    {
        return substr(strrchr($id, '.'), 1);
    }

    /** Returns first field id of specified type from string array
     *  Good for external feeds, where we do not have AA_Fields object
     *  In most cases use firstOfTypeFieldId for AA_Fields object
     *
     * @param $fields_arr  []
     * @param $ftype
     *
     * @return string  - field id | empty string if not found
     */
    public static function getBaseFieldId($fields_arr, $ftype): string
    {
        if (!$fields_arr) {
            return '';
        }
        return ($flds = array_column(array_filter($fields_arr, static fn($fld) => str_starts_with($fld['id'], $ftype)), 'id')) ? min($flds) : '';
    }

    /** Converts real field id into field id as used in the AA form, like:
     *  post_date......1  ==>  post_date______1
     */
    public static function getVarFromFieldId($field_id): string
    {
        return str_replace('.', '_', $field_id);
    }

    /** Converts field id as used in the AA form to real field id, like:
     *  post_date______1  ==>  post_date......1
     */
    public static function getFieldIdFromVar(string $dirty_field_id): string
    {
        return str_replace(['__', '._'], '..', $dirty_field_id);
    }

    /** getFields4Select function
     * @param $slice_id
     * @param $slice_fields
     * @param $order
     * @param $add_empty
     * @return array
     */
    public static function getFields4Select($slice_id, $slice_fields = false, $order = 'name', $add_empty = false, $add_all = false): array
    {
        $where = [['slice_id',$slice_id,'l']];
        if ($slice_fields === 'all') {
            // all fields (item as well as slice fields) - no additional conditions
            //$slice_fields_where = '';
        } elseif (!$slice_fields) {
            // only item fields (not begins with underscore)
            $where[] = ['id', '\_%', 'NOT LIKE'];
        } else {
            // only slice fields (begins with underscore)
            $where[] = ['id', '\_%', 'LIKE'];
        }
        $lookup_fields = DB_AA::select(['id' => 'name'], 'SELECT `id`, `name` FROM `field`', $where, [$order]);
        if ($add_empty) {
            $lookup_fields = array_merge(['' => ' '], $lookup_fields);  // default - none
        }
        if ($add_all) {
            $lookup_fields['all_fields'] = _m('-- any text field --');
        }
        return $lookup_fields;
    }

    /** implements IteratorAggregate */
    public function getIterator(): \Traversable
    {
        $this->loaded_all || $this->load();
        return new \ArrayIterator($this->fields);
    }

    // ----- Countable interface --------------

    /** Count elements of an object (Countable interface)
     *  @link https://php.net/manual/en/countable.count.php
     *  @return int The custom count as an integer. The return value is cast to an integer.
     */
    public function count(): int
    {
        $this->loaded_all || $this->load();
        return count($this->fields);
    }
}
