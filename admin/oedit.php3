<?php
/**
 *
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
 * @link      https://www.apc.org/ APC
 *
*/

// expected at least $slice_id
// user calling is with $edit for edit item
// optionaly encap="false" if this form is not encapsulated into *.shtml file
// optionaly free and freepwd for anonymous user login (free == login, freepwd == password)

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."item.php3";     // GetAliasesFromField funct def
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

/*
if ( $ins_preview ) {
    $insert = true; $preview=true;
}
if ( $ins_edit ) {
    $insert = true; $go_edit=true;
}
if ( $upd_edit ) {
    $update = true; $go_edit=true;
}
if ( $upd_preview ) {
    $update = true; $preview=true;
}
*/

//$add = !( $update OR $cancel OR $insert OR $edit );


// could be _GET for initial sdisplay or _POST for edited object
$ret_url = $_REQUEST['ret_url'];
// could be changed in the future - the owner could be not only slice,
// however we have to change also permission check

$oid = $_REQUEST['oid'];
if ($oid) {
    $old_obj = AA_Object::load($oid);
    $oowner = $old_obj->getOwnerId();
    $otype  = $old_obj->getObjectType();
}
if (!$oowner) { $oowner = $slice_id; }           // for new of incorrect object in db
if (!$otype)  { $otype  = $_REQUEST['otype']; }  // for new of incorrect object in db

if ($cancel) {
    go_url(get_admin_url($ret_url));
}

/** @todo check object permissions */
if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu(get_admin_url($ret_url), _m("You have not permissions to change the object"), "admin");
    exit;
}

if ($_GET['delete']==1) {
    if ($oid AND $otype) {
        if ( $obj = AA_Object::load($oid, $otype) ) {
            $obj->delete();
        }
    }
    go_url($ret_url);
    exit;
}


$err = [];          // error array (Init - just for initializing variable


$form       = AA_Form::factoryForm($otype, $oid, $oowner);

$form_state = $form->process($_POST['aa']);

if ($form_state == AA_Form::SAVED) {
    go_url($ret_url);
}


$apage = new AA_Adminpageutil('sliceadmin','forms');
$apage->setTitle( _m("Admin - Object Edit") );
// for widgets
$apage->addRequire('aa-jslib' );


$form_buttons = [
    "update",
                       "cancel" => ["url"=>$ret_url],
];

$html  = getFrmTabCaption(_m("Object Edit"), $form_buttons);
$html .= '<tr><td colspan="2">';
$html .= $form->getObjectEditHtml();
$html .= '</td></tr>';

$form_buttons['ret_url'] = ["value"=> $ret_url];
$form_buttons['oid']     = ["value"=> $oid];
$form_buttons['otype']   = ["value"=> $otype];

$html .= getFrmTabEnd($form_buttons);

// this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
$apage->printAllPage($html, $err, $Msg);
