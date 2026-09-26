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

// anonymous authentication - locauth calls extauthnobody
use AA\Central\AppExport;
use AA\Central\AppRequest;
use AA\Central\AppStore;
use AA\Central\Package\DbSource;
use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Optimize\CheckSessionidUse;
use AA\Util\Langs;
use AA\Util\LoginToken;

if (!empty($_REQUEST['free'])) {   // can't be just POST - we use it in live Tags, which sends GET request
    $nobody = true;
}

// the only page that accepts Authorization: Bearer (AA_Auth::auth_preauth())
define('AA_TOKEN_ENTRY', true);

require_once __DIR__ . "/include/init_central.php";

/** AA_Responder base class - defines some useful common methods
 */
class AA_Responder extends AA_Object
{
    public function run(): AA_Response
    {
        return new AA_Response();
    }

    /** by default only superadmins are allowed to perform remote operations */
    public function isPerm()
    {
        return IsSuperadmin();
    }

    public static function name(): string
    {
        return str_replace('AA_Responder_', '', static::class);
    }
}

/** Login for scripts: username and password in, the session id out (used as the AA_Session cookie);
 *  also AA_ClientAuth. State Check lists the callers (CheckSessionidUse)
 *  @return Session ID
 */
class AA_Responder_Get_Sessionid extends AA_Responder
{
    public function __construct() {}

    /** every authenticated user can get his/her session_id */
    public function isPerm(): bool
    {
        return true;
    }

    public function run(): AA_Response
    {
        global $sess, $auth;
        CheckSessionidUse::record($_SERVER, $_REQUEST, AuthGetUid(), AA_Auth::login());
        return new AA_Response([$sess->id(), $auth]);
    }
}

/** A login token (AA\Util\LoginToken) of a superadmin, a day at most (ttl in seconds): admin for Central,
 *  apps for an AA that installs an app of this one. The public account of the app store gets an apps
 *  token only (AppStore).
 *  @return JWT string
 */
class AA_Responder_Get_Jwt extends AA_Responder
{
    /** @var array{ttl: ?int, scope: string}|null */
    private ?array $request;

    public function __construct($param = null)
    {
        $this->request = ResponderPolicy::jwtParams($param);
    }

    public function run(): AA_Response
    {
        LoginToken::forgetSession();   // Central never uses the session of this login
        if ($this->request === null) {
            return new AA_Response(_m('Get_Jwt needs ttl as seconds and scope admin or apps'), ResponderPolicy::ERR_TOKEN_REQUEST);
        }
        // only for the password sent with this very request - a stolen cookie or token must not renew itself;
        // superadmin checked here too, so the token never depends on isPerm() alone
        $may = IsSuperadmin() || (AppStore::isPublic((string) AuthGetUid()) && ($this->request['scope'] === LoginToken::SCOPE_APPS));
        $jwt = ((AA_Auth::login() === 'password') && $may) ? LoginToken::issue(AuthGetUid(), null, $this->request['ttl'], $this->request['scope']) : '';
        return ($jwt === '') ? new AA_Response(_m('A token needs a login by the username and password in this request, of a user whose password change this AA can detect'), ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD) : new AA_Response($jwt);
    }
}

/** Used for autentication
 *  @return Session ID
 */
class AA_Responder_Logout extends AA_Responder
{
    public function __construct() {}

    /** every authenticated user can get his/her session_id */
    public function isPerm(): bool
    {
        return true;
    }

    public function run(): AA_Response
    {
        global $sess;
        $ret = ['sessid' => $sess->id(), 'command' => $GLOBALS['request']->getCommand()];
        if (is_object($sess)) {
            $sess->delete();
        }
        return new AA_Response($ret);
    }
}

/** @return array of information about AA - org_name, domaun */
class AA_Responder_Get_Aaname extends AA_Responder
{
    public function __construct() {}

    public function run(): AA_Response
    {
        return new AA_Response(['org_name' => ORG_NAME, 'domain' => AA_HTTP_DOMAIN]);
    }
}

/** @return array of information about AA - org_name, domain */
class AA_Responder_Get_Modules extends AA_Responder
{
    /** array of module types to get */
    private $types;

    public function __construct($param = null)
    {
        $this->types = is_array($param['types']) ? $param['types'] : [];
    }

