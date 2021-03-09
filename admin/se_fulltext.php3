<?php
/** se_fulltext.php3 - assigns html format for fulltext view
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
 *
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

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/item.php3";     // GetAliasesFromField funct def
require_once __DIR__."/../include/msgpage.php3";



//class AA_Adminpage {
//    protected $module_id;
//    protected $perm;
//    protected $name;
//
//    function __construct($module_id, $perm) {
//        $this->module_id = $module_id;
//        $this->perm      = $perm;
//        $this->name      = $name;
//    }
//
//    protected function getCancelUrl() {
//        return get_admin_url('index.php3');
//    }
//    protected function isPerm() {
//        return IfSlPerm($this->perm,$this->module_id);
//    }
//
//    public function process() {
//        if ($_POST['cancel']) {
//            go_url($this->getCancelUrl());
//        }
//        if (!$this-isPerm()) {
//            MsgPageMenu($this->getCancelUrl(), _m('You have not permissions to this page').': '.$this->name, 'admin');
//        }
//
//
//
//    }
//
//}
//
//$apage = new AA_Adminpage(AA::$module_id, PS_FULLTEXT, _m("Admin - design Fulltext view"));
//$apage->process();



$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

if ( $update ) {
    do {
        ValidateInput("fulltext_format_top",    _m("Top HTML code"),      $fulltext_format_top,    $err, false, "text");
        ValidateInput("fulltext_format",        _m("Fulltext HTML code"), $fulltext_format,        $err, false,  "text");
        ValidateInput("fulltext_format_bottom", _m("Bottom HTML code"),   $fulltext_format_bottom, $err, false, "text");
        ValidateInput("fulltext_remove",        _m("Remove strings"),     $fulltext_remove,        $err, false, "text");
        ValidateInput("discus_sel",             _m("Show discussion"),    $discus_sel,             $err, false,  "text");

        if ( count($err) ) {
            break;
        }

        $varset->add("fulltext_format_top", "quoted", $fulltext_format_top);
        $varset->add("fulltext_format", "quoted", $fulltext_format);
        $varset->add("fulltext_format_bottom", "quoted", $fulltext_format_bottom);
        $varset->add("fulltext_remove", "quoted", $fulltext_remove);

        $discus_flag = DB_AA::select1('flag', 'SELECT `flag` FROM `slice`', [['id', $slice_id, 'l']]);
        $discus_flag &= ~ (DISCUS_HTML_FORMAT | DISCUS_ADD_DISABLED); // clear the bits
        $discus_flag |= $discus_htmlf ? DISCUS_HTML_FORMAT : 0;
        $discus_flag |= $discus_disabled ? DISCUS_ADD_DISABLED : 0;

        $varset->add("flag", "number", $discus_flag);
        $varset->add("vid",  "number", $discus_sel);
        $varset->addkey("id", "unpacked", $slice_id);

        if ( !$varset->doUpdate('slice', null, $slice_id) ) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;    // not necessary - we have set the halt_on_error
        }

        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values

    } while (false);
    if ( !count($err) ) {
        $Msg = MsgOk(_m("Fulltext format update successful"));
    }
}

if ( $slice_id != "" ) {  // set variables from database
    $rec = DB_AA::select1('', 'SELECT fulltext_format, fulltext_format_top, fulltext_format_bottom, fulltext_remove, flag, vid FROM slice', [['id', $slice_id, 'l']]);
    if ($rec) {
        $fulltext_format_top    =  $rec['fulltext_format_top'];
        $fulltext_format        =  $rec['fulltext_format'];
        $fulltext_format_bottom =  $rec['fulltext_format_bottom'];
        $fulltext_remove        =  $rec['fulltext_remove'];
        $discus_htmlf           = ($rec['flag'] & DISCUS_HTML_FORMAT)  == DISCUS_HTML_FORMAT;
        $discus_disabled        = ($rec['flag'] & DISCUS_ADD_DISABLED) == DISCUS_ADD_DISABLED;
        $discus_vid             =  $rec['vid'] ?: '';
    }
}


$script2run = ' 
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_top  = CodeMirror.fromTextArea(document.getElementById("fulltext_format_top"), opts);
   window.cm_code = CodeMirror.fromTextArea(document.getElementById("fulltext_format"), opts);
   window.cm_bot  = CodeMirror.fromTextArea(document.getElementById("fulltext_format_bottom"), opts);
   ';
$headcode = '<script>
  function Defaults() {
    window.cm_top.setValue("");
    window.cm_code.setValue("<h2>_#HEADLINE</h2>\n<div>\n  <em>_#PUB_DATE</em><br>\n  _#FULLTEXT\n</div>");
    window.cm_bot.setValue("");
    document.f.fulltext_remove.value        = \'\';
  }
</script>';

$apage = new AA_Adminpageutil('sliceadmin','fulltext');
$apage->setTitle(_m("Admin - design Fulltext view"));
$apage->setSubtitle(_m("Use these boxes ( with the tags listed below ) to control what appears on full text view of each item"));
$apage->addRequire('codemirror@5');
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);


// lookup discussion views
$discus_vids = DB_AA::select(['id'=>'name'], "SELECT id, name FROM view", [
    ['slice_id', $slice_id, 'l'],
    ['type','discus']
]);

$form_buttons = [
    "update",
                       "update" => ['type' => 'hidden', 'value'=>'1'],
                       "cancel" => ["url"=>"se_fields.php3"],
                       "default" => [
                           'type'  => 'button',
                                          'value' => _m("Default"),
                                          'add'   => 'onclick="Defaults()"'
                       ]
];

FrmTabCaption(_m("HTML code for fulltext view"), $form_buttons);
FrmTextarea("fulltext_format_top", _m("Top HTML code"), $fulltext_format_top, 4, 60, false,
             _m("HTML code which appears at the top of slice area")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format_top), '', 1);
FrmTextarea("fulltext_format", _m("Fulltext HTML code"), $fulltext_format, 8, 60, false,
             _m("Put here the HTML code combined with aliases form bottom of this page\n                     <br>The aliases will be substituted by real values from database when it will be posted to page")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format), '', 1);
FrmTextarea("fulltext_format_bottom", _m("Bottom HTML code"), $fulltext_format_bottom, 4, 60, false,
             _m("HTML code which appears at the bottom of slice area")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format_bottom), '', 1);
FrmInputText("fulltext_remove", _m("Remove strings"), $fulltext_remove, 254, 50, false,
             _m("Removes empty brackets etc. Use ## as delimiter."), '');
if ($discus_vids) {
    FrmInputSelect("discus_sel", _m("Show discussion"), $discus_vids, $discus_vid, false, _m("The template for dicsussion you can set on \"Design\" -> \"View\" page"));
    FrmInputChBox("discus_htmlf", _m("Use HTML tags"), $discus_htmlf);
    FrmInputChBox("discus_disabled", _m("New comments disabled"), $discus_disabled);
} else {
    FrmStaticText(_m("Show discussion"), '--', _m("The template for dicsussion you can set on \"Design\" -> \"View\" page"));
};

$slice = AA_Slice::getModule($slice_id);
PrintAliasHelp($slice->aliases(), $slice->getFields()->getRecordArray(), false, $form_buttons);

FrmTabEnd();

$apage->printFoot();
