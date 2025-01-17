<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:35
 */

namespace AA\Widget;
use AA;
use AA\FormArray;
use AA_Content;
use AA_Property;
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
    public static function name(): string
    {
        return _m('Related Item Manager');   // widget name
    }

    protected function requireLibs($aa_property, $winfo): array {
        if (str_contains($this->getProperty('show_buttons', 'MRND'), 'M')) {
            return array_merge(['orderable'], parent::requireLibs($aa_property, $winfo));
        }
        return parent::requireLibs($aa_property, $winfo);
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        if (str_contains($this->getProperty('show_buttons', 'MRND'), 'M')) {
            return "document.querySelectorAll('.itemgroup.orderable').forEach(grp => { Sortable.create(grp, { handle: '.move', animation: 150, ghostClass: 'orderableghost' }); });";
        }
        return '';
    }

    protected function requireCssCode($aa_property, $winfo): string  {
        /** @lang CSS */
        return '.orderableghost  { opacity: 0.5; }';
    }

    protected function requireLibCode($aa_property, $winfo): string
    {
        return <<<JS
        function AA_RimAdd2Current(add_btn) {
            const widget = add_btn.closest('.aa-widget'); 
            widget.querySelector('.id2add').name=widget.getAttribute('data-aa-basename')+'[rim][]';
            const added = widget.querySelector('.itemgroup').appendChild(add_btn.closest('.item'));
            const btns_str = widget.querySelector('template').innerHTML.replaceAll('***_#id***', widget.querySelector('.id2add').value);
            const buttons = document.createRange().createContextualFragment(btns_str);            
            added.querySelector('.icogroup').appendChild(buttons);
        }
        function AA_RimUpdateId(base_id, sid, fid, item_id, insert) {
           AA_Response('Formatted_By_Widget', {"s":sid,"f":fid,"i":item_id,"b":base_id}, function(responseText) {
              const widget = document.getElementById("widget-" + base_id);
              if (widget) {
                  if (insert) {
                      widget.querySelector('.itemgroup').insertAdjacentHTML('beforeend', responseText);
                  } else {
                      widget.querySelector('.itemgroup').querySelector('.item:has(.icogroup input[value="'+item_id+'"])').outerHTML = responseText;
                  }
              }
           })
        }
        JS;
    }

    /** returns if the widget handles multivalue or just single value   */
    public static function multiple(): bool {
        return true;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'slice_field', 'show_buttons', 'item_template', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'filter_conds_rw']);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *
     * @param  AA_Property  $aa_property
     * @param  AA_Content   $content
     * @param  string       $type  normal|live|ajax
     *
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $base_name_add = $base_name . '[rim]';
        //$widget_add    = ($type === 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        //$use_name     = $this->getProperty('use_name', false);
        //$height       = (int)$this->getProperty('height');
        //if ($height < 1) {
        //    $height = 400;
        //}

        $constgroup = $this->getProperty('const');
        if (!$constgroup OR (!str_starts_with($constgroup, "#sLiCe-"))) {  // no constants or slice defined
            return [];                           //  = array();
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
        // if ( substr($constgroup,0,7) === "#sLiCe-" ) {
        //
        //     $bin_filter                   = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
        //     $tag_prefix                   = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used
        //     $crypted_additional_slice_pwd = AA_Credentials::encrypt($this->getProperty('additional_slice_pwd'));
        //
        //     $sid              = substr($constgroup, 7);


        $input_name = $base_name_add . "[]";
        $actions2show = $this->getProperty('show_buttons', 'MRND');

        $widget = '<article class="aa-rim relactionitem flex">
                              <section class="itemgroup'.(str_contains($actions2show, 'M') ? ' orderable' : '').'">';

        $def_field_id = $aa_property->getId();
        $def_slice_id = $content->getOwnerId();

        $buttons = $this->getButtons($base_id, $def_field_id, $def_slice_id);
        $downbuttons = $this->getButtonsDown($aa_property, $content, $base_id, $sid, $def_field_id, $def_slice_id);

        $widget = "<template>$buttons</template>".$widget;

        // if (strpos($actions,'N') !== false) {
        //     $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("New") ."' onclick=\"OpenWindowTop('". Inputform_url(true, null, $sid, 'close_dialog', null, $name) .  "');\">\n");
        // }
        // if (strpos($actions,'E') !== false) {
        //     $this->echoo("&nbsp;&nbsp;<input type='button' value='". _m("Edit") ."' onclick=\"EditItemInPopup('". Inputform_url(false, null, $sid, 'close_dialog', null, $name) .  "', document.inputform['".$name."']);\">\n");
        // }

        $widget .= $this->getRelatedItemsHtml(new zids($content->getValuesArray($aa_property->getId()), 'l'), $base_id, $def_field_id, $def_slice_id);

        // default value - in order something is sent when no chbox is checked
        $input_name = $base_name_add . "[def]";
        $input_id = FormArray::formName2Id($input_name);


        $widget .= <<<HTML
                       </section>
                       <footer>
                         <input type="hidden" name="$input_name" id="$input_id" value="">
                         $downbuttons
                       </footer>
                       <section class="itemselect" id=flt$base_id>
                       </section>
                     </article>
                   HTML;

        // <article><i class="ico megainsert">přetánout</i></article>
        // <article><i class="ico new">nový</i></article>

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     */
    public function getButtons(string $base_id, string $def_field_id, string $def_slice_id): string
    {
        $actbuttons   = [];
        $actions2show = $this->getProperty('show_buttons', 'MRND');
        if (str_contains($actions2show, 'M')) {
            $actbuttons['M'] = '<article><i class="ico move"></i></article>';
        }// @todo
        if (str_contains($actions2show, 'D')) {
            $actbuttons['D'] = '<article onclick="this.closest(\'.item\').remove()"><i class="ico delete">&#215;</i></article>';
        }// @todo
        if (str_contains($actions2show, 'E')) {
            $actbuttons['E'] = "<article onclick=\"OpenWindowTop('itemedit.php?edit=1&id=***_#id***&encap=false&return_url=close_dialog&openervar=/rim/$def_slice_id/$def_field_id/$base_id');\"><i class=\"ico edit\">"._m('edit').'</i></article>';
        }// @todo
        return implode("\n", array_values($actbuttons));
    }


    public function getButtonsDown(AA_Property $aa_property, AA_Content $content, string $base_id, string $sid, string $def_field_id, string $def_slice_id): string
    {
        $actions2show = $this->getProperty('show_buttons', 'MRND');

        $actbuttonsdown = [];
        if (str_contains($actions2show, 'R')) {
            $actbuttonsdown['R'] = "<article><!--i class=\"ico insert\">add</i--><input type=search placeholder='"._m('search in existing')
                ."' value onkeyup=\"DisplayAaResponse('flt$base_id', 'Widget_Selection', {s:'$def_slice_id',f:'$def_field_id',q:this.value})\"></article>";
        }
        if (str_contains($actions2show, 'N')) {
            $actbuttonsdown['N'] = "<article><i class=\"ico new\" onclick=\"OpenWindowTop('".Inputform_url(true, null, $sid, 'close_dialog', null, "/rim/$def_slice_id/$def_field_id/$base_id")."');\">"._m('new').'</i></article>';
        }
        if (str_contains($actions2show, 'F')) {

            $hash = get_short_hash($base_id);

            $input_name =  "aa[f_".$sid."][file][fil][var]";  // we are making it compatible with widget to easier file upload handling (rearangeAaFilesArray())
//              fallback would be nice, but it uses <form>, so we sometimes endup with <form> inside another <form>, which is not allowed. It is solveable (by dynamic form at the and of page...)
//              $inner_code .= "<article class=\"item\" id=\"fileup$base_id\"><form action=\".\"><input name=\"aa[all][$field][0]\" type=\"hidden\" value=\"$item_id\" /><div class=\"fallback\"><input name=\"".$input_name."[]\" type=\"file\" multiple /></div></form>".$mode_arr['F']."</article>";
            $actbuttonsdown['F'] = "<article class=\"item\" id=\"fileup$hash\">"._m('drop files here or click to upload')."</article>";
            AA::Stringexpander()->addRequire('dropzone');

            $filerurl = AA_INSTAL_URL.'filler.php';
            $script2run = <<<JS
                    function AA_UploadInitRim(fu_id, baseid, sid, fid) {
                        const aaDz = new Dropzone('#'+fu_id, { url: "$filerurl", uploadMultiple: true, params: { inline:1 } });
                        aaDz.on("completemultiple", function(files) {
                            Object.keys(JSON.parse(files[0].xhr.response)).forEach(k => /^[a-f0-9]{32}$/i.test(k) && AA_RimUpdateId(baseid, sid, fid, k, true));
                            files.forEach(f => aaDz.removeFile(f));
                            //aaDz.removeAllFiles(); // not working - removes also not uploaded. Upload probably in chunks?
                        });
                    };
                    JS;
            AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');

            $dz_setup_code = "Dropzone.options.fileup$hash = { paramName: '$input_name' }; AA_UploadInitRim('fileup$hash', '$base_id', '$def_slice_id', '$def_field_id');\n";
            if (!IsAjaxCall()) {  // this chack is copied from stringexpand Manager - not sure if it is needed here
                $dz_setup_code = "document.addEventListener('DOMContentLoaded', function() { $dz_setup_code });";
            }
        }

        return "<script> $dz_setup_code </script>". implode("\n", array_values($actbuttonsdown));
    }


    /** get HTML for one or more related items from another slice based on this widget configuration  */
    public function getRelatedItemsHtml(zids $zids, string $base_id, string $def_field_id, string $def_slice_id): string {
        $input_name = FormArray::formId2Name($base_id)."[rim][]";

        $buttons = $this->getButtons($base_id, $def_field_id, $def_slice_id);
        $ret = '';
        $options = $this->getFormattedOptions(null, $zids);
        foreach ($options as $id => $html) {
            $id  = safe($id);
            $btn = str_replace('***_#id***', $id, $buttons);
            $ret .= <<<HTML
                       <article class="item">
                           <section class="iteminfo"> $html </section>
                           <section class="icogroup">
                               <input type="hidden" name="$input_name" value="$id">
                               $btn
                           </section>
                       </article>
                    HTML;
        }
        return $ret;
    }

    /** function which offers filtered selections for current widget */
    function getFilterSelection($searchstring): string {
        $options = $this->getFormattedOptions(null, false, $searchstring);
        $ret = '';
        foreach ($options as $k => $v) {
            $ret .= '<article class="item">
                             <section class="iteminfo">
                               ' . $v . '
                             </section>
                             <section class="icogroup">
                               <input type="hidden" name="" value="' . safe($k) . '" class="id2add"><article class="select" onclick="AA_RimAdd2Current(this)"><i class="ico select">+</i></article>
                             </section>
                           </article>
                           ';
        }
        return $ret;
    }

    public static function getWidgetValue(array $data4field): AA_Value
    {
        $data4field['mch'] = $data4field['rim'];
        unset($data4field['rim']);
        return MchWidget::getWidgetValue($data4field);
    }

    /** @inheritDoc */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}