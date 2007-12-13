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

// A class for manipulating polls
//
// Author and Maintainer: Mitra mitra@mitra.biz
//
// It is intended - and you are welcome - to extend this to bring into
// one place the functions for working with polls.
//
// A design goal is to use lazy-evaluation wherever possible, i.e. to only
// go to the database when something is needed.

//If this is needed, comment why! It trips out anything calling pollobj NOT from one level down
//require_once "../include/config.php3";
//require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."zids.php3"; // Pack and unpack ids
require_once AA_INC_PATH."viewobj.php3"; //GetViewsWhere

class AA_Poll {
    var $name;         // The name of the poll
    var $poll_id;      // The unpacked id of the poll i.e. 32 chars
    var $content;      // poll content - Record from polls table stored as ItemContent
    var $_sum;         // sum of all votes of this poll - caching purposes

    /** AA_Poll function - constructor
     * @param $poll_id
     */
    function AA_Poll($poll_id) {
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
     */
    function getProperty($fname) {
        $this->loadSettings();
        return $this->content->getValue($fname);
    }

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
     */
    function get_format_strings($type='beforevote') {
        $this->loadSettings();
        $design_type = (($type=='beforevote') OR !($this->getProperty('aftervote_design_id'))) ? 'design_id' : 'aftervote_design_id';

        $design = GetTable2Array("SELECT top, answer, bottom FROM polls_design WHERE id = '".$this->getProperty($design_type)."'", 'aa_first');
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
     */
    function aliases() {
        return GetPollsAliases();
    }

    function unalias($expression) {
        $this->loadSettings();
        $item = new AA_Item($this->content->getContent(), $this->aliases(), '', $expression);
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

            $varset->doDeleteWhere('polls_ip_lock', "poll_id='$poll_id' AND timestamp < ". ($current_time - $this->getProperty('ip_lock_timeout')));

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
            $cookie = $poll["cookies_prefix"].$poll_id;
            if ($_COOKIE[$cookie] == "1") {
                $vote_invalid = "Cookie";
            } else {
                setCookie($cookie, "1");
            }
        }

        if (!$vote_invalid) {
            tryQuery("UPDATE polls_answer SET votes=votes+1 WHERE id='$vote_id'");
            $GLOBALS['pagecache']->invalidateFor("slice_id=$poll_id");

            if ($this->getProperty('logging') == 1) {
                $varset->resetFromRecord( array('answer_id'=> $vote_id, 'voters_ip'=>$_SERVER['REMOTE_ADDR'], 'timestamp'=> $current_time) );
                $varset->doInsert('polls_log');
            }
        }
        return $vote_invalid ? false : true;
    }

    function display($type='beforevote') {
        $format   = $this->get_format_strings($type);

        $metabase = AA_Metabase::singleton();
        $aliases  = GetAnswerAliases();
        $fields   = $metabase->getSearchArray('polls_answer');

        $set = new AA_Set;
        $set->addCondition(new AA_Condition('poll_id', '==', $this->id()));
        $set->addSortorder(new AA_Sortorder(array('priority' => 'a')));

        $zids = $metabase->queryZids(array('table'=>'polls_answer'), $set);

        $content_function = array(array('AA_Metabase', 'getContent'), array('table'=>'polls_answer'));

        $itemview = new itemview( $format, $fields, $aliases, $zids, 0, $zids->count(), shtml_url(), "", $content_function);

        echo $itemview->get_output();
    }
}

class AA_Polls {
    var $a = array();     // Array poll_id -> AA_Poll object

    /** AA_Polls constructor  */
    function AA_Polls() {
        $this->a = array();
    }

    /** singleton
     *  called from getPoll method
     *  This function makes sure, there is just ONE static instance if the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Polls object
            $instance = new AA_Polls;
        }
        return $instance;
    }

    /** getPoll function
     *  main factory static method
     * @param $poll_id
     */
    function & getPoll($poll_id) {
        $polls = AA_Polls::singleton();
        return $polls->_getPoll($poll_id);
    }

    /** getPollProperty function
     *  static function
     * @param $poll_id
     * @param $field
     */
    function getPollProperty($poll_id, $field) {
        $polls = AA_Polls::singleton();
        $poll  = $polls->_getPoll($poll_id);
        return $poll ? $poll->getProperty($field) : null;
    }

    /** getName function
     *  static function
     * @param $poll_id
     */
    function getName($poll_id) {
        return AA_Polls::getPollProperty($poll_id, 'name');
    }

    /** _getPoll function
     * @param $poll_id
     */
    function & _getPoll($poll_id) {
        if (!isset($this->a[$poll_id])) {
            $this->a[$poll_id] = new AA_Poll($poll_id);
        }
        return $this->a[$poll_id];
    }
}

?>