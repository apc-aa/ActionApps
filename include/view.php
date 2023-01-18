<?php
/**
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Util\Langs;

require_once __DIR__ . "/mgettext.php";
require_once __DIR__ . "/itemview.php";
require_once __DIR__ . "/searchlib.php";
require_once __DIR__ . "/../modules/links/util.php";
require_once __DIR__ . "/../modules/links/linksearch.php";
// add mlx functions
require_once __DIR__."/mlx.php";


/** Class for storing url commands for view (object of this class stores just
 *  one command
 */
class ViewCommand {
    var $command;      /** type of the command (like x, c, d, v, ...) */
    var $parameters;   /** command parameters */

    /** ViewCommand function
     *  constructor
     *  @param $command string - the letter indicating the command (x, c, d, v, ...)
     *  @param $parameters string[] of command parameters
     */
    function __construct($command, $parameters) {
        $this->command    = $command;
        $this->parameters = $parameters;
    }

    /** addParameters function
     * @param $parameters
     */
    function addParameters($parameters) {
        $this->parameters = array_merge($this->parameters, $parameters);
    }

    /** getCommand function
     * @return string
     */
    function getCommand() {
        return $this->command;
    }

    /** getParameter function
     * @param $index
     * @return string
     */
    function getParameter($index) {
        return $this->parameters[$index];
    }

    /** getParameterArray function
     * @param $offset
     * @return string[]
     */
    function getParameterArray($offset=0) {
        return array_slice($this->parameters, $offset);
    }
}

/** Class for storing set of url commands for view  */
class AA_View_Commands {

    /** @var ViewCommand[] */
    var $commands;     /** array of objects of ViewCommand class */

    /** AA_View_Commands function
     *  constructor - calls parseCommand()
     * @param $cmd
     * @param $als
     */
    function __construct($cmd, $als=false) {
        $this->commands = [];
        $this->parseCommand($cmd, $als);
    }

    /** get function
     *  @return ViewCommand command given by command letter (say 'd')
     * @param $command
     */
    function get($command) {
        return $this->commands[$command];
    }

    /** count function
     *  @return int number of commands in the set
     */
    function count() {
        return count($this->commands);
    }
    /** reset function
     * @return ViewCommand
     */
    function reset() {
        return reset($this->commands);
    }
    /** next function
     * @return ViewCommand
     */
    function next() {
        return next($this->commands);
    }
    /** current function
     * @return ViewCommand
     */
    function current() {
        return current($this->commands);
    }


    /** addCommand function
     *  add new command to the command set
     *  @param $command string - the letter indicating the command (x, c, d, v, ...)
     *  @param $parameters array of command patameters
     */
    function addCommand($command, $parameters) {
        // only one command of specific type must be present
        if ( isset($this->commands[$command]) ) {
            // if the command is already set, then the parameters are appended
            $this->commands[$command]->addParameters($parameters);
        } else {
            $this->commands[$command] = new ViewCommand($command, $parameters);
        }
    }

    /** parseCommand function
     * Separates 'cmd' parameters for current view into array.
     *
     * Parameters are separated by '-'. To escape '-' character use '--' (then it
     * is not used as parameter separator. If you use aliases inside cmd[], then
     * the aliases are expanded by this function (depending on als[] alias array)
     * @param string|array $cmd cmd[<vid>] string from url
     * @param array $als array of aliases used to expand inside cmd
     *                   (als[] obviously comes from url, as well)
     */
    function parseCommand($cmd, $als=false) {

        AA::$debug&2 && AA::$dbg->log("<br>ParseCommand - cmd:", $cmd);

        // cmd could be array - in this case more commands are passed to view
        // Usage: cmd[89][]=c-1-On&cmd[89][]=c-2-Cars&cmd[89][]=x-89-2233-5244
        // this will display items 2233 and 5244 if passes the conditions
        foreach( (array)$cmd as $cmd_part ) {

            // substitute url aliases in cmd
            if (isset($als) AND is_array($als)) {
                foreach ($als as $k => $v) {
                    // we are replacing the aliases in the url, so you can write
                    // something like {view.php?vid=12&cmd[]=x-34-CAMPAIGN&als[CAMPAIGN]=42525}
                    // which displays item 42525. als[] could come through url, also.
                    // Be carefull with alias names - It is good idea to use longer
                    // aliases (say 8 characters), because you can come into trouble
                    // if you use say als[x], because then all 'x'es are replaced in
                    // cmd including the first x in cmd[]=x-34-..

                    // you can use also _#CAMPAIGN in url:
                    // {view.php?vid=12&cmd[]=x-34-_%23CAMPAIGN&als[CAMPAIGN]=42525}
                    // (%23 is urlencoded #)
                    if (substr($k,0,2) != '_#') {
                        $cmd_part = str_replace('_#'.$k, $v, $cmd_part);
                    }

                    $cmd_part = str_replace($k,  $v, $cmd_part);
                }
            }

            [$command, $params] = explode("-", $cmd_part, 2);
            $command = trim($command);
            $params  = ltrim($params);
            $splited = in_array($command, ['x', 'i', 'o']) ? explode("-", $params) : split_escaped("-", $params, "--");
            $this->addCommand($command, $splited);

//            $splited = split_escaped("-", $cmd_part, "--");
//            $this->addCommand($splited[0], array_slice($splited,1));
        }
    }

}


