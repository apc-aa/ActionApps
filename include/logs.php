<?php

use AA\Expand\InternalExpandInterface;
use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;
use AA\Util\Txt;

/** Logging functions
 *
 *  @TODO Convert all loging into some class
 *        Enable setting of log level online
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


/*
Events logged into AA log
type            selector                        parameters
-------------------------------------------------------------------------------
BM_CREATE       bookmark id in profile table    name
BM_UPDATE       bookmark id in profile table    name
BM_RENAME       bookmark id in profile table    new_name:old_name
BM_DELETE       bookmark id in profile table    name
EMAIL_SENT      bookmark id/LIST/TEST           users:valid_emails:emails_sent
MAIL_SEND       AA_Mail:{templ}:{reader}:Mailid transport:sent:server_response   // one row per send() attempt
TOEXECUTE       {class}:{id}:Obj                return code                      // AA_Mail uses the Mailid form too
ALERTS          howoften                        Start/email sent
HITCOUNT        type
ITEM_DEL        item_id
M_FLDI_VLD      module_id:field_id              msg:url                        // insert form field validation
LOGIN           module_id:field_id              msg:url                        // insert form field validation
AA_WARN
*/

class AA_Log implements InternalExpandInterface
{
    public static function warn(array $params = []): void
    {
        self::write('AA_WARN', self::context(), array_merge($params, [self::backtrack(2)]));
    }

    /** AA_Log::write function - Write log entry
     *  Static function
     *
     * @param              $event
     * @param string|array|AA_Logentry $selector - if array, stored as separate
     *        entries (for ITEM_DEL, ...); if AA_Logentry, serialized reversed
     *        (see AA_Logentry_Qa / AA_Logentry_Obj)
     * @param string|array|AA_Logentry $params - if array, joined
     * @return bool
     */
    public static function write($event, $selector = "", $params = ""): bool
    {
        if (!self::isLogable($event)) {
            return false;
        }

        if (is_a($params, 'AA_Logentry')) {
            $params = $params->serializeToString();
        } elseif (is_array($params)) {
            $params = ParamImplode($params);
        }

        // a selector may also be an AA_Logentry - stored reversed (searchable value first, class flag last)
        if (is_a($selector, 'AA_Logentry')) {
            $selector = $selector->serializeToString(true);
        }

        $rows = [];
        if (is_array($selector)) {
            foreach ($selector as $sel) {
                $rows[] = ['time' => time(), 'user' => AuthGetUid(), 'type' => $event, 'selector' => $sel, 'params' => $params];
            }
        } else {
            $rows[] = ['time' => time(), 'user' => AuthGetUid(), 'type' => $event, 'selector' => $selector, 'params' => $params];
        }

        AA::Metabase()->doInsertMulti('log', $rows);

        return true;
    }

    /** The log selector for $object: its own entry when the class knows how to
     *  describe itself (AA_Mail splits its composite id into template + reader),
     *  otherwise the generic class:id one. Callers that log "some object did
     *  something" go through here so they cannot drift apart.
     *
     *  @param object|string $object the object the row is about, or a plain label
     */
    public static function selector($object, ?string $id = null): AA_Logentry
    {
        if (is_object($object) && method_exists($object, 'logSelector')) {
            return $object->logSelector();
        }
        return AA_Logentry_Obj::make($object, $id);
    }

    public static function writeIfChanged(string $event, string $selector, AA_Logentry $params): bool
    {
        if (($last = self::getLastLogEvents($event, $selector)) && $last['params'] === $params->serializeToString()) {
            return false;
        }
        return self::write($event, $selector, $params);
    }

    /** Is the event logable?
     */
    public static function isLogable($event_type): bool
    {
        /** By $DO_NOT_LOG array you are able to specify, which events you don't want
         *  to log - it's just like filter
         *  This should be list of all logable events (at least now - 2005-11-9)
         */
        $DO_NOT_LOG = [
            // 'ALERTS',
            // 'BM_CREATE',
            // 'BM_DELETE',
            // 'BM_RENAME',
            // 'BM_UPDATE',
            // 'CSN',
            // 'CSV_IMPORT',
            // 'EMAIL_SENT',
            // 'FEED2ALL_0',
            // 'FEED2ALL_1',
            // 'FEED_ADD',
            // 'FEED_DEL',
            // 'FEED_DSBLE',
            // 'FEED_ENBLE',
            // 'FILE IMP:',
            // 'ITEM_FIELD_FILLED',
            // 'PAGECACHE',
            // 'TOEXECUTE'
            // 'HITCOUNT'
            // 'AA_WARN'
            // 'ITEM_DEL'
            // 'ITEM_NEW'
            // 'ITEM_UPD'
            // 'MAIL_SEND'
            // 'M_FLDI_VLD'
            // LOGIN
            // PWD_REHASH
            // VIEW
            // SYS_STAT
            // PAGE_PARAM_ERR
        ];
        return !in_array($event_type, $DO_NOT_LOG);
    }

