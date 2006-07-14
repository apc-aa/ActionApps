<?php
// $Id$
?>

<center>
<form <?php echo $formparams ?> action="<?php echo ($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF ?>">

<table class=inouter border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr class=inoutertr><td class=inoutertd><b>&nbsp;<?php echo L_ITEM_HDR ?></b>
</td>
</tr>
<tr><td>
<table class=ininner width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

  FrmInputText("headline", L_HEADLINE, safe($headline), 254, 60, true);
  if($show[abstract])
    FrmTextarea("abstract", L_ABSTRACT, safe($abstract), 4, 60, $needed[abstract]);

  if( ($db->f(id) == $db->f(master_id)) AND $show[full_text])  {  // base item or new item
    if($show[html_formatted]) {  ?>
      <tr>
        <td valign=top class=tabtxt><b><?php 
          echo L_FT_FORMATTING;
          Needed($needed[html_formatted]);
          ?></b></td>
        <?php 
          $foo=$html_formatted;           
          $checkhtml=($foo>0)?"checked":""; $checkplain=(!($foo>0))?"checked":"";              
        ?>
        <td><input type=radio name=html_formatted value=1 <?php echo $checkhtml ?>><?php echo L_FT_FORMATTING_HTML?>
            <input type=radio name=html_formatted value=0 <?php echo $checkplain?>><?php echo L_FT_FORMATTING_PLAIN?>   
        </td>
      </tr><?php
    }  
    FrmTextarea("full_text", L_FULL_TEXT, safe($full_text), 8, 60, $needed[full_text]);
  }
  if($show[highlight])
    FrmInputChBox("highlight", L_HIGHLIGHT, $highlight, false, "", 1, $needed[highlight]);
  if($show[link_only])
    FrmInputChBox("link_only", L_LINK_ONLY, $link_only, false, "", 1, $needed[link_only]);
  if($show[hl_href])
    FrmInputText("hl_href", L_HL_HREF, safe($hl_href), 254, 60, $needed[hl_href]);
  if($show[place])
    FrmInputText("place", L_PLACE, safe($place), 254, 60, $needed[place]);
  if($show[source])
    FrmInputText("source", L_SOURCE, safe($source), 254, 60, $needed[source]);
  if($show[source_href])
    FrmInputText("source_href", L_SOURCE_HREF, safe($source_href), 254, 60, $needed[source_href]);
  if($show[status_code]) {?>
    <tr>
      <td class=tabtxt><b> <?php
         echo L_STATUS_CODE;
         Needed($needed[status_code]);
       ?></b></td>
      <?php
       $foo=$status_code;           
       $check1=($foo==1)?"checked":"";
       $check2=($foo==2)?"checked":"";
       $check3=($foo==3)?"checked":"";              
      ?>
      <td><input type=radio name=status_code value=1 <?php echo $check1 ?>><?php echo L_ACTIVE_BIN ?><br><input type=radio name=status_code value=2 <?php echo $check2 ?>><?php echo L_HOLDING_BIN ?><br><input type=radio name=status_code value=3 <?php echo $check3 ?>><?php echo L_TRASH_BIN ?>
      </td>
    </tr><?php
  }  
  if($show[language_code])
    FrmInputSelect("language_code", L_LANGUAGE_CODE, $languages, $language_code, $needed[language_code]); 
  if($show[cp_code])
    FrmInputSelect("cp_code", L_CP_CODE, $codepages, $cp_code, $needed[cp_code]); 
  if( isset($categories) AND is_array($categories) AND $show[category_id])
    FrmInputSelect("category_id", L_CATEGORY, $categories, $category_id, $needed[category_id]);
  if($show[redirect])
    FrmInputText("redirect", L_REDIRECT, safe($redirect), 254, 60, $needed[redirect]);
  if($show[img_src])
    FrmInputText("img_src", L_IMG_SRC, safe($img_src), 254, 60, $needed[img_src]);
  if($show[img_width])
    FrmInputText("img_width", L_IMG_WIDTH, safe($img_width), 254, 60, $needed[img_width]);
  if($show[img_height])
    FrmInputText("img_height", L_IMG_HEIGHT, safe($img_height), 254, 60, $needed[img_height]);
  if($show[img_upload])
    FrmInputFile("img_upload", L_IMG_UPLOAD, 40, $needed[img_upload]);
  if($show[posted_by])
    FrmInputText("posted_by", L_POSTED_BY, safe($posted_by), 254, 60, $needed[posted_by]);
  if($show[e_posted_by])
    FrmInputText("e_posted_by", L_E_POSTED_BY, safe($e_posted_by), 254, 60, $needed[e_posted_by]);
  if($show[publish_date]) { ?>
    <tr>
      <td class=tabtxt><b><?php
        echo L_PUBLISH_DATE;
        Needed($needed[publish_date]) ?></b></td>
      <td><?php echo $publishdate->pselect(); ?><?php echo $err["publish_date"] ?>
      </td>
    </tr><?php
  }
  if($show[expiry_date]) { ?>
    <tr>
      <td class=tabtxt><b><?php 
        echo L_EXPIRY_DATE;
        Needed($needed[expiry_date]) ?></b></td>
      <td><?php echo $expirydate->pselect(); ?><?php echo $err["expiry_date"] ?>
      <div class=tabhlp>Expiry date is date, when item stops to be viewed on web</div> <!-- This is an example of help (for Klara) -->
      </td>
    </tr><?php
  }    

  if( defined("EXTENDED_ITEM_TABLE") ) {
    if($show[source_desc])
      FrmTextarea("source_desc", L_SOURCE_DESC, safe($source_desc), 8, 60, $needed[source_desc]);
    if($show[source_address]) 
      FrmInputText("source_address",L_SOURCE_ADDRESS, safe($source_address), 254, 60, $needed[source_address]);
    if($show[source_city])
      FrmInputText("source_city", L_SOURCE_CITY, safe($source_city), 254, 60, $needed[source_city]);
    if($show[source_prov])
      FrmInputText("source_prov", L_SOURCE_PROV, safe($source_prov), 254, 60, $needed[source_prov]);
    if($show[source_country])
      FrmInputText("source_country", L_SOURCE_COUNTRY, safe($source_country), 254, 60, $needed[source_country]);
    
    if($show[start_date]) { ?>
      <tr> 
       <td class=tabtxt><b><?php
        echo L_START_DATE;
        Needed($needed[start_date])?>
        </b></td>
       <td><?php 
        echo $startdate->pselect();
        echo $err["start_date"] ?> 
       </td>
      </tr><?php 
    } 
    if($show[end_date]) { ?> 
      <tr> 
       <td class=tabtxt><b><?php 
        echo L_END_DATE; 
        Needed($needed[end_date]) ?>
        </b></td> 
       <td><?php
        echo $enddate->pselect(); 
        echo $err["end_date"] ?> 
       </td> 
      </tr><?php  
    } 
    if($show[time]) 
      FrmInputText("time", L_TIME, safe($time), 254, 60, $needed[time]); 
    
    if($show[con_name]) 
      FrmInputText("con_name", L_CON_NAME, safe($con_name), 254, 60, $needed[con_name]);
    if($show[con_email])
      FrmInputText("con_email",L_CON_EMAIL, safe($con_email), 254, 60, $needed[con_email]); 
    if($show[con_phone])
      FrmInputText("con_phone", L_CON_PHONE, safe($con_phone), 254, 60, $needed[con_phone]); 
    if($show[con_fax]) 
      FrmInputText("con_fax", L_CON_FAX, safe($con_fax), 254, 60, $needed[con_fax]); 
    
    if($show[loc_name]) 
      FrmInputText("loc_name",L_LOC_NAME, safe($loc_name), 254, 60, $needed[loc_name]); 
    if($show[loc_address]) 
      FrmInputText("loc_address", L_LOC_ADDRESS, safe($loc_address), 254, 60, $needed[loc_address]); 
    if($show[loc_city])
      FrmInputText("loc_city", L_LOC_CITY, safe($loc_city), 254, 60, $needed[loc_city]);
    if($show[loc_prov]) 
      FrmInputText("loc_prov", L_LOC_PROV, safe($loc_prov), 254, 60, $needed[loc_prov]); 
    if($show[loc_country]) 
      FrmInputText("loc_country", L_LOC_COUNTRY, safe($loc_country), 254, 60, $needed[loc_country]);

// if($show[con_name]) 
//   FrmInputText("con_name", L_CON_NAME, safe($con_name), 254, 60, $needed[con_name]); 
// if($show[con_email]) 
//   FrmInputText("con_email", L_CON_EMAIL, safe($con_email), 254, 60, $needed[con_email]);
// if($show[con_phone]) 
//   FrmInputText("con_phone", L_CON_PHONE, safe($con_phone), 254, 60,$needed[con_phone]);
// if($show[con_fax])
//   FrmInputText("con_fax", L_CON_FAX, safe($con_fax), 254, 60, $needed[con_fax]);

  }
  
  if($show[edit_note]) 
    FrmTextarea("edit_note", L_EDIT_NOTE, safe($edit_note), 4, 60, $needed[edit_note]); ?>
