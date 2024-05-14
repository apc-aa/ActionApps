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

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['load_from_fld', 'map_shapes', 'multi_shape']);
    }

    /** returns if the widget handles shapes (line, polygon, ...) or just markers   */
    protected function usedShapes(): string {
        // if map_shapes contain another letter than M
        $shapes = strtoupper(trim($this->getProperty('map_shapes')));
        return strlen($shapes) ? $shapes : 'M';
    }

    // inherited from \AA\Widget\Widget
    protected function requireLibs($aa_property, $winfo): array {
        return ['leaflet', 'leaflet-draw', 'aa-fillform', 'https://api.mapy.cz/loader.js'];
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $js_id    = FormArray::formName2Id($winfo['last_input_name'], true);
        $val_id   = FormArray::formName2Id($winfo['last_input_name']);
        $drawComponent = '';
        $shapes        = $this->usedShapes();
        $multi_shape   = $this->getProperty('multi_shape', false);
        if ($shapes !== 'M') {
            $drawComponent = "
              const drawControl = new L.Control.Draw({
                 position: 'bottomright',
                 edit: { featureGroup: drawnItems$js_id, poly: { allowIntersection: false }, remove: false, edit: false },
                 draw: { ";
                        $drawComponent .= strpos($shapes, 'M') !== false ? 'marker: true'         : ', marker: false';
                        $drawComponent .= strpos($shapes, 'L') !== false ? ', polyline: true'     : ', polyline: false';
                        $drawComponent .= strpos($shapes, 'P') !== false ? ', polygon: true'      : ', polygon: false';
                        $drawComponent .= strpos($shapes, 'R') !== false ? ', rectangle: true'    : ', rectangle: false';
                        $drawComponent .= false                          ? ', circle: true'       : ', circle: false';        // circles are not supported by toGeoJSON()
                        $drawComponent .= false                          ? ', circlemarker: true' : ', circlemarker: false';
                        $drawComponent .= "}
                });
              map$js_id.addControl(drawControl);
            
              // Ensure only one shape is drawn at a time
              map$js_id.on(L.Draw.Event.CREATED, function(event) {";
            $drawComponent .= $multi_shape ? '' : "\ndrawnItems$js_id.clearLayers();";  // Clear existing layers
            $drawComponent .= "                
                const layer = event.layer;
                drawnItems$js_id.addLayer(layer);
                layer.editing.enable(); // Automatically enable editing
                layer.on('edit', function(event) {
                    AA_GpsPrepareResults('#coosuggest$js_id', '#copygps$js_id', drawnItems$js_id.toGeoJSON());
                });
                layer.on('drag', function(event) {
                    AA_GpsPrepareResults('#coosuggest$js_id', '#copygps$js_id', drawnItems$js_id.toGeoJSON());
                });
                AA_GpsPrepareResults('#coosuggest$js_id', '#copygps$js_id', drawnItems$js_id.toGeoJSON());
              });
              suggest$js_id.addListener('suggest', function(suggestData) {
                AA_GpsShowGeo(map$js_id, '$js_id', {'type':'Point','coordinates':[suggestData.data.longitude.toFixed(6), suggestData.data.latitude.toFixed(6)]}, inputEl$js_id.value, drawnItems$js_id);
              });
              if (valEl$js_id.value) {
                  AA_GpsShowGeo(map$js_id, '$js_id', valEl$js_id.value, '', drawnItems$js_id);
              }
              ";
        } else {
            $drawComponent = "
              suggest$js_id.addListener('suggest', function(suggestData) {
                AA_GpsShowMarker(map$js_id, '$js_id', suggestData.data.latitude.toFixed(6) , suggestData.data.longitude.toFixed(6), inputEl$js_id.value, drawnItems$js_id);
              });
              let match = valEl$js_id.value.match(/^([+-]?(\d*[.])?\d+)\s([+-]?(\d*[.])?\d+)$/);
              if (match) {
                  AA_GpsShowMarker(map$js_id, '$js_id', match[1], match[3], '', drawnItems$js_id);
              }
              ";
        }

        return /** @lang JavaScript */ <<<EOF
  const inputEl$js_id = AA_$('#adresa4suggest$js_id');
  const valEl$js_id = AA_$('#$val_id');
  const suggest$js_id = new SMap.Suggest(inputEl$js_id);
  const map$js_id = L.map('mapdiv$js_id', {zoomSnap: 0, zoomControl: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map$js_id);
  L.control.zoom({position: 'bottomleft'}).addTo(map$js_id);

  // Set up drawing controls
  const drawnItems$js_id = new L.FeatureGroup();
  map$js_id.addLayer(drawnItems$js_id);
  
  $drawComponent
  
EOF;
    }

    protected function requireCssCode($aa_property, $winfo): string  {
        /** @lang CSS */
        return '.aa-widget-gps input[type="button"][disabled] { background-color: #dfdfdf; cursor: default;} 
          .aa-widget-gps .smap-suggest .item { justify-content: initial; } 
          .aa-gps-content { display: flex; }
          .aa-gps-suggest-box { position: relative; flex: 1; margin: 2px 0 0 7px; }
          .aa-gps-suggest { position: absolute; top: -2px; right: 1px; z-index: 1100; }
          .aa-gps-map { width: 100%; }
          .aa-widget-gps input[type="button"] { padding: 5px 12px; margin: 0; }
          .aa-gps-use { position: absolute; top: 0; z-index: 1100; }
          .leaflet-draw-toolbar { display: flex; flex-direction: column; }
          .leaflet-draw-draw-marker   { order: 1; }
          .leaflet-draw-draw-polyline { order: 2; }
          .leaflet-draw-draw-polygon  { order: 3; }
          .aa-gps-container { container: aa_gps_container / inline-size; }
          @container aa_gps_container (max-width: 500px) { 
            .aa-gps-content { display: block; }
            .aa-gps-content > div:first-of-type > input { width: 98%; }
            .aa-gps-suggest-box { margin-left: 0; }
          }
          ';
    }

    protected function requireLibCode($aa_property, $winfo): string  {
        return /** @lang JavaScript */ <<<EOF
function AA_GpsShowGeo(map, itemid, geojsonData, title, drawnItems) {
    // Clear previous items if you wish to display only the latest GeoJSON
    drawnItems.clearLayers();
    
    // if geojsonData is string, convert to object
    if (typeof geojsonData === "string") {
      geojsonData = geojsonData.trim();
      // check if the value is in format "lat lon" or GeoJSON by regexp
      let match = geojsonData.match(/^([+-]?(\d*[.])?\d+)\s([+-]?(\d*[.])?\d+)$/);
      if (match) {
          geojsonData  = {"type":"Point","coordinates":[match[3], match[1]]};
      } else {
        try {
            geojsonData = JSON.parse(geojsonData);
        } catch (e) {
            console.error("Invalid GeoJSON data");
            return;
        }
      }
    }
    if (geojsonData) {
        L.geoJSON(geojsonData, {
            onEachFeature: function (feature, layer) {
                layer.on('click', function () {
                    layer.editing.enable();
                });
                layer.on("edit", function(event) {
                    AA_GpsPrepareResults(`#coosuggest\${itemid}`, `#copygps\${itemid}`, layer.toGeoJSON());
                });
                layer.on("drag", function(event) {
                    AA_GpsPrepareResults(`#coosuggest\${itemid}`, `#copygps\${itemid}`, layer.toGeoJSON());
                });
            }
        }).addTo(drawnItems);        
        
        // Fit map bounds to the added GeoJSON
        map.fitBounds(drawnItems.getBounds());
        AA_GpsPrepareResults(`#coosuggest\${itemid}`, `#copygps\${itemid}`, geojsonData);
    } else {
        console.error("Invalid or empty GeoJSON data");
    }
}

function AA_GpsShowMarker(map, itemid, lat, lon, title) {
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
         AA_GpsPrepareResults(`#coosuggest\${itemid}`, `#copygps\${itemid}`, {"type":"Point","coordinates":[marker.getLatLng().lng.toFixed(6), marker.getLatLng().lat.toFixed(6)]});
       }).addTo(map);
    }
    map.fitBounds(marker.getLatLng().toBounds(100)); // 50m radius
    AA_GpsPrepareResults(`#coosuggest\${itemid}`, `#copygps\${itemid}`, {"type":"Point","coordinates":[lon,lat]});
}

