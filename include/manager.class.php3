<?php
/**
 * File contains definition of manager class - used for 'item' manipulation
 * 'managers' pages (like Item Manager, Link Manager, Discussion comments,
 * Related Items, ...) It takes care about searchber, scroller, actions, ...
 *
 * Should be included to other scripts (as /admin/index.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

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

require_once $GLOBALS[AA_INC_PATH] . "searchbar.class.php3";
require_once $GLOBALS[AA_INC_PATH] . "statestore.php3";

/**
 * manager class - used for 'item' manipulation 'managers' pages
 * (like Item Manager, Link Manager, Discussion comments, Related Items, ...)
 * It takes care about searchber, scroller, actions, ...
 */
class manager extends storable_class {
    var $searchbar;       // searchbar object
    var $searchbar_funct; // searchbar aditional function
    var $scroller;        // scroller object
    var $actions;
    var $actions_perm_function;
    var $actions_hint;
    var $actions_hint_url;
    var $switches;
    var $messages;        // various language messages (like page title, ...)
    var $itemview;        // itemview object
    var $show;            // what controls show (scroller, searchbar, ...)

    var $msg;             // stores return code from action functions

    // PHPLib variables - used to store class instances into sessions
    // (in fact, we do not use PHPLib sessions for storing manager class
    //  object - there are some issues, like you have to have defined the
    //  class before calling page_open(), but it is quite hard to do, because
    //  of language settings (for example), which is stored right in sessions.
    //  We use our own storable_class for this purpose (in manager, searchbar,
    //  scroller), but we are using the $persistent_slots array in the same way)

    // required - class name (just for PHPLib sessions)
    var $classname = "manager";

    // required - object's slots to save in session
    //            (no itemview, actions, switches, messages, searchbar_funct
    //             - we want to have it fresh (from constructor and $settings))
    var $persistent_slots = array('searchbar', 'scroller', 'msg');

    /**
     * constructor - initializes manager - creates scroller, searchbar, ...
     * based on $settings structure
     *
     * @param array $settings - main manager settings
     */
    function manager($settings) {

        $this->show = isset($settings['show']) ?
                      $settings['show'] :
                      ( MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS );

        if ( $settings['actions'] ) {      // define actions, if we have to
            $this->actions = $settings['actions'];
            $this->actions_perm_function = $settings['actions_perm_function'];
            $this->actions_hint = $settings['actions_hint'];
            $this->actions_hint_url = $settings['actions_hint_url'];
        }

        if ( $settings['switches'] )      // define switches, if we have to
            $this->switches = $settings['switches'];

        // create searchbar, if we have to ------------------------------------
        if ( $settings['searchbar'] ) {
            $this->searchbar = new searchbar(
                              $settings['searchbar']['fields'],
                              'filterform',   // form name is given in this case
                              $settings['searchbar']['search_row_count_min'],
                              $settings['searchbar']['order_row_count_min'],
                              $settings['searchbar']['add_empty_search_row'],
                              $this->show,
                              $settings['searchbar']['hint'],
                              $settings['searchbar']['hint_url']);
            $this->searchbar_funct = $settings['searchbar']['function'];
            if( isset($settings['searchbar']['default_bookmark']) ) {
                $this->searchbar->setFromBookmark($settings['searchbar']['default_bookmark']);
            }
        }

        // create page scroller -----------------------------------------------
        $scroller = new scroller('st',sess_return_url($_SERVER['PHP_SELF'])."&");
        // could be redefined by view (see ['itemview']['manager_vid'])
        $scroller->metapage = $settings['scroller']['listlen'];
        $scroller->addFilter("slice_id", "md5", $settings['scroller']['slice_id']);
        $this->scroller = $scroller;

        $this->messages = $settings['messages'];
        if( !isset($this->messages['noitem_msg']) ) {
            // could be redefined by view (see ['itemview']['manager_vid'])
            $this->messages['noitem_msg'] =
                  get_if($settings['itemview']['format']['noitem_msg'],
                  _m('No item found'));
        }

        // create itemview ----------------------------------------------------
        if ( $settings['itemview']['manager_vid'] ) {
            $view_info = GetViewInfo( $settings['itemview']['manager_vid'] );
            if ( $view_info AND !($view_info['deleted']>0) ) {
                $format_strings = GetViewFormat($view_info);
                $this->messages['noitem_msg'] = $view_info['noitem_msg'];
                if( isset($this->scroller) )
                    $this->scroller->metapage = $view_info['listlen'];
            }
        }

        if ( !$format_strings )
            $format_strings = $settings['itemview']['format'];

        $this->itemview = new itemview( $format_strings,
                                       $settings['itemview']['fields'],
                                       $settings['itemview']['aliases'],
                                       false,   // no item ids yet
                                       0,       // first item
                                       $this->scroller->metapage,
                                       '',      // not necessary I think: $settings['itemview']['url'],  // $r_slice_view_url
                                       '',      // no discussion settings
                                       $settings['itemview']['get_content_funct']);
    }

