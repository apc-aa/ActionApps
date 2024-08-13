<?php

//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* Params:
    $edit=1 .. called by Edit Jump menu
    $jump_id .. ID of jump to edit
    $update=1 .. write changes to database
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";

try {
    // array ( '6c81ec28dd4…' => 4, '28c1fa37265…' => 1, 'c390fa58964…' => 1, '5916c1036b8…' => 2, 'da5b0c89fd3…' => 2, )
    $dest_id = InputVars::idPOST('dest_id');
    // array ( 1 => 9, )-array ( 1 => 5, 'Editace' => 2, )
    $edit = InputVars::boolGETPOST('edit'); // -alnum
    // array ( '28c1fa37265…' => 7, '' => 4, 'a0e0104fd72…' => 1, )
    $jump_id = InputVars::idPOST('jump_id'); // empty
    // array ( 'Jumpaaa' => 1, 'Jumper 38/(…' => 1, 'Jumper 38/(!' => 2, 'Vznášejí…' => 1, 'Jump' => 2, 'Jump ww' => 1, 'odule name:' => 2, )
    $jump_name = InputVars::stringPOST('jump_name');
    // array ( 'Because she…' => 5, 'admin/se_fu…' => 5, )
    $jump_url = InputVars::stringPOST('jump_url');
    // array ( 1 => 4, )
    $no_slice_id = InputVars::boolGET('no_slice_id');
    // array ( 'Přihlásit' => 1, 'Login now' => 2, )
    // $submit = InputVars::boolPOST('submit'); // not needed
    // array ( ' Změnit [^…' => 5, ' Create [^S…' => 4, ' Update [^S…' => 1, )
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

// create the $jumps array:
$jumps = AA_Module::getModules('J');

// choose the first module when none is chosen
if ($edit && !$jump_id && count ($jumps)) {
    reset($jumps);
    $jump_id = key($jumps);
}


$apage = new AA_Adminpageutil('aaadmin', 'jumpedit');
$apage->setTitle(!$jump_id ? _m("Create new Jump module") : _m("Edit Jump module"));
$apage->addRequire('aa-jslib@1');
$apage->setForm();
$apage->printHead($err, $Msg);

if ($update) {
    if (!$jump_id) $jump_id = new_id ();
    $q_jump_id = q_pack_id ($jump_id);

    $varset = new Cvarset();
    $varset->clear();
    $varset->add("destination", "text", $jump_url);
    $varset->add("dest_slice_id", "unpacked", $dest_id);

    $db->query("SELECT * FROM module WHERE id='$q_jump_id'");
    if ($db->next_record()) {
        $db->query("UPDATE jump SET ".$varset->makeUPDATE() ." WHERE slice_id='$q_jump_id'");

        $varset->clear();
        $varset->add("name","text",$jump_name);
        $db->query("UPDATE module SET ".$varset->makeUPDATE() ." WHERE id='$q_jump_id'");
    }
    else {
        $varset->add("slice_id", "unpacked", $jump_id);
        $varset->doINSERT('jump');

        $varset->clear();
        $varset->add("id","unpacked",$jump_id);
        $varset->add("name","text",$jump_name);
        $varset->add("type","text",'J');
        $varset->add("lang_file","text","en_news_lang.php");
        $varset->add("owner","text",'');
        $varset->add("created_by","text",AuthGetUid());
        $varset->add("created_at","text",now());

//        $varset->set("slice_url", $slice_url, "text");
//        $varset->set("priority", $priority, "number");
        $varset->set("deleted", 0, "number");

        $varset->doINSERT('module');
    }
    $jumps = DB_AA::select(['jid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS jid, name FROM module', [['type','J']]);
}

if (!$jump_id) {
    $jump_name = $jump_url = $dest_id = "";
} else {
    $db->query("SELECT name,destination,dest_slice_id FROM jump INNER JOIN module
            ON jump.slice_id = module.id
            WHERE jump.slice_id = '".q_pack_id ($jump_id)."'");
    if ($db->next_record()) {
        $jump_name = $db->f("name");
        $jump_url = $db->f("destination");
        $dest_id = unpack_id($db->f("dest_slice_id"));
    }
}

// Show the select box to choose a module to edit
if ($jump_id) {
    echo '
    <form name=choose action="'.StateUrl("modedit.php").'" method="post">';
    FrmTabCaption(_m("Choose module to be edited"));

    echo '<tr>
            <td>
                <select name="jump_id" onchange="document.forms.choose.submit();">';
                foreach ($jumps as $id => $name) {
                    echo "<option value=\"$id\"" .($id == $jump_id ? " selected" : "") .">".safe($name);
                }

                echo '</select>&nbsp;</td><td>
                <input type=submit name="edit" value="'._m("Edit").'">
            </td>
        </tr>';
    FrmTabEnd();
    echo '</form>';
}

echo '<br>
<form name=f action="'.StateUrl("modedit.php").'" method="post">
<input type=hidden name="jump_id" value="'.$jump_id.'">';
if ($edit) echo "<input type=hidden name='edit' value='1'>";
FrmTabCaption(_m("Edit module"));
echo '
     <tr><th>'._m("Module name").':</th>
        <td><input type=text name="jump_name" size=20 value="'.$jump_name.'"></td></tr>
     <tr><th>'._m("Jump to").' (URL):</th>
        <td><input type=text name="jump_url" size=60 value="'.$jump_url.'"></td></tr>
     <tr><td colspan=2>'._m("Type in an AA-relative path, e.g.").' <code>admin/se_constant.php?group_id=something</code></td></tr>
     <tr><th>'._m("Jump to slice").":</th>
        <td><select name=dest_id>";
//        <option value=''>* * * Don't change slice * * *";
     if ( is_array($g_modules) AND (count($g_modules) > 1) ) {
        foreach ($g_modules as $k => $v) {
            echo "<option value=\"". safe($k)."\"";
            if ( $dest_id == $k ) echo " selected";
            echo ">". safe($v['name']);
        }
     }
     else echo "<option>No module exists";
     echo "</select></td>
     </tr>
     <tr><th>"._m("Module ID").":</th><td>$jump_id</td></tr>";
FrmTabEnd([
    "update"=> ["value"=>($jump_id ? _m("Update") : _m("Create"))],
                "cancel"=> ["url"=>AA_INSTAL_PATH."admin/um_uedit.php"]
]);
echo "</form>";

$apage->printFoot();
