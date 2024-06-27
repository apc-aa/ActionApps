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
use AA\Util\Langs;

require_once __DIR__ . "/item.php";
require_once __DIR__ . "/item_content.php";
require_once __DIR__ . "/stringexpand.php";
require_once __DIR__ . "/htmlMimeMail/htmlMimeMail.php";
require_once __DIR__ . "/validate.php";

// now used only getAsArray and setAs Array - other features like iEditable, getAllAsArray is not used, now
class AA_Mailtemplate implements AA_iEditable, ArrayAccess, Countable, Iterator {
    use AA\Util\DataArrayAccessTrait;

    /** associative array of mail template data - used also by  DataArrayAccessTrait*/
    protected $data = [];

    function __construct($id=null) {
        $this->data = [];
        if ($id and ctype_digit((string)$id)) {
            $template = AA_Metabase::getContent(new zids($id, 's'), ['table' => 'email']);
            if ($template[$id]) {
                $this->data = $template[$id]->getAllAsScalar();
            }
        }
    }

    public function getAsMailArray(): array {
        if ($this->isEmpty()) {
            return [];
        }
        $ret = $this->data;
        $ret['lang'] = Langs::getCharset($ret['lang']);
        return $ret;
    }

    /** creates new mail template from the array and saves it to the database
     * @param array  $mail_arr
     * @param string $type
     * @param string $description
     * @param string $owner
     * @return AA_Mailtemplate - filled or empty if store is failed
     */
    public static function createFromMailArray(array $mail_arr, string $type, string $description, string $owner): AA_Mailtemplate {
        $mail = new AA_Mailtemplate;
        $mail->data = $mail_arr;
        $mail->data['type']            = $type;
        $mail->data['description']     = $description;
        $mail->data['owner_module_id'] = $owner;
        $mail->data['lang'] = Langs::fixLang($mail->data['lang']);   // Mail uses just charset, while template uses lang ('cz-utf8')
        if ($id = AA::Metabase()->doInsert('email', $mail->data)) {
            $mail->data['id'] = $id;
            return $mail;
        }
        return new AA_Mailtemplate;  // empty
    }

    public function getName()                                { return $this->data['description']; }
    public function getId()                                  { return $this->offsetGet('id') ?? 0; }
    public function getOwnerId()                             { return $this->data['owner_module_id']; }
    public function getProperty($property_id, $default=null) { return $this->data[$property_id]; }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows(AA_Form $form): void {
        $form->addProperties(static::getClassProperties());
    }

    public static function getClassProperties(): array {
        return AA::Metabase()->getColumnProperties('email');
    }

    public static function load($id, $type=null) {
        return new AA_Mailtemplate($id);
    }


    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {}
    /** AA_iEditable method - save the object to the database */
    public        function save() {}

    // Array Access implementation is in the DataArrayAccessTrait  --------------------------
}

class AA_Mail extends htmlMimeMail implements \AA\Later\LaterInterface {

    /** $template_id and $reader_id is mainly for getId() used in AA_Log after toexecute */
    protected $template_id = '';
    protected $reader_id   = '';

    /** used in AA_Log after toexecute */
    function getId() {
        return $this->template_id.':'.$this->reader_id;
    }

    /** setFromTemplate function
     *  Prepares the mail for sending
     *  The e-mail template is taken from a database, and all aliases
     *  in the template are expanded according tho $item
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
        if ( !($record = (new AA_Mailtemplate((int)$mail_id))->getAsMailArray())) {
            return false;
        }
        // unalias all the template fields including errors_to ...
        foreach ( $record as $key => $value) {
            $record[$key] = AA::Un($value, "", $item);
        }
        return $this->setFromArray($record);
    }

    /** record array('subject','body','header_from','reply_to','errors_to','sender','lang','html','cc','bcc','attachments')  */
    function setFromArray(array $record) {
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
        $this->setBasicHeaders($record);
        $this->setCharset($lang);

        if ($record["cc"]) {
            $this->setCc($record["cc"]);
        }
        if ($record["bcc"]) {
            $this->setBcc($record["bcc"]);
        }

        if ($record['attachments']) {
            $this->addAttachmentFromString($record['attachments']);
        }
        return true;
    }

