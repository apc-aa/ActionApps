<?php
/**
 *  se_compact.php3 - assigns html format for compact view
 * expected $slice_id for edit slice
 * optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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

if (!IfSlPerm(PS_COMPACT)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change compact view formatting"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);

list($fields,) = GetSliceFields($slice_id);

if ( $update )
{
    do
    {
        ValidateInput("odd_row_format", _m("Odd Rows"), $odd_row_format, $err, true, "text");
        ValidateInput("compact_top", _m("Top HTML"), $compact_top, $err, false, "text");
        ValidateInput("compact_bottom", _m("Bottom HTML"), $compact_bottom, $err, false, "text");
        ValidateInput("compact_remove", _m("Remove strings"), $compact_remove, $err, false, "text");
        ValidateInput("noitem_msg", _m("'No item found' message"), $noitem_msg, $err, false, "text");
        if ( $even_odd_differ ) {
            ValidateInput("even_row_format", _m("Even Rows"), $even_row_format, $err, true, "text");
        }
        if ( $group_by ) {
            ValidateInput("category_top", _m("Category top HTML"), $category_top, $err, false, "text");
            ValidateInput("category_format", _m("Category Headline"), $category_format, $err, true, "text");
            ValidateInput("category_bottom", _m("Category bottom HTML"), $category_bottom, $err, false, "text");
        }
        if ( count($err) > 1) {
            break;
        }

        $varset->add("odd_row_format", "quoted", $odd_row_format);
        $varset->add("even_row_format", "quoted", $even_row_format);
        $varset->add("group_by","quoted",$group_by);
        $varset->add("gb_direction","number",$gb_direction);
        $varset->add("gb_header","number",$gb_header);
        $varset->add("category_top", "quoted", $category_top);
        $varset->add("category_format", "quoted", $category_format);
        $varset->add("category_bottom", "quoted", $category_bottom);
        $varset->add("compact_top", "quoted", $compact_top);
        $varset->add("compact_bottom", "quoted", $compact_bottom);
        $varset->add("compact_remove", "quoted", $compact_remove);
        $varset->add("even_odd_differ", "number", $even_odd_differ ? 1 : 0);
        $varset->add("category_sort", "number", $category_sort ? 1 : 0);
          // if not filled, store " " - the empty value displays "No item found" for
          // historical reasons
        $varset->add("noitem_msg", "quoted", $noitem_msg ? $noitem_msg : " " );

        if ( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . "
                          WHERE id='".q_pack_id($slice_id)."'")) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;   // not necessary - we have set the halt_on_error
        }

        $GLOBALS[pagecache]->invalidateFor("slice_id=$slice_id");  // invalidate old cached values
    }while(false);
    if ( count($err) <= 1 ) {
        $Msg = MsgOK(_m("Design of compact design successfully changed"));
    }
}

if ( $slice_id!="" ) {  // set variables from database - allways
/*  $SQL= " SELECT odd_row_format, even_row_format, even_odd_differ, compact_top,
                 compact_bottom, compact_remove, category_sort, category_format,
                 category_top, category_bottom, noitem_msg */
    $SQL = " SELECT *
          FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
    if ($db->next_record()) {
        $odd_row_format  = $db->f('odd_row_format');
        $even_row_format = $db->f('even_row_format');
        $category_top    = $db->f('category_top');
        $category_format = $db->f('category_format');
        $category_bottom = $db->f('category_bottom');
        $compact_top     = $db->f('compact_top');
        $compact_bottom  = $db->f('compact_bottom');
        $compact_remove  = $db->f('compact_remove');
        $even_odd_differ = $db->f('even_odd_differ');
        $group_by        = $db->f('group_by');
        $gb_direction    = $db->f('gb_direction');
        $gb_header       = $db->f('gb_header');
        $category_sort   = $db->f('category_sort');
        if ($group_by) {
            $category_sort = 0;
        }
        $noitem_msg      = $db->f('noitem_msg');
        if (!$group_by && $category_sort) {
            $db->query("SELECT id FROM field WHERE id LIKE 'category.......%' AND slice_id='".q_pack_id($slice_id)."'");
            if ($db->next_record()) {
                $group_by = $db->f("id");
                $gb_direction  = "2";      // number 2 represents 'a' - ascending (because gb_direction in number)
                $gb_header = 0;
            }
            $category_sort = 0; // correct it
        }
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - design Index view");?></title>
<script Language="JavaScript"><!--
function Defaults()
{
  document.f.odd_row_format.value = '<?php echo DEFAULT_ODD_HTML ?>'
  document.f.even_row_format.value = '<?php echo DEFAULT_EVEN_HTML ?> '
  document.f.group_by.selectIndex = -1;
  document.f.gb_direction.selectIndex = 0;
  document.f.gb_header.selectIndex = 0;
  document.f.category_top.value = '<?php echo DEFAULT_CATEGORY_TOP ?>'
  document.f.category_format.value = '<?php echo DEFAULT_CATEGORY_HTML ?>'
  document.f.category_bottom.value = '<?php echo DEFAULT_CATEGORY_BOTTOM ?>'
  document.f.compact_top.value = '<?php echo DEFAULT_TOP_HTML ?>'
  document.f.compact_remove.value = '<?php echo DEFAULT_COMPACT_REMOVE ?>'
  document.f.even_odd_differ.checked = <?php echo (DEFAULT_EVEN_ODD_DIFFER ? "true" : "false"). "\n"; ?>
  document.f.noitem_msg.value = ''
  InitPage()
}

function InitPage() {
  EnableClick('document.f.even_odd_differ','document.f.even_row_format')
  //EnableClick('document.f.category_sort','document.f.category_format')
}

function EnableClick(cond,what) {
  eval(what).disabled=!(eval(cond).checked);
  // property .disabled supported only in MSIE 4.0+
}


// -->
</script>
</head>

<?php
  $useOnLoad = true;
  require_once AA_INC_PATH."menu.php3";
  showMenu ($aamenus, "sliceadmin", "compact");

  echo "<h1><b>" . _m("Admin - design Index view") . "</b></h1>&nbsp;" . _m("Use these boxes ( and the tags listed below ) to control what appears on summary page");
  PrintArray($err);
  echo $Msg;

  $form_buttons = array ("update",
                         "update"  => array ('type' => 'hidden', 'value'=>'1'),
                         "cancel"  => array("url"=>"se_fields.php3"),
                         "default" => array('type' => 'button',
                                            'value' => _m("Default"),
                                            'add' => 'onclick="Defaults()"'));

?>
<form name="f" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
  FrmTabCaption(_m("HTML code for index view"), '','', $form_buttons, $sess, $slice_id);

  // lookup slice fields
  $lookup_fields = GetFields4Select($slice_id, false, 'name', true);

  FrmTextarea("compact_top", _m("Top HTML"), $compact_top, 4, 50, false,
               _m("HTML code which appears at the top of slice area")
               .'<br>'.AA_View::getViewJumpLinks($compact_top), DOCUMENTATION_URL, 1);
  FrmTextarea("odd_row_format", _m("Odd Rows"), $odd_row_format, 6, 50, false,
               _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
               .'<br>'.AA_View::getViewJumpLinks($odd_row_format), DOCUMENTATION_URL, 1);
  FrmInputChBox("even_odd_differ", _m("Use different HTML code for even rows"), $even_odd_differ, true, "OnClick=\"EnableClick('document.f.even_odd_differ','document.f.even_row_format')\"");
  FrmTextarea("even_row_format", _m("Even Rows"), $even_row_format, 6, 50, false,
               _m("You can define different code for odd and ever rows\n                         <br>first red, second black, for example")
               .'<br>'.AA_View::getViewJumpLinks($even_row_format), DOCUMENTATION_URL, 1);
  FrmTextarea("compact_bottom", _m("Bottom HTML"), $compact_bottom, 4, 50, false,
               _m("HTML code which appears at the bottom of slice area")
               .'<br>'.AA_View::getViewJumpLinks($compact_bottom), DOCUMENTATION_URL, 1);
  echo "<tr><td class=\"tabtxt\"><b>"._m("Group by")."</b></td><td>";
  FrmSelectEasy("group_by", $lookup_fields, $group_by);
  echo "<br>"."";
  echo "</td></tr>
  <tr><td>&nbsp;</td><td>";
  FrmSelectEasy("gb_header", array (_m("Whole text"),_m("1st letter"),"2 "._m("letters"),"3 "._m("letters")), $gb_header);
  FrmSelectEasy("gb_direction", array( '2'=>_m("Ascending"), '8' => _m("Descending"), '1' => _m("Ascending by Priority"), '9' => _m("Descending by Priority")  ),
                $gb_direction);
  PrintHelp( _m("'by Priority' is usable just for fields using constants (like category)") );
  echo "<input type=hidden name='category_sort' value='$category_sort'>";
  echo "</td></tr>";
  FrmTextarea("category_top", _m("Category top HTML"), $category_top, 4, 50, false,
               _m("HTML code which appears at the top of slice area")
               .'<br>'.AA_View::getViewJumpLinks($category_top), DOCUMENTATION_URL, 1);
  FrmTextarea("category_format", _m("Category Headline"), $category_format, 6, 50, false,
               _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
               .'<br>'.AA_View::getViewJumpLinks($category_format), DOCUMENTATION_URL, 1);
  FrmTextarea("category_bottom", _m("Category bottom HTML"), $category_bottom, 4, 50, false,
               _m("HTML code which appears at the bottom of slice area")
               .'<br>'.AA_View::getViewJumpLinks($category_bottom), DOCUMENTATION_URL, 1);
  FrmInputText("compact_remove", _m("Remove strings"), $compact_remove, 254, 50, false,
               _m("Removes empty brackets etc. Use ## as delimiter."), DOCUMENTATION_URL);
  FrmTextarea("noitem_msg", _m("'No item found' message"), $noitem_msg, 4, 50, false,
               _m("message to show in place of slice.php3, if no item matches the query")
               .'<br>'.AA_View::getViewJumpLinks($category_bottom), DOCUMENTATION_URL, 1);

  PrintAliasHelp(GetAliasesFromFields($fields), $fields, false, $form_buttons, $sess, $slice_id);

  FrmTabEnd("", false, true);
?>
</form>
<?php HtmlPageEnd();
page_close()?>

