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

# expected $slice_id for edit slice, nothing for adding slice

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

function EditConstantURL() {
  global $fld, $sess;
  if( substr($fld[id],0,8)== "category" )
    return con_url($sess->url(self_base() . "se_constant.php3"), "categ=1");
   else  
    return $sess->url(self_base() . "se_constant.php3");
}

if($cancel)
  go_url( $sess->url(self_base() . "./se_fields.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if( $del ) {
  $SQL = "DELETE FROM field WHERE id='$fid' AND slice_id='$p_slice_id'";
  if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
    $err["DB"] = MsgErr("Can't change field");
    break;
  }
  $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
  $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

  $Msg = MsgOK(L_FIELD_DELETE_OK);
  go_url( $sess->url("./se_fields.php3") );  # back to field page
}

if( $update ) {
  do {
    ValidateInput("input_before", L_INPUT_BEFORE, $input_before, &$err, false, "text");
    ValidateInput("input_help", L_INPUT_HELP, $input_help, &$err, false, "text");
    ValidateInput("input_morehlp", L_INPUT_MOREHLP, $input_morehlp, &$err, false, "text");
    ValidateInput("input_default", L_INPUT_DEFAULT, $input_default, &$err, false, "text");
    ValidateInput("input_show_func", L_INPUT_SHOW_FUNC, $input_show_func_f, &$err, false, "text");

    ValidateInput("alias1", L_ALIAS1, $alias1, &$err, false, "alias");
    ValidateInput("alias1_help", L_ALIAS1_HELP, $alias1_help, &$err, false, "text");
    ValidateInput("alias1_func", L_ALIAS1_FUNC, $alias1_func, &$err, false, "text");
    ValidateInput("alias2", L_ALIAS1, $alias2, &$err, false, "alias");
    ValidateInput("alias2_help", L_ALIAS1_HELP, $alias2_help, &$err, false, "text");
    ValidateInput("alias2_func", L_ALIAS1_FUNC, $alias2_func, &$err, false, "text");
    ValidateInput("alias3", L_ALIAS1, $alias3, &$err, false, "alias");
    ValidateInput("alias3_help", L_ALIAS1_HELP, $alias3_help, &$err, false, "text");
    ValidateInput("alias3_func", L_ALIAS1_FUNC, $alias3_func, &$err, false, "text");
      
    if( count($err) > 1)
      break;

    $varset->add("input_before", "quoted", $input_before);
    $varset->add("input_help", "quoted", $input_help);
    $varset->add("input_morehlp", "quoted", $input_morehlp);
    $varset->add("input_default", "quoted", "$input_default_f:$input_default");
    $varset->add("multiple", "quoted", (($input_show_func_f=="mch")
                                     OR ($input_show_func_f=="mse")) ? 1 : 0);  #mark as multiple

    $varset->add("alias1", "quoted", $alias1);
    $varset->add("alias1_help", "quoted", $alias1_help);
    $varset->add("alias1_func", "quoted", "$alias1_func_f:$alias1_func");
    $varset->add("alias2", "quoted", $alias2);
    $varset->add("alias2_help", "quoted", $alias2_help);
    $varset->add("alias2_func", "quoted", "$alias2_func_f:$alias2_func");
    $varset->add("alias3", "quoted", $alias3);
    $varset->add("alias3_help", "quoted", $alias3_help);
    $varset->add("alias3_func", "quoted", "$alias3_func_f:$alias3_func");

    switch( $input_show_func_f ) {
      case "fld":
      case "fil":
      case "txt":
      case "dte": $isf = "$input_show_func_f:$input_show_func";
                  break;
      case "mch":
      case "rio":
      case "sel": $isf = "$input_show_func_f:$input_show_func_c";
                  break;
      case "mse": $isf = "$input_show_func_f:$input_show_func_c:$input_show_func";
                  break;
      default: $isf = "$input_show_func_f";
    }  
    $varset->add("input_show_func", "quoted", "$isf");
    $varset->add("input_validate", "quoted", "$input_validate");
    $varset->add("feed", "quoted", "$feed");
    $varset->add("input_insert_func", "quoted", $input_insert_func);
    $varset->add("html_default", "number", ($html_default ? 1 : 0));
    $varset->add("html_show", "number", ($html_show ? 1 : 0));
    $varset->add("text_stored", "number", ((($input_validate=="number") 
                                         OR ($input_validate=="bool")  
                                         OR ($input_validate=="date")) ? 0:1));
    $SQL = "UPDATE field SET ". $varset->makeUPDATE() . 
           " WHERE id='$fid' AND slice_id='$p_slice_id'";

    if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
      $err["DB"] = MsgErr("Can't change field");
      break;
    }
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

    if( count($err) <= 1 ) {
      $Msg = MsgOK(L_FIELDS_OK);
//      go_url( $sess->url($PHP_SELF) ); 
    }    
  } while( 0 );           #in order we can use "break;" statement
} 

  # lookup constants
