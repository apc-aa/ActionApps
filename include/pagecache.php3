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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


/** PageCache class used for caching informations into database
 *  uses table:
 *    CREATE TABLE pagecache (
 *      id varchar(32) NOT NULL,   (md5 crypted keystring used as database primary key (for quicker database searching)
 *      str2find text NOT NULL,    (string used to find record on manual invalidate cache record - could be keystring)
 *      content mediumtext,        (cached information)
 *      stored bigint,             (timestamp of information storing)
 *      flag int,                  (flag - not used for now)
 *     PRIMARY KEY (id), KEY (stored)
 *   );
 */

require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."toexecute.class.php3";

class AA_Cacheentry {
    protected $c = '';      // content
    protected $h = array(); // headers array
    protected $i = '';      // item id for page hit
    protected $t = 0;       // timestamp

    function __construct($content, array $headers=array(), $item_id='') {
        $this->c = $content;
        $this->h = $headers;
        $this->i = $item_id;
        $this->t = time();
    }

    /** send headers, print output and count hit */
    function processPage() {

        $lastModified    = $this->t;     // get the last-modified-date of this very file
        $etag            = hash('md5', 'x'.$this->c.'q'); // get a unique hash of this content (etag) (make it not the same as key in db for (maybe paranoid) security reasons)
        $ifModifiedMatch = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])==$lastModified) : false;
        $etagMatch       = isset($_SERVER['HTTP_IF_NONE_MATCH'])     ? (trim($_SERVER['HTTP_IF_NONE_MATCH'])==$etag) : false;   //(etag: unique file hash)

        header("Last-Modified: ".gmdate("D, d M Y H:i:s", $lastModified)." GMT");   // set last-modified header
        header("Etag: $etag");                                                      // set etag-header
        // maybe we do not have to chache all pages
        header('Cache-Control: public');                                            // make sure caching is turned on
        // maybe we can add max-age=60   (minute helps a lot on big loads (catches 99%), but is nothing for reader)

        // check if page has changed. If not, send 304 and exit
        // Maybe we should check also $this->h, if they do not contain 404, 302 or other status code already
        if ( $ifModifiedMatch OR $etagMatch ) {
            header("HTTP/1.1 304 Not Modified");
        } else {
            AA::sendHeaders($this->h);
            echo $this->c;
        }
        if ($this->i) {
            AA_Hitcounter::hit(new zids($this->i, 'l'));
        }
    }
}

class PageCache  {
    protected $cacheTime     = 600; // number of seconds to store cached informations

    /** PageCache function
     *  PageCache class constructor
     * @param $ct
     */
    function __construct($ct = 600) {
        $this->cacheTime = $ct;
    }

    /** static getKeystring function
     *  Return string to use in keystr for cache if could do a stringexpand
     *  Returns part of keystring
     */
    static function globalKeyArray() {
        // valid just for one domain (there are sites, where content is based also on domain - enviro.example.org, culture.example.org, ... )
        $ks = array('host' => (strpos($host = $_SERVER['HTTP_HOST'], 'www.')===0) ? substr($host,4) : $host);
        if (isset($GLOBALS['apc_state'])) {
            $ks['apc_state'] = $GLOBALS['apc_state'];
        }
        if (isset($GLOBALS['als'])) {
            $ks['als'] = $GLOBALS['als'];
        }
        if (isset($GLOBALS['slice_pwd'])) {
            $ks['slice_pwd'] = $GLOBALS['slice_pwd'];
        }

        if (isset($_COOKIE)) {
            // do not count with cookie names starting with underscore
            // (Google urchin uses cookies like __utmz which varies very often)
            foreach( $_COOKIE as $key => $val ) {
                if ( ($key{0}!='_') AND ($key!='AA_Session')) {
                    $ks["C$key"] = $val;
                }
            }
        }
        return $ks;
    }

    /** get function
     *  Returns cached informations or false
     * @param $keyId
     * @param $action
     */
    function get($key, $action='get') {
        AA::$debug && AA::$dbg->log("Pagecache->get(key):$key", 'Pagecache action:'.$action);

        if ( ENABLE_PAGE_CACHE ) {
            if ( $action == 'invalidate' ) {
                $this->invalidateById( $key );
                AA::$debug && AA::$dbg->log("Pagecache: return false - invlaidating");
                return false;
            } elseif (ctype_digit((string)$action) ) {  // nocache=1
                AA::$debug && AA::$dbg->log("Pagecache: return false - nocache");
                return false;
            }
            return $this->getById( $key );
        }
        return false;
    }

