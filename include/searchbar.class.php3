<?php
/**
 * File contains definition of searchbar class - it handles search and order bar
 * in AA admin interface (Link Manager page for example)
 *
 * Should be included to other scripts (as /modules/links/index.php3)
 *
 * @package Links
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
if (!defined("INCLUDE_SEARCHBAR_CLASS_INCLUDED"))
     define ("INCLUDE_SEARCHBAR_CLASS_INCLUDED",1);
else return;

require_once $GLOBALS[AA_INC_PATH] . "statestore.php3";
require_once $GLOBALS[AA_INC_PATH] . "profile.class.php3";
require_once $GLOBALS[AA_INC_PATH] . "formutil.php3";

/** helper function to sort search fields */
function searchfields_cmp($a, $b) {
    if ($a['search_pri'] == $b['search_pri']) return 0;
    return ($a['search_pri'] < $b['search_pri']) ? -1 : 1;
}

/** helper function to sort order fields */
function orderfields_cmp($a, $b) {
    if ($a['order_pri'] == $b['order_pri']) return 0;
    return ($a['order_pri'] < $b['order_pri']) ? -1 : 1;
}

/**
 * searchbar class - handles search and order bar in AA admin interface
 * (on Links Manager page, for example)
 */
class searchbar extends storable_class{
    var $search_fields;   // fields (options) used in search selectboxes
    var $search_operators;// operators used for fields in search selectboxes
    var $order_fields;    // fields (options) used in order selectboxes
    var $fields;          // fields definitions
    var $form_name;       // name of the form (for submit)

    var $search_row;     // internal array - stores current state of search rows
    var $order_row;      // internal array - stores current state of order rows
    var $bookmarks;      // internal object - stores bookmarks (stored queries)

    // state variables (class settings)
    var $search_row_count_min;
    var $order_row_count_min;
    var $add_empty_search_row;
    var $show;           //
    var $hint, $hint_url;

    // PHPLib variables - used to store class instances into sessions
    var $classname = "searchbar";    // required - class name
    var $persistent_slots =          // required - object's slots to save
            // save only small or dynamicaly changed values - not base setting
            array("search_row", "order_row");

    /** constructor */
    function searchbar($fields=false, $f='foo', $srcm=1, $orcm=1, $aesr=1, $show='aa_default', $hint='', $hint_url='') {
        $this->fields               = $fields;
        $this->show                 = (($show == 'aa_default') ? (MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS) : $show);
        $this->hint                 = $hint;
        $this->hint_url             = $hint_url;

        if( isset($fields) AND is_array($fields) ) {
            uasort ($fields, "searchfields_cmp");
            foreach ( $fields as $fid => $v) {
                if ($v['search_pri'] > 0 ) {           // not searchable fields
                    // searchfields could be splited into groups
                    // one group is allways with search_pri 0-999, 1000-1999, ...
                    if ( $last_pri AND (floor($last_pri/1000) != floor($v['search_pri']/1000)) ) {
                        $this->search_fields['foo'.$last_pri] = '---------------';
                        $this->search_operators[$fid] = 'text';
                    }
                    $last_pri = $v['search_pri'];
                    $this->search_fields[$fid]    = $v['name'];
                    $this->search_operators[$fid] = $v['operators'];
                }
            }
            uasort ($fields, "orderfields_cmp");
            $last_pri = 0;
            foreach ( $fields as $fid => $v) {
                if ($v['order_pri'] > 0 ) {
                    // orderfields could be splited into groups
                    // one group is allways with order_pri 0-999, 1000-1999, ...
                    if ( $last_pri AND (floor($last_pri/1000) != floor($v['order_pri']/1000)) ) {
                        $this->order_fields['foo'.$last_pri] = '---------------';
                    }
                    $last_pri = $v['search_pri'];
                    $this->order_fields[$fid]     = $v['name'];
                }
            }
        }
        $this->form_name            = $f;
        $this->search_row_count_min = $srcm;
        $this->order_row_count_min  = $orcm;
        $this->add_empty_search_row = $aesr;
        $this->bookmarks            = new bookmarks;   // stores bookmarks (stored queries)
    }

