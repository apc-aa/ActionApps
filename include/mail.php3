<?php
/**
 *
 * PHP version 7.2+
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
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// (c) Jakub Adamek, Econnect, December 2002

use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;

require_once __DIR__."/item.php3";
require_once __DIR__."/item_content.php3";
require_once __DIR__."/stringexpand.php3";
require_once __DIR__."/htmlMimeMail/htmlMimeMail.php";
require_once __DIR__."/validate.php3";


class AA_Mailtemplate implements AA_iEditable {
    protected $record;

    function __construct($id=null) {
        $this->record = [];
        if ($id and ctype_digit((string)$id)) {
            $template = AA_Metabase::getContent( ['table'=>'email'], new zids($id, 's') );
            if ($template[$id]) {
                $this->record = $template[$id];
            }
        }
    }

    public function getName()                                { return $this->record['description']; }
    public function getId()                                  { return $this->record['id']; }
    public function getOwnerId()                             { return $this->record['owner_module_id']; }
    public function getProperty($property_id, $default=null) { return $this->record[$property_id]; }

        /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows($form) {
        return $form->addProperties(static::getClassProperties());
    }

    public static function getClassProperties()  {
        return AA_MetabaseTableEdit::defaultGetClassProperties('email');
    }

    public static function load($id, $type=null) {
        return new AA_Mailtemplate($id);
    }


    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {}
    /** AA_iEditable method - save the object to the database */
    public        function save() {}
}

class AA_Mail extends htmlMimeMail implements \AA\Later\LaterInterface {

    /** $template_id and $reader_id is mainly for getId() used in AA_Log after toexecute */
    protected $template_id = '';
    protected $reader_id   = '';

    /** static getTemplate */
    static function getTemplate($mail_id) {
        // return GetTable2Array("SELECT * FROM email WHERE id = $mail_id", 'aa_first', 'aa_fields');
        return DB_AA::select1([], "SELECT * FROM email", [['id', $mail_id]]);
    }

    /** used in AA_Log after toexecute */
    function getId() {
        return $this->template_id.':'.$this->reader_id;
    }

    /** setFromTemplate function
     *  Prepares the mail for sending
     *  The e-mail template is taken from database and all aliases
     *  in the template are expanded acording tho $item
     * @param $mail_id
     * @param $item
     * @return bool
     */
    function setFromTemplate($mail_id, $item=null) {

        $this->template_id = $mail_id;
        if (is_object($item)) {
            $this->reader_id = $item->getId();
        }

        // email has the templates in it
        if (! ( $record = AA_Mail::getTemplate($mail_id))) {
            return false;
        }
        // unalias all the template fields including errors_to ...
        foreach ( $record as $key => $value) {
            $record[$key] = AA::Stringexpander()->unalias($value, "", $item);
        }
        $record["lang"] = AA_Langs::getCharset($record["lang"]);
        return $this->setFromArray($record);
    }

    /** record array('subject','body','header_from','reply_to','errors_to','sender','lang','html','cc','bcc')  */
    function setFromArray($record) {
        // do not send empty emails
        if ( !strlen(trim($record["body"]))) {
            return false;
        }

        $lang = strlen($record["lang"]) ? $record["lang"] : 'utf-8';
        if ($record["html"]) {
            $this->setHtml( $record["body"], html2text($record["body"],$lang) );
        } else {
            $this->setText( html2text( nl2br($record["body"]), $lang) );
        }

        $this->setSubject($record["subject"]);
        $this->setBasicHeaders($record, "");
        $this->setCharset($lang);

        if ($record["cc"]) {
            $this->setCc($record["cc"]);
        }
        if ($record["bcc"]) {
            $this->setBcc($record["bcc"]);
        }

        if ($record['attachments']) {
            $attachs = ParamExplode($record['attachments']);
            foreach ($attachs as $attachment) {
                if ($attachment) {
                    $att_data = $this->getFile($attachment);
                }
                $this->addAttachment($att_data, basename(parse_url($attachment, PHP_URL_PATH)));
            }
        }
        return true;
    }

