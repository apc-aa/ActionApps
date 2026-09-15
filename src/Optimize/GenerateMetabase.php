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

/** Generate metabase definition row */
class GenerateMetabase extends \AA\Optimize\AbstractOptimize
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Generate metabase definition row");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("For programmers only - prints the include/metabase_schema.php content (the expected DB schema, as a readable PHP array) generated from the current database; copy it into the file and commit.");
    }

    /** implemented actions within this class */
    public static function actions(): array
    {
        return ['repair'];
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool
    {
        $metabase = new AA_Metabase();
        $metabase->loadFromDb(true);
        echo '<p>Copy the following into <code>include/metabase_schema.php</code> and commit it:</p>';
        echo '<textarea readonly rows="30" cols="120">' . safe($metabase->renderSchemaPhp()) . '</textarea>';
        exit;
    }
}
