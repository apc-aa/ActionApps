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

# constants select - for searchbar.class.php3, pavelji@ecn.cz

# sid expected - slice_id where to search
# constants_name - constants
# var_id expected - id of variable in calling form, which should be filled
# design expected - boolean - use standard or admin design

$save_hidden = true;   # do not delete r_hidden session variable in init_page!

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "view.php3";
require_once $GLOBALS["AA_INC_PATH"] . "pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"] . "item.php3";
//require_once $GLOBALS["AA_INC_PATH"] . "feeding.php3";
require_once $GLOBALS["AA_INC_PATH"] . "itemfunc.php3";
//require_once $GLOBALS["AA_INC_PATH"] . "notify.php3";
require_once $GLOBALS["AA_INC_PATH"] . "searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"] . "sliceobj.php3";
require_once $GLOBALS["AA_INC_PATH"] . "util.php3";

if ( $sid ) {
  $sess->register(r_sid);
  $r_sid = $sid;
  $sess->register(r_design);  // we are here for the first time  
  $r_design = $design;        // (not scroller or filters) => store $design
}  

/*
echo "<pre>";
echo "slice_id: $slice_id<br>";
echo "constants_name: $field_name<br>";
echo "sel_text: ". $sel_text. "<br>";
echo "var_id: $var_id<br>";
echo "design: $design";
echo "</pre>";
*/

$module_id = $slice_id;
$p_module_id = q_pack_id($module_id); # packed to 16-digit as stored in database
$slice = new slice($module_id);
$slice->fields = $slice->fields('record');

$p_sid= q_pack_id($r_sid);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Editor window - item manager"); echo " - "._m("select constants window");  ?></title>

<script language="JavaScript" type="text/javascript" src="<?php echo get_aa_url("javascript/js_lib.js"); ?>"></script>
<script type="text/javascript">
<!--

    var listboxes=Array();
    
    // select all in select box, needed for submiting all items in wi2 input field
    function SelectAllInBox(myform, listbox) {
          for (var i = 0; i < myform.elements[listbox].length; i++)
              // select all rows without the wIdThTor one, which is only for <select> size setting
             myform.elements[listbox].options[i].selected =
               ( myform.elements[listbox].options[i].value != "wIdThTor" );
    }

    // update changes in parent window
    function updateChanges(myform,varfield, type) {
      window.opener.document.filterform.elements["<?php echo $var_id ?>"].value = "";
      if (type == "wi2") { SelectAllInBox(myform, varfield); }
      if (type == "mch") {
        for (var i = 0; i < myform.elements.length-1; i++) {
            if ((myform.elements[i].name == varfield) && (myform.elements[i].checked == true)) {
                t_val = window.opener.document.filterform.elements["<?php echo $var_id ?>"].value; 
                if (t_val == "") {
                    t_val = '"' + myform.elements[i].value + '"';
                } else {
                    t_val = t_val + ' OR "' + myform.elements[i].value + '"';
                }
                window.opener.document.filterform.elements["<?php echo $var_id ?>"].value = t_val;
            }
        }
      } else {
          for (var i = 0; i < myform.elements[varfield].length; i++) {
              if (myform.elements[varfield].options[i].selected) {
                t_val = window.opener.document.filterform.elements["<?php echo $var_id ?>"].value; 
                if (t_val == "") {
                    t_val = '"' + myform.elements[varfield].options[i].value + '"';
                } else {
                    t_val = t_val + ' OR "' + myform.elements[varfield].options[i].value + '"';
                }
                window.opener.document.filterform.elements["<?php echo $var_id ?>"].value = t_val;
                window.opener.document.filterform.elements["<?php echo $var_id ?>"].value
          }
      }
    }
    
    window.close();
    
    }
//-->
</script> 
</head> <?php

$de = getdate(time());

$zids=QueryZIDs($fields, $r_sid, $conds, $sort, "", $bin_condition);

if($r_design) {
  $format_strings["compact_top"]    = $slice_info['admin_format_top'];
  $format_strings["odd_row_format"] = str_replace('<input type=checkbox name="chb[x_#ITEM_ID#]" value="1">', 
                                                  $mode_string, $slice_info['admin_format']);
  $format_strings["compact_remove"] = $slice_info['admin_remove'];
  $format_strings["compact_bottom"] = $slice_info['admin_format_bottom'];
}  

    $f = $slice->fields[$field_name];
    $fnc = ParseFnc($f["input_show_func"]);   # input show function
    
    switch ($fnc["fnc"]) {
        case "sel" :
        case "pre" :
        case "rio" : $fnc["fnc"] = "wi2"; break;
    }
      
    #get varname - name of field in inputform
    $varname = 'v'. unpack_id($field_name); # "v" prefix - database field var
    $htmlvarname = $varname."html";
    
    $show_fnc_prefix = 'show_fnc_';
    $fncname = $show_fnc_prefix . $fnc["fnc"];

    # parse selected values
    if ($sel_text) {
        $content_tmp = explode(" OR ", $sel_text);
        if (is_array($content_tmp)) {
            for ($i = 0; $i < count($content_tmp); $i++) {
                $content[]["value"] = str_replace("\\\"", "", $content_tmp[$i]);
            }
        }
    }
    
echo "<center>";
echo "$Msg <br>";

$form_buttons = array("var_id" => array("type"=>"hidden", "value"=>$var_id),
                      "btn_ok" => array("type"=>"button",
                                        "value"=> _m("OK"),
                                        "add"=> "onclick=\'javascript:updateChanges(this.form,"'.$varname. ((($fnc["fnc"] == "mse") || ($fnc["fnc"] == "wi2") || ($fnc["fnc"] == "mch") || ($fnc["fnc"] == "hco")) ? "[]" : "").'","'.$fnc["fnc"].'")\'"),
                      "cancel");

# ------- Caption -----------

$table_name = _m("Select constants");

echo "<table border=0 cellspacing=0 class=login width=460>
        <TR><TD align=center class=tablename width=460> $table_name </TD></TR>
        <tr><td align=center width=460>";

echo '      <form name="inputform" method=post action="'. $sess->url($PHP_SELF) .'">';
echo "\n\n<!-- //-->\n\n";
echo "      <table>";
      $fncname($varname, $f, $content, $fnc["param"], 1);
echo "      </table>";
echo "<!-- //-->\n\n";
echo '
            <table width="460" border="0" cellspacing="0" cellpadding="2" bgcolor="#F5F0E7">
            <tr><td><center>
              <input type=hidden name=var_id value='.$var_id.'>
              <input type=button value="'. _m("OK") .'" onclick=\'javascript:updateChanges(this.form,"'.$varname. ((($fnc["fnc"] == "mse") || ($fnc["fnc"] == "wi2") || ($fnc["fnc"] == "mch") || ($fnc["fnc"] == "hco")) ? "[]" : "").'","'.$fnc["fnc"].'")\'>
              <input type=button value="'. _m("Cancel") .'" onclick="window.close()">
            </center></td></tr>  
            </table>
            </form>
      </td></tr>
      </table>
      </center>';
  echo "</body>\n\n</html>";

  $$st_name = $st;   // to save the right scroller 
  page_close();
?>
