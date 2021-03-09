<?php
/**
 * File contains definition of AA_Searchbar class - it handles search and order
 * bar in AA admin interface (Link Manager page for example)
 *
 * Should be included to other scripts (as /modules/links/index.php3)
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

require_once __DIR__."/formutil.php3";

/** searchlib for AA_Condition definition, ... */
require_once __DIR__."/searchlib.php3";

/** searchfields_cmp function
 *  Helper function to sort search fields
 *
 *  @param $a array first field for comparison
 *  @param $b array second
 *  @return integer  (0 or 1 or -1)
 */
function searchfields_cmp($a, $b) {
    if ($a['search_pri'] == $b['search_pri']) {
        return 0;
    }
    return ($a['search_pri'] < $b['search_pri']) ? -1 : 1;
}

/** orderfields_cmp function
 *  helper function to sort order fields
 * @param $a
 * @param $b
 * @return int
 */
function orderfields_cmp($a, $b) {
    if ($a['order_pri'] == $b['order_pri']) {
        return 0;
    }
    return ($a['order_pri'] < $b['order_pri']) ? -1 : 1;
}

/**
 * AA_Searchbar_Row class - handles one search row
 */
class AA_Searchbar_Row extends AA_Storable {
    var $condition;
    var $readonly;

    /** getClassProperties function of AA_Serializable
     * @return array
     */
    static function getClassProperties(): array {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'condition' => new AA_Property( 'condition'  , _m('Condition'), 'AA_Condition', false, true),
            'readonly'  => new AA_Property( 'readonly'   , _m('Readonly' ), 'bool',         false, true)
        ];
    }
    /** AA_Searchbar_Row function
     * @param $condition
     * @param $readonly
     */
    function __construct($condition=null, $readonly=false) {
        $this->condition = $condition;
        $this->readonly  = $readonly;
    }

    /** getField function
     *  Access function to searchbar_row field
     */
    function getField() {
        if (is_null($this->condition)) {
            return '';
        }
        $fields = $this->condition->getFields();
        // get the firs field (there should not be more fields)
        return reset($fields);
    }

    /** getOperator function
     *  Access function to searchbar_row operator
     */
    function getOperator() {
        return ( is_null($this->condition) ? '' : $this->condition->getOperator() );
    }

    /** getValue function
     *  Access function to searchbar_row value
     */
    function getValue() {
        return ( is_null($this->condition) ? '' : $this->condition->getValue() );
    }

    /** getCondsArray function
     *  Get old conds[] array - for backward compatibility only
     *  @deprecated
     */
    function getCondArray() {
        return ( is_null($this->condition) ? [] : $this->condition->getArray() );
    }

    /** getCondsArray function - @return AA_Condition object or null  */
    function getCondition() {
        return $this->condition;
    }

    /** isReadonly function
     *  Returns, if the search row is marked as readonly
     */
    function isReadonly() {
        return $this->readonly;
    }
}


/**
 * AA_Searchbar class - handles search and order bar in AA admin interface
 * (on Links Manager page, for example)
 */
