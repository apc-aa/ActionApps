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
use AA\Widget\Widget;


/** for AA_Generator_*   */
require_once __DIR__ . "/itemfunc.php";

class AA_Field implements ArrayAccess {

    /** associative array of field data as defined in field table
     *   (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored)
     */
    protected $data;

    /** associative array of aliases
     * @var AA_Aliases
     */
    protected $aliases = null;

    /** class and properties for slice defined fields (replaces input_show_func functionality)  */
    protected $widget_params = [];

    /** cache array of all used widgets for this field
     *  @var Widget[]
     */
    protected $widget = [];

    /** AA_Field function
     * @param array|AA_Item $data
     * @param string|null   $sli_id slice id
     */
    function __construct($data) {
        $this->data = $data;
    }

    /** storageColumn function
     *  @return string - the table and column, where the field is stored
     */
    function storageColumn() {
        return $this->data['in_item_tbl'] ?:  ($this->data['text_stored'] ? 'text' : 'number');
    }

    /** storageTable function
     *  @return string - the table and column, where the field is stored
     */
    function storageTable() {
        return $this->data['in_item_tbl'] ? 'item' : 'content';
    }

    /**
     * @return bool is this field protected for reading to ItemContent and for writing to it?
     */
    public function isSafeStored() : bool {
        // the content_edit is not yet used - we plan to make able the field to be secured
        return (($this->data['content_edit'] & FIELD_UNREADABLE) OR (AA_Fields::getFieldType($this->getId()) == 'secret'));     // @todo add also password
                                                                                                                                // @todo mark all secure and password fields as FIELD_UNREADABLE
    }

    /** getProperty function
     * @param string $property
     * @return mixed - field data
     */
    function getProperty(string $property) {
        return $this->data[$property];
    }

    /** getId
     * @return string - id of the field
     */
    function getId() {
        return $this->getProperty('id');
    }

    /** getName function
     * @return string - name of the field
     */
    function getName() {
        return $this->getProperty('name');
    }

    /** getSliceId function
     * @return string - long id of the slice of this field
     */
    function getSliceId() {
        return unpack_id($this->getProperty('slice_id'));
    }

    /** required function
     * @return boolean value if the field is required  (must be filled)
     */
    function required() {
        return (bool) $this->getProperty('required');
    }

