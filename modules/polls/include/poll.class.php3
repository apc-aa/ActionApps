<?php
/**
 * A class for manipulating polls
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

require_once AA_INC_PATH."zids.php3"; // Pack and unpack ids

class AA_Poll {
    var $name;         // The name of the poll
    var $poll_id;      // The unpacked id of the poll i.e. 32 chars
    var $content;      // poll content - Record from polls table stored as ItemContent
    var $_sum;         // sum of all votes of this poll - caching purposes

    /** AA_Poll function - constructor
     * @param $poll_id
     */
    function __construct($poll_id) {
        $this->poll_id = $poll_id; // unpacked id
        $this->_sum    = null;
    }

    /** loadSettings function
     *  Load $this from the DB for any of $fields not already loaded
     * @param $force
     */
    function loadSettings($force=false) {
        if ( !$force AND isset($this->content) AND is_array($this->content) ) {
            return;
        }

        $content = AA_Metabase::getContent(array('table'=>'polls'), new zids($this->poll_id));
        $this->content = new ItemContent($content[$this->poll_id]);
    }

    /** getProperty function
     * @param $fname
     * @return
     */
    function getProperty($fname) {
        $this->loadSettings();
        return $this->content->getValue($fname);
    }

    /** Get Unpacked Module ID */
    function getModuleId() { return unpack_id($this->getProperty('module_id')); }

    function getVotesSum() {
        if (is_null($this->_sum)){
            $this->_sum = (int)GetTable2Array("SELECT SUM(votes) as sum FROM polls_answer WHERE poll_id = '".$this->poll_id."'", 'aa_first', 'sum');
        }
        return $this->_sum;
    }

    /** name function  */
    function name() {
        return $this->getProperty('name');
    }

    /** get id of poll */
    function id() {
        return $this->poll_id; // Return a 32 character id
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     * @param $design  beforevote|aftervote|<design_id>
     * @return array
     */
    function get_format_strings($design='beforevote') {
        $this->loadSettings();
        switch ($design) {
            case 'aftervote':   $design_id = $this->getProperty('aftervote_design_id');
                                break;
                                // if not found, take standard design
            case 'beforevote':  $design_id = false;  // default
                                break;
            default:            $design_id = $design;
        }
        // set deafault desing, if not specified
        if (!$design_id) {
            $design_id = $this->getProperty('design_id');
        }

        $design = GetTable2Array("SELECT top, answer, bottom FROM polls_design WHERE id = '".quote($design_id)."'", 'aa_first');
        // additional string for compact_top and compact_bottom needed
        // for historical reasons (not manager.class verion of item manager)
        return array ( "compact_top"     => $design['top'],
                       "category_sort"   => false,
                       "category_format" => "",
                       "category_top"    => "",
                       "category_bottom" => "",
                       "even_odd_differ" => false,
                       "even_row_format" => "",
                       "odd_row_format"  => $design['answer'],
                       "compact_remove"  => '',
                       "compact_bottom"  => $design['bottom'],
                       "noitem_msg"      => '',
                       // id is packed (format string are used as itemview
                       //               parameter, where $slice_info expected)
                       "id"              => $this->id() ); // we need id for invalidating cache in itemview
    }

    /** aliases function
     *  Get standard aliases definition from poll's fields
     * @param $additional_aliases
     * @return array
     */
    function aliases() {
        return GetPollsAliases();
    }

    /** @return true, if the string is column in polls table */
    function isField($string) {
        static $columns = null;
        if (is_null($columns)) {
            $metabase = AA::Metabase();
            $columns  = $metabase->getColumnNames('polls');
        }
        return in_array($string, $columns);
    }

    function unalias($expression) {
        $this->loadSettings();
        if ($this->isField($expression)) {
            return $this->getProperty($expression);
        }
        $item = new AA_Item($this->content->getContent(), $this->aliases(), $expression);
        return $item->get_item();
    }

    function registerVote($vote_id) {
        $vote_invalid = false;
        $current_time = now();
        $poll_id       = $this->id();

        $varset = new CVarset;

        // checkig for duplicated votes - ip_locking method
        if ($this->getProperty('locked') == 1) {
            $vote_invalid = "Locked";
        }
        if ($this->getProperty('ip_locking') == 1) {

            // ip_lock_timeout = 0 means it is locked forever
            if ($this->getProperty('ip_lock_timeout') <> 0) {
                $varset->doDeleteWhere('polls_ip_lock', "poll_id='$poll_id' AND timestamp < ". ($current_time - $this->getProperty('ip_lock_timeout')));
            }

            $ip = GetTable2Array("SELECT voters_ip FROM polls_ip_lock WHERE (poll_id='$poll_id') AND (voters_ip = '".$_SERVER['REMOTE_ADDR']."')", 'aa_first');
            if ($ip) {
                $vote_invalid = "IP";
            } else {
                $varset->resetFromRecord( array('poll_id'=>$poll_id, 'voters_ip'=>$_SERVER['REMOTE_ADDR'], 'timestamp'=> $current_time) );
                $varset->doInsert('polls_ip_lock');
            }
        }

        // checkig for duplicated votes - Cookies method
        if ($this->getProperty('set_cookies') == 1) {
            $cookie = 'AA_Polls_'.$poll_id;   // $this->getProperty('cookies_prefix') // it is not necessary
            if ($_COOKIE[$cookie] == "1") {
                $vote_invalid = "Cookie";
            } else {
                setcookie($cookie, "1");
            }
        }

        if (!$vote_invalid) {
            DB_AA::sql("UPDATE polls_answer SET votes=votes+1 WHERE id='$vote_id'");
            AA::Pagecache()->invalidateFor("slice_id=". $this->getModuleId());

            if ($this->getProperty('logging') == 1) {
                $varset->resetFromRecord( array('answer_id'=> $vote_id, 'voters_ip'=>$_SERVER['REMOTE_ADDR'], 'timestamp'=> $current_time) );
                $varset->doInsert('polls_log');
            }
        }
        return $vote_invalid ? false : true;
    }


    function getOutput($design='beforevote') {
        $format   = $this->get_format_strings($design);

        $aliases  = GetAnswerAliases();

        $set = new AA_Set;
        $set->addCondition(new AA_Condition('poll_id', '==', $this->id()));
        $set->addSortorder(new AA_Sortorder(array('priority' => 'a')));

        $zids     = AA::Metabase()->queryZids(array('table'=>'polls_answer'), $set);
        $itemview = new itemview( $format, '', $aliases, $zids, 0, $zids->count(), shtml_url(), "", array(array('AA_Metabase', 'getContent'), array('table'=>'polls_answer')));

        return $itemview->get_output();
    }

    function display($design='beforevote') {
        echo $this->getOutput($design);
    }

    /** called like $set=AA_Poll::generateSet()
     *  It creates the set based on the conds and sort array
     */
    static function generateSet($pid,$conds=null,$sort=null) {
        $set = new AA_Set;
        $set->addCondition(new AA_Condition('module_id',   '==', q_pack_id($pid)));
        // there is also one poll which acts as template - managed from Polls Admin
        // (and not from the Polls Manager page) - it has status_code=0,
        // so it is filtered out automaticaly

        $ignore_expirydate = false;
        if (is_array($conds)) {
            foreach ($conds as $cond) {
                if (isset($cond['expiry_date'])) {
                    $ignore_expirydate = true;
                    break;
                }
            }
        }

        $now = now();
        $set->addCondition(new AA_Condition('status_code', '=', '1'));
        if (!$ignore_expirydate) {
            $set->addCondition(new AA_Condition('expiry_date', '>=', $now));
        }
        $set->addCondition(new AA_Condition('publish_date', '<=', $now));

        if ($conds) {
            $set->addCondsFromArray($conds);
        }

        if ($sort) {
            $set->addSortFromArray($sort);
        } else {
            // default sort order - just like for items - publish date - descending
            $set->addSortorder( new AA_Sortorder( array('publish_date' => 'd')));
        }
        return $set;
    }

    /** called like: echo AA_Poll::processPoll($_REQUEST)
     *  It process all the options/vodes and displays the result for the poll
     */
    static function processPoll($request) {

        if (isset($request['vote_id']) AND isset($request['poll_id']) AND !isset($request['novote'])) {
            $poll = AA_Polls::getPoll($request['poll_id']);
            $poll->registerVote($request['vote_id']);
        }

        if ($request['poll_id']) {
            // we want to display specified poll, or we just voted
            $poll_zids = new zids($request['poll_id']);
        } else {
            $set       = AA_Poll::generateSet($request['pid'],$request['conds'],$request['sort']);
            $poll_zids = AA_Metabase::queryZids(array('table'=>'polls'), $set);
            $from      = $request['from'] ? $request['from']-1 : 0;
            $listlen   = get_if($request['listlen'], 1);
            $poll_zids = $poll_zids->slice($from, $listlen);
        }


        // and now display the polls
        $zid_count = $poll_zids->count();

        $ret = '';
        for ( $i=0; $i < $zid_count; $i++ ) {
            $poll   = AA_Polls::getPoll($poll_zids->id($i));
            $design = $request['design_id'] ? $request['design_id'] : ($request['vote_id'] ? 'aftervote' : 'beforevote');
            $ret   .= $poll->getOutput($design);
        }

        if ($request['convertto'] OR $request['convertfrom'] ) {
            $ret = ConvertCharset::singleton()->Convert($ret, $request['convertfrom'], $request['convertto']);
        } elseif (IsAjaxCall()) {
            $ret = ConvertCharset::singleton()->Convert($ret, AA_Module_Polls::getModule($poll->id())->getCharset(), 'utf-8');
        }
        return $ret;
    }
}

