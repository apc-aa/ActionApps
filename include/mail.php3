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

// (c) Jakub Adámek, Econnect, December 2002

if (!defined ("aa_mail_included"))
     define ("aa_mail_included", 1);
else return;

#require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."stringexpand.php3";

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
#                    M A I L   handling utility functions 
#

/*  Function: html2text
    Purpose:  strips the HTML tags and lot more to get a plain text version
*/
function html2text ($html) {
    
    // reverse to htmlentities
    if (function_exists ("get_html_translation_table")) {
        $trans_tbl = get_html_translation_table (HTML_ENTITIES);
	    $trans_tbl = array_flip ($trans_tbl);
        $html = strtr ($html, $trans_tbl);
    }

    // strip HTML tags
    $search = array (
                 "'<br>'si",
                 "'</p>'si",
                 "'<script[^>]*?>.*?</script>'si",  // Strip out javascript
                 "'<[\/\!]*?[^<>]*?>'si",           // Strip out html tags
                 "'([\r\n])[ \t]+'",                // Strip out leading white space
                 "'&(quot|#34);'i",                 // Replace html entities
                 "'&(amp|#38);'i",
                 "'&(lt|#60);'i",
                 "'&(gt|#62);'i",
                 "'&(nbsp|#160);'i",
                 "'&#(\d+);'e");                    // evaluate as php

    $replace = array (
                  "\n",
                  "\n",
                  "",
                  "",
                  "\\1",
                  "\"",
                  "&",
                  "<",
                  ">",
                  " ",
                  "chr(\\1)");

    return preg_replace ($search, $replace, $html);
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    Sends safely HTML messages. Some e-mail clients don't understand HTML. This function creates a multipart message containing both the HTML and the plain-text version of the message (by leaving out the HTML tags). Each e-mail client displays what it understands better (and hides all the rest of the message). 
 *
 *   Author:      Jakub Adámek
 *   Parameters:  same as PHP mail() plus
 *   @param       $additional_headers - use \r\n at the end of each row!
 *                $charset - e.g. iso-8859-1, iso-8859-2, windows-1250
 *                $use_base64 - set to 0 if you want to pass the message 8 bit encoded
 *   @return      true / false same as PHP mail()
*/

function mail_html_text ($to, $subject, $message, $additional_headers = "", $charset = "iso-8859-1", $use_base64 = 1) 
{
    $body = mail_html_text_body ($message, $charset, $use_base64);
    if ($GLOBALS["debug_email"]) {
        $body2 = mail_html_text_body ($message, $charset, 0);
        echo "To: $to<br>
            Subject: $subject<br>".
            nl2br(HTMLEntities($additional_headers.$body2));
    }
    if ($GLOBALS["EMAILS_INTO_TABLE"]) {
        global $db;
        $db->query ("
            INSERT INTO email_sent (send_to, subject, headers, body, created_at)
            VALUES ('".addslashes($to)."', '".addslashes($subject)."',
               '".$additional_headers."','".$body."', ".time().")");
        return true;               
    }               
    else return mail ($to, $subject, "", $additional_headers.$body);
}

function imap_mail_html_text ($to, $subject, $message, $additional_headers = "", $charset = "iso-8859-1", $use_base64 = 1, 
                              $cc = "", $bcc = "", $rpath = "") 
{
    $body = mail_html_text_body ($message, $charset, $use_base64);
    return imap_mail ($to, $subject, "", $additional_headers.$body, $cc, $bcc, $rpath);
}

function mail_html_text_body ($message, $charset, $use_base64) {
    $boundary = "-------AA-MULTI-".gensalt (20)."------";
    $encoding = $use_base64 ? "base64" : "8bit";
    $textmessage = html2text ($message);
        
    if ($use_base64) {
        $textmessage = base64_encode ($textmessage);
        $message = base64_encode ($message);
    }
       
    // All MIME headers should be terminated by CR+LF (\r\n)
    // but the headers in the individual parts should only be delimited by LF (\n)
       
    return
        "MIME-Version: 1.0\r\n"
        ."Content-Type: multipart/alternative;\r\n"
        ." boundary=\"$boundary\"\r\n"
        ."Content-Transfer-Encoding: $encoding\r\n"
        ."\r\n"
        ."--$boundary\n"

        ."Content-Type: text/html; charset=\"$charset\"\n"
        ."Content-Transfer-Encoding: $encoding\n"
        ."\n"
        .$message."\n"
        ."--$boundary\n"

        ."Content-Type: text/plain; charset=\"$charset\"\n"
        ."Content-Transfer-Encoding: $encoding\n"
        ."\r\n"
        .$textmessage."\n"
        ."--$boundary--\n";

}

/** 
* (c) Jakub Adamek, Econnect, December 2002
* Sends email from the table "email" to the address given.
* First resolves the aliases, working even with the {} inline commands.
*
* @param $mail_id   id from the email table
*        $to        email address
*        $aliases   (optional) array of alias => text
* @return true on success, false on failure
*/

function send_mail_from_table ($mail_id, $to, $aliases="") 
{
    global $db, $LANGUAGE_CHARSETS;
    $db->query("SELECT * FROM email WHERE id = $mail_id");
    if (!$db->next_record()) 
        return false;
    $record = $db->Record;
    reset ($record);
    
    if (is_array ($aliases)) {
        // I don't know how to work with unaliasing. Thus I try to pretend
        // having an item. 
        reset ($aliases);
        while (list ($alias, $translate) = each ($aliases)) {
            // I create the "columns"
            $cols[$alias][0] = array (
                "value" => $translate,
                "flag" => FLAG_HTML);
            // and "aliases" 
            $als [$alias] = array ("fce"=>"f_h", "param"=>$alias);
        }
        $item = new Item ("", $cols, $als, "", "" ,"");

        while (list ($key, $value) = each ($record)) 
            $record[$key] = $item->unalias ($value);
            
        /* // Mitra's version, not working:
        while (list ($key, $value) = each ($record)) {
            $level = 0; $maxlevel = 0;
    	    $record[$key] = new_unalias_recurent($value, "", $level, 
                $maxlevel, null, null, $aliases);
        }
        */    
    }
    
    if ($GLOBALS["EMAILS_INTO_TABLE"]) {
        $db->query ("
            INSERT INTO email_sent (email_id, send_to, subject, headers, body, created_at)
            VALUES ($mail_id, '".addslashes($to)."', '".addslashes($record["subject"])."',
               '".addslashes(get_email_headers($record, ""))."',
               '".addslashes($record["body"])."', ".time().")");
        return true;
    }
    
    else {
        if ($record["html"])
            return mail_html_text ($to, $record["subject"], $record["body"],
                 get_email_headers($record, ""), $LANGUAGE_CHARSETS [$record["lang"]]);
        else return mail ($to, $record["subject"], $record["body"], get_email_headers($record));
    }
}
    
function get_email_headers ($record, $default)
{
    $headers = array (
        "From" => "header_from",
        "Reply-To" => "reply_to",
        "Errors-To" => "errors_to",
        "Sender" => "sender");
    reset ($headers);
    while (list ($header, $field) = each ($headers)) {
        if ($record[$field])
            $retval .= $header.": ".$record[$field]."\r\n";
        else if ($default[$field])
            $retval .= $header.": ".$default[$field]."\r\n";
    }
    return $retval;
}

?>