    /**
    * @param string $string either colon separated list of files (https#://test.org/img1.png:https#://test.org/img2.png)
    *                       or json array of files (["https://test.org/img1.png","https://test.org/img2.png"])
    *                       or json object of files ({"filename1.png":"https://test.org/img1.png","filename2.png":"https://test.org/img2.png"})
    */
    static function parseAttachmentsFromString(string $string, bool $extended=false): array {
        $attachments = [];
        switch ($string[0]) {
            case '[':
                $att_arr = json2arr($string);
                break;
            case '{':
                $att_arr = json2asoc($string);
                break;
            default:
                $att_arr = ParamExplode($string);
        }
        foreach ($att_arr as $name => $attachment) {
            if ($attachment) {
                if (strlen($att_data = AA_File_Wrapper::wrapper($attachment)->contents())) {
                    if (ctype_digit((string)$name)) {
                        $name = basename(parse_url($attachment, PHP_URL_PATH));
                    }
                    $attachments[] = $extended ? ['name' => $name, 'data' => $att_data, 'type' => get_headers($attachment, true)['Content-Type'] ?? ''] : ['name' => $name, 'data' => $att_data];
                }
            }
        }
        return $attachments;
    }

    /** Adds attachments to the e-mail
     * @param string $string either colon separated list of files (https#://test.org/img1.png:https#://test.org/img2.png)
     *                       or json array of files (["https://test.org/img1.png","https://test.org/img2.png"])
     *                       or json object of files ({"filename1.png":"https://test.org/img1.png","filename2.png":"https://test.org/img2.png"})
     */
    protected function addAttachmentFromString(string $string) {
        $attachments = self::parseAttachmentsFromString($string);
        foreach ($attachments as $attachment) {
            $this->addAttachment($attachment['data'], $attachment['name']);
        }
    }