    /** cacheDb function
     *  Calls $function with $params and returns its return value. The result
     *  value is then stored into pagecache (database), so next call
     *  of the $function (also from another script) with the same parameters
     *  is returned from cache - function is not performed.
     *  Use this feature mainly for repeating, time consuming functions!
     *  You could use also object methods - then the $function parameter should
     *  be array (see http://php.net/manual/en/function.call-user-func.php)
     * @param $function
     * @param $params
     * @param $str2find
     * @param $action
     */
    function cacheDb($function, $params, $str2find, $action='get') {
        $key = get_hash($function, $params);
        if ( $res = $this->get($key, $action) ) {
            return unserialize($res);  // it is setrialized for storing in the database
        }
        $res = call_user_func_array($function, (array)$params);
        if (!ctype_digit((string)$action)) {  // nocache is not
            $this->store($key, serialize($res), $str2find);
        }
        return $res;
    }

    /** cacheMemDb function
     *  Look in memory (contentcache) for the result. If not found, use database
     *  (pagecache). The result is stored into memory as well as to the database
     * @param $function
     * @param $params
     * @param $str2find
     * @param $action
     */
    function cacheMemDb($function, $params, $str2find, $action='get') {
        global $contentcache;
        $key = get_hash($function, $params);
        if ($res = $contentcache->get($key)) {
            return $res;
        }
        $res = $this->cacheDb($function, $params, $str2find, $action);
        $contentcache->set($key,$res);
        return $res;
    }

    /** cacheMem function
     *  Wrapper for contentcache->get_result
     * @param $function
     * @param $params
     */
    function cacheMem($function, $params) {
        global $contentcache;
        return $contentcache->get_result( $function, $params );
    }

    /** getById function
     *  Get cache content by ID (not keystring)
     * @param $keyid
     */
    function getById($keyid) {
        $arr   = DB_AA::select1('SELECT stored, content FROM `pagecache`', '', array(array('id', $keyid)));
        return ( $arr AND ((time() - $this->cacheTime) < $arr['stored']) ) ? $arr['content'] : false;
    }

    /** set function
     *  Cache informations based on $keyString
     *  Returns database identifier of the cache value (MD5 of keystring)
     * @param $keyString
     * @param $content
     * @param $str2find
     * @param $force - if true, the content is stored into cache even
     *                 if ENABLE_PAGE_CACHE is false (we use cache for cached
     *                 javascript in admin interface (modules selectbox
     *                 for example), so we need to use cache here)
     */
    function store($key, $content, $str2find, $force=false) {
        global $cache_nostore;

        AA::$debug && AA::$dbg->log("Pagecache->store(key):$key", 'Pagecache str2find:'.$str2find->getStr2find(), 'Pagecache content (length):'.strlen($content), 'Pagecache cache_nostore:'.$cache_nostore );

        if ($force OR (ENABLE_PAGE_CACHE AND !$cache_nostore)) {  // $cache_nostore used when
                                                      // {user:xxxx} alias is used
            AA::$debug && AA::$dbg->log("Pagecache->store(): - storing");
            $varset = new Cvarset( array( array('content', $content), array('stored', time())));
            $varset->addkey('id', 'text', $key);
            $str2find->store($key);

            // true replace mean it calls REPLACE command and no
            // SELECT+INSERT/UPDATE (which is better for tables with
            // autoincremented columns). There is no autoincrement, so we can
            // use true Replace
            // I'm trying to avoid problms with:
            //    Database error: Invalid SQL: INSERT INTO pagecache ...
            //    Error Number (description): 1062 (Duplicate entry '52e2804826c438a439cf301817c07020' for key 1)

            $varset->doTrueReplace('pagecache');  // true replace mean it calls REPLACE command and no SELECT+INSERT/UPDATE (which is better for tables with autoincremented columns, which is no

            // it is not necessary to check, if the  AA_Pagecache_Purge is planed
            // store. We check it only once for 1000 (PAGECACHEPURGE_PROBABILITY)
            if (mt_rand(0,PAGECACHEPURGE_PROBABILITY) == 1) {
                // purge only each PAGECACHEPURGE_PROBABILITY-th call of store
                $cache_purger  = new AA_Pagecache_Purge();
                $toexecute     = new AA_Toexecute;
                $toexecute->laterOnce($cache_purger, array($this->cacheTime), 'AA_Pagecache_Purge', 101, now() + 300);  // run it once in 5 minutes
            }
        }
        return $key;
    }

    /** special cache function for storing whole page. The whole page should be
     *  cached also with headers and id of item, where to count hit.
     *  When page is stored with storePage(), then you have to use getPage()
     *  counterpart function
     */
    function storePage($key, AA_Cacheentry $entry, $str2find, $force=false) {
        // @todo - we can use json_encode, when $entry->$h['encoding']=='utf-8' which is quicker, than serialize
        $this->store($key, serialize($entry), $str2find, $force);
    }

    function getPage($key, $action='get') {
        $entry = $this->get($key, $action);
        return $entry ? unserialize($entry) : false;
    }