    public function run(): AA_Response
    {
        $type_sql = !count($this->types) ? '' : Cvarset::sqlin('type', $this->types) . ' AND ';
        $modules  = GetTable2Array("SELECT id, name FROM module WHERE $type_sql deleted=0  ORDER BY type, priority, name", "unpack:id", 'name');
        return new AA_Response($modules);
    }
}

/** return structure which define all the definition of the slice (like slice
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Get_Module_Defs extends AA_Responder
{
    private array $ids;
    private array $limited;  // parts to send - see AA_Definition_Module::normalizeLimited()
    private string $type;     // module type

    public function __construct($param)
    {
        $this->ids     = is_array($param['ids']) ? $param['ids'] : [];
        $this->limited = AA_Definition_Module::normalizeLimited($param['limited'] ?? []);  // Central before 2008 sent bool
        $this->type    = (string) $param['type'];
    }

    public function run(): AA_Response
    {
        $ret        = [];
        if (!($class_name = ResponderPolicy::moduleDefinitionClass($this->type))) {
            return new AA_Response(_m('Unknown module type'), 6);
        }
        foreach ($this->ids as $sid) {
            $ret[$sid] = new $class_name();
            $ret[$sid]->loadForId($sid, $this->limited);
        }
        return new AA_Response($ret);
    }
}

/** return structure which define all the definition of the slice (like slice
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Do_Synchronize extends AA_Responder
{
    private array $sync_commands;

    public function __construct($param)
    {
        $this->sync_commands = is_array($param['sync']) ? $param['sync'] : [];
    }

    public function run(): AA_Response
    {
        $ret = [];
        foreach ($this->sync_commands as $serialized_command) {
            try {
                $cmd   = unserialize((string) $serialized_command, ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);
                $ret[] = ($cmd instanceof AA_Sync_Action) ? $cmd->doAction() : _m('Wrong command - not a synchronization action');
            } catch (\Throwable $e) {
                // one bad command must not cost the rest of the batch; the message may name server
                // paths or SQL, so only the error class goes back
                $ret[] = _m('Wrong command - %1', [get_class($e)]);
            }
        }
        return new AA_Response($ret);
    }
}

/** Run an Optimize class method on this AA — used by Central's optimize buttons
 *  (Update_Db_Structure_Test/Repair, Field_Duplicates_Test/Repair, ...).
 *  $param['class']  - fully qualified Optimize class, e.g. 'AA\Optimize\UpdateDbStructure'
 *  $param['method'] - one of the actions declared by that class (typically 'test' or 'repair')
 */
class AA_Responder_Do_Optimize extends AA_Responder
{
    protected string $class;
    protected string $method;

    public function __construct($param = null)
    {
        $this->class  = (string) ($param['class']  ?? '');
        $this->method = (string) ($param['method'] ?? '');
    }

    public function run(): AA_Response
    {
        if (!is_subclass_of($this->class, \AA\Optimize\AbstractOptimize::class)) {
            return new AA_Response("Unknown optimize class: $this->class", 4);
        }
        /** @var \AA\Optimize\AbstractOptimize $optimizer */
        $optimizer = new $this->class();
        if (!$optimizer->hasAction($this->method)) {
            return new AA_Response("Unsupported method '$this->method' for $this->class", 5);
        }
        // a repair (UpdateDbStructure, ...) must not run next to a whole sql_update
        $release = null;
        if (($this->method === 'repair') && !($release = \AA\Optimize\Chain::tryLock('sql_update', $holder))) {
            return new AA_Response(\AA\Optimize\Chain::runningMessage('sql_update', $holder), ResponderPolicy::ERR_OPTIMIZE_TYPE_RUNNING);
        }
        try {
            $optimizer->{$this->method}();
        } finally {
            $release && $release();
        }
        return new AA_Response($optimizer->report());
    }
}

/** Run all Optimize classes of a type on this AA, as service/sql_update.php does - used by Central's
 *  "Update DB (sql_update)" actions. This AA picks the classes of its own version (AA\Optimize\Chain).
 *  $param['type']   - 'sql_update'
 *  $param['method'] - 'test' (sql_update.php "Test") or 'repair' ("Install / Update")
 */
class AA_Responder_Do_Optimize_Type extends AA_Responder
{
    protected string $type;
    protected string $method;

    public function __construct($param = null)
    {
        $this->type   = (string) ($param['type']   ?? '');
        $this->method = (string) ($param['method'] ?? '');
    }

