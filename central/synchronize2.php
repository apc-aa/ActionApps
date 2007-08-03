<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

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

require_once dirname(__FILE__). "/include/init_central.php";
require_once AA_INC_PATH.     'formutil.php3';
require_once AA_INC_PATH.     'files.class.php3';
require_once AA_INC_PATH.     "msgpage.php3";


function CompareSliceDefs($template_slice_defs, $dest_slice_defs) {
    // resulting array of all differences between selected slices
    $differences = array();
    if ( is_array($template_slice_defs) ) {
        foreach ( $template_slice_defs as $slice_name => $slice_def ) {
            $differences[$slice_name] = array();
            if ( empty($dest_slice_defs[$slice_name]) ) {
                $differences[$slice_name][] = new AA_Difference(_m('Destination slice (%1) noes not exist', array($slice_name)));
            }
            $differences[$slice_name] = array_merge($differences[$slice_name], $slice_def->compareWith($dest_slice_defs[$slice_name]));
        }
    }
    return $differences;
}



page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

if (!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}


// ActionApps to synchronize
$aas   = array();
foreach ($ACTIONAPPS as $k => $aadef) {
    $aas[$k] = new AA_Actionapps($aadef['name'], $aadef['url'], $aadef['user'], $aadef['pwd']);
}

if ($_POST['compare']) {
    $template_slice_defs = $aas[$_POST['template_aa']]->sliceDefinitions($_POST['sync_slices']);
    if (is_array($_POST['destination_aa']) ) {
        foreach ($_POST['destination_aa'] as $dest_aa) {
            $dest_slice_defs = $aas[$dest_aa]->sliceDefinitions($_POST['sync_slices']);
            
            // now compare slices
            $differences = CompareSliceDefs($template_slice_defs, $dest_slice_defs);
        }
    }
}

if ($_POST['synchronize']) {
    if (is_array($_POST['destination_aa']) ) {
        foreach ($_POST['destination_aa'] as $dest_aa) {
            $sync_result[$aas[$dest_aa]->org_name()] = $aas[$dest_aa]->synchronize($_POST['sync']);
        }
    }
    huhl($sync_result);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
FrmJavascriptFile('javascript/aajslib.php3');

?>
<TITLE><?php echo _m("Central - Synchronize ActionApps (2/3) - Slices to Synchronize"); ?></TITLE>
</HEAD>
<BODY>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", "synchronize");

echo "<H1><B>" . _m("Central - Synchronize ActionApps (2/3) - Slices to Synchronize") . "</B></H1>";
PrintArray($err);
echo $Msg;

// ActionApps to synchronize
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->org_name();
}

// slices to compare
$tmp_slices = $aas[$_POST['template_aa']]->slices();

// in synchronization we are working with !!names!! not ids
foreach ($tmp_slices as $sid => $name) {
    $template_slices[$name] = $name;
}

$form_buttons1 = array("synchronize"  => array( "type"      => "submit",
                                               "value"     => _m("Synchronize"),
                                               "accesskey" => "S")
                     );
                     
$form_buttons2 = array("compare"      => array( "type"      => "submit",
                                               "value"     => _m("Compare"),
                                               "accesskey" => "C"),
                      "template_aa"  => array( "value"     =>  $_POST['template_aa'])
                     );
                     
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<?php

if ( isset($differences) ) {
    FrmTabCaption(_m('Slice Comparison - %1', array($aas[$dest_aa]->org_name())), '','', $form_buttons1);
        // and print diffs out
        foreach ($differences as $slice_name => $diffs) {
            FrmTabSeparator($slice_name);
            foreach ($diffs as $diff) {
                $diff->printOut();
            }
        }
    FrmTabEnd($form_buttons1);
}
            

FrmTabCaption('', '','', $form_buttons2, $sess, $slice_id);
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->org_name());
// prepared for multiple update
FrmInputSelect('destination_aa[]', _m('AA to update'), $aas_array, $_POST['destination_aa'], true, _m('ActionApps installation to update'));
FrmInputMultiChBox('sync_slices[]', _m('Slices to synchronize'), $template_slices, $_POST['sync_slices'], false, '', '', 3);
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</FORM>
<?php
HtmlPageEnd();
page_close();

/*
$foreign_config_file = file_get_contents( COLNODO_LOCAL_INC_DIR . $destination );
    print_r(GrabFromConfig('DB_NAME', $foreign_config_file));



function GrabFromConfig($what, $where) {
    $matches = array();
    $row = substr( $where, strpos($where, $what), 100 );
    $pattern = '/'.$what.'.*, *\"([^"]*)/';
    preg_match($pattern, $row, $matches);
    return $matches[1];
}

$destinations = array_flip(array_unique($COLNODO_DOMAINS));

*/

?>
