<?php
/**
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
require_once AA_INC_PATH . "mgettext.php3";
require_once AA_INC_PATH . "itemview.php3";
require_once AA_INC_PATH . "view.class.php3";
require_once AA_INC_PATH . "searchlib.php3";
require_once AA_BASE_PATH. "modules/links/util.php3";
require_once AA_BASE_PATH. "modules/links/linksearch.php3";
require_once AA_INC_PATH . "hitcounter.class.php3";
// add mlx functions
require_once AA_INC_PATH . "mlx.php";


// ----------------------------------------------------------------------------
//                         view functions
// ----------------------------------------------------------------------------
/** GetAliasesFromUrl function
 * @param $als
 */
function GetAliasesFromUrl($als) {
    $ret = array();
    if (is_array($als) ) {
        foreach ( $als as $k => $v ) {
            $ret["_#".$k] = GetAliasDef( "f_s:$v" );
        }
    }
    return $ret;
}

/** GetViewAliases function
 * @param $als
 */
function GetViewAliases($conds) {
    $ret = array();
    if (is_array($conds) ) {
        foreach ( $conds as $k => $v ) {
            $ret[str_pad('_#VIEW_C'.($k+1), 10 ,'_')] = GetAliasDef( 'f_s:'.$v['value'] );
        }
    }
    return $ret;
}



/** Class for storing url commands for view (object of this class stores just
 *  one command
 */
class ViewCommand {
    var $command;      /** type of the command (like x, c, d, v, ...) */
    var $parameters;   /** command parameters */

    /** ViewCommand function
     *  constructor
     *  @param $command the letter indicating the command (x, c, d, v, ...)
     *  @param $parameters array of command patameters
     */
    function ViewCommand($command, $parameters) {
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
     *
     */
    function getCommand() {
        return $this->command;
    }
    /** getParameter function
     * @param $index
     */
    function getParameter($index) {
        return $this->parameters[$index];
    }
    /** getParameterArray function
     * @param $offset
     */
    function getParameterArray($offset=0) {
        return array_slice($this->parameters, $offset);
    }


}

/** Class for storing set of url commands for view  */
class AA_View_Commands {
    var $commands;     /** array of objects of ViewCommand class */

    /** AA_View_Commands function
     *  constructor - calls parseCommand()
     * @param $cmd
     * @param $als
     */
    function __construct($cmd, $als=false) {
        $this->commands = array();
        $this->parseCommand($cmd, $als);
    }

    /** get function
     *  @return command given by command letter (say 'd')
     * @param $command
     */
    function get($command) {
        return $this->commands[$command];
    }

    /** count function
     *  returns number of commands in the set
     */
    function count() {
        return count($this->commands);
    }
    /** reset function
     *
     */
    function reset() {
        return reset($this->commands);
    }
    /** next function
     *
     */
    function next() {
        return next($this->commands);
    }
    /** current function
     *
     */
    function current() {
        return current($this->commands);
    }


