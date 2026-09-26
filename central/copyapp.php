<?php

/**
 * Copy an app between AAs: the admin picks the source and the target AA, the page sends them to the
 * install page of the target (installapp.php), which pulls the app from the source. For this AA it goes
 * there; another AA gets a link to its own install page, where the admin logs in and picks the source.
 *
 * GET               the form
 * GET source,target this AA: redirect to installapp.php; another AA: the form with the link
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
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Central\Actionapps;
use AA\Central\Package\Importer;
use AA\Central\TransferLink;
use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

pageOpen();

try {
    $raw    = [InputVars::stringGET('source'), InputVars::stringGET('target')];
    $chosen = $raw !== ['', ''];
    [$source, $target] = $chosen ? array_map(TransferLink::id(...), $raw) : [TransferLink::THIS_AA, TransferLink::THIS_AA];
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// the same entry check as installapp.php, where every choice ends
if (!Importer::hasInstallPermission((string) AuthGetUid())) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m('You may not install apps (PS_INSTALL_APP).'), 'admin');
}
// another AA as the source needs its superadmin login in installapp.php - and the Central list is theirs
$rows = IsSuperadmin();
if (!$rows && (($source !== TransferLink::THIS_AA) || ($target !== TransferLink::THIS_AA))) {
    AA_Adminpageutil::MsgPageMenu(copyapp_url(), _m('Only a superadmin may copy apps from or to another AA.'), 'admin');
}

$here = constant('ORG_NAME') . ' (' . constant('AA_INSTAL_URL') . ')';
$link = null;
$err  = '';
if ($chosen) {
    try {
        $link = TransferLink::resolve($source, $target, static fn(int $id): ?Actionapps => Actionapps::find($id), $here);
    } catch (InvalidArgumentException) {
        $err = _m('No such AA in the Central list.');
    } catch (UnexpectedValueException) {
        $err = _m('The target AA has no valid http(s) address in the Central list.');
    }
    if ($link && !$link->remote) {
        go_url(StateUrl(get_url(self_base() . $link->url, $link->params)));
    }
}

$aas = [TransferLink::THIS_AA => _m('This AA') . ' - ' . $here];
foreach ($rows ? Actionapps::getArray() : [] as $id => $aa) {
    $aas[$id] = $aa->getName();
}

$apage = new AA_Adminpageutil('central', 'copyapp');
$apage->setTitle(_m('Copy an app between AAs'));
$apage->setForm();
$apage->printHead();

if ($err !== '') {
    echo '<div class="aa-err">' . safe($err) . '</div>';
}
if ($link) {
    $note = ($link->source === '')
        ? _m('Source and target are the same AA: choose that AA itself as the source there.')
        : _m('Choose the source there; it must be in that AA\'s Central list (%1).', [safe($link->source)]);
    echo '<div class="aa-info"><p>' . a_href($link->url, _m('Open the install page of %1', [safe($link->target)]), ['target' => '_blank', 'rel' => 'noopener']) . '</p><p>' . $note . '</p></div>';
}

// a GET form drops the query of its action - the state goes in a hidden field too
echo '<form method="get" action="' . safe(copyapp_url()) . '">' . StateHidden();
FrmTabCaption(_m('Copy an app between AAs'));
FrmInputSelect('source', _m('Source'), $aas, (string) $source, true, _m('The AA the app comes from.'));
FrmInputSelect('target', _m('Target'), $aas, (string) $target, true, _m('The AA the app is installed to. The same AA as the source makes a copy inside it.'));
FrmTabEnd();
echo '<p><button class="btn-l" type="submit">' . _m('Continue') . '</button></p></form>';

$apage->printFoot();

function copyapp_url(): string
{
    return StateUrl(get_url(self_base() . 'copyapp.php'));
}
