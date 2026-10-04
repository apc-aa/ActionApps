<?php

/**
 * File contains definition of Manager class - used for 'item' manipulation
 * 'managers' pages (like Item Manager, Link Manager, Discussion comments,
 * Related Items, ...) It takes care about searchber, scroller, actions, ...
 *
 * Should be included to other scripts (as /admin/index.php)
 *
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

namespace AA;

// require_once __DIR__ . "/menu_util.php";  // for showMenu()
use AA;
use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\IO\Grabber\ObjectForm;
use AA\Manageraction\Manageractions;
use AA\Optimize\CheckObjectFormRefusals;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA\Widget\Widget;
use AA_Condition;
use AA_Content;
use AA_Form;
use AA_Metabase;
use AA_Object;
use AA_Profile;
use AA_Property;
use AA_Scroller;
use AA_Searchbar;
use AA_Set;
use AA_Storable;
use AA_Views;
use Exception;
use itemview;
use zids;

class Managed
{
    public $scroller;
    public const MANGED_URL        = 'AA_MaNaGeD_UrL';
    //  it is public for now, in order we can easily change the setting - $managed->settings[...] == ...
    public $settings        = [];
    /** like  ['edit'=>true, 'add'=>true] - set by setAction() */
    protected $add_action   = [];
    protected $url          = '';
    protected $columns      = [];
    protected $columns2show = [];

    protected $zids_type    = 'l'; // 's' | 'l' | 'w'

    public $msg_title      = '';
    public $msg_noitem     = null;
    public $msg_about      = '';

    protected $widget_setting = [];

    /** Field IDs (in order) that should appear at the top of the edit form;
     *  the remaining fields are rendered inside a collapsed <details> "Optional" section.
     *  Empty array (default) keeps the original behaviour: every field rendered in metabase order.
     *  @var string[]
     */
    protected array $priority_fields = [];

    /** Create Managed object based on the specific (unique) string submitted by form...
     *  necessary for Edit and Add
     * @param string|null $type
     * @return Managed
     */
    public static function factoryFromType(?string $type): Managed
    {
        return match (substr($type, 0, 2)) {
            '_T'    => new Managed_Table(substr($type, 2)),
            default => new Managed_Object($type),
        };
    }

    /** Create specific (unique) string submitted by form from which we can factory right Managed object  */
    public function getType(): string
    {
        return '';
    }


    public function setAction(string $action, $state = true): static
    {
        $this->add_action[$action] = $state;
        return $this;
    }

    public function isAction(string $action): bool
    {
        return (bool) ($this->add_action[$action] ?? false);   // a plain Managed sets none
    }

    /** Generates AA_Form for the managed data
     *  necessary for Edit and Add
     * @param string|null $id
     * @param string|null $module_id
     * @return AA_Form|null
     */
    public function getForm(?string $id, ?string $module_id): ?AA_Form
    {
        return null;
    }

    /** Grabs and saves the data submitted by the AA_Form
     *  necessary for Edit and Add
     * @param string|null $owner  the module the page works on
     * @param string|null $id     the record the form was built for; empty - a new one. A subclass
     *                            which does not check it saves whatever record the POST names
     * @return string|null  why the data was not saved; null - saved
     */
    public function save(?string $owner, ?string $id = null): ?string
    {
        return null;
    }

    /** Logs a form or action of $module refused for the object or row $id - a forged request, or a
     *  page with a stale id. $stored_type '' - nothing stored at $id: the posted id may be any string,
     *  and a long one would cut the user off the selector and each new one would add a log row, so
     *  only a stored object or row is named there.
     */
    public static function logRefusal(string $module, string $id, string $type, string $stored_type, string $stored_owner): void
    {
        $logged = ($stored_type !== '') ? $id : CheckObjectFormRefusals::NO_OBJECT;
        LogOnce::daily(CheckObjectFormRefusals::EVENT, ParamImplode([$module, $logged, mb_strcut(AuthGetUid(), 0, 120)]), [$type, $stored_type, $stored_owner]);
    }

    /** Get content for zids from the storage
     *  necessary for Edit and Add
     * @param zids $zids
     * @return AA_Content[]
     */
    public function getContent(zids $zids): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array $settings
     */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    public function getItemview(zids $zids, $module_id, $listlen): itemview
    {

        $format_strings = $this->settings['itemview']['format'];
        $aliases        = is_object($this->settings['itemview']['aliases']) ? $this->settings['itemview']['aliases']->getArray() : (is_array($this->settings['itemview']['aliases']) ? $this->settings['itemview']['aliases'] : []);

        // modify $format_strings and $aliases (passed by reference)
        $colspan = 100;
        if ($manager_vid = $this->settings['itemview']['manager_vid']) {
            $view = AA_Views::getViewNumeric($manager_vid);
            if ($view->f('deleted') == 0) {
                $format_strings = $view->getViewFormat();
                if (isset($this->scroller)) {
                    $this->scroller->setListlen($view->f('listlen'));
                }
            }
        } else {
            // define JS_HEAD_, HEADLINE, SITEM_ID, ITEM_ID_ (if not set)
            DefineBaseAliases($aliases, $module_id);

            if ($this->columns) {
                $cols = $this->columns2show ? array_merge(['_aa_chb','_aa_action'], $this->columns2show) : array_keys($this->columns);
                $td = '';
                $th = '';
                foreach ($cols as $col) {
                    $th .= "<th class=\"aacol$col\" scope=col>" . $this->columns[$col][0] . '</th>';
                    $td .= "<td class=\"aacol$col\">" . $this->columns[$col][1] . '</td>';   //<td style="white-space:nowrap;"> for .aacol_aa_action
                }

                $format_strings = [    // optionally to manager_vid you can set format array
                    'compact_top'      => '<div class="aa-table aa-items-manager"><table><thead><tr class="aa-table-head">' . $th . '</tr></thead><tbody>',
                    'odd_row_format'   => '<tr>' . $td . '</tr>',
                    'compact_bottom'   => "</tbody></table></div><br>",
                ];
                $colspan = count($cols);
            } elseif (!$format_strings) {
                $row = '<td>_#PUB_DATE&nbsp;</td><td>_#HEADLINE</td>' . (isset($aliases["_#AA_ACTIO"]) ? '<td>_#AA_ACTIO</td>' : '');
                $format_strings["odd_row_format"]  = '<tr>' . $row . '</tr>';
                $format_strings["compact_top"]     = '<table class="aa-table"><thead>
                    <tr><th scope=col>' . _m("Publish date") . '</th><th scope=col>' . _m("Headline") . '</th>' . (isset($aliases["_#AA_ACTIO"]) ? '<th scope=col>' . _m("Actions") . '</th>' : '') . '</tr></thead><tbody>';
                $format_strings["compact_bottom"]  = '</tbody></table>';
                $colspan = 3;
            }
        }
        $format_strings['odd_row_format'] = str_replace(self::MANGED_URL, $this->getUrl(), $format_strings['odd_row_format']);
        $this->setNoitem($format_strings["noitem_msg"]);

        if ($this->isAction('add')) {
            $new_link = a_href(StateUrl($this->getUrl() . '?add=1'), _m('Add'), ['class' => 'aa-button-new']);
            $format_strings['compact_bottom'] = "<tr class=\"aa-table-foot\"><td colspan=\"$colspan\">$new_link</td></tr>" . $format_strings['compact_bottom'];
            $this->msg_noitem     .= '<br>' . $new_link;
        }

        return (new itemview($format_strings, $aliases, $zids, 0, $listlen))->setContentFunct($this->settings['itemview']['get_content_funct']);
    }

    /**
     * @param string $url
     * @return Managed
     */
    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $module_id
     * @return Managed
     */
    public function setModule(string $module_id): static
    {
        $this->settings['module_id'] = $module_id;
        return $this;
    }

    /** set title text of the Manager
     * @param string $text
     * @return Managed
     */
    public function setTitle(string $text): static
    {
        $this->msg_title = $text;
        return $this;
    }

    /** set "No item found" message
     * @param string|null $text
     * @return Managed
     */
    public function setNoitem(?string $text): static
    {
        if (isset($text) && strlen($text)) {
            $this->msg_noitem = $text;
        }
        return $this;
    }

    /** set About message
     * @param string $text
     * @return Managed
     */
    public function setAbout(string $text): static
    {
        $this->msg_about = $text;
        return $this;
    }

    /** Set all definitions of the columns
     * @param array $columns
     * @return Managed
     */
    public function setColumns(array $columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    /** Set definition of the column
     * @param $col
     * @param $format
     * @return Managed
     */
    public function setColumn(string $col, string $format): static
    {
        if ($format === '') {
            unset($this->columns[$col]);
        } else {
            $this->columns[$col][1] = $format;
        }
        return $this;
    }

    /**
     * Add a column to the Manager
     *
     * @param string $col    The column key
     * @param string $name   The column name
     * @param string $format The column format
     * @return Managed The Manager object with the newly added column
     */
    public function addColumn(string $col, string $name, string $format): static
    {
        $this->columns[$col] = [$name, $format];
        return $this;
    }

    /**
     * @param array $columns2show
     */
    public function setColumns2show(array $columns2show): void
    {
        $this->columns2show = $columns2show;
    }

    /**
     * @param string $id
     * @param array  $setting  ['type'=>bool|enum|string, 'enum'=>['x','y'], 'name'=>'', 'help'=>'']
     */
    public function setWidgetSetting(string $id, array $setting): void
    {
        $this->widget_setting[$id] = $setting;
        if (array_key_exists('name', $setting) && array_key_exists($id, $this->columns)) {
            $this->columns[$id][0] = $this->widget_setting[$id]['name'];
        }
    }

    /** Mark these field IDs as priority — they render first in the edit form,
     *  in the order given. All other fields are wrapped in a collapsible
     *  "Optional" <details> block.
     *  @param string[] $fields
     */
    public function setPriorityFields(array $fields): static
    {
        $this->priority_fields = array_values($fields);
        return $this;
    }

    public function getPriorityFields(): array
    {
        return $this->priority_fields;
    }

    /** which typ of zids it uses - 's' | 'l' | 'w' */
    public function getZidsType(): string
    {
        return $this->zids_type;
    }

    /** Set which typ of zids it uses - 's' | 'l' | 'w' */
    public function setZidsType(string $zids_type): static
    {
        $this->zids_type = $zids_type;
        return $this;
    }
}