function AA_GpsPrepareResults(resultinput, usebutton, geojson) {
    AA_$(usebutton).disabled=false;
    AA_$(resultinput).value = AA_GeoJSON2String(geojson);
}
function AA_GpsUse(aainput, itemid, usebutton) {
    const el=AA_$(aainput); 
    el.value=AA_$(`#coosuggest\${itemid}`).value;
    el.dispatchEvent(new Event('change'));
    usebutton.disabled=true;
    // window[`drawnItems\${itemid}`]?.eachLayer(layer => layer.editing?.enabled() && layer.editing.disable());
}

function AA_GeoJSON2String(geojsonData) {
    if (geojsonData.type === "FeatureCollection" && geojsonData.features.length === 1) {
        geojsonData = geojsonData.features[0];
    }
    if (geojsonData.type === "Feature") {
        geojsonData = geojsonData.geometry;
    }

    // Check if the resulting or original data is a Point
    if (geojsonData.type === "Feature" && geojsonData.geometry && geojsonData.geometry.type === "Point") {
        return `\${geojsonData.geometry.coordinates[1]} \${geojsonData.geometry.coordinates[0]}`;
    } else if (geojsonData.type === "Point") {
        // Direct Point without being a Feature
        return `\${geojsonData.coordinates[1]} \${geojsonData.coordinates[0]}`;
    }

    // Return the modified or unmodified GeoJSON object
    return JSON.stringify(geojsonData);
}

Loader.load(null, {suggest: true}); // naseptavac
EOF;

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

        $use_string = _m("Use");

        /** @lang HTML */
        $widget = <<<EOF
                    <div class="aa-gps-container">
                     <div class="aa-gps-content">
                      <div><input $attr_string name="$input_name" id="$input_id" value="$input_value" $required></div>
                      <div class="aa-gps-suggest-box"  id="mapout$js_id">
                        <div class="aa-gps-suggest">
                          <input type=search id="adresa4suggest$js_id" placeholder="$msg_search">
                          $load_from_fld_btn
                          <input type=hidden id="coosuggest$js_id">
                        </div>
                        <div class="aa-gps-map" id="mapdiv$js_id" style="height: 200px;"></div>
                        <input class="aa-gps-use" type=button id="copygps$js_id" value="&larr; {$use_string}" disabled onclick="AA_GpsUse('#$input_id', '$js_id', this);">
                      </div>
                    </div>
                  </div>
EOF;
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }
}