class AA_Showview {
    protected $_view_param     = [];
    protected $_used_slice_ids = [];

    function __construct($view_param) {
        $this->_view_param = $view_param;
    }

    function getViewOutput(): string {
        $dbgtime = microtime(true);

        if (!($view = AA_Views::getView($this->_view_param["vid"]))) {
            return '';
        }
        $vid = $view->getId();

        $als = $this->_view_param["als"];
        $conds = $this->_view_param["conds"];
        $slices = $this->_view_param["slices"];
        $param_conds = $this->_view_param["param_conds"];
        $param_sort = [
            'sort' => $this->_view_param["sort"],
            'group_by' => $this->_view_param["group_by"],
            'group_limit' => $this->_view_param["group_limit"]
        ];
        $category_id = $this->_view_param['cat'];
        // $item_ids   = $this->_view_param["item_ids"];
        $zids = $this->_view_param["zids"];
        //  $use_short_ids = $this->_view_param["use_short_ids"];
        if ($this->_view_param["random"]) {
            $random = (($this->_view_param["random"] == 1) ? 'random' : 'random:' . $this->_view_param["random"]);
        }

        $selected_item = $this->_view_param["selected"];      // used for boolean (1|0) _#SELECTED

        // alias - =1 for selected item
        // gets view data

        $view_info = $view->getViewInfo();

        AA::$debug&2 && AA::$dbg->group("view_$vid" . '_' . ($dbgtime));

        if ( isset($this->_view_param["page"]) ) {
            $list_page = (int)$this->_view_param["page"];
        } elseif ($view->f('scroller')==1) {
            $list_page = (int)$GLOBALS['apc_state']['xpage'];
        } else {
            $list_page = 0;
        }

        if (!$view->isValid()) {
            AA::$debug&2 && AA::$dbg->groupend("view_$vid".'_'.$dbgtime);
            return '';
        }

        // Use right language (from slice settings) - languages are used for
        // 'No item found', Next, ... messages
        // Do not load new language if we are in sitemodule - languages are handled
        // there
        if (!isset($GLOBALS['apc_state']['router'])) {
            mgettext_bind($view->getLang(), 'output');
        }
        if (!AA::$langnum) { // for multilingual content (not defined when called from view.php, or cron.php3 mail, ...)
            AA::$lang    = strtolower(substr($view->getLang(),0,2));   // actual language - two letter shortcut cz / es / en
            AA::$langnum = [Langs::getLangName2Num(AA::$lang)];   // array of prefered languages in priority order.
        }

        $noitem_msg = ($this->_view_param["noitem"] ?? (((strlen($view->f('noitem_msg')) > 0)) ?
                str_replace('<!--Vacuum-->', '', $view->f('noitem_msg')) :
                ("<div>" . _m("No item found") . "</div>")));

        if ( $this->_view_param["banner"] ) {
            $view->setBannerParam(AA_View::parseBannerParam($this->_view_param["banner"]));  // if banner set format
        }

        $listlen    = $this->_view_param["listlen"] ?: $view->f('listlen');

        if ($slice_id = $this->_view_param["slice_id"]) {
            if (!is_long_id($slice_id)) { // unpacked
                AA::$debug && AA::$dbg->warn("View parameter slice_id is not slice ID. If you want to use more slices, use parameter slices.");
                AA::$debug&2 && AA::$dbg->groupend("view_$vid".'_'.$dbgtime);
                return '';
            }
            $view_info["slice_id"] = pack_id($slice_id);  // packed,not quoted
        } else {
            $slice_id = unpack_id($view_info["slice_id"]);
        }

        // At this point, view_info["slice_id"] = $slice_id
        // and view_param[slice_id] is empty or same

        // collect used slices for cache invalidation
        $this->_used_slice_ids = is_array($slices) ? array_merge([$slice_id], $slices) : [$slice_id];

        if (!AA::$site_id AND !AA::$slice_id) {  // it is used as allpage main module to find {_:alias} aliases when called outside of site module
            AA::$slice_id = $slice_id;
        }

        // ---- display content in according to view type ----
        AA::$debug&2 && AA::$dbg->log("getViewOutput:view_info=",$view_info);

        $ret = '';
        switch( $view_info['type'] ) {
            case 'discus':
                // create array of discussion parameters
                $disc = [
                    'ids'         => ($this->_view_param["all_ids"] ? "" : $this->_view_param["ids"]),
                              'item_id'     => $this->_view_param["sh_itm"],
                              'vid'         => $vid,
                              'html_format' => ($view_info['flag'] & DISCUS_HTML_FORMAT),
                              'parent_id'   => $this->_view_param["parent_id"],
                              'disc_ids'    => $this->_view_param["disc_ids"]
                ];
                if (($this->_view_param["disc_type"] == "list") || is_array($this->_view_param["disc_ids"])) {
                    $disc['type'] = "list";
                } elseif ($this->_view_param["add_disc"]) {
                    $disc['type'] = "adddisc";
                } elseif ($this->_view_param["sel_ids"] || $this->_view_param["all_ids"]) {
                    $disc['type'] = "fulltext";
                } else {
                    $disc['type'] = "thread";
                }

                $format = GetDiscussionFormat($view_info);
                // This is probably a bug, I think it should be
                //  $format['slice_id'] = pack_id($slice_id); // packed, not quoted
                //  Re: No, it is not bug - format normaly holds data from slice table,
                //      where id of slice is stored in 'id' column (honzam)

                $format['id'] = pack_id($slice_id);                  // set slice_id because of caching
                                                                     // not needed probably - we no longer call get_output_cached here

                // special url parameter disc_url - tell us, where we have to show
                // discussion fulltext (good for discussion search)
                $durl = ( $this->_view_param["disc_url"] ?: shtml_url());
                // add state variable, if defined (apc - AA Pointer Cache)
                if ( $GLOBALS['apc_state'] AND !$GLOBALS['apc_state']['router'] ) {
                    $durl = con_url($durl,'apc='.$GLOBALS['apc_state']['state']);
                }

                $itemview = new itemview($format, new AA_Aliases(new AA_Aliasgenerator_Discussion()), null, "", "", $durl, $disc);
                $ret      = $itemview->get_output("discussion");
                break;

            case 'links':              // links       (module Links)
            case 'categories':         // categories  (module Likns)
            case 'const':              // constants
                if ( !$category_id ) {
                    $category_id = Links_SliceID2Category($slice_id);             // get default category for the view
                }
                $format    = $view->getViewFormat($selected_item);
                $aliases   = GetAliases4Type($view_info['type'],$als);
                if (!$conds) {          // conds could be defined via cmd[]=d command
                    $conds = $view->getConds($param_conds);
                }
                $sort      = $view->getSort($param_sort);
                if ( $view_info['type'] == 'const' ) {
                    $zids             = QueryConstantZIDs($view_info['parameter'], $conds, $sort);
                    $content_function = 'GetConstantContent';
                } elseif ( ($view_info['type'] == 'links') AND $category_id ) {
                    $cat_path = Links_GetCategoryColumn( $category_id, 'path');
                    if ( $cat_path ) {
                        $zids             = Links_QueryZIDs($cat_path, $conds, $sort, $this->_view_param['show_subcat']);
                        $content_function = 'Links_GetLinkContent';
                    }
                } elseif ( ($view_info['type'] == 'categories') AND $category_id ) {
                    $zids             = Links_QueryCatZIDs($category_id, $conds, $sort, $this->_view_param['show_subcat']);
                    $content_function = 'Links_GetCategoryContent';
                }

                [ $listlen, $list_from ] = AA_View::getListLength($listlen, $this->_view_param["to"], $this->_view_param["from"], $list_page, $zids->count(), $random);

                $itemview = new itemview( $format, $aliases, $zids, $list_from, $listlen, shtml_url(), "", $content_function);
                $itemview->parameter('category_id', $category_id);
                $itemview->parameter('start_cat',   $this->_view_param['start_cat']);

                if ( !isset($zids) || $zids->count() <= 0) {
                    $ret = $itemview->unaliasWithScroller($noitem_msg);
                    break;
                }

                $ret = $itemview->get_output();
                break;

            case 'full':  // parameters: zids, als
                $format = $view->getViewFormat($selected_item);
                AA::$debug&8 && AA::$dbg->log("view type - full - zids",$zids);
                if ( isset($zids) AND ($zids->count() > 0) ) {
                    $slice = AA_Slice::getModule($slice_id);
                    $bins   = AA_BIN_ACTIVE | ($slice->isExpiredContentAllowed() ? AA_BIN_EXPIRED : 0) | ($slice->isPendingContentAllowed() ? AA_BIN_PENDING : 0);
                    $zids  = QueryZids([], '', '', $bins, 0, $zids);
                    AA::$debug&8 && AA::$dbg->log("view type - full - cleanzids",$zids);
                    if ( $zids->count() > 0 ) {
                        //mlx stuff
                        if ($mlxslice = MLXSlice($slice)) {  //mlx stuff, display the item's translation
                            $mlx = ($this->_view_param["mlx"]?: $this->_view_param["MLX"]);
                            //make sure the lang info doesnt get reused with different view
                            $GLOBALS['mlxView'] = new MLXView($mlx,$mlxslice);
                            $GLOBALS['mlxView']->preQueryZIDs($mlxslice,$conds);
                            $zids3 = new zids($zids->longids());
                            $GLOBALS['mlxView']->postQueryZIDs($zids3,$mlxslice,$slice_id); //.serialize($zids3));
                            $zids->a    = $zids3->a;
                            $zids->type = $zids3->type;
                        }
                        $itemview = new itemview($format, $slice->aliases($als), $zids, 0, 1, shtml_url(), "");
                        $ret      = $itemview->get_output("view");
                        break;
                    }
                }
                $ret      = AA::Stringexpander()->unalias($noitem_msg);
                break;
            case 'seetoo':
            case 'calendar':
                $today = getdate();
                $month = $this->_view_param['month'];
                if ($month < 1 || $month > 12) {
                    $month = $today['mon'];
                }
                $year = $this->_view_param['year'];
                if ($year < 1900 || $year > 3000) {
                    $year = $today['year'];
                }
                $calendar_conds = [
                    ['operator' => '<',  'value' => mktime (0,0,0,$month+1,1,$year), $view_info['field1'] => 1],
                                         ['operator' => '>=', 'value' => mktime (0,0,0,$month,1,$year),   $view_info['field2'] => 1]
                ];
                // Note drops through to next case

    //        case 'full':  // parameters: zids, als
            case 'digest':
            case 'list':
            case 'rss':
            case 'urls':
            case 'script':  // parameters: conds, param_conds, als
                if ($view_info['type'] == 'rss') {
                    AA::$headers['type'] = 'text/xml';
                }

                if ( !$conds ) {        // conds could be defined via cmd[]=d command
                    $conds = $view->getConds($param_conds);
                }
                // merge $conds with $calendar_conds
                if (is_array($calendar_conds)) {
                    foreach ($calendar_conds as $v) {
                        $conds[] = $v;
                    }
                }

                $slice   = AA_Slice::getModule($slice_id);
                $aliases = array_merge($slice->aliases($als), AA_View::getViewAliases($conds));

                if (is_array($slices)) {
                    foreach ($slices as $sid) {
                        if ($m = AA_Slice::getModule($sid)) {
                            $aliases[q_pack_id($sid)] = $m->aliases($als);
                        }
                    }
                }

                $sort  = $view->getSort($param_sort);

                AA::$debug&2 && AA::$dbg->log("viewparams",$aliases, $conds, $sort);

                if ($mlxslice = MLXSlice($slice)) {
                    $mlx = $this->_view_param["mlx"] ?: $this->_view_param["MLX"];
                    //make sure the lang info doesnt get reused with different view
                    $GLOBALS['mlxView'] = new MLXView($mlx,$mlxslice);
                    $GLOBALS['mlxView']->preQueryZIDs($mlxslice,$conds);
                }
                $bin   = ($zids AND $slice->isExpiredContentAllowed()) ? (AA_BIN_ACTIVE | AA_BIN_EXPIRED) : AA_BIN_ACTIVE;
                $zids2 = QueryZids($zids ? false : (is_array($slices) ? $slices : [$slice_id]), $conds, $sort, $bin, 0, $zids);

                if ($mlxslice) {
                    $GLOBALS['mlxView']->postQueryZIDs($zids2,$mlxslice,$slice_id);
                }
                //end mlx stuff
                // Note this zids2 is always packed ids, so lost tag information
                AA::$debug&2 && AA::$dbg->log("getViewOutput retrieved ".(isset($zids2) ? $zids2->count() : 0)." IDS");

                if (isset($zids) && isset($zids2) && ($zids->onetype() == "t")) {
                    $zids2 = $zids2->retag($zids);
                }

                AA::$debug&2 && AA::$dbg->log("getViewOutput: Filtered ids=",$zids2);

                $format = $view->getViewFormat($selected_item);
                $format['calendar_month'] = $month;
                $format['calendar_year']  = $year;
                if (isset($this->_view_param['group_by'])) {
                    $format['group_by'] = $this->_view_param['group_by'];
                }
                if (isset($view_info['group_by2'])) {
                    $format['group_by2']    = $view_info['group_by2'];
                    $format['g2_direction'] = $view_info['g2_direction'];
                }

                [$listlen, $list_from] = AA_View::getListLength($listlen, $this->_view_param["to"], $this->_view_param["from"], $list_page, $zids2->count(), $random);

                $itemview = new itemview( $format, $aliases, $zids2, $list_from, $listlen, shtml_url(), "", ($view_info['type'] == 'urls') ? 'GetItemContentMinimal' : '');

            if (isset($zids2) && ($zids2->count() > $list_from)) {
                    $ret = $itemview->get_output(($view_info['type'] == 'calendar') ? 'calendar' : 'view');
                } else {
                    $ret = $itemview->unaliasWithScroller($noitem_msg);
                }
                break;

            case 'static':
                // $format = $view->getViewFormat();  // not needed now
                // I create a CurItem object so I can use the unalias function
                $CurItem      = new AA_Item("", $als);
                $formatstring = $view_info["odd"];          // it is better to copy format-
                $ret = $CurItem->unalias( $formatstring );  // string to variable - unalias
                break;
        }

        AA::$debug&2 && AA::$dbg->log("getViewOutput: ret=",$ret);

        // user could make the view to display view ID before and after the view output
        // which is usefull mainly for debugging. See view setting in admin interface
        if ( AA::$debug OR ($ret AND ($view_info['flag'] & VIEW_FLAG_COMMENTS)) ) {
            $ret = "<!-- $vid -->$ret<!-- /$vid, ". (microtime(true) - $dbgtime) .", ". date("y-m-d H:i:s", (int)$dbgtime). " -->";
        }

        if ($this->_view_param['convertto'] OR $this->_view_param['convertfrom'] ) {
            if (!$this->_view_param['convertfrom']) {
                $slice                     = AA_Slice::getModule($slice_id);
                $this->_view_param['convertfrom'] = $slice->getCharset();
            }
            if ($this->_view_param['convertto'] != $this->_view_param['convertfrom'] ) {
                $ret                     = ConvertCharset::singleton()->Convert($ret, $this->_view_param['convertfrom'], $this->_view_param['convertto']);
                AA::$headers['encoding'] = $this->_view_param['convertto'];
            }
        }
        AA::$debug&2 && AA::$dbg->groupend("view_$vid".'_'.$dbgtime);
        return $ret;
    }

    /**
     * @return array
     */
    public function lastUsedSliceIds(): array {
        return $this->_used_slice_ids;
    }
}