    /** addCommand function
     *  add new command to the command set
     *  @param $command the letter indicating the command (x, c, d, v, ...)
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
     * @param string $cmd cmd[<vid>] string from url
     * @param array $als array of aliases used to expand inside cmd
     *                   (als[] obviously comes from url, as well)
     * @return array separated cmd parameters
     */
    function parseCommand($cmd, $als=false) {

        AA::$debug && AA::$dbg->log("<br>ParseCommand - cmd:", $cmd);

        // cmd could be array - in this case more commands are passed to view
        // Ussage: cmd[89][]=c-1-On&cmd[89][]=c-2-Cars&cmd[89][]=x-89-2233-5244
        // this will display items 2233 and 5244 if passes the conditions
        foreach( (array)$cmd as $cmd_part ) {

            // substitute url aliases in cmd
            if (isset($als) AND is_array($als)) {
                foreach ($als as $k => $v) {
                    // we are replacing the aliases in the url, so you can write
                    // something like {view.php3?vid=12&cmd[]=x-34-CAMPAIGN&als[CAMPAIGN]=42525}
                    // which displays item 42525. als[] could come through url, also.
                    // Be carefull with alias names - It is good idea to use longer
                    // aliases (say 8 characters), because you can come into trouble
                    // if you use say als[x], because then all 'x'es are replaced in
                    // cmd including the first x in cmd[]=x-34-..

                    // you can use also _#CAMPAIGN in url:
                    // {view.php3?vid=12&cmd[]=x-34-_%23CAMPAIGN&als[CAMPAIGN]=42525}
                    // (%23 is urlencoded %23)
                    if (substr($k,0,2) != '_#') {
                        $k2 = '_#'.$k;
                    }
                    $cmd_part = str_replace($k2, $v, $cmd_part);

                    $cmd_part = str_replace($k,  $v, $cmd_part);
                }
            }

            list($command, $params) = explode("-", $cmd_part, 2);
            $splited = in_array($command, array('x', 'i', 'o')) ? explode("-", $params) : split_escaped("-", $params, "--");
            $this->addCommand($command, $splited);

//            $splited = split_escaped("-", $cmd_part, "--");
//            $this->addCommand($splited[0], array_slice($splited,1));
        }
    }

}

/** ParseSettings function
 * Separates 'set' parameters for current view into array. To escape ','
 *                 character uses ',,'.
 * @param array $set_arr set[<vid>][] array of strings from url. 'set' parameters are in form
 *                      set[<vid>]=property1-value1,property2-value2
 *                   or
 *                      set[<vid>][]=property1-value1&set[<vid>][]=property2-value2[,...]
 * @return array asociative array of properties
 */
function ParseSettings($set_arr) {
    $ret  = array();
    foreach ($set_arr as $set) {
        $sets = split_escaped(",", $set, ",,");
        if (isset($sets) AND is_array($sets)) {
            foreach ($sets as $v) {
                $pos = strpos($v,'-');
                if ($pos) {
                    $ret[substr($v,0,$pos)] = substr($v,$pos+1);
                }
            }
        }
    }
    return $ret;
}

/** ParseViewParameters function
 *  Converts a query string into a data view_params data structure
 * @param $query_string
 */
function ParseViewParameters($query_string="") {
    global $cmd, $set, $vid, $als;
    global $x;   // url parameter - used for cmd[]=x-111-url view parameter

    if ($query_string) {
        // Parse parameters
        // if view in cmd[] or set[] is not specified - fill it from vid
        if ( preg_match("/vid=([0-9]*)/", $query_string, $parts) ) {
            $vid = $parts[1];
            $query_string = str_replace( 'cmd[]', "cmd[$vid]", $query_string );
            $query_string = str_replace( 'set[]', "set[$vid]", $query_string );
            // we no not want older calls to ParseViewParameters() will infect this call
            // @todo - make two versions - one for url parameters, second for
            // {view.php3...} parameters, where we do not want to read global data
            unset($cmd[$vid]);
            unset($set[$vid]);
        }
        //  This code below do not work!! - it is not the same as the code above!!
        //  (the code above parses only the specific guerystring for this view)
        //  if (!$cmd[$vid]) {        // (the same for set[])
        //      $cmd[$vid] = $cmd[0];
        //  }
        //  $command = ParseCommand($cmd[$vid], $GLOBALS['als']);

        add_vars($query_string);       // adds values from url (it's not automatical in SSIed script)
    }

    AA::$debug && AA::$dbg->log("ParseViewParameters: vid=$vid, query_string=".str_replace("slice_pwd=". $GLOBALS['slice_pwd'], 'slice_pwd=*****', $query_string )."$query_string", "cmd:", $cmd, "set:", $set, "als:", $als);

    // Splits on "-" and subsitutes aliases
    $commands = new AA_View_Commands($cmd[$vid], $GLOBALS['als']);
    $v_conds  = new AA_Set;

    AA::$debug && AA::$dbg->log("<br>ParseViewParameters - command:", $commands);

    $commands->reset();
    while($command = $commands->current()) {
        $commands->next();
        switch ($command->getCommand()) {
            case 'v':  $vid = $command->getParameter(0);
                       break;

            case 'o':  // the same as x, but no hit for item is added
            case 'i':  // i is exactly the same as o, now
            case 'x':  $vid = $command->getParameter(0);
                       $zids = new zids();
                       $zids->addDirty(($command->getParameter(1)=='url') ? $x : $command->getParameterArray(1));

                       // This is bizarre code, just incrementing the first item, left as it is
                       // but questioned on apc-aa-coders - mitra
                       if (($command->getCommand()=='x') AND ($zids->count()>0)) {
                           AA_Hitcounter::hit($zids->slice(0));
                       }
                       break;


            case 'c':  // Check for experimental c-OR-1-aaa-2-bbb-3-ccc syntax
                       // Note param_conds[0] is not otherwise used
                       // It is converted into conds in GetViewConds
                       // which is consumed in ParseMultiSelectConds
                       if ($command->getParameter(0) == 'OR') {
                           $param_conds[0] = 'OR';
                           $command_params = $command->getParameterArray(1);
                       } else {
                           $command_params = $command->getParameterArray();
                       }


                       if (AA_Set::check($command_params[0], $command_params[1])) {
                           $param_conds[$command_params[0]] = stripslashes($command_params[1]);
                       }
                       if (AA_Set::check($command_params[2], $command_params[3])) {
                           $param_conds[$command_params[2]] = stripslashes($command_params[3]);
                       }
                       if (AA_Set::check($command_params[4], $command_params[5])) {
                           $param_conds[$command_params[4]] = stripslashes($command_params[5]);
                       }
                       break;

            case 'd':  $v_conds->addFromCommand($command);
                       break;
        }
    }

    $arr = isset($set[$vid]) ? ParseSettings((array)$set[$vid]) : array();

    // Following line is here just for caching purposes - we are creating cache
    // keystring from view parameters and we need to add all set[] and cmd[]
    // in the keystring, because of cases like:
    //     view.php3?vid=1781&set[997]=selected-759644
    // (view 997 is called inside 1781)
    $arr['forcache'] = array($set, $cmd);

    if ($arr['slices']) {
        $arr['slices'] = explode('-', $arr['slices']);
    }


    // the parameters for discussion comes (quite not standard way) from globals
    if ( !$arr["all_ids"] ) {
        $arr["all_ids"]     = $GLOBALS['all_ids'];
    }
    if ( !$arr["ids"] ) {
        $arr["ids"]         = $GLOBALS['ids'];
    }
    if ( !$arr["sel_ids"] ) {
        $arr["sel_ids"]     = $GLOBALS['sel_ids'];
    }
    if ( !$arr["add_disc"] ) {
        $arr["add_disc"]    = $GLOBALS['add_disc'];
    }
    if ( !$arr["sh_itm"] ) {
        $arr["sh_itm"]      = $GLOBALS['sh_itm'];
    }
    if ( !$arr["parent_id"] ) {
        $arr["parent_id"]   = $GLOBALS['parent_id'];
    }

    // IDs of discussion items for discussion list
    if ( !$arr["disc_ids"] ) {
        $arr["disc_ids"]    = $GLOBALS['disc_ids'];
    }

    // used for discussion list view
    if ( !$arr["disc_type"] ) {
        $arr["disc_type"]   = $GLOBALS['disc_type'];
    }

    // used for Links module - categories and links
    if ( !$arr["cat"] ) {
        $arr["cat"]         = $GLOBALS['cat'];
    }
    if ( !$arr["show_subcat"] ) {
        $arr["show_subcat"] = $GLOBALS['show_subcat'];
    }

    $arr['als']         = GetAliasesFromUrl($GLOBALS['als']);
    $arr['vid']         = $vid;
    $arr['conds']       = $v_conds->getConds();
    $arr['param_conds'] = $param_conds;
    //  $arr['item_ids'] = $item_ids;
    $arr['zids']        = $zids;

    AA::$debug && AA::$dbg->log($arr);
    return $arr;
}


/** ResolveCondsConflict function
 * Helper function for GetViewConds() - resolves database x url conds conflict
 * @param $conds
 * @param $fld
 * @param $op
 * @param $val
 * @param $param
 */
function ResolveCondsConflict(&$conds, $fld, $op, $val, $param) {
    if ($fld AND $op) {
        $conds[] = array( 'operator' => $op,
                          'value'    => (strlen((string)$param)>0 ? $param : $val),  // param could be also "0"
                          $fld       => 1 );
    }
}

/** GetViewConds function
 * Fills array with conditions defined through
 * 'Slice Admin' -> 'Design View - Edit' -> 'Conditions' setting
 * @param array $view_info view definition from database in asociative array
 * @param array $param_conds possibly redefinition of conds from url (cmd[]=c)
 * @return array conditions array
 */
// If param_conds[0] = "OR" as set by ParseViewParameters then set valuejoin
// used by ParseMultiSelectConds
function GetViewConds($view_info, $param_conds) {
    // param_conds - redefines default condition values by url parameter (cmd[]=c)

    AA::$debug && AA::$dbg->log("(GetViewConds) param_conds=",$param_conds);
    $conds = array();

    ResolveCondsConflict($conds, $view_info['cond1field'], $view_info['cond1op'], $view_info['cond1cond'],  $param_conds[1]);
    ResolveCondsConflict($conds, $view_info['cond2field'], $view_info['cond2op'], $view_info['cond2cond'],  $param_conds[2]);
    ResolveCondsConflict($conds, $view_info['cond3field'], $view_info['cond3op'], $view_info['cond3cond'],  $param_conds[3]);
    if ($param_conds[0]) {
        $conds['valuejoin'] = $param_conds[0];
    }
    AA::$debug && AA::$dbg->log("(GetViewConds) conds=",$conds);

    return $conds;
}
/** GetViewSort function
 * @param $view_info
 * @param $param_sort
 */
function GetViewSort(&$view_info, $param_sort=null) {
    global $VIEW_SORT_DIRECTIONS;
    // translate sort codes (we use numbers in views from historical reason)
    // '0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority")

    $sort = false;
    // grouping

    if ($param_sort['group_by']) {
       // $sort = String2Sort($param_sort['group_by']);
        $sort = String2Sort('100000'.$param_sort['group_by']);
    } else {
        if ($view_info['group_by1']) {
            $sort[] = array($view_info['group_by1'] => $VIEW_SORT_DIRECTIONS[$view_info['g1_direction']]);
        }
        if ($view_info['group_by2']) {
            $sort[] = array($view_info['group_by2'] => $VIEW_SORT_DIRECTIONS[$view_info['g2_direction']]);
        }
    }

    //sorting
    if ($param_sort['sort']) {
        if ( $param_sort['sort'] != 'AAnoSORT' ) {
            $set    = new AA_Set;
            $set->addSortFromString($param_sort['sort']);
            $sort = $set->getSort();
        }
    } else {
        if ($view_info['order1']) {
            $sort[] = array($view_info['order1'] => $VIEW_SORT_DIRECTIONS[$view_info['o1_direction']]);
        }
        if ($view_info['order2']) {
            $sort[] = array($view_info['order2'] => $VIEW_SORT_DIRECTIONS[$view_info['o2_direction']]);
        }
    }

    if ($param_sort['group_limit'] AND count($sort)>0) {
        reset($sort);   // go to first record
        $sort[key($sort)]['limit'] = $param_sort['group_limit'];
    }
    return $sort;
}

/** ParseBannerParam function
 *  Parses banner url parameter (for view.php3 as well as for slice.php3
 *  (banner parameter format: banner-<position in list>-<banner vid>-[<weight_field>]
 *  (@see {@link http://apc-aa.sourceforge.net/faq/#219})
 */
function ParseBannerParam($banner_param) {
    $ret = array();
    if ( $banner_param ) {
        list( $foo_pos, $foo_vid, $foo_fld ) = explode('-',$banner_param);
        $ret['banner_position']   = $foo_pos;
        $ret['banner_parameters'] = "vid=$foo_vid";
        if ($foo_fld == 'norandom') {
            return $ret;
        }
        $ret['banner_parameters'] .= "&set[$foo_vid]=random-". ($foo_fld ? $foo_fld : 1);
    }
    return $ret;
}
/** GetListLength function
 * @param $listlen
 * @param $to
 * @param $from
 * @param $page
 * @param $idscount
 * @param $random
 */
function GetListLength($listlen, $to, $from, $page, $idscount, $random) {
    $list_from = max(0, $from-1);    // user counts items from 1, we from 0
    $list_to   = max(0, $to-1);      // user counts items from 1, we from 0

    if ( $to > 0 ) {
        $listlen = max(0, $list_to - $list_from + 1);
    }

    if ($page) {      // split listing to pages
        // Format:  <page>-<number of pages>
        $pos = strpos($page,'-');
        if ( $pos ) {
            $no_of_pages = substr($page,$pos+1);
            $page_n      = substr($page,0,$pos)-1;    // count from zero
            // to be last page shorter than others if there is bad number of items
            $list_from   = $page_n * floor($idscount/$no_of_pages);
            $listlen     = floor(($idscount*($page_n+1))/$no_of_pages) - floor(($idscount*$page_n)/$no_of_pages);
        } else {
            // second parameter is not specified - take listlen parameter
            // we can also specify both - page and from, which means from the item xy on the page p
            $list_from   = $listlen * ($page-1) + $list_from;
        }
    }
    return array( $listlen, $random ? $random : ($list_from ? $list_from : 0) );
}


/** GetView function
 *  Expand a set of view parameters, and return the view
 * @param $view_param
 */
function GetView($view_param) {
    global $nocache;

    //create keystring from values, which exactly identifies resulting content
    $key = get_hash($view_param, PageCache::globalKeyArray());

    if ( $res = $GLOBALS['pagecache']->get($key, $nocache) ) {
        return $res;
    }

    global $str2find_passon, $pagecache;
    $str2find_save         = $str2find_passon;    // Save str2find from same level
    $str2find_passon       = new CacheStr2find(); // clear it for caches stored further down
    list($res, $cache_sid) = GetViewFromDB($view_param, true);
    $str2find_passon->add($cache_sid, 'slice_id');
    $pagecache->store($key, $res, $str2find_passon);
    $str2find_passon->add_str2find($str2find_save); // and append saved for above
    return $res;
}

// Return view result based on parameters,
function GetViewFromDB($view_param, $return_with_slice_ids=false) {
    $vid           = $view_param["vid"];
    $als           = $view_param["als"];
    $conds         = $view_param["conds"];
    $slices        = $view_param["slices"];
    $param_conds   = $view_param["param_conds"];
    $param_sort    = array('sort'        => $view_param["sort"],
                           'group_by'    => $view_param["group_by"],
                           'group_limit' => $view_param["group_limit"]);
    $category_id   = $view_param['cat'];
    // $item_ids   = $view_param["item_ids"];
    $zids          = $view_param["zids"];
    //  $use_short_ids = $view_param["use_short_ids"];
    $list_page     = $view_param["page"];
    if ( $view_param["random"] ) {
        $random    = (($view_param["random"]==1) ? 'random' : 'random:'.$view_param["random"]);
    }

    $selected_item = $view_param["selected"];      // used for boolean (1|0) _#SELECTED

    AA::$debug && AA::$dbg->group("view_$vid".'_'.($dbgtime=microtime(true)));

    // alias - =1 for selected item
    // gets view data
    $view      = AA_Views::getView($vid);
    $view_info = $view->getViewInfo();

    if (!$view->isValid()) {
        AA::$debug && AA::$dbg->groupend("view_$vid".'_'.$dbgtime);
        return false;
    }

    // Use right language (from slice settings) - languages are used for
    // 'No item found', Next, ... messages
    // Do not load new language if we are in sitemodule - languages are handled
    // there
    if (!isset($GLOBALS['apc_state']['router'])) {
        mgettext_bind($view->getLang(), 'output');
    }
    if (!AA::$langnum) { // for multilingual content (not defined when called from view.php3, or cron.php3 mail, ...)
        AA::$lang    = strtolower(substr($view->getLang(),0,2));   // actual language - two letter shortcut cz / es / en
        AA::$langnum = array(AA_Content::getLangNumber(AA::$lang));   // array of prefered languages in priority order.
    }

    $noitem_msg = (isset($view_param["noitem"]) ? $view_param["noitem"] :
                   ( ((strlen($view->f('noitem_msg')) > 0) ) ?
                   str_replace( '<!--Vacuum-->', '', $view->f('noitem_msg')) :
                                     ("<div>"._m("No item found") ."</div>")));

    $view->setBannerParam(ParseBannerParam($view_param["banner"]));  // if banner set format

    $listlen    = $view_param["listlen"] ? $view_param["listlen"] : $view->f('listlen');

    if ($view_param["slice_id"]) {
        $view_info["slice_id"] = pack_id($view_param["slice_id"]);  // packed,not quoted
        $slice_id = $view_param["slice_id"]; // unpacked
    } else {
        $slice_id = unpack_id($view_info["slice_id"]);
    }

    // At this point, view_info["slice_id"] = $slice_id
    // and view_param[slice_id] is empty or same

    $cache_sid = $slice_id;     // pass back to GetView (passed by reference)
    if (!AA::$site_id AND !AA::$slice_id) {  // it is used as allpage main module to find {_:alias} aliases when called outside of site module
        AA::$slice_id = $slice_id;
    }

    // ---- display content in according to view type ----
    AA::$debug && AA::$dbg->log("GetViewFromDB:view_info=",$view_info);

    switch( $view_info['type'] ) {
        case 'discus':
            // create array of discussion parameters
            $disc = array('ids'         => ($view_param["all_ids"] ? "" : $view_param["ids"]),
                          'item_id'     => $view_param["sh_itm"],
                          'vid'         => $vid,
                          'html_format' => ($view_info['flag'] & DISCUS_HTML_FORMAT),
                          'parent_id'   => $view_param["parent_id"],
                          'disc_ids'    => $view_param["disc_ids"]);
            if (($view_param["disc_type"] == "list") || is_array($view_param["disc_ids"])) {
                $disc['type'] = "list";
            } elseif ($view_param["add_disc"]) {
                $disc['type'] = "adddisc";
            } elseif ($view_param["sel_ids"] || $view_param["all_ids"]) {
                $disc['type'] = "fulltext";
            } else {
                $disc['type'] = "thread";
            }
            $aliases = GetDiscussionAliases();

            $format = GetDiscussionFormat($view_info);
            // This is probably a bug, I think it should be
            //  $format['slice_id'] = pack_id($slice_id); // packed, not quoted
            //  Re: No, it is not bug - format normaly holds data from slice table,
            //      where id of slice is stored in 'id' column (honzam)

            $format['id'] = pack_id($slice_id);                  // set slice_id because of caching
                                                                 // not needed probably - we no longer call get_output_cached here

            // special url parameter disc_url - tell us, where we have to show
            // discussion fulltext (good for discussion search)
            $durl = ( $view_param["disc_url"] ? $view_param["disc_url"] : shtml_url());
            // add state variable, if defined (apc - AA Pointer Cache)
            if ( $GLOBALS['apc_state'] AND !$GLOBALS['apc_state']['router'] ) {
                $durl = con_url($durl,'apc='.$GLOBALS['apc_state']['state']);
            }

            $itemview = new itemview($format,"",$aliases,null,"","",$durl, $disc);
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
                $conds = GetViewConds($view_info, $param_conds);
            }
            $sort      = GetViewSort($view_info, $param_sort);
            if ( $view_info['type'] == 'const' ) {
                $zids             = QueryConstantZIDs($view_info['parameter'], $conds, $sort);
                $content_function = 'GetConstantContent';
            } elseif ( ($view_info['type'] == 'links') AND $category_id ) {
                $cat_path = Links_GetCategoryColumn( $category_id, 'path');
                if ( $cat_path ) {
                    $zids             = Links_QueryZIDs($cat_path, $conds, $sort, $view_param['show_subcat']);
                    $content_function = 'Links_GetLinkContent';
                }
            } elseif ( ($view_info['type'] == 'categories') AND $category_id ) {
                $zids             = Links_QueryCatZIDs($category_id, $conds, $sort, $view_param['show_subcat']);
                $content_function = 'Links_GetCategoryContent';
            }

            list( $listlen, $list_from ) = GetListLength($listlen, $view_param["to"],
            $view_param["from"], $list_page, $zids->count(), $random);

            $itemview = new itemview( $format, GetConstantFields(), $aliases, $zids, $list_from, $listlen, shtml_url(), "", $content_function);
            $itemview->parameter('category_id', $category_id);
            $itemview->parameter('start_cat',   $view_param['start_cat']);

            if ( !isset($zids) || $zids->count() <= 0) {
                $ret = $itemview->unaliasWithScrollerEasy($noitem_msg);
                break;
            }

            $ret = $itemview->get_output();
            break;

        case 'full':  // parameters: zids, als
            $format = $view->getViewFormat($selected_item);
            if ( isset($zids) AND ($zids->count() > 0) ) {
                // get alias list from database and possibly from url
                list($fields,) = GetSliceFields($slice_id);
                $aliases = GetAliasesFromFields($fields, $als);
                //mlx stuff
                $slice = AA_Slice::getModule($slice_id);
                if (isMLXSlice($slice)) {  //mlx stuff, display the item's translation
                    $mlx = ($view_param["mlx"]?$view_param["mlx"]:$view_param["MLX"]);
                    //make sure the lang info doesnt get reused with different view
                    $GLOBALS['mlxView'] = new MLXView($mlx,unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)));
                    $GLOBALS['mlxView']->preQueryZIDs(unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)),$conds);
                    $zids3 = new zids($zids->longids());
                    $GLOBALS['mlxView']->postQueryZIDs($zids3,unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)),$slice_id); //.serialize($zids3));
                    $zids->a    = $zids3->a;
                    $zids->type = $zids3->type;
                }
                $itemview = new itemview($format, $fields, $aliases, $zids, 0, 1, shtml_url(), "");
                $ret      = $itemview->get_output("view");
            } else {
                $ret      = AA_Stringexpand::unalias($noitem_msg);
            }
            break;

        case 'seetoo':
        case 'calendar':
            $today = getdate();
            $month = $view_param['month'];
            if ($month < 1 || $month > 12) {
                $month = $today['mon'];
            }
            $year = $view_param['year'];
            if ($year < 1900 || $year > 3000) {
                $year = $today['year'];
            }
            $calendar_conds = array (array( 'operator' => '<',  'value' => mktime (0,0,0,$month+1,1,$year), $view_info['field1'] => 1 ),
                                     array( 'operator' => '>=', 'value' => mktime (0,0,0,$month,1,$year),   $view_info['field2'] => 1 ));
            // Note drops through to next case