    public static function backtrack($remove_steps = 1): string
    {
        $ret = explode("\n", str_replace(AA_BASE_PATH, '', (new Exception())->getTraceAsString()));
        for (; $remove_steps > 0; --$remove_steps) {
            array_shift($ret);
        }
        return implode("\n", $ret);
    }

    /** Width of the `log.selector` column (see include/metabase_schema.php) -
     *  the reversed Qa selector must fit so its trailing class flag survives. */
    private const SELECTOR_MAX = 255;

    public static function context(?string $selector = null): string
    {
        // we need to be searchable by selector - so in DB index we use reversed format with Qa at the end
        return self::contextSelector(
            (string) $selector,
            (string) AA::$module_id,
            str_replace(':', '_', $_SERVER['REMOTE_ADDR']),
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '' : 'http/') . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
        );
    }

    /** Serialize the reversed AA_Logentry_Qa selector (ident:module:ip:uri:Qa).
     *  The class flag is appended last, so a long uri would overflow the
     *  varchar(255) `selector` column and strip the `:Qa` flag - which breaks
     *  factoryByStringReversed() on the Log page. Trim the uri (the only
     *  unbounded part) until the whole string, flag included, fits the column.
     */
    protected static function contextSelector(string $ident, string $module, string $ip, string $uri): string
    {
        $make = static fn(string $u): string => (new AA_Logentry_Qa(
            ['ident' => $ident, 'module' => $module, 'ip' => $ip, 'uri' => $u],
        ))->serializeToString(true);

        // measure the actual serialized length (robust to ParamImplode ':' escaping);
        // strlen in bytes <= MAX guarantees <= MAX chars too; mb_substr avoids cutting a multibyte char
        $entry = $make($uri);
        while (strlen($entry) > self::SELECTOR_MAX && $uri !== '') {
            $uri   = mb_substr($uri, 0, -max(1, strlen($entry) - self::SELECTOR_MAX));
            $entry = $make($uri);
        }
        return $entry;
    }

    /** @param string $oper searchbar operator for the selector condition. 'RLIKE'
     *        ("begins with") is what callers want when the selector is only a
     *        `{class}:{id}` prefix of the stored (AA_Logentry) form - it renders
     *        as `selector LIKE 'prefix%'`. 'LIKE' would be a substring match. */
    public static function getAdminUrl(string $event, string $selector = "", string $oper = '='): string
    {
        return get_admin_url("aa_log.php", ['srchbr_akce' => 1, 'srchbr_field[0]' => 'selector', 'srchbr_oper[0]' => $oper, "srchbr_value[0]=$selector", 'srchbr_field[1]' => 'type', 'srchbr_oper[1]' => '=', 'srchbr_value[1]' => $event, 'srchbr_order[0]' => 'time', 'srchbr_order_dir[0]' => 'on']);
    }

    /** Get events from log
     *
     * @param  string  $event  - type of event
     * @param  string  $selector
     *
     * @return array
     */
    public static function getLastLogEvents(string $event, string $selector = ""): array
    {
        $conds = [['type', $event, (str_contains($event, '%')) ? 'LIKE' : '=']];
        if ($selector !== "") {
            $conds[] = ['selector', $selector];
        }
        return DB_AA::select1('', 'SELECT * FROM log', $conds, ['id-']) ?: [];
    }

    /** Get first 20 rows from log table with possible link to Log page */
    public static function getLogTable(string $event, $selector = "", int $from = 0, int $to = 0, bool $show_log_link = false): string
    {
        $conds = [['type', $event, (str_contains($event, '%')) ? 'LIKE' : '=']];
        if ($selector != "") {
            $conds[] = ['selector', $selector];
        }
        if ($from) {
            $conds[] = ['time', $from, '>='];
        }
        if ($to) {
            $conds[] = ['time', $to, '<='];
        }
        $tab = DB_AA::select([], 'SELECT FROM_UNIXTIME(`time`) as `time` , `user`, `params` FROM log', $conds, ['id-'], null, 20);
        if (!$tab) {
            return '';
        }
        $tab       = self::extendTable($tab);
        $link_div  = $show_log_link ? '<div>' . a_href(self::getAdminUrl($event, $selector), _m('Log'), ['class' => 'aa-button-show']) . '</div>' : '';

        return GetHtmlTable($tab, 'key') . $link_div;
    }

    /** parse Params row and extract columns based on AA_Logentry structure */
    protected static function extendTable(array $tab): array
    {
        foreach ($tab as $k => $v) {
            $logEntry = AA_Logentry::factoryByString($v['params']);
            if ($logEntry) {
                $props = $logEntry::getClassProperties();
                if ($props) {
                    unset($tab[$k]['params']);
                    foreach ($props as $prop) {
                        // if column already exists, add _ to the name
                        $col = (array_key_exists($prop->id, $tab[$k]) ? '_' : '') . $prop->id;
                        $tab[$k][$col] = $logEntry->{$prop->id};
                    }
                }
            }
        }
        return $tab;
    }

    /** Render a log row's `params` and `selector` cells as inline AA_Logentry
     *  key-value tables, matching the display used by `admin/aa_log.php`.
     *  Cells that are not AA_Logentry-serialized are returned unchanged.
     */
    public static function renderLogentryCells(array $row): array
    {
        if (!empty($row['params']) && str_contains($row['params'], ':')
                && ($entry = AA_Logentry::factoryByString($row['params']))) {
            $row['params'] = $entry->getPrintRow();
        }
        if (!empty($row['selector']) && ($entry = AA_Logentry::factoryByStringReversed($row['selector']))) {
            $row['selector'] = $entry->getPrintRow();
        }
        return $row;
    }

    /** Internal aliases for Admin interface
     *  @usage {internal:AA_Log:entries:CSN:RSS#:22:3600} - display number of entries in log for CSN event with selector RSS:{feed_id} in last 3600 seconds
     */
    public static function internal_expand(?AA_Content $content, ...$params): string
    {
        $info = array_shift($params);
        switch ($info) {
            case 'count':  // number of entries for type / selector in last seconds
                [$type, $selector, $seconds] = $params;
                $seconds = (int) $seconds ?: 3600;
                return (!$type || !$selector) ? '' : (string) DB_AA::count('log', [['type', $type], ['selector', $selector], ['time', time() - $seconds, '>=']]);
            case 'print':  // print entries params based on logentry
                if ($content && ($logEntry = AA_Logentry::factoryByString($content->getValue('params')))) {
                    return $logEntry->getPrintRow(); // also adds CSS to header
                }
                return (string) $content->getValue('params');
            case 'context':  // special format of selector logentry - param is first
                if ($content && ($logEntry = AA_Logentry::factoryByStringReversed($content->getValue('selector')))) {
                    return $logEntry->getPrintRow(); // also adds CSS to header
                }
                return (string) $content->getValue('selector');
        }
        return '';
    }
}

