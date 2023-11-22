<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:29
 */

namespace AA\Widget;
use AA;
use AA\FormArray;
use AA_Formatters;
use AA_Value;

/** GPS coords widget */
class GpsWidget extends Widget
{
    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */

    /** @inheritDoc */
    public static function name(): string {
        return _m('Multiple Text Field');   // widget name
    }

    public static function description(): string { return ''; }

    // inherited from \AA\Widget\Widget
    protected function requireLibs($aa_property, $winfo): array {
        return ['codemirror@5'];
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_editor) {
           $ret .= "\n  document.querySelectorAll(\"". join(',',$this->ids_4_editor) .'").forEach( function(el) { CodeMirror.fromTextArea(el, { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" });});';

           // clean the list - all textareas are converted
           $this->ids_4_editor = [];
        }
        return $ret;
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        $ret = Widget::propertiesShop(['show_buttons_txt', 'row_count', 'max_characters', 'width', 'rows']);
        $ret['rows']->setHelp(_m("if 1 (default), textfield used. for rows>1 textareas are used"))->setExample(1);
        return $ret;
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
        $required = $aa_property->isRequired() ? 'required' : '';

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);
        $attr_string = htmlattr($attrs);

        $row_count = (int)$this->getProperty('row_count', 2);

        $value = $content->getAaValue($aa_property->getId());

        $input_name = $base_name . "[0]";
        $input_id = FormArray::formName2Id($input_name);
        $input_value = safe($value->getValue());

        $widget = "<input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required>";
        $widget .= <<<'EOF'
  
                      <div class="input-box p-1 ukaz-mapu" style="display: none;">
                        <div class="map" style="height: 200px; width: 200px;"></div>
                      </div>

                      <div class="pt-1">
                                Najdi dle adresy / názvu: 
                          <input name="adresa4suggest_#ITEM_ID_" class="address4suggest" size=40>
                          <input type=hidden name="latsuggest_#ITEM_ID_">
                          <input type=hidden name="lonsuggest_#ITEM_ID_">
                        <br><input type=button name="copygps_#ITEM_ID_" value="Ulož" disabled onclick="document.querySelector('#aa-u{_#ITEM_ID_}-location_______1-0').value = document.querySelector('[name=latsuggest_#ITEM_ID_]').value; document.querySelector('#aa-u{_#ITEM_ID_}-location_______2-0').value = document.querySelector('[name=lonsuggest_#ITEM_ID_]').value;document.querySelector('#aa-u{_#ITEM_ID_}-location_______1-0').onchange();document.querySelector('#aa-u{_#ITEM_ID_}-location_______2-0').onchange();this.disabled=true;">
                    
                        <script>
                          // naseptavac
                          var inputEl_#ITEM_ID_ = document.querySelector(".map-widg-_#ITEM_ID_ .address4suggest");
                          var suggest_#ITEM_ID_ = new SMap.Suggest(inputEl_#ITEM_ID_);
                          var map_#ITEM_ID_ = L.map(document.querySelector(".map-widg-_#ITEM_ID_ .map"), {zoomSnap: 0 });
                          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                          }).addTo(map_#ITEM_ID_);
                          //var myMapIcon = L.icon({ iconUrl: '/img/vozejkmap-ico.png', iconSize: [44, 44], popupAnchor: [-22, -44] });
                          var markers_#ITEM_ID_ = [];
                    
                          suggest_#ITEM_ID_.addListener("suggest", function(suggestData) {
                            showOnMap(map_#ITEM_ID_, '_#ITEM_ID_', suggestData.data.latitude, suggestData.data.longitude, inputEl_#ITEM_ID_.value);
                            document.querySelector('[name=copygps_#ITEM_ID_]').disabled=false;;
                          });
                    
                                {ifset:{location.......1}{location.......2}:
                                    showOnMap(map_#ITEM_ID_, '_#ITEM_ID_', '{location.......1}', '{location.......2}', '');
                          }
                    
                        </script>  
                      </div>
EOF;


        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}