class Managed_Object extends Managed
{
    protected $class = '';

    public function __construct(string $class)
    {
        $this->class = $class;
        $this->add_action = ['edit' => true, 'add' => true]; // default
    }

    /** @inheritDoc */
    public function getType(): string
    {
        return $this->class;
    }

    /** @inheritDoc */
    public function getForm(?string $id, ?string $module_id): ?AA_Form
    {
        return AA_Form::factoryForm($this->getType(), $id, $module_id);
    }

    /** Saves the object the form was built for - the object id comes from the POSTed keys, so it is
     *  checked: an edit writes only the object $id, stored owned by $owner and of this class; a new
     *  object gets a fresh id. Otherwise the form could overwrite any object of any module by its id.
     *  @inheritDoc
     */
    public function save(?string $owner, ?string $id = null): ?string
    {
        $owner = strtolower((string) $owner);    // module ids are stored lowercase
        $id    = (string) $id;
        if (!is_long_id($owner) || !$this->isAction(($id === '') ? 'add' : 'edit')) {
            return _m('You can not save this object here.');
        }
        /** @var AA_Object $object */
        $object = call_user_func_array([$this->class, 'factoryFromForm'], [$owner, $this->class]);
        if ($id === '') {
            $object->setId(new_id());
        } elseif ((string) $object->getId() !== $id) {
            return $this->refuse($owner, (string) $object->getId());
        } elseif (!self::isOwn($id, $owner, $this->class)) {
            return $this->refuse($owner, $id);
        }
        $object->save();
        return null;
    }

