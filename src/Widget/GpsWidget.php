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
        return ['leaflet', 'aa-fillform', 'https://api.mapy.cz/loader.js'];
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $js_id    = FormArray::formName2Id($winfo['last_input_name'], true);
        $val_id   = FormArray::formName2Id($winfo['last_input_name']);
        return /** @lang JavaScript */ <<<EOF
  var inputEl$js_id = AA_$('#adresa4suggest$js_id');
  var valEl$js_id = AA_$('#$val_id');
  var suggest$js_id = new SMap.Suggest(inputEl$js_id);
  var map$js_id = L.map(AA_$('#mapdiv$js_id'), {zoomSnap: 0, zoomControl: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map$js_id);
  L.control.zoom({position: 'bottomleft'}).addTo(map$js_id);
  var markers$js_id = [];
  suggest$js_id.addListener("suggest", function(suggestData) {
    AA_ShowOnMap(map$js_id, '$js_id', suggestData.data.latitude.toFixed(6), suggestData.data.longitude.toFixed(6), inputEl$js_id.value);
    AA_$('#copygps$js_id').disabled=false;
  });
  if (valEl$js_id.value) {
    var [lat, lon] = valEl$js_id.value.split(' ');
    AA_ShowOnMap(map$js_id, '$js_id', lat, lon, '');
  }
EOF;
    }

    protected function requireCssCode($aa_property, $winfo): string  {
        return '.aa-widget-gps input[type="button"][disabled] { background-color: #dfdfdf; cursor: default;} 
          .aa-widget-gps .smap-suggest .item { justify-content: initial; } 
          .aa-widget-gps .aa-input { display: flex; }
          .aa-gps-suggest-box { position: relative; flex: 1; margin: 2px 0 0 7px; }
          .aa-gps-suggest { position: absolute; top: -2px; right: 1px; z-index: 1100; }
          .aa-gps-map { width: 100%; }
          .aa-widget-gps input[type="button"] { padding: 5px 12px; margin: 0; }
          .aa-gps-use { position: absolute; top: 0; z-index: 1100; }
          @media (max-width: 810px) { 
            .aa-widget-gps .aa-input { display: block; }
            .aa-gps-suggest-box { margin-left: 0}
          }';
    }

    protected function requireLibCode($aa_property, $winfo): string  {
        return /** @lang JavaScript */ <<<EOF
function AA_ShowOnMap(map, itemid, lat, lon, title) {
    AA_$(`#coosuggest\${itemid}`).value = `\${lat} \${lon}`;
    map.setView([lat, lon], 15);
    
    let marker = null;
    map.eachLayer((layer) => { if (layer instanceof L.Marker) {
        marker = layer;
    }});
    
    if (marker) {
       marker.setLatLng([lat, lon]);
    } else {
       marker = L.marker([lat, lon], {"title":title, draggable: true, autoPan: true}).on('dragend', (e) => {
         AA_$(`#coosuggest\${itemid}`).value = `\${marker.getLatLng().lat.toFixed(6)} \${marker.getLatLng().lng.toFixed(6)}`;
         AA_$(`#copygps\${itemid}`).disabled = false;
       }).addTo(map);
    }
    map.fitBounds(marker.getLatLng().toBounds(100)); // 50m radius
}
Loader.load(null, {suggest: true}); // naseptavac
EOF;

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

        $value = $content->getAaValue($field_id);

        $input_name = $base_name . "[0]";
        $input_id = FormArray::formName2Id($input_name);
        $js_id    = FormArray::formName2Id($input_name, true);
        $input_value = safe($value->getValue());

        $msg_search = _m('Search');
        $load_from_fld_btn = ( $load_from_fld = $this->getProperty('load_from_fld', '') ) ? "<input type=button id=\"copyaddr$js_id\" value=\"" . _m('Load from address') . "\" onclick=\"AA_$('#adresa4suggest$js_id').value=getField('$load_from_fld');AA_$('#adresa4suggest$js_id').dispatchEvent(new Event('input'));\">" : '';

        $widget = "<div><input $attr_string name=\"$input_name\" id=\"$input_id\" value=\"$input_value\" $required></div>";
        $widget .= <<<EOF
                      <div class="aa-gps-suggest-box"  id="mapout$js_id">
                        <div class="aa-gps-suggest">
                          <input type=search id="adresa4suggest$js_id" placeholder="$msg_search">
                          $load_from_fld_btn
                          <input type=hidden id="coosuggest$js_id">
                        </div>
                        <div class="aa-gps-map" id="mapdiv$js_id" style="height: 200px;"></div>
                        <input class="aa-gps-use" type=button id="copygps$js_id" value="&larr; Use" disabled onclick="const el=AA_$('#$input_id'); el.value=AA_$('#coosuggest$js_id').value;el.dispatchEvent(new Event('change'));this.disabled=true;">
                      </div>
EOF;
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}