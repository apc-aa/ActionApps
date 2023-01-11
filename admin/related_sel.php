<?php
/**
 *
 *   sid expected - slice_id where to search
 *   var_id expected - id of variable in calling form, which should be filled
 *   mode expected - which buttons to show ([A][M][B] - 'add' 'add mutual' 'add backward'
 *   design expected - boolean - use standard or admin design
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
 *
*/


use AA\Manager;
use AA\Util\InputVars;

$save_hidden = true;   // do not delete r_hidden session variable in init_page!

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/view.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/feeding.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/searchlib.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/util.php";


/** RELATED SELECTION WINDOW - managed by MANAGER CLASS */

$sess->register('r_state'); // make sure it is _SESSION variable

/* use stored values from session, if we call related window
   for secnd time (e.g. after search or filtering data) */
if ( isset($_GET['sid']) )  {
    $sid         = InputVars::idGET('sid');
    $var_id      = InputVars::stringGET('var_id');
    $mode        = InputVars::stringGET('mode');
    $design      = InputVars::boolGET('design');
    $frombins    = InputVars::stringGET('frombins');
    $showcondsro = InputVars::stringGET('showcondsro');
    $showcondsrw = InputVars::stringGET('showcondsrw');
    $sort        = InputVars::stringGET('sort');
    $slice_field = InputVars::stringGET('slice_field');
    $listlen     = InputVars::intGET('listlen');
    $r_state['related'] = [
       'sid'         => $sid,
       'var_id'      => $var_id,
       'mode'        => $mode,
       'design'      => $design,
       'frombins'    => $frombins,
       'showcondsro' => $showcondsro,
       'showcondsrw' => $showcondsrw,
       'slice_field' => $slice_field,
       'listlen'     => $slice_field
        ];
} else {
    // it is not stored inside Manager - we have to store it in session
    $sid         = $r_state['related']['sid'];
    $var_id      = $r_state['related']['var_id'];
    $mode        = $r_state['related']['mode'];
    $design      = $r_state['related']['design'];
    $frombins    = $r_state['related']['frombins'];
    $showcondsro = $r_state['related']['showcondsro'];
    $showcondsrw = $r_state['related']['showcondsrw'];
    $short       = $r_state['related']['sort'];
    $slice_field = $r_state['related']['slice_field'];
    $listlen     = $r_state['related']['slice_fiel'];
}

$manager_id = 'related'. $sid.$var_id;

if ( isset($_GET['sid']) )  {
    unset($r_state['manager'][$manager_id]);
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$slice       = AA_Slice::getModule($sid);

if (!$slice) {
    echo "<Error - no slice found<br>";
    echo "<hr>sid<br><pre>";
    print_r($sid);
    echo "<hr>r_state<br>";
    print_r($r_state);
    echo "<hr>GET<br>";
    print_r($_GET);
    echo "<hr>SESSION<br>";
    print_r($_SESSION);
    echo "<hr>POST<br>";
    print_r($_POST);
    echo "</pre>";
    exit;
}

/* prepare view format for manager class */
if (!$mode )        { $mode        ='AMB'; }
if (!$slice_field ) { $slice_field ='_#HEADLINE'; }

for ( $i=0, $ino=strlen($mode); $i<$ino; ++$i) {
    $m1 = substr($mode,$i,1);
    $mode_string .= "&nbsp;<a href=\"javascript:SelectRelations('$var_id','".TPS['AMB'][$m1]['tag']."','".TPS['AMB'][$m1]['prefix']."','".TPS['AMB'][$m1]['tag']."_#ITEM_ID_','{safe:{javascript:{expand:".$slice_field."}}}')\" class=\"aa-button\">". _m(TPS['AMB'][$m1]['str']) ."</a>&nbsp;";
}

$aliases = $slice->aliases();
// special alias - will be automatically added as last column in manager view
$aliases["_#AA_ACTIO"] = GetAliasDef( "f_t:$mode_string", "id..............");

$manager_vid = null;
$format      = null;   //default manager format will be used (and _#AA_ACTIO alias expanded)

if ($design) {
    if (ctype_digit((string)$design)) {
        if ($design==1) {
            $format  = $slice->get_format_strings();
            // replace the checkbox with "action selection links"

            // remove links
            $format["odd_row_format"] = preg_replace('~</?a[^>]*>~is', '', $format['odd_row_format']);
            // add action buttons (links)
            $format["odd_row_format"] = preg_replace('~<input[^>]*checkbox[^>]*chb\[x_#ITEM_ID[^>]*>~i', $mode_string, $format['odd_row_format']);
        } else {
            $manager_vid = $design;
        }
    } else {
        $format['odd_row_format']  = '<tr class="tabtxt"><td>'.$design.'</td></tr>';
        $format['compact_top']     = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">';
        $format['compact_bottom']  = '</table>';
    }
} else {  // '' or '0'
    $format['odd_row_format']  = '<tr class="tabtxt"><td>_#PUB_DATE&nbsp;</td><td>{'.$slice_field.'}</td><td>_#AA_ACTIO</td></tr>';
    $format['compact_top']     = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">
                <tr><th>'._m("Publish date").'</th><th>'._m("Headline").'</th><th>'._m("Actions").'</th></tr>';
    $format['compact_bottom']  = '</table>';
}

$manager_settings = [
     'module_id' => $sid,
     'show'      =>  MGR_ALL & ~MGR_ACTIONS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
     'searchbar'  => [
         'fields'               => $slice->getSearchfields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for additional action hooked on standard filter action
     ],
     'scroller'  => [
         'listlen'              => ($listlen ?: EDIT_ITEM_COUNT)
     ],
     'itemview'  => [
         'manager_vid'          => $manager_vid,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $format,
         'fields'               => $slice->getFields()->getRecordArray(),
         'aliases'              => $aliases,
         'get_content_funct'    => 'GetItemContent',
         'query_funct'          => 'QueryZidsSet'
     ]
];

$managed = (new \AA\Managed())->setSettings($manager_settings);
$managed->setTitle(_m("Editor window - item manager, related selection window"));
$manager = new Manager($manager_id, $managed);

if ( isset($_GET['sid']) ) {

    if ( isset($showcondsro) ) {
        $manager->addSearchBar( String2Conds(rawurldecode($showcondsro)), true );
    }
    if (isset($conds_rw)) {
        $manager->addSearchBar( String2Conds(rawurldecode($showcondsrw)));
    }
    if (isset($sort)) {
        $manager->addOrderBar( String2Sort(rawurldecode($sort)));
    }
}

$apage = new AA_Adminpageutil();
//$apage->setTitle(_m('Manage %1', [AA_Plannedtask::name()]));
//$apage->setSubtitle( _m('You can plan the task for specified time, or as the reaction to some event. The task will be probably sending e-mails using {mail...} expression, or recounting some fields after update by {recount...}') );
$apage->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION ));
$apage->addRequire(get_aa_url('javascript/inputform.min.js?v='.AA_JS_VERSION ));

$apage->printWithManager($manager);


// r_state array holds all configuration of Links Manager
// the configuration then could be Bookmarked