    /**
     * Updates internal search_row and order_row variables from data posted from
     * form (in $_POST[])
     */
    function update() {
        $srchbr_akce     = $_POST['srchbr_akce'];
        $srchbr_bookmark = $_POST['srchbr_bookmark'];
        if ( !$srchbr_akce )     // no searchbar action
            return;
        list( $akce, $answer, $confirm ) = ParamExplode($srchbr_akce);

        switch ( $akce ) {
            case 'bookmark':    // bookmark as user bookmark (just for the user)
                $this->setFromForm();      // set the searchbar from form values
                if ( trim($answer) ) {
                    $this->bookmarks->store($answer, $this->getState(), $confirm=='y' );
                }
                return;
            case 'clearsearch':
                $this->resetSearchAndOrder();
                $this->bookmarks->setLastUsed();
                return;
            case 'bookmarkgo':
                $this->setFromBookmark($srchbr_bookmark);
                return;
            case 'bookmarkupdate':
                $this->setFromForm();      // set the searchbar from form values
                if ( $this->bookmarks->is_defined($srchbr_bookmark) ) {
                    $this->bookmarks->updateBookmark($srchbr_bookmark, $this->getState());
                    $this->bookmarks->setLastUsed($srchbr_bookmark);
                }
                return;
            case 'bookmarkrename':
                if ( $this->bookmarks->is_defined($srchbr_bookmark) ) {
                    $this->bookmarks->renameBookmark($srchbr_bookmark, $answer);
                    $this->bookmarks->setLastUsed($srchbr_bookmark);
                }
                return;
            case 'bookmarkdelete':
                if ( $this->bookmarks->is_defined($srchbr_bookmark) ) {
                    $this->bookmarks->delete($srchbr_bookmark);
                    $this->resetSearchAndOrder();
                    $this->bookmarks->setLastUsed();
                }
                return;
        }

        // reset the searchbar and set it from form values
        $this->setFromForm();
    }

    /** Resets the searchbar (both - Search as well as Order)  */
    function resetSearchAndOrder() {
        unset($this->order_row);
            for( $i=0; $i<count($this->search_row); $i++ ) {
                if ($this->search_row[$i]['readonly'] != true) {
                    unset($this->search_row[$i]);
                }
            }
//            unset($this->search_row);
    }

    /** Set searchbar state from form */
    function setFromForm() {
        unset($srchbar);
        if ($this->show & MGR_SB_SEARCHROWS) {
            // cleaning all searchrows except 'readonly' searches
            for( $i=0; $i<count($this->search_row); $i++ ) {
                if ($this->search_row[$i]['readonly'] == true) {
                    $srchbar[] = $this->search_row[$i];
                }
            }
            unset($this->search_row);
            $this->search_row = $srchbar;
        }
        if ($this->show & MGR_SB_ORDERROWS)  unset($this->order_row);   // reset only if visible
        if (is_array($_POST['srchbr_order']))
        foreach ( $_POST['srchbr_order'] as $k => $fld )
            $this->addOrder( array( 0 => array( $fld => $_POST["srchbr_order_dir"][$k])));

        if (is_array($_POST['srchbr_field'])) {
            foreach ( $_POST['srchbr_field'] as $k => $fld ) {
                $this->addSearch( array( 0 => array( $fld       => 1,
                                                 'operator' => $_POST["srchbr_oper"][$k],
                                                 'value'    => $_POST["srchbr_value"][$k])));
            }
        }
    }

    /** Set searchbar state from bookmark number <$key> */
    function setFromBookmark($key) {
        if ( $this->bookmarks->is_defined($key) ) {
            $this->resetSearchAndOrder();
            $this->setFromState($this->bookmarks->get($key));
            $this->bookmarks->setLastUsed($key);
        }
    }

    /**
     * Adds new Order bar(s)
     * @param  array $sort[] = array( <field> => <a|d> )
     *               value other than 'a' means DESCENDING
     */
    function addOrder( $sort ) {
        // fill order_row variable
        if (isset($sort) AND is_array($sort)) {
            foreach ( $sort as $s ) {
                $this->order_row[] =
                     array( 'field' => key($s),
                            'dir' => (current($s) AND (current($s)!='a')) ? 'd' : 'a');
            }
        }
    }

