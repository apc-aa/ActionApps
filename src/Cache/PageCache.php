<?php

/**
 * File contains a definition of Pagecache class - used for caching information
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php)
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Cache;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\PagecachePurge;
use AA\Later\Toexecute;
use AA\Util\Url;
use Cvarset;
use Mobile_Detect;

/** AA\Cache\PageCache class used for caching information into database
 *  uses table:
 *    CREATE TABLE pagecache (
 *      id varchar(32) NOT NULL,   (md5 crypted keystring used as database primary key (for quicker database searching)
 *      str2find text NOT NULL,    (string used to find record on manual invalidate cache record - could be keystring)
 *      content mediumtext,        (cached information)
 *      stored bigint,             (timestamp of information storing)
 *      flag int,                  (FLAG_VARY = the row holds cookie names, see storeVaried())
 *     PRIMARY KEY (id), KEY (stored)
 *   );
 */

class PageCache
{
    private int $cacheTime     = 600; // number of seconds to store cached information

    private bool $cache_nostore = false; // if true, the cache is not stored (used for {user:xxxx} alias)

    /** Cookies this request's render read: name => value at the first read. One set for the
     *  whole request - an expansion memoized earlier in it is not read again. */
    private static array $cookies_read = [];

    /** pagecache.flag: the row holds the cookie names of a varied page, not content */
    private const FLAG_VARY = 1;

    /** $cookies_read names of whole jars; cookie() refuses names starting with NUL */
    private const ALL_COOKIES = "\0all";
    private const FORWARDED   = "\0forwarded";

    /** Google's ad cookies, which the `_` prefix rule of keyCookies() misses */
    private const TRACKING_COOKIES = ['IDE' => 1, 'NID' => 1, '1P_JAR' => 1, 'DSID' => 1];

    /** AA\Cache\PageCache function
     *  AA\Cache\PageCache class constructor
     * @param $ct
     */
    public function __construct($ct = 600)
    {
        $this->cacheTime = (int) $ct;
    }

    /** Seconds a stored page is served (CACHE_TTL) - the rule getById() applies */
    public function getCacheTime(): int
    {
        return $this->cacheTime;
    }

    /**
     * Returns $params with all tracker keys removed. Hot-path optimized:
     * empty input short-circuits; if no tracker key is present, returns
     * the input array unchanged (no allocation, no copy).
     */
    public static function filterIgnoredParams(array $params): array
    {
        if (!$params) {
            return $params;
        }
        foreach ($params as $k => $v) {
            if (is_string($k) && Url::isTrackingParam($k)) {
                $out = [];
                foreach ($params as $k2 => $v2) {
                    if (!is_string($k2) || !Url::isTrackingParam($k2)) {
                        $out[$k2] = $v2;
                    }
                }
                return $out;
            }
        }
        return $params;
    }

    /**
     * Returns $qs (a raw URL query string without leading '?') with tracker
     * params removed. Returns the input unchanged when no tracker is found.
     * Tracker names are pure ASCII, so we skip urldecode of the key.
     *
     * When trackers ARE stripped, any empty pairs created by the strip
     * (e.g. the dangling '&' from "utm_source=a&vid=1&") are dropped too so
     * "utm_source=a&vid=1&" and "vid=1" do not produce different keys.
     */
    public static function normalizeQueryString(string $qs): string
    {
        if ($qs === '') {
            return $qs;
        }
        // Fast path: single-pair query (most slice URLs look like ?vid=12).
        // Skips the explode() allocation entirely.
        if (!str_contains($qs, '&')) {
            $eq   = strpos($qs, '=');
            $name = ($eq === false) ? $qs : substr($qs, 0, $eq);
            return Url::isTrackingParam($name) ? '' : $qs;
        }

        $kept    = [];
        $changed = false;
        foreach (explode('&', $qs) as $pair) {
            $eq   = strpos($pair, '=');
            $name = ($eq === false) ? $pair : substr($pair, 0, $eq);
            if (Url::isTrackingParam($name)) {
                $changed = true;
            } else {
                $kept[] = $pair;
            }
        }
        if (!$changed) {
            return $qs;                                         // identity — preserves no-op fast path
        }
        // We're rebuilding anyway — also drop empty pairs that the strip
        // (or `&&` / trailing `&` in the original) leaves behind, so the
        // resulting string is canonical. Inline foreach beats array_filter+
        // closure (closure call overhead).
        $clean = [];
        foreach ($kept as $p) {
            if ($p !== '') {
                $clean[] = $p;
            }
        }
        return implode('&', $clean);
    }