<tr>
  <td colspan=2>
  <?php 
  if(DEBUG_FLAG && $id) {       //  do not print empty info for new articles
    echo '<I>';
    echo L_POSTDATE.": ".(datetime2date(dequote($post_date)));
    $userinfo = GetUser($created_by);
    echo  " ".L_CREATED_BY.": ", $userinfo["cn"] ? $userinfo["cn"] : $created_by;
    echo "<br>";
    $userinfo = GetUser($edited_by);
    echo  L_LASTEDIT . " ", $userinfo["cn"] ? $userinfo["cn"] : $edited_by;
    echo " ".L_AT." ". dequote($last_edit); 
    echo '</I>';
  }  
  if( ($db->f(id) != $db->f(master_id)) ) 
    $r_hidden["feeded"] = 1;
  $r_hidden["post_date"] = $post_date;
  $r_hidden["created_by"] = $created_by;
  $r_hidden["edited_by"] = $edited_by; 
  $r_hidden["last_edit"] = $last_edit;
  $r_hidden["slice_id"] = $slice_id;

  # anonymous must be in hidden and no in r_hidden - there is no session
  echo '<input type=hidden name="anonymous" value="'. (($free OR $anonymous) ? "true" : "") .'">'; 
  echo '<input type=hidden name="MAX_FILE_SIZE" value="'. IMG_UPLOAD_MAX_SIZE .'">'; 
  echo '<input type=hidden name="encap" value="'. (($encap) ? "true" : "false") .'">'; 
  $sess->hidden_session(); # hidden form element for session id name and value?>
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
// $Log$
// Revision 1.5  2001/05/18 13:55:04  honzam
// New View feature, new and improved search function (QueryIDs)
//
// Revision 1.4  2001/02/26 17:22:30  honzam
// color profiles, itemmanager interface changes
//
// Revision 1.3  2000/12/05 14:20:36  honzam
// Fixed bug with Netscape - not allowed method POST - in annonymous posting.
//
// Revision 1.2  2000/11/20 16:45:58  honzam
// fixed bug with anonymous posting to other aplications than news
//
// Revision 1.1  2000/11/17 19:10:05  madebeer
// this is the default form file for itemedit.
// new form files can be created as itemedit-en_news.php3, for example
//
?>