    /**
     * Adds new Search bar(s)
     * @param  array $conds[] = array ( <field> => 1, 'operator' => <operator>,
     *                                  'value' => <search_string> )
     */
    function addSearch($conds, $readonly=false) {
        // fill search_row variable
        if (isset($conds) AND is_array($conds)) {
            foreach ( $conds as $cond ) {
                if (isset($cond) AND is_array($cond)) {
                    foreach ( $cond as $k=>$c ) {
                        if ( ($k != 'operator') AND ($k != 'value') ) {
                            $field = $k;
                            break;
                        }
                    }
                    if( $field ) {
                        $this->search_row[] =
                            array( 'field' => $field,
                                   'value' => $cond['value'],
                                   'oper'  => $cond['operator'],
                                   'readonly' => $readonly);
                    }
                }
            }
        }
    }

    /** */
    function setFromProfile(&$profile) {
        // admin_order is in 'publish_date....+' format
        $foo_order = GetSortArray( $profile->getProperty('admin_order') );

        if ( count($foo_order) < 1 ) {
            $this->addOrder( array( 0=>array('publish_date....' => 'd')) );
        } else {
            $this->addOrder( array( 0=>$foo_order ));
        }
        list($fld,$search_str) = split( ':', $profile->getProperty('admin_search') );
        if ( $fld ) {
          /* path.net specific change to make profiles readonly */
          //            $this->addSearch( array( 0=>array( $fld => 1, 'value'=>$search_str, 'operator'=>'RLIKE', 'readonly' => 1)),1);
        }
    }

    /**
     * Returns conds[] array to use with QueryIDs() (or Links_QueryIDs(), ...)
     */
    function getConds() {
        if( !isset($this->search_row) OR !is_array($this->search_row) )
            return false;

        $fields = $this->fields;
        reset( $this->search_row );
        while( list( , $c ) = each( $this->search_row ) ) {
            $conds[]=array( 'operator' => $c['oper'],
                            'value' => $c['value'],
                            $c['field'] => 1 );
        }
        return $conds;
    }

    /**
     * Returns sort[] array to use with QueryIDs() (or Links_QueryIDs(), ...)
     */
    function getSort() {
        if( !isset($this->order_row) OR !is_array($this->order_row) )
            return false;

        reset( $this->order_row );
        while( list( , $s ) = each( $this->order_row ) )
            $sort[]=array( $s['field'] => $s['dir'] );
        return $sort;
    }

     /** Returns array of bookmark names <key> => <name>  */
    function getBookmarkNames() {
        if( isset($this->bookmarks) )  return $this->bookmarks->getKeyName();
        return false;
    }

    function getBookmarkParams($key) {
        if( isset($this->bookmarks) )  return $this->bookmarks->getBookmarkParams($key);
        return false;
    }

    /**
     * Prints one search bar (one row)
     * @param int $bar which bar to print (index)
     * @return bool true, if the printed searchrow is not empty
     */
    function print_search_bar($bar) {

        list($val, $fld, $oper, $readonly) = ( isset($this->search_row[$bar]) AND
                                    is_array($this->search_row[$bar]) ) ?
                                array(safe($this->search_row[$bar]['value']),
                                      $this->search_row[$bar]['field'],
                                      $this->search_row[$bar]['oper'],
                                      $this->search_row[$bar]['readonly']) :
                                array( "", $this->search_fields[0], "", false);

        if( $bar == 0 ) {   // first bar is described as 'SEARCH' others 'AND'
            $searchtext = _m('Search');
            $searchimage = "<a href='javascript:document.".$this->form_name. ".submit()'>".
                           GetAAImage('search.gif', $searchtext, 15, 15) .'</a>';
        } else {
            $searchimage = GetAAImage('px.gif', '-', 15, 15);
            $searchtext = _m('And');
        }

        # filter
        echo "<tr class=leftmenuy><td class='search'>$searchimage</td><td><b>$searchtext</b></td>";
        if ($readonly) {
            echo "<td class=\"tabtxteven\">".$this->search_fields[$fld]."</td>";
            echo "<td class=\"tabtxteven\">";
            switch ($oper) {
                case "RLIKE" : echo _m('begins with'); break;
                case "LIKE" : echo _m('contains'); break;
                case "=" : echo _m('is'); break;
            }
            echo "</td>";
            echo "<td class=\"tabtxteven\">".$val."</td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td>";
        } else {
            echo "<td>";
            FrmSelectEasy("srchbr_field[$bar]", $this->search_fields, $fld, 'onchange="ChangeOperators(\''.$this->form_name.'\','.$bar.', \'\')"' );
            echo '</td><td>';
            FrmSelectEasy("srchbr_oper[$bar]", array(' ' => ' '), null, 'onchange="OpenWindowIfRequest(\''.$this->form_name.'\',\''. $slice_id.'\',\''.$bar.'\',\''.get_admin_url("constants_sel.php3").'\')" ');
            echo '</td><td>';
            echo "<input type='Text' name='srchbr_value[$bar]' size=20 maxlength=254
              value=\"$val\"></td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td>";
        }
        echo '</tr>';
        return $val != "";
    }