    public function run(): AA_Response
    {
        if ($refusal = ResponderPolicy::optimizeTypeRefusal($this->type, $this->method)) {
            return new AA_Response(...$refusal);
        }
        if (!($release = \AA\Optimize\Chain::tryLock($this->type, $holder))) {
            return new AA_Response(\AA\Optimize\Chain::runningMessage($this->type, $holder), ResponderPolicy::ERR_OPTIMIZE_TYPE_RUNNING);
        }
        try {
            MoreTimeLimit(360);   // a whole update (table rebuilds) takes minutes
            [, $report] = \AA\Optimize\Chain::run($this->method, \AA\Optimize\Chain::optimizers($this->type));
        } finally {
            $release();
        }
        return new AA_Response($report);
    }
}

/** An app of this AA for the AA that installs it: package XML of the definitions or of an item batch,
 *  the logins of the readers, item counts (AA\Central\AppRequest). Only with a Bearer token - the
 *  other AA holds a short one of a user of this AA; the rights by the mode in AA\Central\AppExport.
 */
class AA_Responder_Get_App extends AA_Responder
{
    private array $param;

    public function __construct($param = null)
    {
        $this->param = is_array($param) ? $param : [];
    }

    public function isPerm(): bool
    {
        return AA_Auth::login() === 'token';
    }

    public function run(): AA_Response
    {
        MoreTimeLimit(120);
        $attributes = ['url' => (string) constant('AA_INSTAL_URL'), 'aa-version' => aa_version('aa')];
        if (AppStore::isPublic((string) AuthGetUid())) {        // no rights: only what the store publishes
            $store  = new AppStore(new DbSource());
            $export = new AppExport(new DbSource(), $attributes, static fn(string $module): bool => in_array($module, $store->publishedModules(), true), false, true);
            return (new AppRequest($export, (string) AuthGetUid(), null, null, $store))->answer($this->param);
        }
        $export = new AppExport(new DbSource(), $attributes, static fn(string $module): bool => IfSlPerm(PS_EDIT, $module), IsSuperadmin());
        return (new AppRequest($export, (string) AuthGetUid()))->answer($this->param);
    }
}

/** The apps of this AA for the AA that installs one (AA\Central\AppStore::apps()): the published sites, and
 *  for a superadmin's token also the sites whose modules it may edit. Only with a Bearer token, as Get_App.
 */
class AA_Responder_Get_Apps extends AA_Responder
{
    public function __construct() {}

    public function isPerm(): bool
    {
        return AA_Auth::login() === 'token';
    }

    public function run(): AA_Response
    {
        $public = AppStore::isPublic((string) AuthGetUid());
        return new AA_Response((new AppStore(new DbSource()))->apps($public ? null : static fn(string $module): bool => IfSlPerm(PS_EDIT, $module)));
    }
}

/** return html widget for the field of item */
class AA_Responder_Get_Widget extends AA_Responder
{
    protected string $field_id;
    protected string $item_id;
    protected string $widget_type;
    protected string $widget_properties;
    protected string $lang;
    protected string $context;   // slice_pwd (spwd) for now
    protected string $sig;       // AA\Widget\AjaxSignature of the widget type and properties

    public function __construct($param = null)
    {
        $this->field_id             = (string) $param['field_id'];
        $this->item_id              = (string) $param['item_id'];
        $this->widget_type          = (string) $param['widget_type'];
        $this->widget_properties    = (string) $param['widget_properties'];
        $this->lang                 = (string) $param['lang'];
        $this->context              = (string) $param['context'];
        $this->sig                  = (string) ($param['sig'] ?? '');
    }

    public function isPerm(): bool
    {
        return true;
    }  //return true;

