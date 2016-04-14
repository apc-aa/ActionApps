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
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH . "searchbar.class.php3";
require_once AA_INC_PATH . "statestore.php3";
require_once AA_INC_PATH . "request.class.php3";

/**
 * AA_Manager class - used for 'item' manipulation 'managers' pages
 * (like Item Manager, Link Manager, Discussion comments, Related Items, ...)
 * It takes care about searchber, scroller, actions, ...
 */
class AA_Manager extends AA_Storable {
    var $searchbar;       // AA_Searchbar object
    var $searchbar_funct; // searchbar aditional function
    var $scroller;        // scroller object
    var $actions;
    var $actions_perm_function;
    var $actions_hint;
    var $actions_hint_url;
    var $switches;
    var $messages;        // various language messages (title, noitem_msg, about)
    var $itemview;        // itemview object
    var $show;            // what controls show (scroller, searchbar, ...)
    var $bin;             // stores the bin in which we are - string
    var $module_id;       // for which module is manager used (slice_id, ...)
    var $managed_class;   // name of the class which are managed / edited
    var $_manager_id;     // ID of manager

    var $msg;             // stores return code from action functions

    // PHPLib variables - used to store class instances into sessions
    // (in fact, we do not use PHPLib sessions for storing manager class
    //  object - there are some issues, like you have to have defined the
    //  class before calling page_open(), but it is quite hard to do, because
    //  of language settings (for example), which is stored right in sessions.
    //  We use our own AA_Storable for this purpose (in manager, searchbar,
    //  scroller), but we are using the $persistent_slots array in the same way)

    // required - class name (just for PHPLib sessions)
    var $classname = "AA_Manager";

    // required - object's slots to save in session
    //            (no itemview, actions, switches, messages, searchbar_funct
    //             - we want to have it fresh (from constructor and $settings))
    var $persistent_slots = array('searchbar', 'scroller', 'msg', 'bin', 'module_id', '_manager_id');

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties() {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return array (
            'searchbar'   => new AA_Property( 'searchbar',   _m("Searchbar"),  'AA_Searchbar', false, true),
            'scroller'    => new AA_Property( 'scroller',    _m("Scroller"),   'AA_Scroller',  false, true),
            'msg'         => new AA_Property( 'msg',         _m("Msg"),        'text',         true,  true),
            'bin'         => new AA_Property( 'bin',         _m("Bin"),        'text',         false, true),
            'module_id'   => new AA_Property( 'module_id',   _m("Module ID"),  'text',         false, true),
            '_manager_id' => new AA_Property( '_manager_id', _m("Manager ID"), 'text',         false, true),
            );
    }

