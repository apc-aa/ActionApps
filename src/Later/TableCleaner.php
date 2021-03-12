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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Later;

use AA\IO\DB\DB_AA;

class TableCleaner
{
    protected $table;
    protected $where;

    function __construct(string $table, array $where)
    {
        $this->table = $table;
        $this->where = $where;
    }

    /** delete rows in table based on where.
     * @return string as result for toexecute method
     */
    public function clean(): string
    {
        $ret = 'Err - wrong params';
        if ($this->table and $this->where) {
            if ( ($rows = DB_AA::delete($this->table, $this->where)) === false) {
                $ret = 'Err - SQL delete';
            } else {
                $ret = "OK - $rows deleted in $this->table";
            }
        }
        return $ret;
    }
}