    /**
     * Access function to searchba operator
     */
    function getSearchBarOperator($bar) {
        return $this->search_row[$bar]['oper'];
    }

    /**
     * Prints one order bar (one row)
     * @param int $bar which bar to print (index)
     */
    function print_order_bar($bar) {

        list($dir, $fld) = ( isset($this->order_row[$bar]) AND
                             is_array($this->order_row[$bar]) ) ?
                                array(safe($this->order_row[$bar]['dir']),
                                      $this->order_row[$bar]['field']) :
                                array( "a", $this->order_fields[0]);

        $searchtext  = _m('Order');
        $searchimage = "<a href='javascript:document.".$this->form_name. ".submit()'>".
                           GetAAImage('order.gif', $searchtext, 15, 15) .'</a>';
        echo "<tr class=leftmenuy><td class=search>$searchimage</td><td><b>".
              str_replace(' ','&nbsp;',$searchtext). "</b></td><td>";
        FrmSelectEasy("srchbr_order[$bar]", $this->order_fields, $fld);
        echo '</td><td colspan="2" class=leftmenuy>';
        FrmChBoxEasy("srchbr_order_dir[$bar]", $dir=='d');
        echo _m('Descending'). "</td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td></tr>";
    }

    function print_bar_actions() {
        echo "<tr class=leftmenuy>

               <td colspan=\"3\">
               <a href='javascript:document.".$this->form_name. ".submit()'>". _m('Search') ."</a> /
               <a href='javascript:SearchBarAction(\"".$this->form_name. "\", \"clearsearch\", false, false)'>". _m('Clear') ."</a>
               </td>
               <td colspan=\"2\">
                <a href='javascript:SearchBarAction(\"".$this->form_name. "\", \"bookmark\", \""._m('Stored search name') ."\",".
                    ( !IfSlPerm(PS_BOOKMARK) ? "\"false\"" : "\"". _m('You have the permission to add stored search globaly. Do you want to add this query as global (common to all slice users)?')) ."\")'>". _m('Store') ."</a>";
              echo "</td>";

              if ($this->hint != "") {
/*
                  if ($this->hint_url != "") {
                      $url = "<a href=\"$this->hint_url\" target=\"_blank\" title=\"".htmlspecialchars($this->hint)."\">".GetAAImage('help50.gif', htmlspecialchars($this->hint), 16, 12)."</a>";
                  } else {
                      $url = "<abbr title=\"".htmlspecialchars($this->hint)."\">".GetAAImage('help50.gif', htmlspecialchars($this->hint), 16, 12)."</abbr>";
                  }

                  echo "<td>$url</td>";
*/
                  echo "<td>";
                  echo FrmMoreHelp($this->hint_url,"",$this->hint, true);
                  echo "</td>";
              }
              echo "</tr>";
    }

    function print_bar_bookmarks() {
        echo "<tr class=leftmenuy>
               <td colspan=\"2\"><b>". _m('Stored searches') ."</b></td>
               <td>".
               $this->bookmarks->getSelectbox() .
               " <a href='javascript:SearchBarAction(\"".$this->form_name ."\", \"bookmarkgo\",     false, false)'>". _m('View')   ."</a>
               </td>
               <td colspan=\"4\">
                 <span class=\"smalltext\"><a href='javascript:SearchBarActionConfirm(\"".$this->form_name ."\", \"bookmarkupdate\", \"". _m("Are you sure to refine current search?") ."\")'>". _m('Update') ."</a> /
                 <a href='javascript:SearchBarAction(\"".$this->form_name ."\", \"bookmarkrename\", \"". _m("Enter new name") ."\", false)'>". _m('Rename') ."</a> /
                 <a href='javascript:SearchBarActionConfirm(\"".$this->form_name ."\", \"bookmarkdelete\", \""._m("Are you sure to delete selected search?")."\")'>". _m('Delete') ."</a></span>
               </td>
              </tr>";
    }


