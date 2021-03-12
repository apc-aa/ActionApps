<?php
/**
 * File contains definition of AA_Manager class - used for 'item' manipulation
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

require_once __DIR__."/menu_util.php3";  // for showMenu()

/**
 * AA_Manager class - used for 'item' manipulation 'managers' pages
 * (like Item Manager, Link Manager, Discussion comments, Related Items, ...)
 * It takes care about searchber, scroller, actions, ...
 */
class AA_Manager extends AA_Storable {
    /** @var AA_Searchbar  */
    var $searchbar;

    /** @var string searchbar aditional function name */
    var $searchbar_funct;

    /** @var AA_Scroller */
    var $scroller;

    /** @var AA_Manageractions  */
    var $actions;

    var $actions_hint;
    var $actions_hint_url;

    /** @var AA_Manageractions  */
    var $switches;

    var $messages;        // various language messages (title, noitem_msg, about)
    var $itemview;        // itemview object
    var $show;            // what controls show (scroller, searchbar, ...)
    var $bin;             // stores the bin in which we are - string
    var $module_id;       // for which module is manager used (slice_id, ...)
    var $managed_class;   // name of the class which are managed / edited
    var $_manager_id;     // ID of manager

    var $msg;             // stores return code from action functions

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

    /** AA_Manager function
     * constructor - initializes manager - creates scroller, searchbar, ...
     * based on $settings structure
     *
     * @param string $manager_id
     * @param array  $settings - main manager settings
     */
    function __construct($manager_id, $settings) {
        global $r_state, $sess, $auth;

        $this->show        = isset($settings['show']) ? $settings['show'] : MGR_ALL ;
        $this->module_id   = $settings['module_id'];
        $this->_manager_id = $manager_id;

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

        $this->bin           = isset($settings['bin']) ? $settings['bin'] : 'app';
        $this->managed_class = isset($settings['managed_class']) ? $settings['managed_class'] : '';

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

        // create itemview ----------------------------------------------------

        $manager_vid    = $settings['itemview']['manager_vid'];
        $format_strings = $settings['itemview']['format'];
        $aliases        = $settings['itemview']['aliases'];

        // modify $format_strings and $aliases (passed by reference)
        $this->setDesign($format_strings, $aliases, $manager_vid);

        $this->itemview = new itemview($format_strings, $aliases, false, 0, $this->scroller->getListlen(), '', '', $settings['itemview']['get_content_funct']);

        // r_state array holds all configuration of Manager
        // the configuration then could be Bookmarked
        if ( !isset($r_state) ) {
            $r_state = [];
            $sess->register('r_state');
        }
        // user switched to another page with different manager?
        if ($r_state["manager_id"] != $this->_manager_id) {
            // we are here for the first time or we are switching to another slice
            unset($r_state['manager']);
            // set default admin interface settings from user's profile

            $profile = AA_Profile::getProfile($auth->auth["uid"], $this->module_id); // current user settings
            $this->setFromProfile($profile);
        } elseif ($r_state['manager']) {        // do not set state for the first time calling
            $this->setFromState($r_state['manager']);
        }
    }