    /**
     * Returns a request URI ("/path?query") with tracker params stripped
     * from the query portion. A bare trailing '?' (or one whose query
     * becomes empty after stripping) is dropped so "/x?" and "/x" map to
     * the same cache key. Returns the input unchanged when no tracker is
     * found and the query is non-empty.
     */
    public static function normalizeRequestUri(string $uri): string
    {
        $qpos = strpos($uri, '?');
        if ($qpos === false) {
            return $uri;
        }
        $qs = substr($uri, $qpos + 1);
        if ($qs === '') {
            return substr($uri, 0, $qpos);                     // bare "?" — drop it
        }
        $normalized = self::normalizeQueryString($qs);
        if ($normalized === $qs) {
            return $uri;
        }
        return $normalized === ''
            ? substr($uri, 0, $qpos)
            : substr($uri, 0, $qpos + 1) . $normalized;
    }

    /** static getKeystring function
     *  Return string to use in keystr for cache if could do a stringexpand
     *  Returns part of keystring
     *
     * @param bool $with_cookies false for keys of getVaried()/storeVaried(), which add only
     *                           the cookies the render read
     */
    public static function globalKeyArray(bool $with_cookies = true): array
    {
        // valid just for one domain (there are sites, where content is based also on domain - enviro.example.org, culture.example.org, ... )
        $ks = ['host' => (str_starts_with($host = $_SERVER['HTTP_HOST'], 'www.')) ? substr($host, 4) : $host];
        if (isset($GLOBALS['apc_state'])) {
            $ks['apc_state'] = $GLOBALS['apc_state'];
        }
        if (isset($GLOBALS['als'])) {
            $ks['als'] = $GLOBALS['als'];
        }
        if (isset($GLOBALS['slice_pwd'])) {
            $ks['slice_pwd'] = $GLOBALS['slice_pwd'];
        }

        if ($with_cookies) {
            foreach (self::keyCookies($_COOKIE) as $name => $value) {
                $ks["C$name"] = $value;
            }
        }

        // for JSON request the parameters are in body, but is not parsed into $_POST variable
        if (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json') {
            $ks["php-input"] = file_get_contents('php://input');
        }

        // for the websites using {detect} in the site
        $detect = new Mobile_Detect();
        $ks['detect'] = $detect->isMobile() . ',' . $detect->isTablet();

        return $ks;
    }

    /** The cookies that may change a page (name => value) */
    private static function keyCookies(array $cookies): array
    {
        $ks = [];
        // Skip:
        //   - cookies starting with `_` (Google urchin, GA, FB pixel, HubSpot __h*, …)
        //   - the AA session cookie (must not segment public cache)
        //   - tracker cookies without underscore prefix (DoubleClick IDE/NID/1P_JAR/DSID,
        //     and the URL trackers some tags keep as cookies - li_fat_id, utm_*)
        foreach ($cookies as $key => $val) {
            // Empty-string keys are possible from malformed Cookie headers
            // ("Cookie: =v"); guard $key[0] from emitting an offset warning.
            $key = (string) $key;
            if ($key === '' || $key[0] === '_' || $key === 'AA_Session' || isset(self::TRACKING_COOKIES[$key]) || Url::isTrackingParam($key)) {
                continue;
            }
            $ks[$key] = $val;
        }
        return $ks;
    }

    /** The one way rendering code reads a cookie. Cookies are not part of the site page-cache
     *  key; each one read here becomes part of it, with the value it had (see storeVaried()).
     *
     * @return array|string|null null = the browser did not send it
     */
    public static function cookie(string $name): array|string|null
    {
        return str_starts_with($name, "\0") ? null : self::read($name);
    }

    /** All cookies - the page then varies on every one */
    public static function cookies(): array
    {
        return self::read(self::ALL_COOKIES);
    }

    /** Cookies a local {include} passes on: those that may change a page and the session, whose
     *  user is in the site key ($auth4cache). Analytics cookies stay home - a page varying on
     *  them would be cached per visitor. */
    public static function forwardedCookies(): array
    {
        $cookies = self::read(self::FORWARDED);
        if (isset($_COOKIE['AA_Session'])) {
            $cookies['AA_Session'] = $_COOKIE['AA_Session'];
            ksort($cookies, SORT_STRING);
        }
        return $cookies;
    }

    private static function read(string $name): mixed
    {
        if (!array_key_exists($name, self::$cookies_read)) {
            $value = match ($name) {
                self::ALL_COOKIES => $_COOKIE,
                self::FORWARDED   => self::keyCookies($_COOKIE),
                default           => $_COOKIE[$name] ?? null,
            };
            if (str_starts_with($name, "\0")) {
                ksort($value, SORT_STRING);   // any order in the header, one variant
            }
            self::$cookies_read[$name] = $value;
        }
        return self::$cookies_read[$name];
    }

