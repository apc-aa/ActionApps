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
if( file_exists( $GLOBALS[AA_INC_PATH]."usr_validate.php3" ) ) {
  include( $GLOBALS[AA_INC_PATH]."usr_validate.php3" );
}

if ($encap) add_vars();        # adds values from QUERY_STRING_UNESCAPED 
                               #       and REDIRECT_STRING_UNESCAPED - from url

QuoteVars("post");  // if magicquotes are not set, quote variables

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

if($cancel) 
{
  if( $anonymous )  // anonymous login
    	if( $encap ) 
	{
      		echo '<SCRIPT Language="JavaScript"><!--
              	document.location = "'. $r_slice_view_url .'";
            	// -->
           	</SCRIPT>';
    	} 
	else
      		go_url( $r_slice_view_url );
   else 
	{

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
if ($return_url)
		{
			echo '<SCRIPT Language="JavaScript"><!--
              		document.location = "'. $return_url.'";
            		// -->
           		</SCRIPT>';			
		}
	else
		{
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
            ValidateInput($varname, $f[name], $$varname, &$err,
                          $f[required] ? 1 : 0, $f[input_validate]);
            break;
          case 'date':  
            $foo_datectrl_name = new datectrl($varname);
            $foo_datectrl_name->update();                   # updates datectrl
            if( $$varname != "")                            # loaded from defaults
              $foo_datectrl_name->setdate_int($$varname);
            $foo_datectrl_name->ValidateDate($f[name], &$err);
            $$varname = $foo_datectrl_name->get_date();  # write to var
            break;
          case 'bool':  
            $$varname = ($$varname ? 1 : 0);
            break;
	  case 'user':
	    // this is under development.... setu, 2002-0301
	    // value can be modified by $$varname = "new value";
	    $$varname = usr_validate($varname, $f[name], $$varname, &$err, $f, $fields);
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
     else 
	{
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

	if ($return_url)
                {
                        echo '<SCRIPT Language="JavaScript"><!--
                        document.location = "'. $return_url.'";
                        // -->
                        </SCRIPT>';
        
                }
        else
                {
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
    
      function OpenRelated(varname, sid) {
        if ((relatedwindow != null) && (!relatedwindow.closed)) {
          relatedwindow.close()    // in order to preview go on top after open
        }
        relatedwindow = open( "'. $sess->url("related_sel.php3") . '&sid=" + sid + "&var_id=" + varname, "relatedwindow", "scrollbars=1, resizable=1, width=500");
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
?>

<?
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
{
	$PASS_PARAM=$PHP_SELF."?return_url=".urlencode($return_url);
}
else
{
	$PASS_PARAM=$PHP_SELF;
}
// End of Ram's Code
?>
<form name=inputform onsubmit="BeforeSubmit()" enctype="multipart/form-data" method=post action="<?php echo  ($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PASS_PARAM ?>">
<table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center" class="inputtab">
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
   <input type=submit name=update value="<?php echo L_POST ?>">
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
if ((!($post_preview==0)) or (!(isset($post_preview))))
{	 
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
  echo '</body></html>';
page_close(); 

/*
$Log$
Revision 1.31  2002/03/14 11:20:45  mitraearth
[[ User Validation for add item / edit item (itemedit.php3). ]]

(by Setu)
 - new selection "User" at admin->field->edit(any field)->validation.
 - if "include/usr_validate.php3" exist, it is included. (in admin/itemedit.php3) and defines "usr_validate()" function.
 - At submit in itemedit if "User" is selected, function usr_validate() is called from itemedit.php3.
 - It can validate the value and return new value for the field.

 - Related files:
   - admin/itemedit.php3
   - include/constants.php3
   - include/en_news_lang.php3
     - "L_INPUT_VALIDATE_USER" for User Validation.

* There is sample code for defining this function at http://apc-aa.sourceforge.net/faq/index.shtml#476

[[ Default value from query variable (add item & edit item :  itemedit.php3) ]]
(by Ram)
 - if the field is blank, it can load default value from URL query strings.
 - new selection "Variable" in admin->field->edit(any field)->Default:
 - "parameter" is the name of variable in URL query strings
   - (or any global variable in APC-AA php3 code while itemedit.php3 is running).

 - Related files:
   - include/constant.php3
   - include/en_news_lang.php3
     - "L_INPUT_DEFAULT_VAR" for Default by variable.
   - include/itemfunc.php3
     - new function "default_fnc_variable()" for "Default by variable"


[[ admin/index.php3 ]]
(by Setu)
 - more switches to allow admin/index.php3 to be called from another program (with return_url).
   - sort_filter=1
   - action_selected=1
     - "feed selected" is not supported.
     - "view selected" is not supported.
 - scroller now works with return_url.
   - caller php3 code needs to pass parameter for scroller for  admin/index.php3
     - scr_st3_Mv
     - scr_st3_Go
 - more changes to work with &return_url.

 - related files:
   - admin/index.php3
   - include/item.php3
     - new function make_return_url()
     - new function sess_return_url()

* Sample code to call admin/index.php3 is at http://apc-aa.sourceforge.net/faq/index.shtml#477

[[ admin/slicedit.php3 can be called from outside to submit the value. ]]
(by Setu)
 - it supports "&return_url=...." to jump to another web page after  submission.
 - related files:
   - admin/slicedit.php3

Revision 1.30  2002/03/06 13:45:53  honzam
just small design change

Revision 1.29  2002/02/12 09:55:51  mitraearth
File: apc-aa/admin/itemedit.php3
Changed By: Ram Prasad
Change:
1) Added enable/disable toggle for "Post and Preview" Button visibility.($post_preview=0 disables Post and Preview button)
2) Added fuctionality of return_url ( when return_url is provided , clicking post/cancel takes the user to $return_url instead of index.php3.

Revision 1.28  2002/02/05 21:42:14  honzam
prepare for anonymous item edit feature

Revision 1.27  2001/12/18 11:49:26  honzam
new WYSIWYG richtext editor for inputform (IE5+)

Revision 1.26  2001/11/26 11:04:48  honzam
IE6.0 center bug fig

Revision 1.25  2001/09/27 16:00:39  honzam
New related stories support

Revision 1.24  2001/07/09 09:29:54  honzam
New sort and search possibility in admin interface

Revision 1.23  2001/06/03 15:57:45  honzam
multiple categories (multiple values at all) for item now works

Revision 1.22  2001/05/29 19:14:58  honzam
copyright + AA logo changed

Revision 1.21  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.20  2001/03/30 11:52:53  honzam
reverse displaying HTML/Plain text bug and others smalll bugs fixed

Revision 1.19  2001/03/20 16:01:13  honzam
HTML / Plain text selection implemented
Standardized content management for items - filler, itemedit, offline, feeding

Revision 1.18  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.17  2001/02/26 17:26:08  honzam
color profiles

Revision 1.16  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.14  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.13  2000/12/05 14:20:35  honzam
Fixed bug with Netscape - not allowed method POST - in annonymous posting.

Revision 1.12  2000/11/17 19:08:03  madebeer
itemedit now creates the form on a application type specific basis.

Revision 1.11  2000/11/15 16:20:41  honzam
Fixed bugs with anonymous posting via SSI and bad viewed item in itemedit

Revision 1.10  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.8  2000/08/17 15:14:32  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.7  2000/08/03 12:31:19  honzam
Session variable r_hidden used instead of HIDDEN html tag. Magic quoting of posted variables if magic_quotes_gpc is off.

Revision 1.6  2000/07/17 15:50:08  kzajicek
Do not print empty info for new articles

Revision 1.5  2000/07/13 14:12:58  kzajicek
SQL keywords to uppercase

Revision 1.4  2000/07/13 10:12:18  kzajicek
iIf possible, print real name instead of uid (number with sql, dn with ldap).

Revision 1.3  2000/07/13 10:02:22  kzajicek
created_by should not be changed, it is a constant value.

Revision 1.2  2000/07/12 11:06:26  kzajicek
names of image upload variables were a bit confusing

Revision 1.1.1.1  2000/06/21 18:39:57  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:46  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.19  2000/06/12 19:58:23  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.18  2000/06/09 15:14:09  honzama
New configurable admin interface

Revision 1.17  2000/06/06 23:12:59  pepturro
Fixed img upload: properly set img_src field

Revision 1.16  2000/04/28 09:48:13  honzama
Small bug in user/group search fixed.

Revision 1.15  2000/04/24 16:38:13  honzama
Anonymous item posting.

Revision 1.14  2000/03/29 14:33:04  honzama
Fixed bug of adding slashes before ' " and \ characters in fulltext.

Revision 1.13  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