    public function run(): AA_Response
    {

        $item         = AA_Item::getItem(new zids($this->item_id));
        $slice_id     = $item?->getSliceID() ?? '';
        $slice        = AA_Slice::getOwnModule($slice_id);
        if (!$slice) {   // unknown item id gives an item with no slice
            return new AA_Response('Item not found', ResponderPolicy::ERR_UNKNOWN_ITEM);
        }
        $iid          = $item->getItemID();

        // we need to fill AA::$site_id in order we can use translations for example
        $site_ids     = AA_Module::belongsToSites($slice_id);
        switch (count($site_ids)) {  // the slice belongs to more than one site module - try to guess which one from url
            case 0:
                AA::$site_id = AA_Module_Site::getIdFromUrl();
                break;
            case '1':
                AA::$site_id = reset($site_ids);
                break;
            default:
                if (!(AA::$site_id = AA_Module_Site::getIdFromUrl())) {  // if possible, set it from URL
                    AA::$site_id = reset($site_ids);                     // if not, get the first site
                }
        }

        // Use right language (from slice settings) - languages are used for button texts, ...
        $lang         = $this->lang ? Langs::getLang(Langs::getLangCode2Name($this->lang) . '-utf8') : $slice->getLang();
        mgettext_bind($lang, 'output');
        AA::$lang     = strtolower(substr($lang, 0, 2));   // actual language - two letter shortcut cz / es / en
        AA::$langnum  = [Langs::getLangName2Num(AA::$lang)];   // array of preferred languages in priority order.
        AA::setEncoding(Langs::getCharset($lang));

        if ($this->context) {
            AA_Credentials::singleton()->importFromToken($this->context);
        }

        $widget_html = '';
        if ($field = $slice->getField($this->field_id)) {
            // widget type and properties only as signed by {ajax} - the request is anonymous
            [$widget_type, $widget_properties] = \AA\Widget\AjaxSignature::widgetArgs($field, $iid, $this->widget_type, $this->widget_properties, $this->sig);
            $widget_html = $field->getWidgetAjaxHtml(new ItemContent($iid), $widget_type, $widget_properties);
        }

        // @todo for TagWidget the CSS is loaded this way, but the select2.js do not work by this way. Check it.
        if ($widget_script = AA::Stringexpander()->getRequireScript()) {
            $widget_html = "$widget_html<script>$widget_script</script>";
        }

        return new AA_Response(ConvertCharset::singleton()->Convert($widget_html, $slice->getCharset(), 'utf-8'));
    }
}

/** return html widget for the field of item */
class AA_Responder_Optimize4id extends AA_Responder
{
    protected string $class;  // class to run
    protected string $id;     // optimize ID - could be slice, item, record ot some compound ID

    public function __construct($param = null)
    {
        $this->class = (string) $param['class'];
        $this->id    = (string) $param['id'];
    }

    public function isPerm()
    {
        return IsSuperadmin();
    }

    public function run(): AA_Response
    {
        /** @var ?\AA\Optimize\AbstractOptimize $optimizer */
        $optimizer = AA_Serializable::factory('AA\\Optimize\\' . $this->class);
        $ret = 'no repair4ids';
        if ($optimizer?->hasAction('repair4ids')) {
            $ret = $optimizer->repair4ids([$this->id]);
        }
        return new AA_Response($ret);
    }
}


///** @return html widget for the field of item */
//class AA_Responder_Get_Hco_Widget_Options extends AA_Responder {
//    var $field_id;
//    var $slice_id;
//    var $value;
//
//    function __construct($param=null) {
//        $this->field_id = $param['field_id'];
//        $this->slice_id = $param['slice_id'];
//        $this->value    = $param['value'];
//    }
//
//    function isPerm() { return true; }
//
//    function run() {
//
//        $slice       = AA_Slice::getModule($this->slice_id);
//
//        // Use right language (from slice settings) - languages are used for button texts, ...
//        $lang        = $slice->getLang();
//        mgettext_bind($lang, 'output');
//
//        $field  = $slice->getField($this->field_id);
//        $widget = $field ? $field->getWidget() : '';
//
//        $ret = $widget->getOptions4Value($this->value);
//
//        $encoder = new ConvertCharset;
//        $ret     =  $encoder->Convert($ret, $slice->getCharset(), 'utf-8');
//        return new AA_Response($ret);
//    }
//}


/** return html selectbox of fields in given slice */
class AA_Responder_Get_Fields extends AA_Responder
{
    /** array of module types to get */
    private string $slice_id;
    private bool $slice_fields;  // bool

    public function __construct($param = null)
    {
        $this->slice_id     = (string) $param['slice_id'];
        $this->slice_fields = (bool) $param['slice_fields'];
    }

    public function isPerm()
    {
        // AA Core Fields slice must be readable by any slice Administrator (for field template picking)
        if ($this->slice_id === '41415f436f72655f4669656c64732e2e') {
            $slices = GetUserSlices(PS_FIELDS);
            return $slices === 'all' || !empty($slices);
        }
        return IfSlPerm(PS_FIELDS);
    }