    /**
     * Prints searchbar (search rows and order rows - based on current settings)
     */
    function printBar() {
        global $slice_id;

        echo '<input type=hidden name=srchbr_akce value="1">
              <table width="100%" border="0" cellspacing="5" cellpadding="0"
              class=leftmenu bgcolor="'. COLOR_TABBG .'">';

        // print searchbars
        $count_sb = 0;
        $empty = false;   // flag - true if the last printed searchrow is empty

        if ($this->show & MGR_SB_SEARCHROWS) {
            while( ($count_sb < $this->order_row_count_min) OR
                   ($this->add_empty_search_row AND !$empty) ) {
                $empty = !$this->print_search_bar($count_sb++);
            }
        }

        if ($this->show & MGR_SB_ORDERROWS) {
            $i = 0;
            while( $i < $this->order_row_count_min ) {
                $this->print_order_bar($i++);
            }
        }


        if ($this->show & MGR_SB_BOOKMARKS) {
            $this->print_bar_actions();
            if ( $this->bookmarks->count() > 0 ) {
                $this->print_bar_bookmarks();
            }
        }

        echo '</table>
              <script language="JavaScript" type="text/javascript"> <!--

                var operator_names  = new Array();
                var operator_values = new Array();
                // text
                operator_names[0]  = new Array(" '._m('begins with').' "," '._m('contains').' ", " '._m('is').' ");
                operator_values[0] = new Array("RLIKE"                ,"LIKE"                  , "=");
                // numeric
                operator_names[1]  = new Array(" = "," < "," > ", " <> ");
                operator_values[1] = new Array("="  ,"<"  ,">"  , "<>");
                // date
                operator_names[2]  = new Array(" < (12/24/2002) "," > (12/24/2002) ");
                operator_values[2] = new Array("d:<"             ,"d:>");
                // constants
                operator_names[3]  = new Array(" '._m('begins with').' "," '._m('contains').' ", " '._m('is').' ", " '._m("select ...").' ");
                operator_values[3] = new Array("RLIKE"                ,"LIKE"                  , "=", "select");

                var field_types    = "';

        // print string like "120021020010" which defines field type (charAt())
        $oper_translate = array( 'text' => 0, 'numeric' => 1, 'date' => 2, 'constants' => 3);
        if ( isset($this->search_operators) AND is_array($this->search_operators) ) {
            reset($this->search_operators);
            while ( list(,$v) = each($this->search_operators) ) {
                echo $oper_translate[$v];
            }
        }
        echo "\";\n";
        for( $i=0; $i<$count_sb; $i++ ) {
            if ($this->search_row[$i]['readonly'] != true) {
                echo "   ChangeOperators('".$this->form_name."','$i','".$this->getSearchBarOperator($i)."');\n";
            }
        }
        echo '// -->
            </script>';
    }
}

/**
 * bookmarks class - stores queries (searchbar state)
 */
class bookmarks {
    var $classname = "bookmarks";    // required - class name
    var $bookmarks;                  // array of stored bookmarks
                                     // bookmarks[] = array('name' => <name>, 'state'=> <searchbar_state>)
    var $profile;                    // profile, where to store bookmarks
    var $active_bookmark;            // last used bookmark

    /** constructor */
    function bookmarks() {
        global $auth, $slice_id;
        $this->profile = new aaprofile($auth->auth["uid"], $slice_id);  // current user settings
        $this->setFromProfile();
        $this->setLastUsed();
    }

    /** Get searchbar state for bookmark number $key.
     *  See storable_class in statestore.php3 for more info about 'state' */
    function get($key) {
        return $this->is_defined($key) ? $this->bookmarks[$key]['state'] : false;
    }

    /** Get array of bookmark (<key> => <name>) */
    function getKeyName() {
        if ( isset($this->bookmarks) AND is_array($this->bookmarks) ) {
            foreach ( $this->bookmarks as $key => $book ) {
                $ret[$key] = $book['name'];
            }
        }
        return $ret;
    }

