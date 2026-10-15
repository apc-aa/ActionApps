<?php

/**
 * A class for manipulating polls
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\Cache\PageCache;
use AA\IO\DB\DB_AA;
use AA\Util\SingletonTrait;

require_once __DIR__ . "/../../../include/zids.php"; // Pack and unpack ids

class AA_Poll
{
    protected $name;         // The name of the poll
    protected $poll_id;      // The unpacked id of the poll i.e. 32 chars
    protected ?AA_Content $content = null;  // poll content - Record from polls table stored as ItemContent
    public $_sum = null;         // sum of all votes of this poll - caching purposes

    /** AA_Poll function - constructor
     * @param $poll_id
     */
    public function __construct($poll_id)
    {
        $this->poll_id = $poll_id; // unpacked id
    }

    /** loadSettings function
     *  Load $this from the DB for any of $fields not already loaded
     * @param $force
     */
    public function loadSettings($force = false): void
    {
        if (!$force && isset($this->content)) {
            return;
        }

        $content = AA_Metabase::getContent(new zids($this->poll_id), ['table' => 'polls']);
        //        $this->content = new ItemContent($content[$this->poll_id]);
        // no row for the id (deleted/wrong poll) -> empty content, so getProperty() just returns false
        $this->content = $content[$this->poll_id] ?? new AA_Content();  // this is AA_Content
    }

    /** getProperty function
     *
     * @param $fname
     *
     * @return false|string
     */
    public function getProperty($fname)
    {
        $this->loadSettings();
        return $this->content->getValue($fname);
    }

    /** Get Unpacked Module ID */
    public function getModuleId()
    {
        return unpack_id($this->getProperty('module_id'));
    }

    public function getVotesSum(): int
    {
        if (is_null($this->_sum)) {
            $this->_sum = (int) DB_AA::select1('sum', "SELECT SUM(votes) as sum FROM polls_answer", [['poll_id', $this->poll_id]]);
        }
        return $this->_sum;
    }

    /** get id of poll */
    public function id()
    {
        return $this->poll_id; // Return a 32 character id
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     * @param string $design  beforevote|aftervote|<design_id>
     * @return array
     */
    public function get_format_strings($design = 'beforevote'): array
    {
        $this->loadSettings();
        $design_id = match ($design) {
            'aftervote'  => $this->getProperty('aftervote_design_id'),
            'beforevote' => false,
            default      => $design,
        };
        // set deafault design, if not specified
        if (!$design_id) {
            $design_id = $this->getProperty('design_id');
        }

        $design = DB_AA::select1([], "SELECT top, answer, bottom FROM polls_design", [['id', (string) $design_id]]);
        // additional string for compact_top and compact_bottom needed
        // for historical reasons (not manager.class verion of item manager)
        return [
            "compact_top"     => $design['top'],
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
            "id"              => $this->id(),
        ]; // we need id for invalidating cache in itemview
    }

    /** @return true, if the string is column in polls table */
    public function isField($string): bool
    {
        static $columns = null;
        if (is_null($columns)) {
            $metabase = AA::Metabase();
            $columns  = $metabase->getColumnNames('polls');
        }
        return in_array($string, $columns);
    }

    public function unalias($expression): false|string
    {
        $this->loadSettings();
        if ($this->isField($expression)) {
            return $this->getProperty($expression);
        }
        $item = new AA_Item($this->content->getContent(), new AA_Aliases(new AA_Aliasgenerator_Polls()), $expression);
        return $item->get_item();
    }

    /** Counts one vote for the $vote_id answer of this poll.
     *
     *  Reachable unauthenticated through modules/polls/poll.php -> processPoll($_REQUEST),
     *  so no request value may be interpolated into SQL (finding N2, 2026-09).
     *
     * @param $vote_id  unpacked 32-char id of the answer voted for
     * @return bool     false when the ids are malformed or the vote was blocked (locked / IP /
     *                  cookie), true otherwise - "true" does not promise the UPDATE matched a
     *                  row, an answer id belonging to another poll matches none
     */
    public function registerVote($vote_id): bool
    {
        $poll_id = $this->id();

        // Both ids come straight from the request. polls.id / polls_answer.id are UNPACKED
        // 32-char hex text (varbinary(32) in include/metabase_schema.php; written by the
        // new_id() calls at polledit.php:109/164 and read back with substr($v, 1, 32) at :160),
        // so is_long_id() is the exact test and the values go to makeWhere() as plain strings -
        // packing them through zids/xpack_id would match nothing.
        if (!is_long_id($poll_id) || !is_long_id($vote_id)) {
            return false;
        }

        // A single, already validated address - include/locsess.php normalizes REMOTE_ADDR
        // for the proxy case, and it is the only place in AA that ever writes it.
        $voters_ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $vote_invalid = false;
        $current_time = now();

        // checkig for duplicated votes - ip_locking method
        if ($this->getProperty('locked') == 1) {
            $vote_invalid = "Locked";
        }
        if ($this->getProperty('ip_locking') == 1) {

            // ip_lock_timeout = 0 means it is locked forever
            $lock_timeout = $this->getProperty('ip_lock_timeout');
            if ($lock_timeout != 0) {
                DB_AA::delete('polls_ip_lock', [['poll_id', $poll_id], ['timestamp', $current_time - $lock_timeout, '<']]);
            }

            if (DB_AA::test('polls_ip_lock', [['poll_id', $poll_id], ['voters_ip', $voters_ip]])) {
                $vote_invalid = "IP";
            } else {
                AA::Metabase()->doInsert('polls_ip_lock', ['poll_id' => $poll_id, 'voters_ip' => $voters_ip, 'timestamp' => $current_time]);
            }
        }

        // checkig for duplicated votes - Cookies method
        if ($this->getProperty('set_cookies') == 1) {
            $cookie = 'AA_Polls_' . $poll_id;   // $this->getProperty('cookies_prefix') // it is not necessary
            if (PageCache::cookie($cookie) == "1") {
                $vote_invalid = "Cookie";
            } else {
                setcookie($cookie, "1");
            }
        }

        if (!$vote_invalid) {
            // poll_id belongs in the WHERE too: an answer id from ANOTHER poll would otherwise
            // be counted here, while it is the other poll's ip-lock / cookie check that never ran
            DB_AA::sql("UPDATE polls_answer SET votes=votes+1", [['id', $vote_id], ['poll_id', $poll_id]]);
            AA::Pagecache()->invalidateFor($this->getModuleId());

            if ($this->getProperty('logging') == 1) {
                AA::Metabase()->doInsert('polls_log', ['answer_id' => $vote_id, 'voters_ip' => $voters_ip, 'timestamp' => $current_time]);
            }
        }
        return !(bool) $vote_invalid;
    }


    public function getOutput($design = 'beforevote'): string
    {
        $format   = $this->get_format_strings($design);

        $set = new AA_Set();
        $set->addCondition(new AA_Condition('poll_id', '==', $this->id()));
        $set->addSortorder(new AA_Sortorder(['priority' => 'a']));

        $zids     = AA::Metabase()->queryZids(['table' => 'polls_answer'], $set);
        return (new itemview($format, new AA_Aliases(new AA_Aliasgenerator_Answer()), $zids))->setContentFunct([['AA_Metabase', 'getContent'], ['table' => 'polls_answer']])->get_output();
    }

    public function display($design = 'beforevote'): void
    {
        echo $this->getOutput($design);
    }

    /** called like $set=AA_Poll::generateSet()
     *  It creates the set based on the conds and sort array
     */
    public static function generateSet($pid, $conds = null, $sort = null): AA_Set
    {
        $set = new AA_Set();
        $set->addCondition(new AA_Condition('module_id', '==', xpack_id($pid)));
        // there is also one poll which acts as template - managed from Polls Admin
        // (and not from the Polls Manager page) - it has status_code=0,
        // so it is filtered out automatically

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
        $set->addCondition(new AA_Condition('status_code', '==', '1'));
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
            $set->addSortorder(new AA_Sortorder(['publish_date' => 'd']));
        }
        return $set;
    }

    /** called like: echo AA_Poll::processPoll($_REQUEST)
     *  It process all the options/vodes and displays the result for the poll
     */
    public static function processPoll($request): string
    {

        if (isset($request['vote_id']) && isset($request['poll_id']) && !isset($request['novote'])) {
            $poll = AA_Polls::getPoll($request['poll_id']);
            $poll->registerVote($request['vote_id']);
        }

        if ($request['poll_id']) {
            // we want to display specified poll, or we just voted
            $poll_zids = new zids($request['poll_id']);
        } else {
            $set       = self::generateSet($request['pid'], $request['conds'], $request['sort']);
            $poll_zids = AA::Metabase()->queryZids(['table' => 'polls'], $set);
            $from      = $request['from'] ? $request['from'] - 1 : 0;
            $listlen   = get_if($request['listlen'], 1);
            $poll_zids = $poll_zids->slice($from, $listlen);
        }


        // and now display the polls
        $zid_count = $poll_zids->count();

        $ret = '';
        for ($i = 0; $i < $zid_count; $i++) {
            $poll   = AA_Polls::getPoll($poll_zids->id($i));
            $design = $request['design_id'] ?: ($request['vote_id'] ? 'aftervote' : 'beforevote');
            $ret   .= $poll->getOutput($design);
        }

        if ($request['convertto'] || $request['convertfrom']) {
            $ret = ConvertCharset::singleton()->Convert($ret, $request['convertfrom'], $request['convertto']);
        } elseif (IsAjaxCall()) {
            $ret = ConvertCharset::singleton()->Convert($ret, AA_Module_Polls::getModule($poll->id())->getCharset(), 'utf-8');
        }
        return $ret;
    }
}

/**
 * Class AA_Polls
 * @method  static AA_Polls singleton()
 */
class AA_Polls
{
    use SingletonTrait;

    /**
     * @var AA_Poll[]
     */
    protected array $a = [];     // Array poll_id -> AA_Poll object

    /** getPoll function
     *  main factory  method
     *
     * @param $poll_id
     *
     * @return AA_Poll
     */
    public static function getPoll($poll_id): AA_Poll
    {
        return self::singleton()->_getPoll($poll_id);
    }

    /** getPollProperty function
     *  static function
     * @param $poll_id
     * @param $field
     * @return null|string|false
     */
    public static function getPollProperty($poll_id, $field)
    {
        return self::singleton()->_getPoll($poll_id)?->getProperty($field);
    }

    /** getName function
     *  static function
     * @param $poll_id
     * @return null
     */
    public function getName($poll_id)
    {
        return self::getPollProperty($poll_id, 'name');
    }

    /** _getPoll function
     *
     * @param $poll_id
     *
     * @return AA_Poll
     */
    private function & _getPoll($poll_id): AA_Poll
    {
        if (!isset($this->a[$poll_id])) {
            $this->a[$poll_id] = new AA_Poll($poll_id);
        }
        return $this->a[$poll_id];
    }
}