    public function run(): AA_Response
    {
        $ret = '';
        if ($slice = AA_Slice::getModule($this->slice_id)) {
            $encoder = new ConvertCharset();

            // AA_Core_Fields.. holds also templates for special slice fields, like _upload_url.....
            // and we do not want to list it as option for normal fields
            $current_types = [];
            if ($this->slice_fields) {
                $current_types = DB_AA::select(['id' => 'name'], 'SELECT `id`, `name` FROM `field`', [['slice_id', $this->slice_id, 'l'], ['id', '\_%', 'LIKE'], ['in_item_tbl', ['',0]]], ['name']);
            }
            $current_types += DB_AA::select(['id' => 'name'], 'SELECT `id`, `name` FROM `field`', [['slice_id', $this->slice_id, 'l'], ['id', '\_%', 'NOT LIKE'], ['in_item_tbl', ['',0]]], ['name']);

            $ret = "\n <select name=\"ftype\"  onchange=\"let newin=document.querySelector('input[name=&quot;name[New_Field]&quot;]');newin.value=this.options[this.selectedIndex].text;newin.focus();newin.select();\" >";
            foreach ($current_types as $k => $v) {
                $ret .= "\n  <option value=\"" . $this->slice_id . '-' . safe($k) . '"> ' . safe($v) . " </option>";
            }
            $ret .= "\n </select>";
            $ret = $encoder->Convert($ret, $slice->getCharset(), 'utf-8');
        }
        return new AA_Response($ret);
    }
}

/** return html selectbox of fields in given slice */
class AA_Responder_Tags extends AA_Responder
{
    /** array of module types to get */
    protected string $slice_id;
    protected string $input;
    protected string $field_id;
    protected string $item_id;  // not filled for new item

    public function __construct($param = null)
    {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->slice_id = (string) $param['s'];
        $this->field_id = (string) $param['f'];
        $this->input    = (string) $param['q'];
        $this->item_id  = (string) $param['i'];
    }

    public function isPerm(): bool
    {
        return true;
    }

    public function run(): AA_Response
    {
        $ret    = [];
        $trimed = trim($this->input);
        $slice  = AA_Slice::getModule($this->slice_id);
        $field  = $slice ? $slice->getField($this->field_id) : null;
        $found  = false;
        $widget = null;
        if ($field && $trimed) {
            $widget = $field->getWidget();
            $opts   = $widget->getFormattedOptions($this->item_id, null, $this->input);
            $i = 100;
            foreach ($opts as $id => $text) {
                if (trim($text) === trim($this->input)) {
                    // to the front
                    array_unshift($ret, ['id' => $id, 'text' => $text]);
                    $found = true;
                } else {
                    $ret[] = ['id' => $id, 'text' => $text];
                }
                if (--$i === 0) {
                    break;
                }
            }
        }
        if ($trimed && !$found && $widget && (int) $widget->getProperty('disable_new', 0) !== 1) {
            array_unshift($ret, ['id' => $this->input, 'text' => $this->input]);
        }
        header("Content-type: application/json");
        return new AA_Response(json_encode($ret));
    }
}

/** return html with items matching the filter for specified widget - add new relation for WidgetRim */
class AA_Responder_Widget_Selection extends AA_Responder
{
    /** array of module types to get */
    protected string $slice_id;
    protected string $input;
    protected string $field_id;
    protected int    $limit = 50;

    public function __construct($param = null)
    {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->slice_id = (string) $param['s'];
        $this->field_id = (string) $param['f'];
        $this->input    = (string) $param['q'];
    }

    public function isPerm(): bool
    {
        return true;
    }

    public function run(): AA_Response
    {
        $ret   = '';
        $slice = AA_Slice::getModule($this->slice_id);
        if ($slice && ($widget = $slice->getWidget($this->field_id)) && $this->input !== '') {
            $ret = $widget->getFilterSelection(trim($this->input), $this->limit);
        }
        return new AA_Response($slice ? ConvertCharset::singleton()->Convert($ret, $slice->getCharset(), 'utf-8') : $ret);
    }
}


/** return html for item formatted as specified in widget in slice_id/field_id */
class AA_Responder_Formatted_By_Widget extends AA_Responder
{
    /** array of module types to get */
    protected string $slice_id;
    protected string $field_id;
    protected string $item_id;
    protected string $base_id;

    public function __construct($param = null)
    {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->slice_id = $param['s'];
        $this->field_id = $param['f'];
        $this->item_id  = $param['i'];
        $this->base_id  = $param['b'];
    }

    public function isPerm(): bool
    {
        return true;
    }