    /** Get parameters of bookmark defined by number $key */
    function getBookmarkParams($key) {
        return $this->bookmarks[$key];
    }

    /** Is the bookmark number $key defined? */
    function is_defined($key) {
        return isset( $this->bookmarks[$key] );
    }

    /** Returns number of stored bookmarks */
    function count() {
        return count($this->bookmarks);
    }

    function setLastUsed($last_used="none") {
        $this->$active_bookmark = $last_used;
    }

    function getLastUsed() {
        return $this->$active_bookmark;
    }


    function setFromProfile() {
        if ( !is_object($this->profile) ) return false;
        $this->bookmarks = array();               // reset
        $b_arr = $this->profile->get('bookmark', '*');  // get all bookmark properties for user
        if ( isset($b_arr) AND is_array($b_arr) ) {
            foreach ( $b_arr as $selector => $property_arr ) {
                $this->bookmarks[] = array('name' =>$selector,
                                           'state'=>unserialize($property_arr[0]),
                                           'type' =>$property_arr[1],
                                           'id' => $property_arr[2],
                                           'uid' => $property_arr[3]);
            }
        }
    }

    /** Store bookmark to database */
    function store( $name, $state, $to_global ) {
        if ( !is_object($this->profile) ) return false;
        if ( $to_global AND !IfSlPerm(PS_BOOKMARK) ) return false;
          // store to database
        $last_id = $this->profile->insertProperty('bookmark', $name, serialize($state), $to_global);
        writeLog("BM_CREATE", $name, $last_id );

        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        foreach ($this->bookmarks as $k => $book) {
            if ($book['name'] == $name) {
                $this->setLastUsed($k); break;
            }
        }
        return true;
    }

    /** Update bookmark in database */
    function update( $name, $state, $to_global, $id) {
        if ( !is_object($this->profile) ) return false;
        if ( $to_global AND !IfSlPerm(PS_BOOKMARK) ) return false;
          // store to database
        $this->profile->updateProperty('bookmark', $name, serialize($state), $to_global, $id);

        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        foreach ($this->bookmarks as $k => $book) {
            if ($book['name'] == $name) {
                $this->setLastUsed($k); break;
            }
        }
        return true;
    }


    /**     */
    function is_global($key) {
        return $this->bookmarks[$key]['type'] == '*';
    }

    /** */
    function delete($key) {
        if ( !is_object($this->profile) ) return false;
          // if it global bookmark?
        $global = $this->is_global($key);
          // and have we permisson to delete it?
        if ( $global AND !IfSlPerm(PS_BOOKMARK) ) return false;
          // store to database
        $this->profile->deleteProperty('bookmark', $this->bookmarks[$key]['name'], $global);
        writeLog("BM_DELETE", $this->bookmarks[$key]['name'], $this->bookmarks[$key]['id'] );
        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        return true;
    }

    /** */
    function updateBookmark($key, $state) {
        $old =  $this->bookmarks[$key];
        $ret = $this->update( $old['name'], $state, $old['type']=='*', $old['id']);
        if ($ret) {
            writeLog("BM_UPDATE", $old['name'], $old['id'] );
        } else {
            return false;
        }
    }

    /** */
    function renameBookmark($key, $newname) {
        $old =  $this->bookmarks[$key];
        $ret = $this->update( $newname, $old['state'], $old['type']=='*', $old['id']);
        if ($ret) {
            writeLog("BM_RENAME", array($newname,$old['name']), $old['id'] );
        } else {
            return false;
        }
    }


    /** Return HTML selectbox from bookmarks */
    function getSelectbox() {
        $ret = '
          <select name="srchbr_bookmark">
             <option value="none" '. (($this->getLastUsed() == "none") ? 'selected' : '') .'>'. _m('Select one...') .'</option>';
        foreach ($this->bookmarks as $k => $book) {
            $class = $this->is_global($k) ? 'class="sel_title"' : '';
            $sel = ((is_numeric($this->getLastUsed()) && ($this->getLastUsed() == $k)) ? 'selected' : '');
            $ret .= "\n<option value=\"$k\" $sel $class>".htmlspecialchars($book['name'])."</option>";
        }
        $ret .= '</select>';
        return $ret;
    }
}
?>