$constants = GetConstants('lt_groupNames', $db);

  # lookup fields
$SQL = "SELECT * FROM field
         WHERE slice_id='$p_slice_id' AND id='$fid'
         ORDER BY input_pri";
$db->query($SQL);
if( $db->next_record()) 
  $fld = $db->Record;
else {
  $Msg = MsgErr(L_NO_FIELDS);
  go_url( $sess->url("./se_fields.php3") );  # back to field page
}    

if( !$update ) {      # load defaults
  $input_before = $fld[input_before];
  $input_help = $fld[input_help];
  $input_morehlp = $fld[input_morehlp];

  $alias1 = $fld[alias1];
  $alias1_help = $fld[alias1_help];
  $alias1_func_f = substr($fld[alias1_func],0,3);
  $alias1_func = substr($fld[alias1_func],4);

  $alias2 = $fld[alias2];
  $alias2_help = $fld[alias2_help];
  $alias2_func_f = substr($fld[alias2_func],0,3);
  $alias2_func = substr($fld[alias2_func],4);

  $alias3 = $fld[alias3];
  $alias3_help = $fld[alias3_help];
  $alias3_func_f = substr($fld[alias3_func],0,3);
  $alias3_func = substr($fld[alias3_func],4);
  
  $input_default_f = substr($fld[input_default],0,3);
  $input_default = substr($fld[input_default],4);
  $input_show_func_f = substr($fld[input_show_func],0,3);
  switch( $input_show_func_f ) {
    case "txt":
    case "fld":
    case "dte":
    case "fil": $input_show_func = substr($fld[input_show_func],4);
                break;
    case "mse": $pos = strpos($fld[input_show_func], ":", 4);
                $input_show_func_c = substr($fld[input_show_func],4,$pos-4);
                $input_show_func = substr($fld[input_show_func],$pos+1);
                break;
    default:    $input_show_func_c = substr($fld[input_show_func],4);
  }  
  $input_insert_func = $fld[input_insert_func];
  $html_default = $fld[html_default];
  $html_show = $fld[html_show];
  $text_stored = $fld[text_stored];
  $input_validate = $fld[input_validate];
  $feed = $fld[feed];
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FIELDS_TIT;?></TITLE>
<script language="JavaScript"><!--
  function CallConstantEdit() {
    var url = "<?php echo EditConstantURL(); ?>"
    var conid = document.f.input_show_func_c.options[document.f.input_show_func_c.selectedIndex].value
    if( conid != "" )
      document.location=(url + "&group_id=" + escape(conid))
  }
//-->
</script>
</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx,"mapping"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_FIELDS_EDT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
  echo L_WARNING_NOT_CHANGE;

echo "
<form enctype=\"multipart/form-data\" method=post action=\"". $sess->url($PHP_SELF) ."\" name=\"f\">
 <table width=\"70%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr>
   <td class=tabtit><b>&nbsp;". L_FIELDS_HDR ."</b></td>
  </tr>
  <tr>
   <td>
    <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
     <tr>
      <td class=tabtxt><b>". L_FIELD ."</b></td>
      <td class=tabtxt>".  safe($fld[name]) ."</td>
      <td class=tabtxt><b>". L_ID ."</b></td>
      <td class=tabtxt>". safe($fld[id]) ."</td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". L_INPUT_FUNC ."</b></td>
      <td class=tabtxt colspan=3>";
       FrmSelectEasy("input_show_func_f", $INPUT_SHOW_FUNC_TYPES, $input_show_func_f);
      echo "<div class=tabhlp>". L_INPUT_SHOW_FUNC_F_HLP ."</div>
            <div class=tabtxt><b>". L_CONSTANTS ."</b> ";
        FrmSelectEasy("input_show_func_c", $constants, $input_show_func_c);
      echo "<a href='javascript:CallConstantEdit()'>". L_EDIT ."</a>
            <a href='". EditConstantURL(). "'>". L_NEW ."</a>
      </div> 
            <div class=tabhlp>". L_INPUT_SHOW_FUNC_C_HLP ."</div>
            <div class=tabtxt><b>". L_PARAMETERS ."</b>
              <input type=\"Text\" name=\"input_show_func\" size=25 maxlength=240 value=\"". safe($input_show_func) ."\">
            </div> 
            <div class=tabhlp>". L_INPUT_SHOW_FUNC_HLP ."</div>
      </td>
     </tr>  
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". L_DEFAULT ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("input_default_f", $INPUT_DEFAULT_TYPES, $input_default_f);
      echo "<div class=tabhlp>". L_INPUT_DEFAULT_F_HLP ."</div>
            <div class=tabtxt><b>". L_PARAMETERS ."</b>
              <input type=\"Text\" name=\"input_default\" size=25 value=\"". safe($input_default) ."\">
            </div> 
            <div class=tabhlp>". L_INPUT_DEFAULT_HLP ."</div>
      </td>
     </tr>  
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". L_VALIDATE ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("input_validate", $INPUT_VALIDATE_TYPES, $input_validate);
      echo "<div class=tabhlp>". L_INPUT_VALIDATE_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_INSERT ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("input_insert_func", $INPUT_INSERT_TYPES, $input_insert_func);
      echo "<div class=tabhlp>". L_INPUT_INSERT_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_HTML_SHOW ."</b></td>
      <td class=tabtxt><input type=\"checkbox\" name=\"html_show\"". ($html_show ? " checked" : "") ."></td>
      <td class=tabtxt><b>". L_HTML_DEFAULT ."</b></td>
      <td class=tabtxt><input type=\"checkbox\" name=\"html_default\"". ($html_default ? " checked" : "") ."></td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_INPUT_HELP ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"input_help\" size=50 maxlength=254 value=\"". safe($input_help). "\">
      <div class=tabhlp>". L_INPUT_HELP_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_INPUT_MOREHLP ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"input_morehlp\" size=50 maxlength=254 value=\"". safe($input_morehlp) ."\">
      <div class=tabhlp>". L_INPUT_MOREHLP_HLP ."</div>
      </td>
     </tr>
     <tr>
      <td class=tabtxt><b>". L_INPUT_BEFORE ."</b></td>
      <td class=tabtxt colspan=3><textarea name=\"input_before\" rows=4 cols=50 wrap=virtual>". safe($input_before) ."</textarea>
      <div class=tabhlp>". L_INPUT_BEFORE_HLP ."</div>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". L_FEED_STATE ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("feed", $INPUT_FEED_MODES, $feed);
      echo "<div class=tabhlp>". L_INPUT_FEED_MODES_HLP ."</div>
      </td>
     </tr>  
    </table>
   </td>
  </tr>  
  <tr>
   <td class=tabtit><b>&nbsp;". L_ALIASES ."</b></td>
  </tr>
  <tr>
   <td>
    <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
     <tr>
      <td class=tabtxt><b>". L_ALIAS1 ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias1\" size=20 maxlength=10 value=\"". safe($alias1) ."\">
      <div class=tabhlp>". L_ALIAS_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_FUNC ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("alias1_func_f", $ALIAS_FUNC_TYPES, $alias1_func_f);
      echo "<div class=tabhlp>". L_ALIAS_FUNC_F_HLP ."</div>
            <div class=tabtxt><b>". L_PARAMETERS ."</b>
              <input type=\"Text\" name=\"alias1_func\" size=25 maxlength=250 value=\"". safe($alias1_func) ."\">
            </div> 
            <div class=tabhlp>". L_ALIAS_FUNC_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_HELP ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias1_help\" size=50 maxlength=254 value=\"". safe($alias1_help) ."\">
      <div class=tabhlp>". L_ALIAS_HELP_HLP ."</div>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
  
     <tr>
      <td class=tabtxt><b>". L_ALIAS2 ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias2\" size=20 maxlength=10 value=\"". safe($alias2) ."\">
      <div class=tabhlp>". L_ALIAS_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_FUNC ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("alias2_func_f", $ALIAS_FUNC_TYPES, $alias2_func_f);
      echo "<div class=tabhlp>". L_ALIAS_FUNC_F_HLP ."</div>
            <div class=tabtxt><b>". L_PARAMETERS ."</b>
              <input type=\"Text\" name=\"alias2_func\" size=25 maxlength=250 value=\"". safe($alias2_func) ."\">
            </div> 
            <div class=tabhlp>". L_ALIAS_FUNC_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_HELP ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias2_help\" size=50 maxlength=254 value=\"". safe($alias2_help) ."\">
      <div class=tabhlp>". L_ALIAS_HELP_HLP ."</div>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>

     <tr>
      <td class=tabtxt><b>". L_ALIAS3 ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias3\" size=20 maxlength=10 value=\"". safe($alias3) ."\">
      <div class=tabhlp>". L_ALIAS_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_FUNC ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("alias3_func_f", $ALIAS_FUNC_TYPES, $alias3_func_f);
      echo "<div class=tabhlp>". L_ALIAS_FUNC_F_HLP ."</div>
            <div class=tabtxt><b>". L_PARAMETERS ."</b>
              <input type=\"Text\" name=\"alias3_func\" size=25 maxlength=250 value=\"". safe($alias3_func) ."\">
            </div> 
            <div class=tabhlp>". L_ALIAS_FUNC_HLP ."</div>
      </td>
     </tr>  
     <tr>
      <td class=tabtxt><b>". L_ALIAS_HELP ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"alias3_help\" size=50 maxlength=254 value=\"". safe($alias3_help) ."\">
      <div class=tabhlp>". L_ALIAS_HELP_HLP ."</div>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
    </table>
   </td>
  </tr>
  
  <tr>
   <td align=\"center\">
    <input type=hidden name=\"update\" value=1>
    <input type=hidden name=\"fid\" value=\"$fid\">
    <input type=submit name=update value=\"". L_UPDATE ."\">&nbsp;&nbsp;
    <input type=submit name=cancel value=\"". L_CANCEL ."\">&nbsp;&nbsp;
   </td></tr></table>
 </FORM>
</BODY>
</HTML>";

/*
$Log$
Revision 1.13  2001/06/21 14:15:45  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.12  2001/06/12 16:00:54  honzam
date inputs support time, now
new multivalue input possibility - <select multiple>

Revision 1.11  2001/06/03 15:57:45  honzam
multiple categories (multiple values at all) for item now works

Revision 1.10  2001/05/21 13:52:31  honzam
New "Field mapping" feature for internal slice to slice feeding

Revision 1.9  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.8  2001/04/09 20:43:28  honzam
fixed bug of not stored parameter for field input type in se_inputform.php3

Revision 1.6  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.5  2001/02/26 17:26:08  honzam
color profiles

Revision 1.4  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.2  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.1.1.1  2000/06/21 18:39:59  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:49  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.9  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/

page_close()?>