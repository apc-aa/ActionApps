<?php
/**
 * Script for submitting items anonymously, without accessing the admin interface
 *
 * See documentation in doc/anonym.html.
 *
 * Parameters (usually from a HTML form):
 * <pre>
 *   my_item_id   - item id, used when editing (not adding a new) item in the
 *                  anonymous form
 *   slice_id     - id of slice into which the item is added
 *   notvalidate  - if true, data input validation is skipped
 *   ok_url       - url where to go, if item is successfully stored in database
 *   err_url      - url where to go, if item is not stored in database (due to
 *                  validation of data, ...)
 *   force_status_code - you may add this to force to change the status code
 *                       but the new status code must always be higher than bin2fill
 *                       setting (you can't add to the Active bin, for example)
 *   notshown[] - array (form field ID => 1) of unpacked IDs, e.g. v7075626c6973685f646174652e2e2e2e
 *                which are shown in the control panel but not in the anonym form
 *   bool use_post2shtml If true, use the post2shtml script to send the error
 *          description and the values filled to fillform.php3.
 *   bool text_password If true, the password is stored in text form (not encrypted).
 * </pre>
 *
 * @package UserInput
 * @version $Id$
 * @author Honza Malík, Jakub Adámek, Econnect
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/
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

/** 
 * Handle with PHP magic quotes - quote the variables if quoting is set off 
 * @param mixed $val the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */

function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}    
 
if (!get_magic_quotes_gpc()) { 
  // Overrides GPC variables 
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
      $$k = Myaddslashes($v); 
}

/** APC-AA configuration file */
require_once "include/config.php3";
/** Main include file for using session management function on a page */
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
/** Set of useful functions used on most pages */
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
/** Defines class for inserting and updating database fields */
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
/** utility for notifying people of events by email */
require_once $GLOBALS["AA_INC_PATH"]."notify.php3";
/** defines PageCache class used for caching informations into database */
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
/** date helper functions */
require_once $GLOBALS["AA_INC_PATH"]."date.php3";
require_once $GLOBALS["AA_INC_PATH"]."feeding.php3";
require_once $GLOBALS["AA_INC_PATH"]."zids.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";
    
/**
 * Outputs a notification page when an error occurs.
 * If the err_url parameter is passed, redirects to the specified URL,
 * and passes $txt as the URL parameter named "err".
 * else generates an error page with the $txt message.
 * @param string $txt error message to print
 */
function SendErrorPage($txt) {
    if( !$GLOBALS["err_url"] ) {
        echo HtmlPageBegin("");
        echo "</head><body>";
        if( is_array( $txt ) )
            PrintArray($txt);    
        else echo $txt;
        echo "</body></html>";
        exit;
    }
    
    else if (! $GLOBALS["use_post2shtml"]) 
       go_url( con_url($GLOBALS["err_url"], "err=".substr(serialize($txt),0,200)));
    
    else {
        // allows to call a script showing the error results from fillform
        $GLOBALS["HTTP_POST_VARS"]["result"] = $txt;
        // allows fillform to use this data 
        $GLOBALS["HTTP_POST_VARS"]["oldcontent4id"] = 
            StripslashesArray ($GLOBALS["content4id"]);            
        $GLOBALS["shtml_page"] = $GLOBALS["err_url"];
        require_once "post2shtml.php3";
        exit;
    }
}  

/**
 * Loads a page if posting is successful. If the ok_url parameter is passed,  
 * redirects to the specified URL, else returns to the calling page.
 */
function SendOkPage() {
    if( ! $GLOBALS["ok_url"] )
        go_url($GLOBALS[HTTP_REFERER]);    
        
    else if (! $GLOBALS["use_post2shtml"]) 
        go_url($GLOBALS["ok_url"]);
    
    else {
        // allows fillform to use this data 
        $GLOBALS["HTTP_POST_VARS"]["oldcontent4id"] = 
            StripslashesArray ($GLOBALS["content4id"]);            
        $GLOBALS["shtml_page"] = $GLOBALS["err_url"];
        require_once "post2shtml.php3";
        exit;
    }
}  

# init used objects

if( !$slice_id ) SendErrorPage(array ("fatal"=>_m("Slice ID not defined"))); 