    /** sendLater function
     *  Send prepared e-mail to adresses specified in the $to array.
     *  The e-mail is queued it AA\Later\Toexecute queue before sending (not imediate)
     * @param $to
     * @return int
     */
    function sendLater($to) {
        static $send_mails_total = 0;

        $toexecute = new Toexecute;

        $tos  = array_unique(AA_Validate::filter((array)$to, 'email'));
        $sent = 0;
        foreach ($tos as $to) {

            // 2 minutes for each 20 e-mails
            if ( ($sent % 20) == 0 ) {
                @set_time_limit( 120 );
            }

            // first two mails in the script send directly (for better UX)
            if (++$send_mails_total < 3) {
                $this->send([$to]);
                ++$sent;
            } else {
                // Yes, two nested arrays - mail->send() accepts array($to) and
                // all parameters to later must be in another only one array
                if ( $toexecute->later($this, [[$to]], 'AA_Mail'.$this->getId())) {
                    ++$sent;
                }
            }
        }
        return $sent;
    }

    /** setBasicHeaders function
     *  This function fits a record from the @c email table.
     * @param $record
     * @param $default
     */
    function setBasicHeaders($record= [], $default= []) {
        $headers = [
            "From"        => "header_from",
            "Reply-To"    => "reply_to",
            "Errors-To"   => "errors_to"
        ];
        foreach ( $headers as $header => $field) {
            if ($record[$field]) {
                $this->setHeader($header, $record[$field]);
            }
            elseif ($default[$field]) {
                $this->setHeader($header, $default[$field]);
            }
        }
        // bounces are going to errors_to (if defined) or ...
        $return_path = ( $record['sender']    ? $record['sender'] :
                        ( $record['header_from'] ? $record['header_from'] :
                          ERROR_REPORTING_EMAIL));
        $extracted_return_path = $this->extractEmails($return_path);
        if (isset($extracted_return_path[0])) {
            $this->setReturnPath($extracted_return_path[0]);
        }
    }