    /** the object $id is stored, owned by $owner and of $class */
    private static function isOwn(string $id, string $owner, string $class): bool
    {
        $stored = self::stored($id);
        return ($stored['aa_owner'] === $owner) && ($stored['aa_type'] === $class);
    }

    /** @return array{aa_owner: string, aa_type: string} of the stored object $id; '' - none stored */
    private static function stored(string $id): array
    {
        $rows = DB_AA::select(['property' => 'value'], 'SELECT property, value FROM `object_text`', [['object_id', $id], ['property', ['aa_owner', 'aa_type']]]);
        return ['aa_owner' => (string) ($rows['aa_owner'] ?? ''), 'aa_type' => (string) ($rows['aa_type'] ?? '')];
    }

    /** a form of $module posted another object - a forged request, or a page with a stale id */
    private function refuse(string $module, string $object_id): string
    {
        $this->logObject($module, $object_id);
        return _m('The object was not saved - it is not the object of this form in this module.');
    }

    private function logObject(string $module, string $object_id): void
    {
        $stored = self::stored($object_id);
        self::logRefusal($module, $object_id, $this->class, is_long_id($object_id) ? $stored['aa_type'] : '', $stored['aa_owner']);
    }

    /** The objects of $zids a Manager action needing $perm may change: of this class and owned by the
     *  module of the Manager - in a Manager over all modules, by a module where the user has $perm.
     *  The ids come from the posted checkboxes, so the others are skipped: an id of no object (a
     *  reload after a delete) silently, a stored object refused - and logged, unless the Manager lists
     *  the objects of all modules, also those the user can not change.
     *  @return array{0: zids, 1: bool}  the objects, whether any was refused
     */
    public function actionable(Manager $manager, zids $zids, string $perm): array
    {
        $module = strtolower($manager->getModuleId());
        $ids    = array_values(array_unique(array_map('strval', $zids->longids())));
        if ($manager->isAllModules()) {
            $owners = $ids ? DB_AA::select('', 'SELECT DISTINCT value FROM `object_text`', [['object_id', $ids], ['property', 'aa_owner']]) : [];
            $owners = array_values(array_filter($owners, static fn($owner) => is_long_id($owner) && IfSlPerm($perm, $owner)));
        } else {
            $owners = is_long_id($module) ? [$module] : [];
        }
        $allowed = ($ids && $owners) ? AA_Object::querySet($this->class, (new AA_Set())->setModules($owners), $zids) : new zids([], 'l');
        $skipped = array_values(array_diff($ids, $allowed->longids()));
        $stored  = $skipped ? DB_AA::select('', 'SELECT DISTINCT object_id FROM `object_text`', [['object_id', $skipped], ['property', 'aa_type']]) : [];
        if (!$manager->isAllModules()) {
            foreach ($stored as $id) {
                $this->logObject($module, (string) $id);
            }
        }
        return [$allowed, (bool) $stored];
    }

    /** @inheritDoc */
    public function getContent(zids $zids): array
    {
        return AA_Object::getContent($zids, ['class' => $this->class]);
    }



    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }
}

class Managed_Table extends Managed
{
    protected $table = '';

    public function __construct(string $table)
    {
        $this->table = $table;
        $this->add_action = ['edit' => true, 'add' => true]; // default
    }

    /** @inheritDoc */
    public function getType(): string
    {
        return '_T' . $this->table;
    }

    /** @inheritDoc */
    public function getForm(?string $id, ?string $module_id): ?AA_Form
    {
        $props   = AA::Metabase()->getColumnProperties($this->table, (bool) $id);
        $widgets = [];      // optional per-field input widget overriding AA_Property::defaultWidget()
        $record        = null;   // edited record, lazily loaded only for value-conditional widgets
        $record_loaded = false;
        foreach ($props as $k => $prop) {
            if ($setting = $this->widget_setting[$k] ?? null) {
                if (isset($setting['type'])) {
                    $prop->setType($setting['type']);
                }
                if (isset($setting['enum'])) {
                    $prop->setConstants($setting['enum']);
                    $prop->required = true;
                }
                if (isset($setting['name'])) {
                    $prop->setName($setting['name']);
                }
                if (isset($setting['help'])) {
                    $prop->setHelp($setting['help']);
                }
                if (array_key_exists('default', $setting)) {
                    $prop->setDefault($setting['default']);
                }
                if (array_key_exists('required', $setting)) {
                    $prop->required = (bool) $setting['required'];   // override the enum-forced required
                }
                if (isset($setting['widget'])) {
                    $w = $setting['widget'];
                    // a non-string callable is a chooser fn($current_value): widget-code|Widget
                    // — e.g. pick the rich editor only for token-free bodies
                    if (is_callable($w) && !is_string($w)) {
                        if (!$record_loaded) {
                            $record        = $id ? ($this->getContent(new zids($id, $this->getZidsType()))[$id] ?? null) : null;
                            $record_loaded = true;
                        }
                        $w = $w($record ? (string) $record->getAaValue($k)->getValue() : '');
                    }
                    // accept a ready Widget instance or a short-code string ('edt', 'edt:20', ...)
                    $widgets[$k] = $w instanceof Widget ? $w : Widget::factoryByString((string) $w);
                }
                // $props['html']->setHelp('xxx');
            }
        }
        $form = new AA_Form();
        if ($this->priority_fields) {
            $rest = $props;
            foreach ($this->priority_fields as $fid) {
                if (isset($rest[$fid])) {
                    $rest[$fid]->addPropertyFormrows($form, $widgets[$fid] ?? null);
                    unset($rest[$fid]);
                }
            }
            if ($rest) {
                $form->addRow(new \AA_Formrow_Html('<details class="aa-form-optional"><summary>' . _m('Optional') . '</summary>'));
                foreach ($rest as $k => $prop) {
                    $prop->addPropertyFormrows($form, $widgets[$k] ?? null);
                }
                $form->addRow(new \AA_Formrow_Html('</details>'));
            }
        } else {
            foreach ($props as $k => $prop) {
                $prop->addPropertyFormrows($form, $widgets[$k] ?? null);
            }
        }
        //        if ($id) {
        //            $form->addRow(new AA_Formrow_Text('ddd'.$id));
        //        }
        $form->setObject($this->getType(), $id, $module_id);  //$module_id not used here
        return $form;
    }