    /** AA_Manager function
     * constructor - initializes manager - creates scroller, searchbar, ...
     * based on $settings structure
     *
     * @param array $settings - main manager settings
     */
    function __construct($manager_id, $settings) {
        global $r_state, $sess, $auth;

        $this->show        = isset($settings['show']) ? $settings['show'] : MGR_ALL ;
        $this->module_id   = $settings['module_id'];
        $this->_manager_id = $manager_id;

        if ($manager_id =='email') {
            //huhl('ooo1', $settings);
            //huhl('ooo2', $this->module_id);
        }

        if ( $settings['actions'] ) {      // define actions, if we have to
            $this->actions = $settings['actions'];
            $this->actions_perm_function = $settings['actions_perm_function'];
            $this->actions_hint = $settings['actions_hint'];
            $this->actions_hint_url = $settings['actions_hint_url'];
        }

        if ( $settings['switches'] ) {      // define switches, if we have to
            $this->switches = $settings['switches'];
        }
            //huhl('ooo3', $this->module_id);

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
            //huhl('ooo4', $this->module_id);

        // create page scroller -----------------------------------------------
        // could be redefined by view (see ['itemview']['manager_vid'])
        $scroller = new AA_Scroller('st', sess_return_url($_SERVER['PHP_SELF']), $settings['scroller']['listlen']);
//        $scroller->addFilter("slice_id", "md5", $this->module_id);
        $this->scroller = $scroller;

        $this->messages = $settings['messages'];
        if ( !isset($this->messages['noitem_msg']) ) {
            // could be redefined by view (see ['itemview']['manager_vid'])
            $this->messages['noitem_msg'] =
                  get_if($settings['itemview']['format']['noitem_msg'],
                  _m('No item found'));
        }
            //huhl('ooo5', $this->module_id);

        // create itemview ----------------------------------------------------

        $manager_vid    = $settings['itemview']['manager_vid'];
        $format_strings = $settings['itemview']['format'];
        $aliases        = $settings['itemview']['aliases'];

        // modify $format_strings and $aliases (passed by reference)
        $this->setDesign($format_strings, $aliases, $manager_vid);

        $this->itemview = new itemview( $format_strings,
                                        $settings['itemview']['fields'],
                                        $aliases,
                                        false,   // no item ids yet
                                        0,       // first item
                                        $this->scroller->getListlen(),
                                        '',      // not necessary I think: $settings['itemview']['url'],  // $r_slice_view_url
                                        '',      // no discussion settings
                                        $settings['itemview']['get_content_funct']);
            //huhl('ooo6', $this->module_id);

        // r_state array holds all configuration of Manager
        // the configuration then could be Bookmarked
        if ( !isset($r_state) ) {
            $r_state = array();
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
            //huhl('ooo7', $this->module_id);

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
            $view = AA_Views::getView($manager_vid);
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

    /** get module id of the module, which is managed by the manager */
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
     * @param $profile
     */
    function setFromProfile(&$profile) {

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
        if ( $this->searchbar ) {
            return $this->searchbar->getSet();
        }
        return new AA_Set;  // empty set
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

    function getBookmarkNames() {
        if ( $this->searchbar ) {
            return $this->searchbar->getBookmarkNames();
        }
        return array();
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

    function getAction($akce) {
        if (!is_object($this->actions)) {
            return false;
        }
        $actions = $this->actions;
        return $actions->getAction($akce);
    }

    /** performActions function
     *
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

        // new approach uses AA_Manageractions
        if (is_object($this->actions)) {
            $actions   = $this->actions;
            $action2do = $actions->getAction($akce);
            if ( $akce AND $action2do AND $action2do->isPerm($this)) {
                $this->msg[] = $action2do->perform($this, $GLOBALS['r_state'], $chb, $_REQUEST['akce_param']);
            }
        // older approach - @todo should be removed after we rewrite all managers
        } else {
            $action2do = $this->actions[$akce];
            $actions_perm_function = $this->actions_perm_function;

            if ( $akce AND $action2do AND $actions_perm_function($akce) ) {
                $function   = $action2do['function'];   // programmer defined action to do
                $func_param = $action2do['func_param']; // aditional parameter for the 'function'
                if ( $function AND isset($chb) AND is_array($chb) ) {
                    if ( $action2do['type'] == 'one_by_one' ) {
                        // call action-function for each checked item
                        foreach ( $chb as $item_id => $foo ) {
                            $this->msg[] = $function($func_param, $item_id, $_REQUEST['akce_param']);
                        }
                    } else {
                        // call action-function for whole list of checked items
                        $this->msg[] = $function($func_param, $chb, $_REQUEST['akce_param']);
                    }
                }
            }
        }

        // new approach uses AA_Manageractions
        if (is_object($this->switches)) {
            $switches     = $this->switches;
            $switches_arr = $switches->getArray();
            foreach ( $switches_arr as $sw => $switch ) {
                if ( isset($_GET[$sw]) AND $switch->isPerm($this)) {
                    $this->msg[] = $switch->perform($this, $GLOBALS['r_state'], $chb, $_GET[$sw]);
                }
            }

        // older approach - @todo should be removed after we rewrite all managers
        } else {

            // now perform switches (url parameteres - not in 'akce' field
            // (like listlen=100, Tab=app, ...)
            if ( isset($this->switches) AND is_array($this->switches) ) {
                foreach ( $this->switches as $sw => $val ) {
                    $actions_perm_function = $this->actions_perm_function;
                    if ( isset($_GET[$sw]) AND $actions_perm_function($sw) ) {
                        if ( $val['function'] ) {
                            $function = $val['function'];
                            $this->msg[] = $function($_GET[$sw], $val['func_param'], "");
                        }
                    }
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
     **/
    function display($zids) {
        global $r_err, $r_msg;          // @todo - check if it is still needed

        if ($this->messages['title']) {
            echo '<h1>'. $this->messages['title'] .'</h1>';
        }
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
        global $r_state, $aamenus;
        $this->printHtmlPageBegin(true, $css_add);  // html, head, css, title, javascripts

        showMenu($aamenus, $menu_top, $menu_left);

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
     */
    function printItems($zids) {
        echo '<form name="itemsform" id="itemsform" method="post" action="'. StateUrl() .'">';

        $ids_count = $zids->count();
        if ( $ids_count == 0 ) {
            echo "<div class=\"tabtxt\">". $this->itemview->unaliasWithScrollerEasy($this->messages['noitem_msg']). "</div></form><br>";
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

            // new approach uses AA_Manageractions
            if (is_object($this->actions)) {
                $actions    = $this->actions;
                $action_arr = $actions->getArray();
                $i       = 1;  // we start on 1 because first option is "Select action:"
                $options = '';

                foreach( $action_arr as $action_id => $action ) {
                    if ( $action->isPerm($this)) {
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

            // older approach - @todo should be removed after we rewrite all managers
            } else {

                if ( isset( $this->actions ) AND is_array( $this->actions ) ) {
                    $i=1;  // we start on 1 because first option is "Select action:"
                    while ( list( $action, $param ) = each ($this->actions) ) {
                        $actions_perm_function = $this->actions_perm_function;
                        if ( $actions_perm_function( $action ) ) {
                            $options .= '<option value="'. myspecialchars($action).'"> '.
                                                           myspecialchars($param['name'] . ($param['open_url'] ? '...' : ''));
                            if ( $param['open_url'] )  { // we have to open window
                                $javascr .= "\n markedactionurl[$i] = '". $param['open_url'] ."';";
                                if ( $param['open_url_add'] )  { // we have to open window
                                    $javascr .= "\n markedactionurladd[$i] = '". $param['open_url_add'] ."';";
                                }
                            }
                            $i++;
                        }
                    }
                }
            }

            if ( $options ) {
                echo "<img src=\"".AA_INSTAL_PATH."images/arrow_ltr.gif\">
                    <a href=\"javascript:SelectVis()\">". _m('Select all')."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

                  // click "go" does not use markedform, it uses itemsfrom above...
                  // maybe this action is not used.
                echo '<select name="markedaction_select" id="markedaction_select" onchange="MarkedActionSelect()" class="markedaction_select">
                      <option value="nothing">'. _m('Selected items') .':'.
                      $options .'</select>';
                if ($this->actions_hint_url || $this->actions_hint) {
                    echo FrmMoreHelp($this->actions_hint_url, "", $this->actions_hint);
                }

                echo '&nbsp;&nbsp;<a href="javascript:MarkedActionGo()" class="leftmenuy">'. _m('Go') . '</a>';

                  // we store open_url parameter to js variable for
                  // MarkedActionGo() function
                echo '<script type="text/javascript"> <!--
                         var markedactionurl     = [];
                         var markedactionurladd  = [];
                         var markedactionsetting = [];
                            '. $javascr .'
                        // -->
                      </script>';
            }
            echo "</td>\n</tr>\n<tr height=\"3\"><td id=\"makrekactionparams\"></td></tr>\n<tr><td>";

        }

        if (($this->scroller->pageCount() > 1) AND ($action_selected != "0")) {
            echo '<b>'. _m('Items Page') .":&nbsp;&nbsp;";
            $this->scroller->pnavbar();
            echo "</b>";
        }
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

    var $id;
    var $open_url;
    var $open_url_add;

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
     */
    static function getClassProperties() {          //  id             name                              type    multi  persistent - validator, required, help, morehelp, example
        return array (
            'id'            => new AA_Property( 'id',           _m('Action ID'),                  'text', false, true),
            'open_url'      => new AA_Property( 'open_url',     _m('URL to open' ),               'text', false, true),
            'open_url_add'  => new AA_Property( 'open_url_add', _m('Additional URL parameters' ), 'text', false, true)
            );
    }

    /** Name of this Manager's action */
    function getName() {}

    /** Name of this Manager's action */
    function getId()         { return $this->id; }

    /** Should this action open new window? And if so, which one? */
    function getOpenUrl()    { return $this->open_url; }

    /** Any addition to url */
    function getOpenUrlAdd() { return $this->open_url_add; }

    function setOpenUrl($url, $add=null) {
        $this->open_url     = $url;
        $this->open_url_add = $add;
    }

    /** main executive function
    * @param $manager    - back link to the manager
    * @param $state      - state array
    * @param $param
    * @param $item_arr
    * @param $akce_param
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return true;
    }

    /** Do this action have some settings, which should be displayed? */
    function isSetting() {
        return is_callable(array($this, 'htmlSettings'));
    }

    /** static */
    function getZidsSanitized(&$item_arr, $slice_id=null) {
        $zids = new zids;
        $zids->setFromItemArr($item_arr);

        if (($zids->count() < 1) OR !$slice_id) {
            return $zids;
        }

        // check if there are no ids from bad slice (attack???)
        $SQL = "SELECT id FROM item WHERE slice_id = '". q_pack_id($slice_id) ."' AND ". $zids->sqlin('id');
        return new zids(GetTable2Array($SQL, '', 'id'), 'p');
    }
}

class AA_Manageractions {
    /** set of AA_Manageraction s */
    var $actions;

    function __construct() {
        $this->actions = array();
    }

    function getAction($id) {
        return isset($this->actions[$id]) ? $this->actions[$id] : false;
    }

    /** We unfortunately need this function, because in manager.class.php3
     *  we have to loop through all switches and the Iterator is not available
     *  for PHP4
     */
    function &getArray() {
        return $this->actions;
    }

    function addAction($action) {
        return $this->actions[$action->getId()] = $action;
    }
}

?>