    /** getWidget function
     * @param $widget_type - wi2|sel|...
     *                       used, when we want to use another widget, than the default one
     *                       usually not used
     * @param $properties - array of properties to redefine for $widget_type
     * @return Widget|mixed
     */
    function getWidget($widget_type=null, $properties= []) {
        $widget_hash = get_hash($widget_type,$properties);

        // just for cache. maybe it is not necessary...
        if ( !isset($this->widget[$widget_hash]) ) {

            // $this->widget = AA\Widget\Widget::factoryByString($widget_type ? $widget_type : $this->data['input_show_func']);
            $widget_definition_string = $this->data['input_show_func'];
            // legacy - in dates we used ' as delimiter some time in history of AA
            if ($widget_definition_string AND (strpos($widget_definition_string,'dte:')===0) AND strpos($widget_definition_string,"'")) {
                $widget_definition_string = str_replace("'",":",$widget_definition_string);
            }

            $params       = $widget_definition_string ? Widget::parseClassProperties($widget_definition_string) : $this->widget_params;
            $widget_class = $widget_type ? Widget::constructClassName($widget_type) : $params['class'];
            if (!class_exists($widget_class)) {
                if (!class_exists($widget_class = $params['class'])) {
                    $widget_class = 'AA\Widget\NulWidget';
                }
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


    /** getDefault function
     * @param bool $with_profile
     * @return AA_Value
     */
    function getDefault( $with_profile=false ) {
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
    function getDefaultFormatterFlag(): int {
        return ($this->data['html_default']>0) ? AA_Formatters::RAW : AA_Formatters::TEXT2HTML;    // RAW = 1 = FLAG_HTML
    }

    /**
     * @return string date|pwd|bool|text|int
     */
    function getType(): string {
        [$validate_type] = explode(":", $this->getProperty('input_validate'), 2);
        [$insert_type]   = explode(":", $this->getProperty('input_insert_func'), 2);
        if ($insert_type == 'pwd') {
            return 'pwd';
        } elseif ($validate_type == 'date') {
            return 'date';
        } elseif ($insert_type == 'boo') {
            return 'bool';
        } elseif ($this->getProperty('text_stored')) {  // not used from here down - it is possible to add more types - ids, ...
            return 'text';
        }
        return 'int';
    }


    /**
     * @param array $oldcontent4id
     * @param bool  $insert
     * @return AA_Value|null
     */
    function readValueFromOldtypeForm($oldcontent4id, bool $insert) {

        $fid = $this->getId();
        // "v" prefix - database field var
        $varname          = 'v'. unpack_id($fid);
        $htmlvarname      = $varname.'html';
        $presence_varname = $varname.'p';


        // GLOBAL - was used for default field value set by ValidateContent4Id()
        // $var = isset($_REQUEST[$varname]) ? $_REQUEST[$varname] : $GLOBALS[$varname];
        $type = $this->getType();

        $var = $_REQUEST[$varname];

        if ($type == 'pwd') {
            $change_var = $_REQUEST[$varname . 'a'];
            $retype_var = $_REQUEST[$varname . 'b'];
            $delete_var = $_REQUEST[$varname . 'd'];

            if ($change_var && ($change_var == $retype_var)) {
                $var = ParamImplode(['AA_PASSWD', $change_var]);
            } elseif ($delete_var) {
                $var = '';
            } elseif (!$insert) {
                // store the original password to use it in
                // insert_fnc_pwd when it is not changed
                // $$varname = $oldcontent4id[$pri_field_id][0]['value'];
                $var = ParamImplode(['AA_PASSWD_CRYPTED', $oldcontent4id[$fid][0]['value']]);
            } else {
                return null;
            }
            return new AA_Value($var, FLAG_HTML);
        } elseif ($type == 'date') {
            $datectrl = new datectrl($varname);
            if ($datectrl->isUpdatable()) {
                $datectrl->update();                   // updates datectrl
                return new AA_Value($datectrl->get_date(), FLAG_HTML);
            } elseif (isset($var)) {
                // hidden date field ('hid' widget)
                return new AA_Value($_REQUEST[$varname], FLAG_HTML);
            }
            return null;
        } elseif ($type == 'bool') {
            // $ret = $this->content[$fid] = new AA_Value(($var ? 1 : 0), FLAG_HTML);  // replaced by the line below - I think it is mistake: $this->content[$fid]
            return new AA_Value(($var ? 1 : 0), FLAG_HTML);
        } elseif( isset($var) ) {
            if (!is_array($var)) {
                $var = [0 => $var];
            }
            $flag = $this->getProperty('html_show') ? ($_REQUEST[$htmlvarname]=="h" ? FLAG_HTML : 0) : $this->getDefaultFormatterFlag();
            return new AA_Value($var, $flag);
        } elseif( $_REQUEST[$presence_varname]=='P' ) {           //  elseif (in_array($input_type, ['mse','wi2','iso','hco'])) {  // uses listbox - no value means reset content - never used
            return new AA_Value('', $this->getDefaultFormatterFlag());
        }
        return null;
    }

    /** getAliases function
     *
     */
    function getAliases() {
        // maybe filled here in previous call, or filled in constructor for item defined fields
        if ($this->aliases) {
            return $this->aliases->getArray();
        }
        $this->aliases = new AA_Aliases;
        $f = $this->data;

        // fld used in PrintAliasHelp to point to alias editing page
        $this->aliases->addAlias(AA_Alias::factory($f['alias1'], $f['alias1_func'], $f['id'], $f['alias1_help']));
        $this->aliases->addAlias(AA_Alias::factory($f['alias2'], $f['alias2_func'], $f['id'], $f['alias2_help']));
        $this->aliases->addAlias(AA_Alias::factory($f['alias3'], $f['alias3_func'], $f['id'], $f['alias3_help']));
        return $this->aliases->getArray();
    }

    /** getConstantGroup function
     * function finds group_id in field.input_show_func parameter
     */
    function getConstantGroup() {
        // does this field use constants? Isn't it slice?
        [$field_type, $field_add] = $this->getSearchType();
        return ( $field_type == 'constants' ) ? $field_add : false;
    }

    /** getRecord function
     *  @deprecated - for backward compatibility only
     */
    function getRecord() {
        return $this->data;
    }

    /** getSearchType function
     * @return array - [text | numeric | date | constants, [relation,<slice_id>], [constants,<constants_name>], [numconstants,<constants_name>]]
     */
    function getSearchType() {
        $field_type = 'numeric';
        $field_add  = '';
        if ($this->data['text_stored']) {
            $field_type = 'text';
        }
        if (substr($this->data['input_validate'],0,4)=='date') {
            $field_type = 'date';
        }
        $r = $this->getRelation();

        if (!empty($r)) {
            $field_add  = $r[1];
            $field_type = ($field_type == 'numeric') ? 'numconstants' : $r[0];
        }

        return [$field_type, $field_add];
    }

    /** getTranslations
     *  Returns array of two letters shortcuts for languages used in this slice for translations - array('en','cz','es')
     */
    function getTranslations() {
        return $this->isMultilingual() ? AA_Slice::getModule($this->getSliceId())->getTranslations() : [];
    }

    /**
     *  @return int bitfield of all shown formatters set for this field
     */
    function getFormatters(): int {
        return ($this->getProperty('html_show') ? AA_Formatters::STANDARD_FORMATTERS : 0);
    }

    /**
     *  @return bool if the field is multivalue (not multilingual - it is independent)
     */
    function isMultiple(): bool {
        return $this->getWidget()::multiple();
    }

    /**
     *  @return bool if the field is multilingual
     */
    function isMultilingual(): bool {
        return ($this->data['multiple'] & 2);
    }

    /** getAaProperty function
     * @param array $widget_properties ['multiple'=> , 'required'=> , 'name'=> , 'input_help'=> , 'html_show' => , 'html_default' => ]
     * @return AA_Property
     */
    function getAaProperty($widget_properties= []) {

        $translations = $this->getTranslations();

        $validator_string = $this->data['input_validate']; // ids for iso widget could be marked - prepended by x, y or z
        if ( (substr($validator_string,0,2)=='id') AND (substr($this->data['input_show_func'],0,3)=='iso') ) {
            $validator_string = 'markedid';
        }

        // AA_Property($id='', $name='', $type='text', $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_HTML, $perms=null, $default=null, $translations=null) {
        return new AA_Property(
            $this->getId(),
            $widget_properties['name'] ?? $this->getName(),
            $this->getProperty('in_item_tbl') ? AA::Metabase()->getFieldType('item',$this->getProperty('in_item_tbl')) : ($this->getProperty('text_stored') ? 'text' : 'int'),
            $widget_properties['multiple'] ?? $this->isMultiple(),
            false,                   // persistent @todo
            AA_Validate::factoryByString($validator_string), // null,
            isset($widget_properties['required']) ? (bool)$widget_properties['required'] : $this->required(),
            $widget_properties['input_help'] ?? $this->getProperty('input_help'),
            $widget_properties['input_morehlp'] ?? $this->getProperty('input_morehlp'),
            null,               // $example;
            isset($widget_properties['show_formatters']) ? (int)$widget_properties['show_formatters'] : $this->getFormatters(),
            isset($widget_properties['default_formatter']) ? (int)$widget_properties['default_formatter'] : $this->getDefaultFormatterFlag(),
            null,               // perms
            $widget_properties['default'] ?? $this->getDefault(),
            $translations
        );
    }



    /** getWidgetAjaxHtml function
     * @param $item_id
     * @param $required - redefine default settings of required
     * @param $function - js function to call after the update
     * @param $widget_type - wi2|sel|...
     *                       used, when we want to use another widget, than the default one
     * @param $widget_properties - array of properties to redefine for $widget_type - array('columns' => 1)
     * @return string
     */
    function getWidgetAjaxHtml($item_id, $required=null, $function=null, $widget_type=null, $widget_properties= []) {
        return $this->_getWidget('getAjaxHtml', $item_id, $required, $function, $widget_type, $widget_properties);
    }

    protected function _getWidget($type, $item_id, $required, $function, $widget_type, $widget_properties) {
        $widget      = $this->getWidget($widget_type, $widget_properties);
        $itemcontent = new ItemContent($item_id);
        $widget_properties['multiple'] =  $widget::multiple();
        $widget_properties['required'] =  $required;
        //$widget_properties['name'], $widget_properties['input_help'], ... could be set already in the $widget_properties array
        $aa_property = $this->getAaProperty($widget_properties);
        return $widget->$type($aa_property, $itemcontent, $function);
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
    function getWidgetNewHtml($required=null, $function=null, $widget_type=null, $widget_properties= [], $preset_value=null, $item_index=null, $widget_only=false) {
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

    /** getRelation function
     *  @return array - [relation,<slice_id>] or [constants,<constants_name>]
     */
    function getRelation() {
        $showfunc = Widget::parseClassProperties($this->data['input_show_func']);
        if (!$showfunc['const']) {
            return [];
        }
        // prefix indicates select from items
        return (substr($showfunc['const'],0,7) == "#sLiCe-") ? ['relation', substr($showfunc['const'],7)] : ['constants', $showfunc['const']];
    }

    /** isMultiline - @return bool - if the default widget allows block elements  */
    function isMultiline(): bool {
        $params = Widget::parseClassProperties($this->data['input_show_func']);
        return in_array($params['class'], ['AA\Widget\TxtWidget', 'AA\Widget\TprWidget', 'AA\Widget\EdtWidget']);
    }

    /** fields which use Manager Widget prevents using "Insert as new" itemedit action (it makes mess) - use duplicate item in the Itemmanager instead */
    function disable_insert_as_new(): bool {
        return Widget::parseClassProperties($this->data['input_show_func'])['class'] == 'AA\Widget\MngWidget';
    }

    function cloneWithId($id) {
        $new = clone $this;
        $new->data['id']=$id;
        return $new;
    }

    private function linkToField(string $text, string $field_id, string $fragment='', array $class=[]) {
        $url = con_url("./se_inputform.php", ['fid'=>$field_id], true);
        if ($fragment) {
            $url .= '#alias'.$fragment;
        }
        $classtxt = join(' ',$class);
        return "<a href=\"$url\" $classtxt>$text</a>";
    }

    /** prints admin row for the field in AA UI
     * @param bool $analyze
     */
    public function showFieldAdminRow(bool $analyze) {
        $slice_id    = $this->getSliceId();
        $id          = $this->getId();
        $name        = $this->getName();
        $pri         = $this->getProperty('input_pri');
        $required    = $this->required();
        $show        = $this->getProperty('input_show');
        $aliases     = array_filter([$this->getProperty('alias1'),$this->getProperty('alias2'),$this->getProperty('alias3')]);
        $separate    = (strpos($this->getProperty('input_before'),'{formbreak') !== false);
        $text_stored = $this->getProperty('text_stored');
        $type        = $this->getProperty('in_item_tbl') ? "in_item_tbl" : "";

        $name = safe($name); $pri=safe($pri);

        $rowclass = ((substr ($id,0,6) == "alerts") ? 'tabtxt_field_alerts' : '');
        if ( $separate ) {
            $rowclass .= ' separator';
        }
        echo "<tr class=\"$rowclass\">
      <td><input type=\"Text\" name=\"name[$id]\" size=50 maxlength=254 value=\"$name\"></td>";
        echo "<td>$id</td>";
        echo "
        <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
        <td><input type=\"checkbox\" name=\"req[$id]\"". ($required ? " checked" : "") ."></td>
        <td><input type=\"checkbox\" name=\"shw[$id]\"". ($show ? " checked" : "") ."></td>";
        echo "<td>".$this->linkToField( _m("Edit"), $id)."</td>";
        if ( $type=="in_item_tbl" ) {
            echo "<td>". _m("Delete") ."</td>";
        } else {
            echo "<td><a href=\"javascript:DeleteField('$id')\">". _m("Delete") ."</a></td>";
        }

        $alias_list = join(' ', array_map(
            function($a) use ($id) {
                return $this->linkToField($a, $id, substr($a,2), (substr($a,0,2)=='X#') ? ['disabled'] : []);
            }, $aliases));
        echo "<td class=\"aa-flds-aliases\">$alias_list</td>";
        echo "</tr>\n";

        if ($analyze AND ($type!='in_item_tbl')) {
            $items       = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM item', [['item.slice_id', $slice_id, 'l']]);
            $items_field = DB_AA::select1('', 'SELECT count(*) as cnt, count(DISTINCT text) as cntval,  count(DISTINCT number) as cntnum, count(DISTINCT item_id) as cntitm  FROM content, item', [
                ['content.item_id', 'item.id', 'j'],
                ['item.slice_id', $slice_id, 'l'],
                ['field_id', $id]
            ]);
            $text_rows   = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM content, item', [
                ['content.item_id', 'item.id', 'j'],
                ['item.slice_id', $slice_id, 'l'],
                ['field_id', $id],
                ['(content.flag & '. FLAG_TEXT_STORED .')', 64, 'i']
            ]);
            $num_rows    = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM content, item', [
                ['content.item_id', 'item.id', 'j'],
                ['item.slice_id', $slice_id, 'l'],
                ['field_id', $id],
                ['(content.flag & '. FLAG_TEXT_STORED .')', 0, 'i']
            ]);
            $num_html    = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM content, item', [
                ['content.item_id', 'item.id', 'j'],
                ['item.slice_id', $slice_id, 'l'],
                ['field_id', $id],
                ['(content.flag & '. FLAG_HTML .')', 1, 'i']
            ]);
            $empty       = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM content, item', [
                ['content.item_id', 'item.id', 'j'],
                ['item.slice_id', $slice_id, 'l'],
                ['field_id', $id],
                ['text', '']
            ]);
            $empty_text    = (($items_field['cnt']==$empty) AND ($items>0)) ? "<b title=\"All empty - maybe you can delete the field\">Empty fields</b>":"Empty fields";

