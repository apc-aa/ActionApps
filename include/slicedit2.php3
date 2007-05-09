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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/
/** set_template_id function
 * @param $template_id
 */
function set_template_id($template_id) {
    global $set_template_id, $change_lang_file;
    $set_template_id = $template_id;
    if ( $set_template_id ) {
      $foo = explode("{", $set_template_id);
      $set_template_id = $foo[0];
      $change_lang_file = $foo[1];
    }
}

// the wizard page has radio buttons instead of submit buttons
if ($template_slice_radio) {
    set_template_id($template_slice_radio == "slice" ? $template_id2 : $template_id);
} else {
    set_template_id($template_slice_sel["slice"] ? $template_id2 : $template_id);
}
?>