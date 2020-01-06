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
 * @link      https://www.apc.org/ APC
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

class AA_Cacheentry {
    protected $c = '';      // content
    protected $h = array(); // headers array
    protected $i = '';      // item id for page hit
    protected $p = '';      // Cacheability - private/public/no-cache - private used for pages, where user is logged in
    protected $t = 0;       // timestamp

    function __construct($content, array $headers=array(), $item_id='', $cacheability='public') {
        $this->c = $content;
        $this->h = $headers;
        $this->i = $item_id;
        $this->p = $cacheability;
        $this->t = time();
    }

    /** send headers, print output and count hit */
    function processPage($cached=0) {
        $lastModified    = $this->t;     // get the last-modified-date of this very file
        $etag            = hash('md5', 'x'.$this->c.'q'); // get a unique hash of this content (etag) (make it not the same as key in db for (maybe paranoid) security reasons)
        $ifModifiedMatch = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])==$lastModified) : false;
        $etagMatch       = isset($_SERVER['HTTP_IF_NONE_MATCH'])     ? (trim($_SERVER['HTTP_IF_NONE_MATCH'])==$etag) : false;   //(etag: unique file hash)
        $cacheability    = in_array($this->p,array('public','private','no_cache')) ? $this->p : 'public';

        header("Last-Modified: ".gmdate("D, d M Y H:i:s", $lastModified)." GMT");   // set last-modified header
        header("Etag: $etag");                                                      // set etag-header
        // maybe we do not have to chache all pages
        header("Cache-Control: $cacheability");                                     // make sure caching is turned on
        // maybe we can add max-age=60   (minute helps a lot on big loads (catches 99%), but is nothing for reader)

        // check if page has changed. If not, send 304 and exit
        // Maybe we should check also $this->h, if they do not contain 404, 302 or other status code already
        if ( $ifModifiedMatch OR $etagMatch ) {
            header("HTTP/1.1 304 Not Modified");
            $cached = 1000*$cached + 304;
        } else {
            AA::sendHeaders($this->h);
            echo $this->c;
        }
        if ($this->i) {
            AA_Hitcounter::hit(new zids($this->i, 'l'), $cached);
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

        // for JSON request the parameters are in body, but is not parsed into $_POST variable
        if ($_SERVER['CONTENT_TYPE'] == 'application/json') {
           $ks["php-input"] = file_get_contents('php://input');
        }

        // for the websites using {detect} in the site
        $detect = new Mobile_Detect;
        $ks['detect'] = $detect->isMobile() . ','. $detect->isTablet();

        return $ks;
    }

    /** get function
     *  Returns cached informations or false
     * @param $keyId
     * @param $action
     * @return bool
     */
    function get($key, $action='get') {
        AA::$debug&2 && AA::$dbg->log("Pagecache->get(key):$key", 'Pagecache action:'.$action);

        if ( ENABLE_PAGE_CACHE ) {
            if ( $action == 'invalidate' ) {
                $this->invalidateById( $key );
                AA::$debug&2 && AA::$dbg->log("Pagecache: return false - invlaidating");
                return false;
            } elseif (ctype_digit((string)$action) ) {  // nocache=1
                AA::$debug&2 && AA::$dbg->log("Pagecache: return false - nocache");
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
     * @return bool|mixed
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
     * @return bool|false|mixed
     */
    function cacheMemDb($function, $params, $str2find, $action='get') {
        $key = get_hash($function, $params);
        if ($res = AA::Contentcache()->get($key)) {
            return $res;
        }
        $res = $this->cacheDb($function, $params, $str2find, $action);
        AA::Contentcache()->set($key,$res);
        return $res;
    }

    /** cacheMem function
     *  Wrapper for contentcache->get_result
     * @param $function
     * @param $params
     * @return mixed
     */
    function cacheMem($function, $params) {
        return AA::Contentcache()->get_result( $function, $params );
    }

    /** getById function
     *  Get cache content by ID (not keystring)
     * @param $keyid
     * @return bool
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
     * @param CacheStr2find $str2find
     * @param $force - if true, the content is stored into cache even
     *                 if ENABLE_PAGE_CACHE is false (we use cache for cached
     *                 javascript in admin interface (modules selectbox
     *                 for example), so we need to use cache here)
     * @return mixed
     */
    function store($key, $content, $str2find, $force=false) {
        global $cache_nostore;

        AA::$debug&2 && AA::$dbg->log("Pagecache->store(key):$key", 'Pagecache str2find:'.join(',', $str2find->getStrings()), 'Pagecache content (length):'.strlen($content), 'Pagecache cache_nostore:'.$cache_nostore );

        if ($force OR (ENABLE_PAGE_CACHE AND !$cache_nostore)) {  // $cache_nostore used when
                                                      // {user:xxxx} alias is used
            AA::$debug&2 && AA::$dbg->log("Pagecache->store(): - storing");
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

    /** invalidateById function
     *  Remove specified ids from cache
     * @param $keys
     */
    static function invalidateOlder( $time ) {
        $highest_old_pid = DB_AA::select1('SELECT id FROM pagecache','id',array(array('stored',$time,'<')), array('id-'));
        $highest_old_id  = DB_AA::select1('SELECT id FROM pagecache_str2find','id',array(array('pagecache_id',$highest_old_pid)));
        if ($highest_old_id) {
            DB_AA::delete_low_priority('pagecache_str2find',array(array('id',$highest_old_id,'<=')));
            DB_AA::delete_low_priority('pagecache',array(array('stored',$time,'<')));
        }
        return "time<$time, id<=$highest_old_id";
    }

    /** invalidateFor function
     *  Remove cached informations for all rows which have the $cond in str2find
     * @param string | array $strings
     * @param string         $type
     */
    function invalidateFor($strings, $type='M') {
        // We do not want to report errors here. Sometimes this SQL leads to:
        //   "MySQL Error: 1213 (Deadlock found when trying to get lock; Try
        //    restarting transaction)" error.
        // It is not so big problem if we do not invalidate cache - much less than
        // halting the operation.

        $str2find = new CacheStr2find($strings, $type);

        $keys = DB_AA::select('pagecache_id', "SELECT pagecache_id FROM pagecache_str2find", array(array('str2find',$str2find->getStrings())));

        // invalidateById() is quite slow - mainly if we have to delete more rows
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
        DB_AA::delete('pagecache_str2find');
        DB_AA::delete('pagecache');
    }
}


class AA_Pagecache_Purge implements \AA\Util\LaterInterface {

    /** purge function  - Clears all old cached data
     *  special function called from AA_Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Util\LaterInterface
     */
    public function toexecutelater($params=array()) {
        list($cache_time) = $params;
        // $tm   = time();
        // we tired to speed up the deletion by multi-table delete:
        // DELETE pagecache, pagecache_str2find FROM pagecache, pagecache_str2find
        //  WHERE pagecache.id = pagecache_str2find.pagecache_id AND pagecache.stored<'1200478499'
        // (supported in MySQL >= 4.0), but it takes ages

        return PageCache::invalidateOlder( time() - $cache_time );
    }
}


/** CacheStr2find class - storage for str2find pairs used by pagecache
 *  to identify records to be deleted (invalidated) from cache
 */
class CacheStr2find {
    protected $strings = array();

    /** CacheStr2find function
     * @param $ids
     * @param $type
     */
    function __construct( $strings=null, $type='M') {   // it was $type=slice_id, but for cache it is better to use shorter - M
        $this->strings = array();
        $this->add($strings, $type);
    }

    /** add function
     *  Add ids (array) of specified type (common to all added ids)
     * @param $ids
     * @param $type
     */
    function add($strings, $type='M') {    // it was $type=slice_id, but for cache it is better to use shorter - M (like Module id)
        if ( !$strings ) {
            return;
        }
        $strings = array_filter((array)$strings, 'trim');
        foreach ($strings as $str) {
            $this->strings["$type=$str"] = true;   // match type-id pair
        }
    }

    /**
     * @return array
     */
    function getStrings() {
        return array_keys($this->strings);
    }

    /** add_str2find function
     * @param CacheStr2find $str2find
     */
    function add_str2find($str2find) {
        if ( is_a($str2find,'CacheStr2find') ) {
            foreach ($str2find->strings as $k => $v) {
                $this->strings[$k] = true;   // copy all the $str2find ids to this
            }
        }
    }

    /** clear function
     */
    function clear() {
        $this->strings = array();
    }

    /** store function
     * @param $keyid
     */
    function store($keyid) {
        $varset = new Cvarset( array( array('pagecache_id', $keyid), array('str2find','')));
        $varset->doDeleteWhere('pagecache_str2find', "pagecache_id='$keyid'" );
        foreach ((array)$this->strings as $str => $v) {
            $varset->set('str2find', $str);
            $varset->doInsert('pagecache_str2find');
        }
    }
}

$GLOBALS['pagecache'] = new PageCache(CACHE_TTL);