class AA_Polls {
    var $a = array();     // Array poll_id -> AA_Poll object

    /** AA_Polls constructor  */
    function __construct() {
        $this->a = array();
    }

    /** singleton
     *  called from getPoll method
     *  This function makes sure, there is just ONE static instance if the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    static function singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Polls object
            $instance = new AA_Polls;
        }
        return $instance;
    }

    /** getPoll function
     *  main factory  method
     * @param $poll_id
     * @return mixed
     */
    static function getPoll($poll_id) {
        $polls = AA_Polls::singleton();
        return $polls->_getPoll($poll_id);
    }

    /** getPollProperty function
     *  static function
     * @param $poll_id
     * @param $field
     * @return null
     */
    function getPollProperty($poll_id, $field) {
        $polls = AA_Polls::singleton();
        $poll  = $polls->_getPoll($poll_id);
        return $poll ? $poll->getProperty($field) : null;
    }

    /** getName function
     *  static function
     * @param $poll_id
     * @return null
     */
    function getName($poll_id) {
        return AA_Polls::getPollProperty($poll_id, 'name');
    }

    /** _getPoll function
     * @param $poll_id
     * @return mixed
     */
    function & _getPoll($poll_id) {
        if (!isset($this->a[$poll_id])) {
            $this->a[$poll_id] = new AA_Poll($poll_id);
        }
        return $this->a[$poll_id];
    }
}