class AA_Searchbar extends AA_Storable {
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

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'search_row' => new AA_Property( 'search_row'  , _m('Search row'), 'AA_Searchbar_Row', true, true),
            'order_row'  => new AA_Property( 'order_row'   , _m('Order row' ), 'text',             true, true)   // @todo probably it should be done better, since it is in fact array of arrays
        ];
    }

    /** AA_Searchbar function
     *  constructor
     * @param $fields AA\Util\Searchfields
     * @param $form_name
     * @param $search_row_count_min
     * @param $order_row_count_min
     * @param $add_empty_search_row
     * @param $show
     * @param $hint
     * @param $hint_url
     */
    function __construct($fields=null, $form_name='foo', $search_row_count_min=1, $order_row_count_min=1, $add_empty_search_row=1, $show=null, $hint='', $hint_url='') {
        $this->fields               = $fields;
        $this->form_name            = $form_name;
        $this->search_row_count_min = $search_row_count_min;
        $this->order_row_count_min  = $order_row_count_min;
        $this->add_empty_search_row = $add_empty_search_row;
        $this->bookmarks            = new AA_Bookmarks; // stores bookmarks (stored queries)
        $this->search_row           = [];          // internal array - stores current state of search rows
        $this->order_row            = [];          // internal array - stores current state of order rows
        $this->show                 = is_null($show) ? (MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS) : $show;
        $this->hint                 = $hint;
        $this->hint_url             = $hint_url;
    }

    /** update funtion
     * Updates internal search_row and order_row variables from data posted from
     * form (in $_POST[])
     */
    function update() {
        $srchbr_akce     = $_GET['srchbr_akce'];
        $srchbr_bookmark = $_GET['srchbr_bookmark'];
        if ( !$srchbr_akce ) {     // no searchbar action
            return;
        }
        [ $akce, $answer, $confirm ] = ParamExplode($srchbr_akce);

        switch ( $akce ) {
            case 'bookmark':    // bookmark as user bookmark (just for the user)
                $this->setFromForm();      // set the searchbar from form values
                if ( trim($answer) ) {
                    $this->bookmarks->store($answer, $this->getState(), $confirm=='y' );
                }
                return;
            case 'clearsearch':
                $this->resetSearchAndOrder();
                $this->setDefaultOrder();
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
                    $this->setDefaultOrder();
                    $this->bookmarks->setLastUsed();
                }
                return;
        }

        // reset the searchbar and set it from form values
        $this->setFromForm();
    }

    /** resetSearchAndOrder function
     *  Resets the searchbar (both - Search as well as Order)
     */
    function resetSearchAndOrder() {
        $this->resetSearch();
        $this->resetOrder();
    }

    /** resetSearch function
     *  Resets the searchbar's Search
     */
    function resetSearch() {
        $search_row = [];
        foreach ( $this->search_row as $k => $row ) {
            if ($row->isReadonly()) {
                // wee need to use keys from 0..
                $search_row[] = $row;
            }
        }
        $this->search_row = $search_row;
    }

    /** resetOrder function
     *  Resets the searchbar's Order
     */
    function resetOrder() {
        unset($this->order_row);
        $this->order_row = [];
    }

    /** setFromForm function
     *  Set searchbar state from form
     */
    function setFromForm() {
        if ($this->show & MGR_SB_SEARCHROWS) {
            // cleaning all searchrows except 'readonly' searches
            $this->resetSearch();
        }
        if ($this->show & MGR_SB_ORDERROWS) {
            // reset only if visible
            $this->resetOrder();
        }
        if (is_array($_GET['srchbr_order'])) {
            foreach ( $_GET['srchbr_order'] as $k => $fld ) {
                $this->addOrder( [0 => [$fld => $_GET["srchbr_order_dir"][$k]]]);
            }
        }

        if (is_array($_GET['srchbr_field'])) {
            foreach ( $_GET['srchbr_field'] as $k => $fld ) {
                $this->addSearch( [
                    0 => [
                        $fld       => 1,
                                                 'operator' => $_GET["srchbr_oper"][$k],
                                                 'value'    => $_GET["srchbr_value"][$k]
                    ]
                ]);
            }
        }
    }

    /** setFromBookmark function
     *  Set searchbar state from bookmark number <$key>
     * @param $key
     */
    function setFromBookmark($key) {
        if ( $this->bookmarks->is_defined($key) ) {
            $this->resetSearchAndOrder();
            $state = $this->bookmarks->getState($key);
            $this->setFromState($state);
            $this->bookmarks->setLastUsed($key);
        }
    }

    /** convertState function
     *  Setting state from previous versions of state
     * @param $version
     * @param $state
     * @return
     */
    function &convertState($version, &$state) {
        /* Current state store structure
                                [search_row] => Array (
                                    [0] => Array (
                                            [condition] => Array (
                                                    [fields] => Array (
                                                            [0] => headline........ )
                                                    [operator] => RLIKE
                                                    [value] => ff )
                                            [readonly] => )
                                    [1] => Array (
                                            [condition] => Array (
                                                    [fields] => Array (
                                                            [0] => headline........ )
                                                    [operator] => RLIKE
                                                    [value] => )
                                            [readonly] => )) */

        switch ($version) {
            case 1:             /* Stored as
                                [search_row] => Array (
                                    [0] => Array (
                                            [field] => headline........
                                            [value] => ff
                                            [oper]  => RLIKE
                                            [readonly] => )
                                    [1] => Array (
                                            [field] => headline........
                                            [value] =>
                                            [oper] => RLIKE
                                            [readonly] => )) */
                $new_search_row = [];
                foreach ($state['search_row'] as $k => $row) {
                    $new_search_row[$k] = [
                        'condition' => [
                                                     'fields'   => [$row['field']],
                                                     'operator' => $row['oper'],
                                                     'value'    => $row['value']
                        ],
                                                 'readonly' => $row['readonly']
                    ];
                }
                $state['search_row'] = $new_search_row;
        }
        return $state;
    }

    /** version function
     *  Searchbar version 2 - we have stored state in older verion
     *  (through stored seaches feature), which do not use AA_Searchbar_Row
     * @return 2
     */
    static function version() {
        return 2;
    }

    /** addOrder function
     * Adds new Order bar(s)
     * @param  array $sort[] = array( <field> => <a|d> )
     *               value other than 'a' means DESCENDING
     */
    function addOrder( $sort ) {
        // fill order_row variable
        if (isset($sort) AND is_array($sort)) {
            foreach ( $sort as $s ) {
                $this->order_row[] =
                     [
                         'field' => key($s),
                            'dir' => (current($s) AND (current($s)!='a')) ? 'd' : 'a'
                     ];
            }
        }
    }

    /** addSearch function
     * Adds new Search bar(s)
     * @param  array $conds[] = array ( <field>    => 1,
     *                                  'operator' => <operator>,
     *                                  'value'    => <search_string> )
     * @param $readonly
     */
    function addSearch($conds, $readonly=false) {
        // fill search_row variable
        if (isset($conds) AND is_array($conds)) {
            foreach ( $conds as $cond ) {
                if (isset($cond) AND is_array($cond)) {
                    $field = false;
                    foreach ( $cond as $k=>$c ) {
                        if ( ($k != 'operator') AND ($k != 'value') ) {
                            $field = $k;
                            break;
                        }
                    }
                    if ( $field ) {
                        $this->search_row[] = new AA_Searchbar_Row( new AA_Condition([$field], $cond['operator'], $cond['value']), $readonly);
                    }
                }
            }
        }
    }

    /** setDefaultOrder function
    */
    function setDefaultOrder() {
        // global $auth, $slice_id;  // @todo - tried to set right order after searchbox is cleaned, but it shows some errors. I have to look on it later. Honza 6.9.2012
        // $profile = AA_Profile::getProfile($auth->auth["uid"], $slice_id); // current user settings
        // $this->setFromProfile($profile);
        $order_fields = $this->fields->getOrderArray();
        if (($this->order_row_count_min > 0) AND isset( $order_fields['publish_date....'])) {
            $this->addOrder( [0=> ['publish_date....' => 'd']]);
        }
    }

    /** setFromProfile function
     * @param AA_Profile $profile
     */
    function setFromProfile($profile) {
        // admin_order is in 'publish_date....+' format
        $set        = new AA_Set;
        $orderstrings = $profile->get('admin_order', '*');
        if (is_array($orderstrings)) {
            foreach ( $orderstrings as $orderstring ) {
                $set->addSortFromString($orderstring[0]);
            }
        }
        $foo_order = $set->getSort();
        if ( count($foo_order) < 1 ) {
            $this->setDefaultOrder();
        } else {
            $this->addOrder($foo_order);
        }
        // we do not know the field id for admin_search profile so we have to use *
        $as = $profile->get('admin_search','*');

        if ( is_array($as) ) {
            foreach ($as as $key => $val) {
                $fld = $key;
                // admin_search profile is stringexpanded
                $search_val = $profile->parseContentProperty($val[0]);
                $search_str = $search_val->getValue();
            }
        }
        if ( $fld ) {
            $this->addSearch( [0=> [$fld => 1, 'value'=>$search_str, 'operator'=>'RLIKE', 'readonly' => 1]],1);
        }
    }

    /** @return AA_Set object - should be used instead of getConds and getSort
     */
    function getSet() {
        $set = new AA_Set;
        foreach ($this->search_row as $row) {
            $set->addCondition($row->getCondition());
        }
        if ( isset($this->order_row) AND is_array($this->order_row) ) {
            foreach ( $this->order_row as $s ) {
                $set->addSortorder(new AA_Sortorder([$s['field'] => $s['dir']]));
            }
        }
        return $set;
    }

     /** getBookmarkNames function
      *  @return array of bookmark names <key> => <name>
      */
    function getBookmarkNames() {
        return isset($this->bookmarks) ? $this->bookmarks->getKeyName() : [];
    }

    /** getBookmarkParams function
     * @param $key
     * @return bool
     */
    function getBookmarkParams($key) {
        return isset($this->bookmarks) ? $this->bookmarks->getBookmarkParams($key) : false;
    }

    /** print_search_bar function
     * Prints one search bar (one row)
     * @param int $bar which bar to print (index)
     * @return bool true, if the printed searchrow is not empty
     */
    function print_search_bar($bar) {

        $search_arr = $this->fields->getSearchArray($this->show & MGR_SB_ALLTEXT, $this->show & MGR_SB_ALLNUM);

        if (empty($this->search_row[$bar])) {
            $val      = '';
            $fld      = $search_arr[0];
            $oper     = '';
            $readonly = false;
        } else {
            $val      = safe($this->search_row[$bar]->getValue());
            $fld      = $this->search_row[$bar]->getField();
            $oper     = $this->search_row[$bar]->getOperator();
            $readonly = $this->search_row[$bar]->isReadonly();
        }

        if ( $bar == 0 ) {   // first bar is described as 'SEARCH' others 'AND'
            $searchtext = _m('Search');
            $searchimage = "<a href='javascript:document.".$this->form_name. ".submit()'>".
                           GetAAImage('search.gif', $searchtext, 15, 15) .'</a>';
        } else {
            $searchimage = GetAAImage('px.gif', '-', 15, 15);
            $searchtext = _m('And');
        }

        // filter
        echo "<tr class=\"leftmenuy\"><td class=\"search\">$searchimage</td><td><b>$searchtext</b></td>";
        if ($readonly) {
            echo "<td class=\"tabtxteven\">".$this->fields[$fld]['name']."</td>";
            echo "<td class=\"tabtxteven\">";
            switch ($oper) {
                case "LIKE":  echo _m('contains');    break;
                case "RLIKE": echo _m('begins with'); break;
                case "=":     echo _m('is');          break;
            }
            echo "</td>";
            echo "<td class=\"tabtxteven\">".$val."</td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td>";
        } else {
            echo "<td>";
            FrmSelectEasy("srchbr_field[$bar]", $search_arr, $fld, 'onchange="ChangeOperators(\''.$this->form_name.'\','.$bar.', \'\')"' );
            echo '</td><td>';
            FrmSelectEasy("srchbr_oper[$bar]", [' ' => ' '], null, 'onchange="OpenWindowIfRequest(\''.$this->form_name.'\',\''.$bar.'\',\''.get_admin_url("constants_sel.php3").'\')" ');
            echo '</td><td>';
            echo "<input type=\"text\" name=\"srchbr_value[$bar]\" size=\"20\" onkeypress=\"if (event.keyCode == 13) document.".$this->form_name. ".submit();\"
              value=\"$val\"></td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td>";
        }
        echo '</tr>';
        return $val != "";
    }

    /** print_bar_actions function
     *
     */
    function print_bar_actions() {
        echo "<tr class=\"leftmenuy\">

               <td colspan=\"3\">
               <input type=\"submit\" value=\"submit\" style=\"display:none\">
               <a href=\"javascript:document.".$this->form_name. ".submit()\">". _m('Search') ."</a> /
               <a href=\"javascript:SearchBarAction('".$this->form_name. "', 'clearsearch', false, false)\">". _m('Clear') ."</a>
               </td>
               <td colspan=\"2\">
                <a href=\"javascript:SearchBarAction('".$this->form_name. "', 'bookmark', '"._m('Stored search name') ."',".
                    ( !IfSlPerm(PS_BOOKMARK) ? "false" : "'". _m('You have the permission to add stored search globaly. Do you want to add this query as global (common to all slice users)?')."'") .")\">". _m('Store') ."</a>";
              echo "</td>";

              if ($this->hint != "") {
                  echo "<td>";
                  echo getFrmMoreHelp($this->hint_url,"",$this->hint, true);
                  echo "</td>";
              }
              echo "</tr>";
    }
    /** print_order_bar function
     * Prints one order bar (one row)
     * @param int $bar which bar to print (index)
     */
    function print_order_bar($bar) {
        $order_fields = $this->fields->getOrderArray();
        [$dir, $fld] = ( isset($this->order_row[$bar]) AND is_array($this->order_row[$bar]) ) ?  [safe($this->order_row[$bar]['dir']), $this->order_row[$bar]['field']] :  ["a", $order_fields[0]];

        $searchtext  = _m('Order');
        $searchimage = "<a href=\"javascript:document.".$this->form_name. ".submit()\">". GetAAImage('order.gif', $searchtext, 15, 15) .'</a>';
        echo "<tr class=\"leftmenuy\"><td class=\"search\">$searchimage</td><td><b>". str_replace(' ','&nbsp;',$searchtext). "</b></td><td>";
        FrmSelectEasy("srchbr_order[$bar]", $order_fields, $fld);
        echo '</td><td colspan="2" class="leftmenuy"><label>';
        FrmChBoxEasy("srchbr_order_dir[$bar]", $dir=='d');
        echo _m('Descending'). "</label></td><td>$searchimage</td><td width=\"99%\"> &nbsp; </td></tr>";
    }
    /** print_bar_bookmarks function
     *
     */
    function print_bar_bookmarks() {
        echo "<tr class=\"leftmenuy\">
               <td colspan=\"2\"><b>". _m('Stored searches') ."</b></td>
               <td>".
               $this->bookmarks->getSelectbox() .
               " <a href=\"javascript:SearchBarAction('".$this->form_name ."', 'bookmarkgo',     false, false)\">". _m('View')   ."</a>
               </td>
               <td colspan=\"4\">
                 <span class=\"smalltext\"><a href=\"javascript:SearchBarActionConfirm('".$this->form_name ."', 'bookmarkupdate', '". _m("Are you sure to refine current search?") ."')\">". _m('Update') ."</a> /
                 <a href=\"javascript:SearchBarAction('".$this->form_name ."', 'bookmarkrename', '". _m("Enter new name") ."', false)\">". _m('Rename') ."</a> /
                 <a href=\"javascript:SearchBarActionConfirm('".$this->form_name ."', 'bookmarkdelete', '"._m("Are you sure to delete selected search?")."')\">". _m('Delete') ."</a></span>
               </td>
              </tr>";
    }


    /** printBar function
     * Prints searchbar (search rows and order rows - based on current settings)
     */
    function printBar() {

        echo '<input type="hidden" name="srchbr_akce" value="1">
              <table width="100%" border="0" cellspacing="5" cellpadding="0"
              class="noprint" bgcolor="'. COLOR_TABBG .'">';

        // print searchbars
        $count_sb = 0;
        $empty = false;   // flag - true if the last printed searchrow is empty

        if ($this->show & MGR_SB_SEARCHROWS) {
            while ( ($count_sb < $this->order_row_count_min) OR
                   ($this->add_empty_search_row AND !$empty) ) {
                $empty = !$this->print_search_bar($count_sb++);
            }
        }

        if ($this->show & MGR_SB_ORDERROWS) {
            $i = 0;
            while ( $i < $this->order_row_count_min ) {
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
              <script>
             ';
        echo AA_Operators::getJsDefinition();
        echo '
                var field_types    = "';

        // print string like "120021020010" which defines field type (charAt())
        $oper_translate = ['text' => 0, 'numeric' => 1, 'date' => 2, 'constants' => 3, 'numconstants' => 4];
        $search_operators = $this->fields->getOperatorsArray($this->show & MGR_SB_ALLTEXT, $this->show & MGR_SB_ALLNUM);
        foreach ($search_operators as $v) {
            echo $oper_translate[$v];
        }
        echo "\";\n";

        for ( $i=0; $i<$count_sb; $i++ ) {
            $row = $this->search_row[$i];
            if ( empty($row) ) {
                echo "   ChangeOperators('".$this->form_name."','$i','');\n";
            }
            elseif (!$row->isReadonly()) {
                echo "   ChangeOperators('".$this->form_name."','$i','".$row->getOperator()."');\n";
            }
        }
        echo '
            </script>';
    }
}

/**
 * bookmarks class - stores queries (searchbar state)
 */
class AA_Bookmarks {
    var $bookmarks;                  // array of stored bookmarks
                                     // bookmarks[] = array('name' => <name>, 'state'=> <searchbar_state>)
    var $profile;                    // profile, where to store bookmarks
    var $active_bookmark;            // last used bookmark

    /** AA_Bookmarks function
     * constructor
     */
    function __construct() {
        global $auth, $slice_id;
        $this->profile = AA_Profile::getProfile($auth->auth["uid"], $slice_id); // current user settings

        $this->setFromProfile();
        $this->setLastUsed();
    }

    /** get function
     *  Get searchbar state for bookmark number $key.
     *  See AA_Storable in statestore.php3 for more info about 'state'
     * @param string $param key
     * @return array
     */
    function getState($key) : array {
        return ($this->is_defined($key) AND is_array($this->bookmarks[$key]['state'])) ? $this->bookmarks[$key]['state'] : [];
    }

    /** getKeeyName function
     *  Get array of bookmark (<key> => <name>)
     * @return array 'id'=>'name'
     */
    function getKeyName() {
        $ret = [];
        if ( isset($this->bookmarks) AND is_array($this->bookmarks) ) {
            foreach ( $this->bookmarks as $key => $book ) {
                $name = $book['name'] . ($this->isSelected($key) ? ' -' : '');
                $ret[(string)$key] = $name;
            }
            // return the bookmarks sorted by name (used in left menu bookmark display)
            asort($ret);
        }
        return $ret;
    }

    /** getBookmarkParams function
     *  Get parameters of bookmark defined by number $key
     * @param $key
     * @return
     */
    function getBookmarkParams($key) {
        return $this->bookmarks[$key];
    }

    /** is_defined function
     *  Is the bookmark number $key defined?
     * @param $key
     * @return bool
     */
    function is_defined($key) {
        return isset( $this->bookmarks[$key] );
    }

    /** count function
     *  Returns number of stored bookmarks
     */
    function count() {
        return count($this->bookmarks);
    }
    /** setLastUsed function
     * @param $last_used
     */
    function setLastUsed($last_used="none") {
        $this->active_bookmark = $last_used;
    }
    /** getLastUsed function
     *
     */
    function getLastUsed() {
        return $this->active_bookmark;
    }

    /** setFromProfile function
     *
     */
    function setFromProfile() {
        if ( !is_object($this->profile) ) {
            return false;
        }
        $this->bookmarks = [];               // reset
        $b_arr = $this->profile->get('bookmark', '*');  // get all bookmark properties for user
        if ( isset($b_arr) AND is_array($b_arr) ) {
            foreach ( $b_arr as $selector => $property_arr ) {
                $this->bookmarks[] = [
                    'name' => $selector,
                                           'state'=> unserialize($property_arr[0]),
                                           'type' => $property_arr[1],
                                           'id'   => $property_arr[2],
                                           'uid'  => $property_arr[3]
                ];
            }
        }
    }

    /** store function
     *  Store bookmark to database
     * @param $name
     * @param $state
     * @param $to_global
     * @return bool
     */
    function store( $name, $state, $to_global ) {
        if ( !is_object($this->profile) ) {
            return false;
        }
        if ( $to_global AND !IfSlPerm(PS_BOOKMARK) ) {
            return false;
        }
          // store to database
        $last_id = $this->profile->insertProperty('bookmark', $name, serialize($state), $to_global);
        AA_Log::write("BM_CREATE", $last_id, $name);

        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        foreach ($this->bookmarks as $k => $book) {
            if ($book['name'] == $name) {
                $this->setLastUsed($k);
                break;
            }
        }
        return true;
    }

    /** update function
     *  Update bookmark in database
     * @param $name
     * @param $state
     * @param $to_global
     * @param $id
     * @return bool
     */
    function update( $name, $state, $to_global, $id) {
        if ( !is_object($this->profile) ) {
            return false;
        }
        if ( $to_global AND !IfSlPerm(PS_BOOKMARK) ) {
            return false;
        }
          // store to database
        $this->profile->updateProperty('bookmark', $name, serialize($state), $id);

        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        foreach ($this->bookmarks as $k => $book) {
            if ($book['name'] == $name) {
                $this->setLastUsed($k); break;
            }
        }
        return true;
    }


    /** is_global function
     * @param $key
     * @return bool
     */
    function is_global($key) {
        return $this->bookmarks[$key]['type'] == '*';
    }

    /** delete function
     * @param $key
     * @return bool
     */
    function delete($key) {
        if ( !is_object($this->profile) ) {
            return false;
        }
          // if it global bookmark?
        $global = $this->is_global($key);
          // and have we permisson to delete it?
        if ( $global AND !IfSlPerm(PS_BOOKMARK) ) {
            return false;
        }
          // store to database
        $this->profile->deleteProperty('bookmark', $this->bookmarks[$key]['name'], $global);
        AA_Log::write("BM_DELETE", $this->bookmarks[$key]['id'], $this->bookmarks[$key]['name']);
        $this->profile->loadprofile(true);    // reread profile from database
        $this->setFromProfile();              // get bookmarks again
        return true;
    }

    /** updateBookmark function
     * @param $key
     * @param $state
     * @return bool
     */
    function updateBookmark($key, $state) {
        $old = $this->bookmarks[$key];
        $ret = $this->update( $old['name'], $state, $old['type']=='*', $old['id']);
        if ($ret) {
            AA_Log::write("BM_UPDATE", $old['id'], $old['name']);
        } else {
            return false;
        }
    }

    /** renameBookmark function
     * @param $key
     * @param $newname
     * @return bool
     */
    function renameBookmark($key, $newname) {
        $old = $this->bookmarks[$key];
        $ret = $this->update( $newname, $old['state'], $old['type']=='*', $old['id']);
        if ($ret) {
            AA_Log::write("BM_RENAME", $old['id'], [$newname, $old['name']]);
        } else {
            return false;
        }
    }

    /** bookmark of key $key is currently seleted? */
    function isSelected($key) {
        return ctype_digit((string)$this->getLastUsed()) && ($this->getLastUsed() == $key);
    }

    /** getSelectbox function
     * Return HTML selectbox from bookmarks
     */
    function getSelectbox() {
        $ret = '
          <select name="srchbr_bookmark">
             <option value="none" '. (($this->getLastUsed() == "none") ? 'selected' : '') .'>'. _m('Select one...') .'</option>';
        foreach ($this->bookmarks as $k => $book) {
            $class = $this->is_global($k)  ? 'class="sel_title"' : '';
            $sel   = $this->isSelected($k) ? 'selected' : '';
            $ret  .= "\n<option value=\"$k\" $sel $class>".myspecialchars($book['name'])."</option>";
        }
        $ret .= '</select>';
        return $ret;
    }
}