    /** Send prepared e-mail to addresses specified in the $to array.
     *  The e-mail is queued it AA\Later\Toexecute queue before sending (not immediate)
     * @param $to
     * @param bool $all_later Sends all mails later - otherwise, the first two mails are sent directly
     * @return int number of sent mails
     */
    function sendLater($to, $all_later=false): int {
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
            if (!$all_later AND (++$send_mails_total < 3)) {
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
    function setBasicHeaders(array $record= [], array $default= []) {
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
        $return_path = $record['sender'] ?: ( $record['header_from'] ?: ERROR_REPORTING_EMAIL );
        $extracted_return_path = $this->extractEmails($return_path);
        if (isset($extracted_return_path[0])) {
            $this->setReturnPath($extracted_return_path[0]);
        }
    }

    /** Extracts e-mail from the string:
     *  Econnect <info@ecn.cz>  -> info@ecn.cz
     */
    function extractEmails($string){
        preg_match_all("/[._a-zA-Z0-9-]+@[._a-zA-Z0-9-]+/i", $string, $matches);
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
     * @param $later bool|null
     *
     * Called as AA_Mail::sendTemplate($mail_id,$to,$item=null)
     * @return bool|int|mixed
     */
     static function sendTemplate($mail_id, $to, $item=null, $later=null) {
        // email has the templates in it
        $mail = new AA_Mail;
        if (!$mail->setFromTemplate($mail_id, $item)) {
            return false;
        }

        if (is_null($later)) {
            return $mail->sendLater($to);  // standard - first two mails send, other queue
        } elseif ($later === true) {
            return $mail->sendLater($to, true);
        }

        $tos  = array_unique(array_map('trim', is_array($to) ? $to : [$to]));
        return $mail->send($tos);
    }

    /** sendToReader function
     *  Sends mail defined in e-mail template id $mail_id to all zids (Readers).
     *  Mail template is unaliased using aliases and data form item identified by
     *  $zids (often Reader item). The recipients are Readers itself, by default.
     * @param int    $mail_id
     * @param zids   $zids
     * @param string $slice_id - slice of the Readers - used for slice credentials
     * @return int number of ids to send mails
     */
    static function sendToReader($mail_id, zids $zids, $slice_id): int {
        $chunks = array_chunk($zids->longids(),50);  // plan 50 mails in one shot
        if (count($chunks)==1) {
            // less than 50 mails - schedule right now
            $mail_scheduler = new AA_Mail_Scheduler($mail_id,$chunks[0], $slice_id);
            $mail_scheduler->schedule();  // right now
        } else {
            // more than 50 - plan scheduling
            // there were problems with more than 1000 mails - it wasn't possible to plan it in one shot
            $toexecute  = new Toexecute;
            foreach ($chunks as $chunk) {
                $mail_scheduler = new AA_Mail_Scheduler($mail_id, $chunk, $slice_id);
                $toexecute->later($mail_scheduler, [], "AA_Mail_Scheduler:$mail_id", 105);      // a bit higher priority than mail sending
            }
        }
        return $zids->count();
    }
};

/** Helps split email generation into chunks - 50 mails each task
 *  there were problems with more than 1000 mails - it wasn't possible to plan it in one shot
 *  @see AA_Mail::sendToReader();
 */
class AA_Mail_Scheduler implements \AA\Later\LaterInterface {
    protected $mail_id;
    protected $reader_ids;

    /** @var string $slice_id slice of the Readers - used for slice credentials */
    protected $slice_id;

    function __construct($mail_id, $reader_ids, $slice_id) {
        $this->mail_id    = $mail_id;
        $this->reader_ids = $reader_ids;
        $this->slice_id   = $slice_id;
    }

    /** this will be written to AA_Log after toexecute. No other purpose */
    function getId() {
       return $this->mail_id;
    }

    function schedule() {
        // Allow using current slice data without slice_pwd
        AA_Credentials::singleton()->loadFromSlice($this->slice_id);

        $mail_count = 0;
        if ($this->mail_id AND count($this->reader_ids)) {
            foreach ( $this->reader_ids as $id ) {
                $item = AA_Item::getItem($id);
                $to   = $item->getval(FIELDID_EMAIL);
                $mail_count += AA_Mail::sendTemplate($this->mail_id, $to, $item, true);
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

/** Strips the HTML tags and a lot more to get a plain text mail version.
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
        "'<li>'si",
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
        "'<[/!]*?[^<>]*?>'si",
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
        "\n  * ",
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
        // trim the end of line whitespaces (also fixes responsiveness when long line is present)
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

function GetMailManaged(): \AA\Managed {
    $managed = AA::Metabase()->getManagerConf('email');
    $managed->setColumns2show(['id', 'type', 'subject', 'description']);
    $managed->setWidgetSetting('html', ['type' => 'bool', 'name' => _m('Use HTML')]);
    $managed->setWidgetSetting('type', ['enum' => AA_Collection::get_email_types(), 'name' => _m('Email type')]);
    $managed->setWidgetSetting('lang', ['enum' => Langs::getNames(), 'name' => _m('Language (charset)')]);
    $managed->setWidgetSetting('owner_module_id', ['enum' => AA_Module::getUserModules(), 'name' => _m('Owner')]);
    $managed->setWidgetSetting('description', ['name' => _m('Description/Result')]);
    $managed->setWidgetSetting('subject', ['name' => _m('Subject')]);
    $managed->setWidgetSetting('body', ['name' => _m('Body')]);
    $managed->setWidgetSetting('header_from', ['name' => _m('From (email) ')]);
    $managed->setWidgetSetting('reply_to', ['name' => _m('Reply to (email)')]);
    $managed->setWidgetSetting('errors_to', ['name' => _m('Errors to (email)')]);
    $managed->setWidgetSetting('sender', ['name' => _m('Envelop sender (email)')]);
    $managed->setWidgetSetting('attachments', ['name' => _m('Attachments')]);
    return $managed;
}

