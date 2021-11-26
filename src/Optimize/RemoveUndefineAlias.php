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
use AA\Optimize\AbstractOptimize;
use AA_Slice;
use function _m;


/** Remove _#UNDEFINE alias and default help.html for more help text in field
 *
 */
class RemoveUndefineAlias extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Remove _#UNDEFINE alias and default help.html for more help text in field");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("AA used to use _#UNDEFINE alias and some preset help text for field, which make little sense for real fields in real project. The help text and alias weill be removed.");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `alias1` FROM `field`', [
            [
                'alias1',
                "_#UNDEFINE",
            ],
        ])) {
            $this->message(_m('We found %1 old _#UNDEFINE aliases:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [
                    $field['id'],
                    $field['name'],
                    AA_Slice::getModuleName($field['uslice_id']),
                    $field['alias1'],
                ]));
            }
        } else {
            $this->message(_m('OK - There is no _#UNDEFINE alias'));
        }

        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_morehlp` FROM `field`', [
            [
                'input_morehlp',
                "%/help.html",
                'LIKE',
            ],
        ])) {
            $this->message(_m('We found %1 old help.html help link:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [
                    $field['id'],
                    $field['name'],
                    AA_Slice::getModuleName($field['uslice_id']),
                    $field['input_morehlp'],
                ]));
            }
        } else {
            $this->message(_m('OK - There is no old help.html help link'));
        }
        return true;
    }

    /** Repair function
     * @return bool
     */
    public function repair(): bool {
        //$metabase = AA::Metabase();
        $changed = DB_AA::sql("UPDATE field SET alias1='', alias1_help=''", [['alias1', "_#UNDEFINE"]]);
        $this->message(_m('Removed %1 _#UNDEFINE aliases', [$changed]));

        $changed = DB_AA::sql("UPDATE field SET input_morehlp=''", [['input_morehlp', "%/help.html", 'LIKE']]);
        $this->message(_m('Removed %1 old help links', [$changed]));
        return true;
    }

}