///** set of properties */
//class AA_Propset {
//    use \AA\Util\ArrTrait;
//
//    protected $arr = [];
//    protected string $id  = '';  // must be unique in the system - as short as possible
//
//    public function __construct(string $id = '') {
//        $this->id = $id;
//    }
//
//    public function factoryFromArray(array $arr): static {
//        foreach ($arr as $k => $v) {
//            $this->arr[$k] = new AA_Property(...$v);
//        }
//        return $this;
//    }
//}

/** Uses AA_Serializable to create log entry. Constructor is taken from AA_Serializable */
abstract class AA_Logentry extends AA_Serializable
{
    public function getPrintRow(): string
    {
        $props = $this::getClassProperties();
        $arr = [];
        foreach ($props as $id => $prop) {
            $arr[$prop->name] = $this->$id;
        }
        return Txt::keyValTable($arr);
    }
}

/** Mail Template log entry - description */
class AA_Logentry_Mail2reader extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'time'    => new AA_Property('time', _m("Created time"), 'string'),
            'readers' => new AA_Property('readers', _m('Readers to be emailed'), 'int'),
        ];
    }
}

/** Mail Template log entry - type */
class AA_Logentry_MR extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'type'  => new AA_Property('type', _m('Type'), 'string'),  // type of mail template
            'page'  => new AA_Property('page', _m('Page'), 'int'),     // short item id of page, where {mail:... function is
        ];
    }
}

/** Planedtask Toexecute log entry
 */
