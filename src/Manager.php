<?php
/**
 * File contains definition of Manager class - used for 'item' manipulation
 * 'managers' pages (like Item Manager, Link Manager, Discussion comments,
 * Related Items, ...) It takes care about searchber, scroller, actions, ...
 *
 * Should be included to other scripts (as /admin/index.php3)
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
use AA\IO\Grabber\ObjectForm;
use AA\Manageraction\Manageractions;
use AA_Condition;
use AA_Content;
use AA_Form;
use AA_Metabase;
use AA_MetabaseTableEdit;
use AA_Object;
use AA_Profile;
use AA_Property;
use AA_Scroller;
use AA_Searchbar;
use AA_Set;
use AA_Storable;
use AA_Views;
use itemview;
use zids;

class Managed {

    //  it is public for now, in order we can easily change the setting - $managed->settings[...] == ...
    const MANGED_URL        = 'AA_MaNaGeD_UrL';
    public $settings        = [];
    protected $add_action   = [];
    protected $url          = '';
    protected $columns      = [];
    protected $columns2show = [];

    /** Create Managed object based on the specific (unique) string submitted by form...
     *  necessary for Edit and Add
     * @param string|null $type
     * @return Managed
     */
    public static function factoryFromType(?string $type): Managed {
        switch (substr($type,0,2)) {
            case '_T': return new Managed_Table(substr($type,2));
        }
        return new Managed_Object($type);
    }

    /** Create specific (unique) string submitted by form from which we can factory right Managed object  */
    public function getType(): string { return ''; }


    public function setAction(string $action, $state = true): Managed {
        $this->add_action[$action] = $state;
        return $this;
    }

    public function isAction(string $action): bool {
        return (bool)$this->add_action[$action];
    }

    /** Generates AA_Form for the managed data
     *  necessary for Edit and Add
     * @param string|null $id
     * @param string|null $module_id
     * @return AA_Form|null
     */
    public function getForm(?string $id, ?string $module_id): ?AA_Form { return null; }

    /** Grabs and saves the data submitted by the AA_Form
     *  necessary for Edit and Add
     * @param string|null $owner
     * @return void
     */
    public function save(?string $owner): void {}

    /** Get content for zids from the storage
     *  necessary for Edit and Add
     * @param zids $zids
     * @return AA_Content[]
     */
    public function getContent(zids $zids): array { return []; }

    /**
     * @return array
     */
    public function getSettings(): array {
        return $this->settings;
    }

    /**
     * @param array $settings
     */
    public function setSettings(array $settings): Managed {
        $this->settings = $settings;
        return $this;
    }

    public function getItemview(zids $zids, $module_id, $listlen): itemview {

        $format_strings = $this->settings['itemview']['format'];
        $aliases        = is_object($this->settings['itemview']['aliases']) ? $this->settings['itemview']['aliases']->getArray() : $this->settings['itemview']['aliases'];

        // modify $format_strings and $aliases (passed by reference)
        if ( $manager_vid = $this->settings['itemview']['manager_vid'] ) {
            $view = AA_Views::getViewNumeric($manager_vid);
            if ( $view AND !($view->f('deleted')>0) ) {
                $format_strings = $view->getViewFormat();
                $this->messages['noitem_msg'] = $view->f('noitem_msg');
                if ( isset($this->scroller) ) {
                    $this->scroller->metapage = $view->f('listlen');
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
                    $th .= "<th class=\"aacol$col\">".$this->columns[$col][0].'</th>';
                    $td .= "<td class=\"aacol$col\">".$this->columns[$col][1].'</td>';   //<td style="white-space:nowrap;"> for .aacol_aa_action
                }

                $format_strings = [    // optionaly to manager_vid you can set format array
                    'compact_top'      => '<div class="aa-table aa-items-manager"><table><tr>'.$th.'</tr>',
                    'odd_row_format'   => '<tr>'. $td .'</tr>',
                    'compact_bottom'   => "</table></div><br>"
                ];
            } elseif ( !$format_strings ) {
                $row = '<td>_#PUB_DATE&nbsp;</td><td>_#HEADLINE</td>'. (isset($aliases["_#AA_ACTIO"]) ? '<td>_#AA_ACTIO</td>': '');
                $format_strings["odd_row_format"]  = '<tr class="tabtxt">'.$row.'</tr>';
                $format_strings["even_row_format"] = '<tr class="tabtxteven">'.$row.'</tr>';
                $format_strings["even_odd_differ"] = 1;
                $format_strings["compact_top"]     = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">
                    <tr class="tabtitlight"><td>'._m("Publish date").'</td><td>'._m("Headline").'</td>'. (isset($aliases["_#AA_ACTIO"]) ? '<td>'._m("Actions").'</td>' : ''). '</tr>';
                $format_strings["compact_bottom"]  = '</table>';
            }
        }
        $format_strings['odd_row_format'] = str_replace(Managed::MANGED_URL, $this->getUrl(), $format_strings['odd_row_format']);

        if ($this->isAction('add')) {
            $new_link = a_href(StateUrl($this->getUrl(). '?add=1'), GetAAImage('icon_new.gif', _m('new'), 17, 17) . ' ' . _m('Add'));
            $format_strings['compact_bottom'] .= $new_link;
            $format_strings['noitem_msg']     .= $new_link;
        }

        return new itemview($format_strings, $aliases, $zids, 0, $listlen, '', '', $this->settings['itemview']['get_content_funct']);
    }

    /**
     * @param string $url
     */
    public function setUrl(string $url): Managed {
        $this->url = $url;
        return $this;
    }

    public function getUrl(): string {
        return $this->url;
    }

    /** Set definition of the column
     * @param array $columns
     */
    public function setColumns(array $columns): Managed  {
        $this->columns = $columns;
        return $this;
    }

    /**
     * @param array $columns2show
     */
    public function setColumns2show(array $columns2show): void
    {
        $this->columns2show = $columns2show;
    }
}