    public function run(): AA_Response
    {
        $ret   = '';
        $slice = AA_Slice::getModule($this->slice_id);

        if ($slice && ($widget = $slice->getWidget($this->field_id))) {
            mgettext_bind($slice->getLang(), 'output');
            $ret = $widget->getRelatedItemsHtml(new zids($this->item_id, 'l'), $this->base_id, $this->field_id, $this->slice_id);   //zids $zids, string $base_id, string $sid, string $def_field_id, string $def_slice_id): string {
        }
        return new AA_Response($slice ? ConvertCharset::singleton()->Convert($ret, $slice->getCharset(), 'utf-8') : $ret);
    }
}


/** return sends one item in mail to alert irregular readers collection */
class AA_Responder_Alertitem extends AA_Responder
{
    /** array of module types to get */
    protected $item_id;
    protected $collection_id;
    /** raw request value - validated in run(), where the alerts code is loaded */
    protected mixed $test_email;

    public function __construct($param = null)
    {
        $this->item_id       = $param['item_id'] ?? null;
        $this->collection_id = $param['collection_id'] ?? null;
        $this->test_email    = $param['test_email'] ?? null;
    }

    public function isPerm()
    {
        if (!is_string($this->item_id) || !is_long_id($this->item_id)) {   // an array would be a TypeError in strlen()
            return false;
        }
        if ($item = AA_Items::getItem(new zids($this->item_id, 'l'))) {
            // the collection is checked in run() - it must be related to this slice (AA_Collection::relatesToSlice())
            return IfSlPerm(PS_EDIT_ALL_ITEMS, $item->getSliceID());
        }
        return false;
    }

    public function run(): AA_Response
    {
        // send_emails() trusts the ids it gets and fatals on an unknown one; errors do not echo the input back
        if (!AA_Collection::isValidId($this->collection_id) || !DB_AA::test('alerts_collection', [['id', $this->collection_id]])) {
            return new AA_Response('Invalid collection id', 7);
        }
        // an editor of the slice may send its item only to the readers of the slice's own collections
        $item = AA_Items::getItem(new zids($this->item_id, 'l'));   // isPerm() found it
        if (!$item) {
            return new AA_Response("Collection is not related to the item's slice", 9);
        }
        if (!AA_Collection::relatesToSlice($this->collection_id, $item->getSliceID())) {
            // an old send button may name an unrelated collection - State Check shows it (CheckAlertitemRefusals)
            AA_Collection::logRefusal($this->collection_id, $item->getSliceID(), $this->item_id);
            return new AA_Response("Collection is not related to the item's slice", 9);
        }
        // null = an address was given but is not valid - never read that as "none" (= a live send to all readers)
        $test_emails = AA_Collection::testEmails($this->test_email);
        if ($test_emails === null) {
            return new AA_Response('Invalid test e-mail address', 8);
        }
        // item_id is a long id - isPerm() checked it
        return new AA_Response(send_emails("irregular", [$this->collection_id], $test_emails, true, $this->item_id));
    }
}

/** Re-render one item-history change event's diff area against a chosen target version - the AJAX
 *  backend of the item-edit "Compare to" picker (ChangesMonitor). Read-only. Authorised to anyone
 *  who may edit the item (the same gate as itemedit.php / iteminfo.php), so the history is never
 *  exposed wider than the item itself. */
class AA_Responder_Item_History_Diff extends AA_Responder
{
    protected string $id;
    protected string $cid;
    protected string $base;

    public function __construct($param = null)
    {
        $this->id   = (string) ($param['id']   ?? '');
        $this->cid  = (string) ($param['cid']  ?? '');
        $this->base = (string) ($param['base'] ?? '');
    }

    public function isPerm(): bool
    {
        // Superadmin-only, matching the itemhistory.php page gate it serves.
        if (!is_long_id($this->id) || !IsSuperadmin()) {
            return false;
        }
        //        $item = AA_Items::getItem(new zids($this->id), null, true, true);
        //        return $item && (IfSlPerm(PS_EDIT_ALL_ITEMS, $item->getSliceID()) || $item->getval('posted_by.......') == AuthGetUid());

        // admin read: ignore the slice reading password so a protected item still resolves (see iteminfo.php)
        return (bool) AA_Items::getItem(new zids($this->id), null, true, true);
    }

    public function run(): AA_Response
    {
        return new AA_Response(\AA\Util\ChangesMonitor::singleton()->renderEventDiffFragment($this->id, $this->cid, $this->base));
    }
}