            $text_vals = '';
            if ($items_field['cntval'] < 5) {
                $arr = DB_AA::select('txt', 'SELECT `text`, SUBSTRING(text,1,12) as txt FROM content, item '. DB_AA::makeWhere([['content.item_id','item.id', 'j'], ['item.slice_id',$slice_id, 'l'],['field_id',$id]]). ' GROUP BY `text`');
                $text_vals = ' ('. join(', ', array_map( function ($str) { return '"'. ((strlen($str) == 12) ? substr($str,0,11).'…' : $str). '"'; }, $arr)). ')';
            }
            $distinct_val  = ($text_stored == 1) ? "<u title='field is marked as text stored'>text:$items_field[cntval]</u>$text_vals,num:$items_field[cntnum]" : "text:$items_field[cntval]$text_vals,<u title='field is marked as number stored'>num:$items_field[cntnum]</u>";
            $distinct_text = (($items_field['cntitm']==$items) AND ($items_field[$text_stored==1 ? 'cntval':'cntnum']==1) AND ($items>1)) ? "<b title=\"All the values are the same - maybe you can delete the field\">Distinct values</b>:$distinct_val":"Distinct values:$distinct_val";
            $distinct_html = (($items_field['cntitm']==$items) AND ($items==$num_html) AND ($items>1)) ? "<b title=\"All values marked as HTML\">HTML</b>:$num_html" : "HTML:$num_html";
            echo "<tr><td colspan=8><small title=\"Items\">Items:$items</small> / <small title=\"Items with field\">Fields:$items_field[cnt]</small> / <small>$distinct_text</small> / <small title=\"Distinct Items with field\">Distinct Items with field:$items_field[cntitm]</small> / <small title=\"Text fields\">Text fields:$text_rows</small> / <small title=\"Numeric fields\">Numeric fields:$num_rows</small> / <small title=\"Empty fields\">$empty_text:$empty</small> / <small title=\"fields amrked as HTML\">$distinct_html</small></td></tr>";
        }
    }

    // Array Access implementation --------------------------
    /** implements ArrayAccess */
    public function offsetSet($offset, $value) {
        if ($offset) {
            $this->data[$offset] = $value;
        }
    }
    /** implements ArrayAccess */
    public function offsetExists($offset) {
        return isset($this->data[$offset]);
    }
    /** implements ArrayAccess */
    public function offsetUnset($offset) {
        unset($this->data[$offset]);
    }
    /** implements ArrayAccess */
    public function offsetGet($offset) {
        return $this->data[$offset] ?? null;
    }
}

