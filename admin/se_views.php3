<?php
/** PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

// expected $view_id for editing specified view

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."item.php3";     // GetAliasesFromField funct def
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You do not have permission to change views"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);

if ( $del ) {
    // check if deleted view is from this slice (for security)
    $SQL = "DELETE FROM view WHERE id='$vid' AND slice_id='$p_slice_id'";
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't delete view");
        exit;
    }
    $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    $Msg = MsgOK(_m("View successfully deleted"));
}
/** PrintViewRow function
 * @param $id
 * @param $name
 * @param $type
 */
function PrintViewRow($id, $name, $type) {
    global $sess;
    $VIEW_TYPES = getViewTypes();
    $name = safe($name); $id = safe($id);

    $edit_url = con_url($sess->url("./se_view.php3"), "view_id=$id&view_type=$type");
    $view_url = AA_INSTAL_URL. "view.php3?vid=$id";

    echo "<tr class=\"tabtxt\">
            <td class=\"tabtxt\"><a href=\"$edit_url\">$id</a></td>
            <td class=\"tabtxt\">". $VIEW_TYPES[$type]["name"] ."</td>
            <td class=\"tabtxt\">$name</td>
            <td class=\"tabtxt\"><a href=\"$edit_url\">". _m("Edit") . "</a></td>
            <td class=\"tabtxt\"><a href=\"$view_url\" title=\"". _m('show this view') ."\">". _m("Show") . "</a></td>
            <td class=\"tabtxt\"><a href=\"javascript:GoIfConfirmed('".
                          $sess->url(con_url("./se_views.php3", "del=1&vid=". urlencode($id))) ."','".
                          _m("Are you sure you want to delete selected view?") ."')\">". _m("Delete") ."</a></td>
           </tr>";
}

// returns javascript row for view selection
function GetViewJSArray( $sid, $id, $name, $i ) {
    $id=safe($id);
    return "\n vs[$i]=\"x$sid\"; vv[$i]=\"$id\"; vn[$i]=\"".safe($name)."\";";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<title>". _m("Admin - design View") ."</title>";
FrmJavascriptFile('javascript/js_lib.js');
$js = '
     function SelectViewSlice() {
       var i,j;
       var xsid=document.fvtype.view_slice.options[document.fvtype.view_slice.selectedIndex].value;
         // clear selectbox
       for ( i=(document.fvtype.view_view.options.length-1); i>=0; i--){
         document.fvtype.view_view.options[i] = null
       }
         // fill selectbox from the right slice
       j=0;
       for ( i=0; i<vs.length ; i++) {
         if ( vs[i] == xsid ) {
           document.fvtype.view_view.options[j++] = new Option(vv[i]+\' - \'+vn[i], vv[i])
         }
       }
     }
     ';

FrmJavascript($js);
echo "</head>\n";

$useOnLoad = ($new_compact ? true : false);
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin","views");

echo "<h1><b>" . _m("Admin - design View") . "</b></h1>";
PrintArray($err);
echo $Msg;

echo '<form name="fvtype" method="post" action="'. $sess->url("./se_view.php3"). '">';

FrmTabCaption(_m("Defined Views"));

// -- get all views --
$SQL = "SELECT * FROM view ORDER BY id DESC";
$db->query($SQL);
$i   = 0;
while ( $db->next_record() ) {
    $view_sid = unpack_id($db->f('slice_id'));
    if ( $view_sid == $slice_id ) {   // list views for this slice
        PrintViewRow($db->f('id'), $db->f('name'), $db->f('type'));
    }
    if ($g_modules[$view_sid]) {     // if user has any permission for the view's slice
        $view_array .= GetViewJSArray( $view_sid, $db->f('id'), $db->f('name'), $i++ );
        $sliceWview[$view_sid] = 1;  // mark the slices, where is an view
    }
}

  // row for new view creaded from view type selection
echo "</td>
     </tr>";
    FrmTabseparator(_m("Create new view"));
echo "
      <tr class=\"tabtxt\">
        <td>"._m("by&nbsp;type:")."</td>
        <td align=\"right\"><select name=\"view_type\">";

foreach ( getViewTypes() as $k => $v) {
    echo "<option value=\"$k\"> ". myspecialchars($v["name"]) ." </option>";
}
echo "</select></td>
        <td><input type=\"submit\" name=\"new\" value=\"". _m("New") ."\"></td>
     </tr>";

  // row for new view creaded from template
echo "<tr class=\"tabtxt\">
        <td>"._m("by&nbsp;template:")."</td>
        <td align=\"right\">
         <select name=\"view_slice\" OnChange=\"SelectViewSlice()\">";
  // slice selection
foreach ( $g_modules as $k => $v) {
    if ( ($v['type'] != 'S') OR !$sliceWview[$k] ) {
        continue;                      // we can feed just between slices ('S')
    }
    $selected = ( (string)$slice_id == (string)$k ) ? "selected" : "";
    echo "<option value=\"x$k\" $selected>". safe($v['name']) ."</option>\n";
}
echo "   </select>&nbsp;<select name=\"view_view\">
          <option> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>
         </select>
         </td>
        <td><input type=\"submit\" name=\"new_templ\" value=\"". _m("New") ."\"></td>
     </tr>
    </table>
</td>
   </form>
 </tr>
</table><br>
";

FrmJavascriptCached("
  var vs, vv, vn;
  vs=new Array();
  vn=new Array();
  vv=new Array();
  $view_array
  SelectViewSlice();", 'view_list');


$viewuri = ereg_replace("/admin/.*", "/view.php3", $_SERVER['PHP_SELF']); //include help
echo _m("<br>To include slice in your webpage type next line \n                         to your shtml code: ") ."<br><pre>&lt;!--#include virtual=&quot;" . $viewuri .
         '?vid=<i>ID</i>&quot;--&gt;</pre>';
HtmlPageEnd();
page_close();

?>