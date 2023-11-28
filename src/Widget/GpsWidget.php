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
        return ['leaflet', 'aa-fillform'];
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
        return Widget::propertiesShop(['load_from_fld']);
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal'): array {
        $field_id = $aa_property->getId();
        $base_name = FormArray::getName4Form($field_id, $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $required = $aa_property->isRequired() ? 'required' : '';

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);
        $attr_string = htmlattr($attrs);

        $load_from_fld = $this->getProperty('load_from_fld', '');

        $value = $content->getAaValue($field_id);

        $input_name = $base_name . "[0]";
        $input_id = FormArray::formName2Id($input_name);
        $js_id    = FormArray::formName2Id($input_name, true);
        $input_value = safe($value->getValue());

        $widget = "<div><input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required></div>";
        $widget .= <<<EOF

        <style>
          input[type="button"][disabled] { background-color: #dfdfdf; cursor: default;} 
          .smap-suggest .item { justify-content: initial; } 
          .aa-stdwidget .aa-input {
            display: flex;
          }
          .aa-gps-suggest-box {
            position: relative;
            flex: 1;
            margin: 2px 0 0 7px;
          }
          .aa-gps-suggest {
            position: absolute;
            top: -2px;
            right: 1px;
            z-index: 1100;
          }
          .aa-gps-map {
            width: 100%;
          }
          .aa-stdwidget input[type="button"] {
            padding: 5px 12px;
            margin: 0;
          }
          .aa-gps-use{
            position: absolute;
            top: 0;
            z-index: 1100;
          }
        </style>
        
        <script src="https://api.mapy.cz/loader.js"></script>
        <script>Loader.load(null, {suggest: true});</script>
        <script>
          function showOnMap(map, itemid, lat, lon, title) {
            AA_$(`#coosuggest\${itemid}`).value = `\${lat} \${lon}`;
            map.setView([lat, lon], 15);
            
            // Check if marker already exists
            let marker = null;
            map.eachLayer((layer) => { if (layer instanceof L.Marker) {
                marker = layer;
            }});
            
            if (marker) {
               // If it does, just update its position
               marker.setLatLng([lat, lon]);
            } else {
               // If it doesn't, create a new marker
               marker = L.marker([lat, lon], {"title":title, draggable: true, autoPan: true}).on('dragend', (e) => {
                 AA_$(`#coosuggest\${itemid}`).value = `\${this.getLatLng().lat} \${this.getLatLng().lng}`;
                 AA_$(`#copygps\${itemid}`).disabled = false;
               }).addTo(map);
            }
            map.fitBounds(marker.getLatLng().toBounds(100)); // 50m radius  
          
        };
        </script>

  
                      <div class="aa-gps-suggest-box"  id="mapout$js_id">
                        <div class="aa-gps-suggest">
                          <input id="adresa4suggest$js_id" placeholder="Hledej">
                          <input type=button id="copyaddr$js_id" value="Load from address" onclick="AA_$('#adresa4suggest$js_id').value=getField('$load_from_fld');AA_$('#adresa4suggest$js_id').dispatchEvent(new Event('input'));">
                          <input type=hidden id="coosuggest$js_id">
                        </div>
                        <div class="aa-gps-map" id="mapdiv$js_id" style="height: 200px;"></div>
                        <input class="aa-gps-use" type=button id="copygps$js_id" value="&larr; Use" disabled onclick="const el=AA_$('#$input_id'); el.value=AA_$('#coosuggest$js_id').value;el.dispatchEvent(new Event('change'));this.disabled=true;">
                      </div>
                    
                      <script>
                          // naseptavac
                          var inputEl$js_id = AA_$('#adresa4suggest$js_id');
                          var suggest$js_id = new SMap.Suggest(inputEl$js_id);
                          var map$js_id = L.map(AA_$('#mapdiv$js_id'), {zoomSnap: 0, zoomControl: false });
                          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                          }).addTo(map$js_id);
                          L.control.zoom({position: 'bottomleft'}).addTo(map$js_id);
                          //var myMapIcon = L.icon({ iconUrl: '/img/vozejkmap-ico.png', iconSize: [44, 44], popupAnchor: [-22, -44] });
                          var markers$js_id = [];
                    
                          suggest$js_id.addListener("suggest", function(suggestData) {
                            showOnMap(map$js_id, '$js_id', suggestData.data.latitude, suggestData.data.longitude, inputEl$js_id.value);
                            AA_$('#copygps$js_id').disabled=false;
                          });
EOF;
        if ($input_value) { 
            [$lat, $lon] = explode(' ', trim($input_value));
            $widget .= "\n  showOnMap(map$js_id, '$js_id', '$lat', '$lon', '');";
        }
        $widget .= "\n  </script>";
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}