    /** $_REQUEST without cookies, for a render cached without them - a value read from there
     *  would be served to everyone. PHP on the ECN servers puts cookies into $_REQUEST, even
     *  over a URL parameter of the same name (request_order GPC).
     *
     * @param ?string $order sources in the order PHP merges them, request_order when null
     */
    public static function requestWithoutCookies(?string $order = null): array
    {
        $request = [];
        foreach (str_split($order ?? self::requestOrder()) as $source) {
            if ($source === 'G') {
                $request = array_replace_recursive($request, $_GET);
            } elseif ($source === 'P') {
                $request = array_replace_recursive($request, $_POST);
            }
        }
        return $request;
    }

    /** Sources of $_REQUEST in the order PHP merges them, e.g. "GPC" */
    private static function requestOrder(): string
    {
        return strtoupper((string) (ini_get('request_order') ?: ini_get('variables_order')));
    }

    /** Row id of the content stored for these cookie values (name => value) */
    public static function variantKey(string $key, array $values): string
    {
        ksort($values, SORT_STRING);
        return get_hash('vary', $key, $values);
    }

    /** get function
     *  Returns cached information or false
     *
     * @param          $key
     * @param  string  $action
     *
     * @return false|string
     */
    public function get($key, string $action = 'get'): false|string
    {
        $row = $this->getRow($key, $action);
        return $row ? $row['content'] : false;
    }

    /** get() returning the whole row - content, stored, flag */
    protected function getRow($key, string $action = 'get'): ?array
    {
        AA::$debug & 2 && AA::$dbg->log("Pagecache->get(key):$key", 'Pagecache action:' . $action);

        if (ENABLE_PAGE_CACHE) {
            if ($action === 'invalidate') {
                $this->invalidateById($key);
                AA::$debug & 2 && AA::$dbg->log("Pagecache: return false - invalidating");
                return null;
            }

            if ($action === '1') {  // nocache=1
                AA::$debug & 2 && AA::$dbg->log("Pagecache: return false - nocache");
                return null;
            }
            return $this->rowById($key);
        }
        return null;
    }

    /** Content storeVaried() stored under $key for this request's cookies, or false.
     *  Its rows never use $key itself: an older AA reading the same database would take the
     *  names row for a page.
     */
    public function getVaried(string $key, string $action = 'get'): false|string
    {
        $row = $this->getRow($base = get_hash('vary', $key), $action);
        if ($row && ((int) $row['flag'] & self::FLAG_VARY)) {
            $names  = unserialize((string) $row['content'], ['allowed_classes' => false]);
            $values = [];
            foreach (is_array($names) ? $names : [] as $name) {
                $values[$name] = self::read((string) $name);   // the page served or rendered now depends on them
            }
            $row = $this->getRow(self::variantKey($key, $values), $action);
        }
        return $row ? $row['content'] : false;
    }

    /** Stores $content rendered under $key. When the render read cookies (cookie()), the
     *  content goes under a row id extended by their values and the names go to a row of
     *  their own - visitors differing only in other cookies (consent, ads) share the page.
     *  The names row is written with every variant: it must not expire or be swept before
     *  them. Names found at the lookup are among the ones read (getVaried()), so no variant
     *  stored so far becomes unreachable.
     */
    public function storeVaried(string $key, string $content, CacheStr2find $str2find, bool $force = false): bool
    {
        $base = get_hash('vary', $key);
        if (!self::$cookies_read) {
            return $this->store($base, $content, $str2find, $force);
        }
        $names = array_keys(self::$cookies_read);
        sort($names, SORT_STRING);
        return $this->store(self::variantKey($key, self::$cookies_read), $content, $str2find, $force)
            && $this->store($base, serialize($names), $str2find, $force, self::FLAG_VARY);
    }