/** AA_Field defined by slice */
class AA_Field_Slicedef extends AA_Field {

    function __construct(zids $zid, string $sli_id) {

        $this->data = AA::Metabase()->getEmptyRowArray('field');  // preset all data values (empty)

        // $fid = $data->get_alias_subst('_#FLD_ID__'); now by parameter
        if (!($data = AA_Items::getItem($zid))) {
            return;
        }
        if (false === ($fnumber = $data->getval(FIELDID_DYNAMIC_ID))) {
            warn("No FIELDID_DYNAMIC_ID - source.......... filed defined in field definition slice");
            return;
        }
        $fid = AA_Fields::createFieldId('text',$fnumber);

        $this->data['id'] = $fid;
        $this->data['name'] = (($fname = $data->get_alias_subst('_#FLD_NAME', 'AA_NULL')) == 'AA_NULL') ? $data->get_alias_subst('_#HEADLINE', $fid) : $fname;
        $this->data['slice_id'] = pack_id($sli_id);
        $this->data['input_pri'] = (int)$data->get_alias_subst('_#FLD_PRI_', 100) ?: 100;
        $this->data['input_help'] = $data->get_alias_subst('_#FLD_HELP', '');
        $this->data['input_default'] = $data->get_alias_subst('_#FLD_DEFA', '');  // txt
        $this->data['required'] = (int)$data->get_alias_subst('_#FLD_REQ_', 0) ? 1 : 0;
        $this->data['multiple'] = (int)$data->get_alias_subst('_#FLD_MULT', 0);  // 0-2
        $this->data['input_show_func'] = '';
        $this->widget_params = json_decode($data->get_alias_subst('_#FLD_PARM', ''), true);
        $this->widget_params['class'] = Widget::constructClassName($data->get_alias_subst('_#FLD_TYPE', 'fld') ?: 'fld');
        $this->data['search_pri'] = $this->data['input_pri'];
        $this->data['html_default'] = 1;
        $this->data['input_validate'] = $data->get_alias_subst('_#FLD_VALI', '');
        $this->data['input_insert_func'] = $data->get_alias_subst('_#FLD_INS_', 'qte');
        $this->data['input_show'] = (int)$data->get_alias_subst('_#FLD_SHOW', 1) ? 1 : 0;
        $this->data['text_stored'] = (int)$data->get_alias_subst('_#FLD_STOR', 1) ? 1 : 0;

        $this->aliases = new AA_Aliases;
        $field_aliases = array_filter($data->getAliasNames(), function ($name) { return (0 === strpos($name, '_#FLD_AN')); });   // $filtered_array = array_filter($array, function ($element) use ($my_value) { return ($element != $my_value); } );
        foreach ($field_aliases as $al) {
            if (IsAlias($als = '_#' . $data->get_alias_subst($al, ''))) {
                $this->aliases->addAlias(new AA_Alias($data->get_alias_subst($als), 'f_t:' . $data->get_alias_subst(str_replace('_#FLD_AN', '_#FLD_AV', $al)), $fid));
            }
        }
    }

