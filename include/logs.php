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
type is at most AA_Log::TYPE_MAX bytes - a longer name is stored cut, so
getLogTable() with the full name never matches it

type            selector                        parameters
-------------------------------------------------------------------------------
BM_CREATE       bookmark id in profile table    name
BM_UPDATE       bookmark id in profile table    name
BM_RENAME       bookmark id in profile table    new_name:old_name
BM_DELETE       bookmark id in profile table    name
EMAIL_SENT      bookmark id/LIST/TEST           users:valid_emails:emails_sent
MAIL_SEND       AA_Mail:{tmpl}:{rdr}:{origin}:Mailid  transport:sent:server_response:recipients:subject  // one row per send() attempt
TOEXECUTE       {class}:{id}:Obj                time:mem_usage:mem_peak:return_code:task_arguments   // AA_Mail uses the Mailid form too
ALERTS          howoften                        Start/email sent
HITCOUNT        type
ITEM_DEL        item_id
M_FLDI_VLD      module_id:field_id              msg:url                        // insert form field validation
LOGIN           module_id:field_id              msg:url                        // insert form field validation
STATE_CHK       {CheckClass} | summary          Sc:severity:count:title | Scs:errors:warnings:infos:checks   // daily State Check snapshot (AA\Later\StateCheck)
AA_WARN
*/

class AA_Log implements InternalExpandInterface
{
    /** width of `log.type` (include/metabase_schema.php); Metabase cuts longer names silently */
    public const TYPE_MAX = 10;

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

        // CRON_FALLBACK_ERR, PAGE_PARAM_ERR and ITEM_FIELD_FILLED were logged for months
        // under cut names nobody searched for; make the next such slip visible in the log
        if (strlen($event) > self::TYPE_MAX) {
            self::warn(['msg' => "log type '$event' exceeds " . self::TYPE_MAX . ' bytes and is stored cut']);
            $event = substr($event, 0, self::TYPE_MAX);
        }

        if ($params instanceof AA_Logentry) {
            $params = $params->serializeToString();
        } elseif (is_array($params)) {
            $params = ParamImplode($params);
        }

        // a selector may also be an AA_Logentry - stored reversed (searchable value first, class flag last)
        if ($selector instanceof AA_Logentry) {
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
            // 'ITEM_FIELD',
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
            // PAGE_PARAM
            // CRON_FALLB
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
        $entry = new AA_Logentry_Qa(['ident' => $ident, 'module' => $module, 'ip' => $ip, 'uri' => $uri]);
        // the uri is the only unbounded part - it gives up its tail so the
        // trailing `:Qa` flag survives
        $entry->trimToFit(['uri'], self::SELECTOR_MAX, true);
        return $entry->serializeToString(true);
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

    /** Get first 20 rows from log table with possible link to Log page
     * @param bool $mark_changes  highlight each value that differs from the entry before it
     */
    public static function getLogTable(string $event, $selector = "", int $from = 0, int $to = 0, bool $show_log_link = false, bool $mark_changes = false): string
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
        if ($mark_changes) {
            $tab = self::markChanges($tab);
        }
        $link_div  = $show_log_link ? '<div>' . a_href(self::getAdminUrl($event, $selector), _m('Log'), ['class' => 'aa-button-show']) . '</div>' : '';

        // 'all' escapes both the header and the cells. extendTable() puts raw
        // AA_Logentry property values in them, and some of those are whatever the
        // visitor sent: a VIEW row carries $_SERVER['REQUEST_URI'] verbatim
        // (include/view.php), and admin/se_view.php renders it through here.
        return GetHtmlTable($tab, 'key', '', 'all') . $link_div;
    }

