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
        return _m('GPS coordinates');   // widget name
    }

    public static function description(): string { return ''; }

    // inherited from \AA\Widget\Widget
    protected function requireLibs($aa_property, $winfo): array {
        return ['leaflet'];
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
        $js_id    = FormArray::formName2Id($input_name, true);
        $input_value = safe($value->getValue());

        $widget = "<input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required>";
        $widget .= <<<EOF

        <style> 
          input[type="button"][disabled] { background-color: #dfdfdf; cursor: default;} 
          .smap-suggest .item { justify-content: initial; }
        </style>
        
        <script src="https://api.mapy.cz/loader.js"></script>
        <script>Loader.load(null, {suggest: true});</script>
        <script>
          function showOnMap(map, itemid, lat, lon, title) {
            // vyber polozky z naseptavace
            document.getElementById('coosuggest'+itemid).value = lat + ' ' + lon;
            document.getElementById('mapout'+itemid).style.display='block';
            map.setView([lat, lon], 15);
            var markers = [];
            markers.push(L.marker([lat, lon], {"title":title, draggable: true, autoPan: true}).on('dragend', function (e) {
              document.getElementById('coosuggest'+itemid).value = this.getLatLng().lat + ' ' + this.getLatLng().lng;
              document.getElementById('copygps'+itemid).disabled=false;;
             })        
            );  //, icon: myMapIcon

            var group = new L.featureGroup(markers).addTo(map);
            map.fitBounds(group.getBounds());
        };
        </script>

  
                      <div class="input-box p-1"  id="mapout$js_id" style="display: none;">
                        <div id="mapdiv$js_id" style="height: 200px; width: 200px;"></div>
                      </div>

                      <div class="pt-1">
                                Najdi dle adresy / názvu: 
                          <input id="adresa4suggest$js_id" class="address4suggest" size=40>
                          <input type=hidden id="coosuggest$js_id">
                          <br><input type=button id="copygps$js_id" value="Ulož" disabled onclick="const el=document.getElementById('$input_id'); el.value=document.getElementById('coosuggest$js_id').value;el.onchange();this.disabled=true;">
                    
                        <script>
                          // naseptavac
                          var inputEl$js_id = document.getElementById("adresa4suggest$js_id");
                          var suggest$js_id = new SMap.Suggest(inputEl$js_id);
                          var map$js_id = L.map(document.getElementById("mapdiv$js_id"), {zoomSnap: 0 });
                          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                          }).addTo(map$js_id);
                          //var myMapIcon = L.icon({ iconUrl: '/img/vozejkmap-ico.png', iconSize: [44, 44], popupAnchor: [-22, -44] });
                          var markers$js_id = [];
                    
                          suggest$js_id.addListener("suggest", function(suggestData) {
                            showOnMap(map$js_id, '$js_id', suggestData.data.latitude, suggestData.data.longitude, inputEl$js_id.value);  // $js_id or  _#ITEM_ID_ ?
                            document.getElementById("$input_id").disabled=false;
                          });
EOF;
        if ($input_value) { 
            [$lat, $lon] = explode(' ', trim($input_value));
            $widget .= "\n  showOnMap('map$js_id', '$js_id', '$lat', '$lon', '');";
        }
        $widget .= "\n  </script>  
                     </div>";
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}