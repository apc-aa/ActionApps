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

if ($encap) $sess->add_vars(); # adds values from QUERY_STRING_UNESCAPED 
                               #       and REDIRECT_STRING_UNESCAPED

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

if($cancel) {
  if( $anonymous )  // anonymous login
    if( $encap ) {
      echo '<SCRIPT Language="JavaScript"><!--
              document.location = "'. $r_slice_view_url .'";
            // -->
           </SCRIPT>';
    } else
      go_url( $r_slice_view_url );
   else 
    go_url( $sess->url(self_base() . "index.php3"));
}    

# ----------------------- functions -------------------------------------------
function default_fnc_now($param) {
  return now();
}  

function default_fnc_uid($param) {
  global $auth;
  return quote($auth->auth["uid"]);
}  

function default_fnc_dte($param) {
  return mktime(0,0,0,date("m"),date("d")+$param,date("Y"));
}

function default_fnc_qte($param) {
  return quote($param);
}

function default_fnc_txt($param) {
  return quote($param);
}

# ----------------------- insert functions

function insert_fnc_qte($item_id, $field, $value, $param, $insert=true) {
  global $varset, $itemvarset, $db;
  if( $field[in_item_tbl] ) {
    # field in item table
    $itemvarset->add( $field[in_item_tbl], "quoted", $value);
    return;
  }  
    # field in content table
  $varset->clear();
  $varset->add("text", "quoted", $value);
  if( $insert ) {
    $varset->add("item_id", "unpacked", $item_id);
    $varset->add("field_id", "quoted", $field[id]);
    $db->query("INSERT INTO content" . $varset->makeINSERT() );
  } else {
    $db->query("UPDATE content SET ". $varset->makeUPDATE() . " 
                 WHERE item_id='". q_pack_id($item_id). "' 
                   AND field_id='". $field[id] . "'");
  }                 
}

function insert_fnc_dte($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_cns($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_num($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_boo($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value ? 1:0, $param, $insert);
}

function insert_fnc_uid($item_id, $field, $value, $param, $insert=true) {
  global $auth;
  insert_fnc_qte($item_id, $field, $auth->auth["uid"], $param, $insert);
}

function insert_fnc_now($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, now(), $param, $insert);
}

  # File upload
function insert_fnc_fil($item_id, $field, $value, $param, $insert=true) {
  $varname = 'v'.unpack_id($field[id]);
  
  if(($value <> "none")&&($value <> "")) {   # see if the uploaded file exists
    $dest_file = $GLOBALS[$varname . "_name"];
    if( file_exists(IMG_UPLOAD_PATH.$dest_file) )
      $dest_file = new_id().substr(strrchr($dest_file, "." ), 0 );

    if(!copy($value,IMG_UPLOAD_PATH.$dest_file)){     // copy the file from the temp directory to the upload directory, and test for success
      $err["Image"] = MsgErr(L_CANT_UPLOAD);          // error array (Init - just for initializing variable
      break;
    }   
    insert_fnc_qte($item_id, $field, IMG_UPLOAD_URL.$dest_file, $param, $insert);
  }
}    

function insert_fnc_nul($item_id, $field, $value, $param, $insert=true) {
}

# not defined insert func in field table (it is better to use insert_fnc_nul)
function insert_fnc_($item_id, $field, $value, $param, $insert=true) {
}

# ----------------------- show functions

function show_fnc_chb($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputChBox($varname, $field[name], $edit ? $content[0] : $value, false,
    "", 1, $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_txt($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  $rows = ($param ? $param : 4);
  FrmTextarea($varname, $field[name], $edit ? $content[0] : $value, $rows, 60,
   $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_fld($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputText($varname, $field[name], safe($edit ? $content[0]:$value), 255,60,
   $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_rio($varname, $field, $content, $value, $param, $edit) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputRadio($varname, $field[name], $arr, $edit ? $content[0] : $value,
    $field[required], $field[input_help], $field[input_morehlp] );
}
  
function show_fnc_sel($varname, $field, $content, $value, $param, $edit) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputSelect($varname, $field[name], $arr, $edit ? $content[0] : $value,
    $field[required], $field[input_help], $field[input_morehlp] );
}

  # $param is uploaded file type (like "image/*");
function show_fnc_fil($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputFile($varname, $field[name], safe($edit ? $content[0]:$value), 255,60,
       $field[required], $param, $field[input_help], $field[input_morehlp] );
}

function show_fnc_dte($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  $arr = explode("'",$param);
  $datectrl = new datectrl($varname, $arr[0], $arr[1], $arr[2]);
  $datectrl->setdate_int($edit ? $content[0] : $value);
  FrmStaticText($field[name], $datectrl->getselect(), $field[required], 
                $field[input_help], $field[input_morehlp] );
}

function show_fnc_nul($varname, $field, $content, $value, $param, $edit) {
}

# ----------------------- functions end ---------------------------------------

$db = new DB_AA;

$err["Init"] = "";          // error array (Init - just for initializing variable

$varset = new Cvarset();
$itemvarset = new Cvarset();

$SQL= " SELECT * FROM field WHERE slice_id='".q_pack_id($slice_id)."' 
         ORDER BY input_pri";
$db->query($SQL);
while($db->next_record()) {
  $fields[] = $db->Record;   # cache rows

    # get default values if needed
  $varname = 'v'. unpack_id($db->f(id));   # "v" prefix - database field var
  if( $add OR (!$db->f(input_show) AND ($insert OR $update) )) {
    $fnc = ParseFnc($db->f(input_default));    # all default should have fnc:param format

    if( $fnc ) {                     # call function
      $fncname = 'default_fnc_' . $fnc[fnc];
      $$varname = $fncname($fnc[param]);
    } else
      $$varname = $foo;
  }    
    # validate input data
  if( $insert || $update )
  {
    if( $db->f(input_show) AND !$db->f(feed) ) {
      switch( $db->f(input_validate) ) {
        case 'text': 
          ValidateInput($varname, $db->f(name), &$$varname, &$err,
                        $db->f(required) ? 1 : 0, "text");
          break;
        case 'url':  
          ValidateInput($varname, $db->f(name), &$$varname, &$err,
                        $db->f(required) ? 1 : 0, "url");
          break;
        case 'email':  
          ValidateInput($varname, $db->f(name), &$$varname, &$err,
                        $db->f(required) ? 1 : 0, "email");
          break;
        case 'number':  
          ValidateInput($varname, $db->f(name), &$$varname, &$err,
                        $db->f(required) ? 1 : 0, "number");
          break;
        case 'id':  
          ValidateInput($varname, $db->f(name), &$$varname, &$err,
                        $db->f(required) ? 1 : 0, "id");
          break;
        case 'date':  
          $foo_datectrl_name = new datectrl($varname);
          $foo_datectrl_name->update();                   # updates datectrl
          if( $$varname != "")                            # loaded from defaults
            $foo_datectrl_name->setdate_int($$varname);
          $foo_datectrl_name->ValidateDate($db->f(name), &$err);
          $$varname = $foo_datectrl_name->get_date();  # write to var
          break;
        case 'bool':  
          $$varname = ($$varname ? 1 : 0);
          break;
      }
    }
  }   
}      

  # update database
if( ($insert || $update) AND (count($err)<=1) 
    AND isset($fields) AND is_array($fields) ) {
  if( $insert )
    $id = new_id();

//p_arr_m($fields);

  reset($fields);
  while(list(,$f) = each($fields)) {
    $varname = 'v'. unpack_id($f[id]);   # "v" prefix - database field var
    $fnc = ParseFnc($f[input_insert_func]);   # input insert function
    if( $fnc ) {                     # call function
      $fncname = 'insert_fnc_' . $fnc[fnc];
        # updates content table or fills $itemvarset 
      $fncname($id, $f, $$varname, $fnc[param], $insert); # add to content table
    }                                                     # or to itemvarset
  }
  
    # update item table
  if( $update )
    $SQL = "UPDATE item SET ". $itemvarset->makeUPDATE() . " WHERE id='". q_pack_id($id). "'";
   else {
    $itemvarset->add("id", "unpacked", $id);
    $itemvarset->add("slice_id", "unpacked", $slice_id);
    $SQL = "INSERT INTO item " . $itemvarset->makeINSERT();
    $added_to_db = true;
  }  
  $db->query($SQL);
  
//  FeedItem($id, $db);   // TODO - odstranit a feedovat

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
      go_url( con_url($sess->url(self_base() .  "index.php3"), "slice_id=$slice_id"));
  }  
}
    
# -----------------------------------------------------------------------------
# Input form
# -----------------------------------------------------------------------------

if($edit) {
  if( !(isset($fields) AND is_array($fields)) ) {
    $err["DB"] = MsgErr(L_ERR_NO_FIELDS);
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }

    # fill content array from item table
  $SQL = "SELECT * FROM item WHERE id='".q_pack_id($id)."'";
	$db->query($SQL);
	if($db->next_record()) {
    while (list($key,$val,,) = each($db->Record)) {  
      if( EReg("^[0-9]*$", $key))
        continue;
      $foo = substr($key.'................',0,16);  #create id
      $content[unpack_id($foo)][] = $val;
    } 
  } else {
    $err["DB"] = MsgErr(L_BAD_ITEM_ID);  
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }  
    
    # fill content array from content table
  $SQL = "SELECT * FROM content WHERE item_id='".q_pack_id($id)."'
           ORDER BY field_id";
	$db->query($SQL);
  while( $db->next_record() ) {       
           #  flag bit 0 set - fed
           #  flag bit 1 set - html
    if ( $db->f(flag) && 1 )
        $content[unpack_id($db->f(field_id))][feed] = true;
    if ( $db->f(flag) && 2 )
        $content[unpack_id($db->f(field_id))][html] = true;
    if ( $db->f(number) > 0 )    # both values are set (fed)
      $content[unpack_id($db->f(field_id))][] = $db->f(number);
    else  
      $content[unpack_id($db->f(field_id))][] = $db->f(text);
  }     
}    

# print begin ---------------------------------------------------------------

if( !$encap ) {
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo '<title>'.( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT). '</title>
    </head>
    <body>
     <H1><B>' . ( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT) . '</B></H1>';
}       
PrintArray($err);
echo $Msg;  

?>
<center>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url( ($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF) ?>">

<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center" class="inputtab">
<tr><td class=tabtit><b>&nbsp;<?php echo L_ITEM_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE" class="inputtab2">
<?php
//p_arr_m($fields);
//p_arr_m($content);
if( !isset($fields) OR !is_array($fields) ) {
  echo "<tr><td>". 	MsgErr(L_NO_FIELDS). "</td></tr>";
} else {  
	reset($fields);
	while(list(,$f) = each($fields)) {
    $field_id = unpack_id($f[id]);
	  $varname = 'v'. $field_id;   # "v" prefix - database field var
	  if( $content[$field_id][feed] OR !$f[input_show])
	    continue;                  # fed fields or not shown fields do not show
	  $fnc = ParseFnc($f[input_show_func]);   # input show function
	  if( $fnc ) {                     # call function
	    $fncname = 'show_fnc_' . $fnc[fnc];
	      # updates content table or fills $itemvarset 
	    $fncname($varname, $f, $content[$field_id], $$varname, $fnc[param], $edit);
	  }
	}
}	
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
  echo '<input type=hidden name="MAX_FILE_SIZE" value="'. IMG_UPLOAD_MAX_SIZE .'">'; 
  echo '<input type=hidden name="encap" value="'. (($encap) ? "true" : "false") .'">'; ?>
  </td>
</tr>
</table></td></tr>
<tr><td align=center><?php
if($edit || $update || ($insert && $added_to_db)) { ?>
   <input type=submit name=update value="<?php echo L_POST ?>">
   <input type=submit name=upd_preview value="<?php echo L_POST_PREV ?>">
   <input type=reset value="<?php echo L_RESET ?>"><?php
   $r_hidden["id"] = $id;
} else { ?>
   <input type=submit name=insert value="<?php echo L_INSERT ?>">
   <input type=submit name=ins_preview value="<?php echo L_POST_PREV ?>"><?php
} ?>
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td>
</tr>
</table>
</form>
</center>
<?php
if( !$encap ) 
  echo '</body></html>';
page_close(); 

/*
$Log$
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
