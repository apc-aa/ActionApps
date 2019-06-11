<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 11.6.19
 * Time: 10:14
 */

namespace AA\Util;


/**
 * Interface LaterInterface - enable to plan this object execution for later time
 * Ussage:
 *   $cache_purger  = new AA_Pagecache_Purge();
 *   $toexecute     = new AA_Toexecute;
 *   $toexecute->laterOnce($cache_purger, array($this->cacheTime), 'AA_Pagecache_Purge', 101, now() + 300);  // run it once in 5 minutes
 * @package AA\Util
 */
interface LaterInterface {

    /**
     * @param array $params - array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     */
    public function toexecutelater($params=array());

}