    /** prints admin row for the field in AA UI
     * @param bool $analyze
     */
    public function showFieldAdminRow(bool $analyze) {  // do nothing - this field is not editable this way (at least now)
    }
}



class AA_Fields implements Iterator, Countable {

    /** @var string[] list of text fields in fields table of database
     *  @todo grab it form AA_Metabase
     */
    public const FIELDS_TEXT = ["id", "type", "slice_id", "name", "input_help", "input_morehlp", "input_default",
      "input_show_func", "content_id", "search_type", "search_help",
      "search_before", "search_more_help", "alias1", "alias1_func", "alias1_help",
      "alias2", "alias2_func", "alias2_help", "alias3","alias3_func", "alias3_help",
      "input_before", "aditional", "input_validate", "input_insert_func", "in_item_tbl"
    ];

    /** @var string[] list of numeric fields in fields table of database
     *  @todo grab it form AA_Metabase
     */
    public const FIELDS_NUM = [ "input_pri", "required", "feed", "multiple",
      "search_pri", "search_show", "search_ft_show", "search_ft_default",
      "content_edit", "html_default", "html_show", "input_show", "text_stored"
    ];

    /**  @var AA_Field[] - array of object of AA_Field type */
    var $fields;

    /** id of slice/module ... for which the fields are used */
    var $master_id;

    /** collection - each id could have multiple fieldsets.
     *  In fact we do not use this feature yet, it is just abstraction for
     *  "slice fields" - slice has two field sets - normal fields and
     *  "slice (setting) fields", where id of those fields begins with '_'
     */
    var $collection;

    /** Array of aliases - for caching purposes */
    var $aliases;

    protected $loaded_all;
    protected $lazy_loaded_no;

    /** AA_Fields function
     * @param $master_id
     * @param $collection
     */
    function __construct($master_id, $collection = 0) {
        $this->master_id      = $master_id;
        $this->fields         = [];
        $this->collection     = $collection;
        $this->aliases        = null;
        $this->loaded_all     = false;
        $this->lazy_loaded_no = 0;
    }