class AA_Logentry_X extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'time'       => new AA_Property('time', _m('Time [s]'), 'string'),
            'mem_usage'  => new AA_Property('mem_usage', _m('Memory usage [MB]'), 'string'), // memory usage
            'mem_peak'   => new AA_Property('mem_peak', _m('Memory peak [MB]'), 'string'),  // memory peak
            'ret'        => new AA_Property('ret', _m('Return code'), 'string'),   // return code
            'params'     => new AA_Property('params', _m('Parameters'), 'string'),  // parameters
        ];
    }
}

/** Toexecute run summary log entry - one row per cron/queue run, written at the
 *  end of AA\Later\Toexecute::execute() / executeTask(). Replaces the old free
 *  text "finished {done}/{started} in {duration}/{allowed}".
 *  Serialized: Xrun:{done}:{started}:{duration}:{allowed}
 */
class AA_Logentry_Xrun extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'done'     => new AA_Property('done', _m('Tasks done'), 'int'),
            'started'  => new AA_Property('started', _m('Tasks started'), 'int'),
            'duration' => new AA_Property('duration', _m('Duration [s]'), 'string'),
            'allowed'  => new AA_Property('allowed', _m('Allowed [s]'), 'string'),
        ];
    }
}

/** Hitcounter entry
 *
 *  @WARNING positional contract — the order of these properties is part of an
 *  implicit data contract with src/Later/HitcounterUpdate.php. The serialized
 *  form is `H:cached:not_modified:time:packtime:mem_usage:uri` and that file
 *  extracts the packtime byte via `SUBSTRING_INDEX(info, ':', 5)`. Reordering
 *  will silently break archival of duration data. If you need to add a field,
 *  append it at the end of the array.
 *  @see \AA\Later\HitcounterUpdate::toexecutelater()
 */
class AA_Logentry_H extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'cached'       => new AA_Property('cached', _m('Cached'), 'bool'),
            'not_modified' => new AA_Property('not_modified', _m('Not Modified (304)'), 'bool'),
            'time'         => new AA_Property('time', _m('Time [s]'), 'string'),
            'packtime'     => new AA_Property('packtime', _m('Time + Cached + 304'), 'string'),
            'mem_usage'    => new AA_Property('mem_usage', _m('Memory usage [MB]'), 'string'),
            'uri'          => new AA_Property('uri', _m('Url'), 'string'),
        ];
    }
}

/** Hitcounter entry
 */
class AA_Logentry_W extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'time'         => new AA_Property('time', _m('Time [s]'), 'string'),
            'uri'          => new AA_Property('uri', _m('Url'), 'string'),
        ];
    }
}

/** System status log entry
 */
class AA_Logentry_Sys extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'aa'  => new AA_Property('aa', _m('AA'), 'string'),
            'svn' => new AA_Property('svn', _m('SVN AA (util.php)'), 'string'),
            'php' => new AA_Property('php', _m('PHP'), 'string'),
            'db'  => new AA_Property('db', _m('DB'), 'string'),
            'mem' => new AA_Property('mem', _m('Memory'), 'string'),
            'cpu' => new AA_Property('cpu', _m('CPU'), 'string'),
        ];
    }
}

/** Log-selector-context
 *  uses special format with class Qa at the end for better selector DB lookup
 *
 */
class AA_Logentry_Qa extends AA_Logentry
{
    // $entry = new AA_Logentry_Qa(['ident'=>$selector, 'module'=>AA::$module_id,'ip'=>str_replace(':','_',$_SERVER['REMOTE_ADDR']), 'url'=>($_SERVER['HTTPS'] ? '' : 'http/'). "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"]);

    public static function getClassProperties(): array
    {
        return [
            'ident'        => new AA_Property('ident', _m('Identifier'), 'string'),
            'module'       => new AA_Property('module', _m('Module ID'), 'string'),
            'ip'           => new AA_Property('ip', _m('IP Address'), 'string'),
            'uri'          => new AA_Property('uri', _m('Url'), 'string'),
        ];
    }
}

/** EMAIL_SENT log entry (admin/write_mail.php) */
class AA_Logentry_Em extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'mail_id' => new AA_Property('mail_id', _m('Template id'), 'string'),
            'users'   => new AA_Property('users', _m('Users'), 'int'),
            'sent'    => new AA_Property('sent', _m('Emails sent'), 'int'),
        ];
    }
}

/** Object-identifying log SELECTOR - the class that produced the row plus its
 *  getId(). Serialized reversed (`{class}:{id}:Obj`, like AA_Logentry_Qa) so the
 *  class stays the column prefix for `selector LIKE 'AA_Mail:%'` and the class
 *  flag survives as the last segment for factoryByStringReversed().
 */