pageOpen('nobody');
if (in_array(AA_Auth::login(), ['token', 'bad-token'], true)) {
    LoginToken::forgetSession();   // a token login lives for this request only
    if (AA_Auth::login() === 'bad-token') {
        AA_Response::error(_m('Login token refused (it lives a day, and a password change ends it) - authenticate this AA again in Central'), ResponderPolicy::ERR_TOKEN_REFUSED);
        exit;
    }
}
// the public account of the app store: anyone knows its password, so it never keeps a session - not even
// when the request ends in an error below (it logs in nowhere else, so a session login is never it)
$public_account = in_array(AA_Auth::login(), ['password', 'token'], true) && AppStore::isPublic((string) AuthGetUid());
if ($public_account) {
    LoginToken::forgetSession();
}

// in order {xuser:id} alias works in widgets, for example
$GLOBALS['apc_state']['xuser'] = AuthGetUname();
$GLOBALS['apc_state']['xuid']  = AuthGetUid();

$request = null;
$via_url = false;  // command named in the URL (admin AJAX) rather than the serialized POST `request`

// we use primarily POST, but manager class actions needs to send GET request
if ($_POST['request']) {
    $request = AA_Request::decode($_POST['request']);
} elseif ($_GET['command']) {  // ajax call (html output expected)
    $via_url = true;

    // switch responses to plain output
    AA_Response::$Response_type = 'html';

    $request = new AA_Request($_GET['command'], $_REQUEST);  // params are in $_POST for ajax
}

if (!is_object($request)) {
    AA_Response::error(_m("No request sent for responder.php"), 102);  // error code > 0
    exit;
}

$responder = AA_Responder::factoryByName($request->getCommand(), $request->getParameters());
if (empty($responder)) {
    AA_Response::error(_m("Bad request sent for responder.php - %1", [$request->getCommand()]), ResponderPolicy::ERR_UNKNOWN_COMMAND);
    exit;
}

// Remote-management commands only via the POST `request` - a URL one is forgeable by any cross-site link.
// Checked on the resolved class, so a case variant of the name cannot slip past.
if ($via_url && ResponderPolicy::isRemoteCommand($responder::name())) {
    AA_Response::error(_m("This command is not accepted in the URL"), 104);  // error code > 0
    exit;
}
if ($via_url && ResponderPolicy::isPostOnlyCommand($responder::name()) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    AA_Response::error(_m("This command must be sent by POST"), 105);  // error code > 0
    exit;
}
// Live-editing AJAX (Get_Widget, Tags, ...) is called from public sites on other domains, so the origin
// check covers only the POST `request` and the POST-only commands.
if ((!$via_url || ResponderPolicy::isPostOnlyCommand($responder::name())) && ResponderPolicy::isForeignBrowserRequest($_SERVER)) {
    AA_Response::error(_m("Request from another site refused"), 106);  // error code > 0
    exit;
}

// an apps token (another AA pulling an app) runs only the commands of its scope - whatever the transport
if ((AA_Auth::login() === 'token') && !ResponderPolicy::scopeAllows(AA_Auth::scope(), $responder::name())) {
    AA_Response::error(_m("The login token does not allow %1", [$responder::name()]), ResponderPolicy::ERR_TOKEN_SCOPE);
    exit;
}

if ($public_account) {
    $scope = ($responder::name() === 'Get_Jwt') ? (ResponderPolicy::jwtParams($request->getParameters())['scope'] ?? '') : AA_Auth::scope();
    if (!ResponderPolicy::publicAllows($responder::name(), AA_Auth::login(), $scope)) {
        AA_Response::error(_m("The app store account may not run %1", [$responder::name()]), ResponderPolicy::ERR_PUBLIC_ACCOUNT);
        exit;
    }
    if (!AppStore::admit($responder::name(), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) AuthGetUid())) {
        AA_Response::error(_m("The app store is busy - try again later"), ResponderPolicy::ERR_RATE_LIMIT);
        exit;
    }
}

if (!$responder->isPerm()) {
    AA_Response::error(_m("You (%2) don't have permissions to run %1", [$responder::name(),AuthGetUid()]), 101);  // error code > 0
    exit;
}

$response = $responder->run();

if ($responder::name() !== 'Logout') {  // resolved name - a 'logout' variant also deleted the session
    Session::page_close();
}

$response->respond();
exit;
