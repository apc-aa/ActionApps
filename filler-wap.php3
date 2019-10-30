<?php
/**
 * Script for submitting items anonymously, without accessing the admin interface
 *
 * See documentation in doc/anonym.html.
 *
 * Parameters (usually from a HTML form):
 * <pre>
 *   my_item_id   - item id, used when editing (not adding a new) item in the
 *                  anonymous form
 *   slice_id     - id of slice into which the item is added
 *   notvalidate  - if true, data input validation is skipped
 *   ok_url       - url where to go, if item is successfully stored in database
 *   err_url      - url where to go, if item is not stored in database (due to
 *                  validation of data, ...)
 *   force_status_code - you may add this to force to change the status code
 *                       but the new status code must always be higher than bin2fill
 *                       setting (you can't add to the Active bin, for example)
 *   notshown[] - array (form field ID => 1) of unpacked IDs, e.g. v7075626c6973685f646174652e2e2e2e
 *                which are shown in the control panel but not in the anonym form
 *   bool use_post2shtml If true, use the post2shtml script to send the error
 *          description and the values filled to fillform.php3.
 *   bool text_password If true, the password is stored in text form (not encrypted).
 * </pre>
 *
 * @package UserInput
 * @version $Id$
 * @author Honza Mal�k, Jakub Ad�mek, Econnect
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

$GLOBALS['wap'] = true;

require_once "filler.php3";