$p_slice_id = q_pack_id($slice_id);
$slice_info = GetSliceInfo($slice_id);

if( !$slice_info ) SendErrorPage(array ("fatal"=>_m("Bad slice ID")));

// if you want to edit an item from an anonymous form, prepare its ID into 
// the my_item_id hidden field
if (!$my_item_id) {
    $my_item_id = new_id();
    $insert = true;
}
else {
    $db->query ("SELECT id FROM item WHERE id='".q_pack_id($my_item_id)."'");
    $insert = ! $db->next_record();
}

ValidateContent4Id ($err_valid, $slice_id, $insert ? "insert" : "update", $my_item_id, 
    ! $notvalidate, $notshown);
    
if( !(isset($prifields) AND is_array($prifields)) )
SendErrorPage(array ("fatal"=>_m("No fields defined for this slice")));

if (count ($err_valid) > 1) {
    unset ($err_valid["Init"]);
    $zids = new zids();
    reset ($err_valid);
    while (list ($field_zid, $msg) = each ($err_valid)) {
        $zids->refill ($field_zid);
        $err["validate"][$zids->packedids (0)] = $msg;
    }
}

// prepare content4id array before calling StoreItem 
$content4id = GetContentFromForm( $fields, $prifields, $oldcontent4id, $insert );

// copy old values for fields not shown in the form
if (! $insert && is_array ($notshown)) {
    reset ($notshown);
    while (list ($vfield_id) = each ($notshown)) 
        $field_ids[] = $vfield_id;
    $zids = new zids($field_ids);
    for ($i = 0; $i < $zids->count(); $i ++) {
        $field_id = $zids->packedids ($i);
        $content4id [$field_id] = $oldcontent4id [$field_id];
    }
}        

if ($insert) {
    $content4id["flags..........."][0]['value'] = ITEM_FLAG_ANONYMOUS_EDITABLE;

    // put the item into the right bin
    $bin2fill = $slice_info["permit_anonymous_post"]; 
    if( $bin2fill < 1 ) SendErrorPage(array("fatal"=>_m("Anonymous posting not admitted.")));
    
    // you may force to put the item into a higher bin (active < hold < trash)
    $bin2fill = max ($bin2fill, $force_status_code);  
    $content4id["status_code....."][0][value] = $bin2fill;
}    

else if (!is_array ($err)) {
    // Proove we are permitted to update this item. 
    switch ($slice_info["permit_anonymous_edit"]) {
    case ANONYMOUS_EDIT_NOT_ALLOWED: $permok = false; break;
    case ANONYMOUS_EDIT_ALL:         $permok = true; break;
    case ANONYMOUS_EDIT_ONLY_ANONYMOUS:
    case ANONYMOUS_EDIT_NOT_EDITED_IN_AA:
        $oldflags = $oldcontent4id["flags..........."][0]['value'];
	    // are we allowed to update this item?
    	$permok = ($oldflags & ITEM_FLAG_ANONYMOUS_EDITABLE != 0); 
        $content4id["flags..........."][0]['value'] = $oldflags;
        break;
    case ANONYMOUS_EDIT_HTTP_AUTH:
        // For HTTP_AUTH permissions the reader is found in fillform.php3.
        // Here we don't get the $_SERVER["REMOTE_USER"] information.
        $permok = true;
        break;
    case ANONYMOUS_EDIT_PASSWORD:    
        $permok = false;
    	reset ($fields);    
    	while (list ($fid) = each($fields))
    		if (substr ($fid,0,14) == "password......") {
                $password = $content4id[$fid][0]['value'];
                if (!$text_password && $password) 
                    $password = md5 ($password);
    			$permok = ($oldcontent4id[$fid][0]['value'] == $password); 
                break;
    		}
        break;
    }
    
    if (! $permok)
        $err["permissions"] = _m("You are not allowed to update this item.");
}

 # update database
if (!is_array ($err)) {
	if (!StoreItem( $my_item_id, $slice_id, $content4id, $fields, $insert, 
                          true, true, $oldcontent4id ))     # insert, invalidatecache, feed
        $err["store"] = _m("Some error in store item.");
    else $err["success"] = $insert ? "insert" : "update";    
}        
                          
if( is_array ($err)) SendErrorPage( $err ); 
else SendOkPage();
?>