    /** Extracts e-mail from the string:
     *  Econnect <info@ecn.cz>  -> info@ecn.cz
     */
    function extractEmails($string){
        preg_match_all("/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $string, $matches);
        return $matches[0];
    }

    /** _encodeHeader function
     *  header encoding does not seem to work correctly
     * @param $input
     * @param $charset
     */
    //maybe fixed in new version 2.5.2 - trying to use default - Honza 15.3.2009
    //function _encodeHeader($input, $charset = 'ISO-8859-1') {
    //    return $input;
    //}

    /** setCharset function
     * @param $charset
     */
    function setCharset($charset) {
        $this->setHeadCharset($charset);
        $this->setHtmlCharset($charset);
        $this->setTextCharset($charset);
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *                       - [0] - $to
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {
        [$to] = $params;
        return $this->send($to);
    }

    /** AA_Mail::sendTemplate function
     *  Sends mail defined in e-mail template id $mail_id to all e-mails listed
     *  in $to (array or string) and unalias aliases according to $item
     * @param $mail_id
     * @param $to   string|array
     * @param $item
     *
     * Called as AA_Mail::sendTemplate($mail_id,$to,$item=null)
     * @return bool|int|mixed
     */
     static function sendTemplate($mail_id, $to, $item=null, $later=true) {
        // email has the templates in it
        $mail = new AA_Mail;
        if (!$mail->setFromTemplate($mail_id, $item)) {
            return false;
        }

        if ($later) {
            return $mail->sendLater($to);
        }


        $tos  = array_unique(array_map('trim', is_array($to) ? $to : [$to]));
        return $mail->send($tos);
    }

    /** sendToReader function
     *  Sends mail defined in e-mail template id $mail_id to all zids (Readers).
     *  Mail template is unaliased using aliases and data form item identified by
     *  $zids (often Reader item). The recipients are Reders itself, by default.
     * @param $mail_id
     * @param $zids
     * @return
     */
    static function sendToReader($mail_id, $zids) {
        $chunks = array_chunk($zids->longids(),50);  // plan 50 mails in one shot
        if (count($chunks)==1) {
            // less than 50 mails - schedule right now
            $mail_scheduler = new AA_Mail_Scheduler($mail_id,$chunks[0]);
            $mail_scheduler->schedule();  // right now
        } else {
            // more than 50 - plan scheduling
            // there was problems wit more than 1000 mails - it wasn't possible to plan it in one shot
            $toexecute  = new Toexecute;
            foreach ($chunks as $chunk) {
                $mail_scheduler = new AA_Mail_Scheduler($mail_id, $chunk);
                $toexecute->later($mail_scheduler, [], "AA_Mail_Scheduler:$mail_id", 105);      // a bit higher priority than mail sending
            }
        }
        return $zids->count();
    }
};

/** Helsp split email generation into chunks - 50 mails each task
 *  there was problems with more than 1000 mails - it wasn't possible to plan it in one shot
 *  @see AA_Mail::sendToReader();
 */
class AA_Mail_Scheduler implements \AA\Later\LaterInterface {
    protected $mail_id;
    protected $reader_ids;

    function __construct($mail_id, $reader_ids) {
        $this->mail_id    = $mail_id;
        $this->reader_ids = $reader_ids;
    }

    /** this will be written to AA_Log after toexecute. No other purpose */
    function getId() {
       return $this->mail_id;
    }

    function schedule() {
        $mail_count = 0;
        if ($this->mail_id AND count($this->reader_ids)) {
            foreach ( $this->reader_ids as $id ) {
                $item = AA_Item::getItem($id);
                $to   = $item->getval(FIELDID_EMAIL);
                $mail_count += AA_Mail::sendTemplate($this->mail_id, $to, $item);
            }
        }
        return $mail_count;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {
        return $this->schedule();
    }
}

/** html2text function
 *  Strips the HTML tags and lot more to get a plain text mail version.
 *   Replaces URL links by the link in compound brackets behind the linked text.
 * @param $html string
 * @return null|string|string[]
 */
function html2text($html, $encoding='utf-8') {

    $html = html_entity_decode(str_ireplace(['&nbsp;','&ensp;','&emsp;','&thinsp;'], [' ',' ',' ',' '], $html));

    // Strip diacritics
    // $html = strtr( $html, "������������������������������ة����ݮ",
    //                       "aacdeeilnoorstuuuyzAACDEEILNOORSTUUUYZ");

    // Replace URL references <a href="http://xy">Link</a> => Link {http://xy}
    /* We can't directly use preg_replace, because it would find the first <a href
       and the last </a>. */
    $ahref       = "<[ \t]*a[ \t][^>]*href[ \t]*=[ \t]*[\"\\']([^\"\\']*)[\"\\'][^>]*>";
    $html_ahrefs = [];
    preg_match_all("'$ahref'si", $html, $html_ahrefs);
    $html_parts = preg_split("'$ahref'si", $html);

    reset($html_parts);
    reset($html_ahrefs[0]);
    $matches = [];
    // Take the first part before any <a href>
    $html = array_shift($html_parts);
    foreach ($html_parts as $html_part) {
        $html_ahref = current($html_ahrefs[0]);
        next($html_ahrefs[0]);
        preg_match ( "'$ahref(.*)</[ \t]*a[ \t]*>(.*)'si", $html_ahref. $html_part , $matches);
        if ( $matches[1] == $matches[2] ) {
            $html .= $matches[1]. $matches[3];
        } else {
            $html .= $matches[2]. ' {'. $matches[1] .'}'. $matches[3];
        }
    }

    $search = [
        // Strip out leading white space
        "'[\r\n][ \t]+'",
        "'[\r\n]*'",
        "'<hr>'si",
        "'</tr>'si",
        "'</table>'si",
        "'<br[^>]{0,2}>'si",   // <br> as well as <br />
        "'</p>'si",
        "'</h[1-9]>'si",
        // Strip out javascript, style and head
        "'<head[^>]*?>.*?</head>'si",
        "'<script[^>]*?>.*?</script>'si",
        "'<style[^>]*?>.*?</style>'si",
        // Strip out html tags
        "'<[\/\!]*?[^<>]*?>'si",
        // trim end of line whitespaces (also fixes responsivnes when long line is present)
        ($encoding=='utf-8') ? "'\\h+\\n'u" : "'\\h+\\n'",
        // If the previous commands added too much whitespace, delete it
        "'\\n\\n\\n+'si"
    ];

   $replace = [
        // Strip out leading white space
        "",
        "",
        "\n------------------------------------------------------------\n",
        "\n",
        "\n",
        "\n",   // <br> as well as <br />
        "\n\n",
        "\n\n",
        // Strip out javascript, style and head
        "",
        "",
        "",
        // Strip out html tags
        "",
        // trim end of line whitespaces (also fixes responsivnes when long line is present)
        "\n",
        // If the previous commands added too much whitespace, delete it
        "\n\n\n"
   ];

        // Replace html entities - removed - now done by html_entity_decode() above
        // "'&(quot|#34);'i" => '"',
        // "'&(amp|#38);'i"  => '&',
        // "'&(lt|#60);'i"   => '<',
        // "'&(gt|#62);'i"   => '>',
        // "'&(nbsp|#160);'i"=> ' ',
        // evaluate as php
        //"'&#(\d+);'e"     => "chr(\\1)");

    $html = preg_replace($search, $replace, $html);
    return $html;
}
