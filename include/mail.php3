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

/** Strips the HTML tags and lot more to get a plain text mail version.
*   Replaces URL links by the link in compound brackets behind the linked text.
*   Removes diacritics.
*/
function html2text ($html) {
    
    // reverse to htmlentities
    if (function_exists ("get_html_translation_table")) {
        $trans_tbl = get_html_translation_table (HTML_ENTITIES);
	    $trans_tbl = array_flip ($trans_tbl);
        $html = strtr ($html, $trans_tbl);
    }    

    // Strip diacritics
    $html = strtr( $html, "áäèïéìíåòóöøšúùüıÁÄÈÏÉÌÍÅÒÓÖØŠÚÙÜİ", 
                          "aacdeeilnoorstuuuyzAACDEEILNOORSTUUUYZ");
                
    // Replace URL references <a href="http://xy">Link</a> => Link {http://xy}
    /* We can't directly use preg_replace, because it would find the first <a href
       and the last </a>. */ 
    $ahref = "<[ \t]*a[ \t][^>]*href[ \t]*=[ \t]*[\"\\']([^\"\\']*)[\"\\'][^>]*>";
    preg_match_all ("'$ahref'si", $html, $html_ahrefs);
    $html_parts = preg_split ("'$ahref'si", $html);

    reset ($html_parts);
    reset ($html_ahrefs[0]);
    // Take the first part before any <a href>
    list (, $html) = each ($html_parts);
    while (list (, $html_part) = each ($html_parts)) {
        list (, $html_ahref) = each ($html_ahrefs[0]);
        preg_match ( "'$ahref(.*)</[ \t]*a[ \t]*>(.*)'si", $html_ahref. $html_part , $matches);
        if ( $matches[1] == $matches[2] ) {
            $html .= $matches[1]. $matches[3];
        } else {
            $html .= $matches[2]. ' {'. $matches[1] .'}'. $matches[3];
        }
    }

    $search_replace = array (
        // Strip out leading white space
        "'[\r\n][ \t]+'" => "",
        "'[\r\n]*'"      => "",
        "'<hr>'si"       => "\n------------------------------------------------------------\n",
        "'</tr>'si"      => "\n",
        "'</table>'si"   => "\n",
        // If the previous commands added too much whitespace, delete it
        "'\\n\\n\\n+'si" => "\n\n",
        "'<br[^>]{0,2}>'si"       => "\n",   // <br> as well as <br />
        "'</p>'si"       => "\n\n",
        "'</h[1-9]>'si"  => "\n\n",
        // Strip out javascript
        "'<script[^>]*?>.*?</script>'si" => "",
        // Strip out html tags
        "'<[\/\!]*?[^<>]*?>'si"          => "",
        // Replace html entities
        "'&(quot|#34);'i" => '"',                 
        "'&(amp|#38);'i"  => '&',
        "'&(lt|#60);'i"   => '<',
        "'&(gt|#62);'i"   => '>',
        "'&(nbsp|#160);'i"=> ' ',
        // evaluate as php
        "'&#(\d+);'e"     => "chr(\\1)");                    
        
    reset ($search_replace);
    while (list ($search, $replace) = each ($search_replace)) 
        $html = preg_replace ($search, $replace, $html);

    return $html;
}

// -----------------------------------------------------------------------------
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
    return send_mail_from_table_inner ($mail_id, $to, $item);
}

// ---------------------------------------------------------------------------
  
function send_mail_from_table_inner ($mail_id, $to, $item, $aliases = null) {
    global $db, $LANGUAGE_CHARSETS;
    // email has the templates in it
    $db->query("SELECT * FROM email WHERE id = $mail_id");
    if (!$db->next_record()) 
        return false;
    $record = $db->Record;
    reset ($record);       
    
    /* Old version - Jakub's
    while (list ($key, $value) = each ($record)) 
        $record[$key] = $item->unalias ($value);
    */
    // Mitra's version, - should be working now
    while (list ($key, $value) = each ($record)) {
        $level = 0; $maxlevel = 0;

	    $record[$key] = new_unalias_recurent($value, "", $level, 
            $maxlevel, $item, null, $aliases);
    }
    
    if (! is_array ($to)) 
        $tos = array ($to);
    else $tos = $to;
    
    $sent = 0;
    
    if ($tos[0] == "jakubadamek@ecn.cz")
        $record["body"] .= "<br>Text version is:<hr>" . 
            nl2br(HtmlEntities(html2text ($record["body"])));
    
    $mail = new HtmlMail;
    if ($record["html"])
        $mail->setHtml ($record["body"], html2text ($record["body"]));
    else $mail->setText (html2text( nl2br($record["body"])));
    $mail->setSubject ($record["subject"]);
    $mail->setBasicHeaders ($record, "");
    $mail->setTextCharset ($LANGUAGE_CHARSETS [$record["lang"]]);
    $mail->setHtmlCharset ($LANGUAGE_CHARSETS [$record["lang"]]);

    foreach ($tos as $to) {
        if (! $to)
            continue;
            
        if (! $GLOBALS["EMAILS_INTO_TABLE"]) {
            #huhl("Sending mail $to");
            if ($mail->send (array ($to)))
                $sent ++;
        }
        else {
            if ($db->query ("
                INSERT INTO email_sent (email_id, send_to, subject, headers, body, text_body, created_at)
                VALUES ($mail_id, '".addslashes($to)."', '".addslashes($record["subject"])."',
                   '','".addslashes($record["body"])."','".
                   addslashes(html2text($record["body"]))."', ".time().")"))
                $sent ++;
        }
    }
    
    return $sent;
}

// -----------------------------------------------------------------------------

?>