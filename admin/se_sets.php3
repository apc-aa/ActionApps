<?php
/** se_sets.php3 - creates sets
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
 *
 *   not nic - quick & dirty solution, for now. Will be rewritten.
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."item.php3";     // GetAliasesFromField funct def
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change sets"), "admin");
    exit;
}

$err = array();          // error array (Init - just for initializing variable

if ( $update ) {
    do {
        for ($k=1; $k<=$_POST['setcount']; $k++) {
            $name  = $_POST['name'][$k];
            $sli   = $_POST['sli'][$k];
            $cond  = $_POST['cond'][$k];
            $sort  = $_POST['sort'][$k];
            $objid = $_POST['objid'][$k];

            ValidateInput("name$k",  _m("Name $k"),      $name,  $err, false, "text");
            ValidateInput("sli$k",   _m("Slice $k"),     $sli,   $err, false, "text");
            ValidateInput("cond$k",  _m("Condition $k"), $cond,  $err, false, "text");
            ValidateInput("sort$k",  _m("Sort $k"),      $sort,  $err, false, "text");
            ValidateInput("objid$k", _m("Object ID $k"), $objid, $err, false, "text");

            if ( count($err)) {
                break;
            }

            if ($cond AND $name) {
                $set = new AA_Set($sli, $cond, $sort);
                $set->setName($name);
                $set->setOwnerId($slice_id);
                // those id are marked so we can use it as group in Reader permissions
                $set->setId($objid ? $objid : new_id(1));
                $set->save();
            }
        }

        AA::Pagecache()->invalidatefor($slice_id);  // invalidate old cached values

    } while (false);

    if ( !count($err) ) {
        $Msg = MsgOk(_m("Sets stored successfully"));
    }
}


$apage = new AA_Adminpageutil('sliceadmin','sets');
$apage->setTitle(_m("Admin - Item Sets"));
$apage->printHead($err, $Msg);

$form_buttons = array ("update",
                       "cancel" =>array("url"=>"se_fields.php3"),
                      );
FrmTabCaption(_m("Sets"), '', '', $form_buttons, $slice_id);
FrmStaticText('', _m('Conditions are in "d-..." or "conds[]" form - just like:<br> &nbsp; d-headline........,category.......1-RLIKE-Bio (d-&lt;fields&gt;-&lt;operator&gt;-&lt;value&gt;-&lt;fields&gt;-&lt;op...)<br> &nbsp; conds[0][category........]=first&conds[1][switch.........1]=1 (default operator is RLIKE, here!)'), '', '', false);

$set_ids = AA_Object::querySet('AA_Set', new AA_Set(array($slice_id)));
$k = 0;
foreach( $set_ids as $i => $set_id ) {
    $set = AA_Object::load($set_id, 'AA_Set');
    if ( is_null($set)) {
        continue;
    }
    $k = $i+1;
    FrmHidden("objid[$k]", $set->getId());
    FrmStaticText(_m('ID'), $set->getId());
    FrmInputText("name[$k]", _m("Set name %1",   array($k)), $set->getName(),             32, 32, false, _m('use alphanumeric characters only'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
    FrmTextarea("sli[$k]", _m("Slices %1",       array($k)), join('-',$set->getModules()), 2, 60, false, _m('possibly dash separated'));
    FrmTextarea("cond[$k]",  _m("Conditions %1", array($k)), $set->getCondsAsString(),     4, 60, false, _m('Use "d-..." or "conds[]" conditions'));
    FrmInputText("sort[$k]", _m("Sort %1",       array($k)), $set->getSortAsString(),    500, 60, false, _m('Use "headline.......,publish_date....-" or "sort[]" conditions'));
}

++$k;
FrmInputText("name[$k]", _m("Set name %1", array($k)), '', 32, 32, false, _m('use alphanumeric characters only'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
FrmTextarea("sli[$k]", _m("Slices %1",     array($k)), '',  2, 60, false, _m('possibly dash separated'));
FrmTextarea("cond[$k]",  _m("Conditions %1", array($k)), '',  4, 60, false, _m('Use "d-..." or "conds[]" conditions'));
FrmInputText("sort[$k]", _m("Sort %1",       array($k)), '', 500, 60,false, _m('Use "headline........,publish_date....-" or "sort[]" conditions'));
FrmHidden("setcount", $k);


FrmTabEnd($form_buttons);

$apage->printFoot();
