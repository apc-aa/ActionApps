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
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change sets"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable

if ( $update ) {
    do {
        ValidateInput("name1",  _m("Name 1"),      $name1,  $err, false, "text");
        ValidateInput("cond1",  _m("Condition 1"), $cond1,  $err, false, "text");
        ValidateInput("objid1", _m("Object ID 1"), $objid1, $err, false, "text");

        ValidateInput("name2",  _m("Name 2"),      $name2,  $err, false, "text");
        ValidateInput("cond2",  _m("Condition 2"), $cond2,  $err, false, "text");
        ValidateInput("objid2", _m("Object ID 2"), $objid2, $err, false, "text");

        ValidateInput("name3",  _m("Name 3"),      $name3,  $err, false, "text");
        ValidateInput("cond3",  _m("Condition 3"), $cond3,  $err, false, "text");
        ValidateInput("objid3", _m("Object ID 3"), $objid3, $err, false, "text");

        ValidateInput("name4",  _m("Name 4"),      $name4,  $err, false, "text");
        ValidateInput("cond4",  _m("Condition 4"), $cond4,  $err, false, "text");
        ValidateInput("objid4", _m("Object ID 4"), $objid4, $err, false, "text");

        if ( count($err) > 1) {
            break;
        }

        if ($cond1 AND $name1) {
            $set1 = new AA_Set();
            $set1->addCondsFromString($cond1);
            $set1->setName($name1);
            $set1->setOwner($slice_id);
            // those id are marked so we can use it as group in Reader permissions
            $set1->setId($objid1 ? $objid1 : new_id(1));
            $set1->save();
        }

        if ($cond2 AND $name2) {
            $set2 = new AA_Set();
            $set2->addCondsFromString($cond2);
            $set2->setName($name2);
            $set2->setOwner($slice_id);
            // those id are marked so we can use it as group in Reader permissions
            $set2->setId($objid2 ? $objid2 : new_id(1));
            $set2->save();
        }

        if ($cond3 AND $name3) {
            $set3 = new AA_Set();
            $set3->addCondsFromString($cond3);
            $set3->setName($name3);
            $set3->setOwner($slice_id);
            // those id are marked so we can use it as group in Reader permissions
            $set3->setId($objid3 ? $objid3 : new_id(1));
            $set3->save();
        }

        if ($cond4 AND $name4) {
            $set4 = new AA_Set();
            $set4->addCondsFromString($cond4);
            $set4->setName($name4);
            $set4->setOwner($slice_id);
            // those id are marked so we can use it as group in Reader permissions
            $set4->setId($objid4 ? $objid4 : new_id(1));
            $set4->save();
        }

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    } while (false);

    if ( count($err) <= 1 ) {
        $Msg = MsgOK(_m("Sets stored successfully"));
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - Item Sets");?></title>
</head>

<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", "sets");

echo "<h1><b>" . _m("Admin - Item Sets") . "</b></h1>";
PrintArray($err);
echo $Msg;

$form_buttons = array ("update",
                       "cancel" =>array("url"=>"se_fields.php3"),
                      );

?>
<form name="f" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
FrmTabCaption(_m("Sets"), '','',$form_buttons, $sess, $slice_id);
FrmStaticText('', _m('Conditions are in "d-..." or "conds[]" form - just like:<br> &nbsp; d-headline........,category.......1-RLIKE-Bio (d-&lt;fields&gt;-&lt;operator&gt;-&lt;value&gt;-&lt;fields&gt;-&lt;op...)<br> &nbsp; conds[0][category........]=first&conds[1][switch.........1]=1 (default operator is RLIKE, here!)'), false, '', '', false);

$set_ids = AA_Object::query('AA_Set', array($slice_id));
$k = 0;
foreach( $set_ids as $i => $set_id ) {
    $set = AA_Object::load($set_id, 'AA_Set');
    if ( is_null($set)) {
        continue;
    }
    $k = $i+1;
    FrmHidden("objid$k", $set->getId());
    FrmStaticText(_m('ID'), $set->getId());
    FrmInputText("name$k", _m("Set name %1",  array($k)), $set->getName(), 32, 32, false, _m('use alphanumeric characters only'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
    FrmTextArea("cond$k", _m("Conditions %1", array($k)), $set->getCondsAsString(), 4, 60, false, _m('Use "d-..." or "conds[]" conditions'));
}

for ( $i=$k+1; $i<5 ;$i++) {
    FrmInputText("name$i", _m("Set name %1", array($i)), '', 32, 32, false, _m('use alphanumeric characters only'));  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
    FrmTextArea("cond$i", _m("Conditions %1", array($i)), '', 4, 60, false, _m('Use "d-..." or "conds[]" conditions'));
}

FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()
?>
