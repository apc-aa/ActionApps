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

// APC AA site Module main administration page
use AA\Cache\Cacheentry;
use AA\Cache\CacheStr2find;
use AA\Cache\PageCache;
use AA\IO\DB\DB_AA;

require_once __DIR__."/../../include/config.php3";
require_once __DIR__."/../../include/locsess.php3";
require_once __DIR__."/../../include/zids.php3";

require_once __DIR__."/../../include/locauth.php3";

$auth4cache = '';
if ($_COOKIE['AA_Session'] AND !isset($_REQUEST['username'])) {   // do not authenticate new user - we could restrict reader slices later
    pageOpen('nobody');   // define $sess and $auth; login user, if (s)he tries to; "nobody" allowed
    $auth4cache = is_object($auth) ? $auth->auth['uname'] : '';
}

// -- CACHE -------------------------------------------------------------------
// CACHE_TTL defines the time in seconds the page will be stored in cache
// (Time To Live) - in fact it can be infinity because of automatic cache
// flushing on page change

/** Create keystring from values, which exactly identifies resulting content */
//  25June03 - Mitra - added post2shtml into here, maybe should add all URL?
//  25Sept03 - Honza - all apc_state is serialized instead of just
//       $apc_state['state'] (we store browser agent in state in kormidlo.cz)
//  28Apr05  - Honza - added also $all_ids, $add_disc, $disc_type, $sh_itm,
//                     $parent_id, $ids, $sel_ids, $disc_ids - for discussions
//                      - it is in fact all global variables used in view.php3
$cache_key = get_hash('site', PageCache::globalKeyArray(), $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['REDIRECT_URL'],  $_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'],$_SERVER['QUERY_STRING_UNESCAPED'], get_authorization_header(), $_POST, $_GET, $auth4cache);  // $_GET because $_SERVER['REQUEST_URI'] do not contain variables from Rewrite (site_id); *_STRING_UNESCAPED is for old sitemodule - see shtml_query_string(); AA\Cache\PageCache::globalKeyArray - now only for _COOKIES and HTTP_HOST

// store nocache to the variable (since it should be set for some view and we
// do not want to have it set for whole site.
// temporary solution - should be solved on view level (not global nocache) - TODO
$site_nocache = ($_REQUEST['nocache'] OR isset($_POST['aa']) OR (isset($_REQUEST['username']) AND isset($_REQUEST['password'])) OR $_REQUEST['logout']);

if ($cacheentry = AA::Pagecache()->getPage($cache_key,$site_nocache)) {
    $cacheentry->processPage(1);
    if ( AA::$debug ) {
        echo '<br><br>Site cache hit!!!';
        echo '<br>Page generation time: '. timestartdiff();
        echo '<br>Dababase instances: '. DB_AA::$_instances_no;
        echo '<br>  (spareDBs): '. count($spareDBs);
        AA::$dbg->duration_stat();
    }
    exit;
}

// -- /CACHE ------------------------------------------------------------------

require_once __DIR__."/../../include/util.php3";

/**
 * @param $domain
 * @return bool
 */
function IsInDomain( $domain ) {
    return (($_SERVER['HTTP_HOST'] == $domain)  || ($_SERVER['HTTP_HOST'] == 'www.'.$domain));
}

/**
 * @param null $page
 */
function Die404($page=null) {
    function_exists('http_response_code') ? http_response_code(404) : header( ($_SERVER['SERVER_PROTOCOL'] ?: 'HTTP/1.0'). ' 404 Not Found');
    if (!is_null($page)) {
        header('Content-Type: '. (AA::$headers['type'] ?: 'text/html') .'; charset='.(AA::$headers['encoding'] ?: AA::$encoding ?: AA_Langs::getCharset()));
        echo AA::Stringexpander()->postprocess(AA::Stringexpander()->unalias($page));
    }
    //    else { echo '<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>'; }
    exit;
}
// ----------------- function definition end -----------------------------------

// change the state
add_vars();                 // get variables pased to stm page

require_once __DIR__."/../../modules/site/router.class.php";

$err = [];          // error array (Init - just for initializing variable

AA::$site_id  = $_REQUEST['site_id'] ?: AA_Module_Site::getIdFromUrl();

if ( !($module = AA_Module_Site::getModule(AA::$site_id)) ) {
    Die404();
    exit;
}

$lang_file    = $module->getProperty('lang_file');
AA::setEncoding($module->getCharset());

// @deprecated - use AA_Router_Seo instead
// There are two possibilities, how to control the apc_state variable. It could
// be se in ./modules/site/sites/site_...php control file. The control file
// could be managed only by people, who have the access to the AA sources on the
// server. If we want to permit control of the site to extenal people, which do
// not have access to AA scripts directory, then it is possible to them to not
// fill "site control file" in site configuration dialog and then call this
// script from their own file, where the new $apc_state, $slices4cache and
// $site_id will be defined and passed by GET method. Just like this:
//
//    $url  = 'https://example.org/apc-aa/modules/site/site.php3?';
//    $url .= http_build_query( array( 'apc_state'    => $apc_state,
//                                     'slices4cache' => $slices4cache,
//                                     'site_id'      => 'ae54378beac7c7e8a998e7de8a998e7a'
//                                    ));
//    readfile($url);
// /@deprecated - use AA_Router_Seo instead

$hit_lid = null;

// auth people from: Reader slice / Reader slices / AA + Reader slices
//
// perm_mode 1 - first Reader Management slice listed in Uses slices
//           2 - all Reader Management slices listed in Uses slices
//           3 - AA + all Reader Management slices in AA (old behavior)
//           First Reader Management slice is encouraged option - only the
//           people from this slice is allowed to login to public pages.
//           If not selected, "AA + all Reader Management slices" option is used.
$perm_mode  = (string)$module->getProperty('perm_mode');
if ( ($perm_mode == '1') OR ($perm_mode == '2') ) {
    $rm_slices = $module->getRelatedSlices('ReaderManagement');
    if ($perm_mode=='1') {
        $rm_slices = array_slice($rm_slices,0,1);
    }
    AA::$perm->restrictPermsTo($rm_slices);
}

if ($ga_id = (string)$module->getProperty('ga_id')) {
    $ga_code = "<script async src=\"https://www.googletagmanager.com/gtag/js?id=$ga_id\"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '$ga_id');
    </script>";
    AA::Stringexpander()->addRequire($ga_code, 'AA_Req_Headcode');
}


if ($module->getProperty('flag') == 1) {    // 1 - Use AA_Router_Seo

    if (!is_object($sess)) {  // could be already opened above
        pageOpen('nobody');   // define $sess and $auth; login user, if (s)he tries to; "nobody" allowed
    }

    $router = $module->getRouter();

    // use REDIRECT_URL for homepage redirects:
    //    RewriteRule ^/?$ /en/home [QSA]
    //    RewriteRule ^/?en /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]

    // or (I think better)
    //    RewriteEngine on
    //    RewriteRule ^/?$  /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]
    //    RewriteRule ^/?en /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]

    $uri          = (strlen($_SERVER['REQUEST_URI']) > 1) ? $_SERVER['REQUEST_URI'] : $_SERVER['REDIRECT_URL'];
    $apc_state    = $router->parse($uri);
    $lang_file    = substr_replace($lang_file, $apc_state['xlang'], 0, 2);

    // count hit for current page - deffered after the page is sent to user
    if (!($hit_lid = $router->xid())) {
        if ($module->getProperty('page404') == '2') {
            Die404();
            exit;
        }
        if ($module->getProperty('page404') == '3') {
            Die404($module->getProperty('page404_code'));
            exit;
        }
        // else - older behavior - site cares
    }
    //    } elseif ( $uri AND $router->xid(null, "/$lang_file/_404-not-found")) {  // item not found => 404
    //        $apc_state = $router->parse("/$lang_file/_404-not-found");
} elseif ( $module->getProperty('state_file') ) {
    // in the following file we should define apc_state variable
    require_once "legacy_util.php";
    require_once __DIR__."/sites/site_".$module->getProperty('state_file');
    $apc_state['4cacheQS'] = shtml_query_string();
    $_REQUEST['nocache'] = $_REQUEST['nocache'] ?: $nocache;
}

if ( !isset($apc_state) )  {
    Die404(($module->getProperty('page404') == '3') ? $module->getProperty('page404_code') : null);
    exit;
}

require_once __DIR__."/../../modules/site/util.php3";                      // module specific utils
require_once __DIR__."/../../modules/site/sitetree.php3";
require_once __DIR__."/../../include/searchlib.php3";
require_once __DIR__."/../../include/view.php3";
require_once __DIR__."/../../include/discussion.php3";
require_once __DIR__."/../../include/item.php3";

if ($lang_file) {
    mgettext_bind(AA_Langs::getLang($lang_file), 'output');
    AA::$lang    = strtolower(substr($lang_file,0,2));   // actual language - two letter shortcut cz / es / en
    AA::$langnum = [AA_Langs::getLangName2Num(AA::$lang)];   // array of prefered languages in priority order.
}



$is_valid = ($perm_alias = $module->getProperty('perm_alias')) ? AA::Stringexpander()->unalias("{item:$hit_lid:{($perm_alias)}}") : 'Valid';


// --- Process all AJAX commandes and possibly EXIT ---------------------

$content_function = function () use (&$module, &$apc_state) {
    // we need to evaluate $page_content in order to reevaluate DependentParts
    $apc_state['xajax'] = 2;  // to not be 1
    return $module->getSite( $apc_state );
};

$page_changer = new \AA\IO\OnPageChanger($content_function);
$page_changer->checkAndProcessCall($is_valid == 'Valid');

// --- if not EXITEed above, continue


$page_content = ($is_valid == 'Valid') ? $module->getSite( $apc_state ) : AA::Stringexpander()->postprocess(AA::Stringexpander()->unalias($module->getProperty('loginpage_code')));

// AJAX check
if ((AA::$headers['encoding'] != 'utf-8') && (AA::$encoding != 'utf-8') && IsAjaxCall()) {
    $page_content = ConvertCharset::singleton()->Convert($page_content, AA::$encoding, 'utf-8');
    AA::$headers['encoding'] = 'utf-8';
}

$cacheentry = new Cacheentry($page_content, AA::getHeaders(), $hit_lid, ((is_object($auth) AND $auth->is_user()) ? 'private' : 'public'));
$cacheentry->processPage(0);

// changed the way, how to get $slices4cache - now we ask for module ids realy
// used during page generation. Honza 2015-12-29
$slices4cache = AA_Module::getUsedModules();
if (empty($slices4cache)) {    // can't be combined empty() and assignment = for php 5.3
    // probably not necessary
    $slices4cache = [AA::$site_id];
}

// do not cache for logged users
if (!$site_nocache AND empty($apc_state['xuser'])) {
    $str2find = new CacheStr2find($slices4cache);
    AA::Pagecache()->storePage($cache_key, $cacheentry, $str2find);
}

if (AA::$debug&2) {
    echo '<br><br>Site cache MIS!!!';
    echo '<br>Page generation time: '. timestartdiff();
    echo '<br>Dababase instances: '. DB_AA::$_instances_no;
    echo '<br>  (spareDBs): '. count($spareDBs);
    echo '<br>UsedModules:<br> - '. join('<br> - ', array_map(function($mid) {return AA_Module::getModuleName($mid);}, $slices4cache));
    AA::$debug&4 && AA::$dbg->duration_stat();
}


// do not remove this exit - we do not want to allow users
// to include this script (honzam)
exit;
