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

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

$no_slice_id   = isset($_REQUEST['template']) && isset($_REQUEST['template']['W']);       // message for init_page.php

// const  AA_REGISTER_GLOBALS = true;
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
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

$template_id = $template['W'] ? substr($template['W'],1) : unpack_id('SiteTemplate....');

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

$err = [];          // error array (Init - just for initializing variable
is_object( $db ) || ($db = getDB());

$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = $slice_id;

if ($template_id) {        // add module
    if (!CheckPerms( AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m("You have not permissions to add slice"));
        exit;
    }
} else {                    // edit module
    if (!CheckPerms( AuthGetUid(), "slice", $module_id, PS_MODW_SETTINGS)) {
        MsgPage(_m("You have not permissions to edit this slice"));
        exit;
    }
}



// additional settings - just make sure it is set
if (!$add) {
    AA_Module_Site::processModuleObject($module_id);
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
        ValidateInput("uses_modules", _m("Uses Slices"), $uses_modules, $err, false, "id");

        if (count($err)) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields(($update && $module_id) ? $module_id : false, $superadmin, 'W', $name, $slice_url, $priority, $lang_file, $owner, $deleted );

        if (!$module_id) {   // error?
            break;
        }
        AA::$module_id = $module_id;
        $slice_id      = $module_id;
        $p_module_id   = q_pack_id($module_id);

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
        if ( $update ) {
            $varset->clear();
            $varset->add("state_file",   "text",   $state_file);
            $varset->add("flag",         "number", $router);

            $SQL = "UPDATE site SET ". $varset->makeUPDATE() . " WHERE id='$p_module_id'";
            if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change site");
                break;
            }
        } else { // insert (add)
            $varset->clear();
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

            AA_Module_Site::processModuleObject($module_id);
        }
        AA::Pagecache()->invalidateFor($module_id);  // invalidate old cached values for this slice
    } while(false);

    if ( !count($err) ) {
        go_return_or_url(self_base() . "modedit.php", 0, 0);
    }
}


// And the form -----------------------------------------------------

$source_id   = ($template['W'] ? $template_id : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module specific data
$SQL= " SELECT * FROM site WHERE id='$p_source_id'";
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
$apage->setModuleMenu('modules/site');
$apage->setTitle($template['W'] ? _m("Add Site") : _m("Edit Site"));
$apage->addRequire(get_aa_url('/javascript/js_lib.min.js', ['v'=>AA_JS_VERSION], false));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire('codemirror@5');
$apage->setForm();
$apage->printHead($err, $Msg);

?>
<form name="f" method=post action="<?php echo StateUrl() ?>" onSubmit="return ModeditSubmit()">
<?php
    FrmTabCaption(_m("Site"), $form_buttons);

    ModuleFieldsForm($source_id, $marr = GetModuleFields($source_id), $superadmin, (bool)$template['W'], $new_owner, $new_owner_email);
    $lang_file = rtrim($marr[3],'3');   // trim for old php3 lang file extension

    //FrmInputText("slice_url", _m("URL"), $slice_url, 254, 60, false, _m("Main URL of the website."), '', 'false', 'https://www.example.org/');

    FrmInputSelect("lang_file", _m("Used Language File"), AA_Moduleregistry::info('W','langs'), $lang_file);
    FrmInputSelect("router",   _m("Router"), [1 => 'AA_Router_Seo'], $router, false, _m("Use AA_Router_Seo. Otherwise you will use old sitemodule approach with 'control file', which we can't recommend."));
    FrmInputText("state_file", _m("Home or State file"), $state_file, 99, 25, false, _m("For AA_Router_Seo fill the <b>default url</b> (home) - like <em>/en/home</em> or left it empty<br><br>For older sites without AA_Router_Seo fill in the name of <b>Site control file</b> - will be placed in /modules/site/sites/ directory. The name you specify will be prefixed by 'site_' prefix, so if you for example name the file as 'apc.php', the site control file will be /modules/site/sites/site_apc.php."), '', 'false', '/en/home');

    foreach ($g_modules as  $k => $v) {
        if (str_contains('SP', $v['type'])) {
            $slice_selection[$k] = $v['name'];
        }
    }

    //    $uses_modules = GetTable2Array("SELECT source_id, destination_id FROM relation WHERE source_id='".q_pack_id($module_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", "", 'unpack:destination_id');
    $uses_modules = AA_Module_Site::getRelatedModules([$module_id]);
    FrmTwoBox('uses_modules[]', _m('Uses slices') , $slice_selection, $uses_modules, 8, false, '', '', "Select all slices which you are using for the site. It is used for caching as well as for seo string search.");
    if ($module_id) {
        FrmStaticText(_m("Additional setting"), AA_Module_Site::getModuleObjectForm($module_id), '', '', false);
    }

    FrmTabEnd($form_buttons);

echo "\n </form>";

$apage->printFoot();