    /** Updates the row the form was built for, or inserts one. In a table with a module column and a
     *  Manager of one module ($owner) the row is the module's and stays so - the key comes from the
     *  POST, so otherwise it could rewrite a row of any module (another slice's view). Without $owner
     *  (a Manager over all modules) and in tables without a module column the posted key picks the row.
     *  @inheritDoc
     */
    public function save(?string $owner, ?string $id = null): ?string
    {
        ($grabber = new ObjectForm())->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getItem();

        // while ($content = $grabber->getContent())
        $store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber

        $owner        = strtolower((string) $owner);   // module ids are stored lowercase
        $module_field = is_long_id($owner) ? AA_Metabase::getModuleField($this->table) : null;

        if ($store_mode === 'update' && $this->isAction('edit')) {
            $key = (string) $content->getId();
            if ($module_field) {
                $stored = $this->rowOwners([$key])[$key] ?? null;
                if (($key !== (string) $id) || ($stored !== $owner)) {
                    self::logRefusal($owner, $key, $this->getType(), isset($stored) ? $this->getType() : '', (string) $stored);
                    return _m('The row was not saved - it is not the row of this form in this module.');
                }
            }
            // key is stored in aa_id field
            $content[AA::Metabase()->getKeys($this->table)[0]] = $content['aa_id']; // assign using ArrayAccess
            unset($content['aa_id']);
            $data = $content->getAllAsScalar();
            if ($module_field) {
                $data[$module_field] = $owner;
            }
            AA::Metabase()->doUpdate($this->table, $data);
        } elseif ($store_mode === 'insert' && $this->isAction('add')) {
            $data = $content->getAllAsScalar();
            if ($module_field) {
                $data[$module_field] = $owner;
            }
            AA::Metabase()->autoInsertKey($this->table, $data);
            AA::Metabase()->doInsert($this->table, $data);
        } else {
            return _m('You can not save this row here.');
        }
        return null;
    }

    /** The rows of $zids a Manager action may change: in a table with a module column, the rows of the
     *  module of the Manager (none - a Manager over all modules: all rows). The ids come from the
     *  posted checkboxes, so the others are skipped: a key of no row (a reload after a delete)
     *  silently, a row of another module refused and logged.
     *  @return array{0: list<string>, 1: bool}  the row keys, whether any was refused
     */
    public function actionable(Manager $manager, zids $zids): array
    {
        $module = strtolower($manager->getModuleId());
        $keys   = array_values(array_unique(array_map('strval', $zids->getArray())));
        if (!is_long_id($module) || !AA_Metabase::getModuleField($this->table)) {
            return [$keys, false];
        }
        $allowed = [];
        $refused = false;
        foreach ($this->rowOwners($keys) as $key => $owner) {
            if ($owner === $module) {
                $allowed[] = $key;
            } else {
                self::logRefusal($module, $key, $this->getType(), $this->getType(), $owner);
                $refused = true;
            }
        }
        return [$allowed, $refused];
    }

    /** Whether a page of $module may work on the row $key the request names (poll edit page): true -
     *  the module's row, false - a row of another module (refused and logged) or a table without a
     *  module column, null - no such row.
     */
    public function isOwnRow(string $module, string $key): ?bool
    {
        $module = strtolower($module);
        if (!is_long_id($module) || !AA_Metabase::getModuleField($this->table)) {
            return false;
        }
        if (null === ($owner = $this->rowOwners([$key])[$key] ?? null)) {
            return null;
        }
        if ($owner !== $module) {
            self::logRefusal($module, $key, $this->getType(), $this->getType(), $owner);
            return false;
        }
        return true;
    }

    /** @return array<string, string> the module column of the stored rows of $keys - key => module id */
    private function rowOwners(array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn($key) => $key !== ''));
        $rows = $keys ? AA_Metabase::getContent(new zids($keys, $this->getZidsType()), ['table' => $this->table]) : [];
        $ret  = [];
        foreach ($rows as $key => $row) {
            $ret[(string) $key] = strtolower((string) $row->getValue(AA_Metabase::getModuleField($this->table)));
        }
        return $ret;
    }

    /** @inheritDoc */
    public function getContent(zids $zids): array
    {
        return AA_Metabase::getContent($zids, ['table' => $this->table]);
    }

    /** which typ of zids it uses - 's' | 'l' | 'w' */
    public function getZidsType(): string
    {
        if (!($zid_type = AA::Metabase()->getZidsType($this->table))) {
            warn("This table cannot be used in Manager - no zids type defined");
            exit;
        }
        return $zid_type;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }
}



/**
 * Manager class - used for 'item' manipulation 'managers' pages
 * (like Item Manager, Link Manager, Discussion comments, Related Items, ...)
 * It takes care about searchbar, scroller, actions, ...
 */
class Manager extends AA_Storable
{
    /** FormToken purpose of every write of every Manager page - one, since a row button may post
     *  to another Manager page (the AA finder lists aliases with the buttons of aliases.php) */
    public const FORM_TOKEN = 'manager';

    protected ?AA_Searchbar $searchbar;

    /** @var string searchbar additional function name */
    protected $searchbar_funct;

    protected ?AA_Scroller $scroller   = null;

    protected ?Manageractions $actions = null;

    protected $actions_hint;
    protected $actions_hint_url;

    protected ?Manageractions $switches = null;

    protected $query_funct = [];

