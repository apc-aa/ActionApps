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
 
# expected at least $slice_id 
# user calling is with $edit for edit item
# optionaly encap="false" if this form is not encapsulated into *.shtml file
# optionaly free and freepwd for anonymous user login (free == login, freepwd == password)


$encap = ( ($encap=="false") ? false : true );

if( $edit OR $add )         # parameter for init_page - we edited new item so 
  $unset_r_hidden = true;   # clear stored content


require "../include/init_page.php3";     # This pays attention to $change_id
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";
require $GLOBALS[AA_INC_PATH]."javascript.php3";

// needed for field JavaScript to work 
$js_trig = getTrig();

if( file_exists( $GLOBALS[AA_INC_PATH]."usr_validate.php3" ) ) {
  include( $GLOBALS[AA_INC_PATH]."usr_validate.php3" );
}

if ($encap) add_vars();        # adds values from QUERY_STRING_UNESCAPED 
                               #       and REDIRECT_STRING_UNESCAPED - from url

QuoteVars("post", array('encap'=>1) );  # if magicquotes are not set, quote variables
                                        # but skip (already edited) encap variable

GetHidden();        // unpacks variables from $r_hidden session var.
unset($r_hidden);
$r_hidden["hidden_acceptor"] = (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF);
                    // only this script accepts r_hidden variable
                    // - if it don't match - unset($r_hidden) (see init_page.pgp3)

if( $ins_preview )
  $insert = true;
if( $upd_preview )
  $update = true;
  
$add = !( $update OR $cancel OR $insert OR $edit );

if($cancel) {
  if( $anonymous ) { // anonymous login
    if( $encap ) {
      echo '<SCRIPT Language="JavaScript"><!--
              document.location = "'. $r_slice_view_url .'";
            // -->
           </SCRIPT>';
    } else
      go_url( $r_slice_view_url );
  }
  else 
    go_return_or_url(self_base() . "index.php3",1,1,"slice_id=$slice_id");
}
$db = new DB_AA;

$err["Init"] = "";          // error array (Init - just for initializing variable

$varset = new Cvarset();
$itemvarset = new Cvarset();

  # get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($slice_id);

