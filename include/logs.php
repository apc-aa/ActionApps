<?php

use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;

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
TOEXECUTE       object's class                  return code
ALERTS          howoften                        Start/email sent
HITCOUNT        type
ITEM_DEL        item_id
M_FLDI_VLD      module_id:field_id              msg:url                        // insert form field validation
LOGIN           module_id:field_id              msg:url                        // insert form field validation
AA_WARN
*/

class AA_Log implements \AA\Expand\InternalExpandInterface {

    static function warn(array $params = []) {
        self::write('AA_WARN', self::context(), array_merge($params, [self::backtrack(2)]));
    }

    /** AA_Log::write function - Write log entry
     *  Static function
     *
     * @param              $event
     * @param string|array $selector - if array, stored as separate entries (for ITEM_DEL, ...)
     * @param string|array|AA_Logentry $params - if array, joined
     * @return bool
     */
    static function write($event, $selector = "", $params = "") {
        if ( !self::isLogable($event) ) {
            return false;
        }

        if (is_a($params, 'AA_Logentry')) {
            $params = $params->serializeToString();
        } elseif (is_array($params)) {
            $params = ParamImplode($params);
        }

        $rows = [];
        if (is_array($selector)) {
            foreach ($selector as $sel) {
                $rows[] = ['time'=>time(), 'user'=>AuthGetUid(), 'type'=>$event, 'selector'=>$sel, 'params'=>$params];
            }
        } else {
            $rows[] = ['time'=>time(), 'user'=>AuthGetUid(), 'type'=>$event, 'selector'=>$selector, 'params'=>$params];
        }

        AA::Metabase()->doInsertMulti('log', $rows);

        return true;
    }

