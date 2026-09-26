<?php  //slice_id expected
/**
 *
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

// @todo only_action option which prints on the output the result of the action
// Then it could be used as AJAX call for this action!

use AA\Central\Actionapps;
use AA\Central\ManagerActionPolicy;
use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Manager;
use AA\Manageraction\Central_DeleteTrash;
use AA\Manageraction\Central_Linkcheck;
use AA\Manageraction\Central_MoveItem;
use AA\Manageraction\Central_Optimize;
use AA\Manageraction\Central_OptimizeType;
use AA\Manageraction\Central_Tab;
use AA\Manageraction\Manageractions;

use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__."/../central/include/actionapps.class.php";

try {
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }
$err = '';


/**
 * @return int[]
 */
function CountItemsInBins(): array
{
    return DB_AA::select(['folder'=>'cnt'], "SELECT CONCAT('folder', status_code) as folder, count(*) as cnt  FROM central_conf GROUP BY status_code");
}

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage ActionApps installations"));
}

// Handle JWT authentication request for a remote AA
$auth_aa_id = InputVars::stringPOST('authenticate_aa');
if ($auth_aa_id && ResponderPolicy::isForeignBrowserRequest($_SERVER)) {
    // a forged form would store the attacker's token for the AA
    $err = _m('The authentication was not performed - start it from the ActionApps Central page');
} elseif ($auth_aa_id) {
    $auth_username = InputVars::stringPOST('auth_username');
    $auth_password = InputVars::stringPOST('auth_password');
    if ($auth_username && $auth_password) {
        $aa  = Actionapps::getActionapps($auth_aa_id);
        $jwt = $aa->requestJwt($auth_username, $auth_password);
        if ($jwt) {
            Actionapps::saveAuthToken($auth_aa_id, 'jwt:' . $jwt);
            $Msg = _m("Successfully authenticated to %1", [$aa->getName()]);
        } else {
            $err = _m("Authentication failed for %1: %2",
                [$aa->getName(), $aa->getLastError()]);
        }
    }
}

// we do not manage more "modules" here, so unique id is OK
$module_id = '43656e7472616c2d41412d61646d696e';

$actions   = new Manageractions;
$actions->addAction(new Central_MoveItem('Activate', 1));
$actions->addAction(new Central_MoveItem('Folder2',  2));
$actions->addAction(new Central_MoveItem('Folder3',  3));
$actions->addAction(new Central_OptimizeType('Sqlupdate_Test',   'sql_update', 'test'));
$actions->addAction(new Central_OptimizeType('Sqlupdate_Update', 'sql_update', 'repair'));
$actions->addAction(new Central_Linkcheck('AA\Util\Linkcheck'));
$actions->addAction(new Central_Optimize('Update_Db_Structure_Test', 'AA\Optimize\UpdateDbStructure', 'test'));
$actions->addAction(new Central_Optimize('Update_Db_Structure_Repair', 'AA\Optimize\UpdateDbStructure', 'repair'));
$actions->addAction(new Central_Optimize('Field_Duplicates_Test', 'AA\Optimize\FieldDuplicates', 'test'));
$actions->addAction(new Central_Optimize('Field_Duplicates_Repair', 'AA\Optimize\FieldDuplicates', 'repair'));
$actions->addAction(new Central_DeleteTrash('DeleteTrashAction',true));

$switches  = new Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new Central_Tab('Tab1', 'app'));
$switches->addAction(new Central_Tab('Tab2', 'hold'));
$switches->addAction(new Central_Tab('Tab3', 'trash'));
$switches->addAction(new Central_DeleteTrash('DeleteTrash',false));

$managed = AA::Metabase()->getManagerConf('central_conf', $actions, $switches);
// $manager_settings['itemview']['query_funct'] = 'Central_QueryZids' (now AA\Central\Actionapps::queryIds());
$managed->setTitle(_m('ActionApps Central'));
//         'get_content_funct'    => 'Central_GetAaContent' (now AA\Central\Actionapps::loadContent())

$managed->addColumn('admin_link', _m('Admin Link'), '<a href="_#AA_HTTP_/_#AA_BASE_/admin">Admin</a>');