class AA_Logentry_Obj extends AA_Logentry
{
    /** mirrors the `log.selector` column width in include/metabase_schema.php */
    private const SELECTOR_MAX = 255;

    /** NOT named `class`: parseClassProperties() keeps the class to instantiate
     *  under that very key, and a property of the same name would overwrite it
     *  in its array_merge - factoryByString() would then instantiate the logged
     *  class instead of this entry. */
    public string $cls = '';
    public string $id  = '';

    /** @param object|string $object the object the row is about, or a plain label */
    public static function make($object, ?string $id = null): self
    {
        $entry = new self([
            'cls' => is_object($object) ? get_class_name($object) : (string) $object,
            'id'  => $id ?? (is_object($object) && method_exists($object, 'getId') ? (string) $object->getId() : ''),
        ]);
        // `id` is the only unbounded part (the no-object branch passes a whole
        // serialized task) - trim it so the trailing `:Obj` flag always fits.
        while ((($over = strlen($entry->serializeToString(true)) - self::SELECTOR_MAX) > 0) && ($entry->id !== '')) {
            $entry->id = mb_substr($entry->id, 0, -$over);
        }
        return $entry;
    }

    public static function getClassProperties(): array
    {
        return [
            'cls' => new AA_Property('cls', _m('Class'), 'string'),
            'id'  => new AA_Property('id', _m('Object id'), 'string'),
        ];
    }
}

/** Mail-identifying log SELECTOR - the decomposed AA_Mail::getId().
 *
 *  That id is a composite: `{email.id}:{item id}` - the mail template row and
 *  the item the template was unaliased against (a reader item on the
 *  sendToReader()/alerts paths, the triggering item on a few event paths;
 *  empty when the mail went to a bare address). Logged as one opaque string it
 *  told the reader nothing and needed ParamImplode to escape its inner colon;
 *  as two properties each value is its own segment, so `AA_Mail:2:c24ef6…:Mailid`
 *  stays escape-free and the Log page can label the parts.
 */
class AA_Logentry_Mailid extends AA_Logentry
{
    public string $cls      = '';
    public string $template = '';
    public string $reader   = '';

    public static function getClassProperties(): array
    {
        return [
            'cls'      => new AA_Property('cls', _m('Class'), 'string'),
            'template' => new AA_Property('template', _m('Mail template'), 'int'),    // email.id
            'reader'   => new AA_Property('reader', _m('Reader item'), 'string'),     // item.id, may be empty
        ];
    }
}

/** MAIL_SEND log entry -- one delivery attempt of one mail (include/mail.php).
 *  Sits under the same selector as the TOEXECUTE row the queue writes for the
 *  same mail, so both line up in the Log page filter. */
class AA_Logentry_Ms extends AA_Logentry
{
    /** log.params is varbinary(128) and the server runs STRICT_TRANS_TABLES, so
     *  an over-long server reply would fail the INSERT (and thus the send). */
    private const PARAMS_MAX = 128;

    /** Declared (unlike the older sibling entries) so setProperties() does not
     *  create them dynamically - deprecated since PHP 8.2. All string, because
     *  setProperties() also fills them from a parsed log row on the Log page,
     *  where a malformed `sent` would be a TypeError against an int property. */
    public string $transport = '';
    public string $sent      = '';
    public string $resp      = '';

    /** Build the entry with `resp` -- the only unbounded property -- trimmed to fit. */
    public static function make(string $transport, int $sent, string $resp): self
    {
        $entry = new self(['transport' => $transport, 'sent' => $sent, 'resp' => $resp]);
        while ((($over = strlen($entry->serializeToString()) - self::PARAMS_MAX) > 0) && ($entry->resp !== '')) {
            $entry->resp = mb_substr($entry->resp, 0, -$over);   // mb_ so a trim never splits a multibyte char
        }
        return $entry;
    }

    public static function getClassProperties(): array
    {
        return [
            'transport' => new AA_Property('transport', _m('Transport'), 'string'),   // 'mail' or 'smtp'
            'sent'      => new AA_Property('sent', _m('Recipients sent'), 'int'),
            'resp'      => new AA_Property('resp', _m('Server response'), 'string'),
        ];
    }
}

