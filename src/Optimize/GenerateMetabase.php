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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Metabase;
use function _m;


// this is wrong! it deletes the priority field! Disabled for now.
/*
$SQL_update_modules[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) SELECT id, name, deleted, 'S', slice_url, lang_file, created_at, created_by, owner, 0 FROM slice";
*/


/** Generate metabase definition row */
class GenerateMetabase extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Generate metabase definition row");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("For programmers only - Generate metabace definition row from current database bo be placed in /service/metabase.class.php3 and /include/metabase.class.php3 scripts");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Name function
     * @return bool
     */
    public function repair(): bool {
        $metabase = new AA_Metabase;
        $metabase->loadFromDb();
        echo '$instance = unserialize(\'' . str_replace("'", '\\\'', serialize($metabase)) . '\');';
        exit;
    }
}


