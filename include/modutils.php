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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// Misc functions used with modules
use AA\IO\DB\DB_AA;

/** FormToken purpose of the delete forms on admin/slicedel.php, checked by slicedel2.php and moddelete.php */
const MODULE_DELETE_TOKEN = 'aa-module-delete';

/** CreateNewOwner function
 *  Adds new owner to database
 * @param $new_owner
 * @param $new_owner_email
 * @param $err
 * @param $varset
 * @return false|string - unpacked owner_id or false (on error)
 */
function CreateNewOwner($new_owner, $new_owner_email, &$err, $varset): false|string
{
    $varset->clear();
    ValidateInput("new_owner", _m("New Owner"), $new_owner, $err, true, "text");
    ValidateInput("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, $err, true, "email");

    if (count($err)) {
        return false;
    }

    $owner = new_id();

    // create new owner
    if (!AA::Metabase()->doInsert('slice_owner', ['id' => $owner, 'name' => $new_owner, 'email' => $new_owner_email])) {
        $err["DB"] .= MsgErr("Can't add owner");
        return false;
    }
    return $owner;
}

function ModuleFieldsForm($module_id, array $module_arr, bool $superadmin, bool $template = false, $new_owner = '', $new_owner_email = '', bool $expand_new_owner = false): void
{
    static $js_emitted = false;

    [$name, $slice_url, $priority, $lang_file, $owner, $deleted, $slice_owners ] = $module_arr;

    if ($template) {
        $name  = '';
        $owner = '';
    }

    // Id row is rendered by callers near the bottom of the form (see slicedit, polls, links, site)

    FrmInputText("name", _m("Title"), $name, 99, 0, true);
    FrmInputText("slice_url", _m("URL of .shtml page (often leave blank)"), $slice_url, 254, 0);
    FrmInputNumber("priority", _m("Priority (order in slice-menu)"), $priority);

    // Expand the inline new-owner form only when (a) the caller flags a redisplay-after-error
    // with no owner, or (b) the user already typed something into the new-owner fields.
    $expand = !$owner && ($new_owner !== '' || $new_owner_email !== '' || $expand_new_owner);
    $btn_add    = _m("+ Add new owner");
    $btn_cancel = _m("× Cancel");
    $owner_btn  = ' <button type="button" id="aa-new-owner-toggle" class="btn-l btn-secondary" onclick="aaToggleNewOwner()">'
        . safe($expand ? $btn_cancel : $btn_add) . '</button>';
    // Inject the toggle button inline into the owner select's cell (right after </select>)
    ob_start();
    FrmInputSelect("owner", _m("Owner"), $slice_owners, $owner);
    echo str_replace('</select>', '</select>' . $owner_btn, ob_get_clean());

    // The new-owner rows; AA_Inputfield::field_tr() already tags them with
    // .cont-new_owner and .cont-new_owner_email classes — we style + toggle via those.
    FrmInputText("new_owner", _m("New Owner"), $new_owner, 99);
    FrmInputEmail("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, 99);

    if (!$js_emitted) {
        // AA's Frm* helpers emit the label cell as <th>, not <td>, so target :first-child generically
        echo '<style>tr.cont-new_owner > :first-child, tr.cont-new_owner_email > :first-child { padding-left: 2em; color: #666; font-style: italic; }</style>' . "\n";
        echo '<script>
function aaToggleNewOwner() {
    const rows = document.querySelectorAll(".cont-new_owner, .cont-new_owner_email");
    const btn  = document.getElementById("aa-new-owner-toggle");
    const n    = document.querySelector(\'input[name="new_owner"]\');
    const e    = document.querySelector(\'input[name="new_owner_email"]\');
    if (!rows.length || !btn || !n || !e) return;
    const showing = rows[0].style.display !== "none";
    rows.forEach(r => { r.style.display = showing ? "none" : ""; });
    if (showing) {
        n.value = ""; e.value = "";
        btn.textContent = ' . json_encode($btn_add) . ';
    } else {
        const sel = document.querySelector(\'select[name="owner"]\');
        if (sel) sel.value = "";
        btn.textContent = ' . json_encode($btn_cancel) . ';
        n.focus();
    }
}
document.querySelector(\'select[name="owner"]\')?.addEventListener("change", (ev) => {
    if (!ev.target.value) return;
    const r = document.querySelector(".cont-new_owner");
    if (r && r.style.display !== "none") aaToggleNewOwner();
});
</script>' . "\n";
        $js_emitted = true;
    }

    // Initial collapsed state (per-form, synchronous → no FOUC)
    if (!$expand) {
        echo '<script>document.querySelectorAll(".cont-new_owner, .cont-new_owner_email").forEach(r => { r.style.display = "none"; });</script>' . "\n";
    }

    if ($superadmin) {
        FrmInputChBox("deleted", _m("Deleted"), $deleted);
    }
}

/** RenderModuleFieldsForm function
 *  Renders the standard module-fields form section (name, slice_url, priority,
 *  lang_file, owner, deleted) via {@see ModuleFieldsForm()}, preserving the
 *  user's submitted values when re-rendering after a validation error.
 *  Without preservation the form re-reads template/source-module data and the
 *  user has to re-type everything (see admin/slicedit.php, modules/polls,
 *  modules/links, modules/site - they all share this pattern).
 *
 * @param  string $source_id          source module id (existing module, or template when adding)
 * @param  bool   $superadmin         superadmin flag, controls Deleted checkbox
 * @param  bool   $is_new             true when this is the "add new module" form
 * @param  bool   $redisplay_posted   true when re-rendering after validation error
 * @param  array  $posted             POSTed module-field values, keyed by:
 *                                    'name', 'slice_url', 'priority', 'lang_file', 'owner', 'deleted'
 * @return array                      the $marr array used to render the form;
 *                                    callers needing the source's lang_file read $marr[3]
 */
function RenderModuleFieldsForm($source_id, bool $superadmin, bool $is_new, bool $redisplay_posted, array $posted, $new_owner = '', $new_owner_email = ''): array
{
    $marr = GetModuleFields($source_id);
    if ($redisplay_posted && $marr) {
        $marr = [$posted['name'], $posted['slice_url'], $posted['priority'], $posted['lang_file'], $posted['owner'], $posted['deleted'], $marr[6]];
    }
    // Expand the new-owner inline form on error redisplay when the user submitted no owner
    $expand_new_owner = $redisplay_posted && empty($posted['owner']);
    ModuleFieldsForm($source_id, $marr, $superadmin, $is_new && !$redisplay_posted, $new_owner, $new_owner_email, $expand_new_owner);
    return $marr;
}

/** ValidateModuleFields function
 * Validate all fields needed for module table (name, slice_url, lang_file, owner)
 * @param $name
 * @param $slice_url
 * @param $lang_file
 * @param $owner
 * @param $err
 */
function ValidateModuleFields($name, $slice_url, $priority, $lang_file, $owner, &$err): void
{
    ValidateInput("name", _m("Title"), $name, $err, true, "text");
    ValidateInput("owner", _m("Owner"), $owner, $err, false, "id");
    ValidateInput("slice_url", _m("URL of .shtml page (often leave blank)"), $slice_url, $err, false, "url");
    ValidateInput("priority", _m("Priority (order in slice-menu)"), $priority, $err, false, "number");
    ValidateInput("lang_file", _m("Used Language File"), $lang_file, $err, true, "text");
}

/** WriteModuleFields function
 *  Updates or inserts all necessary fields to module table
 *
 * @param  string|null  $module_id
 * @param               $superadmin
 * @param               $type
 * @param               $name
 * @param               $slice_url
 * @param               $priority
 * @param               $lang_file
 * @param               $owner
 * @param               $deleted
 * @param  string       $new_id
 *
 * @return string
 */
function WriteModuleFields(?string $module_id, $superadmin, $type, $name, $slice_url, $priority, $lang_file, $owner, $deleted, string $new_id = ""): ?string
{
    global $err;

    if ($module_id) {
        $module = ['id' => $module_id, 'name' => $name, 'slice_url' => $slice_url, 'priority' => $priority, 'lang_file' => $lang_file, 'owner' => $owner];
        if ($superadmin) {
            $module['deleted'] = $deleted;
        }

        if (!AA::Metabase()->doUpdate('module', $module, null, $module_id)) {  // not necessary - we have set the halt_on_error
            $err["DB"] = MsgErr("Can't change module");
            return null;
        }

        $GLOBALS['r_lang_file']      = stripslashes($lang_file);
        $GLOBALS['r_slice_view_url'] = stripslashes($slice_url);
    } else {  // insert (add)
        $module_id = ($new_id ?: new_id());   // sometimes we need specific
        // module_id (links module)
        $module = [
            'id'         => $module_id,
            'created_by' => AuthGetUid(),
            'created_at' => now(),
            'name'       => $name,
            'owner'      => $owner,
            'slice_url'  => $slice_url,
            'priority'   => $priority,
            'deleted'    => $deleted,
            'lang_file'  => $lang_file,
            'type'       => $type,
        ];

        if (!AA::Metabase()->doInsert('module', $module)) {
            $err["DB"] .= MsgErr("Can't add module");
            return null;
        }

        $GLOBALS['r_lang_file'] = stripslashes($lang_file);
        AddPermObject($module_id, "slice");    // no special permission added - only superuser can access
    }
    return $module_id;
}

/** GetModuleFields function
 *   fills variables from module and owners table
 * @param $source_id
 * @return array
 */
function GetModuleFields($source_id): array
{
    // lookup owners
    $slice_owners = [_m("Select owner")] + DB_AA::select(['uid' => 'name'], "SELECT LOWER(HEX(`id`)) AS uid, name FROM slice_owner", '', ['name']);
    $row = DB_AA::select1('', "SELECT name, slice_url, priority, lang_file, LOWER(HEX(`owner`)) AS owner, deleted FROM module", [['id', $source_id, 'l']]);
    // existing module -> its values; brand-new module with no source row (e.g. a
    // template-less add like Alerts) -> blank fields, so the add form still renders
    $arr   = is_array($row) ? array_values($row) : ['', '', '', '', '', ''];
    $arr[] = $slice_owners;
    return $arr;
}


/** ExitIfCantDelete function
 *  check if module can be deleted
 * @param $del
 */
function ExitIfCantDelete($del): void
{
    if (DB_AA::select1('', 'SELECT deleted FROM `module`', [['id', $del, 'l']]) === false) {
        MsgPage(_m("No such module."), get_admin_url('slicedel.php'));
    }
}

/** DeleteModule function
 *  delete module from module table
 * @param $del
 */
function DeleteModule($del): void
{
    DB_AA::sql('DELETE LOW_PRIORITY FROM `module`', [['id', $del, 'l']]);
}