    /** cacheDb function
     *  Calls $function with $params and returns its return value. The result
     *  value is then stored into pagecache (database), so next call
     *  of the $function (also from another script) with the same parameters
     *  is returned from cache - function is not performed.
     *  Use this feature mainly for repeating, time-consuming functions!
     *  You could use also object methods - then the $function parameter should
     *  be array (see http://php.net/manual/en/function.call-user-func.php)
     * @param $function
     * @param $params
     * @param $str2find
     * @param $action
     */
    public function cacheDb($function, $params, $str2find, $action = 'get'): mixed
    {
        $action = (string) $action;
        $key = get_hash($function, $params);
        if ($res = $this->get($key, $action)) {
            return unserialize($res);  // it is serialized for storing in the database
        }
        $res = call_user_func_array($function, (array) $params);
        if (!ctype_digit($action)) {  // nocache is not
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
    public function cacheMemDb($function, $params, $str2find, $action = 'get'): mixed
    {
        $key = get_hash($function, $params);
        if ($res = AA::Contentcache()->get($key)) {
            return $res;
        }
        $res = $this->cacheDb($function, $params, $str2find, $action);
        AA::Contentcache()->set($key, $res);
        return $res;
    }

    /** cacheMem function
     *  Wrapper for contentcache->get_result
     * @param $function
     * @param $params
     */
    public function cacheMem($function, $params): mixed
    {
        return AA::Contentcache()->get_result($function, $params);
    }

    /** getById function
     *  Get cache content by ID (not keystring)
     * @param $keyid
     * @return string|false
     */
    public function getById($keyid): false|string
    {
        $row = $this->rowById($keyid);
        return $row ? $row['content'] : false;
    }

    private function rowById($keyid): ?array
    {
        $arr = DB_AA::select1('', 'SELECT stored, content, flag FROM `pagecache`', [['id', $keyid]]);
        return ($arr && (time() - $this->cacheTime) < $arr['stored']) ? $arr : null;
    }

    /** set function
     *  Cache information based on $keyString
     *  Returns database identifier of the cache value (MD5 of keystring)
     *
     * @param                 $key
     * @param                 $content
     * @param  CacheStr2find  $str2find
     * @param  bool           $force  - if true, the content is stored into cache even
     *                                if ENABLE_PAGE_CACHE is false (we use cache for cached
     *                                javascript in admin interface (modules selectbox
     *                                for example), so we need to use cache here)
     * @param  int            $flag   - pagecache.flag (FLAG_VARY)
     *
     * @return bool
     */
    public function store($key, $content, $str2find, $force = false, int $flag = 0): bool
    {
        $ret = false;

        AA::$debug & 2 && AA::$dbg->log("Pagecache->store(key):$key", 'Pagecache str2find:' . implode(',', $str2find->getStrings()), 'Pagecache content (length):' . strlen($content), 'Pagecache cache_nostore:' . $this->cache_nostore);

        if ($force || (ENABLE_PAGE_CACHE && !$this->cache_nostore)) {  // $this->cache_nostore used if {user:xxxx} alias is used
            AA::$debug & 2 && AA::$dbg->log("Pagecache->store(): - storing");
            $row = ['id' => $key, 'content' => $content, 'stored' => time(), 'flag' => $flag];

            // true replace mean it calls REPLACE command and no
            // SELECT+INSERT/UPDATE (which is better for tables with
            // autoincremented columns). There is no autoincrement, so we can
            // use true Replace
            // I'm trying to avoid problems with:
            //    Database error: Invalid SQL: INSERT INTO pagecache ...
            //    Error Number (description): 1062 (Duplicate entry '52e2804826c438a439cf301817c07020' for key 1)

            // one transaction, so the three statements cost one commit instead of three
            // (each commit is two fsyncs here) and the str2find rows can never point at
            // a pagecache row that was not written
            DB_AA::transaction(static function () use ($str2find, $key, $row) {
                $str2find->store($key);                         // DELETE + INSERT into pagecache_str2find
                AA::Metabase()->doReplace('pagecache', $row);  // REPLACE INTO pagecache
            });

            // it is not necessary to check, if the  AA\Later\PagecachePurge is planed
            // store. We check it only once for 1000 (PAGECACHEPURGE_PROBABILITY)
            /** @noinspection RandomApiMigrationInspection */ // mt_rand is used for speed
            if (mt_rand(0, PAGECACHEPURGE_PROBABILITY) === 1) {
                // purge only each PAGECACHEPURGE_PROBABILITY-th call of store
                $cache_purger  = new PagecachePurge();
                (new Toexecute())->laterOnce($cache_purger, [$this->cacheTime], 'PagecachePurge', 101, now() + 300);  // run it once in 5 minutes
            }
            $ret = true;
        }
        return $ret;
    }

    /** special cache function for storing whole page. The whole page should be
     *  cached also with headers and id of item, where to count hit.
     *  When page is stored with storePage(), then you have to use getPage()
     *  counterpart function
     */
    public function storePage($key, Cacheentry $entry, $str2find, $force = false): bool
    {
        // @todo - we can use json_encode, when $entry->$h['encoding']==='utf-8' which is quicker, than serialize
        return $this->store($key, serialize($entry), $str2find, $force);
    }

    public function getPage($key, $action = 'get'): ?Cacheentry
    {
        $action = (string) $action;
        return self::toEntry($this->get($key, $action), $action);
    }

    /** storePage() for a key without cookies - see storeVaried() */
    public function storeVariedPage(string $key, Cacheentry $entry, CacheStr2find $str2find): bool
    {
        return $this->storeVaried($key, serialize($entry), $str2find);
    }

    /** getPage() for a key without cookies - see getVaried() */
    public function getVariedPage(string $key, string $action = 'get'): ?Cacheentry
    {
        return self::toEntry($this->getVaried($key, $action), $action);
    }

    private static function toEntry(false|string $entry, string $action): ?Cacheentry
    {
        AA::$debug & 2 && AA::$dbg->log("Pagecache->getPage(output): " . ($entry ? 'cached text' : 'not found'), 'Pagecache action:' . $action);
        return $entry ? unserialize($entry, ['allowed_classes' => [Cacheentry::class]]) : null;
    }


    /** invalidateById function
     *  Remove specified ids from cache
     * @param $keys
     */
    public function invalidateById($keys): void
    {
        // we will delete it in chunks in order we do not get max_packet_size error from MySQL
        $chunks = array_chunk((array) $keys, 10000);
        foreach ($chunks as $chunk) {
            $keystring = implode("','", $chunk);
            if ($keystring !== '') {
                $varset = new Cvarset();
                if ($varset->doDeleteWhere('pagecache', "id IN ('$keystring')", 'nohalt')) {
                    // delete keys only in case the pagecache deletion was successful
                    $varset->doDeleteWhere('pagecache_str2find', " pagecache_id IN ('$keystring')", 'nohalt');
                }
            }
        }
    }

    /** Deletes everything stored before $time.
     *
     * @param $time
     * @return string
     */
    public static function invalidateOlder($time): string
    {
        $highest_old_pid = DB_AA::select1('id', 'SELECT id FROM pagecache', [['stored', $time, '<']], ['stored-']);
        if (!$highest_old_pid) {
            return "time<$time, nothing older";
        }
        $highest_old_id  = DB_AA::select1('id', 'SELECT id FROM pagecache_str2find', [['pagecache_id', $highest_old_pid]], ['id-']);
        if ($highest_old_id) {
            DB_AA::delete_low_priority('pagecache_str2find', [['id',$highest_old_id,'<=']]);
        }
        DB_AA::delete_low_priority('pagecache', [['stored',$time,'<']]);
        return "time<$time, id<=$highest_old_id";
    }

    /** invalidateFor function
     *  Remove cached information for all rows that have the $cond in str2find
     * @param string | array $strings
     * @param string         $type
     * @param int|null       $stored_before  only entries with pagecache.stored < $stored_before
     *                       (AA\Later\PagecacheDateSweep: pages rendered after an item's publish/expiry
     *                       moment are already correct and must survive)
     * @return int number of cache entries selected for deletion
     */
    public function invalidateFor($strings, $type = 'M', ?int $stored_before = null): int
    {
        // We do not want to report errors here. Sometimes this SQL leads to:
        //   "MySQL Error: 1213 (Deadlock found when trying to get lock; Try
        //    restarting transaction)" error.
        // It is a not-so-big problem if we do not invalidate cache - much less than
        // halting the operation.

        $str2find = new CacheStr2find($strings, $type);

        if ($stored_before === null) {
            $keys = DB_AA::select('pagecache_id', "SELECT pagecache_id FROM pagecache_str2find", [['str2find',$str2find->getStrings()]]);
        } else {
            // never touches `content`: s via the str2find(20) prefix index, p via PRIMARY (only the inline `stored`)
            $keys = DB_AA::select('pagecache_id', 'SELECT s.pagecache_id FROM pagecache_str2find s JOIN pagecache p ON p.id = s.pagecache_id', [['s.str2find', $str2find->getStrings()], ['p.stored', $stored_before, '<']]);
        }
        $keys = array_unique($keys);

        // invalidateById() is quite slow - mainly if we have to delete more rows,
        // I do not know how to make it quicker. I tried to refine the SQL
        // command, but following SQL does not help either:
        //
        //   DELETE pagecache, pagecache_str2find FROM pagecache, pagecache_str2find
        //    WHERE pagecache.id = pagecache_str2find.pagecache_id AND pagecache_str2find.str2find = '".quote($cond)."'";

        $this->invalidateById($keys);
        return count($keys);
    }

    /** invalidate function
     *  Remove cached information for all rows
     */
    public function invalidate(): void
    {
        DB_AA::delete('pagecache_str2find');
        DB_AA::delete('pagecache');
    }

    /** the page is not stored in the cache */
    public function switchCacheNostore(): void
    {
        $this->cache_nostore = true;
    }
}