if( isset($prifields) AND is_array($prifields) ) {

    // javascript for input validation 
    $js_proove_fields = "
         <SCRIPT language=javascript>
            <!--"
            . get_javascript_field_validation (). "
                function proove_fields () {
                    myform = document.inputform;\n";

    #it is needed to call IsEditable() function and GetContentFromForm()
    if( $update ) {
        $oldcontent = GetItemContent($id);
        $oldcontent4id = $oldcontent[$id];   # shortcut
    }

	reset($prifields);
	while(list(,$pri_field_id) = each($prifields)) {
        $f = $fields[$pri_field_id];
        $varname = 'v'. unpack_id($pri_field_id);  # "v" prefix - database field var
        $htmlvarname = $varname."html";

        if( $add OR (!$f[input_show] AND ($insert OR $update) )) {
            $$varname = GetDefault($f);
            $$htmlvarname = GetDefaultHTML($f);
        }

        # determine if we have to use enctype="multipart/form-data" type of form
        if ( substr($f['input_show_func'], 0, 3) == 'fil')  # uses fileupload?
          $html_form_type = 'enctype="multipart/form-data"';

        # prepare javascript function for validation of the form
        switch( $f[input_validate] ) {
            case 'text':
            case 'url':
            case 'email':
            case 'number':
            case 'id':
                $js_proove_fields .= "
                    if (!validate (myform['$varname'], '$f[input_validate]', "
                        .($f[required] ? "1" : "0")."))
                        return false;";
                break;
        }

          # validate input data
        if( ( $insert || $update )
            && IsEditable($oldcontent4id[$pri_field_id], $f)) {
            switch( $f[input_validate] ) {
                case 'text':
                case 'url':
                case 'email':
                case 'number':
                case 'id':
                    ValidateInput($varname, $f[name], $$varname, $err,
                              $f[required] ? 1 : 0, $f[input_validate]);
                    break;
                case 'date':
                    $foo_datectrl_name = new datectrl($varname);
                    $foo_datectrl_name->update();                   # updates datectrl
                    if( $$varname != "")                            # loaded from defaults
                      $foo_datectrl_name->setdate_int($$varname);
                    $foo_datectrl_name->ValidateDate($f[name], $err);
                    $$varname = $foo_datectrl_name->get_date();  # write to var
                    break;
                case 'bool':
                    $$varname = ($$varname ? 1 : 0);
                    break;
                case 'user':
                    // this is under development.... setu, 2002-0301
                    // value can be modified by $$varname = "new value";
                    $$varname = usr_validate($varname, $f[name], $$varname, $err, $f, $fields);
                    ##	echo "ItemEdit- user value=".$$varname."<br>";
                    break;
            }
        }
    }

    $js_proove_fields .= "
                    return true;
                }
            // -->
         </script>";
}

  # update database
if( ($insert || $update) AND (count($err)<=1)
    AND isset($prifields) AND is_array($prifields) ) {

  # prepare content4id array before call StoreItem function
  $content4id = GetContentFromForm( $fields, $prifields, $oldcontent4id, $insert );

  # remove the ANONYMOUS_EDITABLE flag
  if ($content4id["flags..........."][0]['value'] & ITEM_FLAG_ANONYMOUS_EDITABLE)
    $content4id["flags..........."][0]['value'] -= ITEM_FLAG_ANONYMOUS_EDITABLE;

  if( $insert )
    $id = new_id();

  $added_to_db = StoreItem( $id, $slice_id, $content4id, $fields, $insert,
                            true, true );     # invalidatecache, feed

  if( count($err) <= 1) {
    page_close();
    if( $anonymous )  // anonymous login
      if( $encap ) {
        echo '<SCRIPT Language="JavaScript"><!--
                document.location = "'. $r_slice_view_url .'";
              // -->
             </SCRIPT>';
      } else
        go_url( $r_slice_view_url );
    elseif( $ins_preview OR $upd_preview )
      go_url( con_url($sess->url(self_base() .  "preview.php3"), "slice_id=$slice_id&sh_itm=$id"));
    else  {
      go_return_or_url(self_base() . "index.php3",1,1);
    }
  }
}

# -----------------------------------------------------------------------------
# Input form
# -----------------------------------------------------------------------------

unset( $content );       # used in another context for storing item to db
unset( $content4id );

if($edit) {
  if( !(isset($fields) AND is_array($fields)) ) {
    $err["DB"] = MsgErr(L_ERR_NO_FIELDS);
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }

    # fill content array from item and content tables
  $content = GetItemContent($id);
  if( !$content ) {
    $err["DB"] = MsgErr(_m("Bad item ID"));
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }
  $content4id = $content[$id];
}

//print_r($content);

# print begin ---------------------------------------------------------------

if( !$encap ) {
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  echo '
    <style>
    #body_white_color { color: #000000; }
    </style>
    <title>'.( $edit=="" ? _m("Add Item") : _m("Edit Item")). '</title>
    <script Language="JavaScript"><!--
      function SelectAllInBox( listbox ) {
        var len = eval(listbox).options.length
        for (var i = 0; i < eval(listbox).options.length; i++) {
          // select all rows without the wIdThTor one, which is only for <select> size setting
          eval(listbox).options[i].selected = ( eval(listbox).options[i].value != "wIdThTor" );
        }
      }

      var box_index=0;   // index variable for box input fields
      var listboxes=Array(); // array of listboxes where all selection should be selected
      var relatedwindow;  // window for related stories

      // before submit the form we need to select all selections in some
      // listboxes (2window, relation) in order the rows are sent for processing
      function BeforeSubmit() {
        for(var i = 0; i < listboxes.length; i++)
          SelectAllInBox( listboxes[i] );';
        if ( richEditShowable() )	echo 'saveRichEdits();';
        echo '
        return proove_fields ();
      }

      function OpenRelated(varname, sid, mode, design) {
        if ((relatedwindow != null) && (!relatedwindow.closed)) {
          relatedwindow.close()    // in order to preview go on top after open
        }
        relatedwindow = open( "'. $sess->url("related_sel.php3") . '&sid=" + sid + "&var_id=" + varname + "&mode=" + mode + "&design=" + design, "relatedwindow", "scrollbars=1, resizable=1, width=500");
      }

      function MoveSelected(left, right) {
        var i=eval(left).selectedIndex;
        if( !eval(left).disabled && ( i >= 0 ) )
        {
          var temptxt = eval(left).options[i].text;
          var tempval = eval(left).options[i].value;
          var length = eval(right).length;
          if( (length == 1) && (eval(right).options[0].value==\'wIdThTor\') ){  // blank rows are just for <select> size setting
            eval(right).options[0].text = temptxt;
            eval(right).options[0].value = tempval;
          } else
            eval(right).options[length] = new Option(temptxt, tempval);
          eval(left).options[i] = null;
          if( eval(left).length != 0 )
            if( i==0 )
              eval(left).selectedIndex=0;
            else
              eval(left).selectedIndex=i-1;
        }
      }

      function add_to_line(inputbox, value) {
        if (inputbox.value.length != 0) {
          inputbox.value=inputbox.value+","+value;
        } else {
          inputbox.value=value;
        }
      }

    // -->
    </script>';

    echo $js_proove_fields;

    echo '
  </head>
  <body id="body_white_color">
    <H1><B>' . ( $edit=="" ? _m("Add Item") : _m("Edit Item")) . '</B></H1>';
 }
 
 PrintArray($err);
 echo $Msg;

if ($return_url)
  $PASS_PARAM=$PHP_SELF."?return_url=".urlencode($return_url);
else
  $PASS_PARAM=$PHP_SELF;
// field javascript feature (see /include/javascript.php3)
$javascript = getJavascript();
if ($javascript) {
    echo '
    <script language="javascript">
        <!--
            '.$javascript.'
        //-->
    </script>
    <script language="javascript" src="'.$AA_INSTAL_PATH.'javascript/fillform.js">
    </script>';
}
echo "<form name=inputform $html_form_type method=post action=\""
    .($DOCUMENT_URI != "" ? $DOCUMENT_URI : $PASS_PARAM).'"'
    .getTriggers ("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()")).'>'
    .'<table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center" class="inputtab">'; ?>
<tr><td class=tabtit align="center"><b>&nbsp;<?php //echo _m("News Article")?></b>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" class="inputtab2">
<?php
if( ($errmsg = ShowForm($content4id, $fields, $prifields, $edit)) != "" )
  echo "<tr><td>$errmsg</td></tr>";

?>
<tr>
  <td colspan=2>
  <?php
  if(DEBUG_FLAG && $id) {       //  do not print empty info for new articles
/*    echo '<I>';
    echo L_POSTDATE.": ".(sec2userdate(dequote($post_date)));
    $userinfo = GetUser($created_by);
    echo  " ".L_CREATED_BY.": ", $userinfo["cn"] ? $userinfo["cn"] : $created_by;
    echo "<br>";
    $userinfo = GetUser($edited_by);
    echo  L_LASTEDIT . " ", $userinfo["cn"] ? $userinfo["cn"] : $edited_by;
    echo " ".L_AT." ". dequote($last_edit); 
    echo '</I>'; */
  }  
  $r_hidden["slice_id"] = $slice_id;
  $r_hidden["anonymous"] = (($free OR $anonymous) ? true : "");
  # the slice_id is not needed here, but it helps, if someone will try to create
  # anonymous posted form (posted to filler.php3) - there must be slice_id
  $sess->hidden_session();
  echo '<input type=hidden name="slice_id" value="'. $slice_id .'">';
  echo '<input type=hidden name="MAX_FILE_SIZE" value="'. IMG_UPLOAD_MAX_SIZE .'">'; 
  echo '<input type=hidden name="encap" value="'. (($encap) ? "true" : "false") .'">'; ?>
  </td>
</tr>
</table></td></tr>
<tr><td align=center><?php

// is the accesskey working?
detect_browser();
if ($BPlatform == "Macintosh") {
    if ($BName == "MSIE"
        || ($BName == "Netscape" && $BVersion >= "6"))
        $accesskey = "(ctrl+S)";
}
else {
    if ($BName == "MSIE"
        || ($BName == "Netscape" && $BVersion > "5"))
       $accesskey = "(alt+S)";
};
 
if($edit || $update || ($insert && $added_to_db)) { 
    echo '<input type=submit name=update accesskey=S value="'._m("Update")." ".$accesskey.'">';
    if ((!($post_preview==0)) or (!(isset($post_preview)))) 
        echo "<input type=submit name=upd_preview value='"._m("Update & View")."'>";
	echo '
    <input type=submit name=insert value="'._m("Insert as new").'">
    <input type=reset value="'._m("Reset form").'">';
    $r_hidden["id"] = $id;
} else {
	echo ' 
    <input type=submit name=insert accesskey=S value="'._m("Insert")." ".$accesskey.'">
    <input type=submit name=ins_preview value="'._m("Insert & View").'">';
}
$cancel_url = ($anonymous  ? $r_slice_view_url :
              ($return_url ? expand_return_url(1) :
                             con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id")));
echo '
&nbsp;<input type=button name=cancel value="'._m("Cancel").'" 
	onclick="document.location=\''.$cancel_url.'\'">
</td>
</tr>
</table>
</form>';
if( !$encap )
    echo "</body></html>";
page_close();
?>