    /** initial manager setting from user's profile */
    function setFromProfile(&$profile) {
        // set default admin interface settings from user's profile

        // get default number of listed items from user's profile
        $this->setListlen( $profile->getProperty('listlen') );

        if( $this->searchbar ) {
            $this->searchbar->setFromProfile($profile);
        }
    }


    /**
     * Get conditios (conds[] array) for *_QueryIDs from scroller
     */
    function getConds() {
        if( $this->searchbar )
            return $this->searchbar->getConds();
        return false;
    }

    /**
     * Get sort[] array for *_QueryIDs from scroller
     */
    function getSort() {
        if( $this->searchbar )
            return $this->searchbar->getSort();
        return false;
    }

    /** Resets the searchbar (both - Search as well as Order)  */
    function resetSearchBar() {
        if( $this->searchbar )
            $this->searchbar->resetSearchAndOrder();
    }

    /**
     * Adds new Order bar(s)
     * @param  array $sort[] = array( <field> => <a|d> )
     *               value other than 'a' means DESCENDING
     * for description @see searchbar.class.php3
     */
    function addOrderBar($sort) {
        if( $this->searchbar )
            $this->searchbar->addOrder($sort);
    }


    /**
     * Adds new Search bar(s)
     * @param  array $conds[] = array ( <field> => 1, 'operator' => <operator>,
     *                                  'value' => <search_string> )
     * for description @see searchbar.class.php3
     */
    function addSearchBar($conds) {
        if( $this->searchbar )
            $this->searchbar->addSearch($conds);
    }

    /** Sets listing length - number of items per page */
    function setListlen( $listlen ) {
        if ( $this->scroller AND ($listlen > 0) ) {
            $this->scroller->metapage = $listlen;
            $this->scroller->go2page(1);
        }
    }

    /** Go to specified page (obviously 1) in scroller  */
    function go2page( $page ) {
        if ( $this->scroller AND ($page > 0) )
            $this->scroller->go2page($page);
    }

