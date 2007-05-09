<?php
/**
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
*/


require_once "../include/init_page.php3";
require_once AA_INC_PATH. 'formutil.php3';
require_once AA_INC_PATH. 'remote.class.php3';

//$text      = magic_strip($text);

if (!IsSuperadmin()) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
    exit;
}


$foreign_config_file = file_get_contents( COLNODO_LOCAL_INC_DIR . $destination );
    print_r(GrabFromConfig('DB_NAME', $foreign_config_file));

/** GrabFromConfig function
 *  @param string $what
 *  @param string $where
 *  @return string
 */
function GrabFromConfig($what, $where) {
    $matches = array();
    $row = substr( $where, strpos($where, $what), 100 );
    $pattern = '/'.$what.'.*, *\"([^"]*)/';
    preg_match($pattern, $row, $matches);
    return $matches[1];
}


if ($submit) {

}


//$slice = AA_Slices::getSlice($slice_id);

// Upload a data to the server. The file name is generated automaticly
// by unique id function. The path is upload_directory/csv_data.
// Delete old csv data in the upload_directory/csv_data.

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - Synchronize ActionApps"); ?></title>
<script Language="JavaScript"><!--
function InitPage() {}
//-->
</script>
</head>
<body>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "aaadmin", "synchronize");

echo "<h1><b>" . _m("Admin - Synchronize ActionApps (1/3) - Destination ActionApps") . "</b></h1>";
PrintArray($err);
echo $Msg;

$form_buttons = array ("submit");
$destinations = array_flip(array_unique($COLNODO_DOMAINS));

?>
<form name="f" method="post" action="<?php echo $sess->url(self_base() . "aa_synchronize2.php3") ?>">
<?php
FrmTabCaption('', '','', $form_buttons, $sess, $slice_id);
FrmStaticText(_m('Template ActionApps (current)'), $this_colnodo_domain);
FrmInputSelect("destination", _m("Select destination ActionApps"), $destinations, $destination, true,
    _m("The list is taken from config.php3 file of ActionApps"));
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()
?>
