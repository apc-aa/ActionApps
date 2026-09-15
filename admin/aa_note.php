<?php

/**
 * Notes endpoint - the POST target of AA\Util\Notes forms: add a note to a
 * resource, resolve a State Check finding, unresolve or delete a note; then
 * back to the page the form was on. No output of its own.
 *
 * PHP version 8.2+
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
 */

use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\Note;
use AA\Util\Notes;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    $action     = InputVars::stringPOST('action');
    $resource   = InputVars::stringPOST('resource');
    $text       = InputVars::stringPOST('text');
    $audience   = InputVars::stringPOST('audience');
    $until      = InputVars::stringPOST('until');
    $until_date = InputVars::stringPOST('until_date');
    $id         = InputVars::stringPOST('id');
    $return     = InputVars::stringPOST('return');
    $token      = InputVars::stringPOST('token');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!Notes::mayWrite()) {
    MsgPage(_m("You do not have permissions to write notes"));
    exit;
}
if (!FormToken::check(Notes::TOKEN, $token)) {
    MsgPage(_m('The form has expired; reload the page and try again.'));
    exit;
}

// back only to a page of this install - never to a foreign host (a scheme or host in the value, a header split)
$parts = parse_url($return);
if (!str_starts_with($return, (string) AA_INSTAL_PATH) || !is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || str_contains($return, '\\') || preg_match('/[\r\n]/', $return)) {
    $return = get_admin_url('index.php');
}
$owner = AA::$module_id ?: AA_ID;

switch ($action) {
    case 'add':
        if (trim($text) !== '' && $resource !== '') {
            Note::create($owner, $resource, $text, $audience);
        }
        break;
    case 'resolve':
        // only a superadmin decides what State Check stops showing
        if (IsSuperadmin() && str_starts_with($resource, 'finding:')) {
            Note::create($owner, $resource, $text, Note::AUDIENCE_ALL, Note::STATE_RESOLVED, Note::untilFromChoice($until, $until_date, now()));
        }
        break;
    case 'unresolve':
    case 'delete':
        $note = AA_Object::load($id, Note::class);
        if ($note instanceof Note && (IsSuperadmin() || $note->author === AuthGetUid())) {
            $note->setState($action === 'delete' ? Note::STATE_DELETED : Note::STATE_VALID);
        }
        break;
}

header('Location: ' . $return);
exit;