    /** load function
     * @param string $fid
     */
    function loadField(string $fid) {
        if ( $this->loaded_all or !is_null($this->fields[$fid]) ) {
            return;
        }

        if (++$this->lazy_loaded_no > 9) {   // if we are asking for more that 9 times, we should probably load all the fields
           $this->load();
           return;
        }

        if ( (strpos($fid,'text..') === 0) AND is_long_id($fields_slice = AA_Slice::getModuleProperty($this->master_id, 'fieldslice')) ) {
            $set = new AA_Set([$fields_slice], new AA_Condition(FIELDID_DYNAMIC_ID, '==', AA_Fields::getFieldNo($fid)));     // FIELDID_DYNAMIC_ID = 'source..........'
            $zids = $set->query();
            $this->fields[$fid] = $zids->count() ? new AA_Field_Slicedef($zids->zid(0), $this->master_id) : '';
        } else {
            $data = DB_AA::select1('', 'SELECT * FROM `field`', [['slice_id', $this->master_id, 'l'], ['id', $fid]]);
            $this->fields[$fid] = $data ? new AA_Field($data) : '';
        }
    }


    /** load all fields function  */
    function load() {
        if ( $this->loaded_all ) {
            return;
        }
        $this->fields = [];
        $a = DB_AA::select(['id'=> []], 'SELECT * FROM `field`', [['slice_id', $this->master_id, 'l'], ['id', '\_%', ($this->collection == 0) ? 'NOT LIKE' : 'LIKE']], ['input_pri']);
        foreach ($a as $fid => $data) {
            $this->fields[$fid] = new AA_Field($data);
        }

        if (is_long_id($fields_slice = AA_Slice::getModuleProperty($this->master_id, 'fieldslice'))) {
            $str2find = new CacheStr2find($fields_slice);
            $sf = AA::Pagecache()->cacheDb(['AA_Fields','loadFieldFromSlice'], [$fields_slice, $this->master_id], $str2find);
            $this->fields = array_merge($this->fields, $sf);
        }

        $this->loaded_all = true;
    }