// Auth column: status badge + collapsible Authenticate form. AA_ADMI2 is the
// alias for AA_ADMIN_PWD (AA_ADMIN_USER takes _#AA_ADMIN first).
//   - Wrapping the alias as {_#AA_ADMI2} makes the engine evaluate it first
//     and pass the value as a single parameter (safe with ':' inside a JWT).
//   - {substr:...:0:3} extracts "jwt" / first-3-chars / "" so we can branch in {switch}.
//   - QuoteColons on the translated labels keeps any future translation containing ':' safe.
$auth_format = ''
    . '<div class="aa-auth-cell">'
    .   '<div>{switch:{substr:{_#AA_ADMI2}:0:3}:^jwt$:'
    .       QuoteColons(_m('Authenticated'))
    .     ':.:'
    .       QuoteColons(_m('Not authenticated - get a token'))
    .     ':'
    .       QuoteColons(_m('Not configured'))
    .   '}</div>'
    .   '<details>'
    .     '<summary>'._m('Authenticate').'</summary>'
    .     '<div>'
    .       '<label>'._m('Username').': <input type="text" class="aa-auth-user" size="15" autocomplete="username"></label> '
    .       '<label>'._m('Password').': <input type="password" class="aa-auth-pass" size="15" autocomplete="current-password"></label> '
    .       '<button type="button" onclick="aaCentralAuth(this, \'_#ID______\')">'._m('Get Token').'</button>'
    .     '</div>'
    .   '</details>'
    . '</div>';
$managed->addColumn('auth', _m('Auth'), $auth_format);

// the row id only - installapp.php takes the address from the row, never from the link
$managed->addColumn('install', _m('Apps'), '<a href="installapp.php?aa=_#ID______">' . QuoteColons(_m('Install an app from this AA...')) . '</a>');

$managed->setColumns2show(['ORG_NAME','AA_HTTP_DOMAIN','AA_BASE_DIR', 'admin_link', 'auth', 'install']);

// Edit form: keep ORG_NAME and the URL fields visible at top, hide the rest under "Optional".
$managed->setPriorityFields(['ORG_NAME', 'AA_HTTP_DOMAIN', 'AA_BASE_DIR']);
// status_code: 1 = active, 2 = hold, 3 = trash — render as a select, default 1 on Add.
$managed->setWidgetSetting('status_code', ['enum' => ['1' => '1', '2' => '2', '3' => '3'], 'default' => '1']);
$manager = new Manager('central', $managed);

// Manager takes akce from GET too; here actions reach remote AAs with the stored superadmin login.
// Manager reads $_REQUEST - pinned to POST/GET, so a cookie named akce cannot pass the check below.
$akce_id           = (string) ($_POST['akce'] ?? $_GET['akce'] ?? '');
$_REQUEST['akce']  = $akce_id;
if ($akce_id !== '' && ($akce = $actions->getAction($akce_id)) && !ManagerActionPolicy::mayPerform($_SERVER, (bool) $akce->getButton('', '', ''))) {
    unset($_GET['akce'], $_POST['akce'], $_REQUEST['akce']);
    $err = _m('The action was not performed - start it from the ActionApps Central page');
}
// the Empty trash switch deletes by a GET link of this page - like a row button
if (isset($_GET['DeleteTrash']) && !ManagerActionPolicy::mayPerform($_SERVER, true)) {
    unset($_GET['DeleteTrash'], $_REQUEST['DeleteTrash']);
    $err = _m('The action was not performed - start it from the ActionApps Central page');
}
$manager->performActions();

// need for menu
$r_state['bin_cnt'] = CountItemsInBins();

$apage = new AA_Adminpageutil('central',$manager->getBin());
$apage->setForm();

$html = $manager->getDisplayPage();

// JS helper for the per-row Authenticate button: builds & POSTs a hidden form.
// Required because the manager already wraps rows in <form>, and HTML5 disallows nested forms.
$html .= <<<'HTML'
<script>
function aaCentralAuth(btn, aaId) {
    var box  = btn.closest('details');
    var user = box.querySelector('.aa-auth-user').value;
    var pass = box.querySelector('.aa-auth-pass').value;
    var f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    function add(name, value) {
        var i = document.createElement('input');
        i.name = name; i.value = value;
        f.appendChild(i);
    }
    add('authenticate_aa', aaId);
    add('auth_username',   user);
    add('auth_password',   pass);
    document.body.appendChild(f);
    f.submit();
}
</script>
HTML;

// this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
$apage->printAllPage($html, $err, $Msg);