class Managed_Object extends Managed {
    protected $class = '';

    function __construct(string $class) {
        $this->class = $class;
        $this->add_action = ['edit'=>true, 'add'=>true]; // default
    }

    /** @inheritDoc */
    public function getType(): string {
        return $this->class;
    }

    /** @inheritDoc */
    public function getForm(?string $id, ?string $module_id): ?AA_Form {
        return AA_Form::factoryForm($this->getType(), $id, $module_id);
    }

    /** @inheritDoc */
    public function save(?string $owner): void {
        $object = call_user_func_array([$this->class, 'factoryFromForm'], [$owner, $this->class]);
        // @todo check permissions, validation, $store_mode state (see readFromForm)
        $object->save();
    }

    /** @inheritDoc */
    public function getContent(zids $zids): array  {
        return AA_Object::getContent($zids, ['class' => $this->class]);
    }

    /**
     * @return string
     */
    public function getClass(): string {
        return $this->class;
    }
}

class Managed_Table extends Managed {
    protected $table = '';

    function __construct(string $table) {
        $this->table = $table;
        $this->add_action = ['edit'=>true, 'add'=>true]; // default
    }

    /** @inheritDoc */
    function getType(): string {
        return '_T'.$this->table;
    }

    /** @inheritDoc */
    public function getForm(?string $id, ?string $module_id): ?AA_Form {
        $form = (new AA_Form())->addProperties(AA_MetabaseTableEdit::defaultGetClassProperties($this->table));
        $form->setObject($this->getType(), $id, $module_id);  //$module_id not used here
        return $form;
    }

    /** @inheritDoc */
    public function save(?string $owner): void {
        ($grabber = new ObjectForm())->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getItem();

        // while ($content = $grabber->getContent())
        $store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber

        if (($store_mode == 'update') AND $this->isAction('edit')) {
            // key is stored in aa_id field
            $content[AA::Metabase()->getKeys($this->table)[0]] = $content['aa_id']; // assign using ArrayAccess
            unset($content['aa_id']);
            AA::Metabase()->doUpdate($this->table, $content->getAllAsScalar());
        } elseif (($store_mode == 'insert') AND $this->isAction('add')) {
            AA::Metabase()->doInsert($this->table, $content->getAllAsScalar());
        }
    }

