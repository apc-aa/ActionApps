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



$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);

if ( $update ) {
    do {
        ValidateInput("fulltext_format_top",    _m("Top HTML code"),      $fulltext_format_top,    $err, false, "text");
        ValidateInput("fulltext_format",        _m("Fulltext HTML code"), $fulltext_format,        $err, false,  "text");
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

        $discus_flag = DB_AA::select1('SELECT `flag` FROM `slice`', 'flag', array(array('id',$slice_id,'l')));
        $discus_flag &= ~ (DISCUS_HTML_FORMAT | DISCUS_ADD_DISABLED); // clear the bits
        $discus_flag |= $discus_htmlf ? DISCUS_HTML_FORMAT : 0;
        $discus_flag |= $discus_disabled ? DISCUS_ADD_DISABLED : 0;

        $varset->add("flag", "number", $discus_flag);
        $varset->add("vid",  "number", $discus_sel);

        $SQL = "UPDATE slice SET ". $varset->makeUPDATE()." WHERE id='".q_pack_id($slice_id)."'";

        if ( !$db->query($SQL)) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;    // not necessary - we have set the halt_on_error
        }

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    } while (false);
    if ( count($err) <= 1 ) {
        $Msg = MsgOk(_m("Fulltext format update successful"));
    }
}

if ( $slice_id != "" ) {  // set variables from database
    $SQL= " SELECT fulltext_format, fulltext_format_top, fulltext_format_bottom, fulltext_remove, flag, vid
            FROM slice WHERE id='". q_pack_id($slice_id)."'";
    $db->query($SQL);
    if ($db->next_record()) {
        $fulltext_format_top    =  $db->f('fulltext_format_top');
        $fulltext_format        =  $db->f('fulltext_format');
        $fulltext_format_bottom =  $db->f('fulltext_format_bottom');
        $fulltext_remove        =  $db->f('fulltext_remove');
        $discus_htmlf           = ($db->f('flag') & DISCUS_HTML_FORMAT)  == DISCUS_HTML_FORMAT;
        $discus_disabled        = ($db->f('flag') & DISCUS_ADD_DISABLED) == DISCUS_ADD_DISABLED;
        $discus_vid             =  $db->f('vid');
    }
}

// lookup discussion views
$discus_vids    = GetTable2Array("SELECT id, name FROM view WHERE slice_id ='$p_slice_id' AND type='discus'",    'id', 'name');

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)





//$aa_property = AA_Metabase::singleton()->getColumnProperty('slice', 'fulltext_format');
//$widget      = $aa_property->defaultWidget();
//$ret         = $widget->getEditHtml($aa_property, )
//
//
//                $widget      = $field->getWidget($widget_type, $widget_properties);
//                $aa_property = $field->getAaProperty($widget->multiple(), ($required==1) ? true : null, $widget_properties['name'], $widget_properties['input_help']);
//                $ret         = $widget->$type($aa_property, $item, $function);
//                $widget->registerRequires();
//





?>
<title><?php echo _m("Admin - design Fulltext view");?></title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/lib/codemirror.css" integrity="sha256-XyDJ6+MvRGhJHdk5i+vjmlocQ4lO6W2q5fahVAcYql0=" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/lib/codemirror.js"  integrity="sha256-/OCc+1HKfnjOGUtgcpPUGj14IzwliaF2X83ws+OlXaU=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/mode/htmlmixed/htmlmixed.js" integrity="sha256-YtBvhTDO4A27QPGmzVTybSqjmNNEi8Uc9KPvyytMoTA=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/mode/xml/xml.js" integrity="sha256-sB8UtHQubuS61s0F+Ui6xNwJHrTXcd3zjCgfb4bOnK4=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/mode/css/css.js" integrity="sha256-lKwXc/xVYQ26OoLFzIMNK0o1tTTYBNMqHbBO7bdoIio=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/mode/javascript/javascript.js" integrity="sha256-6vpQF2FCKaaXS1eBjQHL/Zzf5mUcA8cLLTQ8nVGgZYo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/addon/edit/matchbrackets.js" integrity="sha256-coEcESK5JwnNa8975uTdCcJb553y6HKAwlWs/LCWeuQ=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/addon/edit/matchtags.js" integrity="sha256-aKA2BMUxAIY3kNKzmYhxUFMCiNL/s/qF3zIxvROsnm8=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.30.0/addon/fold/xml-fold.js" integrity="sha256-l/TrijjAvQgiGYppltKp2PLTBGe7s7tPiid0WoaLeMY=" crossorigin="anonymous"></script>
<style>
  .CodeMirror { height:auto; min-height: 100px; font-size: 12px; }
  .CodeMirror-scroll { height: auto; overflow-y: hidden !important; overflow-x: auto !important; }
</style>
<script>
function Defaults() {
    document.f.fulltext_format_top.value    = '';
    document.f.fulltext_format.value        = "<h2>_#HEADLINE</h2>\n<div>\n  <em>_#PUB_DATE</em><br>\n  _#FULLTEXT\n</div>";
    document.f.fulltext_format_bottom.value = '';
    document.f.fulltext_remove.value        = '';
}
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

<form name="f" method="post" action="<?php echo StateUrl($_SERVER['PHP_SELF']) ?>">
<?php
FrmTabCaption(_m("HTML code for fulltext view"), '','', $form_buttons, $sess, $slice_id);
FrmTextarea("fulltext_format_top", _m("Top HTML code"), $fulltext_format_top, 4, 60, false,
             _m("HTML code which appears at the top of slice area")
             .'<br>'.AA_View::getViewJumpLinks($fulltext_format_top), DOCUMENTATION_URL, 1);
FrmTextarea("fulltext_format", _m("Fulltext HTML code"), $fulltext_format, 8, 60, false,
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
FrmInputChBox("discus_disabled", _m("New comments disabled"), $discus_disabled);

$slice = AA_Slice::getModule($slice_id);
PrintAliasHelp($slice->aliases(), $slice->fields('record'), false, $form_buttons, $sess, $slice_id);

FrmTabEnd();
?>
</form>
<script> CodeMirror.fromTextArea(document.getElementById('fulltext_format_bottom'), { lineWrapping:true, matchBrackets: true, matchTags: true, mode: "htmlmixed" }); </script>

<?php
HtmlPageEnd();
page_close()
?>
