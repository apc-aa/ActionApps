<?php
/** se_fulltext.php3 - assigns html format for fulltext view
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fulltext formatting"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);

list($fields,) = GetSliceFields($slice_id);

if ( $update ) {
    do {
        ValidateInput("fulltext_format_top",    _m("Top HTML code"),      $fulltext_format_top,    $err, false, "text");
        ValidateInput("fulltext_format",        _m("Fulltext HTML code"), $fulltext_format,        $err, true,  "text");
        ValidateInput("fulltext_format_bottom", _m("Bottom HTML code"),   $fulltext_format_bottom, $err, false, "text");
        ValidateInput("fulltext_remove",        _m("Remove strings"),     $fulltext_remove,        $err, false, "text");
        ValidateInput("discus_sel",             _m("Show discussion"),    $discus_sel,             $err, false,  "text");

        if ( count($err) > 1) {
            break;
        }

        $varset->add("fulltext_format_top", "quoted", $fulltext_format_top);
        $varset->add("fulltext_format", "quoted", $fulltext_format);
        $varset->add("fulltext_format_bottom", "quoted", $fulltext_format_bottom);
        $varset->add("fulltext_remove", "quoted", $fulltext_remove);
        $varset->add("flag", "number", $discus_htmlf ? 1 : 0);
        $varset->add("vid", "number", $discus_sel);


        $SQL = "UPDATE slice SET ". $varset->makeUPDATE().
               " WHERE id='".q_pack_id($slice_id)."'";

        if ( !$db->tquery($SQL)) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;    // not necessary - we have set the halt_on_error
        }

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    } while (false);
    if ( count($err) <= 1 ) {
        $Msg = MsgOK(_m("Fulltext format update successful"));
    }
}

if ( $slice_id != "" ) {  // set variables from database
    $SQL= " SELECT fulltext_format, fulltext_format_top, fulltext_format_bottom,
                   fulltext_remove, flag, vid
            FROM slice WHERE id='". q_pack_id($slice_id)."'";
    $db->query($SQL);
    if ($db->next_record()) {
        $fulltext_format_top    =  $db->f('fulltext_format_top');
        $fulltext_format        =  $db->f('fulltext_format');
        $fulltext_format_bottom =  $db->f('fulltext_format_bottom');
        $fulltext_remove        =  $db->f('fulltext_remove');
        $discus_htmlf           = ($db->f('flag') & DISCUS_HTML_FORMAT) == DISCUS_HTML_FORMAT;
        $discus_vid             =  $db->f('vid');
    }
}

// lookup discussion views
$discus_vids    = GetTable2Array("SELECT id, name FROM view WHERE slice_id ='$p_slice_id' AND type='discus'",    'id', 'name');

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - design Fulltext view");?></title>
<script Language="JavaScript"><!--
function Defaults() {
    document.f.fulltext_format_top.value    = '<?php echo DEFAULT_FULLTEXT_TOP ?>'
    document.f.fulltext_format.value        = '<?php echo DEFAULT_FULLTEXT_HTML ?>'
    document.f.fulltext_format_bottom.value = '<?php echo DEFAULT_FULLTEXT_BOTTOM ?>'
    document.f.fulltext_remove.value        = '<?php echo DEFAULT_FULLTEXT_REMOVE ?>'
}
// -->
</script>
</head>

<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", "fulltext");

echo "<h1><b>" . _m("Admin - design Fulltext view") . "</b></h1>&nbsp;&nbsp;" . _m("Use these boxes ( with the tags listed below ) to control what appears on full text view of each item");
PrintArray($err);
echo $Msg;

$form_buttons = array ("update",
                       "update" => array ('type' => 'hidden', 'value'=>'1'),
                       "cancel" => array ("url"=>"se_fields.php3"),
                       "default" => array('type'  => 'button',
                                          'value' => _m("Default"),
                                          'add'   => 'onclick="Defaults()"'));

?>
<form name="f" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
FrmTabCaption(_m("HTML code for fulltext view"), '','', $form_buttons, $sess, $slice_id);
FrmTextarea("fulltext_format_top", _m("Top HTML code"), $fulltext_format_top, 4, 60, false,
             _m("HTML code which appears at the top of slice area")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format_top), DOCUMENTATION_URL, 1);
FrmTextarea("fulltext_format", _m("Fulltext HTML code"), $fulltext_format, 8, 60, true,
             _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format), DOCUMENTATION_URL, 1);
FrmTextarea("fulltext_format_bottom", _m("Bottom HTML code"), $fulltext_format_bottom, 4, 60, false,
             _m("HTML code which appears at the bottom of slice area")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format_bottom), DOCUMENTATION_URL, 1);
FrmInputText("fulltext_remove", _m("Remove strings"), $fulltext_remove, 254, 50, false,
             _m("Removes empty brackets etc. Use ## as delimiter."), DOCUMENTATION_URL);
FrmInputSelect("discus_sel", _m("Show discussion"), $discus_vids, $discus_vid, false,
             _m("The template for dicsussion you can set on \"Design\" -> \"View\" page"));
FrmInputChBox("discus_htmlf", _m("Use HTML tags"), $discus_htmlf);

PrintAliasHelp(GetAliasesFromFields($fields),$fields, false, $form_buttons, $sess, $slice_id);

FrmTabEnd("", $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()
?>
