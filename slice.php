<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

//expected  slice_id
//expected  encap     // determines wheather this file is ssi included or called directly
//optionally sh_itm    // if specified - selected item is shown in full text
//optionally x         // the same as sh_itm, but short_id is used instead
                     // implemented for shorter item url (see _#SITEM_ID alias)
//optionally o         // the same as x but the hit is not counted
                     // implemented for shorter item url (see _#SITEM_ID alias)
//optionally seo      // the same as x, but instead of it of item you provide seo
                     // string (the one on seo............. field)
//optionally srch      // true if this script have to show search results
//optionally highlight // when true, shows only highlighted items in compact view
//optionally bigsrch   // NOT SUPPORTED IN AA v 1.5+
//optionally cat_id    // select only items in category with id cat_id
//optionally cat_name  // select only items in category with name cat_name
//optionally inc       // for dispalying another file instead of slice data
                     // (like static html file - inc=/contact.html)
//optionally listlen   // change number of listed items in compact view
                     // (aplicable in compact viewe only)
//optionally items[x]  // array of items to show one after one as fulltext
                     // the array format is
//easy_query          // for easiest form of query
//order               // order field id - if other than publish date
                     // add minus sign for descending order (like "headline.......1-");
//timeorder           // rev - reverse publish date order
                     // (less priority than "order")
//no_scr              // if true, no scroller is displayed
//all_scr             // if true, scroller shows also 'All'
//scr_go              // sets scroller to specified page
//scr_url             // redefines the page where the scroller should go (it is
                     // useful if you include slice.php from another php script)
//restrict            // field id used with "res_val" and "exact" for restricted
                     // output (display only items with
                     //       "restrict" field = "res_val"
//res_val             // see restrict
//exact               // = 1: "restrict" field must match res_val exactly (=)
                     // undefined: substring is sufficient (LIKE '%res_val%')
                     // = 2: the same as 1, but the res_val is taken as
                     // expression (like: "environment or (toxic and not fuel)")
//als[]               // user alias definition. Parameter 'als[MY_ALIAS]=Summary'
                     // defines alias _#MY_ALIAS. If used, it prints 'Summary'.
//lock                // used in join with "key" for multiple slices on one page
                     // display. each slice have to have its lock, so commands
                     // (like sh_itm, scr_go, ...) will be executed only if key
                     // is the same as lock. key is send automatically with all
                     // links generated in slice (at this time just prepared)
//key                 // see lock (at this time just prepared)
//slicetext           // displays just the text instead of any output
                     // can be used for hiding the output of slice.hp3

                      //Discussion parameters
//optionally add_disc   // if set, discussion comment will be added
//optionally parent_id  // parent id of added disc. comment
//optionally sel_ids    // if set, show only discussion comments in $ids[] array
//optionally ids[]      // array of discussion comments to show in fulltext mode (ids['x'.$id])
//optionally all_ids    // if set, show all discussion comments
//optionally hideFulltext // if set, don't show fulltext part
//optionally neverAllItems // if set, don't show anything when everything would be shown (if no conds[] are set)
//optionally defaultCondsOperator // replaces LIKE for conds with not specified operator
//optionally group_n   // displays only the n-th group (in listings where items
                      // are grouped by some field (category, for example))
                      // good for display all the items of last magazine issue
                      // ( group_n=1 )
              //
//optionally mlx       // set language extension options (needs MLX field filled in Slice Settings)
                      // mlx=EN sets English as default language
              // mlx=EN-FR-DE first looks for EN, then FR, then DE
              //              and then displays the first one found
              // mlx=EN-ONLY  displays only EN items (like conds)
              // mlx=ALL      turns of language extension


use AA\Util\Hitcounter;
use AA\Util\InputVars;

function add2sort(&$sort, $sarr) {
    if (isset($sort) AND is_array($sort)) {
        foreach ($sort as $s) {
            if (key($s) == key($sarr)) {
                return;  // do not add new sort value to the sort array (it is already presented)
            }
        }
    }
    $sort[] = $sarr;
    return;
}

