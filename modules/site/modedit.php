<?php
//$Id$
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

/* Params:
    $template['W'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

use AA\Central\AppStore;
use AA\Central\Package\DbSource;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use AA\Util\InputVars;

// the App store part of the form: a superadmin's only, and never by a forged form
const MODEDIT_APP_TOKEN = 'site-app-store';

// Creating a module: slicedit.php always sends no_slice_id, the template only when one exists already.
// init_page.php must know, or it falls back to a slice. Not $_REQUEST - that can include cookies.
$no_slice_id   = (bool) (($_GET['no_slice_id'] ?? $_POST['no_slice_id'] ?? null) ?: ($_GET['template']['W'] ?? $_POST['template']['W'] ?? null));

require_once __DIR__ . "/../../include/init_page.php";
//require_once __DIR__."/../../include/en_site_lang.php";
require_once __DIR__ . "/../../include/formutil.php";

require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/date.php";
require_once __DIR__ . "/../../include/modutils.php";

try {
    // array ( )
    $aa = InputVars::arrayPOST('aa'); // alnum
    // array ( 1 => 1, )
    $add = InputVars::boolPOST('add');
    // array ( 'on' => 5, )
    $deleted = InputVars::boolPOST('deleted');
    // array ( ' Insert [^…' => 1, )
    $insert = InputVars::boolPOST('insert');
    // array ( 'en-utf8_sit…' => 5, )
    $lang_file = InputVars::stringPOST('lang_file');
    // array ( ' \' " / \\ �…' => 5, )
    $name = InputVars::stringPOST('name');
    // array ( ' \' " / \\ �…' => 1, )
    $new_owner = InputVars::stringPOST('new_owner');
    // array ( ' \' " / \\ �…' => 1, )
    $new_owner_email = InputVars::stringPOST('new_owner_email');
    // array ( 1 => 2, )
    $no_slice_id = InputVars::boolGET('no_slice_id');
    // array ( '41415f436f7…' => 5, )
    $owner = InputVars::idPOST('owner');
    // array ( 7777 => 4, 33 => 1, )
    $priority = InputVars::intPOST('priority');
    // array ( 1 => 5, )
    $router = InputVars::boolPOST('router');
    // array ( 'https://exa…' => 1, 'https://e/e…' => 4, )
    $slice_url = InputVars::stringPOST('slice_url');
    // array ( '' => 5, )
    //$spot_id = InputVars::intPOST('spot_id'); // empty
    // array ( ' \' " / \\ �…' => 2, ' \\\' \\" / \\\\…' => 1, ' \\\\\\\' \\\\\\" …' => 1, ' \\\\\\\\\\\\\\\' \\…' => 1, )
    $state_file = InputVars::stringPOST('state_file');
    // array ( )-array ( )
    $template = InputVars::arrayGETPOST('template'); // alnum-alnum
    // array ( 1 => 4, )
    $update = InputVars::boolPOST('update');
    // array ( )
    $uses_modules = InputVars::arrayPOST('uses_modules');
    // array ( '41415f436f7…' => 1, )
    // $uses_modules_1 = InputVars::idPOST('uses_modules_1');  // ignore - not needed here
    // array ( 'P' => 5, )
    // $uses_modulesp = InputVars::stringPOST('uses_modulesp'); // alpha   - ignore - not needed here
    $cancel = InputVars::boolPOST('cancel');
    $app_store       = InputVars::stringPOST('app_store');     // its form token - the part was on the form
    $app_publish     = InputVars::boolPOST('app_publish');
    $app_description = InputVars::stringPOST('app_description');
    $app_version     = InputVars::stringPOST('app_version');
    $app_approve     = InputVars::boolPOST('app_approve');
    
    $Msg = InputVars::messageGETPOST();
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }

$template_id = $template['W'] ? substr($template['W'],1) : unpack_id('SiteTemplate....');

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

$err = [];          // error array (Init - just for initializing variable
if (!is_object( $db )) {
    $db = getDB();
}

$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = $slice_id;

if ($template['W']) {        // add module
    if (!CheckPerms( AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m("You do not have permissions to add slice"));
        exit;
    }
} elseif (!CheckPerms( AuthGetUid(), "slice", $module_id, PS_MODW_SETTINGS)) {
    // edit module
    MsgPage(_m("You do not have permissions to edit this slice"));
    exit;
}



// additional settings - just make sure it is set
if (!$add && ($settings_error = AA_Module_Site::processModuleObject($module_id))) {
    $err['settings'] = MsgErr($settings_error . ' ' . _m('The settings and the other fields of this page were not saved.'));
}

if ($add || $update) {
    do {
        if (!$owner ) {  // insert new owner
            if (!($owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset))) {
                break;
            }
        }
        // validate all fields needed for module table (name, slice_url, lang_file, owner)
        ValidateModuleFields( $name, $slice_url, $priority, $lang_file, $owner, $err );
        $deleted          = ( $deleted  ? 1 : 0 );

        // now validate all module specific fields
        ValidateInput("state_file",   _m("State file"),  $state_file,   $err, false, "text");
        ValidateInput("router",       _m("Router"),      $router,       $err, false, "number");
        ValidateInput("uses_modules", _m("Uses modules"), $uses_modules, $err, false, "id");

        // only modules the user may use (the offered list - $g_modules) - a site using a module grants it
        // Alertitem's same-site rule. A module related already may stay, whoever added it (the form shows
        // it selected, also one the user has no rights to); dropping any is fine
        if (!isset($err['uses_modules'])) {
            $uses_modules    = array_map('strtolower', (array) $uses_modules);
            $related_modules = ($update && $module_id) ? DB_AA::select('', 'SELECT LOWER(HEX(`destination_id`)) AS unpackid FROM relation', [['source_id', $module_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]) : [];
            $refused         = array_filter($uses_modules, static fn($id) => (($g_modules[$id]['type'] ?? 'W') === 'W') && !in_array($id, $related_modules, true));
            if ($refused) {
                $err['uses_modules'] = MsgErr(_m('You can not use these modules in the site - you have no permission to them: %1', [safe(implode(', ', $refused))]));
            }
        }

        if (count($err)) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields(($update && $module_id) ? $module_id : null, $superadmin, 'W', $name, $slice_url, $priority, $lang_file, $owner, $deleted );

        if (!$module_id) {   // error?
            break;
        }
        AA::$module_id = $module_id;
        $slice_id      = $module_id;
        $p_module_id   = xpack_id($module_id);

        // store used modules into relation table
        DB_AA::delete('relation', [['source_id', $module_id, 'l'],['flag',REL_FLAG_MODULE_DEPEND,'i']]);

        if (is_array($uses_modules)) {
            $data = [];
            foreach ($uses_modules as $rel_slice_id) {
                $data[] = ["source_id" => $module_id, "destination_id" => $rel_slice_id, "flag" => REL_FLAG_MODULE_DEPEND];
            }
            AA::Metabase()->doInsertMulti('relation', $data);
        }

        // now set all module specific settings
        $varset->clear();
        if ( $update ) {
            $varset->add("state_file",   "text",   $state_file);
            $varset->add("flag",         "number", $router);

            $SQL = "UPDATE site SET ". $varset->makeUPDATE() . " WHERE id=$p_module_id";
            if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change site");
                break;
            }
        } else { // insert (add)
            // prepare varset variables for setFromArray() function
            $varset->addArray(['id','structure','state_file'], ['flag']);

            if (!($site = DB_AA::select1('', 'SELECT * FROM `site`', [['id', $template_id, 'l']]))) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->resetFromRecord($site);
            $varset->set("id",         $module_id,        "unpacked");
            $varset->set("state_file", $state_file,       "text");
            $varset->set("flag",       $router,           "number");

            // create new site
            $varset->doInsert('site');

            // copy spots
            $sitespots = DB_AA::select([], 'SELECT spot_id, content FROM `site_spot`', [['site_id', $template_id, 'l']]);
            foreach ($sitespots as $spot) {
                $varset->clear();
                $varset->set("site_id", $module_id, "unpacked" );
                $varset->set("spot_id", $spot['spot_id'], "number" );
                $varset->set("content", $spot['content'], "text" );
                $varset->set("flag", "", "number" );
                $varset->doInsert('site_spot');
            }

            if ($settings_error = AA_Module_Site::processModuleObject($module_id)) {
                $err['settings'] = MsgErr($settings_error);
                break;
            }
        }
        if ($update && $superadmin && ($app_store !== '')) {
            if (!FormToken::check(MODEDIT_APP_TOKEN, $app_store)) {
                $err['app_store'] = MsgErr(_m('The form has expired; reload the page and try again.'));
                break;
            }
            if (($app_input = AA_Modulesettings_Site::appInputError($app_description, $app_version)) !== null) {
                $err['app_store'] = MsgErr($app_input);
                break;
            }
            // refused now, not by the first AA that tries to install it
            $app_errors = $app_publish ? AppStore::exportErrors($module_id) : [];
            if ($app_errors) {
                $lines = array_map(static fn(array $f): string => '<li>' . safe($f[1]->code . ' ' . $f[0] . ' ' . $f[1]->ref) . '</li>', array_slice($app_errors, 0, 50));
                $err['app_store'] = MsgErr(_m('The app store settings were not saved - the store cannot give the site until these errors are fixed:') . '<ul>' . implode('', $lines) . '</ul>');
                break;
            }
            // publishing approves the modules the site uses; one added later only by its own checkbox
            $app_stored = AA_Module_Site::getAppSettings($module_id);
            $app_used   = (new AppStore(new DbSource()))->modulesOf($module_id);
            AA_Module_Site::saveAppSettings($module_id, $app_publish, $app_description, $app_version, AppStore::approvedModules($app_stored['publish'] ? $app_stored['modules'] : null, $app_used, $app_approve));
        }
        AA::Pagecache()->invalidateFor($module_id);  // invalidate old cached values for this slice
    } while(false);

    if ( !count($err) ) {
        go_return_or_url(self_base() . "modedit.php", 0, 0);
    }
}


// And the form -----------------------------------------------------

$source_id   = ($template['W'] ? $template_id : $module_id );
$p_source_id = xpack_id( $source_id );

// load module specific data
$SQL= " SELECT * FROM site WHERE id=$p_source_id";
$db->query($SQL);
if ($db->next_record()) {
    RestoreVariables($db->record());
}
$id     = unpack_id($db->f("id"));  // correct ids
$router = $db->f("flag");           // router is stored as flag

$module = AA_Module_Site::getModule($source_id);

if ( $template['W'] ) {
    $form_buttons = [
        'insert',
        'template[W]' => ['type'=>"hidden", 'value'=> $template['W']],
        'add'         => ['type'=>"hidden", 'value'=> 1],
    ];
} else {
    $form_buttons = [
        'update',
        "update"  => ['type' => 'hidden', 'value'=>'1'],
    ];
}


$headcode = <<<'EOT'
<script>
    function ModeditSubmit() {
        var lb;
        for (var i = 0; i < listboxes.length; i++) {
           lb = listboxes[i];
           for (var j = 0; j < document.f[lb].length; j++) {
               document.f[lb].options[j].selected = ( document.f[lb].options[j].value !== "wIdThTor" );
           }
        }
        return true;
    }
</script>
EOT;


$apage = new AA_Adminpageutil('modadmin', 'main');
$apage->setTitle($template['W'] ? _m("Add Site") : _m("Edit Site"));
$apage->addRequire(get_aa_url('/javascript/js_lib.min.js', ['v'=>AA_JS_VERSION], false));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire('codemirror@5');
$apage->setForm();

$object_frm = AA_Module_Site::getModuleObjectForm($module_id);  // must be before printHead - this allows to automatically load codemirror/select2 in the head (printHead is called after $form->getObjectEditHtml)

$apage->printHead($err, $Msg);

?>
<form name="f" method=post action="<?php echo StateUrl() ?>" onSubmit="return ModeditSubmit()">
<?php
    FrmTabCaption(_m("Site"), $form_buttons);

    $marr = RenderModuleFieldsForm($source_id, $superadmin, (bool)$template['W'], (bool)count($err), compact('name', 'slice_url', 'priority', 'lang_file', 'owner', 'deleted'), $new_owner, $new_owner_email);
    $lang_file = rtrim($marr[3],'3');   // trim for old php3 lang file extension

    //FrmInputText("slice_url", _m("URL"), $slice_url, 254, 60, false, _m("Main URL of the website."), '', 'false', 'https://www.example.org/');

    FrmInputSelect("lang_file", _m("Used Language File"), AA_Moduleregistry::info('W','langs'), $lang_file);
    FrmInputSelect("router",   _m("Router"), [1 => 'AA_Router_Seo'], $router, false, _m("Use AA_Router_Seo. Otherwise you will use old sitemodule approach with 'control file', which we can't recommend."));
    FrmInputText("state_file", _m("Home or State file"), $state_file, 99, 25, false, _m("For AA_Router_Seo fill the <b>default url</b> (home) - like <em>/en/home</em> or leave it empty<br><br>For older sites without AA_Router_Seo fill in the name of <b>Site control file</b> - will be placed in /modules/site/sites/ directory. The name you specify will be prefixed by 'site_' prefix, so if you for example name the file as 'apc.php', the site control file will be /modules/site/sites/site_apc.php."), '', 'false', '/en/home');

    // Offer every module type except sites (W): a site can attach any other
    // module - slice, polls, alerts, links, jump, ... - via REL_FLAG_MODULE_DEPEND.
    $module_selection = [];
    foreach ($g_modules as $k => $v) {
        if ($v['type'] !== 'W') {
            $module_selection[$k] = $v['name'];
        }
    }

    //    $uses_modules = GetTable2Array("SELECT source_id, destination_id FROM relation WHERE source_id='".q_pack_id($module_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", "", 'unpack:destination_id');
    $uses_modules = AA_Module_Site::getRelatedModules([$module_id]);
    FrmTwoBox('uses_modules[]', _m('Uses modules'), $module_selection, $uses_modules, 8, false, '', '', "Select all modules which you are using for the site. It is used for caching as well as for seo string search.");
    if ($module_id) {
        FrmTabSeparator(_m("Additional setting"));
        echo $object_frm;
    }
    if ($module_id && $superadmin) {
        $app = AA_Module_Site::getAppSettings($module_id);
        FrmTabSeparator(_m("App store"));
        echo '<tr><td colspan="2"><input type="hidden" name="app_store" value="' . safe(FormToken::get(MODEDIT_APP_TOKEN)) . '">'
            . _m('A published site can be installed by any AA from this one - with the slices and Alerts it uses: their templates, fields, views and constants, never the items. Only a superadmin sets this.') . '</td></tr>';
        $app_added = (new AppStore(new DbSource()))->site($module_id)['added'] ?? [];
        if ($app_added) {
            $app_names = implode(', ', array_map(static fn(string $id): string => AA_Module::getModuleNameSafe($id), $app_added));
            echo '<tr><td colspan="2"><div class="aa-err">' . _m("The site's modules changed since publishing: %1 - the app store does not give the site until a superadmin approves them.", [$app_names]) . '</div></td></tr>';
            FrmInputChBox('app_approve', _m("Approve the changed modules: %1", [$app_names]), false, false, '', 1, false, _m("Their templates, fields, views and constants then go to the app store with the site."));
        }
        FrmInputChBox('app_publish', _m("Publish in the app store"), $app['publish'], false, '', 1, false, _m("The published app has no warnings: every warning of its export is an error. A constant group owned by a slice outside every published site stops it too."));
        FrmTextarea('app_description', _m("App description"), $app['description'], 4, 60, false, _m("What the app does, for the list of the app store."), '', false, false, AA_Modulesettings_Site::APP_DESCRIPTION_MAX);
        FrmInputText('app_version', _m("App version"), $app['version'], 32, 12, false, _m("Like 1.2.0."), '', false, '1.0.0');
    }

    FrmTabEnd($form_buttons);

echo "\n </form>";

$apage->printFoot();