    protected $itemview;        // itemview object
    protected $show;            // what controls show (scroller, searchbar, ...)
    protected $bin;             // stores the bin in which we are - string

    /** @var AA_Condition[] */
    protected $restrict_conds; // restriction

    protected $all_modules = false; // do not restrict for one module

    protected $module_id;          // for which module is manager used (slice_id, ...)
    /** the edit form was posted in this request - the list is shown then, not the form again */
    private bool $form_posted = false;

    /** @var Managed */
    protected $managed;            // object describing managed data ['class'=>.., 'table'=>, settings...]
    protected $_manager_id;        // ID of manager

    protected $msg;                // stores return code from action functions

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array
    {
        //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'searchbar'   => new AA_Property('searchbar', _m("Searchbar"), 'AA_Searchbar', false, true),
            'scroller'    => new AA_Property('scroller', _m("Scroller"), 'AA_Scroller', false, true),
            'msg'         => new AA_Property('msg', _m("Msg"), 'text', true, true),
            'bin'         => new AA_Property('bin', _m("Bin"), 'text', false, true),
            'module_id'   => new AA_Property('module_id', _m("Module ID"), 'text', false, true),
            '_manager_id' => new AA_Property('_manager_id', _m("Manager ID"), 'text', false, true),
        ];
    }

    /** Manager function
     * constructor - initializes manager - creates scroller, searchbar, ...
     * based on $settings structure
     *
     * @param string $manager_id
     * @param Managed $managed - main manager settings
     */
    public function __construct($manager_id, Managed $managed)
    {
        global $r_state, $sess;

        $this->managed     = $managed;
        $this->_manager_id = $manager_id;
        $settings          = $managed->getSettings();

        $this->show        = $settings['show'] ?? MGR_ALL;
        $this->module_id   = $settings['module_id'];

        if ($settings['actions']) {      // define actions, if we have to
            $this->actions = $settings['actions'];
            $this->actions_hint = $settings['actions_hint'];
            $this->actions_hint_url = $settings['actions_hint_url'];
        }

        if ($settings['switches']) {      // define switches, if we have to
            $this->switches = $settings['switches'];
        }

        // create searchbar, if we have to ------------------------------------
        if ($settings['searchbar']) {
            $this->searchbar = new AA_Searchbar(
                $this->module_id,
                $settings['searchbar']['fields'],   // form name is given in this case
                'filterform',
                $settings['searchbar']['search_row_count_min'],
                $settings['searchbar']['order_row_count_min'],
                $settings['searchbar']['add_empty_search_row'],
                $this->show,
                $settings['searchbar']['hint'],
                $settings['searchbar']['hint_url'],
            );
            $this->searchbar_funct = $settings['searchbar']['function'];
            if (isset($settings['searchbar']['default_bookmark'])) {
                $this->searchbar->setFromBookmark($settings['searchbar']['default_bookmark']);
            } elseif (isset($settings['searchbar']['default_sort'])) {
                $this->searchbar->addOrder($settings['searchbar']['default_sort']);
            }
        }

        $this->bin           = $settings['bin'] ?? 'app';
        $this->query_funct   = $settings['itemview']['query_funct'] ?? [];

        // create page scroller -----------------------------------------------
        // could be redefined by view (see ['itemview']['manager_vid'])
        $this->scroller = new AA_Scroller('st', $settings['scroller']['listlen'], 0, sess_return_url($_SERVER['PHP_SELF']), 1, true);
        //        $scroller = new AA_Scroller('st', sess_return_url($_SERVER['PHP_SELF']), $settings['scroller']['listlen']);
        //        $scroller->addFilter("slice_id", "md5", $this->module_id);

        if (!isset($this->managed->msg_noitem)) {
            // could be redefined by view (see ['itemview']['manager_vid'])
            $this->managed->setNoitem(_m('No item found'));
        }

        // r_state array holds all configuration of Manager
        // the configuration then could be Bookmarked
        if (!isset($r_state)) {
            $r_state = [];
            $sess->register('r_state');
        }
        // user switched to another page with different manager?
        if (!$r_state["manager"][$manager_id]) {
            // we are here for the first time, or we are switching to another slice

            // we use profile just for main Item manager
            if ($settings['use_profile']) {
                // set default admin interface settings from user's profile
                $profile = AA_Profile::getProfile(AuthGetUid(), $this->module_id); // current user settings
                $this->setFromProfile($profile);
            }
        } else {        // do not set state for the first time calling
            $this->setFromState($r_state['manager'][$manager_id]);
            // one manager id may serve more modules (the tasks of all modules) - the stored one
            // would be the module of the first visit, not the one the page works on now
            $this->module_id = $settings['module_id'];
        }
    }

    /** get module id of the module, which is managed by the manager
     * @return string
     */
    public function getModuleId(): string
    {
        return (string) $this->module_id;    // none in some Managers over all modules (aa_emails.php)
    }

    /** sets the bin, where we are */
    public function setBin($bin): void
    {
        $this->bin = $bin;
    }

    /** gets the bin, where we are */
    public function getBin()
    {
        return $this->bin;
    }

    /** setRestrictionConds */
    public function setRestrictionConds(array $conds): void
    {
        $this->restrict_conds = $conds;
    }

    /** do not restrict the manager just to one module */
    public function setAllModules(): static
    {
        $this->all_modules = true;
        return $this;
    }

    /** the manager lists the objects of all modules, not just of its own */
    public function isAllModules(): bool
    {
        return (bool) $this->all_modules;
    }

    /** setFromProfile function
     *  initial manager setting from user's profile
     * @param AA_Profile $profile
     */
    public function setFromProfile($profile): void
    {
        // set default admin interface settings from user's profile

        // get default number of listed items from user's profile
        $this->setListlen($profile->getProperty('listlen'));
        $this->searchbar?->setFromProfile($profile);
    }

    /** @return AA_Set object - should be used instead of getConds and getSort
     */
    public function getSet(): AA_Set
    {
        $set = $this->searchbar ? $this->searchbar->getSet() : new AA_Set();
        if (!$this->all_modules && $this->module_id) {
            $set->setModules([$this->module_id]);
        }
        $BIN_CONDS   = [
            'app'    => AA_BIN_ACTIVE,
            'appb'   => AA_BIN_PENDING,
            'appc'   => AA_BIN_EXPIRED,
            'hold'   => AA_BIN_HOLDING,
            'trash'  => AA_BIN_TRASH,
        ];
        if ($BIN_CONDS[$this->bin]) {
            $set->setBins($BIN_CONDS[$this->bin]);
        } elseif (ctype_digit((string) $this->bin)) {
            $set->setBins($this->bin);
        }
        if ($this->restrict_conds) {
            foreach ($this->restrict_conds as $cond) {
                $set->addCondition($cond);
            }
        }
        return $set;
    }

    /** getConds function
     * Get conditios (conds[] array) for *_QueryIDs from set
     * @deprecated - use getSet() instead
     */
    public function getConds(): array
    {
        return $this->getSet()->getConds();
    }

    /** getSort function
     * Get sort[] array for *_QueryIDs from set
     * @deprecated - use getSet() instead
     */
    public function getSort(): array
    {
        return $this->getSet()->getSort();
    }

    /**
     * @return array 'id'=>'name'
     */
    public function getBookmarkNames(): array
    {
        if ($this->searchbar) {
            return $this->searchbar->getBookmarkNames();
        }
        return [];
    }

    public function setFromBookmark($bookmark_id): void
    {
        $this->searchbar?->setFromBookmark($bookmark_id);
    }

    /** resetSearchBar function
     *  Resets the searchbar (both - Search and Order)
     */
    public function resetSearchBar(): void
    {
        $this->searchbar?->resetSearchAndOrder();
    }

    /** addOrderBar function
     * Adds new Order bar(s)
     * @param  array $sort[] = array( <field> => <a|d> )
     *               value other than 'a' means DESCENDING
     * for description @see searchbar.class.php
     */
    public function addOrderBar($sort): void
    {
        $this->searchbar?->addOrder($sort);
    }


    /** addSearchBar function
     * Adds new Search bar(s)
     * @param array $conds [] = array ( <field> => 1, 'operator' => <operator>,'value' => <search_string> )
     *
     * @param bool $readonly
     * @see searchbar.class.php for description
     */
    public function addSearchBar($conds, bool $readonly = false): void
    {
        $this->searchbar?->addSearch($conds, $readonly);
    }

    /** setListlen function
     *  Sets listing length - number of items per page
     * @param $listlen
     */
    public function setListlen($listlen): void
    {
        if ($this->scroller && ($listlen > 0)) {
            $this->scroller->setListlen($listlen)->go2page(1);
        }
    }

    /** Go to specified page (obviously 1) in scroller
     * @param $page
     */
    public function go2page($page): void
    {
        if ($this->scroller && ($page > 0)) {
            $this->scroller->go2page($page);
        }
    }

    /** the checked rows: chb[x<id>] => value - only from the POST, a link must not pick them */
    public function getPostedChb(): array
    {
        $chb = $_POST['chb'] ?? null;
        return is_array($chb) ? $chb : [];
    }

    /** the rows an action works on; no checked row and "all rows" chosen - all rows of the list
     * @return zids
     */
    public function getPostedItems(): zids
    {
        if (!isset($_POST['chb']) && ($_POST['aa_akce_all'] ?? '') === '1') {
            return $this->getZids();
        }
        return (new zids([], $this->managed->getZidsType()))->setFromItemArr($this->getPostedChb());
    }

    /** performActions function
     *  Writes (actions, switches like Empty trash, the edit form, stored filters) run only from a POST
     *  of a page of this site which this session rendered: a link runs for a prefetch, a crawler or
     *  an <img src>, SameSite=Lax still lets a sibling subdomain POST with the session cookie, and
     *  the token (FORM_TOKEN; JS adds it from data-aa-token) stops a form of another page.
     */
    public function performActions(): void
    {
        /** used for AJAX display of action parameters */
        if ($_GET['display_params'] ?? '') {
            if ($action2display = $this->actions?->getAction((string) $_GET['display_params'])) {
                echo $action2display->htmlSettings();
            }
            exit;
        }

        $token     = $_POST['token'] ?? '';
        $may_write = !ResponderPolicy::isForeignBrowserRequest($_SERVER)
            && FormToken::checkPost(self::FORM_TOKEN, is_string($token) ? $token : '', $_SERVER);
        $refused   = isset($_GET['akce']);

        // call custom searchbar function (if searchbar action invoked)
        // used for additional search functions like 'category search' in Links - it only sets the view
        if ($this->searchbar_funct && ($_REQUEST['srchbr_akce'] ?? '')) {
            $function2call = $this->searchbar_funct;
            $function2call();
        }

        // update searchbar - storing a filter is a write too
        if (isset($this->searchbar) && !$this->searchbar->update($may_write)) {
            $refused = true;
        }

        $item_zids = $this->getPostedItems(); // in some managers we use 'x' before the item id - remove it

        $akce = is_string($_POST['akce'] ?? null) ? $_POST['akce'] : '';
        if ($akce && $this->actions) {
            $action2do  = $this->actions->getAction($akce);
            $akce_param = $_POST['akce_param'] ?? null;
            if (!$may_write) {
                $refused = true;
            } elseif ($action2do && $action2do->isPerm4Perform($this, $item_zids, $akce_param)) {
                $this->msg[] = $action2do->perform($this, $item_zids, $akce_param);
            }
        }

        // switches of the view (tabs, bookmarks) are links; the ones which write come only by POST
        if ($this->switches) {
            foreach ($this->switches as $sw => $switch) {
                if ($switch->allowGet()) {
                    $input = $_GET;
                } else {
                    $refused = $refused || isset($_GET[$sw]) || (isset($_POST[$sw]) && !$may_write);
                    $input   = $may_write ? $_POST : [];
                }
                if (isset($input[$sw]) && $switch->isPerm4Perform($this, $item_zids, $input[$sw])) {
                    $this->msg[] = $switch->perform($this, $item_zids, $input[$sw]);
                }
            }
        }

        // update scroller (items could be deleted, moved, ...)
        if ($this->scroller) {
            $this->scroller->update();
            if ($_GET['listlen'] ?? '') {
                $this->setListlen($_GET['listlen']);
            }
            // new search - go to first page
            if ($_REQUEST['srchbr_akce'] ?? '') {
                $this->go2page(1);
            }
        }


        // for managed class
        if (($_POST['aa'] ?? null) && ($this->managed->isAction('edit') || $this->managed->isAction('add'))) {
            if (!$may_write) {
                $refused = true;
            } else {
                $form = $this->managed->getForm($_GET['id'], $this->module_id);
                // the managed of the page - with its actions, not the defaults (add, edit)
                if ($form->process($_POST['aa'], $this->managed) === AA_Form::REFUSED) {
                    $this->msg[] = MsgErr($form->getError());
                }
                $this->form_posted = true;
            }
        }

        if ($refused) {
            $this->msg[] = MsgErr(_m('The action was not performed - use the button on the page.'));
        }
    }

    /** zids directly from manager, which is a right way! */
    public function getZids(): ?zids
    {
        return is_array($this->query_funct)
            ? call_user_func_array($this->query_funct[0], [$this->query_funct[1], $this->getSet()])
            : call_user_func_array($this->query_funct, [$this->getSet()]);
    }

    /** Displays the manager. This function joins together some common method
     *  calls, which was separate. We should use display, now. We plan to move
     *  even more methods here
     *
     * @param  zids  $zids
     */
    public function getManagerCode(zids $zids): string
    {
        global $r_err, $r_msg;          // @todo - check if it is still needed
        $ret = '';

        // if ($this->messages['title']) {
        //     echo '<h1>'. $this->messages['title'] .'</h1>';
        // }
        if ($this->managed->msg_about) {
            $ret .= '<div class="aa-about">' . $this->managed->msg_about . '</div>';
        }

        $ret .= $this->getSearchbarBegin();
        $ret .= $this->getSearchbarEnd();     // close the searchbar form
        $ret .= $this->getAndClearMessages();

        $ret .= GetPrintArray($r_err);
        $ret .= GetPrintArray($r_msg);
        unset($r_err);
        unset($r_msg);
        $ret .= $this->getItems($zids);       // print items and actions
        return $ret;
    }


    /**
     * @param zids|null $zids  @deprecated - manager should generate the zids from its own settings
     * @return string
     * @throws Exception
     */
    public function getDisplayPage(?zids $zids = null): string
    {
        global $r_state;

        $zids = $zids ?? $this->getZids();

        if (!$this->form_posted && (($_GET['id'] && $this->managed->isAction('edit')) || ($_GET['add'] && $this->managed->isAction('add')))) {

            $form = $this->managed->getForm($_GET['id'], $this->module_id);
            $form_buttons = [ "update", "cancel"];

            try {
                // the id goes back with the post - the form saves only the object it was built for
                $html = $this->getAndClearMessages() . '<form name="f" method="post" action="' . safe(StateUrl(get_url('', $_GET['id'] ? ['id' => $_GET['id']] : []))) . '">' . FormToken::field(self::FORM_TOKEN);
                $html .= getFrmTabCaption(_m("Edit id") . ': ' . safe($_GET['id']), $form_buttons);
                $html .= '<tr><td colspan="2">';
                $html .= $form->getObjectEditHtml();//            $html .= $form->getEditFormHtml();
                $html .= '</td></tr>';
                $html .= getFrmTabEnd($form_buttons);
                $html .= '</form>';
            } catch (Exception $e) {
                $html = MsgErr($e->getMessage());
            }
            // echo $form->getAjaxHtml('xxx'); // ($_GET['ret_code']);
        } else {
            AA::Stringexpander()->addRequire(get_aa_url('javascript/manager.min.js', ['v' => AA_JS_VERSION]));
            AA::Stringexpander()->addRequire('aa-jslib@1');
            AA::Stringexpander()->addRequire('AA_AllowMultiselect(\'input[name^="chb["]\', \'' . _m("Hold Shift and click to select multiple rows") . '\')', 'AA_Req_Load');
            $html = $this->getManagerCode($zids);
        }
        $r_state['manager'][$this->_manager_id]    = $this->getState();
        // $r_state['manager_id'] = $this->_manager_id;
        return $html;
    }


    /** printHtmlPageBegin function
     * Print HTML start page tags (html begin, encoding, style sheet, title
     * and includes necessary javascripts for manager
     * @param bool $head_end
     */
    public function printHtmlPageBegin($head_end = false): void
    {
        // Print HTML start page (html begin, encoding, style sheet, no title)
        HtmlPageBegin();
        // manager javascripts - must be included
        echo '<title>' . $this->managed->msg_title . '</title>';
        IncludeManagerJavascript();
        if ($head_end) {
            echo "\n</head>\n";
        }
    }

    /** getSearchbarBegin function
     * Return begin of search form with searchbar (you can then add more code
     * to searchbar after calling this function. Then you MUST close the form
     * with getSearchbarEnd() function
     */
    /** The 'js' of an "Empty trash" menu item: asks, then POSTs DeleteTrash with the token (manager.js).
     *  The arguments are escaped for JS strings (an apostrophe of a translation would break the call;
     *  escape4js, not json_encode - some language files are not UTF-8), the whole for the attribute:
     *  menus put it raw into href="javascript:..." or onclick="...".
     *  @param string $url the page - '{href}' for the URL the menu computes */
    public static function emptyTrashJs(string $url): string
    {
        $args = array_map(static fn(string $s): string => "'" . escape4js($s) . "'", [$url, _m("Are you sure you want to empty trash?"), FormToken::get(self::FORM_TOKEN)]);
        return safe('EmptyTrashQuestion(' . implode(',', $args) . ')');
    }

    public function getSearchbarBegin(): string
    {
        // filtering goes by GET, so the token is not a field (it would land in the URL); JS adds it to a POST
        $ret = '<form name="filterform" class="noprint aa-ui-filterform" data-aa-token="' . safe(FormToken::get(self::FORM_TOKEN)) . '">' . StateHidden();
        if (isset($this->searchbar)) {
            $ret .= $this->searchbar->getBar();
        }
        return $ret;
    }

    /**
     * Prints end of search form with searchbar (@see printSearchbarBegin())
     */
    public function getSearchbarEnd(): string
    {
        return "</form>";
    }

    /**
    * get item/link/... table with scroller, actions, ... for printing
    * @param zids $zids
    * @return mixed
    */
    public function getItems($zids): string
    {
        // no token field: content of a row (a <button> in it) would submit it natively; JS adds it
        $ret  = '<form name="itemsform" id="itemsform" method="post" action="' . StateUrl() . '" data-aa-token="' . safe(FormToken::get(self::FORM_TOKEN)) . '">';
        $ret .= '<div class=aa-manager-tab>';
        $ret .= '<input type="submit" disabled style="display: none" aria-hidden="true"> '; // prevents form to be submitted by pressing Enter on any live text field

        $itemview = $this->managed->getItemview($zids, $this->module_id, $this->scroller->getListlen());

        $ids_count = $zids->count();
        if (!$ids_count) {
            $ret .= "<div>" . $itemview->unaliasWithScroller($this->managed->msg_noitem) . "</div></form><br>";
            return $ret;
        }

        $this->scroller->countPages($ids_count);

        // update itemview
        $itemview->num_records = $this->scroller->getListlen();
        $itemview->from_record = $itemview->num_records * ($this->scroller->getCurrent() - 1);                // from which index begin showing items

        // big security hole is open if we cache it
        // (links to itemedit.php would stay with session ids in cache
        // - you bacame another user !!!)

        $ret .= $itemview->get_output('view');


        if ($this->show & MGR_ACTIONS) {
            $ret .= '<div class="aa_manager_actions noprint">';
            $ret .=  '<div class="aa-formgroup-inline">';
            $ret .=   '<input type="hidden" name="akce" value="">';          // filled by javascript - contains action to perform
            $ret .=   '<input type="hidden" name="akce_param" value="">';  // if we need some parameteres to the action, store it here

            $javascr = '';
            $options = '';

            // new approach uses AA\Manageractions
            if ($this->actions) {
                $i       = 1;  // we start on 1 because first option is "Select action:"

                foreach ($this->actions as $action) {
                    if ($action->isPerm4Action($this)) {
                        $options .= '<option value="' . safe($action->getId()) . '"> '
                                                       . safe($action->getName() . ($action->getOpenUrl() ? '...' : ''));
                        // we have to open window?
                        if ($action->getOpenUrl()) {
                            $javascr .= "\n markedactionurl[$i] = '" . $action->getOpenUrl() . "';";
                            if ($action->getOpenUrlAdd()) { // we have to open window
                                $javascr .= "\n markedactionurladd[$i] = '" . $action->getOpenUrlAdd() . "';";
                            }
                        }
                        // we have to display some setting?
                        if ($action->isSetting()) {
                            // $request = new AA_Request('Do_Manageraction', array('action_class'=>get_class($action), 'action_state'=>$action->getState()));
                            $javascr .= "\n markedactionsetting[$i] = '" . $action->getId() . "';";
                        }
                        $i++;
                    }
                }
            }

            if ($options) {
                $ret .= "<a href=\"javascript:SelectVis()\"><img src=\"" . AA_INSTAL_PATH . "images/arrow_ltr.gif\" alt='^'></a>
                    <a href=\"javascript:SelectVis()\" class=\"aa-button\">" . _m('Select all') . "</a>&nbsp;&nbsp;&nbsp;&nbsp;";

                // click "go" does not use markedform, it uses itemsfrom above...
                // maybe this action is not used.
                $ret .= '<select name="markedaction_select" id="markedaction_select" onchange="MarkedActionSelect(this)" class="markedaction_select">
                      <option value="nothing">' . _m('Selected items') . ':'
                      . $options . '</select>';
                if ($this->actions_hint_url || $this->actions_hint) {
                    $ret .= getFrmMoreHelp($this->actions_hint_url, $this->actions_hint);
                }

                $ret .= '&nbsp;&nbsp;<a href="javascript:MarkedActionGo()" class="aa-button">' . _m('Go') . '</a>';

                // we store open_url parameter to js variable for
                // MarkedActionGo() function
                $ret .= '<script>
                         var markedactionurl     = [];
                         var markedactionurladd  = [];
                         var markedactionsetting = [];
                            ' . $javascr . '
                      </script>';
            }
            $ret .=  '</div>';
            //            $ret .=  '<input type="hidden" name="debug" value="511">';
            $ret .=  '<div class="aa_manager_actions_all" style="display: none">' . _m("No item selected. Perform action for all filtered items? ") . '<input type="hidden" name="aa_akce_all" value=""><button type="button" class="aa-button" onclick="AA_$N(\'aa_akce_all\').value=1;MarkedActionGo();">' . _m('Run for %1 items', [$this->scroller->getItemCount()]) . '</button></div>';
            $ret .=  "<div id=\"markedactionparams\"></div>";
            $ret .= '</div>';
        }
        $ret .= '<div class="aa-ui-pager">';
        $ret .=  $this->scroller->getNavbar();
        $ret .= "\n</div></div></form><br>";
        return $ret;
    }

    /**
     *  get return values from action functions and clears the messages for printing
     */
    public function getAndClearMessages()
    {
        $ret = GetPrintArray($this->msg);
        $this->msg = [];
        return $ret;
    }

    public function getManaged(): ?Managed
    {
        return $this->managed;
    }
}