    /**
     * Rows are newest first, so each row is compared with the one below it, the
     * entry before. A value that differs becomes a highlighted cell; the oldest row
     * has nothing to differ from. When and who are not the entry's content.
     */
    public static function markChanges(array $tab): array
    {
        $rows = array_values($tab);
        $out  = $rows;
        foreach ($rows as $k => $row) {
            $older = $rows[$k + 1] ?? null;
            if ($older === null) {
                break;
            }
            foreach ($row as $col => $value) {
                if (!in_array($col, ['time', 'user'], true) && (string) ($older[$col] ?? '') !== (string) $value) {
                    // unquoted on purpose: GetHtmlTable()'s escaping branch passes the attribute through safe()
                    $out[$k][$col] = ['attr' => 'class=aa-log-changed', 'text' => $value];
                }
            }
        }
        return $out;
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
    /** log.params is varbinary(128) and the server runs STRICT_TRANS_TABLES, so
     *  an over-long row fails the INSERT instead of being truncated - and those
     *  INSERTs sit on the mail-sending and queue-run paths. */
    protected const PARAMS_MAX = 128;

    public function getPrintRow(): string
    {
        $props = $this::getClassProperties();
        $arr = [];
        foreach ($props as $id => $prop) {
            $arr[$prop->name] = $this->$id;
        }
        return Txt::keyValTable($arr);
    }

    /** Shorten the named properties, in the given order, until the serialized
     *  form fits $max bytes - PARAMS_MAX for a `params` row, the log.selector
     *  width for a selector. Each property gives up its tail completely before
     *  the next one is touched, so the order says which value is sacrificed
     *  first. mb_strcut cuts on a byte count (that is the budget) without
     *  splitting a multibyte character.
     *
     *  Measuring the serialized form rather than the raw values is what makes
     *  this correct: ParamImplode escapes every ':' to '#:', so a colon-heavy
     *  value costs more than its own length. Public because AA_Log builds the
     *  Qa context selector without being an AA_Logentry itself.
     *
     * @param string[] $properties property names, in the order they give up their tail
     * @param bool     $reversed   selector serialization (values first, class flag
     *                             last) - the flag has to survive the trim, or
     *                             factoryByStringReversed() cannot read the row back
     */
    public function trimToFit(array $properties, int $max, bool $reversed = false): void
    {
        foreach ($properties as $property) {
            while ((($over = strlen($this->serializeToString($reversed)) - $max) > 0) && ($this->$property !== '')) {
                $this->$property = mb_strcut($this->$property, 0, max(0, strlen($this->$property) - $over));
            }
        }
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
    /** Ceilings in bytes for the two properties a task can blow up, and the
     *  order in which they give up their tail if the row still does not fit. */
    private const VALUE_MAX  = ['ret' => 40, 'params' => 60];
    private const TRIM_ORDER = ['params', 'ret'];

    /** How much of a task's argument list flatten() spells out. */
    private const FLATTEN_ITEMS = 4;
    private const FLATTEN_DEPTH = 3;

    public string $time      = '';
    public string $mem_usage = '';
    public string $mem_peak  = '';
    public string $ret       = '';
    public string $params    = '';

    /** Build the row from the task's *unserialized* arguments. Storing
     *  `serialize()`'s output verbatim (as this did until 2026-09) put
     *  `a:1:{i:0;a:1:{i:0;s:28:"someone@example.org";}}` in the Log page where
     *  `someone@example.org` was the whole content - and blew the INSERT, which
     *  sits inside the queue run, whenever a task carried bigger arguments.
     *
     * @param mixed $params the task's arguments as passed to execute_one()
     */
    public static function make(string $time, string $mem_usage, string $mem_peak, string $ret, $params): self
    {
        $entry = new self([
            'time'      => $time,
            'mem_usage' => $mem_usage,
            'mem_peak'  => $mem_peak,
            'ret'       => mb_strcut($ret, 0, self::VALUE_MAX['ret']),
            'params'    => mb_strcut(self::flatten($params), 0, self::VALUE_MAX['params']),
        ]);
        $entry->trimToFit(self::TRIM_ORDER, self::PARAMS_MAX);
        return $entry;
    }

    /** A task's arguments as a short readable list: `a,b`, `k=v`, `{AA_Mail}`.
     *  Bounded in both width and depth - this runs on every queue task, and the
     *  arguments are arbitrary (an AA_Mail recipient list, a whole item id set).
     *
     * @param mixed $value
     */
    private static function flatten($value, int $depth = self::FLATTEN_DEPTH): string
    {
        if (is_array($value)) {
            if ($depth < 1) {
                return '[...]';
            }
            $parts = [];
            foreach (array_slice($value, 0, self::FLATTEN_ITEMS, true) as $key => $item) {
                $parts[] = (is_string($key) ? $key . '=' : '') . self::flatten($item, $depth - 1);
            }
            return implode(',', $parts) . (count($value) > self::FLATTEN_ITEMS ? ',...' : '');
        }
        if (is_object($value)) {
            return '{' . get_class_name($value) . '}';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string) $value : '';   // null, resource, ...
    }

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
    public string $aa  = '';
    public string $svn = '';
    public string $php = '';
    public string $db  = '';
    public string $mem = '';
    public string $cpu = '';

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

/** State Check snapshot - one row per check with a non-ok finding, written
 *  by AA\Later\StateCheck only when it changed. Serialized: Sc:severity:count:title
 */
class AA_Logentry_Sc extends AA_Logentry
{
    public string $severity = '';
    public string $count = '';
    public string $title = '';

    public static function make(string $severity, int $count, string $title): self
    {
        $entry = new self(['severity' => $severity, 'count' => (string) $count, 'title' => $title]);
        $entry->trimToFit(['title'], self::PARAMS_MAX);
        return $entry;
    }

    public static function getClassProperties(): array
    {
        return [
            'severity' => new AA_Property('severity', _m('Severity'), 'string'),
            'count'    => new AA_Property('count', _m('Count'), 'int'),
            'title'    => new AA_Property('title', _m('Finding'), 'string'),
        ];
    }
}

/** State Check snapshot summary - one row per run (selector "summary"), so the
 *  time of the last full run is known. Serialized: Scs:errors:warnings:infos:checks
 */
class AA_Logentry_Scs extends AA_Logentry
{
    public string $errors = '';
    public string $warnings = '';
    public string $infos = '';
    public string $checks = '';

    public static function getClassProperties(): array
    {
        return [
            'errors'   => new AA_Property('errors', _m('Problems'), 'int'),
            'warnings' => new AA_Property('warnings', _m('Warnings'), 'int'),
            'infos'    => new AA_Property('infos', _m('Recommendations'), 'int'),
            'checks'   => new AA_Property('checks', _m('Checks'), 'int'),
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

    /** Declared so trimToFit() has a real property to shorten (and so
     *  setProperties() stops creating them dynamically - deprecated since PHP 8.2). */
    public string $ident  = '';
    public string $module = '';
    public string $ip     = '';
    public string $uri    = '';

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
        $entry->trimToFit(['id'], self::SELECTOR_MAX, true);
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
 *  as two properties each value is its own segment, so
 *  `AA_Mail:2:c24ef6…::Mailid` stays escape-free and the Log page can label the
 *  parts.
 *
 *  `origin` is appended after them: in practice most mails carry no template at
 *  all (notify.php and the {sendmail} expansion build their message with
 *  setFromArray()/setSubject() and never create an `email` row), so the code
 *  site the mail was born at is what actually identifies it.
 */
class AA_Logentry_Mailid extends AA_Logentry
{
    public string $cls      = '';
    public string $template = '';
    public string $reader   = '';
    public string $origin   = '';

    public static function getClassProperties(): array
    {
        return [
            'cls'      => new AA_Property('cls', _m('Class'), 'string'),
            'template' => new AA_Property('template', _m('Mail template'), 'int'),    // email.id
            'reader'   => new AA_Property('reader', _m('Reader item'), 'string'),     // item.id, may be empty
            'origin'   => new AA_Property('origin', _m('Origin'), 'string'),          // AA_Mail::detectOrigin(), appended last so older rows still parse
        ];
    }

    /** Same key-value table as the parent, except the template id links to the
     *  template it names. Both this page and admin/aa_emails.php are
     *  superadmin-only, so the link needs no extra permission check. */
    public function getPrintRow(): string
    {
        $arr = [];
        foreach (static::getClassProperties() as $id => $prop) {
            $value = trim($this->$id);
            $arr[safe($prop->name)] = ($id === 'template' && $value !== '')
                ? '<a href="' . safe(AA_INSTAL_PATH . 'admin/aa_emails.php?id=' . $value) . '">' . safe($value) . '</a>'
                : safe($value);
        }
        return Txt::keyValTable($arr, true);   // html - the values are escaped above
    }
}

/** MAIL_SEND log entry -- one delivery attempt of one mail (include/mail.php).
 *  Sits under the same selector as the TOEXECUTE row the queue writes for the
 *  same mail, so both line up in the Log page filter. */
class AA_Logentry_Ms extends AA_Logentry
{
    /** Ceilings for the three unbounded properties, in bytes. Their sum plus the
     *  fixed part is over PARAMS_MAX, so a maxed-out row still needs TRIM_ORDER -
     *  but no single long value can starve the other two. `resp` gets the widest
     *  one because a whole SMTP reply is what makes the mail findable in the MTA
     *  log: `250 2.0.0 67VCcxIC4029086 Message accepted for delivery` is 54 B,
     *  and the queue id in the middle of it is worthless if the tail is cut. */
    private const VALUE_MAX = ['resp' => 64, 'to' => 36, 'subj' => 44];

    /** Which property gives up its tail first when the row still does not fit.
     *  `subj` leads: a shortened subject still identifies the mail, and where a
     *  template exists the Log page links to its full text anyway. `to` is next.
     *  `resp` yields last, so a long subject can never cost us the server's
     *  answer - the one value nothing else in the row can substitute for. */
    private const TRIM_ORDER = ['subj', 'to', 'resp'];

    /** Declared (unlike the older sibling entries) so setProperties() does not
     *  create them dynamically - deprecated since PHP 8.2. All string, because
     *  setProperties() also fills them from a parsed log row on the Log page,
     *  where a malformed `sent` would be a TypeError against an int property. */
    public string $transport = '';
    public string $sent      = '';
    public string $resp      = '';
    public string $to        = '';
    public string $subj      = '';

    /** Build the entry, capped per property and then trimmed to fit PARAMS_MAX.
     *  `to` and `subj` are appended after `resp` (not inserted next to the other
     *  identifying values) because the serialization is positional and unnamed -
     *  putting them earlier would make every pre-existing row decode its server
     *  response as a recipient. */
    public static function make(string $transport, int $sent, string $resp, string $to = '', string $subj = ''): self
    {
        $entry = new self([
            'transport' => $transport,
            'sent'      => $sent,
            'resp'      => mb_strcut($resp, 0, self::VALUE_MAX['resp']),
            'to'        => mb_strcut($to, 0, self::VALUE_MAX['to']),
            'subj'      => mb_strcut($subj, 0, self::VALUE_MAX['subj']),
        ]);
        $entry->trimToFit(self::TRIM_ORDER, self::PARAMS_MAX);
        return $entry;
    }

    public static function getClassProperties(): array
    {
        return [
            'transport' => new AA_Property('transport', _m('Transport'), 'string'),   // 'mail' or 'smtp'
            'sent'      => new AA_Property('sent', _m('Recipients sent'), 'int'),
            'resp'      => new AA_Property('resp', _m('Server response'), 'string'),
            'to'        => new AA_Property('to', _m('Recipients'), 'string'),         // first address + how many more
            'subj'      => new AA_Property('subj', _m('Subject'), 'string'),          // appended last - see make()
        ];
    }
}

/** ITEM_FIELD log entry (admin/search_replace.php) */
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
    /** Only `detail` gets a ceiling: it is a raw excerpt of whatever a remote
     *  server sent and has no natural size at all, while `feed` is a name and
     *  `status` a short literal from this file's own call sites. Capping the
     *  other two as well would shorten rows that fit perfectly today - the real
     *  CSN log runs to 124 bytes at its longest, all of it feed name.
     *
     *  `feed` yields its tail first because the CSN selector already carries
     *  `RSS:{feed_id}`, so the row stays attributable without it; `detail` is
     *  the only diagnostic and `status` is never trimmed. With both maxed out
     *  each still keeps a usable head. */
    private const VALUE_MAX  = ['detail' => 48];
    private const TRIM_ORDER = ['feed', 'detail'];

    public string $feed   = '';
    public string $status = '';
    public string $detail = '';

    /** `detail` is whatever the remote end sent back where XML was expected -
     *  short in practice ("Not active"), but nothing stops it being a whole
     *  HTML error page. Until 2026-09 it went into the 128-byte `log.params`
     *  column raw, and under STRICT_TRANS_TABLES an over-long value fails the
     *  INSERT rather than truncating: the feed run would have died on the log
     *  write instead of recording the feed error it was called to record.
     *  Latent, not observed - the real CSN log (199k rows on geoinformace)
     *  peaks at 124 bytes, none of it detail. Whitespace is collapsed because
     *  the excerpt shares one table cell with the rest of the row.
     */
    public static function make(string $feed, string $status, string $detail = ''): self
    {
        $entry = new self([
            'feed'   => $feed,
            'status' => $status,
            'detail' => mb_strcut(trim((string) preg_replace('/\s+/', ' ', $detail)), 0, self::VALUE_MAX['detail']),
        ]);
        $entry->trimToFit(self::TRIM_ORDER, self::PARAMS_MAX);
        return $entry;
    }

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
