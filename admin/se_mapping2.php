<?php

/**  se_mapping2.php - writes feed mapping to feedmap table
 *     expected $slice_id for edit slice
 *              $from_slice_id for id of imported slice
 *              $fmap - array of fields mapping
 *              $fval - array of field value
 *              $extslice
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


use AA\IO\FeedUtil;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";

try {
    // array ( '' => 2, 2 => 3, )
    $ext_slice = InputVars::intPOST('ext_slice'); // empty for a local slice
    // array ( )
    $fmap = InputVars::arrayPOST('fmap', true);
    // array ( '4429a8e44bb…' => 2, '164ced40da0…' => 3, )
    $from_slice_id = InputVars::idPOST('from_slice_id', true);
    // array ( )
    $fval = InputVars::arrayPOST('fval', true);
    $cancel = InputVars::boolPOST('cancel');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You do not have permissions to change feeding setting"));
    exit;
}

$p_from_slice_id = xpack_id($from_slice_id);

$err = [];       // error array (Init - just for initializing variable)

// save old values
[$map_to,$field_map] = FeedUtil::GetExternalMapping(AA::$module_id, $from_slice_id);

// First we DELETE current fields mapping and then INSERT new.
$db->query("DELETE FROM feedmap WHERE from_slice_id=$p_from_slice_id AND to_slice_id = '$p_slice_id' ");

// insert into feedmap - one INSERT per row: a left out from_field_id takes the column default, NULL or '' depending on the database
foreach ($fmap as $to_field_id => $val) {

    $feedmap = [
        'from_slice_id'   => $from_slice_id,
        'to_slice_id'     => AA::$module_id,
        'to_field_id'     => $to_field_id,
        'from_field_name' => (string) $map_to[$val],
    ];

    switch ($val) {
        case _m("-- Not map --"):
            $flag = FEEDMAP_FLAG_EMPTY;
            break;
        case _m("-- Value --"):
            $flag = FEEDMAP_FLAG_VALUE ;
            $feedmap['value'] = (string) $fval[$to_field_id];
            break;
        case _m("-- Joined fields --"):
            $flag = FEEDMAP_FLAG_JOIN;
            $feedmap['value'] = (string) $fval[$to_field_id];
            break;
        case _m("-- RSS field or expr --"):
            $flag = FEEDMAP_FLAG_RSS;
            $feedmap['value'] = (string) $fval[$to_field_id];
            if (! $map_to[$val]) {
                $feedmap['from_field_name'] = (string) $fval[$to_field_id];
            }
            unset($map_to[$val]);
            break;
        default:  // $val is the id of the source field
            $flag = ($ext_slice) ? FEEDMAP_FLAG_EXTMAP : FEEDMAP_FLAG_MAP ;
            $feedmap['from_field_id'] = $val;
            unset($map_to[$val]);
            break;
    }
    $feedmap['flag'] = $flag;

    if (!AA::Metabase()->doInsert('feedmap', $feedmap)) {
        $err["DB"] .= MsgErr("Can't add fields mapping");
    }
}

// Write external fields, which did not map.
if ($map_to && is_array($map_to)) {
    $unmapped = [];
    foreach (array_keys($map_to) as $from_field_id) {
        $unmapped[] = [
            'from_slice_id'   => $from_slice_id,
            'to_slice_id'     => AA::$module_id,
            'from_field_id'   => $from_field_id,
            'from_field_name' => $map_to[$from_field_id],
            'flag'            => FEEDMAP_FLAG_EXTMAP,
        ];
    }
    if (AA::Metabase()->doInsertMulti('feedmap', $unmapped) === false) {
        $err["DB"] .= MsgErr("Can't add fields mapping");
    }
}

go_url(StateUrl(self_base() . "se_mapping.php") . "&from_slice_id=" . rawurlencode($from_slice_id) . "&Msg=" . rawurlencode(_m("Fields' mapping update successful")));  // @todo Mssg
Session::page_close();
