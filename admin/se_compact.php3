<?php
/**
 *  se_compact.php3 - assigns html format for compact view
 * expected $slice_id for edit slice
 * optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/item.php3";     // GetAliasesFromField funct def
require_once __DIR__."/../include/msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_COMPACT)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change compact view formatting"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

if ( $update ) {
    do {
        ValidateInput("odd_row_format", _m("Odd Rows"), $odd_row_format, $err, false, "text");
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
        if ( count($err) ) {
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

        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values
    }while(false);
    if ( !count($err) ) {
        $Msg = MsgOk(_m("Design of compact design successfully changed"));
    }
}

if ( $slice_id!="" ) {  // set variables from database - always
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

$headcode = <<<'HTML'
<script>
    function Defaults() {
      document.f.group_by.selectIndex = -1;
      document.f.gb_direction.selectIndex = 0;
      document.f.gb_header.selectIndex = 0;
      document.f.compact_remove.value = '';
      document.f.even_odd_differ.checked = false;
      window.cm_top.setValue("");    
      window.cm_odd.setValue('<article>\n  _#PUB_DATE\n  <h2><a href="_#SEO_URL_">_#HEADLINE</a></h2>\n  <div>_#PUB_DATE | _#PLACE###</div>\n  _#ABSTRACT\n</article>');    
      window.cm_even.setValue("");   
      window.cm_bottom.setValue(""); 
      window.cm_ctop.setValue("");   
      window.cm_cformat.setValue("<p>_#CATEGORY</p>");
      window.cm_cbottom.setValue("");
      window.cm_noitem.setValue("");       
      InitPage()
    }

    function InitPage() {
      [].forEach.call(document.querySelectorAll('tr.cont-even_row_format'), function(e) { e.style.display=(document.f['even_odd_differ'].checked ? 'table-row' : 'none'); });
    }
</script>
HTML;


$script2run = ' 
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_top     = CodeMirror.fromTextArea(document.getElementById("compact_top"), opts);
   window.cm_odd     = CodeMirror.fromTextArea(document.getElementById("odd_row_format"), opts);
   window.cm_even    = CodeMirror.fromTextArea(document.getElementById("even_row_format"), opts);
   window.cm_bottom  = CodeMirror.fromTextArea(document.getElementById("compact_bottom"), opts);
   window.cm_ctop    = CodeMirror.fromTextArea(document.getElementById("category_top"), opts);
   window.cm_cformat = CodeMirror.fromTextArea(document.getElementById("category_format"), opts);
   window.cm_cbottom = CodeMirror.fromTextArea(document.getElementById("category_bottom"), opts);
   window.cm_noitem  = CodeMirror.fromTextArea(document.getElementById("noitem_msg"), opts);
   InitPage();
   ';

$apage = new AA_Adminpageutil('sliceadmin','compact');
$apage->setTitle(_m("Admin - design Index view"));
$apage->setSubtitle(_m("Use these boxes ( and the tags listed below ) to control what appears on summary page"));
$apage->addRequire('codemirror@5');
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);

$form_buttons = [
    "update",
                       "update"  => ['type' => 'hidden', 'value'=>'1'],
                       "cancel"  => ["url"=>"se_fields.php3"],
                       "default" => [
                           'type' => 'button',
                                          'value' => _m("Default"),
                                          'add' => 'onclick="Defaults()"'
                       ]
];

FrmTabCaption(_m("HTML code for index view"), $form_buttons);

// lookup slice fields
$lookup_fields = AA_Fields::getFields4Select($slice_id, false, 'name', true);

FrmTextarea("compact_top", _m("Top HTML"), $compact_top, 4, 50, false,
           _m("HTML code which appears at the top of slice area")
           .'<br>'.AA_View::getViewJumpLinks($compact_top), '', 1);
FrmTextarea("odd_row_format", _m("Odd Rows"), $odd_row_format, 6, 50, false,
           _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
           .'<br>'.AA_View::getViewJumpLinks($odd_row_format), '', 1);
FrmInputChBox("even_odd_differ", _m("Use different HTML code for even rows"), $even_odd_differ, true, "onclick=\"InitPage()\"");
FrmTextarea("even_row_format", _m("Even Rows"), $even_row_format, 6, 50, false,
           _m("You can define different code for odd and ever rows\n                         <br>first red, second black, for example")
           .'<br>'.AA_View::getViewJumpLinks($even_row_format), '', 1);
FrmTextarea("compact_bottom", _m("Bottom HTML"), $compact_bottom, 4, 50, false,
           _m("HTML code which appears at the bottom of slice area")
           .'<br>'.AA_View::getViewJumpLinks($compact_bottom), '', 1);
echo "<tr><td class=\"tabtxt\"><b>"._m("Group by")."</b></td><td>";
FrmSelectEasy("group_by", $lookup_fields, $group_by);
echo "<br>"."";
echo "</td></tr>
<tr><td>&nbsp;</td><td>";
FrmSelectEasy("gb_header", getViewGroupFunctions(), $gb_header);
FrmSelectEasy("gb_direction", ['2'=>_m("Ascending"), '8' => _m("Descending"), '1' => _m("Ascending by Priority"), '9' => _m("Descending by Priority")],
            $gb_direction);
PrintHelp( _m("'by Priority' is usable just for fields using constants (like category)") );
echo "<input type=hidden name='category_sort' value='$category_sort'>";
echo "</td></tr>";
FrmTextarea("category_top", _m("Category top HTML"), $category_top, 4, 50, false,
           _m("HTML code which appears at the top of slice area")
           .'<br>'.AA_View::getViewJumpLinks($category_top), '', 1);
FrmTextarea("category_format", _m("Category Headline"), $category_format, 6, 50, false,
           _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
           .'<br>'.AA_View::getViewJumpLinks($category_format), '', 1);
FrmTextarea("category_bottom", _m("Category bottom HTML"), $category_bottom, 4, 50, false,
           _m("HTML code which appears at the bottom of slice area")
           .'<br>'.AA_View::getViewJumpLinks($category_bottom), '', 1);
FrmInputText("compact_remove", _m("Remove strings"), $compact_remove, 254, 50, false,
           _m("Removes empty brackets etc. Use ## as delimiter."), '');
FrmTextarea("noitem_msg", _m("'No item found' message"), $noitem_msg, 4, 50, false,
           _m("message to show in place of slice.php3, if no item matches the query")
           .'<br>'.AA_View::getViewJumpLinks($category_bottom), '', 1);

$slice = AA_Slice::getModule($slice_id);
PrintAliasHelp($slice->aliases(), $slice->getFields()->getRecordArray(), false, $form_buttons);

FrmTabEnd();

$apage->printFoot();
