<?php
/**
 * Mailman feature related functions:
 * Event handlers.
 *
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
 * @package   ReaderInput
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."files.class.php3";  // file wrapper for {include};

class AA_Mailman {

    /** AA_Mailman::createSynchroFiles function
     *   Finds all reader-mailing list assignments for the given slice.
     *   Creates one file for each mailing list, named the same as the list.
     *   If no users are subscribed to a list, an empty file is created.
     */
    public static function createSynchroFiles($slice_id) {
        global $MAILMAN_SYNCHRO_DIR;

        if (! @is_dir($MAILMAN_SYNCHRO_DIR)) {
            return;
        }
        endslash($MAILMAN_SYNCHRO_DIR);

        $slice = AA_Slice::getModule($slice_id);
        $field = $slice->getProperty("mailman_field_lists");
        if ($slice->getProperty("type") != "ReaderManagement" || ! $field) {
            return;
        }

        // Add empty mailing lists
        if ($group_id = GetCategoryGroup($slice_id, $field)) {
            $maillists = array_keys(GetConstants($group_id));
            foreach ($maillists as $listname) {
                self::_createOneFile($slice_id, $field, $listname);
            }
        }
    }


    /** AA_Mailman::_createOneFile function
     *   Creates one file for each mailing list, named the same as the list.
     */
    private static function _createOneFile($slice_id, $field_id, $listname) {
        global $MAILMAN_SYNCHRO_DIR;
        if ($slice_id AND $field_id AND $listname) {
            $mails = join("\n",AA_Validate::filter(explode('|',str_replace(array(' ',"\t"),array('',''),AA_Stringexpand::unalias("{item:{ids:$slice_id:d-$field_id-=-$listname}:".FIELDID_EMAIL.":|}"))), 'email'));

            if ($fd = fopen($MAILMAN_SYNCHRO_DIR.$listname, "w")) {
               fwrite ($fd, $mails);
               fclose ($fd);
            }
        }
    }



    // --------------------------------------------------------------------------
    /** constantsChanged function
     * @param $constant_id
     * @param $oldvalue
     * @param $newvalue
     */
    function constantsChanged( $constant_id, $oldvalue, $newvalue ) {
        $db = getDB();
        $db->query("SELECT group_id FROM constant WHERE id='".q_pack_id($constant_id)."'");
        if (!$db->next_record()) {
            return;
        }
        $group_id = $db->f("group_id");
        $db->query("SELECT slice.id FROM slice
            INNER JOIN field ON field.slice_id = slice.id
            WHERE slice.type = 'ReaderManagement'
            AND (field.input_show_func LIKE '___:$group_id:%'
            OR  field.input_show_func LIKE '___:$group_id')");
        $slices = array();
        while ($db->next_record()) {
            $slices[] = unpack_id($db->f("id"));
        }
        foreach ($slices as $slice_id) {
            AA_Mailman::createSynchroFiles($slice_id);
        }
        freeDB($db);
    }
}

?>