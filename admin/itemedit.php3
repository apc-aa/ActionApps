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
  
require "../include/init_page.php3";
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
  } else  {

    /*
    Code Added by Ram Prasad on 07-Feb-2002
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    Function: 
    ~~~~~~~~~
    This checks if the parameter return_url is present, and redirects the user
    to the $redirect_url page.
    if not present, it redirects the user to default (index.php3) page. 
    */	
    // Begin Ram's Code
    if ($return_url){
      echo '<SCRIPT Language="JavaScript"><!--
            document.location = "'. $return_url.'";
            // -->
           </SCRIPT>';			
    } else {
        // Old Version ? go_url( $sess->url(self_base() . "index.php3"));
        go_url( con_url($sess->url(self_base() .  "index.php3"), "slice_id=$slice_id"));
    }    
    // End of Ram's Code
  }    
}
$db = new DB_AA;

$err["Init"] = "";          // error array (Init - just for initializing variable

$varset = new Cvarset();
$itemvarset = new Cvarset();

  # get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($slice_id);   

if( isset($prifields) AND is_array($prifields) ) {

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
    
      # validate input data
    if( $insert || $update )
    {
      if( IsEditable($oldcontent4id[$pri_field_id], $f)) {
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
  }
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
      /*
      Code Added by Ram Prasad on 07-Feb-2002
      ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
      Function: 
      ~~~~~~~~~ 
      This checks if the parameter return_url is present, and redirects the user
      to the $redirect_url page.
      if not present, it redirects the user to default (index.php3) page.
      */      
      // Begin Ram's Code        
      if ($return_url) {
        echo '<SCRIPT Language="JavaScript"><!--
              document.location = "'. $return_url.'";
              // -->
              </SCRIPT>';
      } else {
        go_url( $sess->url(self_base() . "index.php3"));
      }
      // End of Ram's Code
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
    $err["DB"] = MsgErr(L_BAD_ITEM_ID);  
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
    <title>'.( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT). '</title>
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
        return true;  
      }    
    
      function OpenRelated(varname, sid, mode, design) {
        if ((relatedwindow != null) && (!relatedwindow.closed)) {
          relatedwindow.close()    // in order to preview go on top after open
        }
        relatedwindow = open( "'. $sess->url("related_sel.php3") . '&sid=" + sid + "&var_id=" + varname + "&mode=" + mode + "&design=" + design, "relatedwindow", "scrollbars=1, resizable=1, width=500");
      }  
      
    // -->
    </script>';
/*		// load the rich editor module:
	  if (richEditShowable ())
			echo '<?import namespace="XS" implementation="htmlEditor.htc" />';*/
		echo '
    </head>
  <body id="body_white_color">
   <H1><B>' . ( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT) . '</B></H1>';
}       
PrintArray($err);
echo $Msg;  

/* 
Code Added by Ram Prasad on 07-Feb-2002
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
Function: 
~~~~~~~~~ 
This checks if the parameter return_url is present. If yes, it passes the parameter 
instead of using $PHP_SELF , we use $PASS_PARAM
*/      

// Begin Ram's Code
if ($return_url)
  $PASS_PARAM=$PHP_SELF."?return_url=".urlencode($return_url);
else
  $PASS_PARAM=$PHP_SELF;
// End of Ram's Code

// field javascript feature (see /include/javascript.php3)
$javascript = getJavascript();
if ($javascript) {
    echo '
    <script language="javascript">
        <!--
            '.$javascript.'
        //-->
    </script>
    <script language="javascript" src="'.$AA_INSTAL_PATH.'include/fillform.js">
    </script>';
}

echo '<form name=inputform enctype="multipart/form-data" method=post action="'
    .($DOCUMENT_URI != "" ? $DOCUMENT_URI : $PASS_PARAM).'"'
    .getTriggers ("form","v".unpack_id("inputform"),array("onSubmit"=>"BeforeSubmit()")).'>'
    .'<table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center" class="inputtab">'; ?>
<tr><td class=tabtit align="center"><b>&nbsp;<?php //echo L_ITEM_HDR?></b>
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
if($edit || $update || ($insert && $added_to_db)) { ?>
   <input type=submit name=update accesskey=s value="<?php echo L_POST ?>">
  <?

  /*
  Code Added by Ram Prasad on 07-Feb-2002
  ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
  Function
  ~~~~~~~~
  This checks if the parameter for post_preview, if passed and if equals 0 (zero)
  it does not display the "Post and Preview Option".
  */

  // Begin Ram's Code
  if ((!($post_preview==0)) or (!(isset($post_preview)))) {	 
    echo "<input type=submit name=upd_preview value='".L_POST_PREV."'>";
  }
  // End Ram's Code
  ?>

   <input type=submit name=insert value="<?php echo L_INSERT_AS_NEW ?>">
   <input type=reset value="<?php echo L_RESET ?>"><?php
   $r_hidden["id"] = $id;
} else { ?>
   <input type=submit name=insert value="<?php echo L_INSERT ?>">
   <input type=submit name=ins_preview value="<?php echo L_INSERT_PREV ?>"><?php
} ?>
&nbsp;<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td>
</tr>
</table>
</form>
<?php
if( !$encap ) 
    echo "</body></html>";
page_close(); 
?>
