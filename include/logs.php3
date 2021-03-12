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

class AA_Log {

    static function warn($selector) {
        AA_Log::write('AA_WARN', $selector, AA_Log::backtrack(2));
    }

    /** AA_Log::write function - Write log entry
     *  Static function
     *
     * @param              $event
     * @param string|array $selector - if array, stored as separate entries (for ITEM_DEL, ...)
     * @param string|array $params - if array, joined
     * @return bool
     */
    static function write($event, $selector = "", $params = "") {
        global $auth;

        if ( !AA_Log::isLogable($event) ) {
            return false;
        }

        if (is_array($params)) {
            $params = ParamImplode($params);
        }

        $rows = [];
        if (is_array($selector)) {
            foreach ($selector as $sel) {
                $rows[] = ['time'=>time(), 'user'=>$auth->auth['uid'], 'type'=>$event, 'selector'=>$sel, 'params'=>$params];
            }
        } else {
            $rows[] = ['time'=>time(), 'user'=>$auth->auth['uid'], 'type'=>$event, 'selector'=>$selector, 'params'=>$params];
        }

        AA::Metabase()->doInsertMulti('log', $rows);

        return true;
    }

    /** Is the event logable?
     **/
    static function isLogable($event_type) {
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
           // AA_WARN
           // LOGIN
           // PWD_REHASH
        ];
        return !in_array($event_type, $DO_NOT_LOG);
    }

    static function backtrack($remove_steps=1) {
        $ret = explode("\n", str_replace(AA_BASE_PATH, '', (new Exception() )->getTraceAsString()));
        for (; $remove_steps>0; --$remove_steps) {
            array_shift($ret);
        }
        return join("\n", $ret);
    }

    static function context($selector='') {
        return ParamImplode([$selector,str_replace(':','_',$_SERVER['REMOTE_ADDR']), ($_SERVER['HTTPS'] ? '' : 'http/'). "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"]);
    }

    /** getLastLogEvents function
     *  Get events from log
     * @param        $event - type of event
     * @param string $selector
     * @return array
     */
    static public function getLastLogEvents($event, $selector = "") {
        $conds = [['type',$event, (strpos($event, '%') === false) ? '=' : 'LIKE']];
        if ($selector != "") {
            $conds[] = ['selector',$selector];
        }
        return DB_AA::select1('', 'SELECT * FROM log', $conds, ['id-']);
    }

}