    /** invalidateById function
     *  Remove specified ids from cache
     * @param $keys
     */
    function invalidateById( $keys ) {
        // we will delete it in chuns in order we do not get max_packet_size error from MySQL
        $chunks = array_chunk((array)$keys, 10000);
        foreach ($chunks as $chunk) {
            $keystring = join("','", $chunk);
            if ( $keystring != '' ) {
                $varset = new Cvarset();
                if ( $varset->doDeleteWhere('pagecache', "id IN ('$keystring')", 'nohalt') ) {
                    // delete keys only in case the pagecache deletion was successful
                    $varset->doDeleteWhere('pagecache_str2find', " pagecache_id IN ('$keystring')", 'nohalt');
                }
            }
        }
    }

    /** invalidateFor function
     *  Remove cached informations for all rows which have the $cond in str2find
     * @param $cond
     */
    function invalidateFor($cond) {
        // We do not want to report errors here. Sometimes this SQL leads to:
        //   "MySQL Error: 1213 (Deadlock found when trying to get lock; Try
        //    restarting transaction)" error.
        // It is not so big problem if we do not invalidate cache - much less than
        // halting the operation.

        // AA_Log::write('PAGECACHE', $cond, 'invalidate'); // for debug

        $keys = GetTable2Array("SELECT pagecache_id FROM pagecache_str2find WHERE str2find = '".quote($cond)."'", '', 'pagecache_id');

        // invalidateById() is quite slow - mainly if we have to delete mor rows
        // I do not know, how to make it quicker. I tried to refine the SQL
        // command, but following SQL do not help either:
        //
        //   DELETE pagecache, pagecache_str2find FROM pagecache, pagecache_str2find
        //    WHERE pagecache.id = pagecache_str2find.pagecache_id AND pagecache_str2find.str2find = '".quote($cond)."'";

        $this->invalidateById( $keys );
    }

    /** invalidate function
     *  Remove cached informations for all rows
     */
    function invalidate() {
        $db  = getDB();
        $SQL = "DELETE FROM pagecache";
        $db->query_nohalt($SQL);
        $SQL = "DELETE FROM pagecache_str2find";
        $db->query_nohalt($SQL);
        freeDB($db);
    }
}


class AA_Pagecache_Purge {
    /** purge function
     *  Clears all old cached data
     */
    function toexecutelater($cache_time) {
        $tm   = time();
        // we tired to speed up the deletion by multi-table delete:
        // DELETE pagecache, pagecache_str2find FROM pagecache, pagecache_str2find
        //  WHERE pagecache.id = pagecache_str2find.pagecache_id AND pagecache.stored<'1200478499'
        // (supported in MySQL >= 4.0), but it takes ages

        $keys = GetTable2Array("SELECT id FROM pagecache WHERE stored<'".(time() - $cache_time)."'", '', 'id');
        PageCache::invalidateById( $keys );
    }
}


/** CacheStr2find class - storage for str2find pairs used by pagecache
 *  to identify records to be deleted (invalidated) from cache
 */
class CacheStr2find {
    protected $ids = array();

    /** CacheStr2find function
     * @param $ids
     * @param $type
     */
    function __construct( $ids=null, $type='slice_id') {
        $this->add($ids, $type);
    }

    /** add function
     *  Add ids (array) of specified type (common to all added ids)
     * @param $ids
     * @param $type
     */
    function add($ids, $type='slice_id') {
        if ( !$ids ) {
            return;
        }
        foreach ((array)$ids as $id) {
            $this->ids["$type=$id"] = true;   // match type-id pair
        }
    }
    /** add_str2find function
     * @param $str2find
     */
    function add_str2find($str2find) {
        if ((strtolower(get_class($str2find)) == 'cachestr2find') AND is_array($str2find->ids)) {
            foreach ($str2find->ids as $k => $v) {
                $this->ids[$k] = true;   // copy all the $str2find ids to this
            }
        }
    }
    /** clear function
     *
     */
    function clear() {
        unset($this->ids);
        $this->ids = array();
    }
    /** store function
     * @param $keyid
     */
    function store($keyid) {
        $varset = new Cvarset( array( array('pagecache_id', $keyid), array('str2find','')));
        $varset->doDeleteWhere('pagecache_str2find', "pagecache_id='$keyid'" );
        foreach ((array)$this->ids as $id => $v) {
            $varset->set('str2find', $id);
            $varset->doInsert('pagecache_str2find');
        }
    }
    /** getStr2find function
     *
     */
    function getStr2find() {
        $out = '';
        foreach ((array)$this->ids as $id => $v) {
            $out .= ",$id";
        }
        return $out;
    }
}

$GLOBALS['pagecache'] = new PageCache(CACHE_TTL);
?>