//        case 'full':  // parameters: zids, als
        case 'digest':
        case 'list':
        case 'rss':
        case 'urls':
        case 'script':  // parameters: conds, param_conds, als
            // we have to respect listlen, from, to, page parameters, but also we have to deal with view-listlen settings, which is 0 for fulltext vieww
/*            if ($view_info['type'] == 'full') {
                $listlen = max(@min($listlen, $zids->count()), 1);
            }
*/
            if ($view_info['type'] == 'rss') {
                header("Content-type: text/xml");
            }

            if (! $conds ) {        // conds could be defined via cmd[]=d command
                $conds = GetViewConds($view_info, $param_conds);
            }
            // merge $conds with $calendar_conds
            if (is_array($calendar_conds)) {
                foreach ( $calendar_conds as $v) {
                    $conds[] = $v;
                }
            }
            list($fields,) = GetSliceFields($slice_id);
            $aliases       = array_merge(GetAliasesFromFields($fields, $als), GetViewAliases($conds));

            if (is_array($slices)) {
                foreach ( $slices as $sid) {
                    list($fields,) = GetSliceFields($sid);
                    $aliases[q_pack_id($sid)] = GetAliasesFromFields($fields,$als);
                }
            }

            $sort  = GetViewSort($view_info, $param_sort);

            AA::$debug && AA::$dbg->log("viewparams",$aliases, $conds, $sort);

            //mlx stuff
            if (!$slice) {
                $slice = AA_Slice::getModule($slice_id);
            }
            if (isMLXSlice($slice)) {
                $mlx = ($view_param["mlx"]?$view_param["mlx"]:$view_param["MLX"]);
                //make sure the lang info doesnt get reused with different view
                $GLOBALS['mlxView'] = new MLXView($mlx,unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)));
                $GLOBALS['mlxView']->preQueryZIDs(unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)),$conds);
            }
            $zids2 = QueryZIDs($zids ? false : (is_array($slices) ? $slices : array($slice_id)), $conds, $sort, "ACTIVE", 0, $zids);

            if (isMLXSlice($slice)) {
                $GLOBALS['mlxView']->postQueryZIDs($zids2,unpack_id($slice->getProperty(MLX_SLICEDB_COLUMN)),$slice_id);
            }
            //end mlx stuff
            // Note this zids2 is always packed ids, so lost tag information
            AA::$debug && AA::$dbg->log("GetViewFromDB retrieved ".(isset($zids2) ? $zids2->count() : 0)." IDS");

            if (isset($zids) && isset($zids2) && ($zids->onetype() == "t")) {
                $zids2 = $zids2->retag($zids);
            }

            AA::$debug && AA::$dbg->log("GetViewFromDB: Filtered ids=",$zids2);

            $format = $view->getViewFormat($selected_item);
            $format['calendar_month'] = $month;
            $format['calendar_year']  = $year;
            if (isset($view_param['group_by'])) {
                $format['group_by'] = $view_param['group_by'];
            }

            AA::$debug && AA::$dbg->group("GetListLength".'_'.$dbgtime);
            list($listlen, $list_from) = GetListLength($listlen, $view_param["to"], $view_param["from"], $list_page, $zids2->count(), $random);
            AA::$debug && AA::$dbg->groupend("GetListLength".'_'.$dbgtime);

            AA::$debug && AA::$dbg->log("GetViewFromDB: Filtered listlen=",$listlen);

            $itemview = new itemview( $format, $fields, $aliases, $zids2, $list_from, $listlen, shtml_url(), "", ($view_info['type'] == 'urls') ? 'GetItemContentMinimal' : '');

            if (isset($zids2) && ($zids2->count() > $list_from)) {
                $itemview_type = (($view_info['type'] == 'calendar') ? 'calendar' : 'view');
                AA::$debug && AA::$dbg->log("GetViewFromDB: to show=",$zids2, $itemview_type);
                $ret = $itemview->get_output($itemview_type);
                AA::$debug && AA::$dbg->log("GetViewFromDB: to showend");
            }
            else {
                /* Not sure if this was a necessary change that got missed, or got changed again
                // $ret = $noitem_msg;
                $level = 0; $maxlevel = 0;
                // This next line is not 100% clear, might not catch aliases
                //since there are two formats for aliases structures. (mitra)
                //    huhl("XYZZY:v578, msg=",$noitem_msg);
                $ret = new_unalias_recurent($noitem_msg,"",$level,$maxlevel,null,null,$aliases);
                */

                AA::$debug && AA::$dbg->group("unaliasWithScrollerEasy".'_'.$dbgtime);
                $ret = $itemview->unaliasWithScrollerEasy($noitem_msg);
                AA::$debug && AA::$dbg->groupend("unaliasWithScrollerEasy".'_'.$dbgtime);

            }
            break;

        case 'static':
            // $format = $view->getViewFormat();  // not needed now
            // I create a CurItem object so I can use the unalias function
            $CurItem      = new AA_Item("", $als);
            $formatstring = $view_info["odd"];          // it is better to copy format-
            AA::$debug && AA::$dbg->log("GetViewFromDB: unalias=",$CurItem, $formatstring);
            $ret = $CurItem->unalias( $formatstring );  // string to variable - unalias
            break;
    }

    AA::$debug && AA::$dbg->log("GetViewFromDB: ret=",$ret);

    // user could make the view to display view ID before and after the view output
    // which is usefull mainly for debugging. See view setting in admin interface
    if ( AA::$debug OR ($ret AND ($view_info['flag'] & VIEW_FLAG_COMMENTS)) ) {
        $ret = "<!-- $vid -->$ret<!-- /$vid -->";
    }

    if ($view_param['convertto'] OR $view_param['convertfrom'] ) {
        if (!$view_param['convertfrom']) {
            $slice                     = AA_Slice::getModule($slice_id);
            $view_param['convertfrom'] = $slice->getCharset();
        }
        if ($view_param['convertto'] != $view_param['convertfrom'] ) {
            $encoder = ConvertCharset::singleton();
            $ret     = $encoder->Convert($ret, $view_param['convertfrom'], $view_param['convertto']);
        }
    }
    AA::$debug && AA::$dbg->groupend("view_$vid".'_'.$dbgtime);
    return $return_with_slice_ids ? array($ret, $cache_sid) : $ret;
}
?>
