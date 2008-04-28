<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Jakub Adámek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// (c) Jakub Adámek, Econnect, December 2002

require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."item_content.php3";
require_once AA_INC_PATH."slice.class.php3";
require_once AA_INC_PATH."stringexpand.php3";
require_once AA_INC_PATH."htmlMimeMail/htmlMimeMail.php";
require_once AA_INC_PATH."validate.php3";
require_once AA_INC_PATH."toexecute.class.php3";

class HtmlMail extends htmlMimeMail {

    /** setFromTemplate function
     *  Prepares the mail for sending
     *  The e-mail template is taken from database and all aliases
     *  in the template are expanded acording tho $item
     * @param $mail_id
     * @param $item
     */
    function setFromTemplate($mail_id, $item) {
        global  $LANGUAGE_CHARSETS;
        // email has the templates in it
        $record = GetTable2Array("SELECT subject, body, header_from, reply_to, errors_to, sender, lang, html
                                  FROM email WHERE id = $mail_id", 'aa_first', 'aa_fields');
        if (!$record) {
            return false;
        }
        // unalias all the template fields including errors_to ...
        foreach ( $record as $key => $value) {
            $level = 0; $maxlevel = 0;
            $record[$key] = new_unalias_recurent($value, "", $level, $maxlevel, $item);
        }
        if ($record["html"]) {
            $this->setHtml( $record["body"], html2text($record["body"]));
        } else {
            $this->setText( html2text( nl2br($record["body"]) ));
        }
        $this->setSubject($record["subject"]);
        $this->setBasicHeaders($record, "");
        $this->setCharset($LANGUAGE_CHARSETS[$record["lang"]]);
    }

    /** sendLater function
     *  Send prepared e-mail to adresses specified in the $to array.
     *  The e-mail is queued it AA_Toexecute queue before sending (not imediate)
     * @param $to
     */
    function sendLater($to) {
        $toexecute = new AA_Toexecute;

        $tos  = array_unique(is_array($to) ? $to : array($to));
        $sent = 0;
        foreach ($tos as $to) {
            if (!$to OR !AA_Validate::validate($to, 'email')) {
                continue;
            }

            // 2 minutes for each 20 e-mails
            if ( ($sent % 20) == 0 ) {
                set_time_limit( 120 );
            }

            // Yes, two nested arrays - mail->send() accepts array($to) and
            // all parameters to later must be in another only one array
            if ( $toexecute->later($this, array(array($to)), 'send_mail') ) {
                $sent++;
            }
        }
        return $sent;
    }

    /** setBasicHeaders function
     *  This function fits a record from the @c email table.
     * @param $record
     * @param $default
     */
    function setBasicHeaders($record, $default) {
        $headers = array (
            "From"        => "header_from",
            "Reply-To"    => "reply_to",
            "Errors-To"   => "errors_to",
            "Sender"      => "sender"
            );
        foreach ( $headers as $header => $field) {
            if ($record[$field]) {
                $this->setHeader($header, $record[$field]);
            }
            elseif ($default[$field]) {
                $this->setHeader($header, $default[$field]);
            }
        }
        // bounces are going to errors_to (if defined) or ...
        $return_path = ( $record['errors_to']    ? $record['errors_to'] :
                        ( $record['header_from'] ? $record['header_from'] :
                          ERROR_REPORTING_EMAIL));
        $this->setReturnPath($return_path);
    }

    /** _encodeHeader function
     *  header encoding does not seem to work correctly
     * @param $input
     * @param $charset
     */
    function _encodeHeader($input, $charset = 'ISO-8859-1') {
        return $input;
    }
    /** setCharset function
     * @param $charset
     */
    function setCharset($charset) {
        $this->setHeadCharset($charset);
        $this->setHtmlCharset($charset);
        $this->setTextCharset($charset);
    }

    /** toexecutelater function
     *  Toexecutelater - special function called from AA_Toexecute class
     *  - used for queued tasks (runed form cron)
     * @param $to
     */
    function toexecutelater($to) {
        return $this->send($to);
    }
};

/** html2text function
 *  Strips the HTML tags and lot more to get a plain text mail version.
 *   Replaces URL links by the link in compound brackets behind the linked text.
 *   Removes diacritics.
 * @param $html
 */
function html2text($html) {

    // reverse to htmlentities
    if (function_exists ("get_html_translation_table")) {
        $trans_tbl = get_html_translation_table(HTML_ENTITIES);
        $trans_tbl = array_flip($trans_tbl);
        $html      = strtr($html, $trans_tbl);
    }

    // Strip diacritics
    $html = strtr( $html, "áäèïéìíåòóöø¹»úùüı¾ÁÄÈÏÉÌÍÅÒÓÖØ©«ÚÙÜİ®",
                          "aacdeeilnoorstuuuyzAACDEEILNOORSTUUUYZ");

    // Replace URL references <a href="http://xy">Link</a> => Link {http://xy}
    /* We can't directly use preg_replace, because it would find the first <a href
       and the last </a>. */
    $ahref = "<[ \t]*a[ \t][^>]*href[ \t]*=[ \t]*[\"\\']([^\"\\']*)[\"\\'][^>]*>";
    preg_match_all("'$ahref'si", $html, $html_ahrefs);
    $html_parts = preg_split("'$ahref'si", $html);

    reset($html_parts);
    reset($html_ahrefs[0]);
    // Take the first part before any <a href>
    list(, $html) = each ($html_parts);
    while (list(, $html_part) = each($html_parts)) {
        list(, $html_ahref) = each($html_ahrefs[0]);
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

    foreach ( $search_replace as $search => $replace) {
        $html = preg_replace($search, $replace, $html);
    }

    return $html;
}

// -----------------------------------------------------------------------------
/** send_mail_from_table function
* (c) Jakub Adamek, Econnect, December 2002
* Sends email from the table "email" to the address given.
* First resolves the aliases, working even with the {} inline commands.
*
* @param  int $mail_id     id from the email table
* @param  mixed $to        email address or an array of email addresses
* @param  array $aliases   (optional) array of alias => text
* @return int count of successfully sent emails
*/
function send_mail_from_table($mail_id, $to, $aliases="") {
    if (!is_array($aliases)) {
        $aliases = array ("_#dUmMy__aLiAsSs#_" => "");
    }

    // I try to pretend having an item.
    foreach ($aliases as $alias => $translate) {
        // I create the "columns"
        $cols[$alias][0] = array(
            "value" => $translate,
            "flag"  => FLAG_HTML);
        // and "aliases"
        $als[$alias] = array ("fce"=>"f_h", "param"=>$alias);
    }
    $item = new AA_Item($cols, $als);
    return send_mail_from_table_inner($mail_id, $to, $item);
}

/** send_mail_to_reader function
 *  Sends mail defined in e-mail template id $mail_id to all $recipients.
 *  Mail template is unaliased using aliases and data form item identified by
 *  $zids (often Reader item). The recipients are Reders itself, by default.
 * @param $mail_id
 * @param $zids
 * @param $recipient
 */
function send_mail_to_reader($mail_id, $zids, $recipient='aa_field_email') {
    $mail_count = 0;
    for ( $i=0; $i<$zids->count(); $i++) {
        $item = AA_Item::getItem($zids->longids($i));
        $to   = (($recipient=='aa_field_email') ? $item->getval(FIELDID_EMAIL) : $recipient);
        $mail_count += send_mail_from_table_inner($mail_id, $to, $item);
    }
    return $mail_count;
}

/** send_mail_from_table_inner function
 *  Sends mail defined in e-mail template id $mail_id to all e-mails listed
 *  in $to (array or string) and unalias aliases according to $item
 * @param $mail_id
 * @param $to
 * @param $item
 */
function send_mail_from_table_inner($mail_id, $to, $item) {
    // email has the templates in it
    $mail = new HtmlMail;
    $mail->setFromTemplate($mail_id, $item);
    return $mail->sendLater($to);
}

// -----------------------------------------------------------------------------

?>