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

require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."stringexpand.php3";
require_once $GLOBALS["AA_INC_PATH"]."htmlMimeMail/htmlMimeMail.php";

class HtmlMail extends HtmlMimeMail {

    /// This function fits a record from the @c email table.
    function setBasicHeaders ($record, $default) {
        $headers = array (
            "From" => "header_from",
            "Reply-To" => "reply_to",
            "Errors-To" => "errors_to",
            "Sender" => "sender");
        reset ($headers);
        while (list ($header, $field) = each ($headers)) {
            if ($record[$field])
                $this->setHeader ($header, $record[$field]);
            else if ($default[$field])
                $this->setHeader ($header, $default[$field]);
        }
    }
    
    // header encoding does not seem to work correctly
	function _encodeHeader($input, $charset = 'ISO-8859-1') {
        return $input;
    }
    
    function setCharset ($charset) {
        $this->setHeadCharset ($charset);
        $this->setHtmlCharset ($charset);
        $this->setTextCharset ($charset);
    }
};

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

/** 
* (c) Jakub Adamek, Econnect, December 2002
* Sends email from the table "email" to the address given.
* First resolves the aliases, working even with the {} inline commands.
*
* @param int $mail_id     id from the email table
*        mixed $to        email address or an array of email addresses
*        array $aliases   (optional) array of alias => text
* @return int count of successfully sent emails
*/

function send_mail_from_table ($mail_id, $to, $aliases="") 
{
    global $db, $LANGUAGE_CHARSETS;
    $db->query("SELECT * FROM email WHERE id = $mail_id");
    if (!$db->next_record()) 
        return false;
    $record = $db->Record;
    reset ($record);
    
    if (! is_array ($aliases)) 
        $aliases = array ("_#dUmMy__aLiAsSs#_" => "");

    // I try to pretend having an item. 
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
    
    if (! is_array ($to)) 
        $tos = array ($to);
    else $tos = $to;
    
    $sent = 0;
    
    $mail = new HtmlMail;
    if ($record["html"])
        $mail->setHtml ($record["body"], html2text ($record["body"]));
    else $mail->setText ($record["body"]);
    $mail->setSubject ($record["subject"]);
    $mail->setBasicHeaders ($record, "");
    $mail->setTextCharset ($LANGUAGE_CHARSETS [$record["lang"]]);
    $mail->setHtmlCharset ($LANGUAGE_CHARSETS [$record["lang"]]);

    foreach ($tos as $to) {
        if (! $to)
            continue;
            
        if (! $GLOBALS["EMAILS_INTO_TABLE"]) 
            if ($mail->send (array ($to)))
                $sent ++;
        else {
            if ($db->query ("
                INSERT INTO email_sent (email_id, send_to, subject, headers, body, created_at)
                VALUES ($mail_id, '".addslashes($to)."', '".addslashes($record["subject"])."',
                   '','".addslashes($record["body"])."', ".time().")"))
                $sent ++;
        }
    }
    
    return $sent;
}
   
?>