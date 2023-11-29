<?php
/**
 * File contains definition of inputform class - used for displaying input form
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
use Cvarset;

/** AA\Cache\CacheStr2find class - storage for str2find pairs used by pagecache
 *  to identify records to be deleted (invalidated) from cache
 */
class CacheStr2find {
    protected $strings = [];

    /** AA\Cache\CacheStr2find function
     * @param string[]|null   $strings
     * @param string $type
     */
    function __construct( $strings=null, $type='M') {   // it was $type=slice_id, but for cache it is better to use shorter - M
        $this->strings = [];
        $this->add($strings, $type);
    }

    /** add function
     *  Add ids (array) of specified type (common to all added ids)
     * @param string[] $strings
     * @param string   $type
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
    function getStrings(): array {
        return array_keys($this->strings);
    }

    /** add_str2find function
     * @param CacheStr2find $str2find
     */
    function add_str2find($str2find) {
        if ( is_a($str2find, 'AA\Cache\CacheStr2find') ) {
            foreach ($str2find->strings as $k => $v) {
                $this->strings[$k] = true;   // copy all the $str2find ids to this
            }
        }
    }

    /** clear function
     */
    function clear() {
        $this->strings = [];
    }

    /** store function
     * @param $keyid
     */
    function store($keyid) {
        $varset = new Cvarset( [['pagecache_id', $keyid], ['str2find','']]);
        $varset->doDeleteWhere('pagecache_str2find', "pagecache_id='$keyid'" );
        foreach ((array)$this->strings as $str => $v) {
            $varset->set('str2find', $str);
            $varset->doInsert('pagecache_str2find');
        }
    }
}
