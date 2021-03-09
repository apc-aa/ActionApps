<?php
/** PHP version 7.2+
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
 *
*/

// expected $view_id for editing specified view

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/item.php3";     // GetAliasesFromField funct def
require_once __DIR__."/../include/msgpage.php3";
require_once __DIR__."/../include/actions.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You do not have permission to change views"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

if ( $del ) {
    // check if deleted view is from this slice (for security)
    if (!AA::Metabase()->doDelete('view', ['id'=>$vid, 'slice_id'=>$slice_id])) {
        $err["DB"] = MsgErr("Can't delete view");
        exit;
    }
    AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values

    $Msg = MsgOk(_m("View successfully deleted"));
}

// returns javascript row for view selection
function GetViewJSArray( $sid, $id, $name, $i ) {
    $id=safe($id);
    return "\n vs[$i]=\"x$sid\"; vv[$i]=\"$id\"; vn[$i]=\"".safe($name)."\";";
}

$headcode = <<<'EOT'
<script>
     function SelectViewSlice() {
       var i,j;
       var xsid=document.fvtype.view_slice.value;
         // clear selectbox
       for ( i=(document.fvtype.view_view.options.length-1); i>=0; i--){
         document.fvtype.view_view.options[i] = null
       }
         // fill selectbox from the right slice
       j=0;
       for ( i=0; i<vs.length ; i++) {
         if ( vs[i] == xsid ) {
           document.fvtype.view_view.options[j++] = new Option(vn[i], vv[i])
         }
       }
     }
</script>
EOT;

$apage = new AA_Adminpageutil('sliceadmin','views');
$apage->setTitle(_m("Admin - design View"));
$apage->setSubtitle(_m(""));
//$apage->setForm(array('action'=>'se_view.php3', 'name'=>'fvtype'));
//$apage->addRequire('codemirror@5');
$apage->addRequire('aa-jslib');   // just for MarkedActionSelect() in manager.min.js
$apage->addRequire(get_aa_url('javascript/manager.min.js?v='.AA_JS_VERSION ));
//$apage->addRequire(get_aa_url('/javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false ));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);


$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_View_Move2slice(   'Move2slice',    $module_id));

//$switches  = new AA_Manageractions;  // we do not need switches here

$VIEW_TYPES = getViewTypes();

$view_types_ifeq = array_reduce( array_keys($VIEW_TYPES), function ($str, $key) use ($VIEW_TYPES) { return "$str:$key:".str_replace(':','#:',$VIEW_TYPES[$key]['name']); });

$manager_settings = AA::Metabase()->getManagerConf('view', $actions);
$manager_settings['itemview']['format']['compact_top'] = '
                                        <div class="aa-table">
                                          <table border="0" cellpadding="5" cellspacing="0">
                                            <tbody><tr>
                                              <th width="30">&nbsp;</th>
                                              <th>'._m('Actions').'</th>
                                              <th colspan="2">vid=</th>
                                              <th>'._m('Name').'</th>
                                              <th>'._m('Type').'</th>
                                            </tr>';
$manager_settings['itemview']['format']['odd_row_format'] = '
                                      <tr><td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
                                        <td>
                                          <a href="./se_view.php3?module_id=_#SLICE_ID&view_id=_#ID______" class="aa-button-edit">'. _m('Edit') .'</a>
                                          <a href="'.AA_INSTAL_URL.'view.php3?vid=_#ID______" class="aa-button-show" title="'. _m("show this view") .'">'. _m('Show') .'</a>
                                          <a href="javascript:;" class="aa-button-delete" onclick="if (confirm(\''._m("Are you sure you want to delete selected view?").'\')) {document.location=\'./se_views.php3?module_id=_#SLICE_ID&vid=_#ID______&del=1\';}">'. _m("Delete") .'</a>
                                        </td>
                                        <td><a href="./se_view.php3?module_id=_#SLICE_ID&view_id=_#ID______">_#FIELD3__</a></td>
                                        <td><a href="./se_view.php3?module_id=_#SLICE_ID&view_id=_#ID______">_#ID______</a></td>
                                        <td>_#NAME____</td>
                                        <td>{ifeq:{_#TYPE____}'. $view_types_ifeq.'}</td>
                                    </tr>';                      // <td>_#OBJECT__</td><td>_#EXECUTE_</td>

$manager_settings['show'] = MGR_ALL & ~MGR_SB_BOOKMARKS & ~MGR_SB_SEARCHROWS & ~MGR_SB_ORDERROWS;    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
$manager_settings['searchbar']['default_sort'] =  [0 => ['id' => 'd']];
$manager_settings['actions'] =  $actions;

$manager = new AA_Manager('views', $manager_settings);
$manager->performActions();

$aa_set  = $manager->getSet();
// $aa_set->setModules($module_id);
$aa_set->addCondition(new AA_Condition('slice_id', '==', q_pack_id($module_id)));

$zids    = AA::Metabase()->queryZids(['table'=>'view'], $aa_set);

//$manager->displayPage($zids, 'aaadmin', 'views');
$manager->display($zids);







// -- get all views --

$views = DB_AA::select([], 'SELECT LOWER(HEX(`slice_id`)) as uslice_id, id, name, type, field3 FROM view', [], ['id-']);
$i   = 0;
foreach ( $views as $vw ) {
    if ($g_modules[$vw['uslice_id']]) {     // if user has any permission for the view's slice
        $view_array .= GetViewJSArray( $vw['uslice_id'], $vw['id'], ($vw['field3'] ? $vw['field3'].' - ' : '').$vw['id'].' - '.$vw['name'], $i++ );
        $sliceWview[$vw['uslice_id']] = 1;  // mark the slices, where is an view
    }
}

echo '<form action="se_view.php3" name="fvtype">';
echo StateHidden();

echo '<div class="aa-table"><table><tbody>
      <tr><th colspan="3">'._m("Create new view").'</th> </tr>
      <tr>
        <td>'._m("by&nbsp;type:").'</td>
        <td align="right"><select name="view_type">';

foreach ( $VIEW_TYPES as $k => $v) {
    echo "<option value=\"$k\"> ". myspecialchars($v["name"]) ." </option>";
}
echo "</select></td>
        <td><input type=\"submit\" name=\"new\" value=\"". _m("New") ."\"></td>
     </tr>";

// row for new view creaded from template
echo "<tr>
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
     </tr>";

echo "</tbody></table></div></form>";

FrmJavascriptCached("
  var vs, vv, vn;
  vs=new Array();
  vn=new Array();
  vv=new Array();
  $view_array
  SelectViewSlice();", 'view_list');


$apage->printFoot();
