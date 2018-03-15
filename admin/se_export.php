<?php
/**
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

/** @param format    csv|excel|html - corresponds to AA_Exporter_* classes
 *  @param slice_id  unpacked slice id to export
 *  @param sort      optional - sorting
 *  @param conds     optional - conditions when just some items shoud be exported
 *  @param filename  optional - name of the file
 *
 *  You can also store the export and then allow people to export items by public link /apc-aa/export.php
 *
 *  @example http://example.org/apc-aa/export.php?id=353399822763422617723728a7787b77
 *  @example http://example.org/apc-aa/export.php?id=353399822763422617723728a7787b77&name=Jane
 */

require_once "../include/init_page.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."locsess.php3";    // DB_AA object definition
require_once AA_INC_PATH."exporter.class.php3";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to export"));
    exit;
}

if ($_GET['export']) {

    $exportset_params = array (
        'grabber_type' => $grabber_name,
        'format'       => $format,
        'type'         => $type,
        'bins'         => $bins,
        'filename'     => $filename,
        'conds'        => $conds,
        'sort'         => $sort
    );

    $exportset = new AA_Exportsetings($exportset_params);
    $exportset->setOwnerId($slice_id);

    $exportset->export();

    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - Export Items");?></title>
</head>

<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", "export");

echo "<h1><b>" . _m("Admin - Export Items") . "</b></h1>";
PrintArray($err);
echo $Msg;

$form_buttons = array ('export' => array('type'=>'submit', 'value'=> _m('Export')));

$grabber_arr = array(
    'AA_Grabber_Slice'      => _m('Item Contents'),
    'AA_Grabber_Discussion' => _m('Discussion')
    );

$bins_arr = array(
    AA_BIN_ALL      => _m('All'),
    AA_BIN_ACTIVE   => _m('Active'),
    AA_BIN_PENDING  => _m('Pending'),
    AA_BIN_EXPIRED  => _m('Expired'),
    AA_BIN_APPROVED => _m('Approved'),
    AA_BIN_HOLDING  => _m('Holding'),
    AA_BIN_TRASH    => _m('Trash')
    );

$types_arr = array(
    'db'    => _m('Database backup (as stored in DB)'),
    'human' => _m('Human - dates converted to Y-m-d format, ...')
    );


$format_arr     = array();
$format_classes = AA_Components::getClassNames('AA_Exporter_');
foreach ($format_classes as $fclass) {
    $format_arr[$fclass] = substr($fclass,12);
}

?>
<form name="f" method="get" action="<?php echo StateUrl($_SERVER['PHP_SELF']) ?>">
<?php
FrmTabCaption(_m("Export Items"), '','',$form_buttons, $sess, $slice_id);

FrmInputSelect('format', _m("Output Format"),    $format_arr, $format, true);
FrmInputSelect('type', _m("Type"),    $types_arr, $type, true);
FrmInputSelect('bins', _m("Bins"),      $bins_arr, $bins, true);
FrmInputText("filename",  _m("Filename"), $filename, 255, 60, false, _m('save as...'));
FrmInputText("conds",  _m("Conditions"), $conds, 255, 60, false, _m('conditions are in "d-..." or "conds[]" form - just like:<br> &nbsp; d-headline........,category.......1-RLIKE-Bio (d-&lt;fields&gt;-&lt;operator&gt;-&lt;value&gt;-&lt;fields&gt;-&lt;op...)<br> &nbsp; conds[0][category........]=first&conds[1][switch.........1]=1 (default operator is RLIKE, here!)'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
FrmInputText("sort",   _m("Sort"),       $sort, 255,  60, false, _m('like: publish_date....-'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then

FrmTabEnd($form_buttons);
?>
</form>
<br>
<?php


$module_id  = $slice_id;
$manager_id = 'exportsetings'.$module_id;

$manager_settings = AA_Exportsetings::getManagerConf(get_admin_url('se_export.php'));

$manager = new AA_Manager($manager_id, $manager_settings);
$manager->performActions();

$aa_set = $manager->getSet();
$aa_set->setModules($module_id);
//$aa_set->addCondition(new AA_Condition('aa_user',       '=', $auth->auth['uid']));

$zids  = AA_Object::querySet('AA_Exportsetings', $aa_set);

$manager->display($zids);
$r_state['manager']    = $manager->getState();
$r_state['manager_id'] = $manager_id;

//$manager->displayPage($zids, 'sliceadmin', 'exportsetings');


HtmlPageEnd();
page_close()
?>