    /** @inheritDoc */
    public function getContent(zids $zids): array {
        return AA_Metabase::getContent($zids, ['table' => $this->table]);
    }

    /**
     * @return string
     */
    public function getTable(): string {
        return $this->table;
    }
}



/**
 * Manager class - used for 'item' manipulation 'managers' pages
 * (like Item Manager, Link Manager, Discussion comments, Related Items, ...)
 * It takes care about searchbar, scroller, actions, ...
 */
class Manager extends AA_Storable {
    /** @var AA_Searchbar  */
    protected $searchbar;

    /** @var string searchbar aditional function name */
    protected $searchbar_funct;

    /** @var AA_Scroller */
    protected $scroller;

    /** @var Manageractions  */
    protected $actions;

    protected $actions_hint;
    protected $actions_hint_url;

    /** @var Manageractions  */
    protected $switches;

    protected $query_funct = [];

    protected $messages;        // various language messages (title, noitem_msg, about)
    protected $itemview;        // itemview object
    protected $show;            // what controls show (scroller, searchbar, ...)
    protected $bin;             // stores the bin in which we are - string

    /** @var AA_Condition[] */
    protected $restrict_conds; // restriction

    protected $all_modules = false; // do not restrict for one module

    protected $module_id;          // for which module is manager used (slice_id, ...)

    /** @var Managed */
    protected $managed;            // object describing managed data ['class'=>.., 'table'=>, settings...]
    protected $_manager_id;        // ID of manager