    static function loadFieldFromSlice($fields_slice, $actual_slice) {
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


    /** getField function
     *  @return AA_Field - the field (copy - just because of syntax - it is not possible
     *  to return null in &function())
     * @param $field_id
     */
    function getField(string $field_id) {
        $this->loadField($field_id);
        return is_object($this->fields[$field_id]) ? $this->fields[$field_id] : null;
    }

    /**
     *  @return bool - if the field exists
     *  @param $field_id
     */
    function isField(string $field_id): bool {
        $this->loadField($field_id);
        return is_object($this->fields[$field_id]);
    }

    /** getProperty function
     * @param $field_id
     * @param $property
     * @return mixed|null
     */
    function getProperty(string $field_id, $property) {
        $this->loadField($field_id);
        return is_object($this->fields[$field_id]) ? $this->fields[$field_id]->getProperty($property) : null;
    }

    /** getAliases function
     * @param $additional
     * @param $type
     * @return array|null|string
     */
    function getAliases($additional='', $type='') {
        if ( !is_null($this->aliases) ) {
            return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
        }
        $this->load();

        $this->aliases = is_array($additional) ? $additional : [];

        //  Standard aliases
        $this->aliases["_#ID_COUNT"] = GetAliasDef( "f_e:itemcount",        "id..............", _m("number of found items"));
        $this->aliases["_#ITEMINDX"] = GetAliasDef( "f_e:itemindex",        "id..............", _m("index of item within whole listing (begins with 0)"));
        $this->aliases["_#PAGEINDX"] = GetAliasDef( "f_e:pageindex",        "id..............", _m("index of item within a page (it begins from 0 on each page listed by pagescroller)"));
        $this->aliases["_#GRP_INDX"] = GetAliasDef( "f_e:groupindex",       "id..............", _m("index of a group on page (it begins from 0 on each page)"));
        $this->aliases["_#IGRPINDX"] = GetAliasDef( "f_e:itemgroupindex",   "id..............", _m("index of item within a group on page (it begins from 0 on each group)"));
        $this->aliases["_#ITEM_ID_"] = GetAliasDef( "f_1",                  "unpacked_id.....", _m("alias for Item ID"));
        $this->aliases["_#SITEM_ID"] = GetAliasDef( "f_1",                  "short_id........", _m("alias for Short Item ID"));

        if ( $type == 'justids') {  // it is enough for view of urls
            // maybe we should make $this->aliases = null (to be recounted next time with all aliases), but there was no problem so far, so we left here quicker solution. Honza 2016-10-20
            return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
        }

        $this->aliases["_#EDITITEM"] = GetAliasDef(  "f_e",            "id..............", _m("alias used on admin page index.php3 for itemedit url"));
        $this->aliases["_#ADD_ITEM"] = GetAliasDef(  "f_e:add",        "id..............", _m("alias used on admin page index.php3 for itemedit url"));
        $this->aliases["_#EDITDISC"] = GetAliasDef(  "f_e:disc",       "id..............", _m("Alias used on admin page index.php3 for edit discussion url"));
        $this->aliases["_#RSS_TITL"] = GetAliasDef(  "f_r",            "SLICEtitle",       _m("Title of Slice for RSS"));
        $this->aliases["_#RSS_LINK"] = GetAliasDef(  "f_r",            "SLICElink",        _m("Link to the Slice for RSS"));
        $this->aliases["_#RSS_DESC"] = GetAliasDef(  "f_r",            "SLICEdesc",        _m("Short description (owner and name) of slice for RSS"));
        $this->aliases["_#RSS_DATE"] = GetAliasDef(  "f_r",            "SLICEdate",        _m("Date RSS information is generated, in RSS date format"));
        $this->aliases["_#SLI_NAME"] = GetAliasDef(  "f_e:slice_info", "name",             _m("Slice name"));

        $this->aliases["_#MLX_LANG"] = GetAliasDef(  "f_e:mlx_lang",   MLX_CTRLIDFIELD,             _m("Current MLX language"));
        $this->aliases["_#MLX_DIR_"] = GetAliasDef(  "f_e:mlx_dir",   MLX_CTRLIDFIELD,             _m("HTML markup direction tag (e.g. DIR=RTL)"));

        // database stored aliases
        foreach ($this->fields as $field) {
            $this->aliases = array_merge($this->aliases, $field->getAliases());
        }
        return is_array($additional) ? array_merge($additional, $this->aliases) : $this->aliases;
    }

    /** getCategoryFieldId function
     *  returns first field id of given field_type (category........, file............)
     * @param string $field_type
     * @return string
     */
    function getCategoryFieldId($field_type = 'category') {
        $this->load();
        $no = 10000;
        foreach ($this->fields as  $fid => $foo ) {
            if ( strpos($fid, $field_type) !== 0 ) {
                continue;
            }
            $last = AA_Fields::getFieldNo($fid);
            $no = min( $no, ( ($last=='') ? -1 : (integer)$last) );
        }
        if ($no==10000) {
            return '';
        }
        $no = ( ($no==-1) ? '.' : (string)$no);
        return AA_Fields::createFieldId($field_type, $no);
    }

    public function getSafeFieldsArray() {
        $this->load();
        $ret = [];
        foreach ( $this->fields as $fid => $fld ) {
            if ($fld->isSafeStored()) {
                $ret[] = $fid;
            }
        }
        return $ret;
    }

    /** getRecordArray function
     *  @deprecated - for backward compatibility only
     */
    function getRecordArray() {
        $this->load();
        $ret = [];
        foreach ( $this->fields as $fid => $fld ) { // in priority order
            $ret[$fid] = $fld->getRecord();
        }
        return $ret;
    }

    /** getNameArray function */
    function getNameArray(): array {
        $this->load();
        $ret = [];
        foreach ( $this->fields as $fid => $fld ) { // in priority order
            $ret[$fid] = $fld->getProperty('name');
        }
        return $ret;
    }

    /** getPriorityArray function
     *
     */
    function getPriorityArray() {
        $this->load();
        return array_keys($this->fields);
    }

    /** getSearchArray function
     * @param int $related_cnt - return also $related_cnt related fields (we do not want it for recurent call)
     * @return AA\Util\Searchfields
     */
    function getSearchfields($related_cnt=8) {
        $this->load();
        $i = 0;
        $related = 0;
        $searchfields = new AA\Util\Searchfields();
        foreach ( $this->fields as $field_id => $field ) { // in priority order
            $field_name = $field->getProperty('name');
            [$field_type, $values_slice_or_const] = $field->getSearchType();
            if ($field_type=='relation') {
                $field_type = 'constants';
                if ($related_cnt) {
                    $related_fields = (new AA_Fields($values_slice_or_const))->getSearchfields(0);
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
                        $r_fld['opt_group']  = $opt_group;
                        $r_fld['search_pri'] += $related;
                        $r_fld['order_pri'] = 0;           // ordering by remote field is not supported in QueryZids  //  if ($r_fld['order_pri']>0) { $r_fld['order_pri'] += $related; }
                        $r_fld['name'] = ' > ' . $r_fld['name'];
                        $field_expr = $r_fid . '@' . $field_id;
                        $r_fld['field']   = $field_expr;
                        $searchfields->addArray($field_expr, $r_fld);
                        if (++$i>$related_cnt) {  // the number of fields in related search is limited to first $related_cnt (just not to be so long for some heavy related slices)
                            break;
                        }
                    }
                }
            }

            // we can hide the field, if we put in fields.search_pri=0
            $search_pri = ($field->getProperty('search_pri') ? ++$i : 0 );
                               //             $name,        $field,   $operators, $table, $search_pri, $order_pri
            $searchfields->add($field_id, $field_name, $field_id, $field_type, false, $search_pri, $search_pri);
        }

        // we are constructing something like:
        //   (case 1 - above - link from this slice)
        //     $fields['headline........@relation.......2']=array('search_pri'=>1,'name'=>'headline........@relation.......2', 'operators'=>'text');
        //   (case 2 - below - link from other slice to this one)
        //     $fields['headline........@de0cfa6d391d6365181855801925ffe6/relation.......2']=array('search_pri'=>1,'name'=>'headline........@de0cfa6d391d6365181855801925ffe6/relation.......2', 'operators'=>'text');

        if ($related_cnt) {

            // add all fields of slices which points to this sice
            $related_fields = DB_AA::select([], 'SELECT id, name, LOWER(HEX(slice_id)) as sid FROM `field`', [['input_show_func','___:#sLiCe-' . $this->master_id . '%','LIKE']]);
            foreach ($related_fields as $rf_def) {
                $related_fields = (new AA_Fields($rf_def['sid']))->getSearchfields(0);
                $related += 1000;
                $opt_group = AA_Module::getModuleName($rf_def['sid']) . ' / ' . $rf_def['name'];
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
                    if (++$i>8) {  // the number of fields in related search is limited to forst 8 (just not to be so long for some heavy related slices)
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
     * @param $field_id
     * @return bool
     */
    static function isSliceField($field_id) {
        return $field_id AND ($field_id[0] == '_');
    }

    /** createFieldId function
     *  Create field id from type and number
     * @param        $ftype
     * @param string $no
     * @param string $id_type  '.' | '_'
     * @return string
     */
    static function createFieldId($ftype, $no="0", $id_type='.') {
        $no = (int)$no;
        if ($no<0 OR $no>99999) {   // maybe the upper boundary could be bigger?
            return '';
        }
        if ($no == 0) {
            $no = "";    // id for 0 is "xxxxx..........."
        }
        return $ftype. substr( str_pad($no, 16, $id_type, STR_PAD_LEFT), -(16-strlen($ftype)));
    }

    /** Experimental field - now used just for history records
     * @param $fid string
     * @return string
     */
    static function createShortId(string $fid) {
        return preg_replace("/\.+/", ".", $fid);
    }

    /** getFieldType function
     *  get field type from id (works also for AA_Core_Fields (without dots))
     *  - static class function
     * @param $id
     * @return bool|string
     */
    static function getFieldType($id) {
        $id = ltrim($id, "_");  // slice (module) fields are prefixed by underscore
        $dot_pos = strpos($id, ".");
        return ($dot_pos === false) ? $id : substr($id, 0, $dot_pos);
    }

    /** getFieldNo function
     *  get field number from id ('.', '0', '1', '12', ... )
     * @param $id
     * @return string
     */
    static function getFieldNo($id) {
        return (string)substr(strrchr($id,'.'), 1);
    }

    /** Converts real field id into field id as used in the AA form, like:
     *  post_date......1  ==>  post_date______1
     */
    public static function getVarFromFieldId($field_id) {
        return str_replace('.', '_', $field_id);
    }

    /** Converts field id as used in the AA form to real field id, like:
     *  post_date______1  ==>  post_date......1
     */
    public static function getFieldIdFromVar($dirty_field_id) {
        return str_replace('._', '..', str_replace('__', '..', $dirty_field_id));
    }

    /** getFields4Select function
     * @param $slice_id
     * @param $slice_fields
     * @param $order
     * @param $add_empty
     * @return array
     */
    static function getFields4Select($slice_id, $slice_fields = false, $order = 'name', $add_empty = false, $add_all=false) {
        $where = [['slice_id',$slice_id,'l']];
        if ($slice_fields == 'all') {
            // all fields (item as well as slice fields) - no additional conditions
            //$slice_fields_where = '';
        } elseif (!$slice_fields) {
            // only item fields (not begins with underscore)
            $where[] = ['id', '\_%', 'NOT LIKE'];
        } else {
            // only slice fields (begins with underscore)
            $where[] = ['id', '\_%', 'LIKE'];
        }
        $lookup_fields = DB_AA::select(['id'=>'name'], 'SELECT `id`, `name` FROM `field`', $where, [$order]);
        if ($add_empty) {
            $lookup_fields = array_merge([''=>' '], $lookup_fields);  // default - none
        }
        if ($add_all) {
            $lookup_fields['all_fields'] = _m('-- any text field --');
        }
        return $lookup_fields;
    }


    // ----- Iterator interface --------------

    /**
     * Rewind the Iterator to the first element
     * @link https://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     */
    public function rewind()  { $this->loaded_all || $this->load(); return reset($this->fields);                         }

    /** Return the current element
     *  @link https://php.net/manual/en/iterator.current.php
     *  @return AA_Field
     */
    public function current() { $this->loaded_all || $this->load(); return current($this->fields); }

    /**
     * Return the key of the current element
     * @link https://php.net/manual/en/iterator.key.php
     * @return string|null scalar on success, or null on failure.
     */
    public function key()     { $this->loaded_all || $this->load(); return key($this->fields);                }

    /** Move forward to next element
     *  @link https://php.net/manual/en/iterator.next.php
     *  @return void Any returned value is ignored.
     */
    public function next()    { $this->loaded_all || $this->load(); return next($this->fields);                   }

    /** Checks if current position is valid
     *  @link https://php.net/manual/en/iterator.valid.php
     *  @return bool The return value will be casted to boolean and then evaluated. Returns true on success or false on failure.
     */
    public function valid()   { $this->loaded_all || $this->load(); return (current($this->fields) !== false);    }

    // ----- Countable interface --------------

    /** Count elements of an object (Countable interface)
     *  @link https://php.net/manual/en/countable.count.php
     *  @return int The custom count as an integer. The return value is cast to an integer.
     */
    public function count()   { $this->loaded_all || $this->load(); return count($this->fields);                     }
}
