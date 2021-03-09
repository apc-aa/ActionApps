<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:35
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;
use zids;

/** Related Item Manager widget */
class RimWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Related Item Manager');   // widget name
    }

    /** returns if the widget handles multivalue or just single value   */
    function multiple()
    {
        return true;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'slice_field', 'show_buttons', 'item_template', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'filter_conds_rw']);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $base_name_add = $base_name . '[rim]';
        //$widget_add    = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        //$use_name     = $this->getProperty('use_name', false);
        //$height       = (int)$this->getProperty('height');
        //if ($height < 1) {
        //    $height = 400;
        //}

        $constgroup = $this->getProperty('const');
        if (!$constgroup OR (substr($constgroup, 0, 7) != "#sLiCe-")) {  // no constants or slice defined
            return;                           //  = array();
        }
        $sid = substr($constgroup, 7);

        // $restrict_zids could be removed from this check. Honza 2015-06-11
        // $filter_conds    = ($ignore_filters AND $restrict_zids) ? '' : $this->getProperty('filter_conds');
        // // filter_conds_rw - changeable conditions - could come as paremeter widget_properties
        // $filter_conds_rw = ($ignore_filters AND $restrict_zids) ? '' : $this->getProperty('filter_conds_rw');
        // $sort_by         = $this->getProperty('sort_by');
        // $slice_field     = $this->getProperty('slice_field');
        //
        // // if variable is for some item, then we can use _#ALIASES_ in conds
        // // and sort
        // if ( is_object($content) ) {
        //     $filter_conds    = $content->unalias($filter_conds);
        //     $filter_conds_rw = $content->unalias($filter_conds_rw);
        //     $sort_by         = $content->unalias($sort_by);
        // }

        // // "#sLiCe-" prefix indicates select from items
        // if ( substr($constgroup,0,7) == "#sLiCe-" ) {
        //
        //     $bin_filter                   = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
        //     $tag_prefix                   = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used
        //     $crypted_additional_slice_pwd = AA_Credentials::encrypt($this->getProperty('additional_slice_pwd'));
        //
        //     $sid              = substr($constgroup, 7);


        $input_name = $base_name_add . "[]";
        $widget = '<article class="aa-rim relactionitem flex">
                              <section class="itemgroup">';

        $def_field_id = $aa_property->getId();
        $def_slice_id = $content->getOwnerId();

        $options = $this->getFormattedOptions(null, new zids($content->getValuesArray($aa_property->getId()), 'l'));

        $actions2show = $this->getProperty('show_buttons', 'MRND');
        $actbuttons = [];
        $actbuttonsdown = [];
        if (strpos($actions2show, 'M') !== false) {
            $actbuttonsdown['M'] = '';
        }// @todo
        if (strpos($actions2show, 'R') !== false) {
            $actbuttonsdown['R'] = "<article><i class=\"ico insert\">add</i><input type=search value onkeyup=\"DisplayAaResponse('flt$input_id', 'Widget_Selection', {s:'$def_slice_id',f:'$def_field_id',q:this.value})\"></article>";
        }// @todo
        if (strpos($actions2show, 'N') !== false) {
            $actbuttonsdown['N'] = "<article><i class=\"ico new\" onclick=\"OpenWindowTop('" . Inputform_url(true, null, $sid, 'close_dialog', null, $input_name) . "');\">" . _m('new') . '</i></article>';
        }// @todo
        if (strpos($actions2show, 'D') !== false) {
            $actbuttons['D'] = '<article onclick="this.parentNode.parentNode.parentNode.removeChild(this.parentNode.parentNode)"><i class="ico delete">x</i></article>';
        }// @todo
        if (strpos($actions2show, 'E') !== false) {
            $actbuttons['E'] = '<article><i class="ico edit">edit</i></article>';
        }// @todo

        // if (strpos($actions,'N') !== false) {
        //     $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("New") ."' onclick=\"OpenWindowTop('". Inputform_url(true, null, $sid, 'close_dialog', null, $name) .  "');\">\n");
        // }
        // if (strpos($actions,'E') !== false) {
        //     $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Edit") ."' onclick=\"EditItemInPopup('". Inputform_url(false, null, $sid, 'close_dialog', null, $name) .  "', document.inputform['".$name."']);\">\n");
        // }

        $i = 0;
        foreach ($options as $k => $v) {
            $input_id = FormArray::formName2Id($input_name . "[$i]");
            ++$i;
            $widget .= '<article class="item">
                             <section class="iteminfo">
                               ' . $v . '
                             </section>
                             <section class="icogroup">
                               <input type="hidden" name="' . $input_name . '" id="' . $input_id . '" value="' . safe($k) . '">' .
                join("\n", array_values($actbuttons)) . '
                             </section>
                           </article>
                           ';
        }

        // default value - in order something is send when no chbox is checked
        $input_name = $base_name_add . "[def]";
        $input_id = FormArray::formName2Id($input_name);

        // <input type="text" id="finduser" name="finduser" onkeyup="PlanSearch($('#finduser').val()+$('#prispeluser').val(), ReloadUserTable,500)" placeholder="hledej...">

        $downbuttons = join("\n", array_values($actbuttonsdown));


        $widget .= <<<HTML
                       </section>
                       <footer>
                         <input type="hidden" name="$input_name" id="$input_id" value="">
                         $downbuttons
                       </footer>
                       <section class="itemselect" id=flt$input_id>
                       </section>
                     </article>
HTML;

        // <article><i class="ico megainsert">přetánout</i></article>
        // <article><i class="ico new">nový</i></article>

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /** function which offers filtered selections for current widget */
    function getFilterSelection($searchstring)
    {
        $options = $this->getFormattedOptions(null, false, $searchstring);
        $ret = '';
        foreach ($options as $k => $v) {
            $ret .= '<article class="item">
                             <section class="iteminfo">
                               ' . $v . '
                             </section>
                             <section class="icogroup">
                               <input type="hidden" name="" value="' . safe($k) . '"><article class="select" onclick="this.previousSibling.name=AA_up(this, \'.aa-widget\').getAttribute(\'data-aa-basename\')+\'[rim][]\'; AA_up(this, \'.aa-widget\').querySelector(\'.itemgroup\').appendChild(this.parentNode.parentNode);"><i class="ico select">+</i></article>
                               <article class="delete" onclick="this.parentNode.parentNode.parentNode.removeChild(this.parentNode.parentNode)"><i class="ico delete">x</i></article>
                             </section>
                           </article>
                           ';
        }
        return $ret;
    }

    public static function getValue($data4field): AA_Value
    {
        return MchWidget::getValue($data4field);
    }

    /** @inheritDoc */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}