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
use AA\Later\LaterInterface;
use AA\Later\Toexecute;
use AA\Managed;
use AA\Util\Langs;

require_once __DIR__ . "/item.php";
require_once __DIR__ . "/item_content.php";
require_once __DIR__ . "/stringexpand.php";
require_once __DIR__ . "/htmlMimeMail/htmlMimeMail.php";
require_once __DIR__ . "/validate.php";

// now used only getAsArray and setAs Array - other features like iEditable, getAllAsArray is not used, now
class AA_Mailtemplate implements AA_iEditable, ArrayAccess, Countable, IteratorAggregate {
    use AA\Util\DataArrayAccessTrait;

    /** associative array of mail template data - used also by  DataArrayAccessTrait*/
    protected $data = [];

    function __construct($id=null) {
        if ($id && ctype_digit((string)$id)) {
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

    public static function load($id, $type=null): AA_Mailtemplate
    {
        return new AA_Mailtemplate($id);
    }


    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {}
    /** AA_iEditable method - save the object to the database */
    public        function save(): false|string { return false; }

    // Array Access implementation is in the DataArrayAccessTrait  --------------------------
}

class AA_Mail extends htmlMimeMail implements LaterInterface {

    /** $template_id and $reader_id is mainly for getId() used in AA_Log after toexecute */
    private $template_id = '';
    private $reader_id   = '';

    /** Attempt counter for SMTP-transient retries via Toexecute. Cap at MAX_SMTP_RETRIES. */
    private int $retry_attempts = 0;
    private const MAX_SMTP_RETRIES = 3;
    /** Seconds between SMTP retry attempts. */
    private const SMTP_RETRY_DELAY = 300;

    /** Where this mail was created - see detectOrigin(). Defaults to '' so a
     *  task serialized by an older version still unserializes. */
    private string $origin = '';

    /** Frames scanned for the creating call site, and the files that are the
     *  mail machinery itself (full paths - apc-aa/mail.php, the {mail} contact
     *  form, shares its basename with this file). */
    private const ORIGIN_BACKTRACE_DEPTH = 6;
    private const ORIGIN_OWN_FILES = [__DIR__ . '/mail.php', __DIR__ . '/htmlMimeMail/htmlMimeMail.php'];
    /** Fits `log.selector` next to template + reader with room to spare. */
    private const ORIGIN_MAX = 40;

    public function __construct()
    {
        parent::__construct();
        $this->origin = self::detectOrigin();
    }

    /** Entry script plus the first call frame outside the mail classes, e.g.
     *  `index.php>notify.php:77`. Captured here, at construction, rather than
     *  at send time: Toexecute::later() serializes the whole AA_Mail object, so
     *  the origin travels through the queue with it - taken at send time, every
     *  queued mail would report only the cron run that flushed it.
     */
    private static function detectOrigin(): string
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? '')));
        $caller = '';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::ORIGIN_BACKTRACE_DEPTH) as $frame) {
            if (empty($frame['file']) || in_array($frame['file'], self::ORIGIN_OWN_FILES, true)) {
                continue;
            }
            $caller = basename($frame['file']) . ':' . ($frame['line'] ?? 0);
            break;
        }
        // `write_mail.php>write_mail.php:174` says nothing twice - name the entry
        // script only where it differs from the file the mail was created in.
        if (($script === '') || str_starts_with($caller, $script . ':')) {
            $origin = $caller;
        } elseif ($caller === '') {
            $origin = $script;
        } else {
            $origin = $script . '>' . $caller;
        }
        return mb_strcut($origin, 0, self::ORIGIN_MAX);
    }

    /** Composite id `{email.id}:{item id}` - the queue's selector column and
     *  get_class_name($obj, true) both build on it. Kept as-is for those; for
     *  the log use logSelector(), which keeps the two values apart.
     *  Used in AA_Log after toexecute*/
    function getId(): string {
        return $this->template_id.':'.$this->reader_id;
    }

    /** How this mail identifies itself in the log - picked up by AA_Log::selector(),
     *  so the MAIL_SEND row and the TOEXECUTE row of the same queued mail carry
     *  the identical selector. */
    public function logSelector(): AA_Logentry_Mailid {
        return new AA_Logentry_Mailid([
            'cls'      => get_class_name($this),
            'template' => (string)$this->template_id,
            'reader'   => (string)$this->reader_id,
            'origin'   => $this->origin,
        ]);
    }

    /** Log one MAIL_SEND row per attempt -- which transport ran and what it
     *  said back (on SMTP the server's own reply, queue id included). Takes the
     *  selector from AA_Log::selector(), the same call AA\Later\Toexecute makes,
     *  so a queued mail's MAIL_SEND and TOEXECUTE rows sit side by side. */
    private function logSend(int $sent, array $recipients): void
    {
        $transport = $this->getLastTransport();
        if ($transport === '') {
            return;   // send() bailed out before picking a transport - nothing was attempted
        }
        AA_Log::write('MAIL_SEND', AA_Log::selector($this), AA_Logentry_Ms::make(
            $transport,
            $sent,
            $this->getLastTransportResponse(),
            self::formatRecipients($recipients),
            $this->getSubject()
        ));
    }

    /** Recipients for one log row: the first address, plus how many more went
     *  with it (`jan@ecn.cz+2`). A send() is usually one address anyway -
     *  sendLater() splits a batch into one send() per recipient. */
    private static function formatRecipients(array $recipients): string
    {
        $first = (string) reset($recipients);
        $more  = count($recipients) - 1;
        return $more > 0 ? $first . '+' . $more : $first;
    }

    /**
     * Catch transient SMTP failures (4xx / network blip) and requeue via
     * Toexecute.
     */
    public function send($recipients): int
    {
        try {
            $sent = parent::send($recipients);
            $this->logSend($sent, (array) $recipients);
            return $sent;
        } catch (\AA\Mail\TransientSmtpException $e) {
            $this->logSend(0, (array) $recipients);
            $this->retry_attempts++;
            if ($this->retry_attempts > self::MAX_SMTP_RETRIES) {
                trigger_error(
                    'AA_Mail: giving up after '.self::MAX_SMTP_RETRIES.' SMTP retries: '.$e->getMessage(),
                    E_USER_WARNING
                );
                return 0;
            }
            $toexecute = new Toexecute;
            $selector  = 'AA_Mail_retry:'.$this->getId().':'.$this->retry_attempts;
            $toexecute->later($this, [$recipients], $selector, 90, time() + self::SMTP_RETRY_DELAY);
            return 0;
        }
    }

    /** setFromTemplate function
     *  Prepares the mail for sending
     *  The e-mail template is taken from a database, and all aliases
     *  in the template are expanded according tho $item
     * @param $mail_id
     * @param $item
     * @return bool
     */
    function setFromTemplate($mail_id, $item=null): bool
    {

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
    function setFromArray(array $record): bool {
        // do not send empty emails
        if (trim($record["body"]) === '') {
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
        $att_arr = match ($string[0]) {
            '['     => json2arr($string),
            '{'     => json2asoc($string),
            default => ParamExplode($string),
        };
        foreach ($att_arr as $name => $attachment) {
            if (!$attachment) {
                continue;
            }
            if ('' === ($att_data = AA_File_Wrapper::wrapper($attachment)->contents())) {
                continue;
            }
            if (ctype_digit((string)$name)) {
                $name = basename(parse_url($attachment, PHP_URL_PATH));
            }
            $attachments[] = $extended ? ['name' => $name, 'data' => $att_data, 'type' => get_headers($attachment, true)['Content-Type'] ?? ''] : ['name' => $name, 'data' => $att_data];
        }
        return $attachments;
    }

    /** Adds attachments to the e-mail
     *
     * @param string  $string  either colon separated list of files (https#://test.org/img1.png:https#://test.org/img2.png)
     *                       or json array of files (["https://test.org/img1.png","https://test.org/img2.png"])
     *                       or json object of files ({"filename1.png":"https://test.org/img1.png","filename2.png":"https://test.org/img2.png"})
     */
    private function addAttachmentFromString(string $string): void
    {
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
            if (!$all_later && ++$send_mails_total < 3) {
                $this->send([$to]);
                ++$sent;
            } else {
                $toexecute->later($this, [[$to]], 'AA_Mail'.$this->getId());
                // Yes, two nested arrays - mail->send() accepts array($to) and
                // all parameters to later must be in another only one array
                ++$sent;
            }
        }
        return $sent;
    }

    /** setBasicHeaders function
     *  This function fits a record from the @c email table.
     *
     * @param  array  $record
     * @param  array  $default
     */
    function setBasicHeaders(array $record= [], array $default= []): void
    {
        $headers = [
            "From"        => "header_from",
            "Reply-To"    => "reply_to",
            "Errors-To"   => "errors_to"
        ];
        foreach ( $headers as $header => $field) {
            if ($record[$field] ?? null) {
                $this->setHeader($header, $record[$field]);
            }
            elseif ($default[$field] ?? null) {
                $this->setHeader($header, $default[$field]);
            }
        }
        // bounces are going to errors_to (if defined) or ...
        $return_path = ($record['sender'] ?? null) ?: ( ($record['header_from'] ?? null) ?: ERROR_REPORTING_EMAIL );
        $extracted_return_path = $this->extractEmails($return_path);
        if (isset($extracted_return_path[0])) {
            $this->setReturnPath($extracted_return_path[0]);
        }
    }

    /* extractEmails() is inherited from htmlMimeMail -- the base SMTP send
     * path depends on it, so it lives there as the single canonical copy. */

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
    function setCharset($charset): void
    {
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
    public function toexecutelater($params= []): string
    {
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
     */
     static function sendTemplate($mail_id, $to, $item=null, $later=null): int
     {
        // email has the templates in it
        $mail = new AA_Mail;
        if (!$mail->setFromTemplate($mail_id, $item)) {
            return 0;
        }

         if (is_null($later)) {
             return $mail->sendLater($to);  // standard - first two mails send, other queue
         }

         if ($later === true) {
             return $mail->sendLater($to, true);
         }

         $tos  = array_unique(array_map('trim', is_array($to) ? $to : [$to]));
        return $mail->send($tos);  // send directly
    }

    /** Sends mail defined in e-mail template id $mail_id to all zids (Readers).
     *  Mail template is unaliased using aliases and data form item identified by
     *  $zids (often Reader item). The recipients are Readers itself, by default.
     * @param int    $mail_id
     * @param zids   $zids
     * @param string $slice_id - slice of the Readers - used for slice credentials
     * @return int number of ids to send mails
     */
    static function sendToReader($mail_id, zids $zids, $slice_id): int {
        $chunks = array_chunk($zids->longids(),50);  // plan 50 mails in one shot
        if (count($chunks)===1) {
            // less than 50 mails - schedule right now
            $mail_scheduler = new AA_Mail_Scheduler((int)$mail_id,$chunks[0], $slice_id);
            $mail_scheduler->schedule();  // right now
        } else {
            // more than 50 - plan scheduling
            // there were problems with more than 1000 mails - it wasn't possible to plan it in one shot
            $toexecute  = new Toexecute;
            foreach ($chunks as $chunk) {
                $mail_scheduler = new AA_Mail_Scheduler((int)$mail_id, $chunk, $slice_id);
                $toexecute->later($mail_scheduler, [], "AA_Mail_Scheduler:$mail_id", 105);      // a bit higher priority than mail sending
            }
        }
        return $zids->count();
    }
}

/** Helps split email generation into chunks - 50 mails each task
 *  there were problems with more than 1000 mails - it wasn't possible to plan it in one shot
 *  @see AA_Mail::sendToReader();
 */
class AA_Mail_Scheduler implements LaterInterface {
    private int $mail_id;

    /** @var array $reader_ids - array of reader ids (longId) */
    private array $reader_ids;

    /** @var string $slice_id slice of the Readers - used for slice credentials */
    private $slice_id;

    function __construct(int $mail_id, array $reader_ids, $slice_id) {
        $this->mail_id    = $mail_id;
        $this->reader_ids = $reader_ids;
        $this->slice_id   = $slice_id;
    }

    /** this will be written to AA_Log after toexecute. No other purpose */
    function getId(): int
    {
       return $this->mail_id;
    }

    function schedule(): string
    {
        // Allow using current slice data without slice_pwd
        AA_Credentials::singleton()->loadFromSlice($this->slice_id);

        $mail_count = 0;
        if ($this->mail_id && count($this->reader_ids)) {
            foreach ( $this->reader_ids as $id ) {
                $item = AA_Item::getItem(new zids($id, 'l'));
                $to   = $item->getValuesArray(FIELDID_EMAIL);
                $mail_count += AA_Mail::sendTemplate($this->mail_id, $to, $item, true);
            }
        }
        return (string)$mail_count;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []): string
    {
        return $this->schedule();
    }
}

/** Strips the HTML tags and a lot more to get a plain text mail version.
 *   Replaces URL links by the link in compound brackets behind the linked text.
 * @param $html string
 */
function html2text($html, $encoding='utf-8'): string
{

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
        if ( $matches[1] === $matches[2] ) {
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
        ($encoding==='utf-8') ? "'\\h+\\n'u" : "'\\h+\\n'",
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

    return preg_replace($search, $replace, $html);
}

function GetMailManaged(): Managed {
    $managed = AA::Metabase()->getManagerConf('email');
    $managed->setColumns2show(['id', 'type', 'subject', 'description']);
    $managed->setWidgetSetting('html', ['type' => 'bool', 'name' => _m('Use HTML')]);
    $managed->setWidgetSetting('type', ['enum' => AA_Collection::get_email_types(), 'name' => _m('Email type')]);
    $managed->setWidgetSetting('lang', ['enum' => Langs::getNames(), 'name' => _m('Language (charset)')]);
    // empty owner = global e-mail (not tied to a module); keep it optional so such
    // e-mails can be saved without being forced into a single module.
    $managed->setWidgetSetting('owner_module_id', ['enum' => ['' => _m('— all modules (global) —')] + AA_Module::getUserModules(), 'name' => _m('Owner'), 'required' => false]);
    $managed->setWidgetSetting('description', ['name' => _m('Description/Result')]);
    $managed->setWidgetSetting('subject', ['name' => _m('Subject')]);
    // Rich editor (CKEditor) only for token-free bodies; templates with {...} commands
    // keep the code editor so their raw markup is not reformatted on save.
    $managed->setWidgetSetting('body', ['name' => _m('Body'), 'widget' => static fn ($body) => str_contains((string) $body, '{') ? 'cod' : 'edt']);
    $managed->setWidgetSetting('header_from', ['name' => _m('From (email) ')]);
    $managed->setWidgetSetting('reply_to', ['name' => _m('Reply to (email)')]);
    $managed->setWidgetSetting('errors_to', ['name' => _m('Errors to (email)')]);
    $managed->setWidgetSetting('sender', ['name' => _m('Envelop sender (email)')]);
    $managed->setWidgetSetting('attachments', ['name' => _m('Attachments')]);
    return $managed;
}

