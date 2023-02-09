<?php
/**
 * Form displayed in popup window used for feeding selected items
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
*/
const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

/** PrintExportRow function
 * Print one row with one 'sliceexport' radiobuttons
 * @param $sname
 * @param $sid
 * @param $odd
 * @return void
 */
function PrintExportRow( $sname, $sid, $odd) {
    echo '
    <tr>
      <td>'. safe($sname). '</td>
      <td><input type="radio" name="x'. $sid .'" value="2"></td>
      <td>'.
      ( CheckPerms( AuthGetUid(), "slice", $sid, PS_ITEMS2ACT) ?
        '<input type="radio" name="x'. $sid .'" value="1">' : '&nbsp;') .'</td>
      <td><input type="radio" name="x'. $sid .'" value="0" checked></td>
    </tr>';
}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <title>'.  _m("Export Item to Selected Slice") .'</title>';
IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Export selected items to selected slice") .'</h1>
  <form name="incf">
   <table>
     <tr class="aa-tbl-header"><th>'. _m("Slice") .'</th>
         <th>'. _m("Holding bin")   .'</th>
         <th>'. _m("Active")        .'</th>
         <th>'. _m("Do not export to this slice") .'</th></tr>';

$i=1;     // slice checkbox counter
if ( is_array($g_modules) AND (count($g_modules) > 1) ) {
    foreach ( $g_modules as $sid => $v) {
        if ( ($v['type'] == 'S') AND    //  we can feed just between slices ('S')
            ((string)AA::$module_id != (string)$sid) AND
            // we must have autor or editor perms in destination slices
            CheckPerms( AuthGetUid(), "slice", $sid, PS_EDIT_SELF_ITEMS) ) {
            $odd = (gettype($i/2) == 'integer');
            PrintExportRow($v['name'], $sid, $odd);
            $i++;
        }
    }
}
if ( $i==1 ) {   // can't feed to any slice
  echo '<tr><td colspan="3">'. _m("No permission to set feeding for any slice") .'</td></tr>';
}
?>
   <tr class="aa-tbl-header"><th colspan="4"><br /><input type="button" name="sendfeeded" value="<?php echo _m("Export") ?>" onclick="SendFeed();"><br />&nbsp;</th></tr>
   </table>
   <script>
    function SendFeed() {
        var len = document.incf.elements.length;
        var retval='';
        var inputname;
        var radiovalue = '';
        var delimiter = '';

        // prepare returnvalue
        for ( var i=0; i<len; i++ ) {
            inputname = document.incf.elements[i].name;
            if ( inputname.substring(0,1) == 'x') {     // slices radiobuttons
                radiovalue = document.incf.elements[i].value;
                if ( (radiovalue > 0) && (document.incf.elements[i].checked)) {
                    retval += delimiter + radiovalue + '-' + inputname.substring(1);
                    delimiter=',';
                }
            }
        }
        // fill return field and submit itemform
        ReturnParam(retval);
    }
   </script>
  </form>
</body>
</html>
<?php page_close(); ?>