    /** Is the event logable?
     **/
    static function isLogable($event_type): bool
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
           // 'M_FLDI_VLD'
           // LOGIN
           // PWD_REHASH
           // VIEW
        ];
        return !in_array($event_type, $DO_NOT_LOG);
    }

    static function backtrack($remove_steps=1) {
        $ret = explode("\n", str_replace(AA_BASE_PATH, '', (new Exception() )->getTraceAsString()));
        for (; $remove_steps>0; --$remove_steps) {
            array_shift($ret);
        }
        return implode("\n", $ret);
    }

    static function context(?string $selector=null): string
    {
        // There was bug - no return - so the last line was used always. We left it this was right now (maybe it is better for compatibility, but could be changed - HM 2024-10-03)
        // if (is_null($selector)) {
        //     ParamImplode([AA::$module_id,'',str_replace(':','_',$_SERVER['REMOTE_ADDR']), ($_SERVER['HTTPS'] ? '' : 'http/'). "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"]);
        // }

        // we need to be searchable by selector - so in DB index
        return ParamImplode([$selector, AA::$module_id,str_replace(':','_',$_SERVER['REMOTE_ADDR']), ($_SERVER['HTTPS'] ? '' : 'http/'). "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"]);
    }

    public static function getAdminUrl(string $event, string $selector = ""): string {
        return get_admin_url("aa_log.php", ['srchbr_akce' => 1, 'srchbr_field[0]' => 'selector', 'srchbr_oper[0]' => '=', "srchbr_value[0]=$selector", 'srchbr_field[1]' => 'type', 'srchbr_oper[1]' => '=', 'srchbr_value[1]' => $event, 'srchbr_order[0]' => 'time', 'srchbr_order_dir[0]' => 'on']);
    }

    /** Get events from log
     * @param        $event - type of event
     * @param string $selector
     * @return array
     */
    public static function getLastLogEvents($event, $selector = "") {
        $conds = [['type', $event, (!str_contains($event, '%')) ? '=' : 'LIKE']];
        if ($selector != "") {
            $conds[] = ['selector', $selector];
        }
        return DB_AA::select1('', 'SELECT * FROM log', $conds, ['id-']);
    }

    /** Get first 20 rows from log table with possible link to Log page */
    public static function getLogTable($event, $selector = "", bool $show_log_link = false): string {
        $conds = [['type', $event, (!str_contains($event, '%')) ? '=' : 'LIKE']];
        if ($selector != "") {
            $conds[] = ['selector', $selector];
        }
        $tab = DB_AA::select([], 'SELECT * FROM log', $conds, ['id-'], null, 20);
        if (!$tab) {
            return '';
        }
        $tab       = self::extendTable($tab);
        $link_div  = $show_log_link ? '<div>'. a_href(self::getAdminUrl($event, $selector), _m('Log'), ['class' => 'aa-button-show']) . '</div>' : '';

        return GetHtmlTable($tab, 'key'). $link_div;
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

    /** Internal aliases for Admin interface
     *  @usage {internal:AA_Log:RSS#:21:3600}
     */
    static function internal_expand(?AA_Content $content, ...$params): string {
        [$type,$selector,$seconds] = $params;
        $seconds = (int)$seconds ?: 3600;

        return (!$type OR !$selector) ? '' : (string)DB_AA::select1('cnt', "SELECT count(*) as cnt FROM `log`", [['type',$type], ['selector',$selector], ['time',time()-$seconds, '>=']]);
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
//    public function factoryFromArray(array $arr): AA_Propset {
//        foreach ($arr as $k => $v) {
//            $this->arr[$k] = new AA_Property(...$v);
//        }
//        return $this;
//    }
//}

/** Uses AA_Serializable to create log entry. Constructor is taken from AA_Serializable */
abstract class AA_Logentry extends AA_Serializable {}

/** Mail Template log entry - description */
class AA_Logentry_Mail2reader extends AA_Logentry {
    public static function getClassProperties(): array {
        return [
            'time'    => new AA_Property('time',    _m("Created time"), 'string'),
            'readers' => new AA_Property('readers', _m('Readers to be emailed'), 'int'),
        ];
    }
}

/** Mail Template log entry - type */
class AA_Logentry_MR extends AA_Logentry {
    public static function getClassProperties(): array {
        return [
            'type'  => new AA_Property('type',_m('Type'), 'string'),  // type of mail template
            'page'  => new AA_Property('page',_m('Page'), 'int'),     // short item id of page, where {mail:... function is
        ];
    }
}

/** Planedtask Toexecute log entry
 */
class AA_Logentry_X extends AA_Logentry {
     public static function getClassProperties(): array {
         return [
         'time'       => new AA_Property('time',       _m('Time [s]'), 'string'),
         'mem_usage'  => new AA_Property('mem_usage',  _m('Memory usage [MB]'), 'string'), // memory usage
         'mem_peak'   => new AA_Property('mem_peak',   _m('Memory peak [MB]'), 'string'),  // memory peak
         'ret'        => new AA_Property('ret',        _m('Return code'), 'string'),   // return code
         'params'     => new AA_Property('params',     _m('Parameters'), 'string'),  // parameters
         ];
     }
}

/** Hitcounter entry
 */
class AA_Logentry_H extends AA_Logentry {
    public static function getClassProperties(): array {
        return [
            'cached'       => new AA_Property('cached',       _m('Cached'), 'bool'),
            'not_modified' => new AA_Property('not_modified', _m('Not Modified (304)'), 'bool'),
            'time'         => new AA_Property('time',         _m('Time [s]'), 'string'),
            'packtime'     => new AA_Property('packtime',     _m('Time + Cached + 304'), 'string'),
            'mem_usage'    => new AA_Property('mem_usage',    _m('Memory usage [MB]'), 'string'),
            'uri'          => new AA_Property('uri',          _m('Url'), 'string'),
        ];
    }
}

/** Hitcounter entry
 */
class AA_Logentry_W extends AA_Logentry {
    public static function getClassProperties(): array {
        return [
            'time'         => new AA_Property('time',         _m('Time [s]'), 'string'),
            'uri'          => new AA_Property('uri',          _m('Url'), 'string'),
        ];
    }
}