    /** setDesing function
     *  Fills format array with manager default design and ensures, the aliases are
     *  properly. If aliases there are not needed aliases, the function will add it
     * @param $format_strings
     * @param $aliases
     * @param $manager_vid
     */
    function setDesign(&$format_strings, &$aliases, $manager_vid = null) {

        if ( $manager_vid ) {
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
            DefineBaseAliases($aliases, $this->module_id);

            if ( !$format_strings ) {
                $row = '<td>_#PUB_DATE&nbsp;</td><td>_#HEADLINE</td>'. (isset($aliases["_#AA_ACTIO"]) ? '<td>_#AA_ACTIO</td>': '');
                $format_strings["odd_row_format"]  = '<tr class="tabtxt">'.$row.'</tr>';
                $format_strings["even_row_format"] = '<tr class="tabtxteven">'.$row.'</tr>';
                $format_strings["even_odd_differ"] = 1;
                $format_strings["compact_top"]     = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">
                    <tr class="tabtitlight"><td>'._m("Publish date").'</td><td>'._m("Headline").'</td>'. (isset($aliases["_#AA_ACTIO"]) ? '<td>'._m("Actions").'</td>' : ''). '</tr>';
                $format_strings["compact_bottom"]  = '</table>';
            }
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
        if ($this->module_id) {
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
        return $set;
    }

    /** getConds function
     * Get conditios (conds[] array) for *_QueryIDs from scroller
     * @deprecated - use getSet() instead
     */
    function getConds() {
        $set = $this->getSet();
        return $set->getConds();
    }

    /** getSort function
     * Get sort[] array for *_QueryIDs from scroller
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

    /** reserSearchBar function
     *  Resets the searchbar (both - Search as well as Order)
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
     * @param  array $conds[] = array ( <field> => 1, 'operator' => <operator>,
     *                                  'value' => <search_string> )
     * for description @see searchbar.class.php3
     */
    function addSearchBar($conds) {
        if ( $this->searchbar )
            $this->searchbar->addSearch($conds);
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
     * @return AA_Manageraction
     */
    function getAction($akce) {
        if (!is_object($this->actions)) {
            return null;
        }
        return $this->actions->getAction($akce);
    }

    /** performActions function
     */
    function performActions() {

        $akce = $_REQUEST['akce'];
        $chb  = $_REQUEST['chb'];

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

        $item_zids = (new zids())->setFromItemArr($chb); // in some managers we use 'x' before the item id - remove it

        // new approach uses AA_Manageractions
        if (is_object($this->actions)) {
            $actions   = $this->actions;
            $action2do = $actions->getAction($akce);
            if ( $akce AND $action2do AND $action2do->isPerm4Perform($this, $item_zids, $_REQUEST['akce_param'])) {
                $this->msg[] = $action2do->perform($this, $item_zids, $_REQUEST['akce_param']);
            }
        }

        // new approach uses AA_Manageractions
        if (is_object($this->switches)) {
            $switches     = $this->switches;
            $switches_arr = $switches->getArray();
            foreach ( $switches_arr as $sw => $switch ) {
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

    }

    /** Displays the manager. This function joins together some common method
     *  calls, which was separate. We should use display, now. We plan to move
     *  even more methods here
     * @param zids $zids
     */
    function display($zids) {
        global $r_err, $r_msg;          // @todo - check if it is still needed

        // if ($this->messages['title']) {
        //     echo '<h1>'. $this->messages['title'] .'</h1>';
        // }
        if ($this->messages['about']) {
            echo '<div class="aa-about"><small>'. $this->messages['about'] .'<br><br></small></div>';
        }

        $this->printSearchbarBegin();
        $this->printSearchbarEnd();     // close the searchbar form
        $this->printAndClearMessages();

        PrintArray($r_err);
        PrintArray($r_msg);
        unset($r_err);
        unset($r_msg);
        $this->printItems($zids);       // print items and actions
    }

    function displayPage($zids, $menu_top, $menu_left, $css_add='') {
        global $r_state;
        $this->printHtmlPageBegin(true, $css_add);  // html, head, css, title, javascripts

        showMenu($menu_top, $menu_left);

        if ($_GET['id'] AND $this->managed_class) {
            //huhl('sss1',$this->module_id);
            $form = AA_Form::factoryForm($this->managed_class, $_GET['id'], $this->module_id);
            huhl($form);
            echo $form->getAjaxHtml('xxx'); // ($_GET['ret_code']);
        } else {
            $this->display($zids);
        }

        $r_state['manager']    = $this->getState();
        $r_state['manager_id'] = $this->_manager_id;

        HtmlPageEnd();
    }

    /** printHtmlPageBegin function
     * Print HTML start page tags (html begin, encoding, style sheet, title
     * and includes necessary javascripts for manager
     * @param $head_end
     * @param $css_add  adds custom css to the manager page
     */
    function printHtmlPageBegin( $head_end = false, $css_add='') {
        // Print HTML start page (html begin, encoding, style sheet, no title)
        HtmlPageBegin();
        // manager javascripts - must be included
        echo '<title>'. $this->messages['title'] .'</title>';
        IncludeManagerJavascript();
        if ($css_add) {
             echo "\n  <link rel=\"StyleSheet\" href=\"$css_add\" type=\"text/css\">";

        }
        if ( $head_end ) {
            echo "\n</head>\n";
        }
    }

    /** printSearchbarBegin function
     * Prints begin of search form with searchbar (you can then add more code
     * to searchbar after callin this function. Then you MUST close the form
     * with printSearchbarEnd() function
     */
    function printSearchbarBegin() {
        echo '<form name="filterform" action="'.StateUrl().'" class="noprint">'.StateHidden();
        if ( isset($this->searchbar) ) {
            $this->searchbar->printBar();
        }
    }

    /** printSearchbarEnd function
     * Prints end of search form with searchbar (@see printSearchbarBegin())
     */
    function printSearchbarEnd() {
        echo "</form><p></p>"; // workaround for align=left bug
    }

    /** printItems function
     * Prints item/link/... table with scroller, actions, ...
     * @param $zids
     * @return mixed
     */
    function printItems($zids) {
        echo '<form name="itemsform" id="itemsform" method="post" action="'. StateUrl() .'">';

        $ids_count = $zids->count();
        if ( $ids_count == 0 ) {
            echo "<div class=\"tabtxt\">". $this->itemview->unaliasWithScroller($this->messages['noitem_msg']). "</div></form><br>";
            return $ids_count;
        }

        $this->scroller->countPages( $ids_count );

        // update itemview
        $this->itemview->assign_items($zids);                // ids to show
        $this->itemview->from_record = $this->scroller->metapage * ($this->scroller->current-1);                // from which index begin showing items
        $this->itemview->num_records = $this->scroller->metapage;

        // big security hole is open if we cache it
        // (links to itemedit.php3 would stay with session ids in cache
        // - you bacame another user !!!)

        echo $this->itemview->get_output('view');

        echo '<table border="0" cellpadding="3" class="aa_manager_actions">
                <tr class="aa_manager_actions_tr"><td>';

        if ($this->show & MGR_ACTIONS) {
            echo '<input type="hidden" name="akce" value="">';          // filled by javascript - contains action to perform
            echo '<input type="hidden" name="akce_param" value="">';  // if we need some parameteres to the action, store it here

            $javascr = '';
            $options = '';

            // new approach uses AA_Manageractions
            if (is_object($this->actions)) {
                $actions    = $this->actions;
                $action_arr = $actions->getArray();
                $i       = 1;  // we start on 1 because first option is "Select action:"

                foreach( $action_arr as $action_id => $action ) {
                    if ( $action->isPerm4Action($this)) {
                        $options .= '<option value="'. myspecialchars($action->getId()).'"> '.
                                                       myspecialchars($action->getName() . ($action->getOpenUrl() ? '...' : ''));
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
                echo "<img src=\"".AA_INSTAL_PATH."images/arrow_ltr.gif\">
                    <a href=\"javascript:SelectVis()\">". _m('Select all')."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

                  // click "go" does not use markedform, it uses itemsfrom above...
                  // maybe this action is not used.
                echo '<select name="markedaction_select" id="markedaction_select" onchange="MarkedActionSelect(this)" class="markedaction_select">
                      <option value="nothing">'. _m('Selected items') .':'.
                      $options .'</select>';
                if ($this->actions_hint_url || $this->actions_hint) {
                    echo getFrmMoreHelp($this->actions_hint_url, "", $this->actions_hint);
                }

                echo '&nbsp;&nbsp;<a href="javascript:MarkedActionGo()" class="leftmenuy">'. _m('Go') . '</a>';

                  // we store open_url parameter to js variable for
                  // MarkedActionGo() function
                echo '<script>
                         var markedactionurl     = [];
                         var markedactionurladd  = [];
                         var markedactionsetting = [];
                            '. $javascr .'
                      </script>';
            }
            echo "</td>\n</tr>\n<tr><td id=\"markedactionparams\"></td></tr>\n<tr><td>";
        }

        $this->scroller->pnavbar();
        
        echo '</td></tr></table>';

        echo '</form><br>';
        return $ids_count;
    }

    /** printAndClearMessages function
     *  Prints return values from action functions and clears the messages
     */
    function printAndClearMessages() {
        PrintArray( $this->msg );
        unset( $this->msg );
    }
}

/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 *
 *  We extending AA_Storable, because we want to get the state form some
 *  actions. Action selectbox is able to display settings by AJAX call, where
 *  we need to pass all parameters of the object
 */
class AA_Manageraction extends AA_Storable {

    var $id;             /** @var string */
    var $open_url;       /** @var string */
    var $open_url_add;   /** @var string */

    /** constructor - assigns identifier of action */
    function __construct($id, $open_url=null, $open_url_add=null) {
        $this->id = $id;
        if ($open_url) {
            $this->setOpenUrl($open_url, $open_url_add);
        }
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     * @return array
     */
    static function getClassProperties(): array {          //  id             name                              type    multi  persistent - validator, required, help, morehelp, example
        return [
            'id'            => new AA_Property( 'id',           _m('Action ID'),                  'text', false, true),
            'open_url'      => new AA_Property( 'open_url',     _m('URL to open' ),               'text', false, true),
            'open_url_add'  => new AA_Property( 'open_url_add', _m('Additional URL parameters' ), 'text', false, true)
        ];
    }

    /** Name of this Manager's action
     *  @return string
     */
    function getName() {}

    /** ID of this Manager's action
     *  @return string
     */
    function getId()         { return $this->id; }

    /** Should this action open new window? And if so, which one?
     *  @return string
     */
    function getOpenUrl()    { return $this->open_url; }

    /** Any addition to url
     *  @return string
     */
    function getOpenUrlAdd() { return $this->open_url_add; }

    /**
     * @param string      $url
     * @param string|null $add
     */
    function setOpenUrl($url, $add=null) {
        $this->open_url     = $url;
        $this->open_url_add = $add;
    }

    /** main executive function
     * @param AA_Manager $manager - back link to the manager
     * @param            $item_zids
     * @param string     $action_param
     */
    function perform($manager, $item_zids, $action_param) {
    }

    /** Checks if the user have enough permission to PERFORM the action on items
     * @param AA_Manager $manager
     * @param $item_zids
     * @param $action_param
     * @return bool
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        return $this->isPerm4Action($manager);  // in most cases it is enough
    }

    /** Checks if the user have enough permission to allow him/her to SELECT the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return true;
    }

    /** Do this action have some settings, which should be displayed? */
    function isSetting() {
        return is_callable([$this, 'htmlSettings']);
    }
}

class AA_Manageractions {
    /** @var AA_Manageraction[] */
    var $actions;

    function __construct() {
        $this->actions = [];
    }

    /**
     * @param $id
     * @return AA_Manageraction|null
     */
    function getAction($id) {
        return $this->actions[$id] ?: null;
    }

    /** We unfortunately need this function, because in manager.class.php3
     *  we have to loop through all switches and the Iterator is not available
     *  for PHP4
     */
    function &getArray() {
        return $this->actions;
    }

    /**
     * @param AA_Manageraction $action
     * @return AA_Manageraction
     */
    function addAction($action) {
        return $this->actions[$action->getId()] = $action;
    }
}