/** ITEM_FIELD_FILLED log entry (admin/search_replace.php) */
class AA_Logentry_Iff extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'selected' => new AA_Property('selected', _m('Items selected'), 'int'),
            'updated'  => new AA_Property('updated', _m('Items updated'), 'int'),
            'planned'  => new AA_Property('planned', _m('Items planned'), 'int'),
        ];
    }
}

/** M_FLDI_VLD log entry - insert-form field validation (include/item_content.php) */
class AA_Logentry_Mfv extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'msg'     => new AA_Property('msg', _m('Message'), 'string'),
            'uri'     => new AA_Property('uri', _m('Url'), 'string'),
            'referer' => new AA_Property('referer', _m('Referer'), 'string'),
        ];
    }
}

/** BM_RENAME log entry - bookmark rename (include/searchbar.class.php) */
class AA_Logentry_Bmr extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'newname' => new AA_Property('newname', _m('New name'), 'string'),
            'oldname' => new AA_Property('oldname', _m('Old name'), 'string'),
        ];
    }
}

/** ALERTS log entry - alert mailing progress (modules/alerts/*) */
class AA_Logentry_Alerts extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'phase'      => new AA_Property('phase', _m('Phase'), 'string'),       // start | sent | users | queued | emails | debug
            'collection' => new AA_Property('collection', _m('Collection'), 'string'),
            'count'      => new AA_Property('count', _m('Count'), 'string'),
            'detail'     => new AA_Property('detail', _m('Detail'), 'string'),
        ];
    }
}

/** CSN log entry - content syndication feed status (src/IO/Grabber/AARSS.php) */
class AA_Logentry_Csn extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'feed'   => new AA_Property('feed', _m('Feed'), 'string'),
            'status' => new AA_Property('status', _m('Status'), 'string'),
            'detail' => new AA_Property('detail', _m('Detail'), 'string'),
        ];
    }
}

/** IMPORT log entry - module-chunk import (central/responder.php) */
class AA_Logentry_Import extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'phase'  => new AA_Property('phase', _m('Phase'), 'string'),    // start | result
            'detail' => new AA_Property('detail', _m('Detail'), 'string'),
        ];
    }
}

/** CSV_IMPORT summary log entry (admin/se_csv_import2.php) */
class AA_Logentry_Csv extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'slice'     => new AA_Property('slice', _m('Slice'), 'string'),
            'processed' => new AA_Property('processed', _m('Processed'), 'int'),
            'stored'    => new AA_Property('stored', _m('Stored'), 'int'),
            'error'     => new AA_Property('error', _m('Errors'), 'int'),
        ];
    }
}

/** File-operation outcome log entry - shared by CSV_IMPORT / FILE IMP file deletes
 *  (admin/se_csv_import2.php, include/files.class.php)
 */
class AA_Logentry_File extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'outcome' => new AA_Property('outcome', _m('Outcome'), 'string'),   // deleted | delete failed | invalid directory
            'file'    => new AA_Property('file', _m('File'), 'string'),
        ];
    }
}

/** ITEM_NEW / ITEM_UPD params log entry - item store (include/item_content.php).
 *  Short tag `Iu` (item insert/update) - the postfix is stored in every row.
 *  The insert vs update distinction is in the event type; this holds the detail.
 */
class AA_Logentry_Iu extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'mode'    => new AA_Property('mode', _m('Mode'), 'string'),    // insert | update | update_filled | ...
            'flags'   => new AA_Property('flags', _m('Flags'), 'string'),
            'context' => new AA_Property('context', _m('Context'), 'string'),
        ];
    }
}

/** FEED_ENBLE / FEED_DSBLE / FEED_ADD / FEED_DEL selector - feeding permission
 *  change (admin/se_import2.php). Short tag `Fd` (feed). Reversed selector
 *  (module first for prefix lookup): module:target:Fd - direction/action is in
 *  the event type.
 */
class AA_Logentry_Fd extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'module' => new AA_Property('module', _m('Module ID'), 'string'),
            'target' => new AA_Property('target', _m('Feed counterpart'), 'string'),
        ];
    }
}

/** M_FLDI_VLD selector - insert-form field validation (include/item_content.php).
 *  Short tag `Mf` (module/field); pairs with the params entry AA_Logentry_Mfv
 *  (message/uri/referer). Reversed selector (module first): module:field:Mf.
 */
class AA_Logentry_Mf extends AA_Logentry
{
    public static function getClassProperties(): array
    {
        return [
            'module' => new AA_Property('module', _m('Module ID'), 'string'),
            'field'  => new AA_Property('field', _m('Field ID'), 'string'),
        ];
    }
}