    protected $msg;                // stores return code from action functions

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'searchbar'   => new AA_Property( 'searchbar',   _m("Searchbar"),  'AA_Searchbar', false, true),
            'scroller'    => new AA_Property( 'scroller',    _m("Scroller"),   'AA_Scroller',  false, true),
            'msg'         => new AA_Property( 'msg',         _m("Msg"),        'text',         true,  true),
            'bin'         => new AA_Property( 'bin',         _m("Bin"),        'text',         false, true),
            'module_id'   => new AA_Property( 'module_id',   _m("Module ID"),  'text',         false, true),
            '_manager_id' => new AA_Property( '_manager_id', _m("Manager ID"), 'text',         false, true),
        ];
    }

    /** Manager function
     * constructor - initializes manager - creates scroller, searchbar, ...
     * based on $settings structure
     *
     * @param string $manager_id
     * @param Managed $managed - main manager settings
     */
    function __construct($manager_id, Managed $managed) {
        global $r_state, $sess;

        $this->managed     = $managed;
        $this->_manager_id = $manager_id;
        $settings          = $managed->getSettings();

        $this->show        = $settings['show'] ?? MGR_ALL;
        $this->module_id   = $settings['module_id'];

        if ( $settings['actions'] ) {      // define actions, if we have to
            $this->actions = $settings['actions'];
            $this->actions_hint = $settings['actions_hint'];
            $this->actions_hint_url = $settings['actions_hint_url'];
        }

        if ( $settings['switches'] ) {      // define switches, if we have to
            $this->switches = $settings['switches'];
        }

        // create searchbar, if we have to ------------------------------------
        if ( $settings['searchbar'] ) {
            $this->searchbar = new AA_Searchbar(
                              $settings['searchbar']['fields'],
                              'filterform',   // form name is given in this case
                              $settings['searchbar']['search_row_count_min'],
                              $settings['searchbar']['order_row_count_min'],
                              $settings['searchbar']['add_empty_search_row'],
                              $this->show,
                              $settings['searchbar']['hint'],
                              $settings['searchbar']['hint_url']);
            $this->searchbar_funct = $settings['searchbar']['function'];
            if ( isset($settings['searchbar']['default_bookmark']) ) {
                $this->searchbar->setFromBookmark($settings['searchbar']['default_bookmark']);
            } elseif ( isset($settings['searchbar']['default_sort']) ) {
                $this->searchbar->addOrder($settings['searchbar']['default_sort']);
            }
        }

        $this->bin           = $settings['bin'] ?? 'app';
        $this->query_funct   = $settings['itemview']['query_funct'] ?? [];

        // create page scroller -----------------------------------------------
        // could be redefined by view (see ['itemview']['manager_vid'])
        $scroller = new AA_Scroller('st', sess_return_url($_SERVER['PHP_SELF']), $settings['scroller']['listlen']);
//        $scroller->addFilter("slice_id", "md5", $this->module_id);
        $this->scroller = $scroller;

        $this->messages = $settings['messages'];
        if ( !isset($this->messages['noitem_msg']) ) {
            // could be redefined by view (see ['itemview']['manager_vid'])
            $this->messages['noitem_msg'] = get_if( $settings['itemview']['format']['noitem_msg'], _m('No item found'));
        }

        // r_state array holds all configuration of Manager
        // the configuration then could be Bookmarked
        if ( !isset($r_state) ) {
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
        }
    }

    /** get module id of the module, which is managed by the manager
     * @return string
     */
    function getModuleId() {
        return $this->module_id;
    }

    /** sets the bin, where we are */
    function setBin($bin) {
        $this->bin = $bin;
    }

    /** gets the bin, where we are */
    function getBin() {
        return $this->bin;
    }

    /** setRestrictionConds */
    function setRestrictionConds(array $conds) {
        $this->restrict_conds = $conds;
    }

    /** do not restrict the manager just to one module */
    function setAllModules(): self {
        $this->all_modules = true;
        return $this;
    }

    /** setFromProfile function
     *  initial manager setting from user's profile
     * @param AA_Profile $profile
     */
    function setFromProfile($profile) {
        // set default admin interface settings from user's profile

        // get default number of listed items from user's profile
        $this->setListlen( $profile->getProperty('listlen') );

        if ( $this->searchbar ) {
            $this->searchbar->setFromProfile($profile);
        }
    }

    /** @return AA_Set object - should be used instead of getConds and getSort
     */
    function getSet() {
        $set = $this->searchbar ? $this->searchbar->getSet() :  new AA_Set;
        if (!$this->all_modules AND $this->module_id) {
            $set->setModules([$this->module_id]);
        }
        $BIN_CONDS   = [
            'app'    => AA_BIN_ACTIVE,
            'appb'   => AA_BIN_PENDING,
            'appc'   => AA_BIN_EXPIRED,
            'hold'   => AA_BIN_HOLDING,
            'trash'  => AA_BIN_TRASH
        ];
        if ($BIN_CONDS[$this->bin]) {
            $set->setBins($BIN_CONDS[$this->bin]);
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
    function getConds() {
        $set = $this->getSet();
        return $set->getConds();
    }

    /** getSort function
     * Get sort[] array for *_QueryIDs from set
     * @deprecated - use getSet() instead
     */
    function getSort() {
        $set = $this->getSet();
        return $set->getSort();
    }

    /**
     * @return array 'id'=>'name'
     */
    function getBookmarkNames() {
        if ( $this->searchbar ) {
            return $this->searchbar->getBookmarkNames();
        }
        return [];
    }

    function setFromBookmark($bookmark_id) {
        if ( $this->searchbar ) {
            $this->searchbar->setFromBookmark($bookmark_id);
        }
    }

    /** resetSearchBar function
     *  Resets the searchbar (both - Search and Order)
     */
    function resetSearchBar() {
        if ( $this->searchbar ) {
            $this->searchbar->resetSearchAndOrder();
        }
    }

    /** addOrderBar function
     * Adds new Order bar(s)
     * @param  array $sort[] = array( <field> => <a|d> )
     *               value other than 'a' means DESCENDING
     * for description @see searchbar.class.php3
     */
    function addOrderBar($sort) {
        if ( $this->searchbar ) {
            $this->searchbar->addOrder($sort);
        }
    }


    /** addSearchBar function
     * Adds new Search bar(s)
     * @param array $conds [] = array ( <field> => 1, 'operator' => <operator>,'value' => <search_string> )
     *
     * @param bool $readonly
     * @see searchbar.class.php3 for description
     */
    function addSearchBar($conds, bool $readonly=false) {
        if ( $this->searchbar ) {
            $this->searchbar->addSearch($conds, $readonly);
        }
    }

    /** setListlen function
     *  Sets listing length - number of items per page
     * @param $listlen
     */
    function setListlen( $listlen ) {
        if ( $this->scroller AND ($listlen > 0) ) {
            $this->scroller->metapage = $listlen;
            $this->scroller->go2page(1);
        }
    }

    /** go2page function
     *  Go to specified page (obviously 1) in scroller
     * @param $page
     */
    function go2page( $page ) {
        if ( $this->scroller AND ($page > 0) )
            $this->scroller->go2page($page);
    }

    /**
     * @param string $akce
     * @return null|Manageraction\AbstractManageraction
     */
    function getAction($akce) {
        if (!is_object($this->actions)) {
            return null;
        }
        return $this->actions->getAction($akce);
    }

    /**
     * @return zids
     */
    public function getPostedItems(): zids {
        if (!is_array($_POST['chb']) AND ($_POST['aa_akce_all']=='1')) {
            $this->getZids();
        }
        return (new zids())->setFromItemArr($_POST['chb'] ?? $_GET['chb'] ?? []);
    }

    /** performActions function
     */
    function performActions() {

        $akce = $_REQUEST['akce'];

        /** used for AJAX display of action parameters */
        if ( $_GET['display_params'] ) {
            $action2display = $this->getAction($_GET['display_params']);
            if ($action2display) {
                echo $action2display->htmlSettings();
            }
            exit;
        }

        /* if (!isset($akce)) { $akce = $_GET['akce']; }
           if (!isset($chb))  { $akce = $_GET['chb'];  }
        */

        // call custom searchbar function (if searchbar action invoked)
        // used for additional search functions like 'category search' in Links
        if ( $this->searchbar_funct AND $_REQUEST['srchbr_akce'] ) {
            $function2call = $this->searchbar_funct;
            $function2call();
        }

        // update searchbar
        if ( isset($this->searchbar) ) {
            $this->searchbar->update();
        }

        /** @var zids $item_zids */
        $item_zids = $this->getPostedItems(); // in some managers we use 'x' before the item id - remove it


        // new approach uses AA\Manageractions
        if ($akce AND is_object($this->actions)) {
            $actions   = $this->actions;
            $action2do = $actions->getAction($akce);
            if ( $action2do AND $action2do->isPerm4Perform($this, $item_zids, $_REQUEST['akce_param'])) {
                $this->msg[] = $action2do->perform($this, $item_zids, $_REQUEST['akce_param']);
            }
        }

        // new approach uses AA\Manageractions
        if (is_object($this->switches)) {
            $switches     = $this->switches;
            foreach ( $switches as $sw => $switch ) {
                if ( isset($_GET[$sw]) AND $switch->isPerm4Perform($this, $item_zids, $_GET[$sw])) {
                    $this->msg[] = $switch->perform($this, $item_zids, $_GET[$sw]);
                }
            }
        }

        // update scroller (items could be deleted, moved, ...)
        if ( isset($this->scroller) ) {
            $this->scroller->updateScr(sess_return_url($_SERVER['PHP_SELF'])); // use $return_url if set.
            if ( $_GET['listlen'] ) {
                $this->setListlen($_GET['listlen']);
            }
            // new search - go to first page
            if ( $_REQUEST['srchbr_akce']) {
                $this->go2page(1);
            }
        }


        // for managed class
        if ($this->managed->isAction('edit') OR $this->managed->isAction('add')) {
            if ($_POST['aa']) {
                $form = $this->managed->getForm($_GET['id'], $this->module_id);

                $err        = [];          // error array (Init - just for initializing variable
                $form_state = $form->process($_POST['aa']);

                if ($form_state == AA_Form::SAVED) {
                    // go_url($ret_url);
                }
            }
        }
    }

    /** zids directly from manager, which is a right way! */
    function getZids(): zids {
        return is_array($this->query_funct) ?
            call_user_func_array($this->query_funct[0], [$this->query_funct[1], $this->getSet()]) :
            call_user_func_array($this->query_funct, [$this->getSet()]);
    }

    /** Displays the manager. This function joins together some common method
     *  calls, which was separate. We should use display, now. We plan to move
     *  even more methods here
     * @param zids $zids
     */
    function getManagerCode($zids): string {
        global $r_err, $r_msg;          // @todo - check if it is still needed
        $ret = '';

        // if ($this->messages['title']) {
        //     echo '<h1>'. $this->messages['title'] .'</h1>';
        // }
        if ($this->messages['about']) {
            $ret .= '<div class="aa-about"><small>'. $this->messages['about'] .'<br><br></small></div>';
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
    function getDisplayPage(zids $zids=null): string {
        global $r_state;
        $html = '';

        $zids = $zids ?? $this->getZids();

        if (($_GET['id'] AND $this->managed->isAction('edit')) OR ($_GET['add'] AND $this->managed->isAction('add'))) {

            $form = $this->managed->getForm($_GET['id'], $this->module_id);
            $form_buttons = [
                "update",
                "cancel",
            ];

            $html = '<form name="f" method="post" action="'. StateUrl() .'">';
            $html .= getFrmTabCaption(_m("Object Edit"), $form_buttons);
            $html .= '<tr><td colspan="2">';
            $html .= $form->getObjectEditHtml();
//            $html .= $form->getEditFormHtml();
            $html .= '</td></tr>';

            $html .= getFrmTabEnd($form_buttons);
            $html .= '</form>';

            // echo $form->getAjaxHtml('xxx'); // ($_GET['ret_code']);
        } else {
            AA::Stringexpander()->addRequire(get_aa_url('javascript/manager.min.js?v='.AA_JS_VERSION));
            AA::Stringexpander()->addRequire('aa-jslib@1');
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
    function printHtmlPageBegin($head_end = false) {
        // Print HTML start page (html begin, encoding, style sheet, no title)
        HtmlPageBegin();
        // manager javascripts - must be included
        echo '<title>'. $this->messages['title'] .'</title>';
        IncludeManagerJavascript();
        if ( $head_end ) {
            echo "\n</head>\n";
        }
    }

    /** getSearchbarBegin function
     * Return begin of search form with searchbar (you can then add more code
     * to searchbar after calling this function. Then you MUST close the form
     * with getSearchbarEnd() function
     */
    function getSearchbarBegin(): string {
        $ret = '<form name="filterform" action="'.StateUrl().'" class="noprint">'.StateHidden();
        if ( isset($this->searchbar) ) {
            $ret .= $this->searchbar->getBar();
        }
        return $ret;
    }

    /**
     * Prints end of search form with searchbar (@see printSearchbarBegin())
     */
    function getSearchbarEnd(): string {
        return "</form><p></p>"; // workaround for align=left bug
    }

     /**
     * get item/link/... table with scroller, actions, ... for printing
     * @param zids $zids
     * @return mixed
     */
    function getItems($zids): string {
        $ret = '<form name="itemsform" id="itemsform" method="post" action="'. StateUrl() .'">';

        $itemview = $this->managed->getItemview($zids, $this->module_id, $this->scroller->getListlen());

        $ids_count = $zids->count();
        if ( $ids_count == 0 ) {
            $ret .= "<div class=\"tabtxt\">". $itemview->unaliasWithScroller($this->messages['noitem_msg']). "</div></form><br>";
            return $ret;
        }

        $this->scroller->countPages( $ids_count );

        // update itemview
        $itemview->from_record = $this->scroller->metapage * ($this->scroller->current-1);                // from which index begin showing items
        $itemview->num_records = $this->scroller->metapage;

        // big security hole is open if we cache it
        // (links to itemedit.php3 would stay with session ids in cache
        // - you bacame another user !!!)

        $ret .= $itemview->get_output('view');

        $ret .= '<table border="0" cellpadding="3" class="aa_manager_actions noprint">
                <tr class="aa_manager_actions_tr"><td>';

        if ($this->show & MGR_ACTIONS) {
            $ret .= '<input type="hidden" name="akce" value="">';          // filled by javascript - contains action to perform
            $ret .= '<input type="hidden" name="akce_param" value="">';  // if we need some parameteres to the action, store it here

            $javascr = '';
            $options = '';

            // new approach uses AA\Manageractions
            if (is_object($this->actions)) {
                $i       = 1;  // we start on 1 because first option is "Select action:"

                foreach( $this->actions as $action ) {
                    if ( $action->isPerm4Action($this)) {
                        $options .= '<option value="'. safe($action->getId()).'"> '.
                                                       safe($action->getName() . ($action->getOpenUrl() ? '...' : ''));
                        // we have to open window?
                        if ( $action->getOpenUrl() )  {
                            $javascr .= "\n markedactionurl[$i] = '". $action->getOpenUrl() ."';";
                            if ( $action->getOpenUrlAdd() )  { // we have to open window
                                $javascr .= "\n markedactionurladd[$i] = '". $action->getOpenUrlAdd() ."';";
                            }
                        }
                        // we have to display some setting?
                        if ( $action->isSetting() )  {
                            // $request = new AA_Request('Do_Manageraction', array('action_class'=>get_class($action), 'action_state'=>$action->getState()));
                            $javascr .= "\n markedactionsetting[$i] = '". $action->getId() ."';";
                        }
                        $i++;
                    }
                }
            }

            if ( $options ) {
                $ret .= "<img src=\"".AA_INSTAL_PATH."images/arrow_ltr.gif\">
                    <a href=\"javascript:SelectVis()\" class=\"aa-button\">". _m('Select all')."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

                  // click "go" does not use markedform, it uses itemsfrom above...
                  // maybe this action is not used.
                $ret .= '<select name="markedaction_select" id="markedaction_select" onchange="MarkedActionSelect(this)" class="markedaction_select">
                      <option value="nothing">'. _m('Selected items') .':'.
                      $options .'</select>';
                if ($this->actions_hint_url || $this->actions_hint) {
                    $ret .= getFrmMoreHelp($this->actions_hint_url, "", $this->actions_hint);
                }

                $ret .= '&nbsp;&nbsp;<a href="javascript:MarkedActionGo()" class="aa-button">'. _m('Go') . '</a>';

                  // we store open_url parameter to js variable for
                  // MarkedActionGo() function
                $ret .= '<script>
                         var markedactionurl     = [];
                         var markedactionurladd  = [];
                         var markedactionsetting = [];
                            '. $javascr .'
                      </script>';
            }
            $ret .= '<div class="aa_manager_actions_all" style="display: none">'._m("No item selected. Perform action for all filtered items? ").'<input type="hidden" name="aa_akce_all" value=""><button class="aa-button" onclick="aa$N(\'aa_akce_all\').value=1;MarkedActionGo();">'._m('Run for %1 items',[$this->scroller->getItemCount()]).'</button></div>';
            $ret .= "</td>\n</tr>\n<tr><td id=\"markedactionparams\"></td></tr>\n<tr><td>";
        }
        $ret .= $this->scroller->getNavbar();
        $ret .= '</td></tr></table>';
        $ret .= '</form><br>';
        return $ret;
    }

    /**
     *  get return values from action functions and clears the messages for printing
     */
    function getAndClearMessages() {
        $ret = GetPrintArray($this->msg);
        unset( $this->msg );
        return $ret;
    }

    public function getManaged(): ?Managed {
        return $this->managed;
    }
}