    /**
     *
     */
    function performActions() {

        $akce = $_POST['akce'];
        $chb  = $_POST['chb'];
/*
        if (!isset($akce)) {
            $akce = $_GET['akce'];
        }
        if (!isset($chb)) {
            $akce = $_GET['chb'];
        }
*/
        // update scroller
        if ( isset($this->scroller) ) {
            $this->scroller->updateScr(sess_return_url($_SERVER['PHP_SELF'])."&"); // use $return_url if set.
            if ( $_GET['listlen'] ) {
                $this->setListlen($_GET['listlen']);
            }
            // new search - go to first page
            if ( $_POST['srchbr_akce']) {
                $this->go2page(1);
            }
        }

        // call custom searchbar function (if searchbar action invoked)
        // used for additional search functions like 'category search' in Links
        if ( $this->searchbar_funct AND $_POST['srchbr_akce'] ) {
            $function2call = $this->searchbar_funct;
            $function2call();
        }

        // update searchbar
        if ( isset($this->searchbar) )
            $this->searchbar->update();

        $action2do = $this->actions[$akce];
        $actions_perm_function = $this->actions_perm_function;

        if ( $akce AND $action2do AND $actions_perm_function($akce) ) {
            $function   = $action2do['function'];   // programmer defined action to do
            $func_param = $action2do['func_param']; // aditional parameter for the 'function'
            if ( $function AND isset($chb) AND is_array($chb) ) {
                if( $action2do['type'] == 'one_by_one' ) {
                    // call action-function for each checked item
                    foreach ( $chb as $item_id => $foo ) {
                        $this->msg[] = $function($func_param, $item_id, $_POST['akce_param']);
                    }
                } else {
                    // call action-function for whole list of checked items
                    $this->msg[] = $function($func_param, $chb, $_POST['akce_param']);
                }
            }
        }

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

    /**
     * Print HTML start page tags (html begin, encoding, style sheet, title
     * and includes necessary javascripts for manager
     */
    function printHtmlPageBegin( $head_end = false) {
        // Print HTML start page (html begin, encoding, style sheet, no title)
        HtmlPageBegin();
        // manager javascripts - must be included
        echo '<title>'. $this->messages['title'] .'</title>';
        IncludeManagerJavascript();
        if ( $head_end )
            echo "\n</head>\n";
    }

    /**
     * Prints begin of search form with searchbar (you can then add more code
     * to searchbar after callin this function. Then you MUST close the form
     * with printSearchbarEnd() function
     */
    function printSearchbarBegin() {
        global $sess;
        echo '<form name=filterform method=post action="'.$_SERVER['PHP_SELF'].'">';
        $sess->hidden_session();
        if ( isset($this->searchbar) )
            $this->searchbar->printBar();
    }

    /**
     * Prints end of search form with searchbar (@see printSearchbarBegin())
     */
    function printSearchbarEnd() {
            echo "</form><p></p>"; // workaround for align=left bug
    }

    /**
     * Prints item/link/... table with scroller, actions, ...
     */
    function printItems($zids) {
        global $sess;
        echo '<form name=itemsform method=post action="'. $_SERVER['PHP_SELF'] .'">';
        $sess->hidden_session();

        $ids_count = $zids->count();
        if( $ids_count == 0 ) {
            echo "<div class=tabtxt>". $this->messages['noitem_msg']. "</div></form><br>";
            return $ids_count;
        }

        // update itemview
        $this->itemview->assign_items($zids);                // ids to show
        $this->itemview->from_record = $this->scroller->metapage * ($this->scroller->current-1);                // from which index begin showing items
        $this->itemview->num_records = $this->scroller->metapage;

        // big security hole is open if we cache it
        // (links to itemedit.php3 would stay with session ids in cache
        // - you bacame another user !!!)
        $this->itemview->print_view("NOCACHE");

        $this->scroller->countPages( $ids_count );

        if (($this->scroller->pageCount() > 1) || ($action_selected != "0")) {
            echo "<table border=0 cellpadding=3>
                   <tr>
                    <td class=tabtxt>";
        }

        if ($action_selected != "0") {
            echo '<input type=hidden name=akce value="">';          // filled by javascript - contains action to perform
            echo '<input type=hidden name="akce_param" value="">';  // if we need some parameteres to the action, store it here

            if ( isset( $this->actions ) AND is_array( $this->actions ) ) {
                $i=1;  // we start on 1 because first option is "Select action:"
                while ( list( $action, $param ) = each ($this->actions) ) {
                    $actions_perm_function = $this->actions_perm_function;
                    if ( $actions_perm_function( $action ) ) {
                        $options .= '<option value="'. htmlspecialchars($action).'"> '.
                                                       htmlspecialchars($param['name'] . ($param['open_url'] ? '...' : ''));
                        if( $param['open_url'] )  { // we have to open window
                            $javascr .= "\n markedactionurl[$i] = '". $param['open_url'] ."';";
                            if( $param['open_url_add'] )  { // we have to open window
                                $javascr .= "\n markedactionurladd[$i] = '". $param['open_url_add'] ."';";
                            }
                        }
                        $i++;
                    }
                }
            }

            if ( $options ) {
                echo "<img src='".$GLOBALS['AA_INSTAL_PATH']."images/arrow_ltr.gif'>
                    <a href='javascript:SelectVis()'>". _m('Select all')."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

                  // click "go" does not use markedform, it uses itemsfrom above...
                  // maybe this action is not used.
                echo '<select name="markedaction_select">
                      <option value="nothing">'. _m('Selected items') .':'.
                      $options .'</select>';
                if ($this->actions_hint_url || $this->actions_hint) {
                    echo FrmMoreHelp($this->actions_hint_url, "", $this->actions_hint);
                }

                echo '&nbsp;&nbsp;<a href="javascript:MarkedActionGo()"
                      class=leftmenuy>'. _m('Go') . '</a>';

                  // we store open_url parameter to js variable for
                  // MarkedActionGo() function
                echo '<script language="JavaScript" type="text/javascript"> <!--
                         var markedactionurl=Array();
                         var markedactionurladd=Array();
                            '. $javascr .'
                        // -->
                      </script>';
            }
        }

        if (($this->scroller->pageCount() > 1) || ($action_selected != "0")) {
            if ($this->scroller->pageCount() > 1) {
                echo '</td></tr><tr height=3><td></td></tr>
                    <tr><td class=tabtxt><b>'. _m('Items Page') .":&nbsp;&nbsp;";
                $this->scroller->pnavbar();
                echo "</b>";
            }
            echo '</td></tr></table>';
        }

        echo '</form><br>';
        return $ids_count;
    }

    /** Prints return values from action functions and clears the messages */
    function printAndClearMessages() {
        PrintArray( $this->msg );
        unset( $this->msg );
    }
}

?>
