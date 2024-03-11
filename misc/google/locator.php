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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

/** APC-AA configuration file */

use AA\Util\Langs;

require_once __DIR__."/../../include/config.php";
/** APC-AA constant definitions */
require_once __DIR__ . "/../../include/constants.php";
require_once __DIR__ . "/../../include/mgettext.php";
require_once __DIR__ . "/../../include/locsess.php";

mgettext_bind(Langs::getLang($lang_file), 'news');

/** Set of useful functions used on most pages */
require_once __DIR__ . "/../../include/util.php";

bind_mgettext_domain(DEFAULT_LANG_INCLUDE);

HtmlPageBegin();

//if (!$_GET['key']) {
//    echo 'Error: No key specified - you have to obtain <a href="http://code.google.com/apis/maps/signup.html">Google Map key</a> and pass it as locator.php?key= parameter';
//    exit;
//}
?>

<h1>Google Map Locator</h1>

<script src="//www.google.com/jsapi"></script>
<script src="//maps.google.com/maps?file=api&amp;v=2&amp;key=<?php echo $_GET['key']; ?>"></script>
<script>

function mapload() {
    if (GBrowserIsCompatible()) {
        const map = new GMap2(document.getElementById("map"));
        map.addControl(new GSmallMapControl());
        map.addControl(new GMapTypeControl());
        const center = new GLatLng(49.94815, 15.26761);
        map.setCenter(center, 7);
        geocoder = new GClientGeocoder();  // global variable
        const marker = new GMarker(center, {draggable: true});
        map.addOverlay(marker);
        document.getElementById("lat").innerHTML = center.lat().toFixed(5);
        document.getElementById("lng").innerHTML = center.lng().toFixed(5);

        GEvent.addListener(marker, "dragend", function() {
            var point = marker.getPoint();
            map.panTo(point);
            document.getElementById("lat").innerHTML = point.lat().toFixed(5);
            document.getElementById("lng").innerHTML = point.lng().toFixed(5);

        });

        GEvent.addListener(map, "moveend", function() {
            map.clearOverlays();
            const center = map.getCenter();
            const marker = new GMarker(center, {draggable: true});
            map.addOverlay(marker);
            document.getElementById("lat").innerHTML = center.lat().toFixed(5);
            document.getElementById("lng").innerHTML = center.lng().toFixed(5);

            GEvent.addListener(marker, "dragend", function() {
                const point = marker.getPoint();
                map.panTo(point);
                document.getElementById("lat").innerHTML = point.lat().toFixed(5);
                document.getElementById("lng").innerHTML = point.lng().toFixed(5);

            });
        });
    }
}

function showAddress(address) {
    const map = new GMap2(document.getElementById("map"));
    map.addControl(new GSmallMapControl());
    map.addControl(new GMapTypeControl());
    if (geocoder) {  // global variable
        geocoder.getLatLng(
            address,
            function(point) {
                if (!point) {
                    alert(address + " not found");
                } else {
                    document.getElementById("lat").innerHTML = point.lat().toFixed(5);
                    document.getElementById("lng").innerHTML = point.lng().toFixed(5);
                    map.clearOverlays()
                    map.setCenter(point, 15);
                    const marker = new GMarker(point, {draggable: true});
                    map.addOverlay(marker);

                    GEvent.addListener(marker, "dragend", function() {
                        const pt = marker.getPoint();
                        map.panTo(pt);
                        document.getElementById("lat").innerHTML = pt.lat().toFixed(5);
                        document.getElementById("lng").innerHTML = pt.lng().toFixed(5);
                    });

                    GEvent.addListener(map, "moveend", function() {
                        map.clearOverlays();
                        const center = map.getCenter();
                        const marker = new GMarker(center, {draggable: true});
                        map.addOverlay(marker);
                        document.getElementById("lat").innerHTML = center.lat().toFixed(5);
                        document.getElementById("lng").innerHTML = center.lng().toFixed(5);

                        GEvent.addListener(marker, "dragend", function() {
                            const pt = marker.getPoint();
                            map.panTo(pt);
                            document.getElementById("lat").innerHTML = pt.lat().toFixed(5);
                            document.getElementById("lng").innerHTML = pt.lng().toFixed(5);
                        });
                    });

                }
            }
        );
    }
}

google.setOnLoadCallback(mapload);
</script>


<form action="#" onsubmit="showAddress(this.address.value); return false">
     <p>
      <input type="text" size="40" name="address" value="" placeholder="find address..." />
      <input type="submit" value="Show" />

      </p>
    </form>

 <table  width="300">
  <tr>
    <td width="100"><b>Latitude</b></td>
    <td id="lat"></td>
  </tr>
  <tr>

    <td width="100"><b>Longitude</b></td>
    <td id="lng"></td>
  </tr>
</table>

    <div id="map" style="width:600px;height:360px"></div>
  </body>
</html>