// ----- input variables normalization - start --------------------------------

// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }

foreach ($_REQUEST as $k => $v) {
    $$k = AddslashesDeep($v);
}
// ----- input variables normalization - end ----------------------------------

$encap = ( array_key_exists('encap', $_REQUEST) AND ($_REQUEST['encap']==="false") ) ? false : true ;

require_once __DIR__."/./include/config.php";
require_once __DIR__ . "/include/util.php";
require_once __DIR__ . "/include/item.php";
require_once __DIR__ . "/include/view.php";
require_once __DIR__ . "/include/searchlib.php";
require_once __DIR__ . "/include/discussion.php";
require_once __DIR__ . "/include/mgettext.php";
// function definitions:
require_once __DIR__ . "/include/slice.php";

require_once __DIR__ . "/include/locsess.php";

/** MyUrl function - was in sessions before, but now it is used just in this script, so moved here and rewritten to handle encap (=shtml)/not encap version
 *  rewriten to return URL of shtml page that includes this script instead to return self url of this script.
 */
function MyUrl($encap, $scr_url) {  //sliceID is here just for compatibility with MyUrl function

    $server  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $server .= '://'. $_SERVER['HTTP_HOST'];

    if ($scr_url) {  // if included into php script
        return $server.$scr_url;
    }

    if ($_SERVER["HTTP_X_FORWARDED_SERVER"]) {
        return '';   // it is impossible to get original script name when AA is hidden after proxy. In this case we will use reletive URL (which is not bad in any case, I think - Honza 2016-11-21)
    }

    $ret = $server;
    if ($encap) {
        if (isset($_SERVER['REDIRECT_DOCUMENT_URI'])) {  // CGI --enable-force-cgi-redirect
            $ret .= $_SERVER['REDIRECT_DOCUMENT_URI'];
        } elseif (isset($_SERVER['DOCUMENT_URI'])) {
            $ret .= $_SERVER['DOCUMENT_URI'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $url_parsed = parse_url($_SERVER['REQUEST_URI']);
            $ret .= $url_parsed['path'];
        } else {
            $ret .= $_SERVER['SCRIPT_URL'];
        }
    } elseif (isset($_SERVER['REDIRECT_SCRIPT_NAME'])) {
        $ret .= $_SERVER['REDIRECT_SCRIPT_NAME'];
    } else {
        $ret .= $_SERVER['SCRIPT_NAME'];
    }

    // // not executed - mode is cookie. Could be removed (Honza 16-03-31)
    // if ( ($this->mode === 'get') AND (!$noquery) ) {
    //    if ($encap) {
    //        $ret .= "?".urlencode($this->name)."=".$this->id;
    //    } else {
    //        $ret .= "?slice_id=$sliceID" . ($encap?"":"&encap=false"). "&".urlencode($this->name)."=".$this->id;
    //    }
    // }

    return $ret;
}


//MLX stuff
require_once __DIR__."/include/mlx.php";

// session is not working right now with PHP5 style sessions. It should not be
// needed. We are testing the code, and if all will be OK, we remove sessions
// from slice.php completely. It doesn-t work because of Cookies vs. SSI.
//    Honza 2016-07-12
pageOpen('noauth');

$sess->register('r_packed_state_vars');
$sess->register('slices');

$r_state_vars = unserialize($r_packed_state_vars);

// there was problems with storing too much ids in session variable,
// so I commented it out. It is not necessary to have it in session. The only
// reason to have it there is the display speed, but because of impementing
// pagecache, it is not so big problem now

//$sess->register(item_ids);

if ($encap) {                    // adds values from QUERY_STRING_UNESCAPED
    add_vars("");                //       and REDIRECT_STRING_UNESCAPED
    // if we use input type="buton" for submitting of form, then it adds x and y
    // variables, which we do not want (x means (unfortunately) - item id in AA)
    if (isset($x) AND isset($y) AND ctype_digit((string)$x) AND ctype_digit((string)$y) AND isset($conds)) {
        unset($x);
    }
}

if ( (($key != $lock) OR $scrl) AND is_array($r_state_vars) ) {  // command is for other slice on page
    RestoreVariables($r_state_vars);          // or scroller
}

// url posted command to display specified text instead of slice content -------
if ($slicetext) {
    echo $slicetext;
    ExitPage();
}

// url posted command to display another file ----------------------------------
if ( $inc ) {                   // this section must be after add_vars()
    //  StoreVariables(array("inc")); // store in session
    if ( !preg_match("/^([0-9a-z_])+(\.[0-9a-z]*)?$/i", $inc) ) {
        echo _m("Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters"). " $inc";
    } else {
        $fp = @fopen(shtml_base().$inc, "r");    //   if encapsulated
        if (!$fp) {
            echo _m("No such file") ." $inc";
        } else {
            fpassthru($fp);
        }
    }
    ExitPage();
}

// Take any slice to work with
if (!$slice_id AND is_array($slices)) {
    reset ($slices);
    $slice_id = current($slices);
}

// if someone breaks <!--#include virtual=""... into two rows, then slice_id
// (or other variables) could contain \n. Should be fixed more generaly -
// We will do it during rewrite AA to $_GET[] variable handling (probably)
$slice_id   = trim($slice_id);

is_object( $db ) || ($db = getDB());

$slice      = AA_Slice::getModule($slice_id);
$slice_info = GetModuleInfo($slice_id, 'S');       // get slice info

if (!$slice_info OR $slice_info['deleted']>0) {
    ExitPage(_m("Invalid slice number or slice was deleted") . " (ID: $slice_id)");
}
if ($slice_info['d_listlen']==0) {
    ExitPage(_m("slice.php output forbiden for this slice"));
}

// Use right language (from slice settings) - languages are used for scroller (Next, ...)
mgettext_bind($slice->getLang(), 'output');

if (!$slice_info['even_odd_differ']) {
    $slice_info['even_row_format'] = "";
}

// it is possible to redefine the design of fulltext or compact view by the view
// see fview and iview url parameters for this file (slice.php)
if ($fview || $iview) {
    if ($fview) {                       // use formating from view for fulltext
        $fview_info = AA_Views::getViewNumeric($fview);
        if ($fview_info->f('deleted')<1) {
            $slice_info['fulltext_format']        = $fview_info->f('odd');
            $slice_info['fulltext_format_top']    = $fview_info->f('before');
            $slice_info['fulltext_format_bottom'] = $fview_info->f('after');
            $slice_info['fulltext_remove']        = $fview_info->f('remove_string');
        }
    }
    if ($iview) {                       // use formating from view for index
        $iview_info = AA_Views::getViewNumeric($iview);
        if ($iview_info->f('deleted')<1) {
            $slice_info['group_by']        = $iview_info->f('group_by1');
            $slice_info['gb_direction']    = AA_View::VIEW_SORT_DIRECTIONS[$iview_info->f('g1_direction')];  // views uses different sort codes (historical reasons)
            $slice_info['category_format'] = $iview_info->f('group_title');
            $slice_info['category_bottom'] = $iview_info->f('group_bottom');
            $slice_info['compact_top']     = $iview_info->f('before');
            $slice_info['compact_bottom']  = $iview_info->f('after');
            $slice_info['compact_remove']  = $iview_info->f('remove_string');
            $slice_info['even_row_format'] = $iview_info->f('even');
            $slice_info['odd_row_format']  = $iview_info->f('odd');
            $slice_info['even_odd_differ'] = $iview_info->f('even_odd_differ');
        }
    }
}

const DEFAULT_CODEPAGE = "windows-1250";

if (!$encap) {
    Page_HTML_Begin($slice_info['name']);
}

if ($bigsrch OR $easy_query) {  // big search form or authomatical search form
    ExitPage('<!-- bigsrch parameter is NOT SUPPORTED IN AA v 1.5+ <br>easy_query is  NOT SUPPORTED IN AA v 2.50+ <br>See <a href="https://actionapps.org/faq/#215">AA FAQ</a> for more details. -->');
}

$add_aliases = $aliases    = AA_View::getAliasesFromUrl($als);

// this is not good way - aliases are then different on each call, so it isn't
// cached. The better way is below
// $add_aliases['_#SESSION_'] = GetAliasDef( 'f_s:'. $sess->id(), '', _m('session id'));

$add_aliases['_#SESSION_'] = GetAliasDef( 'f_e:session', 'id..............', _m('session id'));

// if banner parameter supplied => set format
$slice_info = array_merge( $slice_info, AA_View::parseBannerParam($banner));

// get alias list from database and possibly from url
// if working with multi-slice, get aliases for all slices
if (!is_array($slices)) {
    $aliases = $slice->aliases();
    array_add($add_aliases, $aliases);
} else {
    foreach ($slices as $sid) {
        // hack for searching in multiple slices. This is not so nice part
        // of code - we mix there $aliases[<alias>] with $aliases[<p_slice_id>][<alias>]
        // it is needed by itemview::set_column() (see include/itemview.php)
        $aliases[q_pack_id($sid)] = AA_Slice::getModule($sid)->aliases($als);
        array_add($add_aliases, $aliases[q_pack_id($sid)]);
    }
}

// fulltext view ---------------------------------------------------------------
if ( $sh_itm OR $x OR $o OR $seo ) {
    //  $r_state_vars = StoreVariables(array("sh_itm")); // store in session
    if ( $x ) {
        $zid = new zids((int)$x, 's');
        Hitcounter::hit($zid);
    }
    elseif ( $seo ) {
        $zid = new zids(explode_ids(StrExp('seo2ids', [$slice_id, $seo])), 'l');
        Hitcounter::hit($zid);
    }
    elseif ( $o ) {
        $zid = new zids((int)$o, 's');
    } else {
        $zid = new zids($sh_itm, 'l');
        Hitcounter::hit($zid);
    }
    
    if ( !is_object($item = AA_Items::getItem($zid)) OR ($item->getStatusCode()<>1) ) {
       ExitPage();
    }

    if (!isset($hideFulltext)) {
        echo (new itemview($slice_info, $aliases, $zid))->get_output_cached("fulltext");
    }

    // show discussion if assigned
    $discussion_vid = ($dview ?? $slice_info['vid']);
    // you can set dview=0 to not show discussion
    if ($discussion_vid > 0) {
        $db->query("SELECT view.*, slice.flag FROM view, slice
                     WHERE slice.id='".q_pack_id($slice_id)."' AND view.id=$discussion_vid");
        if ($db->next_record()) {
            $view_info = $db->record();

            // create array of parameters
            $disc = [
                'ids'         => $all_ids ? "" : $ids,
                'type'        => $add_disc ? "adddisc" : (($sel_ids || $all_ids) ? "fulltext" : "thread"),
                'item_id'     => $zid->longids(0),
                'vid'         => $view_info['id'],
                'html_format' => $view_info['flag'] & DISCUS_HTML_FORMAT,
                'parent_id'   => $parent_id
            ];

            $format  = GetDiscussionFormat($view_info);
            $format['id'] = q_pack_id($slice_id);                  // set slice_id because of caching


            $itemview = new itemview($format, new AA_Aliases(new AA_Aliasgenerator_Discussion()), null, "", "", MyUrl($encap, $scr_url), $disc);
            echo $itemview->get_output("discussion");
            // discussions should not be
            // cached or even better (TODO) discussions should have its separate slice
            // which is cached independently form the item itself through standard
            // AA caching

        }
    }
    ExitPage();
}

// multiple items fulltext view ------------------------------------------------
if ( $items AND is_array($items) ) {   // shows all $items[] as fulltext one after one
    //  $r_state_vars = StoreVariables(array("items")); // store in session
    $ids = [];
    foreach( $items as $k => $foo) {
        $ids[] = substr($k,1);    //delete starting character ('x') - used for interpretation of index as string, not number (by PHP)
    }
    ExitPage((new itemview($slice_info, $aliases, new zids($ids,"l")))->get_output_cached("itemlist"));
}

// compact view ----------------------------------------------------------------

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *         Parse parameters posted by query form and from $slice_info
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

$r_state_vars = StoreVariables(["no_scr","scr_go","order","cat_id", "cat_name", "exact","restrict","res_val","highlight","conds","group_by", "sort","als","defaultCondsOperator","mlx"]); // store in session, added mlx

// ***** CONDS *****

if ($cat_id AND ($flds = $slice->getFields()) AND ($cat_field = $flds->firstOfTypeFieldId())) {  // optional parameter cat_id - deprecated - slow ------
    $cat_group = $flds->getCategoryGroup();
    $SQL = "SELECT value FROM constant WHERE group_id = '$cat_group' AND id='". q_pack_id($cat_id) ."'";
    $db->query($SQL);
    if ( $db->next_record() ) {
        $conds[] = [
            $cat_field => 1,
            'value'    => $db->f('value'),
            'operator' => ($exact ? '=' : 'LIKE')
        ];
    }
} elseif ($cat_name AND ($tmpobj = $slice->getFields()) AND ($cat_field = $tmpobj->firstOfTypeFieldId()))  {  // optional parameter cat_name -------
    $conds[]     = [
        $cat_field => 1,
        'value'    => $cat_name,
        'operator' => ($exact ? '=' : 'LIKE')
    ];
}

if ($restrict) {
    $conds[]     = [
        $restrict  => 1,
        'value'    => ((($res_val[0] === '"' OR $res_val[0] === "'") AND $exact != 2 ) ? $res_val : "\"$res_val\""),
        'operator' => ($exact ? '=' : 'LIKE')
    ];
}

if ($highlight != "") {
    $conds[] = ['highlight.......' => 1];
}

if (!isset($defaultCondsOperator)) {
    $defaultCondsOperator = 'LIKE';
}
if (is_array($conds)) {
    ParseEasyConds($conds, $defaultCondsOperator);
    if (is_array($als)) {
        foreach ( $conds as $k => $v ) {
            // SubstituteAliases( $als, $conds[$k]['value'] );
            $conds[$k]['value'] = strtr($v['value'], $als);
        }
    }
} elseif ( is_string($conds) AND strlen($conds) ) {
    // we can use also conds=d-switch..........-=-1
    $tmp_set = new AA_Set(null, $conds);
    $conds   = $tmp_set->getConds();
}

// ***** SORT *****

/** order by field xy if other than publish date.
*  Syntax: [number]field_id[-]
*  (add minus sign for descending order (like "headline.......1-")
*  (add number before the field if you want to group limit (limit number of items of the same value))
*/

$sort_tmp = [];

if ($order) {
    $set = new AA_Set;
    $set->addSortFromString($order);
    $sort_tmp       = $set->getSort();
    $orderarr       = reset($sort_tmp);  // get the first from array
    $orderdirection = reset($orderarr);
    $order          = key($orderarr);
}

if ($debug) {
    echo "<BR>Group by: -$group_by- <br>Slice_info[category_sort] -$slice_info[category_sort]-<br>slice_info[group_by] -$slice_info[group_by]-";
}

if ($group_by) {
    $set = new AA_Set;
    $set->addSortFromString($group_by);
    $sort_tmp = $set->getSort();
    $slice_info["group_by"] = key($sort_tmp[0]);
}
elseif ($slice_info['category_sort'] AND ($tmpobj = $slice->getFields()) AND ($group_field = $tmpobj->firstOfTypeFieldId())) {
    $grp_odir    = (($order==$group_field) AND ($orderdirection!=='d')) ? 'a' : 'd';
    $sort_tmp[]  = [$group_field => $grp_odir];
}
elseif ($slice_info['group_by']) {
    $gbd = match ((string)$slice_info['gb_direction']) {  // gb_direction is number
        '1'      => '1',    // 1 (1)- ascending by priority
        'd', '8' => 'd',    // d (8)- descending - 'd' goes from view (iview) settings
        '9'      => '9',    // 9 (9)- descending by priority (for fields using constants)
        default  => 'a',    // 2 (2)- ascending;
    };

    $sort_tmp[] = [$slice_info['group_by'] => $gbd];
}

$sort_tmp = array_merge($sort_tmp, getSortFromUrl($sort));

if ($order) {
    add2sort($sort_tmp, [$order => (str_contains('aAdD19', $orderdirection) ? $orderdirection : 'a')]);
}

// time order the fields in compact view
add2sort($sort_tmp, ['publish_date....' => (($timeorder === "rev") ? 'a' : 'd')]);
$sort  = $sort_tmp;

//mlx stuff

if ($mlxslice = MLXSlice($slice)) {
    if (!$mlxView) {
        $mlxView = new MLXView($mlx);
    }
    $mlxView->preQueryZIDs($mlxslice,$conds);
}

$zids = QueryZids( ($slices ?: [$slice_id]), $conds, $sort, "ACTIVE", $neverAllItems, 0, $defaultCondsOperator );

if ($mlxslice) {
    $mlxView->postQueryZIDs($zids,$mlxslice,$slice_id);
}



if (!is_object($scr)) {
    $sess->register('scr');
    $scr_url_param = get_url(($scr_url ? StateUrl("$scr_url") : MyUrl($encap, $scr_url)), is_array($als) ? ['als'=>$als] : '');
    $scr = new AA_Scroller_Easy('scr', $slice_info['d_listlen'], $zids->count(), $scr_url_param);
}

// display 'All' option in scroller
if ($all_scr) { $scr->setShowAll($all_scr); }

// change number of listed items
if ($listlen) { $scr->setListlen($listlen); }

// default start page = 1
if (!$scr_go) { $scr_go = 1; }

// $scrl comes from easy_scroller
if ($scrl)    { $scr->update(); }

/** Add scroller aliases - page number, listlen */
$scr_aliases['_#PAGE_NO_'] = GetAliasDef( 'f_s:'. $scr->getCurrent(),  '', _m('number of current page (on pagescroller)'));
$scr_aliases['_#PAGE_LEN'] = GetAliasDef( 'f_s:'. $scr->getListlen(), '', _m('page length (number of items)'));
// aliases array have two form (quite stupid - will be changed in future - TODO)
// depending on listing for one slice or many slices
if (!is_array($slices)) {
    array_add($scr_aliases, $aliases);
} else {
    foreach ($slices as $sid) {
        // hack for searching in multiple slices. This is not so nice part
        // of code - we mix there $aliases[<alias>] with $aliases[<p_slice_id>][<alias>]
        // it is needed by itemview::set_column() (see include/itemview.php)
        array_add($scr_aliases, $aliases[q_pack_id($sid)]);
    }
}

if ( !$scrl ) {
    $scr->go2page($scr_go);
}

if ( !$srch AND !$encap AND !$easy_query ) {
    pCatSelector(MyUrl($encap, $scr_url), $slice_id);
}

if ($zids->count() > 0) {

    $itemview = new itemview($slice_info, $aliases, $zids, $scr->getListlen() * ($scr->getCurrent() - 1), ($group_n ? -$group_n : $scr->getListlen()));
    echo $itemview->get_output_cached("view");

    if (($scr->pageCount() > 1) AND !$no_scr AND !$group_n) {
        echo $scr->getNavbar();
    }
} else {
    // test if the noitem_msg is filled (be careful - "0" should be considered as filled)
    echo (isset($slice_info['noitem_msg']) AND (strlen($slice_info['noitem_msg']) > 0)) ?               // <!--Vacuum--> is keyword for removing 'no item message'
          str_replace( '<!--Vacuum-->', '', AA::Un($slice_info['noitem_msg'])) : ("<div>"._m("No item found") ."</div>");
}

if ($searchlog) {
    PutSearchLog($searchlog, count($GLOBALS['item_ids']));
}

if ($debug) {
    echo "<br><br>Page generation time: ". timestartdiff();
}

ExitPage();

